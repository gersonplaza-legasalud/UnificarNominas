<?php

/**
 * Aplica la migración 001 (separar persona y póliza) y verifica que no se perdió nada.
 *
 * Uso:  php scripts/migrar_polizas.php
 *       DB_NAME=unificar_nominas_prueba php scripts/migrar_polizas.php   (probar en una copia)
 *
 * Se detiene si la base ya tiene la tabla `polizas`. Después de migrar compara, contra los
 * datos de antes: personas, pagos, pagos pagados y estados. Cualquier diferencia se informa
 * y el script termina con código 1.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();

if ($pdo->query("SHOW TABLES LIKE 'polizas'")->fetchColumn()) {
    exit("La base `$base` ya tiene la tabla `polizas`: la migración ya se aplicó.\n");
}

/** Cifras de control tomadas antes de migrar, para compararlas después. */
$antes = [
    'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
    'pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
    'pagos pagados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE pagado = 1')->fetchColumn(),
    'pagos editados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE editado = 1')->fetchColumn(),
    'suma montos' => (int) $pdo->query('SELECT COALESCE(SUM(monto), 0) FROM pagos_mensuales')->fetchColumn(),
];
$estadosAntes = $pdo->query('SELECT estado, COUNT(*) FROM asegurados GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

echo "Migrando la base `$base`…\n";
$pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/001_polizas.sql'));

$despues = [
    'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
    'pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
    'pagos pagados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE pagado = 1')->fetchColumn(),
    'pagos editados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE editado = 1')->fetchColumn(),
    'suma montos' => (int) $pdo->query('SELECT COALESCE(SUM(monto), 0) FROM pagos_mensuales')->fetchColumn(),
];
$estadosDespues = $pdo->query('SELECT estado, COUNT(*) FROM polizas GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);

$errores = [];
foreach ($antes as $nombre => $valor) {
    $ok = $valor === $despues[$nombre];
    echo sprintf("  %-15s antes %-9s después %-9s %s\n", $nombre, $valor, $despues[$nombre], $ok ? 'OK' : 'DIFERENTE');
    if (!$ok) $errores[] = $nombre;
}

// Una póliza por persona, y cada una con los mismos datos que tenía en asegurados
$polizas = (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn();
echo sprintf("  %-15s esperadas %-6s creadas %-9s %s\n", 'pólizas', $antes['personas'], $polizas, $polizas === $antes['personas'] ? 'OK' : 'DIFERENTE');
if ($polizas !== $antes['personas']) $errores[] = 'pólizas';

$sinPoliza = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id IS NULL')->fetchColumn();
echo sprintf("  %-15s %s\n", 'pagos sin póliza', $sinPoliza === 0 ? '0 OK' : "$sinPoliza DIFERENTE");
if ($sinPoliza) $errores[] = 'pagos sin póliza';

// Cada pago debe seguir ligado a la misma persona a través de su póliza
$descuadrados = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE z.rut <> p.rut')->fetchColumn();
echo sprintf("  %-15s %s\n", 'pagos en póliza ajena', $descuadrados === 0 ? '0 OK' : "$descuadrados DIFERENTE");
if ($descuadrados) $errores[] = 'pagos en póliza ajena';

// Los datos de la póliza deben ser idénticos a los de asegurados (se comparan con <=> para tratar NULL = NULL)
$distintos = (int) $pdo->query(
    'SELECT COUNT(*) FROM asegurados a JOIN polizas z ON z.rut = a.rut
     WHERE NOT (a.poliza <=> z.numero AND a.cobertura_id <=> z.cobertura_id AND a.medio_pago_id <=> z.medio_pago_id
            AND a.rut_pagador <=> z.rut_pagador AND a.fecha_alta <=> z.fecha_alta AND a.fecha_baja <=> z.fecha_baja
            AND a.motivo_baja_id <=> z.motivo_baja_id AND a.estado = z.estado AND a.estado_manual = z.estado_manual)'
)->fetchColumn();
echo sprintf("  %-15s %s\n", 'datos distintos', $distintos === 0 ? '0 OK' : "$distintos DIFERENTE");
if ($distintos) $errores[] = 'datos distintos';

echo '  estados antes:   ' . json_encode($estadosAntes, JSON_UNESCAPED_UNICODE) . "\n";
echo '  estados después: ' . json_encode($estadosDespues, JSON_UNESCAPED_UNICODE) . "\n";
if ($estadosAntes !== $estadosDespues) $errores[] = 'estados';

if ($errores) {
    echo 'MIGRACIÓN CON DIFERENCIAS: ' . implode(', ', $errores) . "\n";
    exit(1);
}
echo "Migración correcta: no se perdió ningún dato.\n";
