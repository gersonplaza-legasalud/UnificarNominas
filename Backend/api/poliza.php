<?php

/**
 * POST api/poliza.php   (cuerpo JSON)
 *
 * Crea o edita UNA póliza de una persona; lo usa el diálogo "Editar póliza / Agregar póliza" del front.
 *   - Con "id"  → edita esa póliza.
 *   - Con "rut" → crea una póliza nueva para esa persona.
 *
 *   {
 *     "id": 918,                      (o "rut": 13161636 para crear)
 *     "producto": "SOEMAF" | null,    producto de la póliza (null = sin producto asignado)
 *     "numero": "28581",              solo dígitos; vacío = 99999 (póliza aún no registrada)
 *     "estado": "VIGENTE" | "NO VIGENTE",
 *     "va_a_corte": true | false,     aviso de riesgo de corte (solo tiene sentido en una póliza VIGENTE)
 *     "tipo_contrato", "compania", "corredor", "cobertura", "medio_pago": texto o null,
 *     "fecha_alta", "fecha_renuncia": "AAAA-MM-DD" o null,
 *     "cobertura_desde": "AAAA-MM-DD" o null,   inicio de la cobertura (dura 12 meses y se renueva sola cada año)
 *     "valor_lega", "valor_poliza", "deducible_uf": número o null,   (si la persona tiene siniestro y no se indica deducible, lleva el de la persona)
 *     "numero_certificado", "observaciones": texto o null,
 *     "pagador": "12.345.678-9" o una nota (texto) o null,
 *     "vigencia_desde", "vigencia_hasta": "AAAA-MM-DD" (ambas o ninguna)
 *   }
 *
 * Reglas:
 *   - Una persona puede tener varias pólizas del mismo producto (una por período, con distinto número);
 *     lo que no puede haber es dos con el mismo producto Y el mismo número (409).
 *   - La vigencia se guarda como el período de la póliza (uno solo); si se dejan vacías las fechas
 *     se quita el período. Si coinciden con las de la póliza matriz queda como 'automatica', si no 'manual'.
 *   - Cada campo que cambia se registra en `historial_cambios` (valor anterior y nuevo).
 *   - Cambiar el plan (cobertura) o el inicio de la cobertura de una póliza se propaga a las pólizas VIGENTES posteriores del
 *     mismo producto de la persona: su inicio de cobertura pasa a la ventana de 12 meses vigente hoy contada desde la nueva
 *     fecha (p. ej. inicio 01-04-2025 → la póliza actual queda con 01-04-2026 a 01-04-2027) y, si tenían el plan anterior,
 *     pasan al nuevo.
 *   - Los pagos de la póliza nunca se tocan.
 *
 * Responde: { success, message, poliza_id, cambios }
 */

require_once __DIR__ . '/_comun.php';

exigirMetodo('POST');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    responder(400, ['success' => false, 'message' => 'Se esperaba un JSON']);
}

function error400(string $mensaje): never
{
    responder(400, ['success' => false, 'message' => $mensaje]);
}

/** Texto recortado; null si viene vacío. Rechaza lo que supere el largo de su columna. */
function textoCampo(array $in, string $clave, int $max, string $etiqueta, bool $mayusculas = false): ?string
{
    $v = $in[$clave] ?? null;
    if ($v === null) return null;
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    if ($v === '') return null;
    if (mb_strlen($v) > $max) error400("$etiqueta admite hasta $max caracteres");
    return $mayusculas ? mb_strtoupper($v) : $v;
}

