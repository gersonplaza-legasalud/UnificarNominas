<?php

/**
 * Separa a las personas en pólizas por período: la póliza vigente de un producto y la anterior, cada una con su
 * número, su vigencia y sus pagos.
 *
 * Ejemplo (Gonzalo Peña, dentista): tiene la póliza Odontólogo 26157 (01-01-2026 a 01-07-2027) con todos sus pagos
 * juntos. Queda:
 *   - Odontólogo 26157: vigencia 01-01-2026 a 01-07-2027, con los pagos desde el 01-01-2026.
 *   - Odontólogo 24818: vigencia 18 meses antes (01-07-2024 a 01-01-2026), con TODOS los pagos anteriores al 01-01-2026
 *     (incluso los más antiguos: no hay pólizas más viejas donde ponerlos).
 *
 * Uso:
 *   php scripts/separar_por_periodos.php --informe  <RUT> [--producto=Odontólogo] [--reutilizar=<id de póliza>]
 *   php scripts/separar_por_periodos.php --aplicar  <RUT> [--producto=Odontólogo] [--reutilizar=<id de póliza>]
 *   php scripts/separar_por_periodos.php --informe  --todos [--producto=Odontólogo]
 *   php scripts/separar_por_periodos.php --aplicar  --todos [--producto=Odontólogo]
 *   DB_NAME=unificar_nominas_prueba php scripts/separar_por_periodos.php --aplicar --todos   (probar en una copia)
 *
 *   --informe     calcula todo y lo muestra, pero DESHACE los cambios.
 *   --todos       toda póliza que tenga el número vigente de su producto y pagos anteriores al inicio de su vigencia
 *                 (con --producto, solo las de ese producto). Si la persona ya tiene la póliza del número anterior, se usa.
 *   --reutilizar  (solo con un RUT) convierte una póliza existente de la persona, que debe estar sin pagos, en la póliza
 *                 anterior, en vez de crear una nueva. Conserva sus observaciones y sus fechas.
 *
 * La póliza anterior que se crea copia de la vigente el tipo de contrato, la compañía, el corredor, la cobertura, el medio
 * de pago, la fecha de alta, el pagador y los valores; queda NO VIGENTE (su período ya terminó).
 *
 * Requiere las migraciones 006 (pólizas matrices) y 007 (varias pólizas por producto). Los pagos solo cambian de póliza
 * (nunca se borran ni se modifican); todo ocurre en UNA transacción y se verifica antes de confirmar.
 */

require_once __DIR__ . '/../config/database.php';

$args = array_slice($argv, 1);
$modo = array_shift($args);
$todos = false;
$rut = 0;
$opciones = [];
foreach ($args as $a) {
    if ($a === '--todos') $todos = true;
    elseif (preg_match('/^--([a-z]+)=(.+)$/u', $a, $m)) $opciones[$m[1]] = $m[2];
    elseif (ctype_digit($a)) $rut = (int) $a;
}
if (!in_array($modo, ['--informe', '--aplicar'], true) || (!$todos && $rut <= 0) || ($todos && $rut > 0)) {
    exit("Uso: php scripts/separar_por_periodos.php --informe|--aplicar (<RUT> [--reutilizar=<id>] | --todos) [--producto=Odontólogo]\n");
}
$soloProducto = $opciones['producto'] ?? ($todos ? null : 'Odontólogo');
$reutilizar = isset($opciones['reutilizar']) ? (int) $opciones['reutilizar'] : null;
if ($todos && $reutilizar !== null) exit("--reutilizar solo se puede usar con un RUT.\n");

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn()) exit("La base `$base` no tiene la migración 006 (pólizas matrices).\n");
if (!$pdo->query("SHOW INDEX FROM polizas WHERE Key_name = 'uq_poliza_rut_producto_numero'")->fetchColumn()) exit("La base `$base` no tiene la migración 007 (varias pólizas por producto).\n");

function n(int $v): string { return number_format($v, 0, ',', '.'); }
function fecha(string $f): string { return date('d-m-Y', strtotime($f)); }

/** Huellas de lo que NO debe cambiar al separar. */
function huellas(PDO $pdo): array
{
    return [
        // Cada pago, ligado a su persona (da igual a qué póliza de ella pertenezca). Es una suma de CRC32 por pago, así que
        // no depende del orden (una persona con dos pólizas puede tener el mismo mes en las dos) ni del largo de un texto.
        'pagos' => (string) $pdo->query("SELECT CONCAT(SUM(CRC32(CONCAT(z.rut, ':', p.periodo, ':', p.pagado, ':', COALESCE(p.monto, '')))), '/',
                                                       SUM(CRC32(CONCAT(COALESCE(p.monto, 0), ':', p.pagado, ':', p.periodo, ':', z.rut))))
                                         FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id")->fetchColumn(),
        'total_pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
        'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
    ];
}

