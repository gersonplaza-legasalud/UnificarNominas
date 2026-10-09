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

/** Producto de las pólizas que vienen del Excel madre (y de la hoja de Google). */
const PRODUCTO_MADRE = 'Odontólogo';

/**
 * Clave con la que `historial_cambios` identifica un mes de una póliza.
 * Las pólizas del Excel madre conservan "rut|AAAA-MM-01" (igual que la sincronización); las
 * demás agregan el id de póliza, porque una misma persona puede tener dos pólizas con el mismo mes.
 */
function claveMes(int $rut, string $fecha, int $polizaId, bool $esMadre): string
{
    return $esMadre ? "$rut|$fecha" : "$rut|$fecha|p$polizaId";
}

/**
 * Código que acompaña a la póliza en el enlace del QR del certificado: los primeros 20
 * caracteres del HMAC-SHA256 del id de póliza con la clave de config/verificacion.php. No se
 * puede calcular sin la clave, así que el enlace de una póliza no sirve para consultar otra.
 */
function codigoVerificacion(int $polizaId): string
{
    $clave = (require __DIR__ . '/../config/verificacion.php')['clave'];
    return substr(hash_hmac('sha256', 'poliza:' . $polizaId, $clave), 0, 20);
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

/**
 * Producto que debe cubrir a una persona según su especialidad y su "otro tipo" (regla acordada con el usuario):
 *   cirujano dentista de tipo DERMO (el tipo SOEMAF se unió a DERMO) → póliza SOEMAF; dentista "puro" → Odontólogo;
 *   solo dermatología → Derma; cualquier otra especialidad → Individual.
 * Sin especialidad no se puede decidir (null).
 */
function productoSegunEspecialidad(?string $especialidad, ?string $otroTipo): ?string
{
    $e = mb_strtoupper(trim((string) $especialidad));
    $t = mb_strtoupper(trim((string) $otroTipo));
    $dentista = str_contains($e, 'DENTISTA') || str_contains($e, 'ODONT');
    if ($dentista && (str_contains($e, 'SOEMAF') || $t === 'DERMO')) return 'SOEMAF';
    if ($dentista) return 'Odontólogo';
    if (str_contains($e, 'DERMAT')) return 'Derma';
    return $e !== '' ? 'Individual' : null;
}

/**
 * Cobertura vigente de una póliza. La cobertura dura 12 meses desde su inicio y, mientras la persona siga vigente
 * (no renuncia ni rechaza el pago), se renueva sola cada año. Es distinta del período de la póliza matriz (18 meses).
 *
 * @param ?string $inicio  inicio de alguna cobertura de la persona (p. ej. el de su contrato), 'AAAA-MM-DD'
 * @param string  $estado  VIGENTE / NO VIGENTE; solo las vigentes se renuevan
 * @return ?array{desde:string, hasta:string, renovada:bool}  null si no hay inicio registrado
 */
function coberturaActual(?string $inicio, string $estado, ?string $hoy = null): ?array
{
    if ($inicio === null || $inicio === '') return null;
    $hoy ??= date('Y-m-d');
    $desde = new DateTimeImmutable($inicio);
    $renovada = false;
    if ($estado === 'VIGENTE') {
        // Se avanza un año a la vez hasta la cobertura que contiene a hoy
        while ($desde->modify('+1 year')->format('Y-m-d') <= $hoy) {
            $desde = $desde->modify('+1 year');
            $renovada = true;
        }
    }
    return ['desde' => $desde->format('Y-m-d'), 'hasta' => $desde->modify('+1 year')->format('Y-m-d'), 'renovada' => $renovada];
}