function fechaCampo(array $in, string $clave, string $etiqueta): ?string
{
    $v = $in[$clave] ?? null;
    if ($v === null || $v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', (string) $v);
    if (!$d || $d->format('Y-m-d') !== $v || $d->format('Y') < 1990 || $d->format('Y') > 2100) error400("$etiqueta no es una fecha válida");
    return $v;
}

function decimalCampo(array $in, string $clave, string $etiqueta, float $max): ?float
{
    $v = $in[$clave] ?? null;
    if ($v === null || $v === '') return null;
    if (!is_numeric($v) || $v < 0 || $v > $max) error400("$etiqueta debe ser un número entre 0 y $max");
    return round((float) $v, 2);
}

// ---- Validación de la entrada (nada de lo que llega del navegador se asume correcto) ----
$id = isset($in['id']) ? filter_var($in['id'], FILTER_VALIDATE_INT) : null;
$rut = isset($in['rut']) ? filter_var($in['rut'], FILTER_VALIDATE_INT) : null;
if (isset($in['id']) && (!$id || $id <= 0)) error400('Póliza inválida');
if (!$id && (!$rut || $rut <= 0)) error400('Indica la póliza a editar o el RUT de la persona');

$numero = textoCampo($in, 'numero', 30, 'El número de póliza') ?? '99999';
if (!ctype_digit($numero)) error400('El número de póliza debe tener solo dígitos');

$estado = (string) ($in['estado'] ?? '');
if (!in_array($estado, ['VIGENTE', 'NO VIGENTE'], true)) error400('Elige el estado de la póliza');
// "Va a corte" es un aviso, no un estado: una póliza NO VIGENTE ya no puede ir a corte
$vaACorte = $estado === 'VIGENTE' && !empty($in['va_a_corte']) ? 1 : 0;

$desde = fechaCampo($in, 'vigencia_desde', 'El inicio de la vigencia');
$hasta = fechaCampo($in, 'vigencia_hasta', 'El término de la vigencia');
if (($desde === null) !== ($hasta === null)) error400('La vigencia necesita las dos fechas (o ninguna)');
if ($desde !== null && $hasta <= $desde) error400('El término de la vigencia debe ser posterior al inicio');

// Pagador: un RUT ("12.345.678-9") o una nota. Si es un RUT se guarda también separado.
$pagador = textoCampo($in, 'pagador', 30, 'El pagador');
$rutPagador = $dvPagador = null;
if ($pagador !== null && preg_match('/^(\d{1,3}(?:\.?\d{3}){1,2})\s*-\s*([\dkK])$/', $pagador, $m)) {
    $rutPagador = (int) str_replace('.', '', $m[1]);
    $dvPagador = strtoupper($m[2]);
}

$campos = [
    'numero' => $numero,
    'estado' => $estado,
    'va_a_corte' => $vaACorte,
    'tipo_contrato' => textoCampo($in, 'tipo_contrato', 40, 'El tipo de contrato', true),
    'compania' => textoCampo($in, 'compania', 40, 'La compañía', true),
    'corredor' => textoCampo($in, 'corredor', 60, 'El corredor', true),
    'fecha_alta' => fechaCampo($in, 'fecha_alta', 'La fecha de alta'),
    'fecha_renuncia' => fechaCampo($in, 'fecha_renuncia', 'La fecha de renuncia'),
    'cobertura_desde' => fechaCampo($in, 'cobertura_desde', 'El inicio de la cobertura'),
    'valor_lega' => decimalCampo($in, 'valor_lega', 'El valor Lega', 9999999999),
    'valor_poliza' => decimalCampo($in, 'valor_poliza', 'El valor de la póliza', 9999999999),
    'deducible_uf' => decimalCampo($in, 'deducible_uf', 'El deducible', 9999),
    'numero_certificado' => textoCampo($in, 'numero_certificado', 30, 'El N° de certificado'),
    'observaciones' => textoCampo($in, 'observaciones', 2000, 'Las observaciones'),
    'rut_pagador' => $rutPagador,
    'dv_pagador' => $dvPagador,
    'rut_pagador_original' => $pagador,
];
$productoNombre = textoCampo($in, 'producto', 60, 'El producto');
$coberturaNombre = textoCampo($in, 'cobertura', 40, 'La cobertura', true);
$medioNombre = textoCampo($in, 'medio_pago', 40, 'El medio de pago', true);

conManejoDeErrores(function () use ($id, $rut, $campos, $productoNombre, $coberturaNombre, $medioNombre, $desde, $hasta) {
    $pdo = conectarBD();
    $pdo->beginTransaction();

    /** Id de un valor de catálogo (lo crea si no existe); null si no se indicó. */
    $catalogo = function (string $tabla, ?string $nombre) use ($pdo): ?int {
        if ($nombre === null) return null;
        $s = $pdo->prepare("SELECT id FROM $tabla WHERE nombre = ?");
        $s->execute([$nombre]);
        $idCat = $s->fetchColumn();
        if ($idCat === false) {
            $pdo->prepare("INSERT INTO $tabla (nombre) VALUES (?)")->execute([$nombre]);
            $idCat = $pdo->lastInsertId();
        }
        return (int) $idCat;
    };

    // El producto debe existir (no se crean productos desde el formulario)
    $productoId = null;
    if ($productoNombre !== null) {
        $s = $pdo->prepare('SELECT id FROM productos WHERE nombre = ?');
        $s->execute([$productoNombre]);
        $productoId = $s->fetchColumn();
        if ($productoId === false) {
            $pdo->rollBack();
            responder(400, ['success' => false, 'message' => "El producto \"$productoNombre\" no existe"]);
        }
        $productoId = (int) $productoId;
    }

    $nuevos = $campos + [
        'producto_id' => $productoId,
        'cobertura_id' => $catalogo('coberturas', $coberturaNombre),
        'medio_pago_id' => $catalogo('medios_pago', $medioNombre),
    ];

    // ---- Persona y póliza ----
    if ($id) {
        $s = $pdo->prepare('SELECT * FROM polizas WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        $actual = $s->fetch();
        if (!$actual) {
            $pdo->rollBack();
            responder(404, ['success' => false, 'message' => 'No existe esa póliza']);
        }
        $rutPersona = (int) $actual['rut'];
    } else {
        $s = $pdo->prepare('SELECT 1 FROM asegurados WHERE rut = ?');
        $s->execute([$rut]);
        if (!$s->fetchColumn()) {
            $pdo->rollBack();
            responder(404, ['success' => false, 'message' => 'No existe un asegurado con ese RUT']);
        }
        $rutPersona = $rut;
        $actual = null;
    }

    // Persona con siniestro: la póliza no puede quedar sin deducible; si no se indica, lleva el de la persona
    $s = $pdo->prepare('SELECT con_siniestro, deducible_uf FROM asegurados WHERE rut = ?');
    $s->execute([$rutPersona]);
    $persona = $s->fetch();
    if ($persona && (int) $persona['con_siniestro'] && (($nuevos['deducible_uf'] ?? null) === null || (float) $nuevos['deducible_uf'] <= 0)) {
        $nuevos['deducible_uf'] = (float) ($persona['deducible_uf'] ?? 16);
    }

    // No puede haber dos pólizas con el mismo producto y el mismo número (sí varias del producto, una por período)
    if ($productoId !== null) {
        $s = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ? AND id <> ?');
        $s->execute([$rutPersona, $productoId, $campos['numero'], $id ?? 0]);
        if ($s->fetchColumn()) {
            $pdo->rollBack();
            responder(409, ['success' => false, 'message' => "La persona ya tiene una póliza de $productoNombre con el número {$campos['numero']}. Edita esa en lugar de crear otra."]);
        }
    }

    // En el historial se anotan nombres ("SOEMAF", "PAT"), no ids de catálogo
    $nombreDe = function (string $campo, mixed $valor) use ($pdo): ?string {
        $tabla = ['producto_id' => 'productos', 'cobertura_id' => 'coberturas', 'medio_pago_id' => 'medios_pago'][$campo] ?? null;
        if ($tabla === null || $valor === null) return $valor === null ? null : (string) $valor;
        $s = $pdo->prepare("SELECT nombre FROM $tabla WHERE id = ?");
        $s->execute([$valor]);
        return $s->fetchColumn() ?: (string) $valor;
    };
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, ?, ?, ?, 'front')");
    $cambios = 0;

    if ($actual === null) {
        $columnas = array_merge(['rut'], array_keys($nuevos));
        $pdo->prepare('INSERT INTO polizas (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
            ->execute(array_merge([$rutPersona], array_values($nuevos)));
        $id = (int) $pdo->lastInsertId();
        $log->execute([(string) $id, 'creada', null, json_encode(['producto' => $productoNombre] + $campos, JSON_UNESCAPED_UNICODE)]);
        $cambios = 1;
    } else {
        // Se compara campo a campo; solo se guarda y se registra lo que de verdad cambió
        $sets = [];
        $valores = [];
        $cambioInicioCobertura = $cambioPlan = false;
        foreach ($nuevos as $campo => $valor) {
            $antes = $actual[$campo];
            if (is_numeric($antes) && is_numeric($valor) && !is_string($valor)) $igual = (float) $antes === (float) $valor;
            else $igual = ($antes === null ? null : (string) $antes) === ($valor === null ? null : (string) $valor);
            if ($igual) continue;
            $sets[] = "$campo = ?";
            $valores[] = $valor;
            $log->execute([(string) $id, str_replace('_id', '', $campo), $nombreDe($campo, $antes), $nombreDe($campo, $valor)]);
            $cambios++;
            if ($campo === 'cobertura_desde') $cambioInicioCobertura = true;
            if ($campo === 'cobertura_id') $cambioPlan = true;
        }
        if ($sets) {
            $valores[] = $id;
            $pdo->prepare('UPDATE polizas SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
        }
    }

    // ---- Período (vigencia): la póliza tiene uno solo ----
    $s = $pdo->prepare('SELECT numero, vigencia_desde, vigencia_hasta FROM poliza_periodos WHERE poliza_id = ? ORDER BY vigencia_desde DESC LIMIT 1');
    $s->execute([$id]);
    $periodo = $s->fetch();
    $quedaIgual = $periodo
        ? ($desde === $periodo['vigencia_desde'] && $hasta === $periodo['vigencia_hasta'] && $campos['numero'] === (string) $periodo['numero'])
        : ($desde === null);
    if (!$quedaIgual) {
        $pdo->prepare('DELETE FROM poliza_periodos WHERE poliza_id = ?')->execute([$id]);
        if ($desde !== null) {
            // ¿Coincide con la póliza matriz de ese producto y número? Entonces es la renovación normal
            $esMatriz = false;
            if ($productoId !== null) {
                $s = $pdo->prepare('SELECT 1 FROM polizas_matrices WHERE producto_id = ? AND numero = ? AND vigencia_desde = ? AND vigencia_hasta = ?');
                $s->execute([$productoId, $campos['numero'], $desde, $hasta]);
                $esMatriz = (bool) $s->fetchColumn();
            }
            $pdo->prepare('INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,?)')
                ->execute([$id, $campos['numero'], $desde, $hasta, $esMatriz ? 'automatica' : 'manual']);
        }
        $log->execute([(string) $id, 'vigencia',
            $periodo ? "{$periodo['vigencia_desde']} a {$periodo['vigencia_hasta']}" : null,
            $desde !== null ? "$desde a $hasta" : null]);
        $cambios++;
    }

    // ---- Cambio de plan o de inicio de cobertura: se refleja en las pólizas vigentes posteriores del mismo producto ----
    $propagadas = 0;
    if ($actual !== null && $productoId !== null && ($cambioInicioCobertura || $cambioPlan)) {
        $s = $pdo->prepare('SELECT MAX(vigencia_desde) FROM poliza_periodos WHERE poliza_id = ?');
        $s->execute([$id]);
        $inicioPoliza = $s->fetchColumn();
        if ($inicioPoliza) {
            $s = $pdo->prepare("SELECT z.id, z.cobertura_desde, z.cobertura_id FROM polizas z JOIN poliza_periodos pp ON pp.poliza_id = z.id
                                WHERE z.rut = ? AND z.producto_id = ? AND z.id <> ? AND z.estado = 'VIGENTE' GROUP BY z.id HAVING MAX(pp.vigencia_desde) > ?");
            $s->execute([$rutPersona, $productoId, $id, $inicioPoliza]);
            $siguientes = $s->fetchAll();
            foreach ($siguientes as $y) {
                $sets = [];
                $valores = [];
                if ($cambioInicioCobertura && $nuevos['cobertura_desde'] !== null) {
                    // ventana de 12 meses vigente hoy, contada desde la nueva fecha (misma cuenta que coberturaActual)
                    $inicioVigente = coberturaActual($nuevos['cobertura_desde'], 'VIGENTE')['desde'];
                    if ($y['cobertura_desde'] !== $inicioVigente) {
                        $sets[] = 'cobertura_desde = ?';
                        $valores[] = $inicioVigente;
                        $log->execute([(string) $y['id'], 'cobertura_desde', $y['cobertura_desde'], $inicioVigente]);
                    }
                }
                if ($cambioPlan && (int) $y['cobertura_id'] === (int) $actual['cobertura_id'] && $nuevos['cobertura_id'] !== null) {
                    $sets[] = 'cobertura_id = ?';
                    $valores[] = $nuevos['cobertura_id'];
                    $log->execute([(string) $y['id'], 'cobertura', $nombreDe('cobertura_id', $actual['cobertura_id']), $nombreDe('cobertura_id', $nuevos['cobertura_id'])]);
                }
                if ($sets) {
                    $valores[] = $y['id'];
                    $pdo->prepare('UPDATE polizas SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($valores);
                    $propagadas++;
                }
            }
        }
    }

    $pdo->commit();
    responder(200, [
        'success' => true,
        'message' => $cambios ? ($actual === null ? 'Póliza creada' : 'Póliza actualizada') . ($propagadas ? '; se actualizó también el inicio de cobertura/plan de la póliza vigente' : '') : 'Sin cambios',
        'poliza_id' => $id,
        'cambios' => $cambios,
        'polizas_actualizadas' => $propagadas,
    ]);
});
