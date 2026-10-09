<?php

namespace App\Services;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/**
 * Importador de la nómina maestra ("Nomina con Poliza.xlsx") y del Excel de pagos por póliza
 * ("PAGOS POLIZAS MENSUALES INDIVIDUAL ...xlsx").
 *
 * ── Qué hace ──────────────────────────────────────────────────────────────────
 *   planificar()  lee los dos archivos, los cruza con la base de datos y arma un PLAN:
 *                 qué personas, sociedades, pólizas, períodos y pagos se crearían o completarían.
 *                 Solo hace SELECT: no escribe nada. Es el "modo informe".
 *   aplicar()     carga ese mismo plan en la base, en UNA transacción: si algo falla o alguna
 *                 verificación no cuadra, no queda nada a medias. Lo que se ve en el informe es
 *                 exactamente lo que se carga.
 *
 * ── Reglas de negocio (acordadas con el usuario) ─────────────────────────────
 *   - Una póliza dura 12 meses. Si falta el término, es inicio + 1 año. Un tramo de más de
 *     13 meses no es una póliza anual: se toma su último año.
 *   - El producto sale del NÚMERO de póliza (ver PRODUCTO_POR_NUMERO). El número cambia cada
 *     año; los números desconocidos se guardan tal cual y la póliza queda sin producto.
 *   - Sin número de póliza → 99999, para poder guardar sus pagos y editarlos después.
 *   - El TIPO del Excel (INDIVIDUAL, DERMO, SOLO LEGA…) es solo una descripción de la persona
 *     ("otro tipo"); no define el producto.
 *   - Lo que ya está en la base NO se pisa: a las personas existentes solo se les completan
 *     los campos vacíos; las pólizas existentes conservan su estado y sus pagos.
 *   - La renuncia es de una póliza, no de la persona.
 *   - Las clínicas (tipo CLINICA) también se cargan, como cualquier persona; mientras no se
 *     entregue su póliza quedan con el número 99999.
 *   - "PAT" en la celda de un mes es una cuota pagada con PAT.
 */
class ImportadorNominaMaestra
{
    /** Producto de cada número de póliza matriz conocido. 'inferido' = deducido de los datos, a confirmar. */
    public const PRODUCTO_POR_NUMERO = [
        // Tabla confirmada por el usuario (2026-10-07). Cada número de póliza dura 18 meses y se renueva solo; los
        // números más antiguos se ignoran. Individual y Derma comparten el 26158 (Derma es una especialidad).
        '26157' => ['Odontólogo', 'confirmado'],  // vence 01-07-2027
        '24818' => ['Odontólogo', 'confirmado'],  // anterior
        '26158' => ['Individual', 'confirmado'],
        '24930' => ['Individual', 'confirmado'],  // anterior
        '28581' => ['SOEMAF', 'confirmado'],
        '24935' => ['SOEMAF', 'confirmado'],      // anterior
        '26159' => ['SOEMAF', 'confirmado'],      // según la hoja P_SOEMAF
        // Números antiguos, deducidos de los datos (se ignorarán cuando se implemente la regla de 18 meses)
        '22882' => ['Individual', 'inferido'],
        '22883' => ['SOEMAF', 'inferido'],
    ];

    public const NUMERO_SIN_POLIZA = '99999';
    /** false: los períodos ya no se cargan desde el Excel; salen de las pólizas matrices (18 meses por número). */
    public const IMPORTAR_PERIODOS_DEL_EXCEL = false;
    public const PRODUCTO_MADRE = 'Odontólogo';

    /** Un tramo de más de esta cantidad de días no es una póliza anual. */
    private const DIAS_MAX_ANUAL = 400;
    /** Hueco (o solape) entre períodos consecutivos a partir del cual se informa una laguna. */
    private const DIAS_LAGUNA = 31;

    /** Textos de una celda de mes que cuentan como pagado (la "tra…" cubre los errores de tipeo de transfirió). */
    private const PREFIJOS_PAGADO = ['pagado', 'pago', 'paga', 'webpay', 'transf', 'trasnf', 'trasns', 'deposito', 'anual', 'pagador'];

    // Columnas (base 0) de la hoja ODONTOLOGOS
    private const O = ['rut' => 0, 'dv' => 1, 'nombre' => 3, 'direccion' => 4, 'comuna' => 5, 'telefono' => 6, 'mail' => 7,
        'nacimiento' => 8, 'especialidad' => 9, 'fecha_cia' => 10, 'tipo_persona' => 12, 'soc_nombre' => 13, 'soc_rut' => 14,
        'soc_dv' => 15, 'soc_direccion' => 16, 'soc_comuna' => 17, 'numero' => 19, 'inicio' => 20, 'termino' => 21,
        'tipo_contrato' => 22, 'estado' => 24, 'ejecutivo' => 25, 'envio_cert' => 26, 'envio_pol' => 27, 'valor_lega' => 28,
        'valor_poliza' => 29, 'monto' => 30, 'compania' => 31, 'corredor' => 32, 'renuncia' => 33, 'obs' => 34, 'n_cert' => 35];

    // Columnas (base 0) de la hoja INDIVIDUALES. Los números de póliza por año van en 32..38 (2019-2020 … 2025-2026).
    private const I = ['convenio' => 0, 'rut' => 1, 'dv' => 2, 'nombre' => 4, 'direccion' => 5, 'comuna' => 6, 'telefono' => 7,
        'mail' => 8, 'nacimiento' => 9, 'especialidad' => 10, 'ingreso' => 11, 'tipo_persona' => 12, 'soc_nombre' => 13,
        'soc_direccion' => 14, 'soc_rut' => 15, 'giro' => 16, 'contrato' => 17, 'fecha_lega' => 18, 'ejecutivo' => 20,
        'envio_cert' => 21, 'envio_pol' => 22, 'valor_lega' => 23, 'valor_poliza' => 24, 'monto' => 25, 'compania' => 27,
        'corredor' => 28, 'estado' => 29, 'obs' => 30, 'renuncia' => 31, 'anios' => 32, 'inicio' => 39, 'termino' => 40,
        'deducible' => 42];

    // Columnas (base 0) del Excel de pagos. Los meses empiezan en la columna 20.
    private const P = ['tipo' => 0, 'estatus' => 1, 'numero' => 2, 'pagador' => 3, 'medio' => 4, 'inicio' => 5, 'termino' => 6,
        'especialidad' => 7, 'deducible' => 8, 'monto' => 13, 'rut' => 14, 'dv' => 15, 'nombre' => 16, 'primer_mes' => 20];

    public function __construct(private PDO $pdo)
    {
    }

    // =====================================================================================
    //  PLAN
    // =====================================================================================

