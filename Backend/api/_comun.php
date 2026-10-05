<?php

/**
 * Utilidades compartidas por todos los endpoints de api/.
 *
 * Cada endpoint hace `require_once __DIR__ . '/_comun.php'` y con eso obtiene:
 *   - el autoload de Composer (PhpSpreadsheet) y la función conectarBD();
 *   - las cabeceras de respuesta JSON y CORS;
 *   - responder(), exigirMetodo(), formatearRut() y conManejoDeErrores().
 *
 * Flujo general de cualquier petición:
 *   front (fetch) → endpoint → exigirMetodo() → validar datos → consultar MySQL → responder(JSON)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// CORS: en desarrollo el front corre en otro origen (Vite, puerto 5173) que la API (Apache),
// y el navegador solo permite la comunicación si la API lo autoriza explícitamente.
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/**
 * Envía la respuesta JSON y termina el script.
 * Todas las respuestas llevan `success` (true/false); en errores, un `message` legible.
 */
function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Deja pasar solo el método HTTP esperado.
 * Antes de un POST con JSON el navegador manda una consulta de "permiso" (OPTIONS,
 * llamada preflight de CORS): se responde 200 vacío y se termina. Cualquier otro método
 * distinto del esperado recibe 405.
 */
function exigirMetodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        responder(405, ['success' => false, 'message' => 'Método no permitido']);
    }
}

/** RUT con puntos y guion para mostrar: (7779582, '0') → "7.779.582-0". */
function formatearRut(int|string $rut, string $dv): string
{
    return number_format((int) $rut, 0, '', '.') . '-' . $dv;
}

/**
 * Ejecuta $accion y convierte cualquier error inesperado (base caída, SQL fallido…)
 * en una respuesta 500 genérica. El detalle técnico va al log del servidor y no se
 * envía al navegador, para no filtrar información interna.
 */
function conManejoDeErrores(callable $accion): void
{
    try {
        $accion();
    } catch (Throwable $e) {
        error_log(basename($_SERVER['SCRIPT_NAME']) . ': ' . $e);
        responder(500, ['success' => false, 'message' => 'Error interno del servidor']);
    }
}