// ---- Productos y sus pólizas matrices (de la más reciente a la más antigua: la primera es la vigente, la segunda la anterior) ----
$productos = $pdo->query('SELECT id, nombre FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
$matrices = [];
foreach ($pdo->query('SELECT producto_id, numero, vigencia_desde, vigencia_hasta FROM polizas_matrices ORDER BY producto_id, vigencia_hasta DESC') as $r) {
    $matrices[(int) $r['producto_id']][] = $r;
}
if ($soloProducto !== null && !in_array($soloProducto, $productos, true)) exit("No existe el producto \"$soloProducto\".\n");

// ---- Pólizas a separar ----
$sql = 'SELECT z.id, z.rut, z.producto_id, z.numero, a.nombre FROM polizas z JOIN asegurados a ON a.rut = z.rut WHERE z.producto_id IS NOT NULL';
$params = [];
if ($rut > 0) { $sql .= ' AND z.rut = ?'; $params[] = $rut; }
if ($soloProducto !== null) { $sql .= ' AND z.producto_id = ?'; $params[] = array_search($soloProducto, $productos, true); }
$st = $pdo->prepare($sql . ' ORDER BY z.id');
$st->execute($params);
$candidatas = [];
foreach ($st->fetchAll() as $z) {
    $m = $matrices[(int) $z['producto_id']] ?? null;
    if (!$m || count($m) < 2 || $z['numero'] !== $m[0]['numero']) continue; // solo las que tienen el número vigente de su producto
    $candidatas[] = $z + ['vigente' => $m[0], 'anterior' => $m[1]];
}
if ($rut > 0 && !$candidatas) exit("La persona $rut no tiene una póliza con el número vigente de su producto" . ($soloProducto ? " ($soloProducto)" : '') . ".\n");

$antes = huellas($pdo);
$antesPolizas = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn();
$maxIdAntes = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM polizas')->fetchColumn();

// ---- Sentencias reutilizadas ----
$conPagos = $pdo->prepare('SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = ? AND periodo < ?');
$buscaAnterior = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ?');
$crearAnterior = $pdo->prepare(
    'INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, estado)
     SELECT rut, ?, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, \'NO VIGENTE\' FROM polizas WHERE id = ?'
);
$borrarPeriodos = $pdo->prepare('DELETE FROM poliza_periodos WHERE poliza_id = ?');
$crearPeriodo = $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')");
$mover = $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE poliza_id = ? AND periodo < ?');
$log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'separada_por_periodo', NULL, ?, 'separacion_periodos')");

$stats = ['separadas' => 0, 'anterior_creada' => 0, 'anterior_ya_existia' => 0, 'anterior_reutilizada' => 0, 'pagos_movidos' => 0, 'sin_pagos_anteriores' => 0, 'por_producto' => []];
$ejemplo = null;