    /**
     * Lee los archivos y arma el plan completo (sin escribir nada).
     *
     * @return array{personas:array, sociedades:array, polizas:array, stats:array}
     */
    public function planificar(string $nominaXlsx, string $pagosXlsx): array
    {
        $bd = $this->cargarBase();
        [$odo, $ind, $comunas] = $this->leerNomina($nominaXlsx);
        [$pagos, $columnasMes] = $this->leerPagos($pagosXlsx);

        $s = $this->estadisticasVacias();
        $personas = [];
        $sociedades = [];
        $polizas = [];

        // ---- 1. ODONTOLOGOS: pólizas de dentistas (todas con el número 26157) ----------------
        foreach ($this->agruparPorRut($odo, self::O['rut'], $s, 'ODONTOLOGOS') as $rut => $filas) {
            usort($filas, fn($a, $b) => strcmp((string) $this->fecha($a[self::O['inicio']]), (string) $this->fecha($b[self::O['inicio']])));
            foreach ($filas as $f) {
                $this->fusionarPersona($personas[$rut], $rut, [
                    'dv' => $this->texto($f[self::O['dv']]),
                    'nombre' => $this->nombrePersona($f[self::O['nombre']], $s),
                    'telefono' => $this->telefono($f[self::O['telefono']]),
                    'mail' => $this->mail($f[self::O['mail']]),
                    'direccion' => $this->texto($f[self::O['direccion']]),
                    'comuna' => $this->texto($f[self::O['comuna']]),
                    'fecha_nacimiento' => $this->fecha($f[self::O['nacimiento']]),
                    'especialidad' => $this->texto($f[self::O['especialidad']]),
                    'ejecutivo' => $this->texto($f[self::O['ejecutivo']]),
                ], 'ODONTOLOGOS');
                $this->vincularSociedad($personas[$rut], $sociedades, $s, [
                    'nombre' => $f[self::O['soc_nombre']], 'rut' => $f[self::O['soc_rut']], 'dv' => $f[self::O['soc_dv']],
                    'direccion' => $f[self::O['soc_direccion']], 'comuna' => $f[self::O['soc_comuna']], 'giro' => null,
                ]);
            }
            // La póliza se arma con todas las filas (cada una aporta un período); los atributos, de la última
            $ultima = end($filas);
            $numero = $this->numero($ultima[self::O['numero']]);
            $this->agregarPoliza($polizas, $rut, $numero, [
                'tipo_contrato' => $this->contrato($ultima[self::O['tipo_contrato']]),
                'compania' => $this->nombreCorto($ultima[self::O['compania']]),
                'corredor' => $this->nombreCorto($ultima[self::O['corredor']]),
                'cobertura' => $this->cobertura($ultima[self::O['monto']]),
                'valor_lega' => $this->monto($ultima[self::O['valor_lega']]),
                'valor_poliza' => $this->monto($ultima[self::O['valor_poliza']]),
                'estado' => $this->estado($ultima[self::O['estado']]),
                'fecha_renuncia' => $this->fecha($ultima[self::O['renuncia']]),
                'fecha_envio_certificado' => $this->fecha($ultima[self::O['envio_cert']]),
                'fecha_envio_poliza' => $this->fecha($ultima[self::O['envio_pol']]),
                'numero_certificado' => $this->texto($ultima[self::O['n_cert']]),
                'observaciones' => $this->texto($ultima[self::O['obs']]),
                'fecha_alta' => $this->fecha($filas[0][self::O['fecha_cia']]) ?? $this->fecha($filas[0][self::O['inicio']]),
            ], 'ODONTOLOGOS', $s);
            $clave = $this->clavePoliza($rut, $numero);
            foreach ($filas as $f) {
                $this->agregarPeriodo($polizas[$clave], $this->fecha($f[self::O['inicio']]), $this->fecha($f[self::O['termino']]),
                    $this->numero($f[self::O['numero']]), 'nomina', $s);
            }
        }

        // ---- 2. INDIVIDUALES: pólizas de los convenios (INDIVIDUAL, SOEMAF, DERMO, CETEP…) ------
        foreach ($this->agruparPorRut($ind, self::I['rut'], $s, 'INDIVIDUALES') as $rut => $filas) {
            usort($filas, fn($a, $b) => strcmp((string) $this->fecha($a[self::I['inicio']]), (string) $this->fecha($b[self::I['inicio']])));
            foreach ($filas as $f) {
                $this->fusionarPersona($personas[$rut], $rut, [
                    'dv' => $this->texto($f[self::I['dv']]),
                    'nombre' => $this->nombrePersona($f[self::I['nombre']], $s),
                    'telefono' => $this->telefono($f[self::I['telefono']]),
                    'mail' => $this->mail($f[self::I['mail']]),
                    'direccion' => $this->texto($f[self::I['direccion']]),
                    'comuna' => $this->texto($f[self::I['comuna']]),
                    'fecha_nacimiento' => $this->fecha($f[self::I['nacimiento']]),
                    'especialidad' => $this->texto($f[self::I['especialidad']]),
                    'ejecutivo' => $this->texto($f[self::I['ejecutivo']]),
                    'otro_tipo_nomina' => $this->otroTipo($f[self::I['convenio']]),
                ], 'INDIVIDUALES');
                $this->vincularSociedad($personas[$rut], $sociedades, $s, [
                    'nombre' => $f[self::I['soc_nombre']], 'rut' => $f[self::I['soc_rut']], 'dv' => null,
                    'direccion' => $f[self::I['soc_direccion']], 'comuna' => null, 'giro' => $f[self::I['giro']],
                ]);
            }
            // Una póliza por producto: las filas se reparten según el número de póliza de cada una
            $porClave = [];
            foreach ($filas as $f) {
                $numero = $this->numeroVigenteIndividual($f);
                $porClave[$this->clavePoliza($rut, $numero)][] = [$f, $numero];
            }
            foreach ($porClave as $clave => $grupo) {
                [$ultima, $numero] = end($grupo);
                $this->agregarPoliza($polizas, $rut, $numero, [
                    'tipo_contrato' => $this->contrato($ultima[self::I['contrato']]),
                    'compania' => $this->nombreCorto($ultima[self::I['compania']]),
                    'corredor' => $this->nombreCorto($ultima[self::I['corredor']]),
                    'cobertura' => $this->cobertura($ultima[self::I['monto']]),
                    'valor_lega' => $this->monto($ultima[self::I['valor_lega']]),
                    'valor_poliza' => $this->monto($ultima[self::I['valor_poliza']]),
                    'deducible_uf' => $this->monto($ultima[self::I['deducible']]),
                    'estado' => $this->estado($ultima[self::I['estado']]),
                    'fecha_renuncia' => $this->fecha($ultima[self::I['renuncia']]),
                    'fecha_envio_certificado' => $this->fecha($ultima[self::I['envio_cert']]),
                    'fecha_envio_poliza' => $this->fecha($ultima[self::I['envio_pol']]),
                    'observaciones' => $this->texto($ultima[self::I['obs']]),
                    'fecha_alta' => $this->fecha($grupo[0][0][self::I['fecha_lega']]) ?? $this->fecha($grupo[0][0][self::I['ingreso']]),
                ], 'INDIVIDUALES', $s);
                foreach ($grupo as [$f, $n]) {
                    $this->agregarPeriodo($polizas[$clave], $this->fecha($f[self::I['inicio']]), $this->fecha($f[self::I['termino']]), $n, 'nomina', $s);
                }
            }
        }

        // ---- 3. Excel de pagos: personas, estado, medio de pago y los pagos mes a mes -------------
        foreach ($this->agruparPorRut($pagos, self::P['rut'], $s, 'PAGOS') as $rut => $filas) {
            foreach ($filas as $f) { // las filas más abajo en el Excel son las más recientes
                $this->fusionarPersona($personas[$rut], $rut, [
                    'dv' => $this->texto($f[self::P['dv']]),
                    'nombre' => $this->nombrePersona($f[self::P['nombre']], $s),
                    'especialidad' => $this->texto($f[self::P['especialidad']]),
                    'otro_tipo_pagos' => $this->otroTipo($f[self::P['tipo']]),
                ], 'PAGOS');
            }
            foreach ($filas as $f) {
                $numero = $this->numero($f[self::P['numero']]);
                $clave = $this->clavePolizaDePagos($polizas, $rut, $numero);
                $this->agregarPoliza($polizas, $rut, $numero, [
                    'estado' => $this->estado($f[self::P['estatus']]),
                    'medio_pago' => $this->medioPago($f[self::P['medio']]),
                    'deducible_uf' => $this->monto($f[self::P['deducible']]),
                    'cobertura' => $this->cobertura($f[self::P['monto']]),
                    'pagador_original' => $this->texto($f[self::P['pagador']]),
                ], 'PAGOS', $s, $clave);
                $this->agregarPeriodo($polizas[$clave], $this->fecha($f[self::P['inicio']]), $this->fecha($f[self::P['termino']]), $numero, 'nomina', $s);
                foreach ($columnasMes as $col => $periodo) {
                    $celda = $this->clasificarMes($f[$col] ?? null, $s);
                    if ($celda === null) continue;
                    $actual = $polizas[$clave]['pagos'][$periodo] ?? null;
                    // Si dos filas traen el mismo mes: un pago le gana a un impago
                    if ($actual === null || $celda['pagado'] >= $actual['pagado']) {
                        $polizas[$clave]['pagos'][$periodo] = $celda;
                    }
                }
            }
        }

        // ---- 4. Resolver contra la base de datos y calcular lo que cambiaría -------------------
        $this->resolverPersonas($personas, $sociedades, $comunas, $bd, $s);
        $this->resolverPolizas($polizas, $personas, $bd, $s);

        return ['personas' => $personas, 'sociedades' => $sociedades, 'polizas' => $polizas, 'stats' => $s];
    }

