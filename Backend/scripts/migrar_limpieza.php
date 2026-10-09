<?php

/**
 * Aplica la migración 002 (quitar las columnas viejas de `asegurados` y `pagos_mensuales`)
 * y verifica que no se perdió ningún dato.
 *
 * Uso:  php scripts/migrar_limpieza.php
 *       DB_NAME=unificar_nominas_prueba php scripts/migrar_limpieza.php   (probar en una copia)
 *
 * Requiere que la 001 ya esté aplicada. Se detiene si `pagos_mensuales` ya no tiene la columna `rut`.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();

if (!$pdo->query("SHOW TABLES LIKE 'polizas'")->fetchColumn()) {
    exit("La base `$base` no tiene la tabla `polizas`: aplica antes scripts/migrar_polizas.php.\n");
}
if (!$pdo->query("SHOW COLUMNS FROM pagos_mensuales LIKE 'rut'")->fetchColumn()) {
    exit("La base `$base` ya tiene aplicada la limpieza.\n");
}

/** Cifras que no deben cambiar con la limpieza. */
function cifras(PDO $pdo): array
{
    return [
        'personas' => (int) $pdo->query('SELECT COUNT(*) FROM asegurados')->fetchColumn(),
        'pólizas' => (int) $pdo->query('SELECT COUNT(*) FROM polizas')->fetchColumn(),
        'pagos' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn(),
        'pagos pagados' => (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales WHERE pagado = 1')->fetchColumn(),
        'suma montos' => (int) $pdo->query('SELECT COALESCE(SUM(monto), 0) FROM pagos_mensuales')->fetchColumn(),
        'estados' => json_encode($pdo->query('SELECT estado, COUNT(*) FROM polizas GROUP BY estado ORDER BY estado')->fetchAll(PDO::FETCH_KEY_PAIR)),
        // Pagos de cada persona (ligados por la póliza): detecta pagos que cambiaran de dueño
        'huella pagos' => $pdo->query('SELECT MD5(GROUP_CONCAT(CONCAT(z.rut, ":", p.periodo, ":", p.pagado) ORDER BY z.rut, p.periodo SEPARATOR "|"))
                                       FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id')->fetchColumn(),
    ];
}

$pdo->exec('SET SESSION group_concat_max_len = 1073741824');
$antes = cifras($pdo);

echo "Aplicando la limpieza en la base `$base`…\n";
$pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/002_limpieza_asegurados.sql'));

$despues = cifras($pdo);
$errores = [];
foreach ($antes as $nombre => $valor) {
    $ok = $valor === $despues[$nombre];
    echo sprintf("  %-14s %s\n", $nombre, $ok ? 'OK (' . substr((string) $valor, 0, 40) . ')' : "DIFERENTE: antes $valor, después {$despues[$nombre]}");
    if (!$ok) $errores[] = $nombre;
}

if ($errores) {
    echo 'LIMPIEZA CON DIFERENCIAS: ' . implode(', ', $errores) . " (restaura el respaldo)\n";
    exit(1);
}
echo "Limpieza correcta: no se perdió ningún dato.\n";
