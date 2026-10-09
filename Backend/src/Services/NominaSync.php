<?php

namespace App\Services;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Sincroniza el Excel madre (hoja "NOMINA ACT 2024") con la base de datos.
 *
 * Todo lo que trae el Excel madre pertenece a la póliza de producto "Odontólogo" de cada
 * persona. Si la persona tiene otras pólizas (p. ej. Dermo/Estética), esta sincronización
 * NO las toca: ni sus datos ni sus pagos.
 *
 * ── Flujo de datos ────────────────────────────────────────────────────────────
 *   Excel madre ──leerHoja()──▶ filas + columnas de meses
 *        │
 *        ├─ se agrupan por RUT (una persona puede tener varias filas: re-afiliaciones)
 *        │
 *        └─ por cada RUT:
 *             1. valoresAsegurado()  ──▶ INSERT/UPDATE en `asegurados` (la persona)
 *             2. valoresPoliza()     ──▶ INSERT/UPDATE en `polizas` (su póliza de Odontólogo)
 *             3. afiliaciones previas ─▶ `historial_cambios`
 *             4. combinarMeses()     ──▶ INSERT/UPDATE en `pagos_mensuales` (de esa póliza)
 *        todo dentro de UNA transacción: si algo falla no queda nada a medias.
 *
 * ── Garantías ─────────────────────────────────────────────────────────────────
 *   - Repetible: subir el mismo archivo dos veces no cambia nada.
 *   - Inserta lo nuevo y actualiza lo que cambió en el Excel.
 *   - NO pisa los pagos editados a mano desde el front (`editado = 1`): los cuenta
 *     como "conflictos" en el resumen.
 *   - NO revierte un cambio de estado hecho por la aplicación (`estado_manual = 1`, cuando
 *     una persona pasó a NO VIGENTE por 2 meses sin pago) mientras el Excel no lo refleje.
 *   - NO borra nada: si algo desaparece del Excel, sigue en la base.
 *
 * Lo usan api/importar.php (botón "Actualizar nómina") y scripts/sincronizar_nomina.php.
 */
class NominaSync
{
    /** Nombre de la hoja que manda; de ella salen las demás hojas del Excel. */
    public const HOJA = 'NOMINA ACT 2024';

    // Posición (base 0) de cada columna fija de la hoja madre.
    // Desde C_PRIMER_MES en adelante cada columna es un mes (el encabezado es una fecha).
    // La columna 2 (RUT-DV) se ignora: es una fórmula; se usa RUT y DV por separado.
    private const C_RUT = 0, C_DV = 1, C_NOMBRE = 3, C_GENERO = 4, C_TELEFONO = 5, C_MAIL = 6,
                  C_ALTA = 7, C_POLIZA = 8, C_COBERTURA = 9, C_ESTADO = 10, C_BAJA = 11,
                  C_MOTIVO = 12, C_PAGADOR = 13, C_MEDIO_PAGO = 14, C_TITULACION = 15,
                  C_PRIMER_MES = 16;

    // Cuando una persona aparece en varias filas y todas tienen dato para el mismo mes,
    // gana el de mayor puntaje: un pago real le gana a un impago, y este a una "c" tardía.
    private const P_PAGADO = 3, P_IMPAGO = 2, P_C_TARDIA = 1;

    /** Textos de celda que se ignoran por completo (estaban ocultos en el Excel). */
    private const IGNORAR = ['tribunal'];

    /**
     * Caché de los catálogos: nombre => id. Evita consultar la base por cada fila.
     * @var array<string, array<string,int>>
     */
    private array $catalogos = ['coberturas' => [], 'medios_pago' => [], 'motivos_baja' => []];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Ejecuta la sincronización completa.
     *
     * @param string $rutaExcel     ruta física del archivo (en la API, el temporal de la subida)
     * @param string $nombreArchivo nombre original, solo para dejarlo en `importaciones`
     * @return array contadores de lo ocurrido (nuevos, actualizados, sin cambios, conflictos…)
     * @throws RuntimeException si el archivo no tiene el formato esperado
     */
    public function sincronizar(string $rutaExcel, string $nombreArchivo): array
    {
        [$filas, $columnasMes] = $this->leerHoja($rutaExcel);
        return $this->procesar($filas, $columnasMes, null, $nombreArchivo, true);
    }

