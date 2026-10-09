<?php

/**
 * Elimina las líneas de pólizas VACÍAS que sobran: personas que tienen una línea de un producto sin NINGÚN pago (restos de una
 * inscripción antigua en la nómina, p. ej. un Odontólogo que pasó a SOEMAF) y que además tienen otra línea con pagos.
 *
 * Regla (conservadora; solo se elimina cuando es claramente un resto del producto equivocado):
 *   - todas las pólizas de la línea vacía están NO VIGENTE y ninguna tiene pagos;
 *   - la persona tiene especialidad y el producto que le corresponde por ella (ver productoSegunEspecialidad) es OTRO que el de
 *     la línea vacía, y esa línea correspondiente existe y SÍ tiene pagos.
 * Se omiten (y se listan por motivo): líneas vigentes, personas sin especialidad, líneas vacías que son justo las que le
 * corresponden por su especialidad, y personas que no tienen la línea que les corresponde con pagos.
 *
 * Cada póliza eliminada (con sus períodos) queda completa en `historial_cambios` (usuario 'limpiar_vacias') para poder
 * recuperarla. Los pagos no se tocan.
 *
 * Con --todas la regla es más amplia: se elimina CUALQUIER póliza vacía (sin pagos, de cualquier producto y estado, también las
 * que no tienen producto) de una persona que conserva al menos otra póliza CON pagos. No se elimina y se avisa (con una lista en
 * C:\xampp\respaldos_nominas\informes) cuando la persona:
 *   - solo tiene esa póliza y está vacía, o
 *   - tiene varias pólizas y todas están vacías (quedaría sin ninguna).
 * Tampoco se elimina una póliza vacía VIGENTE si la persona no conservaría ninguna otra vigente (se listan en OMITIDAS).
 *
 * Con --todas --dejar-una, a las personas que tienen varias pólizas y TODAS vacías se les deja UNA sola: la vigente (si hay
 * varias vigentes, la más reciente) y, si no tienen vigente, la más reciente por su período de póliza (sin período al final) y,
 * si empatan, por fecha de alta y luego por id. Las demás se eliminan. Las personas con una única póliza vacía no se tocan.
 *
 * Uso:
 *   php scripts/limpiar_lineas_vacias.php --informe [--todas [--dejar-una]]   calcula y muestra, pero DESHACE los cambios
 *   php scripts/limpiar_lineas_vacias.php --aplicar [--todas]   (hacer antes un respaldo con mysqldump)
 *   DB_NAME=unificar_nominas_prueba php scripts/limpiar_lineas_vacias.php --aplicar   (probar en una copia)
 */

require_once __DIR__ . '/../config/database.php';

$modo = $argv[1] ?? '';
$todas = in_array('--todas', $argv, true);
$dejarUna = $todas && in_array('--dejar-una', $argv, true);
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/limpiar_lineas_vacias.php --informe|--aplicar [--todas]\n");

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();

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

// ---- Líneas de cada persona: producto → pólizas, pagos y si hay alguna vigente ----
$lineas = [];
foreach ($pdo->query('SELECT z.id, z.rut, pr.nombre AS producto, z.estado, (SELECT COUNT(*) FROM pagos_mensuales p WHERE p.poliza_id = z.id) AS pagos
                      FROM polizas z JOIN productos pr ON pr.id = z.producto_id') as $z) {
    $l = &$lineas[(int) $z['rut']][$z['producto']];
    $l['ids'][] = (int) $z['id'];
    $l['pagos'] = ($l['pagos'] ?? 0) + (int) $z['pagos'];
    $l['vigente'] = ($l['vigente'] ?? false) || in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true);
    unset($l);
}
$personas = $pdo->query('SELECT a.rut, a.nombre, a.especialidad, ti.nombre AS otro_tipo FROM asegurados a LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id')
    ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$eliminar = []; // [rut, producto vacío, producto que le corresponde, ids]
