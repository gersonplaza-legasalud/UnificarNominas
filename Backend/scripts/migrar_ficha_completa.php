<?php

/**
 * Aplica la migración 003 (ficha completa: sociedades, datos de contacto, períodos de póliza)
 * y verifica que no se perdió ningún dato.
 *
 * Uso:  php scripts/migrar_ficha_completa.php
 *       DB_NAME=unificar_nominas_prueba php scripts/migrar_ficha_completa.php   (probar en una copia)
 *
 * Requiere la 001 y la 002 aplicadas. Se detiene si la base ya tiene la tabla `sociedades`.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();

if ($pdo->query("SHOW TABLES LIKE 'sociedades'")->fetchColumn()) {
    exit("La base `$base` ya tiene la migración 003 aplicada.\n");
}
if (!$pdo->query("SHOW TABLES LIKE 'polizas'")->fetchColumn() || $pdo->query("SHOW COLUMNS FROM pagos_mensuales LIKE 'rut'")->fetchColumn()) {
    exit("La base `$base` no tiene aplicadas las migraciones 001 y 002.\n");
}

/** Cifras que no deben cambiar con esta migración. */
function cifras(PDO $pdo): array
{
    $pdo->exec('SET SESSION group_concat_max_len = 1073741824');
    return [
        'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
        'pólizas' => (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn(),
        'pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
        'pagos pagados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE pagado = 1')->fetchColumn(),
        'suma montos' => (int) $pdo->query('SELECT COALESCE(SUM(monto), 0) FROM pagos_mensuales')->fetchColumn(),
        'estados' => json_encode($pdo->query('SELECT estado, COUNT(*) FROM polizas GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR)),
        'huella pagos' => $pdo->query('SELECT MD5(GROUP_CONCAT(CONCAT(poliza_id, ":", periodo, ":", pagado, ":", COALESCE(monto, "")) ORDER BY poliza_id, periodo SEPARATOR "|")) FROM pagos_mensuales')->fetchColumn(),
        'huella personas' => $pdo->query('SELECT MD5(GROUP_CONCAT(CONCAT(rut, "|", nombre, "|", COALESCE(mail, ""), "|", COALESCE(telefono, ""), "|", COALESCE(especialidad, "")) ORDER BY rut SEPARATOR "#")) FROM asegurados')->fetchColumn(),
        'huella pólizas' => $pdo->query('SELECT MD5(GROUP_CONCAT(CONCAT(id, "|", rut, "|", COALESCE(numero, ""), "|", COALESCE(producto_id, ""), "|", estado, "|", COALESCE(fecha_alta, "")) ORDER BY id SEPARATOR "#")) FROM polizas')->fetchColumn(),
    ];
}

$antes = cifras($pdo);
echo "Aplicando la migración 003 en la base `$base`…\n";
$pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/003_ficha_completa.sql'));
$despues = cifras($pdo);

$errores = [];
foreach ($antes as $nombre => $valor) {
    $ok = $valor === $despues[$nombre];
    echo sprintf("  %-16s %s\n", $nombre, $ok ? 'OK (' . substr((string) $valor, 0, 36) . ')' : "DIFERENTE: antes $valor, después {$despues[$nombre]}");
    if (!$ok) $errores[] = $nombre;
}

// La estructura nueva debe existir y lo movido no debe quedar duplicado
$checks = [
    'tabla sociedades' => (bool) $pdo->query("SHOW TABLES LIKE 'sociedades'")->fetchColumn(),
    'tabla poliza_periodos' => (bool) $pdo->query("SHOW TABLES LIKE 'poliza_periodos'")->fetchColumn(),
    'asegurados.sociedad_id' => (bool) $pdo->query("SHOW COLUMNS FROM asegurados LIKE 'sociedad_id'")->fetchColumn(),
    'asegurados.tipo_individuo_id' => (bool) $pdo->query("SHOW COLUMNS FROM asegurados LIKE 'tipo_individuo_id'")->fetchColumn(),
    'polizas.tipo_individuo_id quitada' => !$pdo->query("SHOW COLUMNS FROM polizas LIKE 'tipo_individuo_id'")->fetchColumn(),
    'polizas.fecha_renuncia' => (bool) $pdo->query("SHOW COLUMNS FROM polizas LIKE 'fecha_renuncia'")->fetchColumn(),
    'productos (Odontólogo, Individual, SOEMAF, Derma)' => (int) $pdo->query("SELECT COUNT(*) FROM productos WHERE nombre IN ('Odontólogo','Individual','SOEMAF','Derma')")->fetchColumn() === 4,
];
foreach ($checks as $nombre => $ok) {
    echo sprintf("  %-16s %s\n", '', ($ok ? 'OK    ' : 'FALTA ') . $nombre);
    if (!$ok) $errores[] = $nombre;
}

// El CHECK de períodos debe rechazar un término anterior al inicio (se prueba y se deshace)
$pdo->beginTransaction();
try {
    $id = (int) $pdo->query('SELECT id FROM polizas LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, vigencia_desde, vigencia_hasta) VALUES (?, '2026-06-01', '2026-01-01')")->execute([$id]);
    echo "  FALTA  el CHECK de fechas de los períodos no rechazó un término anterior al inicio\n";
    $errores[] = 'check de períodos';
} catch (PDOException) {
    echo "  OK     el CHECK de fechas de los períodos rechaza un término anterior al inicio\n";
}
$pdo->rollBack();

if ($errores) {
    echo 'MIGRACIÓN CON DIFERENCIAS: ' . implode(', ', $errores) . " (restaura el respaldo)\n";
    exit(1);
}
echo "Migración 003 correcta: no se perdió ningún dato.\n";
