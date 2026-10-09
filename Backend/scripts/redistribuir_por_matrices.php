<?php

/**
 * Redistribuye los pagos de cada persona entre las pólizas matrices de su producto, según el período de cada pago.
 *
 * Duraciones confirmadas por el usuario (08-10-2026):
 *   Odontólogo           26157 01-01-2026→01-07-2027 | 24818 01-01-2025→01-01-2026 | 22728 01-01-2024→01-01-2025 | 19002 01-01-2023→01-01-2024
 *   Individual y Derma   26158 01-02-2026→01-08-2027 | 24930 01-01-2025→01-02-2026 | 22882 01-02-2024→01-01-2025 | 19035 01-01-2024→01-02-2024
 *   SOEMAF               28581 01-02-2026→01-08-2027 | 24935 01-01-2025→01-02-2026 | 22883 01-02-2024→01-01-2025 | 19035 01-01-2024→01-02-2024
 *
 * Qué hace (todo en UNA transacción):
 *   1. Deja esas duraciones en `polizas_matrices` (actualiza las que existen y agrega las que faltan).
 *   2. Cada pago de una póliza con número de matriz pasa a la póliza de su persona que corresponde a su período. Si la persona
 *      no tiene esa póliza, se crea (copia contrato, compañía, corredor, cobertura, medio de pago, alta, pagador y valores;
 *      queda NO VIGENTE, salvo que sea la póliza más reciente: ahí conserva el estado de la póliza de donde vienen los pagos).
 *   3. Los pagos anteriores al inicio de la póliza más antigua (por ejemplo de 2022 en Odontólogo) se quedan en esa póliza más antigua.
 *   4. Ajusta el período (poliza_periodos) de las pólizas con número de matriz a la duración de la matriz.
 *
 * Pólizas "sin asignar" (sin producto, número 99999 o sin número): si la persona tiene otra póliza con producto se usa ese producto (si no, el de su especialidad); sus pagos se reparten igual, una de ellas se convierte en una de las pólizas y, si queda sin pagos, se elimina (archivada). No toca pólizas con un número que no sea matriz de su producto. Nunca borra ni modifica un
 * pago (solo cambia de póliza). Si el mismo período ya existe en la póliza de destino, no se mueve y se lista como conflicto.
 *
 * Uso:
 *   php scripts/redistribuir_por_matrices.php --informe [--rut=N] [--producto=Odontólogo]   calcula, pero DESHACE todo
 *   php scripts/redistribuir_por_matrices.php --aplicar [--rut=N] [--producto=Odontólogo]   (hacer antes un respaldo)
 *   DB_NAME=unificar_nominas_prueba php scripts/redistribuir_por_matrices.php --aplicar     (probar en una copia)
 */

ini_set('memory_limit', '2G');
require_once __DIR__ . '/../config/database.php';

const DURACIONES = [
    'Odontólogo' => [['26157', '2026-01-01', '2027-07-01'], ['24818', '2025-01-01', '2026-01-01'], ['22728', '2024-01-01', '2025-01-01'], ['19002', '2023-01-01', '2024-01-01']],
    'Individual' => [['26158', '2026-02-01', '2027-08-01'], ['24930', '2025-01-01', '2026-02-01'], ['22882', '2024-02-01', '2025-01-01'], ['19035', '2024-01-01', '2024-02-01']],
    'Derma' => [['26158', '2026-02-01', '2027-08-01'], ['24930', '2025-01-01', '2026-02-01'], ['22882', '2024-02-01', '2025-01-01'], ['19035', '2024-01-01', '2024-02-01']],
    'SOEMAF' => [['28581', '2026-02-01', '2027-08-01'], ['24935', '2025-01-01', '2026-02-01'], ['22883', '2024-02-01', '2025-01-01'], ['19035', '2024-01-01', '2024-02-01']],
];

