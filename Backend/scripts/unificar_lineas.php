<?php

/**
 * Unifica en UN producto a las personas que quedaron con dos líneas de pólizas (p. ej. Odontólogo y SOEMAF) porque dos
 * archivos las registraron por separado. Se queda con la línea de destino (la póliza que de verdad las cubre) y pasa a
 * ella todo el historial de la línea de origen.
 *
 * Ejemplo (Johana Uribe, cirujano dentista SOEMAF): tenía Odontólogo 26157 / 24818 y SOEMAF 28581 / 24935. Queda solo
 * SOEMAF, con dos pólizas: 28581 (vigente, desde 01-02-2026) y 24935 (anterior).
 *
 * Uso:
 *   php scripts/unificar_lineas.php --informe <RUT> --origen=Odontólogo --destino=SOEMAF   una persona
 *   php scripts/unificar_lineas.php --aplicar <RUT> --origen=Odontólogo --destino=SOEMAF
 *   php scripts/unificar_lineas.php --informe --todos    a todas las personas con dos líneas con pagos en los mismos meses:
 *   php scripts/unificar_lineas.php --aplicar --todos    se queda la línea que corresponde a su especialidad
 *   DB_NAME=unificar_nominas_prueba php scripts/unificar_lineas.php --aplicar ...   (probar en una copia)
 *
 * Con --todos se OMITEN (y se listan, con el motivo) las personas que no se pueden decidir solas: sin especialidad, con una
 * especialidad cuyo producto no es ninguna de sus dos líneas, con más de dos líneas, sin la póliza vigente en la línea de
 * destino, o cuya línea de origen está vigente y la de destino no (se perdería su vigencia). Si al destino solo le falta la
 * póliza anterior, se crea (copiando los datos de la vigente, como NO VIGENTE y con su período de 18 meses).
 *
 * Qué hace con cada pago de la línea de origen:
 *   - si el mes es anterior al inicio de la póliza vigente del destino → pasa a la póliza anterior del destino; si no, a la vigente;
 *   - si el destino ya tiene ese mes (el mismo mes registrado en las dos líneas), SE CONSERVA EL DEL DESTINO y el del origen
 *     sale de la ficha; antes se guarda completo en `historial_cambios` (con su monto) para poder recuperarlo.
 * Luego las pólizas de origen, ya vacías, se eliminan; su fila completa (observaciones, cobertura, medio de pago…) también
 * queda en `historial_cambios`.
 *
 * --informe calcula todo y lo muestra, pero DESHACE los cambios. Todo ocurre en una transacción y se verifica antes de
 * confirmar. Hacer un respaldo (mysqldump) antes de --aplicar.
 */

require_once __DIR__ . '/../config/database.php';

$args = array_slice($argv, 1);
$modo = array_shift($args);
$rut = 0;
$todos = false;
$op = [];
foreach ($args as $a) {
    if ($a === '--todos') $todos = true;
    elseif (ctype_digit($a)) $rut = (int) $a;
    elseif (preg_match('/^--([a-z]+)=(.+)$/u', $a, $m)) $op[$m[1]] = $m[2];
}
if (!in_array($modo, ['--informe', '--aplicar'], true) || ($todos ? ($rut > 0 || $op) : ($rut <= 0 || empty($op['origen']) || empty($op['destino'])))) {
    exit("Uso: php scripts/unificar_lineas.php --informe|--aplicar (--todos | <RUT> --origen=<producto> --destino=<producto>)\n");
}
if (!$todos && $op['origen'] === $op['destino']) exit("El origen y el destino no pueden ser el mismo producto.\n");

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn()) exit("La base `$base` no tiene la migración 006.\n");

function n(int $v): string { return number_format($v, 0, ',', '.'); }

/** Producto que debe cubrir a la persona según su especialidad y su "otro tipo" (misma regla que api/_comun.php). */
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