$omitidas = [];
foreach ($lineas as $rut => $prods) {
    $conPagos = array_keys(array_filter($prods, fn($l) => $l['pagos'] > 0));
    if (!$conPagos) continue;
    foreach ($prods as $producto => $l) {
        if ($l['pagos'] > 0) continue; // línea con pagos: no es una línea vacía
        $per = $personas[$rut] ?? ['nombre' => '?', 'especialidad' => null, 'otro_tipo' => null];
        $omitir = function (string $motivo) use (&$omitidas, $rut, $per, $producto) { $omitidas[$motivo][] = "$rut {$per['nombre']} ($producto)"; };
        if ($l['vigente']) { $omitir('la línea vacía está VIGENTE (revisar a mano)'); continue; }
        $deseado = productoSegunEspecialidad($per['especialidad'], $per['otro_tipo']);
        if ($deseado === null) { $omitir('no tiene especialidad registrada'); continue; }
        if ($deseado === $producto) { $omitir('la línea vacía es la que le corresponde por su especialidad'); continue; }
        if (!in_array($deseado, $conPagos, true)) { $omitir('no tiene con pagos la línea que le corresponde por su especialidad'); continue; }
        $eliminar[] = [$rut, $producto, $deseado, $l['ids']];
    }
}

// ---- Con --todas: toda póliza vacía de una persona que conserva otra póliza con pagos ----
$unicas = $todasVacias = $dejadas = []; // personas que se avisan y no se tocan / a quienes se les dejó una
if ($todas) {
    $eliminar = [];
    $omitidas = [];
    $porPersona = [];
    foreach ($pdo->query('SELECT z.id, z.rut, z.numero, z.estado, z.fecha_alta, IFNULL(pr.nombre, \'(sin producto)\') AS producto,
                                 (SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id) AS hasta,
                                 (SELECT COUNT(*) FROM pagos_mensuales p WHERE p.poliza_id = z.id) AS pagos
                          FROM polizas z LEFT JOIN productos pr ON pr.id = z.producto_id ORDER BY z.rut, z.id') as $z) {
        $porPersona[(int) $z['rut']][] = $z;
    }
    foreach ($porPersona as $rut => $lista) {
        $vacias = array_values(array_filter($lista, fn($z) => (int) $z['pagos'] === 0));
        if (!$vacias) continue;
        $nombre = $personas[$rut]['nombre'] ?? '?';
        if (count($vacias) === count($lista) && count($lista) > 1 && $dejarUna) {
            // Todas vacías: se deja una (la vigente; si no, la de período más reciente) y las demás se eliminan
            usort($lista, fn($a, $b) => [in_array($b['estado'], ['VIGENTE', 'VAN A CORTE'], true) <=> in_array($a['estado'], ['VIGENTE', 'VAN A CORTE'], true), $b['hasta'] ?? '', $b['fecha_alta'] ?? '', $b['id']]
                                      <=> [0, $a['hasta'] ?? '', $a['fecha_alta'] ?? '', $a['id']]);
            $deja = array_shift($lista);
            $dejadas[] = "$rut $nombre → queda {$deja['producto']} " . ($deja['numero'] ?? 's/n') . " ({$deja['estado']})";
            foreach ($lista as $z) $eliminar[] = [$rut, $z['producto'] . ' ' . ($z['numero'] ?? 's/n') . ' (' . $z['estado'] . ')', 'una póliza que se dejó (todas estaban vacías)', [(int) $z['id']]];
            continue;
        }
        if (count($vacias) === count($lista)) { // ninguna póliza con pagos: no se puede borrar sin dejarla sin pólizas
            foreach ($vacias as $z) {
                $fila = [$rut, $nombre, $z['producto'], $z['numero'], $z['estado'], $z['fecha_alta']];
                if (count($lista) === 1) $unicas[] = $fila; else $todasVacias[] = $fila;
            }
            continue;
        }
        // Si la persona se quedaría sin ninguna póliza vigente, las vacías VIGENTE se conservan: serían el único registro de su vigencia
        $conservaVigente = (bool) array_filter($lista, fn($z) => (int) $z['pagos'] > 0 && in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true));
        foreach ($vacias as $z) {
            if (!$conservaVigente && in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true)) {
                $omitidas['póliza vacía VIGENTE y la persona no tiene otra vigente (se perdería su vigencia)'][] = "$rut $nombre ({$z['producto']} {$z['numero']})";
                continue;
            }
            $eliminar[] = [$rut, $z['producto'] . ' ' . ($z['numero'] ?? 's/n') . ' (' . $z['estado'] . ')', 'otra póliza con pagos', [(int) $z['id']]];
        }
    }
}