$modo = $argv[1] ?? '';
$opciones = [];
foreach (array_slice($argv, 2) as $a) if (preg_match('/^--([a-z]+)=(.+)$/u', $a, $m)) $opciones[$m[1]] = $m[2];
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/redistribuir_por_matrices.php --informe|--aplicar [--rut=N] [--producto=Odontólogo]\n");
$soloRut = isset($opciones['rut']) ? (int) $opciones['rut'] : 0;
$soloProducto = $opciones['producto'] ?? null;

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn()) exit("La base `$base` no tiene la migración 006 (pólizas matrices).\n");
if (!$pdo->query("SHOW INDEX FROM polizas WHERE Key_name = 'uq_poliza_rut_producto_numero'")->fetchColumn()) exit("La base `$base` no tiene la migración 007.\n");

function n(int $v): string { return number_format($v, 0, ',', '.'); }

$productos = $pdo->query('SELECT nombre, id FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
$nombreDe = array_flip($productos);
foreach (array_keys(DURACIONES) as $p) if (!isset($productos[$p])) exit("No existe el producto \"$p\".\n");
if ($soloProducto !== null && !isset(DURACIONES[$soloProducto])) exit("Producto \"$soloProducto\" sin duraciones definidas.\n");

/** Huellas de lo que NO debe cambiar: cada pago ligado a su persona, sin importar a qué póliza suya pertenezca. */
function huellas(PDO $pdo): array
{
    return [
        'pagos' => (string) $pdo->query("SELECT CONCAT(SUM(CRC32(CONCAT(z.rut, ':', p.periodo, ':', p.pagado, ':', COALESCE(p.monto, '')))), '/',
                                                       SUM(CRC32(CONCAT(COALESCE(p.monto, 0), ':', p.pagado, ':', p.periodo, ':', z.rut))))
                                         FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id")->fetchColumn(),
        'total_pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
        'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
    ];
}

/** Producto que cubre a la persona según su especialidad y su "otro tipo" (misma regla que api/_comun.php). */
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

$antes = huellas($pdo);
$polizasAntes = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn();

$pdo->beginTransaction();
try {
    // ---- 1. Duraciones en polizas_matrices ----
    $upsert = $pdo->prepare('INSERT INTO polizas_matrices (producto_id, numero, vigencia_desde, vigencia_hasta, aproximada) VALUES (?,?,?,?,0)
                             ON DUPLICATE KEY UPDATE vigencia_desde = VALUES(vigencia_desde), vigencia_hasta = VALUES(vigencia_hasta), aproximada = 0');
    $conocidas = [];
    foreach ($pdo->query('SELECT producto_id, numero, vigencia_desde, vigencia_hasta FROM polizas_matrices') as $r) {
        $conocidas[$r['producto_id'] . '|' . $r['numero']] = $r['vigencia_desde'] . '|' . $r['vigencia_hasta'];
    }
    $matricesNuevas = $matricesCambiadas = 0;
    foreach (DURACIONES as $producto => $lista) {
        foreach ($lista as [$numero, $desde, $hasta]) {
            $clave = $productos[$producto] . '|' . $numero;
            if (!isset($conocidas[$clave])) $matricesNuevas++;
            elseif ($conocidas[$clave] !== "$desde|$hasta") $matricesCambiadas++;
            $upsert->execute([$productos[$producto], $numero, $desde, $hasta]);
        }
    }

    // Ventanas por producto, de la más antigua a la más reciente
    $ventanas = [];
    foreach (DURACIONES as $producto => $lista) {
        $v = array_map(fn($x) => ['numero' => $x[0], 'desde' => $x[1], 'hasta' => $x[2]], $lista);
        usort($v, fn($a, $b) => strcmp($a['desde'], $b['desde']));
        $ventanas[$productos[$producto]] = $v;
    }
    $destinoDe = function (int $productoId, string $periodo) use ($ventanas): string {
        $v = $ventanas[$productoId];
        if ($periodo < $v[0]['desde']) return $v[0]['numero'];                       // más antiguo que todo: se queda en la póliza más antigua
        foreach ($v as $w) if ($periodo >= $w['desde'] && $periodo < $w['hasta']) return $w['numero'];
        return $v[count($v) - 1]['numero'];                                           // posterior a todo: la más reciente
    };
    $esReciente = fn(int $productoId, string $numero) => $ventanas[$productoId][count($ventanas[$productoId]) - 1]['numero'] === $numero;

    // ---- 2. Pagos de pólizas con número de matriz y de pólizas "sin asignar" (sin producto, con número 99999 o sin número) ----
    $idsProducto = array_map(fn($p) => (int) $productos[$p], array_keys(DURACIONES));
    $idsIn = implode(',', $idsProducto);

    // Producto de las pólizas sin producto: el de las otras pólizas de la persona (si tiene varios, el que corresponde a su
    // especialidad); si no tiene ninguna, el que corresponde a su especialidad. Sin ninguna de las dos: no se asigna.
    $resolverProductos = function (array $ruts) use ($pdo, $productos, $idsIn): array {
        $efectivo = [];
        if (!$ruts) return $efectivo;
        $in = implode(',', array_map('intval', $ruts));
        $tiene = [];
        foreach ($pdo->query("SELECT rut, producto_id, COUNT(*) n FROM polizas WHERE producto_id IN ($idsIn) AND rut IN ($in) GROUP BY rut, producto_id") as $r) {
            $tiene[(int) $r['rut']][(int) $r['producto_id']] = (int) $r['n'];
        }
        foreach ($pdo->query("SELECT a.rut, a.especialidad, ti.nombre AS otro_tipo FROM asegurados a LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id WHERE a.rut IN ($in)") as $a) {
            $porEsp = productoSegunEspecialidad($a['especialidad'], $a['otro_tipo']);
            $idEsp = ($porEsp !== null && isset($productos[$porEsp])) ? (int) $productos[$porEsp] : null;
            $cands = array_keys($tiene[(int) $a['rut']] ?? []);
            if (count($cands) === 1) $efectivo[(int) $a['rut']] = $cands[0];
            elseif (count($cands) > 1) $efectivo[(int) $a['rut']] = in_array($idEsp, $cands, true) ? $idEsp : null;
            else $efectivo[(int) $a['rut']] = $idEsp;
        }
        return $efectivo;
    };
    $cargarPagos = function () use ($pdo, $idsIn, $soloRut, $soloProducto, $productos, $resolverProductos): array {
        $pagos = $pdo->query("SELECT p.id AS pago_id, p.poliza_id, p.periodo, p.pagado, z.rut, z.producto_id, z.numero, z.estado, (m.id IS NULL) AS sin_asignar
                              FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id
                              LEFT JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero
                              WHERE (m.id IS NOT NULL OR z.producto_id IS NULL OR (z.producto_id IN ($idsIn) AND (z.numero IS NULL OR z.numero = '99999')))"
                              . ($soloRut ? " AND z.rut = $soloRut" : ''))->fetchAll();
        $nulos = [];
        foreach ($pagos as $g) if ($g['producto_id'] === null) $nulos[(int) $g['rut']] = true;
        $efectivo = $resolverProductos(array_keys($nulos));
        $salida = [];
        foreach ($pagos as $g) {
            $g['prod'] = $g['producto_id'] !== null ? (int) $g['producto_id'] : ($efectivo[(int) $g['rut']] ?? null);
            if ($g['prod'] !== null && $soloProducto !== null && $g['prod'] !== (int) $productos[$soloProducto]) continue;
            $g['sin_asignar'] = (bool) $g['sin_asignar'];
            $salida[] = $g;
        }
        return $salida;
    };
    $pagos = $cargarPagos();

    $polizas = []; // "rut|producto|numero" => id
    $sqlP = "SELECT z.id, z.rut, z.producto_id, z.numero FROM polizas z JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero"
          . ($soloRut ? " WHERE z.rut = $soloRut" : '');
    foreach ($pdo->query($sqlP) as $z) $polizas["{$z['rut']}|{$z['producto_id']}|{$z['numero']}"] = (int) $z['id'];
    $ocupado = [];
    $estados = [];
    foreach ($pagos as $g) { $ocupado[$g['poliza_id'] . '|' . $g['periodo']] = true; $estados[(int) $g['poliza_id']] = $g['estado']; }

    $movimientos = [];      // "rut|producto|destino" => [poliza_origen => [pagos]]
    $sinProducto = [];      // pólizas sin producto cuyo producto no se puede deducir: poliza_id => [rut, estado, numero]
    $sinAsignarOrigen = []; // pólizas sin asignar con pagos: poliza_id => [destino => [producto, rut]]
    $conflictos = [];
    $prodDeOrigen = [];
    $porConflicto = []; // pólizas de origen donde se quitó algún pago por la regla de conflictos
    $restaHuella = [0, 0];
    $borradosPagos = 0;
    foreach ($pagos as $g) {
        if ($g['prod'] === null) { $sinProducto[(int) $g['poliza_id']] = ['rut' => (int) $g['rut'], 'estado' => $g['estado'], 'numero' => $g['numero']]; continue; }
        $destino = $destinoDe($g['prod'], $g['periodo']);
        if (!$g['sin_asignar'] && $destino === $g['numero']) continue;
        $movimientos["{$g['rut']}|{$g['prod']}|$destino"][(int) $g['poliza_id']][] = ['id' => (int) $g['pago_id'], 'periodo' => $g['periodo'], 'pagado' => (int) $g['pagado']];
        if ($g['sin_asignar']) { $sinAsignarOrigen[(int) $g['poliza_id']][$destino] = [$g['prod'], (int) $g['rut']]; $prodDeOrigen[(int) $g['poliza_id']] = $g['prod']; }
    }
    // Una póliza sin asignar se convierte en la más reciente de las pólizas que le tocan y que la persona aún no tiene; las demás se crean
    $convierte = []; // origen => [producto, destino]
    foreach ($sinAsignarOrigen as $origenId => $destinos) {
        $mejor = null;
        $orden = null;
        foreach ($destinos as $dest => [$prod, $rutO]) {
            $dest = (string) $dest; // las claves numéricas de un arreglo son enteros
            if (isset($polizas["$rutO|$prod|$dest"])) continue;
            $pos = array_search($dest, array_column($ventanas[$prod], 'numero'), true);
            if ($mejor === null || $pos > $orden) { $mejor = [$prod, $dest]; $orden = $pos; }
        }
        if ($mejor !== null) $convierte[$origenId] = $mejor;
    }

    $crear = $pdo->prepare('INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                                rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, estado)
                            SELECT rut, ?, tipo_contrato, compania, corredor, ?, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                                rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, ? FROM polizas WHERE id = ?');
    $convertir = $pdo->prepare('UPDATE polizas SET producto_id = ?, numero = ? WHERE id = ?');
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'redistribuida_por_matriz', NULL, ?, 'redistribuir_matrices')");
    $mover = $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?');

    $stats = ['creadas' => 0, 'creadas_reciente' => 0, 'convertidas' => 0, 'eliminadas' => 0, 'vigentes_vacias' => 0, 'pagos_movidos' => 0, 'impagos_quitados' => 0, 'impagos_sobrescritos' => 0, 'personas' => [], 'creadas_por' => [], 'movidos_por' => []];
    $convertidas = [];
    foreach ($movimientos as $clave => $porOrigen) {
        [$rut, $productoId, $destino] = explode('|', $clave);
        $rut = (int) $rut; $productoId = (int) $productoId;
        $producto = $nombreDe[$productoId];
        $destinoId = $polizas["$rut|$productoId|$destino"] ?? null;
        foreach ($porOrigen as $origenId => $lista) {
            if ($destinoId === null) {
                if (isset($convierte[$origenId]) && $convierte[$origenId] === [$productoId, $destino]) {
                    $convertir->execute([$productoId, $destino, $origenId]);
                    $destinoId = $origenId;
                    $polizas["$rut|$productoId|$destino"] = $destinoId;
                    $convertidas[$origenId] = true;
                    $stats['convertidas']++;
                    $log->execute([(string) $destinoId, "Póliza sin asignar convertida en $producto $destino al repartir los pagos por duración de matriz"]);
                } else {
                    $reciente = $esReciente($productoId, $destino);
                    $estado = $reciente ? ($estados[$origenId] ?? 'NO VIGENTE') : 'NO VIGENTE';
                    $crear->execute([$destino, $productoId, $estado, $origenId]);
                    $destinoId = (int) $pdo->lastInsertId();
                    $polizas["$rut|$productoId|$destino"] = $destinoId;
                    $stats['creadas']++;
                    if ($reciente) $stats['creadas_reciente']++;
                    $stats['creadas_por']["$producto $destino"] = ($stats['creadas_por']["$producto $destino"] ?? 0) + 1;
                    $log->execute([(string) $destinoId, "Póliza $destino ($producto) creada al repartir los pagos por duración de matriz"]);
                }
            }
            if ($destinoId === $origenId) continue; // la póliza convertida ya tiene estos pagos
            $movidos = 0;
            foreach ($lista as $g) {
                if (isset($ocupado["$destinoId|{$g['periodo']}"])) {
                    // Conflicto: el mismo mes ya existe en la póliza de destino. Un pago real sobrescribe a un impago.
                    $existe = $pdo->prepare('SELECT id, pagado FROM pagos_mensuales WHERE poliza_id = ? AND periodo = ?');
                    $existe->execute([$destinoId, $g['periodo']]);
                    $d = $existe->fetch();
                    $quitar = null; // id del pago que se elimina
                    $mueve = false;
                    if ($d && (int) $g['pagado'] === 0) { $quitar = $g['id']; $stats['impagos_quitados']++; }                       // el de origen es impago: sobra
                    elseif ($d && (int) $d['pagado'] === 0) { $quitar = (int) $d['id']; $mueve = true; $stats['impagos_sobrescritos']++; } // el de destino es impago: lo reemplaza el pago real
                    if ($quitar === null) { $conflictos[] = "$rut $producto {$g['periodo']}: los dos meses están pagados, no se tocan (póliza $destino)"; continue; }
                    $fila = $pdo->query("SELECT p.*, z.rut AS rut_persona FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE p.id = $quitar")->fetch();
                    $c = $pdo->query("SELECT CRC32(CONCAT(z.rut, ':', p.periodo, ':', p.pagado, ':', COALESCE(p.monto, ''))) a, CRC32(CONCAT(COALESCE(p.monto, 0), ':', p.pagado, ':', p.periodo, ':', z.rut)) b
                                      FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE p.id = $quitar")->fetch();
                    $restaHuella[0] += (int) $c['a']; $restaHuella[1] += (int) $c['b'];
                    $borradosPagos++;
                    $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('pagos_mensuales', ?, 'eliminado_por_duplicado', ?, ?, 'redistribuir_matrices')")
                        ->execute([(string) $quitar, json_encode($fila, JSON_UNESCAPED_UNICODE), 'Impago sobrescrito: el mismo mes está registrado en otra póliza de la persona']);
                    $pdo->exec("DELETE FROM pagos_mensuales WHERE id = $quitar");
                    $porConflicto[$origenId] = true;
                    if ($mueve) { // el pago real ocupa el lugar del impago que se quitó
                        $mover->execute([$destinoId, $g['id']]);
                        unset($ocupado["$origenId|{$g['periodo']}"]);
                        $movidos++;
                    } else {
                        unset($ocupado["$origenId|{$g['periodo']}"]);
                    }
                    continue;
                }
                $mover->execute([$destinoId, $g['id']]);
                unset($ocupado["$origenId|{$g['periodo']}"]);
                $ocupado["$destinoId|{$g['periodo']}"] = true;
                $movidos++;
            }
            if ($movidos) {
                $stats['pagos_movidos'] += $movidos;
                $stats['personas'][$rut] = true;
                $k = "$producto → $destino";
                $stats['movidos_por'][$k] = ($stats['movidos_por'][$k] ?? 0) + $movidos;
            }
        }
    }

    // Pólizas sin asignar que quedaron sin pagos: ya no sirven (sus pagos están en pólizas con número). Se archivan completas.
    $archivar = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'eliminada_por_redistribucion', ?, ?, 'redistribuir_matrices')");
    foreach (array_keys($sinAsignarOrigen) as $origenId) {
        if (isset($convertidas[$origenId])) continue;
        if ((int) $pdo->query("SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = $origenId")->fetchColumn() > 0) continue;
        $fila = $pdo->query("SELECT * FROM polizas WHERE id = $origenId")->fetch();
        if (isset($porConflicto[$origenId])) { // quedó sin pagos por la regla de conflictos: se conserva con el producto de la persona
            if ($fila['producto_id'] === null && isset($prodDeOrigen[$origenId])) {
                $choca = $pdo->prepare('SELECT COUNT(*) FROM polizas WHERE rut = ? AND producto_id = ? AND numero <=> ? AND id <> ?');
                $choca->execute([$fila['rut'], $prodDeOrigen[$origenId], $fila['numero'], $origenId]);
                if (!(int) $choca->fetchColumn()) {
                    $pdo->prepare('UPDATE polizas SET producto_id = ? WHERE id = ?')->execute([$prodDeOrigen[$origenId], $origenId]);
                    $stats['conservadas_con_producto'] = ($stats['conservadas_con_producto'] ?? 0) + 1;
                    $log->execute([(string) $origenId, 'Póliza sin producto: se conserva como ' . $nombreDe[$prodDeOrigen[$origenId]] . ' (sus pagos eran impagos repetidos)']);
                }
            }
            continue;
        }
        if (in_array($fila['estado'], ['VIGENTE', 'VAN A CORTE'], true)) { $stats['vigentes_vacias']++; continue; }
        $periodosFila = $pdo->query("SELECT numero, vigencia_desde, vigencia_hasta, origen FROM poliza_periodos WHERE poliza_id = $origenId")->fetchAll();
        $archivar->execute([(string) $origenId, json_encode(['poliza' => $fila, 'periodos' => $periodosFila], JSON_UNESCAPED_UNICODE), 'Sus pagos pasaron a pólizas con número de matriz']);
        $pdo->exec("DELETE FROM poliza_periodos WHERE poliza_id = $origenId");
        $pdo->exec("DELETE FROM polizas WHERE id = $origenId");
        $stats['eliminadas']++;
    }

    // ---- 3. Período de cada póliza con número de matriz = duración de la matriz (los períodos puestos a mano no se tocan) ----
    $periodos = ['actualizados' => 0, 'creados' => 0];
    $sqlZ = "SELECT z.id, z.numero, m.vigencia_desde, m.vigencia_hasta,
                    (SELECT COUNT(*) FROM poliza_periodos pp WHERE pp.poliza_id = z.id AND pp.origen = 'manual') AS manuales
             FROM polizas z JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero"
          . ($soloRut ? " WHERE z.rut = $soloRut" : '');
    $borrarAuto = $pdo->prepare("DELETE FROM poliza_periodos WHERE poliza_id = ? AND origen = 'automatica'");
    $leerAuto = $pdo->prepare("SELECT numero, vigencia_desde, vigencia_hasta FROM poliza_periodos WHERE poliza_id = ? AND origen = 'automatica'");
    $crearPer = $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')");
    foreach ($pdo->query($sqlZ)->fetchAll() as $z) {
        if ((int) $z['manuales'] > 0) continue;
        $leerAuto->execute([$z['id']]);
        $actuales = $leerAuto->fetchAll();
        if (count($actuales) === 1 && $actuales[0]['numero'] === $z['numero'] && $actuales[0]['vigencia_desde'] === $z['vigencia_desde'] && $actuales[0]['vigencia_hasta'] === $z['vigencia_hasta']) continue;
        $borrarAuto->execute([$z['id']]);
        $crearPer->execute([$z['id'], $z['numero'], $z['vigencia_desde'], $z['vigencia_hasta']]);
        $periodos[$actuales ? 'actualizados' : 'creados']++;
    }

    // ---- Verificación ----
    $despues = huellas($pdo);
    $errores = [];
    [$h1, $h2] = array_map('intval', explode('/', $antes['pagos']));
    $antes['pagos'] = ($h1 - $restaHuella[0]) . '/' . ($h2 - $restaHuella[1]);
    $antes['total_pagos'] -= $borradosPagos;
    foreach ($antes as $k => $val) if ($despues[$k] !== $val) $errores[] = "cambió: $k";
    $nuevas = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn() - $polizasAntes;
    $esperadas = $stats['creadas'] - $stats['eliminadas'];
    if ($nuevas !== $esperadas) $errores[] = "pólizas esperadas: $esperadas más que antes, hay $nuevas";
    $fuera = 0; // pagos que siguen en una póliza distinta de la que les toca (solo deberían ser los conflictos)
    foreach ($cargarPagos() as $g) if ($g['prod'] !== null && ($g['sin_asignar'] || $destinoDe($g['prod'], $g['periodo']) !== $g['numero'])) $fuera++;
    if ($fuera !== count($conflictos)) $errores[] = "pagos fuera de su póliza: $fuera (conflictos: " . count($conflictos) . ')';
    $sinPeriodo = (int) $pdo->query("SELECT COUNT(*) FROM polizas z JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero
                                     WHERE NOT EXISTS (SELECT 1 FROM poliza_periodos p WHERE p.poliza_id = z.id)" . ($soloRut ? " AND z.rut = $soloRut" : ''))->fetchColumn();
    if ($sinPeriodo > 0) $errores[] = "$sinPeriodo pólizas con número de matriz sin período";
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));

    // Pólizas con número de matriz que quedaron sin pagos (solo para informar)
    $vacias = $pdo->query("SELECT z.estado, COUNT(*) FROM polizas z JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero
                           WHERE NOT EXISTS (SELECT 1 FROM pagos_mensuales p WHERE p.poliza_id = z.id)" . ($soloRut ? " AND z.rut = $soloRut" : '') . ' GROUP BY z.estado')->fetchAll(PDO::FETCH_KEY_PAIR);

    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
echo "Matrices: $matricesNuevas nuevas, $matricesCambiadas con otras fechas\n";
echo 'Pagos movidos: ' . n($stats['pagos_movidos']) . ' | personas afectadas: ' . n(count($stats['personas'])) . ' | pólizas creadas: ' . n($stats['creadas'])
   . ' (de ellas ' . n($stats['creadas_reciente']) . " son la póliza más reciente del producto)\n";
echo 'Pólizas sin asignar: ' . n($stats['convertidas']) . ' convertidas en una póliza con número, ' . n($stats['eliminadas']) . ' eliminadas por quedar sin pagos (archivadas en historial_cambios), '
   . n($stats['vigentes_vacias']) . " VIGENTE que quedaron sin pagos (no se borran)\n";
$porEstado = [];
foreach ($sinProducto as $x) $porEstado[$x['estado']] = ($porEstado[$x['estado']] ?? 0) + 1;
echo 'Sin producto deducible (la persona no tiene otra póliza ni especialidad; no se tocan): ' . n(count($sinProducto)) . ' pólizas ' . json_encode($porEstado, JSON_UNESCAPED_UNICODE) . "\n";
echo 'Períodos de póliza: ' . n($periodos['actualizados']) . ' actualizados, ' . n($periodos['creados']) . " creados\n";
ksort($stats['movidos_por']);
echo "Pagos movidos por producto y póliza de destino:\n";
foreach ($stats['movidos_por'] as $k => $v) echo '   ' . str_pad($k, 26) . n($v) . "\n";
ksort($stats['creadas_por']);
echo "Pólizas creadas:\n";
foreach ($stats['creadas_por'] as $k => $v) echo '   ' . str_pad($k, 26) . n($v) . "\n";
echo 'Pólizas con número de matriz que quedan sin pagos (no se borran): ' . json_encode($vacias, JSON_UNESCAPED_UNICODE) . "\n";
echo 'Regla de conflictos (el mismo mes ya existía en la póliza de destino): ' . n($stats['impagos_quitados']) . ' impagos de origen quitados, ' . n($stats['impagos_sobrescritos'])
   . ' impagos de destino sobrescritos por un pago real; pólizas que quedaron sin pagos y se conservan con producto: ' . n($stats['conservadas_con_producto'] ?? 0) . "\n";
echo 'Conflictos que no se resuelven (los dos meses están pagados): ' . n(count($conflictos)) . "\n";
foreach (array_slice($conflictos, 0, 10) as $c) echo "   $c\n";
echo 'Verificado: salvo los ' . n($borradosPagos) . ' impagos duplicados quitados (archivados en historial_cambios), el total de pagos (' . n($antes['total_pagos']) . ") y los pagos de cada persona no cambiaron.\n";
