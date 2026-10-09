<?php

/**
 * Aplica las pólizas matrices: reasigna el producto de cada póliza según la especialidad de la
 * persona, renueva las vigentes al número actual y arma los períodos a partir de la matriz.
 *
 * Uso:
 *   php scripts/aplicar_matrices.php --informe    calcula todo y lo muestra, pero DESHACE los cambios
 *   php scripts/aplicar_matrices.php --aplicar    lo guarda (hacer antes un respaldo con mysqldump)
 *   DB_NAME=unificar_nominas_prueba php scripts/aplicar_matrices.php --aplicar   (probar en una copia)
 *
 * Reglas (acordadas con el usuario):
 *   - El producto que cubre a la persona depende de su especialidad:
 *       cirujano dentista de tipo DERMO / SOEMAF → SOEMAF;  dentista "puro" → Odontólogo;
 *       solo dermatología → Derma;  cualquier otra especialidad → Individual.
 *     Sin especialidad registrada no se decide: la póliza conserva su producto.
 *   - Cada número de póliza dura 18 meses y se renueva solo salvo renuncia o pago rechazado:
 *       póliza VIGENTE (o VAN A CORTE) → pasa al número actual de su producto y toma su vigencia;
 *       póliza NO VIGENTE → conserva su número solo si es el actual o el anterior; un número
 *       antiguo o desconocido se ignora (queda sin período).
 *   - No se tocan los pagos ni los estados; todo ocurre en una transacción y se verifica antes de confirmar.
 */

require_once __DIR__ . '/../config/database.php';

/** Pólizas matrices confirmadas por el usuario. Vencen: Odontólogo 01-07-2027; Individual, Derma y SOEMAF 01-08-2027. */
const MATRICES = [
    // [producto, número, desde, hasta, aproximada, actual]
    ['Odontólogo', '26157', '2026-01-01', '2027-07-01', 0, true],
    ['Odontólogo', '24818', '2024-07-01', '2026-01-01', 1, false], // el anterior termina donde empieza el siguiente
    ['Individual', '26158', '2026-02-01', '2027-08-01', 0, true],
    ['Individual', '24930', '2024-08-01', '2026-02-01', 1, false],
    ['Derma',      '26158', '2026-02-01', '2027-08-01', 0, true],  // Individual y Derma comparten el número
    ['Derma',      '24930', '2024-08-01', '2026-02-01', 1, false],
    ['SOEMAF',     '28581', '2026-02-01', '2027-08-01', 0, true],
    ['SOEMAF',     '24935', '2024-08-01', '2026-02-01', 1, false],
];

$modo = $argv[1] ?? '';
if (!in_array($modo, ['--informe', '--aplicar'], true)) {
    exit("Uso: php scripts/aplicar_matrices.php --informe|--aplicar\n");
}

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'poliza_periodos'")->fetchColumn()) exit("La base `$base` no tiene la migración 003.\n");
if ($pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn() && (int) $pdo->query('SELECT COUNT(*) FROM polizas_matrices')->fetchColumn() > 0) {
    exit("La base `$base` ya tiene las pólizas matrices cargadas.\n");
}

/** Especialidad (y "otro tipo") → producto que cubre a la persona; null si no se puede decidir. */
function productoSegunEspecialidad(?string $especialidad, ?string $otroTipo): ?string
{
    $e = mb_strtoupper(trim((string) $especialidad));
    $t = mb_strtoupper(trim((string) $otroTipo));
    $dentista = str_contains($e, 'DENTISTA') || str_contains($e, 'ODONT');
    if ($dentista && (str_contains($e, 'SOEMAF') || in_array($t, ['DERMO', 'SOEMAF'], true))) return 'SOEMAF';
    if ($t === 'SOEMAF') return 'SOEMAF';
    if ($dentista) return 'Odontólogo';
    if (str_contains($e, 'DERMAT')) return 'Derma';
    return $e !== '' ? 'Individual' : null;
}

/** Huellas de lo que NO debe cambiar. */
function huellas(PDO $pdo): array
{
    $pdo->exec('SET SESSION group_concat_max_len = 1073741824');
    return [
        'pagos' => $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT(poliza_id, ':', periodo, ':', pagado, ':', COALESCE(monto, '')) ORDER BY poliza_id, periodo SEPARATOR '|')) FROM pagos_mensuales")->fetchColumn(),
        'estados' => $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', rut, ':', estado, ':', estado_manual) ORDER BY id SEPARATOR '|')) FROM polizas")->fetchColumn(),
        'personas' => $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn() . '/' . $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn() . '/' . $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
    ];
}
function n(int $v): string { return number_format($v, 0, ',', '.'); }