// ---- Eliminación (una sola transacción) ----
$huella = fn() => (string) $pdo->query("SELECT CONCAT(COUNT(*), '/', COALESCE(SUM(CRC32(CONCAT(poliza_id, ':', periodo, ':', pagado, ':', COALESCE(monto, '')))), 0)) FROM pagos_mensuales")->fetchColumn();
$cuenta = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$pagosAntes = $huella();
$polizasAntes = $cuenta('SELECT COUNT(*) FROM polizas');
$personasAntes = $cuenta('SELECT COUNT(*) FROM asegurados');
$borradas = 0;
$porPar = [];

$pdo->beginTransaction();
try {
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'eliminada_por_vacia', ?, ?, 'limpiar_vacias')");
    foreach ($eliminar as [$rut, $producto, $deseado, $ids]) {
        foreach ($ids as $id) {
            if ($cuenta("SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = $id") > 0) throw new RuntimeException("La póliza $id tiene pagos: no se elimina.");
            $fila = $pdo->query("SELECT * FROM polizas WHERE id = $id")->fetch();
            $periodos = $pdo->query("SELECT numero, vigencia_desde, vigencia_hasta, origen FROM poliza_periodos WHERE poliza_id = $id")->fetchAll();
            $log->execute([(string) $id, json_encode(['poliza' => $fila, 'periodos' => $periodos], JSON_UNESCAPED_UNICODE),
                $todas ? "sin pagos; la persona conserva otra póliza con pagos" : "$producto {$fila['numero']} sin pagos; la persona queda con la línea $deseado que le corresponde por su especialidad"]);
            $pdo->exec("DELETE FROM poliza_periodos WHERE poliza_id = $id");
            $pdo->exec("DELETE FROM polizas WHERE id = $id");
            $borradas++;
        }
        $par = $todas ? preg_replace('/ \S+ \(.*$/', '', $producto) . (str_contains($producto, 'VIGENTE)') && !str_contains($producto, 'NO VIGENTE)') ? ' (VIGENTE)' : '') : "$producto → queda $deseado";
        $porPar[$par] = ($porPar[$par] ?? 0) + 1;
    }
    $errores = [];
    if ($huella() !== $pagosAntes) $errores[] = 'cambiaron los pagos';
    if ($cuenta('SELECT COUNT(*) FROM polizas') !== $polizasAntes - $borradas) $errores[] = 'el total de pólizas no cuadra';
    if ($cuenta('SELECT COUNT(*) FROM asegurados') !== $personasAntes) $errores[] = 'cambió el número de personas';
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));
    $polizasDespues = $cuenta('SELECT COUNT(*) FROM polizas');
    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
echo 'Líneas vacías eliminadas: ' . n(count($eliminar)) . ' (' . n($borradas) . ' pólizas) | ' . json_encode($porPar, JSON_UNESCAPED_UNICODE) . "\n";
foreach ($omitidas as $motivo => $lista) {
    echo '  OMITIDAS (' . count($lista) . ") — $motivo:\n";
    foreach (array_slice($lista, 0, 5) as $x) echo "      $x\n";
    if (count($lista) > 5) echo '      … y ' . (count($lista) - 5) . " más\n";
}
if ($dejadas) {
    echo '  Personas con varias pólizas y todas vacías: se dejó UNA a ' . n(count($dejadas)) . ". Ejemplos:\n";
    foreach (array_slice($dejadas, 0, 5) as $x) echo "      $x\n";
}
if ($todas) {
    $carpeta = 'C:/xampp/respaldos_nominas/informes';
    if (!is_dir($carpeta)) mkdir($carpeta, 0777, true);
    foreach ([['unicas_vacias', $unicas, 'persona con UNA sola póliza y vacía'], ['todas_vacias', $todasVacias, 'persona con varias pólizas y todas vacías']] as [$archivo, $filas, $texto]) {
        $ruta = "$carpeta/polizas_{$archivo}_" . date('Ymd') . '.csv';
        $fh = fopen($ruta, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['rut', 'nombre', 'producto', 'numero', 'estado', 'fecha_alta'], ';');
        foreach ($filas as $f) fputcsv($fh, $f, ';');
        fclose($fh);
        echo '  NO SE BORRAN (' . n(count($filas)) . " pólizas, $texto): $ruta\n";
    }
}
echo 'Verificado: los pagos no cambiaron (' . $pagosAntes . '), pólizas ' . n($polizasAntes) . ' → ' . n($polizasDespues) . ', personas ' . n($personasAntes) . " (sin cambios). Las eliminadas quedaron archivadas en el historial.\n";
