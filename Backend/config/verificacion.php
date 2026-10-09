<?php

/**
 * Clave con la que se firman los enlaces de verificación de los certificados (el QR).
 *
 * El QR lleva el RUT y un código `k` = HMAC(RUT, clave). Solo quien tiene el certificado puede
 * consultar el estado: cambiar el RUT en el enlace invalida el código, así nadie puede recorrer
 * RUTs ajenos. Si cambias la clave, los QR ya impresos dejan de funcionar.
 *
 * La clave real va en config/verificacion.local.php (ignorado por git) o en la variable de
 * entorno VERIFICACION_CLAVE. Si no existe ninguna, se genera una al azar y se guarda en
 * verificacion.local.php la primera vez que se necesita.
 *
 * Ejemplo de config/verificacion.local.php:
 *   <?php return ['clave' => 'texto-largo-y-secreto'];
 */

$local = __DIR__ . '/verificacion.local.php';
$valores = is_file($local) ? require $local : [];
$clave = $valores['clave'] ?? (getenv('VERIFICACION_CLAVE') ?: '');

if ($clave === '') {
    $clave = bin2hex(random_bytes(32));
    @file_put_contents($local, "<?php return ['clave' => '$clave'];\n");
}

return ['clave' => $clave];