    // =====================================================================================
    //  Resolución contra la base
    // =====================================================================================

    /** Carga lo que ya existe: personas, pólizas y pagos de las pólizas existentes. */
    private function cargarBase(): array
    {
        $bd = ['personas' => [], 'polizas' => [], 'pagos' => [], 'productos' => [], 'sociedades_rut' => []];
        foreach ($this->pdo->query('SELECT * FROM asegurados') as $r) $bd['personas'][(int) $r['rut']] = $r;
        foreach ($this->pdo->query('SELECT id, nombre FROM productos') as $r) $bd['productos'][$r['nombre']] = (int) $r['id'];
        foreach ($this->pdo->query('SELECT z.*, pr.nombre AS producto FROM polizas z LEFT JOIN productos pr ON pr.id = z.producto_id') as $r) {
            $bd['polizas'][(int) $r['rut'] . '|' . ($r['producto'] ?? '')] = $r;
        }
        foreach ($this->pdo->query("SELECT poliza_id, DATE_FORMAT(periodo,'%Y-%m') AS m, pagado FROM pagos_mensuales") as $r) {
            $bd['pagos'][(int) $r['poliza_id']][$r['m']] = (int) $r['pagado'];
        }
        foreach ($this->pdo->query('SELECT rut FROM sociedades WHERE rut IS NOT NULL') as $r) $bd['sociedades_rut'][(int) $r['rut']] = true;
        return $bd;
    }

    /** Decide qué persona es nueva y qué campos vacíos de las existentes se completarían. */
    private function resolverPersonas(array &$personas, array &$sociedades, array $comunas, array $bd, array &$s): void
    {
        $campos = ['mail', 'telefono', 'direccion', 'comuna', 'ciudad', 'fecha_nacimiento', 'especialidad', 'ejecutivo'];
        foreach ($personas as $rut => &$p) {
            // Ciudad: sale de la hoja REGIONES a partir de la comuna
            if (!empty($p['comuna'])) {
                $p['ciudad'] = $comunas[$this->normalizar($p['comuna'])] ?? null;
                if ($p['ciudad']) {
                    $s['ciudades_resueltas']++;
                } else {
                    // La hoja REGIONES no lista las comunas que son ciudades (Concepción, Antofagasta…): la ciudad es la comuna
                    $p['ciudad'] = mb_convert_case($p['comuna'], MB_CASE_UPPER);
                    $s['ciudades_sin_resolver']++;
                    $s['comunas_sin_ciudad'][$p['comuna']] = ($s['comunas_sin_ciudad'][$p['comuna']] ?? 0) + 1;
                }
            }
            // "Otro tipo": gana la nómina maestra (INDIVIDUALES); si no, el Excel de pagos
            $a = $p['otro_tipo_nomina'] ?? null;
            $b = $p['otro_tipo_pagos'] ?? null;
            if ($a && $b && $a !== $b) { $s['otro_tipo_conflictos']["$a  vs  $b"] = ($s['otro_tipo_conflictos']["$a  vs  $b"] ?? 0) + 1; }
            $p['otro_tipo'] = $a ?? $b;
            if ($p['otro_tipo']) $s['otro_tipo'][$p['otro_tipo']] = ($s['otro_tipo'][$p['otro_tipo']] ?? 0) + 1;

            $p['nombre'] = $p['nombre'] ?? '(sin nombre)';
            $p['dv_calculado'] = $this->calcularDv($rut);
            $p['dv_valido'] = isset($p['dv']) ? (strtoupper($p['dv']) === $p['dv_calculado'] ? 1 : 0) : 1;
            if (!$p['dv_valido']) $s['dv_invalido']++;
            $p['dv'] = strtoupper($p['dv'] ?? $p['dv_calculado']);

            $existente = $bd['personas'][$rut] ?? null;
            if ($existente === null) {
                $p['accion'] = 'CREAR';
                $s['personas_nuevas']++;
            } else {
                $p['accion'] = 'COMPLETAR';
                $p['completa'] = [];
                foreach ($campos as $c) {
                    if (($existente[$c] === null || $existente[$c] === '') && !empty($p[$c])) $p['completa'][] = $c;
                }
                if (!$existente['sociedad_id'] && !empty($p['sociedad'])) $p['completa'][] = 'sociedad';
                if (!$existente['tipo_individuo_id'] && !empty($p['otro_tipo'])) $p['completa'][] = 'otro_tipo';
                foreach ($p['completa'] as $c) $s['completa_campos'][$c] = ($s['completa_campos'][$c] ?? 0) + 1;
                $s['personas_existentes']++;
                $s[$p['completa'] ? 'personas_existentes_con_cambios' : 'personas_existentes_sin_cambios']++;
            }
        }
        unset($p);

        foreach ($sociedades as $k => $soc) {
            $s['sociedades'][!empty($soc['rut']) && isset($bd['sociedades_rut'][$soc['rut']]) ? 'ya_existen' : 'nuevas']++;
        }
    }