$pdo->beginTransaction();
try {
    foreach ($candidatas as $z) {
        $producto = $productos[(int) $z['producto_id']];
        $v = $z['vigente'];
        $a = $z['anterior'];
        $corte = $v['vigencia_desde'];

        $conPagos->execute([$z['id'], $corte]);
        $hayPagos = (int) $conPagos->fetchColumn();
        if ($hayPagos === 0 && $reutilizar === null) { $stats['sin_pagos_anteriores']++; continue; } // nada que separar

        // póliza anterior: la reutilizada, la que ya tiene ese número, o una nueva
        if ($reutilizar !== null) {
            $s = $pdo->prepare('SELECT id, numero FROM polizas WHERE id = ? AND rut = ? FOR UPDATE');
            $s->execute([$reutilizar, $z['rut']]);
            $r = $s->fetch();
            if (!$r) throw new RuntimeException("La póliza $reutilizar no existe o no es de esta persona.");
            if ((int) $r['id'] === (int) $z['id']) throw new RuntimeException('No se puede reutilizar la póliza vigente.');
            $n = (int) $pdo->query("SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = {$r['id']}")->fetchColumn();
            if ($n > 0) throw new RuntimeException("La póliza $reutilizar tiene $n pagos: no se reutiliza para no mezclar datos.");
            $pdo->prepare('UPDATE polizas SET producto_id = ?, numero = ?, estado = \'NO VIGENTE\' WHERE id = ?')->execute([$z['producto_id'], $a['numero'], $r['id']]);
            $anteriorId = (int) $r['id'];
            $stats['anterior_reutilizada']++;
            $accion = "reutilizada la póliza $anteriorId (antes " . ($r['numero'] ?? 'sin número') . ')';
        } else {
            $buscaAnterior->execute([$z['rut'], $z['producto_id'], $a['numero']]);
            $existente = $buscaAnterior->fetchColumn();
            if ($existente !== false) {
                $anteriorId = (int) $existente;
                $stats['anterior_ya_existia']++;
                $accion = "ya existía la póliza $anteriorId";
            } else {
                $crearAnterior->execute([$a['numero'], $z['id']]);
                $anteriorId = (int) $pdo->lastInsertId();
                $stats['anterior_creada']++;
                $accion = "creada la póliza $anteriorId";
            }
        }

        $borrarPeriodos->execute([$anteriorId]);
        $crearPeriodo->execute([$anteriorId, $a['numero'], $a['vigencia_desde'], $a['vigencia_hasta']]);
        $mover->execute([$anteriorId, $z['id'], $corte]);
        $movidos = $mover->rowCount();
        $log->execute([(string) $anteriorId, "Póliza {$a['numero']} ($producto): $movidos pagos anteriores a $corte, desde la póliza {$z['id']}"]);

        $stats['separadas']++;
        $stats['pagos_movidos'] += $movidos;
        $stats['por_producto'][$producto] = ($stats['por_producto'][$producto] ?? 0) + 1;
        if ($rut > 0) {
            $ejemplo = [$z, $anteriorId, $accion, $movidos];
        }
    }

    // ---- verificación: no se perdió ni cambió ningún pago; nadie más fue tocado ----
    $despues = huellas($pdo);
    $errores = [];
    foreach ($antes as $k => $val) if ($despues[$k] !== $val) $errores[] = "cambió: $k";
    $polizasNuevas = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn() - $antesPolizas;
    if ($polizasNuevas !== $stats['anterior_creada']) $errores[] = "pólizas nuevas esperadas {$stats['anterior_creada']}, hay $polizasNuevas";
    foreach ($candidatas as $z) { // ninguna póliza vigente puede conservar pagos anteriores a su inicio (solo las que se separaron)
    }
    $pendientes = (int) $pdo->query("SELECT COUNT(*) FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id
        JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero AND m.vigencia_hasta = (SELECT MAX(vigencia_hasta) FROM polizas_matrices WHERE producto_id = z.producto_id)
        WHERE p.periodo < m.vigencia_desde" . ($rut > 0 ? " AND z.rut = $rut" : '') . ($soloProducto !== null ? ' AND z.producto_id = ' . (int) array_search($soloProducto, $productos, true) : ''))->fetchColumn();
    if ($pendientes > 0) $errores[] = "quedaron $pendientes pagos anteriores al inicio en pólizas vigentes";
    $sinPeriodo = (int) $pdo->query("SELECT COUNT(*) FROM polizas z WHERE z.id > $maxIdAntes AND NOT EXISTS (SELECT 1 FROM poliza_periodos p WHERE p.poliza_id = z.id)")->fetchColumn();
    if ($sinPeriodo > 0) $errores[] = "$sinPeriodo pólizas nuevas sin período";
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));

    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
if ($rut > 0 && $ejemplo) {
    [$z, $anteriorId, $accion, $movidos] = $ejemplo;
    echo "Persona: {$z['nombre']} ($rut) | $accion | $movidos pagos pasaron a la póliza anterior\n";
    $linea = function (int $id) use ($pdo) {
        $r = $pdo->query("SELECT z.numero, pr.nombre, (SELECT CONCAT(vigencia_desde, ' a ', vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id LIMIT 1) vig,
            (SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = z.id) pagos, (SELECT CONCAT(MIN(DATE_FORMAT(periodo,'%Y-%m')), ' a ', MAX(DATE_FORMAT(periodo,'%Y-%m'))) FROM pagos_mensuales WHERE poliza_id = z.id) rango
            FROM polizas z JOIN productos pr ON pr.id = z.producto_id WHERE z.id = $id")->fetch();
        return "Póliza $id: {$r['nombre']} {$r['numero']}, vigencia {$r['vig']} → {$r['pagos']} pagos" . ($r['pagos'] ? " ({$r['rango']})" : '');
    };
    echo '  ' . $linea((int) $z['id']) . "\n  " . $linea($anteriorId) . "\n";
} else {
    echo 'Pólizas con el número vigente: ' . n(count($candidatas)) . ' | separadas: ' . n($stats['separadas']) . ' | sin pagos anteriores (no se tocan): ' . n($stats['sin_pagos_anteriores']) . "\n";
    echo '  Póliza anterior creada: ' . n($stats['anterior_creada']) . ' | ya existía: ' . n($stats['anterior_ya_existia']) . ' | pagos movidos: ' . n($stats['pagos_movidos']) . "\n";
    echo '  Por producto: ' . json_encode($stats['por_producto'], JSON_UNESCAPED_UNICODE) . "\n";
}
echo 'Verificado: el total de pagos (' . n($antes['total_pagos']) . ") y los pagos de cada persona no cambiaron.\n";