// ---- 1. La tabla (DDL: confirma por sí sola, por eso va antes de la transacción) ----
$creada = false;
if ($modo === '--aplicar' && !$pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn()) {
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/006_polizas_matrices.sql'));
    $creada = true;
}

$antes = huellas($pdo);
$productoId = $pdo->query('SELECT nombre, id FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['Odontólogo', 'Individual', 'Derma', 'SOEMAF'] as $p) {
    if (!isset($productoId[$p])) exit("Falta el producto \"$p\" en la base.\n");
}
// Matrices por producto: número → [desde, hasta]; y la actual de cada producto
$matriz = []; $actual = [];
foreach (MATRICES as [$prod, $num, $desde, $hasta, $aprox, $esActual]) {
    $matriz[$prod][$num] = ['desde' => $desde, 'hasta' => $hasta, 'aprox' => $aprox];
    if ($esActual) $actual[$prod] = $num;
}

$pdo->beginTransaction();
try {
    // ---- 2. Matrices ----
    if ($modo === '--aplicar') {
        $ins = $pdo->prepare('INSERT INTO polizas_matrices (producto_id, numero, vigencia_desde, vigencia_hasta, aproximada) VALUES (?,?,?,?,?)');
        foreach (MATRICES as [$prod, $num, $desde, $hasta, $aprox]) $ins->execute([$productoId[$prod], $num, $desde, $hasta, $aprox]);
    }

    // ---- 3. Personas y pólizas ----
    $personas = $pdo->query('SELECT a.rut, a.especialidad, ti.nombre AS otro_tipo FROM asegurados a LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $polizas = [];
    foreach ($pdo->query('SELECT z.id, z.rut, z.numero, z.estado, pr.nombre AS producto FROM polizas z LEFT JOIN productos pr ON pr.id = z.producto_id ORDER BY z.id') as $z) {
        $polizas[(int) $z['id']] = $z;
    }
    $porRut = [];
    foreach ($polizas as $id => $z) $porRut[(int) $z['rut']][] = $id;

    $stats = ['reasignadas' => [], 'reasignadas_por_estado' => [], 'omitidas_varias_polizas' => 0, 'sin_decidir' => 0,
        'renovadas' => ['a número anterior' => 0, 'de número antiguo o desconocido' => 0, 'sin número (99999)' => 0],
        'periodos' => [], 'sin_periodo_antiguas' => 0, 'sin_producto_sin_periodo' => 0];
    $log = []; // historial: [registro, campo, anterior, nuevo]

    // ---- 3a. Reasignar el producto según la especialidad ----
    $cambiarProducto = $pdo->prepare('UPDATE polizas SET producto_id = ?, updated_at = updated_at WHERE id = ?');
    foreach ($porRut as $rut => $ids) {
        $deriv = productoSegunEspecialidad($personas[$rut]['especialidad'] ?? null, $personas[$rut]['otro_tipo'] ?? null);
        if ($deriv === null) { $stats['sin_decidir']++; continue; }
        foreach ($ids as $id) if ($polizas[$id]['producto'] === $deriv) continue 2;      // ya tiene la póliza que le corresponde
        if (count($ids) > 1) { $stats['omitidas_varias_polizas']++; continue; }          // varias pólizas y ninguna es la correcta: se revisa a mano
        $id = $ids[0];
        $de = $polizas[$id]['producto'] ?? '(sin producto)';
        $cambiarProducto->execute([$productoId[$deriv], $id]);
        $polizas[$id]['producto'] = $deriv;
        $stats['reasignadas']["$de → $deriv"] = ($stats['reasignadas']["$de → $deriv"] ?? 0) + 1;
        $stats['reasignadas_por_estado'][$polizas[$id]['estado']] = ($stats['reasignadas_por_estado'][$polizas[$id]['estado']] ?? 0) + 1;
        $log[] = [(string) $id, 'producto', $de, $deriv];
    }

    // ---- 3b. Renovación y períodos a partir de la matriz ----
    $pdo->exec('DELETE FROM poliza_periodos'); // los períodos del Excel eran inconsistentes: se reemplazan
    $cambiarNumero = $pdo->prepare('UPDATE polizas SET numero = ?, updated_at = updated_at WHERE id = ?');
    $periodos = [];
    foreach ($polizas as $id => $z) {
        $prod = $z['producto'];
        if ($prod === null || !isset($matriz[$prod])) { $stats['sin_producto_sin_periodo']++; continue; }
        $numero = $z['numero'];
        $vigente = in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true);
        $esActual = $numero === $actual[$prod];
        $conocido = isset($matriz[$prod][$numero]);

        if ($vigente && !$esActual) { // renovación automática al número actual
            $motivo = $numero === '99999' ? 'sin número (99999)' : ($conocido ? 'a número anterior' : 'de número antiguo o desconocido');
            $stats['renovadas'][$motivo]++;
            $log[] = [(string) $id, 'numero', $numero, $actual[$prod]];
            $cambiarNumero->execute([$actual[$prod], $id]);
            $numero = $actual[$prod];
            $conocido = true;
        }
        if (!$conocido) { $stats['sin_periodo_antiguas']++; continue; } // póliza antigua NO vigente: se ignora
        $m = $matriz[$prod][$numero];
        $periodos[] = [$id, $numero, $m['desde'], $m['hasta'], 'automatica'];
        $stats['periodos']["$prod $numero"] = ($stats['periodos']["$prod $numero"] ?? 0) + 1;
    }
    foreach (array_chunk($periodos, 500) as $lote) {
        $pdo->prepare('INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES '
            . implode(',', array_fill(0, count($lote), '(?,?,?,?,?)')))->execute(array_merge(...$lote));
    }
    foreach (array_chunk($log, 500) as $lote) {
        $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES "
            . implode(',', array_fill(0, count($lote), "('polizas',?,?,?,?,'reglas_matriz')")))->execute(array_merge(...$lote));
    }

    // ---- 4. Verificación: no cambian ni los pagos, ni los estados, ni la cantidad de personas, pólizas o pagos ----
    $despues = huellas($pdo);
    $errores = [];
    foreach ($antes as $k => $v) if ($despues[$k] !== $v) $errores[] = "cambió: $k";
    $nPeriodos = (int) $pdo->query('SELECT COUNT(*) FROM poliza_periodos')->fetchColumn();
    if ($nPeriodos !== count($periodos)) $errores[] = "períodos esperados " . count($periodos) . ", hay $nPeriodos";
    if ((int) $pdo->query('SELECT COUNT(*) FROM (SELECT poliza_id FROM poliza_periodos GROUP BY poliza_id HAVING COUNT(*) > 1) x')->fetchColumn() > 0) $errores[] = 'hay pólizas con más de un período';
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));

    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

