<?php

/**
 * POST api/importar.php   (multipart/form-data, campo "archivo")
 *
 * Recibe el Excel madre (.xlsx) y sincroniza la hoja "NOMINA ACT 2024" con la base de
 * datos; lo usa el botón "Actualizar nómina" del front.
 *
 * Flujo:  front (FormData) → este endpoint → App\Services\NominaSync::sincronizar() → MySQL
 *
 * Responde:
 *   200 { success, message, archivo, resumen }  resumen = contadores (asegurados/meses nuevos,
 *                                               modificados, sin cambios, conflictos…)
 *   400 archivo ausente, con error de subida o que no es .xlsx
 *   422 es un .xlsx pero con formato inesperado (falta la hoja, cambió el encabezado…)
 *   500 error interno (el detalle queda en el log del servidor)
 *
 * La lectura es pesada (~11 MB, 8.000 filas × 144 columnas): tarda cerca de medio minuto.
 */

require_once __DIR__ . '/_comun.php';
require_once __DIR__ . '/../src/Services/NominaSync.php';

use App\Services\NominaSync;

exigirMetodo('POST');

if (!isset($_FILES['archivo'])) {
    responder(400, ['success' => false, 'message' => 'No se recibió ningún archivo']);
}

$archivo = $_FILES['archivo'];

if ($archivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['success' => false, 'message' => 'Error al subir el archivo', 'codigo' => $archivo['error']]);
}

if (strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    responder(400, ['success' => false, 'message' => 'El archivo debe ser el Excel madre (.xlsx)']);
}

// La hoja madre es grande: la lectura necesita más memoria y tiempo que un request normal.
// Se sube el límite solo para este endpoint (el php.ini global queda intacto).
ini_set('memory_limit', '3G');
set_time_limit(0);

try {
    // $archivo['tmp_name'] es el archivo temporal donde PHP dejó la subida
    $resumen = (new NominaSync(conectarBD()))->sincronizar($archivo['tmp_name'], $archivo['name']);

    responder(200, [
        'success' => true,
        'message' => 'Base de datos actualizada',
        'archivo' => $archivo['name'],
        'resumen' => $resumen,
    ]);
} catch (RuntimeException $e) {
    // Archivo con formato inesperado (falta la hoja, cambió el encabezado, etc.):
    // es un problema del archivo, no del servidor, y el mensaje sirve para el usuario
    responder(422, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('importar.php: ' . $e);
    responder(500, ['success' => false, 'message' => 'No se pudo procesar el Excel']);
}
