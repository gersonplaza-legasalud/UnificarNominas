<?php

/**
 * Reintenta enviar a la hoja de Google los cambios que quedaron pendientes.
 *
 * Uso:  php scripts/procesar_cola.php
 *
 * Normalmente los cambios se envían en el momento en que se editan en la app. Este script
 * recoge los que no pudieron entregarse (sin internet, script de Google caído…).
 * Conviene programarlo cada minuto con el Programador de tareas de Windows:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\UnificarNominas\Backend\scripts\procesar_cola.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/HojaGoogle.php';

use App\Services\HojaGoogle;

$hoja = new HojaGoogle(conectarBD(), require __DIR__ . '/../config/hoja.php');

if (!$hoja->activa()) {
    fwrite(STDERR, "La conexión con la hoja no está configurada (config/hoja.local.php).\n");
    exit(1);
}

echo json_encode($hoja->procesarPendientes(200)) . "\n";
