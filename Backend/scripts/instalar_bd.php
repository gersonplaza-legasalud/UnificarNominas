<?php

/**
 * Instalador de la base de datos (script de consola).
 *
 * Uso:  php scripts/instalar_bd.php
 *
 * Crea la base `unificar_nominas` ejecutando database/schema.sql.
 * ATENCIÓN: el esquema borra y recrea todas las tablas, así que se pierden los datos
 * existentes. Después hay que volver a cargar la nómina con sincronizar_nomina.php
 * (o desde el botón "Actualizar nómina" del front).
 */

require_once __DIR__ . '/../config/database.php';

// Se conecta sin elegir base porque el propio schema.sql la crea
$pdo = conectarBD(false);
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));

echo "Base de datos creada.\n";