// ---- Informe ----
echo $modo === '--aplicar' ? "=== APLICADO en la base `$base` ===\n" : "=== INFORME en la base `$base` (se deshizo todo: no se guardó nada) ===\n";
echo 'Pólizas evaluadas: ' . n(count($polizas)) . ' | pólizas matrices: ' . count(MATRICES) . ($creada ? ' (tabla creada)' : '') . "\n\n";
echo "REASIGNACIÓN DE PRODUCTO (según especialidad)\n";
arsort($stats['reasignadas']);
foreach ($stats['reasignadas'] as $k => $v) echo '  ' . str_pad($k, 36) . n($v) . "\n";
echo '  Total reasignadas: ' . n(array_sum($stats['reasignadas'])) . '  (por estado: ' . json_encode($stats['reasignadas_por_estado'], JSON_UNESCAPED_UNICODE) . ")\n";
echo '  Personas sin especialidad (conservan su producto): ' . n($stats['sin_decidir']) . ' | con varias pólizas y ninguna correcta (revisar a mano): ' . n($stats['omitidas_varias_polizas']) . "\n\n";
echo "RENOVACIÓN AUTOMÁTICA (pólizas vigentes que pasan al número actual)\n";
foreach ($stats['renovadas'] as $k => $v) echo '  ' . str_pad($k, 36) . n($v) . "\n";
echo "\nPERÍODOS CREADOS (vigencia de la matriz): " . n(count($periodos)) . "\n";
arsort($stats['periodos']);
foreach ($stats['periodos'] as $k => $v) echo '  ' . str_pad($k, 36) . n($v) . "\n";
echo '  Pólizas antiguas NO vigentes sin período (se ignoran): ' . n($stats['sin_periodo_antiguas']) . ' | pólizas sin producto (sin período): ' . n($stats['sin_producto_sin_periodo']) . "\n\n";
echo "Verificado: los pagos, los estados y las cantidades no cambiaron.\n";
