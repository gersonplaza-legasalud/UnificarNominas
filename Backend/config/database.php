<?php

/**
 * Conexión a MySQL (MariaDB de XAMPP).
 *
 * Todo el backend (API y scripts de consola) obtiene su conexión desde aquí.
 * Los valores por defecto sirven para XAMPP local (root sin contraseña); en otro
 * entorno se pueden sobrescribir con las variables de entorno DB_HOST, DB_USER,
 * DB_PASS y DB_NAME, sin tocar el código.
 *
 * @param bool $conBase true = conecta a la base `unificar_nominas`;
 *                      false = conecta solo al servidor (lo usa instalar_bd.php,
 *                      que necesita crear la base antes de poder usarla).
 */
function conectarBD(bool $conBase = true): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $base = getenv('DB_NAME') ?: 'unificar_nominas';

    $dsn = "mysql:host=$host;charset=utf8mb4" . ($conBase ? ";dbname=$base" : '');

    return new PDO($dsn, $user, $pass, [
        // Los errores de SQL lanzan excepciones en vez de fallar en silencio
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Cada fila se devuelve como arreglo asociativo ['columna' => valor]
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_LOCAL_INFILE => false,
    ]);
}
