<?php

/**
 * POST api/fila.php   (cuerpo JSON)   ← lo llama el script de Google Sheets
 *
 *   {
 *     "token": "clave secreta compartida",
 *     "encabezado": [ ...fila 1 de la hoja... ],
 *     "filas": [ { "fila": 25, "valores": [ ...celdas de esa fila... ] }, ... ]
 *   }
 *
 * Recibe las filas que cambiaron en la hoja de Google y las aplica a la base de datos con
 * las mismas reglas que la carga del Excel (ver App\Services\NominaSync::sincronizarFilas).
 *
 * Este es el único endpoint pensado para quedar expuesto a internet (a través de un túnel
 * o del servidor), por eso:
 *   - exige la clave secreta de config/hoja.php (se compara en tiempo constante);
 *   - si la clave no está configurada en el servidor, se rechaza todo (503);
 *   - limita la cantidad de filas por llamada.
 *
 * Importante para quien lo llama: enviar TODAS las filas de cada RUT (las re-afiliaciones
 * ocupan más de una fila).
 *
 * Responde: { success, resumen }  (400 datos inválidos, 401 clave incorrecta, 422 formato de
 * hoja inesperado, 503 sin configurar, 500 error interno).
 */

require_once __DIR__ . '/_comun.php';
require_once __DIR__ . '/../src/Services/NominaSync.php';

use App\Services\NominaSync;

exigirMetodo('POST');

const MAX_FILAS = 500;

$config = require __DIR__ . '/../config/hoja.php';
if ($config['token'] === '') {
    responder(503, ['success' => false, 'message' => 'La conexión con la hoja no está configurada']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    responder(400, ['success' => false, 'message' => 'Se esperaba un JSON']);
}

// hash_equals compara en tiempo constante: no filtra la clave por diferencias de tiempo
if (!hash_equals($config['token'], (string) ($in['token'] ?? ''))) {
    responder(401, ['success' => false, 'message' => 'Clave incorrecta']);
}

$encabezado = $in['encabezado'] ?? null;
$filas = $in['filas'] ?? null;
if (!is_array($encabezado) || !is_array($filas) || count($filas) === 0) {
    responder(400, ['success' => false, 'message' => 'Faltan el encabezado o las filas']);
}
if (count($filas) > MAX_FILAS) {
    responder(400, ['success' => false, 'message' => 'Demasiadas filas en una sola llamada (máximo ' . MAX_FILAS . ')']);
}
foreach ($filas as $f) {
    if (!is_array($f) || !isset($f['fila']) || !is_array($f['valores'] ?? null)) {
        responder(400, ['success' => false, 'message' => 'Cada fila debe traer "fila" y "valores"']);
    }
}

try {
    $resumen = (new NominaSync(conectarBD()))->sincronizarFilas($encabezado, $filas);
    responder(200, ['success' => true, 'resumen' => $resumen]);
} catch (RuntimeException $e) {
    // La hoja no tiene el formato esperado: es un problema de la hoja, y el mensaje sirve para corregirlo
    responder(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('fila.php: ' . $e);
    responder(500, ['success' => false, 'message' => 'No se pudo procesar el aviso de la hoja']);
}
