<?php

/**
 * Aplica a TODAS las pólizas VIGENTE la regla de vigencia vigente (ver App\Services\Vigencia):
 *   - 2 o más meses seguidos sin pago (hasta el último mes completo)  → NO VIGENTE (marcada `estado_manual`, para que la
 *                                                                        sincronización con el Excel no la revierta) y sin aviso de corte
 *   - exactamente 1 mes sin pago                                      → sigue VIGENTE y se enciende el aviso `va_a_corte`
 * Los meses se cuentan sobre todas las pólizas del mismo producto de la persona. No se evalúan las pólizas sin producto ni
 * las que no tienen ningún pago. Cada cambio queda en `historial_cambios` (usuario 'regla_2_meses'). No se escribe en la
 * hoja de Google. Una sola transacción, con verificación antes de confirmar.
 *
 * Uso:
 *   php scripts/aplicar_regla_dos_meses.php --informe   calcula y muestra, pero DESHACE los cambios
 *   php scripts/aplicar_regla_dos_meses.php --aplicar   (hacer antes un respaldo con mysqldump)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/Vigencia.php';

use App\Services\Vigencia;

ini_set('memory_limit', '2G');
$modo = $argv[1] ?? '';
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/aplicar_regla_dos_meses.php --informe|--aplicar\n");

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
function n(int $v): string { return number_format($v, 0, ',', '.'); }

$pagos = [];
foreach ($pdo->query("SELECT z.rut, z.producto_id, DATE_FORMAT(p.periodo, '%Y-%m') per, MAX(p.pagado) pg
                      FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE z.producto_id IS NOT NULL
                      GROUP BY z.rut, z.producto_id, per") as $r) {
    $pagos[$r['rut'] . '|' . $r['producto_id']][$r['per']] = (bool) $r['pg'];
}

$antesPagos = (string) $pdo->query("SELECT CONCAT(COUNT(*), '/', COALESCE(SUM(CRC32(CONCAT(poliza_id, ':', periodo, ':', pagado, ':', COALESCE(monto, '')))), 0)) FROM pagos_mensuales")->fetchColumn();
$antesPolizas = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn();

$pdo->beginTransaction();
try {
    $aNoVigente = $aAviso = [];
    $sinEvaluar = ['sin_producto' => 0, 'sin_pagos' => 0];
    $q = $pdo->query("SELECT z.id, z.rut, z.producto_id, z.va_a_corte, MIN(z2.fecha_alta) alta
                      FROM polizas z JOIN polizas z2 ON z2.rut = z.rut AND z2.producto_id <=> z.producto_id
                      WHERE z.estado = 'VIGENTE' GROUP BY z.id");
    $vigentes = 0;
    foreach ($q as $z) {
        $vigentes++;
        if ($z['producto_id'] === null) { $sinEvaluar['sin_producto']++; continue; }
        $meses = $pagos[$z['rut'] . '|' . $z['producto_id']] ?? [];
        if (!$meses) { $sinEvaluar['sin_pagos']++; continue; }
        $racha = Vigencia::mesesSinPago($meses, $z['alta']);
        if ($racha['consecutivos'] >= Vigencia::UMBRAL_MESES) $aNoVigente[] = [(int) $z['id'], (int) $z['va_a_corte'], $racha['consecutivos']];
        elseif ($racha['consecutivos'] >= Vigencia::AVISO_MESES && !(int) $z['va_a_corte']) $aAviso[] = (int) $z['id'];
    }

    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, ?, ?, ?, 'regla_2_meses')");
    $baja = $pdo->prepare("UPDATE polizas SET estado = 'NO VIGENTE', va_a_corte = 0, estado_manual = 1, estado_calculado_at = NOW() WHERE id = ? AND estado = 'VIGENTE'");
    $avisa = $pdo->prepare("UPDATE polizas SET va_a_corte = 1 WHERE id = ? AND estado = 'VIGENTE' AND va_a_corte = 0");
    $dist = [];
    foreach ($aNoVigente as [$id, $teniaAviso, $meses]) {
        $baja->execute([$id]);
        $log->execute([(string) $id, 'estado', 'VIGENTE', 'NO VIGENTE']);
        if ($teniaAviso) $log->execute([(string) $id, 'va_a_corte', '1', '0']);
        $k = $meses >= 6 ? '6 o más' : (string) $meses;
        $dist[$k] = ($dist[$k] ?? 0) + 1;
    }
    foreach ($aAviso as $id) {
        $avisa->execute([$id]);
        $log->execute([(string) $id, 'va_a_corte', '0', '1']);
    }

    // ---- verificación ----
    $errores = [];
    $despuesPagos = (string) $pdo->query("SELECT CONCAT(COUNT(*), '/', COALESCE(SUM(CRC32(CONCAT(poliza_id, ':', periodo, ':', pagado, ':', COALESCE(monto, '')))), 0)) FROM pagos_mensuales")->fetchColumn();
    if ($despuesPagos !== $antesPagos) $errores[] = 'cambiaron los pagos';
    if ((int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn() !== $antesPolizas) $errores[] = 'cambió la cantidad de pólizas';
    $vigentesDespues = (int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE estado = 'VIGENTE'")->fetchColumn();
    if ($vigentesDespues !== $vigentes - count($aNoVigente)) $errores[] = "VIGENTE esperadas " . ($vigentes - count($aNoVigente)) . ", hay $vigentesDespues";
    if ((int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE estado = 'NO VIGENTE' AND va_a_corte = 1")->fetchColumn() > 0) $errores[] = 'hay NO VIGENTE con aviso de corte';
    if ($errores) throw new RuntimeException('La verificación falló: ' . implode('; ', $errores));

    $resumen = $pdo->query("SELECT estado, va_a_corte, COUNT(*) n FROM polizas GROUP BY estado, va_a_corte ORDER BY estado, va_a_corte")->fetchAll();
    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
echo 'Pólizas VIGENTE evaluadas: ' . n($vigentes) . ' (sin producto: ' . n($sinEvaluar['sin_producto']) . ', sin pagos: ' . n($sinEvaluar['sin_pagos']) . ")\n";
echo 'Pasan a NO VIGENTE (2 o más meses seguidos sin pago): ' . n(count($aNoVigente)) . ' ' . json_encode($dist, JSON_UNESCAPED_UNICODE) . "\n";
echo 'Quedan VIGENTE con aviso de corte (1 mes sin pago): ' . n(count($aAviso)) . "\n";
echo "Estado final de las pólizas:\n";
foreach ($resumen as $r) echo '   ' . str_pad($r['estado'] . ($r['va_a_corte'] ? ' + aviso de corte' : ''), 28) . n((int) $r['n']) . "\n";
echo 'Verificado: los pagos (' . $antesPagos . ") y la cantidad de pólizas no cambiaron.\n";