    /** Decide qué pólizas son nuevas, cuáles se actualizan, y cuántos pagos y períodos se crearían. */
    private function resolverPolizas(array &$polizas, array $personas, array $bd, array &$s): void
    {
        foreach ($polizas as $clave => &$z) {
            $existente = $bd['polizas'][$z['rut'] . '|' . ($z['producto'] ?? '')] ?? null;
            // Una póliza sin producto no se puede identificar en la base: se considera nueva
            if ($z['producto'] === null) $existente = null;
            $z['poliza_id'] = $existente ? (int) $existente['id'] : null;
            $z['accion'] = $existente ? 'ACTUALIZAR' : 'CREAR';
            $p = $z['producto'] ?? ('sin producto (' . $z['numero'] . ')');

            // Períodos: ordenados, con las lagunas entre uno y el siguiente
            ksort($z['periodos']);
            $prevHasta = null;
            foreach ($z['periodos'] as $desde => $per) {
                if ($prevHasta !== null) {
                    $dias = (int) ((strtotime($desde) - strtotime($prevHasta)) / 86400);
                    if ($dias > self::DIAS_LAGUNA) { $z['lagunas'][] = "$prevHasta a $desde ($dias días)"; }
                    elseif ($dias < -self::DIAS_LAGUNA) { $z['solapes'][] = "$desde antes de $prevHasta"; }
                }
                $prevHasta = $per['hasta'];
                $s['periodos']['total']++;
                $s['periodos'][$per['calculado'] ? 'termino_calculado' : 'termino_del_excel']++;
            }
            if (!empty($z['lagunas'])) $s['polizas_con_lagunas']++;
            if (!empty($z['solapes'])) $s['polizas_con_solapes']++;

            $s['polizas'][$z['accion']][$p] = ($s['polizas'][$z['accion']][$p] ?? 0) + 1;
            if ($z['numero'] === self::NUMERO_SIN_POLIZA) $s['polizas_99999']++;
            elseif ($z['producto'] === null) {
                $tipo = $personas[$z['rut']]['otro_tipo'] ?? '(sin tipo)';
                $s['numeros_sin_producto_tipos'][$z['numero']][$tipo] = ($s['numeros_sin_producto_tipos'][$z['numero']][$tipo] ?? 0) + 1;
            }

            // Estado: la póliza existente conserva el suyo; las nuevas usan el del Excel
            if ($z['accion'] === 'CREAR') {
                $z['estado'] = $z['estado'] ?? 'NO VIGENTE';
                // Una póliza a la que se renunció no puede quedar vigente, aunque la hoja lo diga
                if (!empty($z['fecha_renuncia']) && $z['fecha_renuncia'] <= date('Y-m-d') && $z['estado'] !== 'NO VIGENTE') {
                    $z['estado'] = 'NO VIGENTE';
                    $s['estado_corregido_por_renuncia']++;
                }
                $s['estados_nuevas'][$z['estado']] = ($s['estados_nuevas'][$z['estado']] ?? 0) + 1;
            }

            // Pagos: nuevos / ya existentes iguales / en conflicto con lo que hay en la base
            $enBd = $z['poliza_id'] ? ($bd['pagos'][$z['poliza_id']] ?? []) : [];
            foreach ($z['pagos'] as $periodo => $celda) {
                $m = substr($periodo, 0, 7);
                if (!isset($enBd[$m])) {
                    $s['pagos']['nuevos']++;
                    $s['pagos'][$celda['pagado'] ? 'nuevos_pagados' : 'nuevos_impagos']++;
                } elseif ($enBd[$m] === $celda['pagado']) {
                    $s['pagos']['ya_en_base_iguales']++;
                } else {
                    $s['pagos']['en_conflicto_no_se_tocan']++;
                }
            }
        }
        unset($z);
    }

    // =====================================================================================
    //  Construcción del plan
    // =====================================================================================

    /** Producto (o null) y su nivel de certeza para un número de póliza. */
    private function productoDe(?string $numero): array
    {
        return self::PRODUCTO_POR_NUMERO[$numero ?? ''] ?? [null, null];
    }

    /** "rut|Producto" o, si el número no se reconoce, "rut|?número". */
    private function clavePoliza(int $rut, ?string $numero): string
    {
        [$producto] = $this->productoDe($numero);
        return $rut . '|' . ($producto ?? '?' . ($numero ?? self::NUMERO_SIN_POLIZA));
    }

    /**
     * A qué póliza de la persona van los pagos de una fila del Excel de pagos:
     *   1. si el número identifica un producto, a la póliza de ese producto;
     *   2. si no, y la persona tiene UNA sola póliza que no es de Odontólogo, a esa;
     *   3. si no, a una póliza sin producto con ese número (o 99999).
     */
    private function clavePolizaDePagos(array $polizas, int $rut, ?string $numero): string
    {
        [$producto] = $this->productoDe($numero);
        if ($producto !== null) return $rut . '|' . $producto;

        $otras = [];
        foreach ($polizas as $clave => $z) {
            if ($z['rut'] === $rut && $z['producto'] !== self::PRODUCTO_MADRE) $otras[] = $clave;
        }
        if (count($otras) === 1) return $otras[0];
        return $rut . '|?' . ($numero ?? self::NUMERO_SIN_POLIZA);
    }

    /** Crea la póliza en el plan si no existe y le agrega los atributos que traiga la fuente (los nulos no pisan). */
    private function agregarPoliza(array &$polizas, int $rut, ?string $numero, array $atributos, string $fuente, array &$s, ?string $clave = null): void
    {
        $clave ??= $this->clavePoliza($rut, $numero);
        [$producto, $certeza] = $this->productoDe($numero);
        if (!isset($polizas[$clave])) {
            // Un número desconocido se guarda tal cual; sin número va 99999
            if (str_starts_with(explode('|', $clave)[1], '?')) {
                $numero = substr(explode('|', $clave)[1], 1);
                $producto = null;
            }
            $polizas[$clave] = [
                'rut' => $rut, 'producto' => $producto, 'certeza_producto' => $certeza,
                'numero' => $numero ?? self::NUMERO_SIN_POLIZA, 'periodos' => [], 'pagos' => [], 'fuentes' => [],
            ];
            if ($producto === null) {
                $k = $polizas[$clave]['numero'];
                $s['numeros_sin_producto'][$k] = ($s['numeros_sin_producto'][$k] ?? 0) + 1;
            } elseif ($certeza === 'inferido') {
                $s['productos_inferidos'][$numero . ' → ' . $producto] = ($s['productos_inferidos'][$numero . ' → ' . $producto] ?? 0) + 1;
            }
        }
        $z = &$polizas[$clave];
        $z['fuentes'][$fuente] = true;
        // El número más reciente conocido (no 99999) es el de la póliza
        if ($numero !== null && ($z['numero'] === self::NUMERO_SIN_POLIZA || ($z['producto'] !== null && (int) $numero > (int) $z['numero']))) {
            $z['numero'] = $numero; // dentro de un producto, el número de mayor valor es el del año más reciente
        }
        foreach ($atributos as $k => $v) {
            if ($v !== null && $v !== '') $z[$k] = $v; // las fuentes más recientes (más abajo en el plan) sobrescriben
        }
    }

    /**
     * Agrega un período anual. Si falta el término (o no es posterior al inicio) es inicio + 1 año;
     * un tramo de más de 13 meses se reduce a su último año.
     */
    private function agregarPeriodo(array &$poliza, ?string $desde, ?string $hasta, ?string $numero, string $origen, array &$s): void
    {
        // Las fechas por persona del Excel eran inconsistentes: la vigencia sale de las pólizas matrices (scripts/aplicar_matrices.php)
        if (!self::IMPORTAR_PERIODOS_DEL_EXCEL || $desde === null) return;
        $calculado = false;
        if ($hasta === null || $hasta <= $desde) {
            $hasta = date('Y-m-d', strtotime($desde . ' +1 year'));
            $calculado = true;
        } elseif ((strtotime($hasta) - strtotime($desde)) / 86400 > self::DIAS_MAX_ANUAL) {
            $desde = date('Y-m-d', strtotime($hasta . ' -1 year'));
            $s['periodos_recortados']++;
        }
        if (!isset($poliza['periodos'][$desde])) {
            $poliza['periodos'][$desde] = ['hasta' => $hasta, 'numero' => $numero, 'origen' => $origen, 'calculado' => $calculado];
        }
    }

    /** Suma datos a la persona: los valores no vacíos reemplazan a los anteriores (la última fuente manda). */
    private function fusionarPersona(?array &$p, int $rut, array $campos, string $fuente): void
    {
        $p ??= ['rut' => $rut, 'fuentes' => []];
        $p['fuentes'][$fuente] = true;
        foreach ($campos as $k => $v) {
            if ($v !== null && $v !== '') $p[$k] = $v;
        }
    }

