<?php

/**
 * Corrige los nombres escritos al revés (APELLIDOS NOMBRES → NOMBRES APELLIDOS) de la lista revisada por el usuario:
 * el CSV de C:\xampp\respaldos_nominas\informes\nombres_al_reves_*.csv (columnas rut, nombre_actual, nombre_sugerido, evaluacion).
 * Solo se corrigen las filas evaluadas "al revés (seguro)" y "al revés (probable)"; las "probablemente está bien" no se tocan.
 * Cada cambio queda en `historial_cambios` (usuario 'corregir_nombres'). Solo cambia el nombre si todavía es el de la lista
 * (si alguien lo editó entretanto, se omite). Una sola transacción.
 *
 * Uso:
 *   php scripts/corregir_nombres_al_reves.php "<CSV>" --informe|--aplicar
 */

require_once __DIR__ . '/../config/database.php';

$csv = $argv[1] ?? '';
$modo = $argv[2] ?? '';
if (!is_file($csv) || !in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/corregir_nombres_al_reves.php \"<CSV>\" --informe|--aplicar\n");

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
$fh = fopen($csv, 'r');
$cab = fgetcsv($fh, 0, ';');
if ($cab && str_starts_with($cab[0], "\xEF\xBB\xBF")) $cab[0] = substr($cab[0], 3);
$filas = [];
while (($f = fgetcsv($fh, 0, ';')) !== false) if (count($f) === count($cab)) $filas[] = array_combine($cab, $f);
fclose($fh);

$pdo->beginTransaction();
try {
    $leer = $pdo->prepare('SELECT nombre FROM asegurados WHERE rut = ?');
    $upd = $pdo->prepare('UPDATE asegurados SET nombre = ? WHERE rut = ? AND nombre = ?');
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('asegurados', ?, 'nombre', ?, ?, 'corregir_nombres')");
    $hechos = $omitidos = $ignorados = 0;
    $lista = [];
    foreach ($filas as $f) {
        if (!str_starts_with($f['evaluacion'], 'al rev')) { $ignorados++; continue; }
        $rut = (int) $f['rut']; // "5009281-K" → 5009281
        $leer->execute([$rut]);
        $actual = $leer->fetchColumn();
        if ($actual !== $f['nombre_actual']) { $omitidos++; $lista[] = "OMITIDO $rut: el nombre ya no es \"{$f['nombre_actual']}\" (es \"$actual\")"; continue; }
        $upd->execute([$f['nombre_sugerido'], $rut, $actual]);
        if ($upd->rowCount() !== 1) throw new RuntimeException("No se pudo actualizar el RUT $rut");
        $log->execute([(string) $rut, $actual, $f['nombre_sugerido']]);
        $hechos++;
    }
    $personas = (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn();
    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}
echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (no se guardó nada) ===\n";
echo "Nombres corregidos: $hechos | omitidos: $omitidos | filas que no se tocan (probablemente están bien): $ignorados | personas en la base: $personas\n";
foreach ($lista as $l) echo "  $l\n";