$productos = $pdo->query('SELECT nombre, id FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
$nombreProducto = array_flip($productos);

/** Pólizas matrices de un producto, de la vigente a la anterior. */
$matrices = [];
foreach ($pdo->query('SELECT producto_id, numero, vigencia_desde, vigencia_hasta FROM polizas_matrices ORDER BY producto_id, vigencia_hasta DESC') as $r) {
    $matrices[(int) $r['producto_id']][] = $r;
}

/** Suma de CRC32 por pago de las DEMÁS personas: no puede cambiar. */
$huellaOtros = fn(array $ruts) => (string) $pdo->query("SELECT CONCAT(COUNT(*), '/', COALESCE(SUM(CRC32(CONCAT(z.rut, ':', p.periodo, ':', p.pagado, ':', COALESCE(p.monto, '')))), 0))
                                                        FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id
                                                        WHERE z.rut NOT IN (" . implode(',', array_map('intval', $ruts)) . ')')->fetchColumn();
$cuenta = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

// ---------------------------------------------------------------------------------------------
// Qué personas se procesan y con qué origen y destino
// ---------------------------------------------------------------------------------------------
$trabajo = []; // [rut, origenId, destinoId, vigenteDest, anteriorDest, nombre]
$omitidas = [];

if (!$todos) {
    foreach (['origen', 'destino'] as $k) if (!isset($productos[$op[$k]])) exit("No existe el producto \"{$op[$k]}\".\n");
    $trabajo[] = [$rut, $productos[$op['origen']], $productos[$op['destino']]];
} else {
    // Personas con pagos en el mismo mes en dos pólizas de productos distintos
    $candidatas = $pdo->query(
        'SELECT DISTINCT z1.rut FROM polizas z1
         JOIN polizas z2 ON z2.rut = z1.rut AND z2.producto_id > z1.producto_id
         JOIN pagos_mensuales m1 ON m1.poliza_id = z1.id
         JOIN pagos_mensuales m2 ON m2.poliza_id = z2.id AND m2.periodo = m1.periodo
         ORDER BY z1.rut'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($candidatas as $r) {
        $r = (int) $r;
        $p = $pdo->prepare('SELECT a.nombre, a.especialidad, ti.nombre AS otro_tipo FROM asegurados a LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id WHERE a.rut = ?');
        $p->execute([$r]);
        $per = $p->fetch();
        $l = $pdo->prepare('SELECT z.producto_id, z.numero, z.estado FROM polizas z WHERE z.rut = ? AND z.producto_id IS NOT NULL');
        $l->execute([$r]);
        $lineas = [];
        foreach ($l->fetchAll() as $z) $lineas[(int) $z['producto_id']][] = $z;
        $omitir = function (string $motivo) use (&$omitidas, $r, $per) { $omitidas[$motivo][] = "$r {$per['nombre']}"; };

        if (count($lineas) > 2) { $omitir('tiene más de dos líneas de productos'); continue; }
        $deseado = productoSegunEspecialidad($per['especialidad'], $per['otro_tipo']);
        if ($deseado === null) { $omitir('no tiene especialidad registrada'); continue; }
        $destinoId = $productos[$deseado] ?? null;
        if (!isset($lineas[$destinoId])) { $omitir("su especialidad corresponde a $deseado, que no es ninguna de sus líneas"); continue; }
        $origenId = array_values(array_diff(array_keys($lineas), [$destinoId]))[0];

        // El destino debe tener la póliza vigente y la anterior
        $mat = $matrices[$destinoId] ?? [];
        $numeros = array_column($lineas[$destinoId], 'estado', 'numero');
        if (count($mat) < 2 || !isset($numeros[$mat[0]['numero']])) { $omitir("no tiene la póliza vigente de $deseado ({$mat[0]['numero']})"); continue; }
        // No se pierde una vigencia: si el origen está vigente, el destino también debe estarlo
        $origenVigente = (bool) array_filter($lineas[$origenId], fn($z) => in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true));
        $destinoVigente = in_array($numeros[$mat[0]['numero']], ['VIGENTE', 'VAN A CORTE'], true);
        if ($origenVigente && !$destinoVigente) { $omitir("su línea {$nombreProducto[$origenId]} está vigente y la de $deseado no (se perdería su vigencia)"); continue; }

        $trabajo[] = [$r, $origenId, $destinoId];
    }
}

// ---------------------------------------------------------------------------------------------
// Unificación (una sola transacción)
// ---------------------------------------------------------------------------------------------
$ruts = array_column($trabajo, 0);
if (!$ruts && $todos) { echo "No hay personas para unificar.\n"; }
$otrosAntes = $ruts ? $huellaOtros($ruts) : null;
$totalAntes = $cuenta('SELECT COUNT(*) FROM pagos_mensuales');
$polizasAntes = $cuenta('SELECT COUNT(*) FROM polizas');

$resumen = ['personas' => 0, 'movidos' => 0, 'duplicados' => 0, 'polizas_eliminadas' => 0, 'anteriores_creadas' => 0, 'por_par' => []];
$detalle = [];

$pdo->beginTransaction();
try {
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES (?, ?, ?, ?, ?, 'unificar_lineas')");
    $mover = $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?');
    $borrarPago = $pdo->prepare('DELETE FROM pagos_mensuales WHERE id = ?');

    foreach ($trabajo as [$r, $origenId, $destinoId]) {
        $nombre = $pdo->query("SELECT nombre FROM asegurados WHERE rut = $r")->fetchColumn();
        if ($nombre === false) throw new RuntimeException("No existe una persona con el RUT $r.");
        $mat = $matrices[$destinoId] ?? [];
        if (count($mat) < 2) throw new RuntimeException("El producto {$nombreProducto[$destinoId]} no tiene póliza vigente y anterior en la tabla de matrices.");

        $polizaDe = function (string $numero) use ($pdo, $r, $destinoId) {
            $s = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ?');
            $s->execute([$r, $destinoId, $numero]);
            $id = $s->fetchColumn();
            return $id === false ? null : (int) $id;
        };
        $destVigente = $polizaDe($mat[0]['numero']);
        $destAnterior = $polizaDe($mat[1]['numero']);
        if (!$destVigente) throw new RuntimeException("$nombre no tiene la póliza vigente de {$nombreProducto[$destinoId]} ({$mat[0]['numero']}).");
        if (!$destAnterior) {
            // Falta la póliza del período anterior: se crea copiando los datos de la vigente (NO VIGENTE, con su período de la matriz)
            $pdo->prepare('INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                    rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, estado)
                 SELECT rut, ?, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                    rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, \'NO VIGENTE\' FROM polizas WHERE id = ?')
                ->execute([$mat[1]['numero'], $destVigente]);
            $destAnterior = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')")
                ->execute([$destAnterior, $mat[1]['numero'], $mat[1]['vigencia_desde'], $mat[1]['vigencia_hasta']]);
            $resumen['anteriores_creadas']++;
        }

        $origenes = $pdo->query("SELECT id FROM polizas WHERE rut = $r AND producto_id = $origenId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        if (!$origenes) throw new RuntimeException("$nombre no tiene pólizas de {$nombreProducto[$origenId]}.");
        $enLista = implode(',', array_map('intval', $origenes));

        $destPagos = [];
        foreach ($pdo->query("SELECT DATE_FORMAT(periodo, '%Y-%m-%d') AS periodo FROM pagos_mensuales WHERE poliza_id IN ($destVigente, $destAnterior)") as $x) $destPagos[$x['periodo']] = true;

        $movidos = $duplicados = 0;
        $pagos = $pdo->query("SELECT id, poliza_id, DATE_FORMAT(periodo, '%Y-%m-%d') AS periodo, pagado, monto, valor_original, nota, origen
                              FROM pagos_mensuales WHERE poliza_id IN ($enLista) ORDER BY periodo, id")->fetchAll();
        foreach ($pagos as $p) {
            $destino = $p['periodo'] < $mat[0]['vigencia_desde'] ? $destAnterior : $destVigente;
            if (isset($destPagos[$p['periodo']])) {
                // El destino ya tiene ese mes: se conserva el suyo y el del origen se guarda en el historial antes de salir
                $log->execute(['pagos_mensuales', "$r|{$p['periodo']}|p{$p['poliza_id']}", 'duplicado_eliminado',
                    json_encode(['pagado' => (int) $p['pagado'], 'monto' => $p['monto'], 'valor_original' => $p['valor_original'], 'nota' => $p['nota'], 'origen' => $p['origen']], JSON_UNESCAPED_UNICODE),
                    "mes ya registrado en la línea {$nombreProducto[$destinoId]}; se conservó el de esa línea"]);
                $borrarPago->execute([$p['id']]);
                $duplicados++;
            } else {
                $mover->execute([$destino, $p['id']]);
                $destPagos[$p['periodo']] = true;
                $movidos++;
            }
        }

        // Las pólizas de origen ya están sin pagos: se guardan completas en el historial y se eliminan
        foreach ($origenes as $id) {
            $fila = $pdo->query("SELECT * FROM polizas WHERE id = $id")->fetch();
            $periodos = $pdo->query("SELECT numero, vigencia_desde, vigencia_hasta, origen FROM poliza_periodos WHERE poliza_id = $id")->fetchAll();
            $log->execute(['polizas', (string) $id, 'unificada_en_otro_producto', json_encode(['poliza' => $fila, 'periodos' => $periodos], JSON_UNESCAPED_UNICODE),
                "{$nombreProducto[$origenId]} {$fila['numero']} unificada con {$nombreProducto[$destinoId]} (pólizas $destVigente y $destAnterior)"]);
            $pdo->exec("DELETE FROM poliza_periodos WHERE poliza_id = $id");
            $pdo->exec("DELETE FROM polizas WHERE id = $id");
            $resumen['polizas_eliminadas']++;
        }

        // Verificación por persona: nada de su origen queda, y no se perdió ningún pago salvo los archivados
        $quedan = $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $r AND producto_id = $origenId")->fetchColumn();
        if ($quedan > 0) throw new RuntimeException("A $nombre le quedaron pólizas de origen.");
        if ($movidos + $duplicados !== count($pagos)) throw new RuntimeException("A $nombre le quedó un pago del origen sin mover ni archivar.");

        $resumen['personas']++;
        $resumen['movidos'] += $movidos;
        $resumen['duplicados'] += $duplicados;
        $par = "{$nombreProducto[$origenId]} → {$nombreProducto[$destinoId]}";
        $resumen['por_par'][$par] = ($resumen['por_par'][$par] ?? 0) + 1;
        $detalle[$r] = [$nombre, $par, $movidos, $duplicados, $destVigente, $destAnterior];
    }

    // ---- verificación global ----
    $errores = [];
    if ($ruts && $huellaOtros($ruts) !== $otrosAntes) $errores[] = 'cambiaron los pagos de otras personas';
    if ($cuenta('SELECT COUNT(*) FROM pagos_mensuales') !== $totalAntes - $resumen['duplicados']) $errores[] = 'el total de pagos no cuadra (debe bajar solo por los duplicados archivados)';
    if ($cuenta('SELECT COUNT(*) FROM polizas') !== $polizasAntes + $resumen['anteriores_creadas'] - $resumen['polizas_eliminadas']) $errores[] = 'el total de pólizas no cuadra (antes + creadas - eliminadas)';
    $archivados = $cuenta("SELECT COUNT(*) FROM historial_cambios WHERE usuario = 'unificar_lineas' AND campo = 'duplicado_eliminado' AND created_at >= NOW() - INTERVAL 1 DAY");
    if ($archivados < $resumen['duplicados']) $errores[] = 'no se archivaron todos los pagos eliminados';
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));

    $totalDespues = $cuenta('SELECT COUNT(*) FROM pagos_mensuales');
    $polizasDespues = $cuenta('SELECT COUNT(*) FROM polizas');
    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------------------------
echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
if (!$todos) {
    [$nombre, $par, $movidos, $duplicados, $dv, $da] = $detalle[$rut];
    echo "$nombre ($rut): $par\n  Pagos pasados al destino: $movidos | meses duplicados (se conservó el del destino; el otro quedó en el historial): $duplicados\n";
    foreach ([$dv, $da] as $id) {
        $x = $pdo->query("SELECT pr.nombre, z.numero, z.estado, (SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = z.id) pagos,
                (SELECT CONCAT(MIN(DATE_FORMAT(periodo,'%Y-%m')), ' a ', MAX(DATE_FORMAT(periodo,'%Y-%m'))) FROM pagos_mensuales WHERE poliza_id = z.id) rango FROM polizas z JOIN productos pr ON pr.id = z.producto_id WHERE z.id = $id")->fetch();
        echo "  {$x['nombre']} {$x['numero']} ({$x['estado']}): {$x['pagos']} pagos" . ($x['pagos'] ? " ({$x['rango']})" : '') . "\n";
    }
} else {
    echo 'Personas con dos líneas y pagos en los mismos meses: ' . n(count($trabajo) + array_sum(array_map('count', $omitidas))) . ' | se unifican: ' . n($resumen['personas']) . ' | se omiten: ' . n(array_sum(array_map('count', $omitidas))) . "\n";
    echo '  ' . json_encode($resumen['por_par'], JSON_UNESCAPED_UNICODE) . "\n";
    echo '  Pagos pasados al destino: ' . n($resumen['movidos']) . ' | meses duplicados archivados: ' . n($resumen['duplicados']) . ' | pólizas de origen eliminadas: ' . n($resumen['polizas_eliminadas']) . ' | pólizas anteriores creadas en el destino: ' . n($resumen['anteriores_creadas']) . "\n";
    foreach ($omitidas as $motivo => $lista) {
        echo "  OMITIDAS (" . count($lista) . ") — $motivo:\n";
        foreach (array_slice($lista, 0, 6) as $x) echo "      $x\n";
        if (count($lista) > 6) echo '      … y ' . (count($lista) - 6) . " más\n";
    }
}
echo 'Verificado: pagos ' . n($totalAntes) . ' → ' . n($totalDespues) . ' (solo bajan los duplicados archivados), pólizas ' . n($polizasAntes) . ' → ' . n($polizasDespues) . ", y los pagos de las demás personas no cambiaron.\n";