    /**
     * Aplica solo unas pocas filas que la hoja de Google envía cuando alguien edita una celda.
     *
     * Diferencias con la sincronización completa del Excel:
     *   - La hoja manda: si la celda de un mes se editó en la app (`editado = 1`) y la hoja
     *     dice otra cosa, gana la hoja (la edición más reciente), y queda en el historial.
     *   - No crea un registro en `importaciones` (serían miles de avisos).
     *   - Solo carga de la base los datos de las personas recibidas, así que es rápida.
     *
     * IMPORTANTE: el que llama debe enviar TODAS las filas de cada RUT (si una persona
     * aparece en dos filas por una re-afiliación, las dos). Con una sola, el sistema no
     * podría saber cuál es su afiliación más reciente.
     *
     * @param array $encabezado fila 1 de la hoja (nombres de columnas y fechas de los meses)
     * @param array $filas      lista de ['fila' => número de fila en la hoja, 'valores' => celdas de la fila]
     * @return array contadores, con las mismas claves que la sincronización completa
     * @throws RuntimeException si el encabezado no tiene el formato esperado
     */
    public function sincronizarFilas(array $encabezado, array $filas): array
    {
        if (($encabezado[self::C_RUT] ?? null) !== 'RUT') {
            throw new RuntimeException('El formato de la hoja cambió: la primera columna debería ser "RUT".');
        }
        $columnasMes = $this->columnasDeMeses($encabezado);

        return $this->procesar(
            array_map(fn($f) => $f['valores'] ?? [], $filas),
            $columnasMes,
            array_map(fn($f) => (int) ($f['fila'] ?? 0), $filas),
            null,
            false
        );
    }

