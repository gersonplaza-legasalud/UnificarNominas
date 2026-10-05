<?php

/**
 * Sincronización del Excel madre desde la consola.
 *
 * Uso:  php scripts/sincronizar_nomina.php "ruta\NOMINA PAGOS DENTISTAS.xlsx"
 *
 * Hace lo mismo que el botón "Actualizar nómina" del front (api/importar.php), pero
 * sin pasar por el navegador: sirve para la carga inicial y para pruebas.
 * Es repetible y no borra datos (ver App\Services\NominaSync).
 * Imprime el resumen de lo que cambió en formato JSON.
 */

// La hoja madre es grande: leerla completa necesita más memoria que el valor por defecto
ini_set('memory_limit', '3G');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/NominaSync.php';

use App\Services\NominaSync;

$archivo = $argv[1] ?? null;
if (!$archivo || !is_file($archivo)) {
    fwrite(STDERR, "Indica la ruta del Excel madre.\n");
    exit(1);
}

$resumen = (new NominaSync(conectarBD()))->sincronizar($archivo, basename($archivo));

echo json_encode($resumen, JSON_PRETTY_PRINT) . "\n";
