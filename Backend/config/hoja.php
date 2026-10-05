<?php

/**
 * Conexión con la hoja de Google (Apps Script).
 *
 * Devuelve:
 *   url    dirección de la "aplicación web" del script (termina en /exec). Es la que usa la
 *          app para pedirle al script que escriba en la hoja.
 *   token  clave secreta compartida. La hoja la envía en cada aviso a la API, y la app la
 *          envía en cada pedido al script; ambos lados rechazan lo que no la traiga.
 *
 * Los valores reales NO van en este archivo: se ponen en config/hoja.local.php (ignorado por
 * git) o en las variables de entorno HOJA_URL y HOJA_TOKEN. Si `url` queda vacía, la escritura
 * hacia la hoja está desactivada y la app funciona como hasta ahora.
 *
 * Ejemplo de config/hoja.local.php:
 *   <?php return ['url' => 'https://script.google.com/macros/s/XXXX/exec', 'token' => 'clave-larga-y-secreta'];
 */

$local = __DIR__ . '/hoja.local.php';
$valores = is_file($local) ? require $local : [];

return [
    'url' => $valores['url'] ?? (getenv('HOJA_URL') ?: ''),
    'token' => $valores['token'] ?? (getenv('HOJA_TOKEN') ?: ''),
];