    /** Registra la sociedad de la persona (si la hay) y las agrupa por RUT, o por nombre cuando no hay RUT legible. */
    private function vincularSociedad(array &$persona, array &$sociedades, array &$s, array $d): void
    {
        $nombre = $this->texto($d['nombre']);
        if ($nombre === '0') $nombre = null;
        $crudo = $this->texto($d['rut']);
        if ($crudo === '0') $crudo = null;
        [$rut, $dv, $resto] = $this->parsearRut($d['rut'], $d['dv']);

        // Error de digitación frecuente: en la columna del RUT hay un nombre o una dirección
        if ($rut === null && $crudo !== null) {
            $s['sociedades_rut_ilegible'][] = $crudo;
            if ($nombre === null && preg_match('/\b(SPA|LTDA|SOC|LIMITADA|ODONT)/i', $crudo)) $nombre = $crudo;
        }
        if ($nombre === null && $rut === null) return; // la persona no está en una sociedad

        $clave = $rut !== null ? "R$rut" : 'N' . $this->normalizar($nombre);
        $sociedades[$clave] ??= ['rut' => $rut, 'dv' => $dv, 'nombre' => $nombre ?? '(sin nombre)', 'direccion' => null, 'comuna' => null, 'giro' => null, 'personas' => 0];
        foreach (['direccion', 'comuna', 'giro'] as $c) {
            $v = $this->texto($d[$c]) ?? ($c === 'direccion' ? $resto : null);
            if ($v !== null && $v !== '0') $sociedades[$clave][$c] = $v;
        }
        if ($nombre !== null) $sociedades[$clave]['nombre'] = $nombre;
        if (($persona['sociedad'] ?? null) !== $clave) {
            $persona['sociedad'] = $clave;
            $sociedades[$clave]['personas']++;
        }
    }

    /** Número de póliza vigente de una fila de INDIVIDUALES: la última columna de año con valor. */
    private function numeroVigenteIndividual(array $f): ?string
    {
        for ($i = 6; $i >= 0; $i--) {
            $n = $this->numero($f[self::I['anios'] + $i] ?? null);
            if ($n !== null && ctype_digit($n)) return $n;
        }
        return null;
    }

    // =====================================================================================
    //  Lectura de archivos
    // =====================================================================================