    /**
     * Núcleo común: aplica un conjunto de filas a la base de datos.
     *
     * @param array      $filas        filas de la hoja, sin el encabezado
     * @param array      $columnasMes  índice de columna => 'AAAA-MM-01'
     * @param array|null $numerosFila  número de fila de cada elemento de $filas (si es null: posición + 2)
     * @param bool       $completa     true = carga completa del Excel (protege los meses editados a
     *                                 mano y registra la importación); false = aviso de la hoja
     */
    private function procesar(array $filas, array $columnasMes, ?array $numerosFila, ?string $nombreArchivo, bool $completa): array
    {
        // Agrupar por RUT: una persona puede aparecer varias veces (se dio de baja y volvió).
        // Se guarda también el número de fila de Excel para dejar rastro en el historial.
        $porRut = [];
        $sinRut = 0;
        foreach ($filas as $n => $f) {
            if (!is_numeric($f[self::C_RUT] ?? null)) {
                $sinRut++; // filas vacías o de relleno al final de la hoja
                continue;
            }
            $porRut[(int) $f[self::C_RUT]][] = ['fila' => $numerosFila[$n] ?? $n + 2, 'datos' => $f]; // +2: encabezado + base 1
        }

        // Contadores que se devuelven al llamador (y se muestran en el front)
        $s = [
            'filas_excel' => count($filas),
            'filas_sin_rut' => $sinRut,
            'asegurados_nuevos' => 0,
            'asegurados_actualizados' => 0,
            'polizas_nuevas' => 0,
            'polizas_actualizadas' => 0,
            'pagos_nuevos' => 0,
            'pagos_actualizados' => 0,
            'pagos_sin_cambios' => 0,
            'pagos_editados_en_conflicto' => 0,
            'pagos_editados_reemplazados' => 0, // solo en avisos de la hoja: la hoja pisó una edición de la app
            'afiliaciones_previas_nuevas' => 0,
            'dv_invalido' => 0,
        ];

        $this->pdo->beginTransaction();
        try {
            // Carga completa: se registra la importación primero para poder enlazar a ella cada
            // pago cargado. Los avisos fila a fila de la hoja no crean registro (serían miles).
            $importacionId = null;
            if ($completa) {
                $this->pdo->prepare("INSERT INTO importaciones (origen, archivo, cobertura_desde, cobertura_hasta, filas_leidas)
                                     VALUES ('madre', ?, ?, ?, ?)")
                    ->execute([$nombreArchivo, min($columnasMes), max($columnasMes), count($filas)]);
                $importacionId = (int) $this->pdo->lastInsertId();
            }

            $this->cargarCatalogos();
            $productoId = $this->idProductoMadre();

            // Se carga en memoria lo que ya existe para decidir, sin una consulta por fila,
            // si cada mes es nuevo, cambió o quedó igual (son ~400 mil filas). En un aviso de la
            // hoja solo se cargan las personas recibidas.
            $ruts = $completa ? null : array_keys($porRut);
            $existentes = $this->cargarPagosExistentes($productoId, $ruts);
            $polizaIds = $this->cargarPolizaIds($productoId, $ruts); // rut => id de su póliza Odontólogo
            $historialPrevio = $this->cargarHistorialAfiliaciones($ruts);
            // Quién figura como autor de los cambios en historial_cambios
            $usuario = $completa ? 'sincronizacion' : 'hoja';

            // Persona: se inserta o, si el RUT ya existe, se actualizan sus datos de identidad.
            // `especialidad` no está en el Excel madre, así que no se toca (la carga el Excel de pólizas).
            $upsertAseg = $this->pdo->prepare(
                'INSERT INTO asegurados (rut, dv, dv_valido, nombre, genero, telefono, mail)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE dv=VALUES(dv), dv_valido=VALUES(dv_valido), nombre=VALUES(nombre),
                    genero=VALUES(genero), telefono=VALUES(telefono), mail=VALUES(mail)'
            );
            // Póliza Odontólogo de la persona (la más reciente si tiene varias, una por período). El Excel manda sobre sus
            // datos (a diferencia de los pagos editados), con una excepción: el `estado`. Si la
            // aplicación pasó la póliza a NO VIGENTE (estado_manual = 1), se conserva mientras el
            // Excel diga otra cosa; en cuanto el Excel coincide, la marca se limpia. MySQL evalúa las
            // asignaciones de izquierda a derecha, por eso `estado_manual` va antes que `estado`.
            // `tipo_individuo_id` no se toca: lo gobierna el Excel de pólizas.
            $insertPoliza = $this->pdo->prepare(
                'INSERT INTO polizas (rut, producto_id, numero, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                    rut_pagador_original, fecha_alta, fecha_titulacion, fecha_baja, fecha_baja_texto, motivo_baja_id, estado, va_a_corte)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $updatePoliza = $this->pdo->prepare(
                'UPDATE polizas SET numero=?, cobertura_id=?, medio_pago_id=?, rut_pagador=?, dv_pagador=?,
                    rut_pagador_original=?, fecha_alta=?, fecha_titulacion=?, fecha_baja=?, fecha_baja_texto=?, motivo_baja_id=?,
                    estado_manual=IF(estado_manual = 1 AND ? <> estado, 1, 0),
                    estado=IF(estado_manual = 1, estado, ?),
                    va_a_corte=IF(estado = \'VIGENTE\', ?, 0)
                 WHERE id = ?'
            );
            $insHistorial = $this->pdo->prepare(
                "INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            // editado = 0: el valor ahora viene del Excel/hoja, ya no es una edición de la app
            $updPago = $this->pdo->prepare(
                'UPDATE pagos_mensuales SET pagado = ?, monto = ?, valor_original = ?, nota = ?,
                        importacion_id = ?, origen = \'madre\', editado = 0 WHERE id = ?'
            );

            $lote = []; // pagos nuevos pendientes de insertar (se insertan de a 500)
            foreach ($porRut as $rut => $afiliaciones) {
                // Orden cronológico por fecha de alta: la última fila es la afiliación vigente
                usort($afiliaciones, fn($a, $b) => ($a['datos'][self::C_ALTA] ?? 0) <=> ($b['datos'][self::C_ALTA] ?? 0));
                $actual = $afiliaciones[array_key_last($afiliaciones)]['datos'];

                // 1. Datos de la persona (siempre desde su afiliación más reciente)
                $upsertAseg->execute($this->valoresAsegurado($rut, $actual, $s));
                // MySQL informa 1 = fila insertada, 2 = fila existente que cambió, 0 = sin cambios
                match ($upsertAseg->rowCount()) {
                    1 => $s['asegurados_nuevos']++,
                    2 => $s['asegurados_actualizados']++,
                    default => null,
                };

                // 2. Su póliza de Odontólogo (se crea si es la primera vez)
                $v = $this->valoresPoliza($rut, $productoId, $actual);
                if (isset($polizaIds[$rut])) {
                    // Ya tiene póliza Odontólogo: se actualiza por su id (MySQL informa 1 si algo cambió, 0 si quedó igual)
                    $updatePoliza->execute([...array_slice($v, 2, 11), $v[13], $v[13], $v[14], $polizaIds[$rut]]);
                    if ($updatePoliza->rowCount() > 0) $s['polizas_actualizadas']++;
                } else {
                    $insertPoliza->execute($v);
                    $polizaIds[$rut] = (int) $this->pdo->lastInsertId(); // póliza recién creada
                    $s['polizas_nuevas']++;
                }
                $polizaId = $polizaIds[$rut];

                // 3. Afiliaciones anteriores: no tienen tabla propia, se conservan en el historial
                foreach (array_slice($afiliaciones, 0, -1) as $prev) {
                    $d = $prev['datos'];
                    $json = json_encode([
                        'fila_excel' => $prev['fila'],
                        'fecha_alta' => $this->serialAFecha($d[self::C_ALTA]),
                        'fecha_baja' => is_numeric($d[self::C_BAJA]) ? $this->serialAFecha($d[self::C_BAJA]) : $this->limpiar($d[self::C_BAJA]),
                        'motivo_baja' => $this->limpiar($d[self::C_MOTIVO]),
                        'cobertura' => $this->limpiar($d[self::C_COBERTURA]),
                        'estado' => $this->limpiar($d[self::C_ESTADO]),
                    ], JSON_UNESCAPED_UNICODE);
                    // La fila de Excel puede cambiar al reordenar la hoja: se compara sin ella
                    // para no duplicar la misma afiliación en cada sincronización
                    $huella = $rut . '|' . md5($this->sinFila($json));
                    if (!isset($historialPrevio[$huella])) {
                        $insHistorial->execute(['asegurados', (string) $rut, 'afiliacion_anterior', $json, null, $usuario]);
                        $historialPrevio[$huella] = true;
                        $s['afiliaciones_previas_nuevas']++;
                    }
                }

                // 4. Pagos mes a mes: comparar contra lo que ya hay en la base
                foreach ($this->combinarMeses($afiliaciones, $columnasMes) as $periodo => $m) {
                    $clave = $rut . '|' . $periodo;
                    $actualBD = $existentes[$clave] ?? null;

                    if ($actualBD === null) {
                        // Mes que no existía: se acumula para insertarlo en lote
                        $lote[] = [$polizaId, $periodo, $m['pagado'], $m['monto'], $importacionId, 'madre', $m['valor_original'], $m['nota']];
                        $s['pagos_nuevos']++;
                        if (count($lote) >= 500) {
                            $this->insertarPagos($lote);
                            $lote = [];
                        }
                        continue;
                    }

                    $igual = (int) $actualBD['pagado'] === $m['pagado']
                        && ($actualBD['monto'] === null ? null : (int) $actualBD['monto']) === $m['monto'];

                    if ($igual) {
                        $s['pagos_sin_cambios']++;
                    } elseif ($completa && (int) $actualBD['editado'] === 1) {
                        // Carga completa del Excel: alguien corrigió este mes a mano en el front,
                        // se respeta su valor y se informa como conflicto
                        $s['pagos_editados_en_conflicto']++;
                    } else {
                        // Cambió en el Excel/hoja: se actualiza y, si cambió el estado de pago, se deja registro.
                        // (Un aviso de la hoja sí puede pisar una edición de la app: es el cambio más reciente.)
                        $updPago->execute([$m['pagado'], $m['monto'], $m['valor_original'], $m['nota'], $importacionId, $actualBD['id']]);
                        if ((int) $actualBD['pagado'] !== $m['pagado']) {
                            $insHistorial->execute(['pagos_mensuales', $clave, 'pagado', $actualBD['pagado'], (string) $m['pagado'], $usuario]);
                        }
                        if ((int) $actualBD['editado'] === 1) {
                            $s['pagos_editados_reemplazados']++;
                        }
                        $s['pagos_actualizados']++;
                    }
                }
            }
            $this->insertarPagos($lote); // lo que quedó sin completar el último lote

            if ($completa) {
                // Personas cuyo estado cambió la aplicación y que el Excel aún da por vigentes
                $s['estados_mantenidos_manualmente'] = (int) $this->pdo->query('SELECT COUNT(*) FROM polizas WHERE estado_manual = 1')->fetchColumn();

                $this->pdo->prepare('UPDATE importaciones SET filas_cargadas = ?, resumen = ? WHERE id = ?')
                    ->execute([count($porRut), json_encode($s), $importacionId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            // Cualquier fallo deshace toda la sincronización: la base queda como estaba
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($completa) {
            $s['importacion_id'] = $importacionId;
        }
        return $s;
    }

    // ------------------------------------------------------------ lectura del Excel

    /**
     * Lee la hoja madre y valida que tenga el formato esperado.
     *
     * Se carga solo esa hoja y sin estilos (modo solo datos) para ahorrar memoria: el
     * archivo completo tiene 9 hojas y más de 8.000 filas por 144 columnas.
     *
     * @return array{0: array, 1: array<int,string>}
     *         [filas sin el encabezado, columnasMes = índice de columna => 'YYYY-MM-01']
     * @throws RuntimeException si falta la hoja o cambió el encabezado
     */
    private function leerHoja(string $ruta): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        if (!in_array(self::HOJA, $reader->listWorksheetNames($ruta), true)) {
            throw new RuntimeException('El archivo no tiene la hoja "' . self::HOJA . '".');
        }
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::HOJA]);
        // Segundo argumento false: las fórmulas no se calculan, se devuelven como texto
        $filas = $reader->load($ruta)->getSheetByName(self::HOJA)->toArray(null, false, false, false);

        $encabezado = array_shift($filas);
        if (($encabezado[self::C_RUT] ?? null) !== 'RUT') {
            throw new RuntimeException('El formato de la hoja cambió: la primera columna debería ser "RUT".');
        }

        return [$filas, $this->columnasDeMeses($encabezado)];
    }

    /**
     * Identifica qué columnas del encabezado son meses.
     * Los meses tienen como encabezado una fecha (número serial de Excel); las columnas sin
     * fecha (separadores de año, "Estado1"...) se descartan.
     *
     * @return array<int,string> índice de columna => 'AAAA-MM-01'
     * @throws RuntimeException si no hay ninguna columna de mes
     */
    private function columnasDeMeses(array $encabezado): array
    {
        $columnasMes = [];
        foreach ($encabezado as $i => $h) {
            if ($i >= self::C_PRIMER_MES && is_numeric($h)) {
                $fecha = $this->serialAFecha($h);
                // Cada encabezado de mes es el día 1. Si no lo es, las fechas llegaron corridas (por
                // ejemplo por una zona horaria mal interpretada al leer la hoja) y cada mes se
                // guardaría en el mes equivocado: se rechaza todo antes de tocar la base.
                if (substr($fecha, 8, 2) !== '01') {
                    throw new RuntimeException("Las fechas de los meses llegaron corridas (la columna {$h} es {$fecha}, no un día 1). No se guardó nada.");
                }
                $columnasMes[$i] = substr($fecha, 0, 7) . '-01';
            }
        }
        if (!$columnasMes) {
            throw new RuntimeException('No se encontraron columnas de meses en la hoja.');
        }
        return $columnasMes;
    }

    // ------------------------------------------------------------ asegurados y pólizas

    /**
     * Convierte una fila del Excel en los 7 valores (en orden) que recibe el INSERT de `asegurados`
     * (los datos de identidad de la persona). Limpia espacios y valida el dígito verificador.
     *
     * @param array $s contadores de la sincronización; se suma aquí los DV inválidos (por referencia)
     */
    private function valoresAsegurado(int $rut, array $f, array &$s): array
    {
        $dv = strtoupper((string) $f[self::C_DV]);
        $dvValido = $dv === $this->calcularDv($rut) ? 1 : 0;
        $s['dv_invalido'] += 1 - $dvValido;

        // "Sin Telefono" y similares no son teléfonos
        $telefono = $this->limpiar($f[self::C_TELEFONO]);
        if ($telefono !== null && !preg_match('/\d/', $telefono)) {
            $telefono = null;
        }

        return [
            $rut,
            $dv,
            $dvValido,
            $this->limpiar($f[self::C_NOMBRE]) ?? '(sin nombre)',
            $this->limpiar($f[self::C_GENERO]),
            $telefono,
            $this->limpiar($f[self::C_MAIL]),
        ];
    }

    /**
     * Convierte una fila del Excel en los 14 valores (en orden) que recibe el INSERT de `polizas`.
     * Convierte fechas y traduce textos a ids de catálogo.
     */
    private function valoresPoliza(int $rut, int $productoId, array $f): array
    {
        $pagador = $this->parsearRutPagador($f[self::C_PAGADOR]);

        // La baja puede venir como fecha o como texto libre; cada una va a su propia columna
        $baja = $f[self::C_BAJA];
        $motivo = $this->limpiar($f[self::C_MOTIVO]);
        $cobertura = $this->limpiar($f[self::C_COBERTURA]);

        $estado = strtoupper((string) $f[self::C_ESTADO]);
        if (!in_array($estado, ['VIGENTE', 'VAN A CORTE', 'NO VIGENTE'], true)) {
            $estado = 'NO VIGENTE';
        }
        // "Va a corte" no es un estado: la póliza sigue VIGENTE y se guarda el aviso aparte
        $vaACorte = $estado === 'VAN A CORTE' ? 1 : 0;
        if ($vaACorte) $estado = 'VIGENTE';

        return [
            $rut,
            $productoId,
            $this->limpiar($f[self::C_POLIZA]),
            $this->idCatalogo('coberturas', $cobertura === null ? null : mb_strtoupper($cobertura)),
            $this->idCatalogo('medios_pago', $this->normalizarMedioPago($this->limpiar($f[self::C_MEDIO_PAGO]))),
            $pagador[0] ?? null,
            $pagador[1] ?? null,
            $this->limpiar($f[self::C_PAGADOR]), // se conserva el texto original por si no era un RUT
            $this->serialAFecha($f[self::C_ALTA]),
            $this->serialAFecha($f[self::C_TITULACION]),
            is_numeric($baja) ? $this->serialAFecha($baja) : null,
            !is_numeric($baja) ? $this->limpiar($baja) : null,
            $this->idCatalogo('motivos_baja', $motivo === null ? null : mb_strtoupper($motivo)),
            $estado,
            $vaACorte,
        ];
    }

    /** Id del producto de las pólizas del Excel madre (lo crea si la base aún no lo tiene). */
    private function idProductoMadre(): int
    {
        $nombre = 'Odontólogo'; // mismo valor que PRODUCTO_MADRE en api/_comun.php
        $this->pdo->prepare('INSERT IGNORE INTO productos (nombre) VALUES (?)')->execute([$nombre]);
        $q = $this->pdo->prepare('SELECT id FROM productos WHERE nombre = ?');
        $q->execute([$nombre]);
        return (int) $q->fetchColumn();
    }

    /**
     * Pólizas Odontólogo que ya existen: rut => id de póliza. Con $ruts solo las de esas personas.
     * @return array<int,int>
     */
    private function cargarPolizaIds(int $productoId, ?array $ruts = null): array
    {
        // Si una persona tiene varias pólizas del producto (una por período), queda la de vigencia más reciente:
        // en un FETCH_KEY_PAIR gana la última fila de cada RUT, por eso van ordenadas de la más antigua a la más reciente
        $q = $this->pdo->prepare(
            'SELECT z.rut, z.id FROM polizas z WHERE z.producto_id = ?'
            . ($ruts === null ? '' : ' AND z.rut IN (' . $this->marcadores($ruts) . ')')
            . ' ORDER BY z.rut, COALESCE((SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id), \'0000-00-00\'), z.id'
        );
        $q->execute(array_merge([$productoId], $ruts ?? []));
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // ------------------------------------------------------------ pagos

    /**
     * Junta los meses de todas las filas (afiliaciones) de una misma persona.
     *
     * Cuando dos filas tienen dato para el mismo mes, gana el de mayor puntaje
     * (pagado > impago > "c" tardía) y, a igualdad, el de la afiliación más reciente.
     * Esto resuelve, por ejemplo, la fila vieja con ceros posteriores a su baja frente a
     * la fila nueva con los pagos reales.
     *
     * @param array $afiliaciones filas de la persona, ya ordenadas de la más antigua a la más reciente
     * @return array<string, array> periodo ('YYYY-MM-01') => resultado de clasificarCelda()
     */
    private function combinarMeses(array $afiliaciones, array $columnasMes): array
    {
        $meses = [];
        foreach ($afiliaciones as $a) {
            $d = $a['datos'];
            $altaYm = is_numeric($d[self::C_ALTA]) ? substr($this->serialAFecha($d[self::C_ALTA]), 0, 7) : null;
            foreach ($columnasMes as $i => $periodo) {
                $celda = $this->clasificarCelda($d[$i] ?? null, $periodo, $altaYm);
                if ($celda === null) {
                    continue; // celda vacía o ignorada: no genera fila
                }
                if (!isset($meses[$periodo]) || $celda['puntaje'] >= $meses[$periodo]['puntaje']) {
                    $meses[$periodo] = $celda;
                }
            }
        }
        return $meses;
    }

    /**
     * Interpreta el contenido de UNA celda de mes y decide si el mes está pagado.
     *
     *   número > 0            → pagado, ese monto
     *   0                     → impago
     *   vacío                 → sin dato (no genera fila)
     *   "tribunal"            → se ignora (no genera fila)
     *   "c" antes del alta    → la persona aún no estaba afiliada (no genera fila)
     *   "c" desde el alta     → impago, con la "c" como nota
     *   "=37000+319400"       → fórmula suma: pagado por el total
     *   PAGADO, webpay, ...   → pagado (pagos desfasados o por otros medios)
     *   cualquier otro texto  → impago (falta de fondos, prorroga, tarjeta..., etc.)
     *
     * Para los textos se conserva el original en `valor_original` y `nota`.
     *
     * @return array{pagado:int, monto:?int, valor_original:?string, nota:?string, puntaje:int}|null
     */
    private function clasificarCelda(mixed $v, string $periodo, ?string $altaYm): ?array
    {
        if ($v === null) {
            return null;
        }
        if (is_numeric($v)) {
            return $v > 0
                ? ['pagado' => 1, 'monto' => (int) round($v), 'valor_original' => null, 'nota' => null, 'puntaje' => self::P_PAGADO]
                : ['pagado' => 0, 'monto' => null, 'valor_original' => null, 'nota' => null, 'puntaje' => self::P_IMPAGO];
        }

        $orig = $this->limpiar($v);
        if ($orig === null) {
            return null;
        }
        // Se compara en minúsculas y sin símbolos al inicio ("´pagado" aparece en el Excel)
        $l = mb_strtolower(ltrim($orig, "´'` "));

        if (in_array($l, self::IGNORAR, true)) {
            return null;
        }

        $base = ['monto' => null, 'valor_original' => $orig, 'nota' => $orig];

        if ($l === 'c') {
            // "c" antes del alta = aún no afiliado, no genera pago
            if ($altaYm === null || substr($periodo, 0, 7) < $altaYm) {
                return null;
            }
            return $base + ['pagado' => 0, 'puntaje' => self::P_C_TARDIA];
        }

        // Fórmulas que quedaron como texto. Solo se evalúan las sumas simples (=a+b+c)
        if ($l !== '' && $l[0] === '=') {
            if (preg_match('/^=[\d+\s.]+$/', $l)) {
                $monto = (int) array_sum(array_map('intval', explode('+', substr($l, 1))));
                return ['monto' => $monto] + $base + ['pagado' => 1, 'puntaje' => self::P_PAGADO];
            }
            return $base + ['pagado' => 0, 'puntaje' => self::P_IMPAGO];
        }

        foreach (['pagado', 'pago', 'paga', 'webpay', 'transfirio', 'deposito', 'anual', 'pagador'] as $p) {
            if (str_starts_with($l, $p)) {
                return $base + ['pagado' => 1, 'puntaje' => self::P_PAGADO];
            }
        }

        // prorroga, exonerado, enviado, falta de fondos, tarjeta..., excede maximo, mandato..., etc.
        return $base + ['pagado' => 0, 'puntaje' => self::P_IMPAGO];
    }

    /**
     * Carga los pagos de las pólizas Odontólogo que hay en la base, indexados por "rut|periodo".
     * Permite decidir en memoria si un mes del Excel es nuevo, cambió o es igual. Los pagos de
     * otras pólizas de la misma persona no se cargan, así que la sincronización nunca los toca.
     */
    private function cargarPagosExistentes(int $productoId, ?array $ruts = null): array
    {
        $mapa = [];
        $q = $this->pdo->prepare(
            'SELECT p.id, z.rut, p.periodo, p.pagado, p.monto, p.editado
             FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id
             WHERE z.producto_id = ?'
            . ($ruts === null ? '' : ' AND z.rut IN (' . $this->marcadores($ruts) . ')')
        );
        $q->execute(array_merge([$productoId], $ruts ?? []));
        while ($r = $q->fetch()) {
            $mapa[$r['rut'] . '|' . $r['periodo']] = $r;
        }
        return $mapa;
    }

    /**
     * Huellas ("rut|md5") de las afiliaciones anteriores ya guardadas en el historial,
     * para no registrar la misma afiliación en cada sincronización.
     */
    private function cargarHistorialAfiliaciones(?array $ruts = null): array
    {
        $mapa = [];
        $q = $this->pdo->prepare(
            "SELECT registro, valor_anterior FROM historial_cambios WHERE campo = 'afiliacion_anterior'"
            . ($ruts === null ? '' : ' AND registro IN (' . $this->marcadores($ruts) . ')')
        );
        $q->execute(array_map('strval', $ruts ?? []));
        while ($r = $q->fetch()) {
            $mapa[$r['registro'] . '|' . md5($this->sinFila($r['valor_anterior']))] = true;
        }
        return $mapa;
    }

    /** "?,?,?" con un marcador por elemento, para armar un IN (...) con parámetros. */
    private function marcadores(array $valores): string
    {
        return implode(',', array_fill(0, max(1, count($valores)), '?'));
    }

    /** Quita el número de fila de Excel del JSON de una afiliación (cambia si se reordena la hoja). */
    private function sinFila(string $json): string
    {
        $d = json_decode($json, true) ?: [];
        unset($d['fila_excel']);
        return json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Inserta varios pagos nuevos en una sola sentencia (mucho más rápido que uno a uno).
     * @param array $lote lista de filas [poliza_id, periodo, pagado, monto, importacion_id, origen, valor_original, nota]
     */
    private function insertarPagos(array $lote): void
    {
        if (!$lote) {
            return;
        }
        $sql = 'INSERT INTO pagos_mensuales (poliza_id, periodo, pagado, monto, importacion_id, origen, valor_original, nota) VALUES '
             . implode(',', array_fill(0, count($lote), '(?,?,?,?,?,?,?,?)'));
        $this->pdo->prepare($sql)->execute(array_merge(...$lote));
    }

    // ------------------------------------------------------------ catálogos

    /** Lee los catálogos existentes a la caché (nombre => id). */
    private function cargarCatalogos(): void
    {
        foreach (array_keys($this->catalogos) as $tabla) {
            $this->catalogos[$tabla] = $this->pdo->query("SELECT nombre, id FROM $tabla")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }

    /**
     * Devuelve el id de un valor de catálogo, creándolo si es la primera vez que aparece.
     * Así los catálogos (coberturas, medios de pago, motivos de baja) se arman solos con
     * lo que trae el Excel. Devuelve null si no hay valor.
     *
     * @param string $tabla tabla de catálogo (valor fijo del código, nunca viene del usuario)
     */
    private function idCatalogo(string $tabla, ?string $nombre): ?int
    {
        if ($nombre === null) {
            return null;
        }
        if (!isset($this->catalogos[$tabla][$nombre])) {
            $this->pdo->prepare("INSERT INTO $tabla (nombre) VALUES (?)")->execute([$nombre]);
            $this->catalogos[$tabla][$nombre] = (int) $this->pdo->lastInsertId();
        }
        return (int) $this->catalogos[$tabla][$nombre];
    }

    // ------------------------------------------------------------ utilidades

    /** Número serial de Excel (días desde 1900) → 'YYYY-MM-DD'. Devuelve null si no es una fecha. */
    private function serialAFecha(mixed $v): ?string
    {
        if (!is_numeric($v) || $v <= 0) {
            return null;
        }
        // 25569 = días entre el origen de Excel (1900) y el de Unix (1970)
        return gmdate('Y-m-d', (int) round(($v - 25569) * 86400));
    }

    /** Recorta y colapsa espacios repetidos. Devuelve null si queda vacío. */
    private function limpiar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return $s === '' ? null : $s;
    }

    /** Dígito verificador de un RUT chileno (módulo 11): '0'-'9' o 'K'. */
    private function calcularDv(int $n): string
    {
        $suma = 0;
        $m = 2;
        foreach (array_reverse(str_split((string) $n)) as $d) {
            $suma += $d * $m;
            $m = $m === 7 ? 2 : $m + 1;
        }
        $r = 11 - ($suma % 11);
        return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
    }

    /**
     * Interpreta la celda "RUT PAGADOR", que viene en formatos mezclados.
     * Acepta "12.345.678-9", 12345678 (sin dígito verificador) y 123456789 (con dígito pegado).
     * Si no se puede interpretar (ej. "ELIMINO PAT") devuelve null; el texto original igual
     * se guarda aparte en `rut_pagador_original`.
     *
     * @return array{0:int, 1:string}|null  [rut, dv]
     */
    private function parsearRutPagador(mixed $v): ?array
    {
        $s = $this->limpiar($v);
        if ($s === null) {
            return null;
        }
        $s = strtoupper(str_replace(['.', ' '], '', $s));
        if (preg_match('/^(\d{1,9})-([\dK])$/', $s, $m)) {
            return [(int) $m[1], $m[2]];
        }
        if (!ctype_digit($s) || (int) $s === 0) {
            return null;
        }
        // Solo dígitos: primero se prueba si el último ya es un dígito verificador válido...
        if (strlen($s) >= 7) {
            $cuerpo = (int) substr($s, 0, -1);
            if ($this->calcularDv($cuerpo) === substr($s, -1)) {
                return [$cuerpo, substr($s, -1)];
            }
        }
        // ...y si no, se asume que es el RUT sin dígito y se calcula
        $n = (int) $s;
        return [$n, $this->calcularDv($n)];
    }

    /**
     * Unifica las variantes del medio de pago ("PAT PAGADOR" → "PAT", "Transferencia" →
     * "TRANSFERENCIA", etc.). Los valores desconocidos se conservan en mayúsculas.
     */
    private function normalizarMedioPago(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = strtoupper($v);
        return match (true) {
            str_starts_with($v, 'PAT') => 'PAT',
            str_starts_with($v, 'PAC') => 'PAC',
            str_contains($v, 'MERCADO') => 'MERCADO PAGO',
            str_contains($v, 'TRANSFER') => 'TRANSFERENCIA',
            str_contains($v, 'SIN MEDIO') => 'SIN MEDIO DE PAGO',
            str_contains($v, 'ANUALIDAD') => 'ANUALIDAD',
            str_contains($v, 'SEMESTRE') => 'SEMESTRE',
            default => $v,
        };
    }
}