    /** @return array{0:array, 1:array, 2:array<string,string>} filas de ODONTOLOGOS, de INDIVIDUALES y mapa comuna → ciudad */
    private function leerNomina(string $ruta): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $hojas = $reader->listWorksheetNames($ruta);
        foreach (['ODONTOLOGOS', 'INDIVIDUALES', 'REGIONES'] as $h) {
            if (!in_array($h, $hojas, true)) throw new RuntimeException("La nómina no tiene la hoja \"$h\".");
        }
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['ODONTOLOGOS', 'INDIVIDUALES', 'REGIONES']);
        $wb = $reader->load($ruta);
        $leer = function (string $nombre) use ($wb): array {
            $f = $wb->getSheetByName($nombre)->toArray(null, true, false, false); // true: valores de las fórmulas
            array_shift($f); // encabezado
            return $f;
        };
        $comunas = [];
        foreach ($leer('REGIONES') as $r) {
            if (!empty($r[0]) && !empty($r[1])) $comunas[$this->normalizar((string) $r[0])] = trim((string) $r[1]);
        }
        return [$leer('ODONTOLOGOS'), $leer('INDIVIDUALES'), $comunas];
    }

    /** @return array{0:array, 1:array<int,string>} filas del Excel de pagos y columna → 'AAAA-MM-01' de cada mes */
    private function leerPagos(string $ruta): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $filas = $reader->load($ruta)->getActiveSheet()->toArray(null, true, false, false); // true: valores de las fórmulas
        $enc = array_shift($filas);
        if (($enc[self::P['rut']] ?? null) !== 'RUT') throw new RuntimeException('El Excel de pagos cambió de formato: la columna O debería ser "RUT".');
        $meses = [];
        foreach ($enc as $i => $h) {
            if ($i >= self::P['primer_mes'] && is_numeric($h)) {
                $f = Date::excelToDateTimeObject($h)->format('Y-m-d');
                if (substr($f, 8, 2) !== '01') throw new RuntimeException("Las fechas de los meses llegaron corridas (columna $i: $f).");
                $meses[$i] = $f;
            }
        }
        return [$filas, $meses];
    }

    /**
     * Agrupa las filas por RUT descartando las que no tienen uno válido.
     * @return array<int,array>
     */
    private function agruparPorRut(array $filas, int $colRut, array &$s, string $fuente): array
    {
        $g = [];
        foreach ($filas as $f) {
            $rut = $f[$colRut] ?? null;
            if (!is_numeric($rut) || (int) $rut < 1000000) { // vacías, de relleno o RUT imposible
                if (array_filter($f, fn($c) => $c !== null && $c !== '')) $s['filas_sin_rut_valido'][$fuente] = ($s['filas_sin_rut_valido'][$fuente] ?? 0) + 1;
                continue;
            }
            $g[(int) $rut][] = $f;
        }
        $s['filas_por_fuente'][$fuente] = count($filas);
        $s['rut_por_fuente'][$fuente] = count($g);
        return $g;
    }


    // =====================================================================================
    //  APLICAR (carga el plan en la base)
    // =====================================================================================

    /** Cache de catálogos: tabla => nombre => id. */
    private array $catalogos = [];

    /**
     * Carga el plan en la base de datos, todo dentro de una transacción.
     *
     * Antes de confirmar se verifica que:
     *   - el número de personas, pólizas, sociedades, períodos y pagos es el esperado;
     *   - los pagos y el estado de las pólizas que YA existían no cambiaron.
     * Si algo no cuadra se deshace todo y se lanza una excepción.
     *
     * @return array{antes:array, despues:array, insertados:array}
     */
    public function aplicar(array $plan): array
    {
        $antes = $this->cifras();
        $ins = ['personas' => 0, 'personas_completadas' => 0, 'sociedades' => 0, 'polizas' => 0, 'polizas_completadas' => 0,
            'periodos' => 0, 'pagos' => 0];

        $this->pdo->beginTransaction();
        try {
            $sociedadIds = $this->guardarSociedades($plan['sociedades'], $ins);
            $this->guardarPersonas($plan['personas'], $sociedadIds, $ins);
            $this->guardarPolizas($plan['polizas'], $ins);

            // ---- verificación antes de confirmar ----
            $despues = $this->cifras($antes['max_poliza_id']);
            $esperado = [
                'personas' => $antes['personas'] + $ins['personas'],
                'sociedades' => $antes['sociedades'] + $ins['sociedades'],
                'polizas' => $antes['polizas'] + $ins['polizas'],
                'periodos' => $antes['periodos'] + $ins['periodos'],
                'pagos' => $antes['pagos'] + $ins['pagos'],
            ];
            $errores = [];
            foreach ($esperado as $k => $v) {
                if ($despues[$k] !== $v) $errores[] = "$k: se esperaban $v y hay {$despues[$k]}";
            }
            // Lo que ya existía no puede haber cambiado
            if ($despues['huella_pagos_previos'] !== $antes['huella_pagos_previos']) $errores[] = 'cambiaron los pagos de pólizas que ya existían';
            if ($despues['huella_estados_previos'] !== $antes['huella_estados_previos']) $errores[] = 'cambió el estado de pólizas que ya existían';
            if ($errores) throw new RuntimeException('La verificación falló, no se guardó nada: ' . implode('; ', $errores));

            $this->pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('importacion', ?, 'resumen', NULL, ?, 'importacion')")
                ->execute(['nomina_maestra_' . date('Ymd_His'), json_encode($ins + ['stats' => $plan['stats']], JSON_UNESCAPED_UNICODE)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['antes' => $antes, 'despues' => $despues, 'insertados' => $ins];
    }

    /** Cifras de control. `$hastaId` limita las huellas a las pólizas que ya existían antes de cargar. */
    private function cifras(?int $hastaId = null): array
    {
        $this->pdo->exec('SET SESSION group_concat_max_len = 1073741824');
        $q = fn(string $sql) => $this->pdo->query($sql)->fetchColumn();
        $max = $hastaId ?? (int) $q('SELECT COALESCE(MAX(id), 0) FROM polizas');
        return [
            'personas' => (int) $q('SELECT COUNT(*) FROM asegurados'),
            'sociedades' => (int) $q('SELECT COUNT(*) FROM sociedades'),
            'polizas' => (int) $q('SELECT COUNT(*) FROM polizas'),
            'periodos' => (int) $q('SELECT COUNT(*) FROM poliza_periodos'),
            'pagos' => (int) $q('SELECT COUNT(*) FROM pagos_mensuales'),
            'max_poliza_id' => $max,
            'huella_pagos_previos' => $q("SELECT MD5(GROUP_CONCAT(CONCAT(poliza_id, ':', periodo, ':', pagado, ':', COALESCE(monto, '')) ORDER BY poliza_id, periodo SEPARATOR '|')) FROM pagos_mensuales WHERE poliza_id <= $max"),
            'huella_estados_previos' => $q("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', estado, ':', estado_manual) ORDER BY id SEPARATOR '|')) FROM polizas WHERE id <= $max"),
        ];
    }

    /** Id de un valor de catálogo, creándolo si no existe. */
    private function idCatalogo(string $tabla, ?string $nombre): ?int
    {
        if ($nombre === null || $nombre === '') return null;
        $nombre = mb_substr($nombre, 0, 60);
        if (!isset($this->catalogos[$tabla])) {
            $this->catalogos[$tabla] = $this->pdo->query("SELECT nombre, id FROM $tabla")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        if (!isset($this->catalogos[$tabla][$nombre])) {
            $this->pdo->prepare("INSERT INTO $tabla (nombre) VALUES (?)")->execute([$nombre]);
            $this->catalogos[$tabla][$nombre] = (int) $this->pdo->lastInsertId();
        }
        return (int) $this->catalogos[$tabla][$nombre];
    }

    /** @return array<string,int> clave de sociedad del plan => id en la base */
    private function guardarSociedades(array $sociedades, array &$ins): array
    {
        $ids = [];
        $porRut = $this->pdo->query('SELECT rut, id FROM sociedades WHERE rut IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        $insert = $this->pdo->prepare('INSERT INTO sociedades (rut, dv, nombre, direccion, comuna, giro) VALUES (?,?,?,?,?,?)');
        foreach ($sociedades as $clave => $soc) {
            if (!empty($soc['rut']) && isset($porRut[$soc['rut']])) { $ids[$clave] = (int) $porRut[$soc['rut']]; continue; }
            $insert->execute([$soc['rut'], $soc['dv'], mb_substr($soc['nombre'], 0, 200), $this->recortar($soc['direccion'], 200),
                $this->recortar($soc['comuna'], 80), $this->recortar($soc['giro'], 150)]);
            $ids[$clave] = (int) $this->pdo->lastInsertId();
            $ins['sociedades']++;
        }
        return $ids;
    }

    private function guardarPersonas(array $personas, array $sociedadIds, array &$ins): void
    {
        $crear = $this->pdo->prepare(
            'INSERT INTO asegurados (rut, dv, dv_valido, nombre, fecha_nacimiento, especialidad, telefono, mail, direccion, comuna, ciudad,
                ejecutivo, sociedad_id, tipo_individuo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        // A las personas que ya existen solo se les completan los campos vacíos
        $completar = $this->pdo->prepare(
            "UPDATE asegurados SET
                telefono = IF(telefono IS NULL OR telefono = '', ?, telefono), mail = IF(mail IS NULL OR mail = '', ?, mail),
                direccion = IF(direccion IS NULL OR direccion = '', ?, direccion), comuna = IF(comuna IS NULL OR comuna = '', ?, comuna),
                ciudad = IF(ciudad IS NULL OR ciudad = '', ?, ciudad), especialidad = IF(especialidad IS NULL OR especialidad = '', ?, especialidad),
                ejecutivo = IF(ejecutivo IS NULL OR ejecutivo = '', ?, ejecutivo), fecha_nacimiento = IF(fecha_nacimiento IS NULL, ?, fecha_nacimiento),
                sociedad_id = IF(sociedad_id IS NULL, ?, sociedad_id), tipo_individuo_id = IF(tipo_individuo_id IS NULL, ?, tipo_individuo_id)
             WHERE rut = ?"
        );
        foreach ($personas as $rut => $p) {
            $sociedadId = isset($p['sociedad']) ? ($sociedadIds[$p['sociedad']] ?? null) : null;
            $tipoId = $this->idCatalogo('tipos_individuo', $p['otro_tipo'] ?? null);
            if ($p['accion'] === 'CREAR') {
                $crear->execute([$rut, $p['dv'], $p['dv_valido'], mb_substr($p['nombre'], 0, 150), $p['fecha_nacimiento'] ?? null,
                    $this->recortar($p['especialidad'] ?? null, 100), $this->recortar($p['telefono'] ?? null, 30), $this->recortar($p['mail'] ?? null, 150),
                    $this->recortar($p['direccion'] ?? null, 200), $this->recortar($p['comuna'] ?? null, 80), $this->recortar($p['ciudad'] ?? null, 80),
                    $this->recortar($p['ejecutivo'] ?? null, 60), $sociedadId, $tipoId]);
                $ins['personas']++;
            } else {
                $completar->execute([$this->recortar($p['telefono'] ?? null, 30), $this->recortar($p['mail'] ?? null, 150),
                    $this->recortar($p['direccion'] ?? null, 200), $this->recortar($p['comuna'] ?? null, 80), $this->recortar($p['ciudad'] ?? null, 80),
                    $this->recortar($p['especialidad'] ?? null, 100), $this->recortar($p['ejecutivo'] ?? null, 60), $p['fecha_nacimiento'] ?? null,
                    $sociedadId, $tipoId, $rut]);
                if ($completar->rowCount() > 0) $ins['personas_completadas']++;
            }
        }
    }

    private function guardarPolizas(array $polizas, array &$ins): void
    {
        $crear = $this->pdo->prepare(
            'INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                rut_pagador_original, fecha_alta, fecha_renuncia, fecha_envio_certificado, fecha_envio_poliza, numero_certificado, observaciones,
                valor_lega, valor_poliza, deducible_uf, estado, va_a_corte) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        // Una póliza que ya existe solo recibe los datos que le faltan; su estado y sus pagos no se tocan
        $completar = $this->pdo->prepare(
            "UPDATE polizas SET
                tipo_contrato = IF(tipo_contrato IS NULL, ?, tipo_contrato), compania = IF(compania IS NULL, ?, compania),
                corredor = IF(corredor IS NULL, ?, corredor), cobertura_id = IF(cobertura_id IS NULL, ?, cobertura_id),
                medio_pago_id = IF(medio_pago_id IS NULL, ?, medio_pago_id), fecha_alta = IF(fecha_alta IS NULL, ?, fecha_alta),
                fecha_renuncia = IF(fecha_renuncia IS NULL, ?, fecha_renuncia), fecha_envio_certificado = IF(fecha_envio_certificado IS NULL, ?, fecha_envio_certificado),
                fecha_envio_poliza = IF(fecha_envio_poliza IS NULL, ?, fecha_envio_poliza), numero_certificado = IF(numero_certificado IS NULL, ?, numero_certificado),
                observaciones = IF(observaciones IS NULL, ?, observaciones), valor_lega = IF(valor_lega IS NULL, ?, valor_lega),
                valor_poliza = IF(valor_poliza IS NULL, ?, valor_poliza), deducible_uf = IF(deducible_uf IS NULL, ?, deducible_uf)
             WHERE id = ?"
        );
        $pagosDe = $this->pdo->prepare("SELECT DATE_FORMAT(periodo, '%Y-%m-01') FROM pagos_mensuales WHERE poliza_id = ?");

        $periodos = [];
        $pagos = [];
        foreach ($polizas as $z) {
            $productoId = $this->idCatalogo('productos', $z['producto']);
            $coberturaId = $this->idCatalogo('coberturas', $z['cobertura'] ?? null);
            $medioId = $this->idCatalogo('medios_pago', $z['medio_pago'] ?? null);
            $comunes = [$z['tipo_contrato'] ?? null, $z['compania'] ?? null, $z['corredor'] ?? null];

            if ($z['accion'] === 'CREAR') {
                [$rutPagador, $dvPagador] = $this->parsearRut($z['pagador_original'] ?? null);
                $crear->execute([$z['rut'], $z['numero'], ...$comunes, $productoId, $coberturaId, $medioId, $rutPagador, $dvPagador,
                    $this->recortar($z['pagador_original'] ?? null, 30), $z['fecha_alta'] ?? null, $z['fecha_renuncia'] ?? null,
                    $z['fecha_envio_certificado'] ?? null, $z['fecha_envio_poliza'] ?? null, $this->recortar($z['numero_certificado'] ?? null, 30),
                    $z['observaciones'] ?? null, $z['valor_lega'] ?? null, $z['valor_poliza'] ?? null, $z['deducible_uf'] ?? null,
                    $z['estado'] === 'VAN A CORTE' ? 'VIGENTE' : $z['estado'], $z['estado'] === 'VAN A CORTE' ? 1 : 0]); // "va a corte" es un aviso, no un estado
                $polizaId = (int) $this->pdo->lastInsertId();
                $ins['polizas']++;
                $existentes = [];
            } else {
                $polizaId = $z['poliza_id'];
                $completar->execute([...$comunes, $coberturaId, $medioId, $z['fecha_alta'] ?? null, $z['fecha_renuncia'] ?? null,
                    $z['fecha_envio_certificado'] ?? null, $z['fecha_envio_poliza'] ?? null, $this->recortar($z['numero_certificado'] ?? null, 30),
                    $z['observaciones'] ?? null, $z['valor_lega'] ?? null, $z['valor_poliza'] ?? null, $z['deducible_uf'] ?? null, $polizaId]);
                if ($completar->rowCount() > 0) $ins['polizas_completadas']++;
                $pagosDe->execute([$polizaId]);
                $existentes = array_flip($pagosDe->fetchAll(PDO::FETCH_COLUMN));
            }

            foreach ($z['periodos'] as $desde => $per) {
                $periodos[] = [$polizaId, $per['numero'], $desde, $per['hasta'], $per['origen']];
                if (count($periodos) >= 500) { $ins['periodos'] += $this->insertarLote('poliza_periodos', ['poliza_id', 'numero', 'vigencia_desde', 'vigencia_hasta', 'origen'], $periodos, true); $periodos = []; }
            }
            foreach ($z['pagos'] as $periodo => $c) {
                if (isset($existentes[$periodo])) continue; // el mes ya está en la base: no se toca
                $texto = $c['valor_original'] === null ? null : mb_substr($c['valor_original'], 0, 255);
                $pagos[] = [$polizaId, $periodo, $c['pagado'], $c['monto'], 'archivo', $texto, $texto];
                if (count($pagos) >= 500) { $ins['pagos'] += $this->insertarLote('pagos_mensuales', ['poliza_id', 'periodo', 'pagado', 'monto', 'origen', 'valor_original', 'nota'], $pagos); $pagos = []; }
            }
        }
        $ins['periodos'] += $this->insertarLote('poliza_periodos', ['poliza_id', 'numero', 'vigencia_desde', 'vigencia_hasta', 'origen'], $periodos, true);
        $ins['pagos'] += $this->insertarLote('pagos_mensuales', ['poliza_id', 'periodo', 'pagado', 'monto', 'origen', 'valor_original', 'nota'], $pagos);
    }

    /** Inserta varias filas en una sola sentencia; devuelve cuántas se insertaron (IGNORE descarta repetidas). */
    private function insertarLote(string $tabla, array $columnas, array $filas, bool $ignorar = false): int
    {
        if (!$filas) return 0;
        $sql = 'INSERT ' . ($ignorar ? 'IGNORE ' : '') . "INTO $tabla (" . implode(', ', $columnas) . ') VALUES '
            . implode(',', array_fill(0, count($filas), '(' . implode(',', array_fill(0, count($columnas), '?')) . ')'));
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge(...$filas));
        return $st->rowCount();
    }

    /** Recorta un texto al largo de su columna (null si está vacío). */
    private function recortar(?string $t, int $max): ?string
    {
        return ($t === null || $t === '') ? null : mb_substr($t, 0, $max);
    }

    // =====================================================================================
    //  Limpieza de valores
    // =====================================================================================

    /** Recorta y colapsa espacios; null si queda vacío. */
    private function texto(mixed $v): ?string
    {
        if ($v === null || is_array($v)) return null;
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return ($t === '' || $t === '#VALUE!') ? null : $t;
    }

    /** Nombre de la persona; "APELLIDOS, NOMBRES" (formato de algunas hojas) pasa a "NOMBRES APELLIDOS", como en la base. */
    private function nombrePersona(mixed $v, array &$s): ?string
    {
        $t = $this->texto($v);
        // Algunos nombres traen el RUT pegado entre paréntesis: "ANA TOLEDO(15.698.159-1)"
        if ($t !== null) $t = $this->texto(preg_replace('/\(\s*[\d.]+\s*-\s*[\dkK]\s*\)/', ' ', $t));
        if ($t !== null && substr_count($t, ',') === 1) {
            [$apellidos, $nombres] = array_map('trim', explode(',', $t));
            if ($apellidos !== '' && $nombres !== '') { $s['nombres_reordenados']++; return mb_strtoupper("$nombres $apellidos"); }
        }
        return $t;
    }

    private function mail(mixed $v): ?string
    {
        $t = $this->texto($v);
        return ($t !== null && str_contains($t, '@')) ? mb_strtolower($t) : null;
    }

    /** "Sin Telefono" y similares no son teléfonos. */
    private function telefono(mixed $v): ?string
    {
        $t = $this->texto($v);
        return ($t !== null && preg_match('/\d{6,}/', $t)) ? $t : null;
    }

    /** Número serial de Excel → 'AAAA-MM-DD' (null si no es una fecha razonable). */
    private function fecha(mixed $v): ?string
    {
        if (!is_numeric($v) || $v < 20000 || $v > 60000) return null;
        return Date::excelToDateTimeObject($v)->format('Y-m-d');
    }

    /** Número de póliza como texto ("26158"). Acepta 26158.0; "S/N", "ASESORIA" o vacío = sin número (null). */
    private function numero(mixed $v): ?string
    {
        if ($v === null || $v === '' || is_array($v)) return null;
        if (is_numeric($v)) return $v > 0 ? (string) (int) $v : null;
        $t = $this->texto($v);
        return ($t !== null && ctype_digit($t) && (int) $t > 0) ? $t : null;
    }

    /** Monto o valor numérico (cobertura en UF, valores, deducible); null si no es un número. */
    private function monto(mixed $v): ?float
    {
        return (is_numeric($v) && $v > 0) ? round((float) $v, 2) : null;
    }

    /** Cobertura: "3000", "7000"… o texto en mayúsculas ("SOLO LEGA", "SIN LEGA"). */
    private function cobertura(mixed $v): ?string
    {
        if (is_numeric($v)) return $v > 0 ? (string) (int) $v : null;
        $t = $this->texto($v);
        return $t === null ? null : mb_strtoupper($t);
    }

    /** Tipo de contrato en mayúsculas ("LEGA + SEGURO"); un número suelto no es un contrato. */
    private function contrato(mixed $v): ?string
    {
        $t = $this->texto($v);
        return ($t === null || is_numeric($t)) ? null : mb_strtoupper($t);
    }

    /** Compañía o corredor en mayúsculas; los números sueltos son errores de la hoja. */
    private function nombreCorto(mixed $v): ?string
    {
        $t = $this->texto($v);
        return ($t === null || is_numeric($t)) ? null : mb_strtoupper($t);
    }

    /** "Otro tipo" de la persona: INDIVIDUAL, DERMO, SOEMAF, CETEP… (se corrigen errores de tipeo). */
    private function otroTipo(mixed $v): ?string
    {
        $t = $this->texto($v);
        if ($t === null || is_numeric($t) || preg_match('/\d{6,}/', $t)) return null;
        $t = mb_strtoupper($t);
        return ['ASEORIA' => 'ASESORIA', 'SOEMAF' => 'DERMO', 'P/NATURAL' => null, 'PERSONA NATURAL' => null, 'SOCIEDAD' => null][$t] ?? $t;
    }

    /** VIGENTE / NO VIGENTE; "VA A CORTE" se mantiene aquí y quien guarda lo convierte en VIGENTE con el aviso va_a_corte. */
    private function estado(mixed $v): ?string
    {
        $t = $this->texto($v);
        if ($t === null) return null;
        $t = mb_strtoupper($t);
        return match (true) {
            $t === 'VIGENTE' => 'VIGENTE',
            $t === 'NO VIGENTE' => 'NO VIGENTE',
            str_contains($t, 'CORTE') => 'VAN A CORTE',
            default => null,
        };
    }

    /** Unifica el medio de pago; los valores que no son un medio ("NO VIGENTE", "NO LO ENCONTRE") se descartan. */
    private function medioPago(mixed $v): ?string
    {
        $t = $this->texto($v);
        if ($t === null) return null;
        $t = mb_strtoupper($t);
        return match (true) {
            str_starts_with($t, 'PAT') => 'PAT',
            str_starts_with($t, 'PAC') => 'PAC',
            $t === 'MP' || str_contains($t, 'MERCADO') => 'MERCADO PAGO',
            str_contains($t, 'TRANSFER') || str_contains($t, 'TRANSFIR') => 'TRANSFERENCIA',
            str_contains($t, 'NO VIGENTE') || str_contains($t, 'NO LO ENCONTRE') => null,
            default => $t,
        };
    }

    /**
     * Interpreta una celda de mes. Número > 0 = pagado (ese monto); 0 = impago; texto de pago
     * (PAGADO, TRANSFIRIO, WEBPAY…) = pagado; cualquier otro texto = impago con el texto como nota.
     * @return array{pagado:int, monto:?int, valor_original:?string}|null  null = sin dato
     */
    private function clasificarMes(mixed $v, array &$s): ?array
    {
        if ($v === null || $v === '' || is_array($v)) return null;
        if (is_numeric($v)) {
            return $v > 0 ? ['pagado' => 1, 'monto' => (int) round($v), 'valor_original' => null]
                          : ['pagado' => 0, 'monto' => null, 'valor_original' => null];
        }
        $orig = $this->texto($v);
        if ($orig === null) return null;
        $l = mb_strtolower(ltrim($orig, "´'` "));
        if ($l === 'tribunal') return null;
        foreach (self::PREFIJOS_PAGADO as $p) {
            if (str_starts_with($l, $p)) return ['pagado' => 1, 'monto' => null, 'valor_original' => $orig];
        }
        // "PAT" en un mes: esa cuota se pagó con PAT
        if ($l === 'pat' || str_starts_with($l, 'pat ')) {
            $s['celdas_pat_pagadas']++;
            return ['pagado' => 1, 'monto' => null, 'valor_original' => $orig];
        }
        return ['pagado' => 0, 'monto' => null, 'valor_original' => $orig];
    }

    /**
     * Interpreta un RUT en cualquier formato ("12.345.678-9", "12345678-9", 12345678 + DV aparte,
     * "76558416-7 /AV. ARGENTINA 540"). Devuelve [rut, dv, texto sobrante] o [null, null, null].
     */
    private function parsearRut(mixed $v, mixed $dvAparte = null): array
    {
        if (is_numeric($v) && $v > 100000) {
            $rut = (int) $v;
            $dv = $this->texto($dvAparte);
            return [$rut, strtoupper($dv ?? $this->calcularDv($rut)), null];
        }
        $t = $this->texto($v);
        if ($t === null) return [null, null, null];
        if (preg_match('/(\d{1,3}(?:\.?\d{3}){1,2})\s*-\s*([\dkK])/', $t, $m)) {
            $resto = trim(str_replace($m[0], '', $t), " /-");
            return [(int) str_replace('.', '', $m[1]), strtoupper($m[2]), $resto !== '' ? $resto : null];
        }
        return [null, null, null];
    }

    /** Minúsculas, sin tildes ni espacios sobrantes: para comparar nombres de comunas y sociedades. */
    private function normalizar(string $t): string
    {
        $t = mb_strtoupper(trim($t));
        return preg_replace('/\s+/', ' ', strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']));
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

    /** Contadores del informe, con todas las claves en cero. */
    private function estadisticasVacias(): array
    {
        return [
            'filas_por_fuente' => [], 'rut_por_fuente' => [], 'filas_sin_rut_valido' => [], 
            'personas_nuevas' => 0, 'personas_existentes' => 0, 'personas_existentes_con_cambios' => 0, 'personas_existentes_sin_cambios' => 0,
            'completa_campos' => [], 'dv_invalido' => 0, 'nombres_reordenados' => 0, 'estado_corregido_por_renuncia' => 0, 'ciudades_resueltas' => 0, 'ciudades_sin_resolver' => 0, 'comunas_sin_ciudad' => [], 'numeros_sin_producto_tipos' => [],
            'otro_tipo' => [], 'otro_tipo_conflictos' => [],
            'sociedades' => ['nuevas' => 0, 'ya_existen' => 0], 'sociedades_rut_ilegible' => [],
            'polizas' => ['CREAR' => [], 'ACTUALIZAR' => []], 'polizas_99999' => 0, 'numeros_sin_producto' => [], 'productos_inferidos' => [],
            'estados_nuevas' => [], 'polizas_con_lagunas' => 0, 'polizas_con_solapes' => 0,
            'periodos' => ['total' => 0, 'termino_calculado' => 0, 'termino_del_excel' => 0], 'periodos_recortados' => 0,
            'pagos' => ['nuevos' => 0, 'nuevos_pagados' => 0, 'nuevos_impagos' => 0, 'ya_en_base_iguales' => 0, 'en_conflicto_no_se_tocan' => 0],
            'celdas_pat_pagadas' => 0,
        ];
    }
}
