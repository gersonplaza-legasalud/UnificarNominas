<?php

/**
 * POST api/pago.php   (cuerpo JSON)
 *
 *   { "rut": 7779582, "periodo": "2026-09", "pagado": true, "monto": 33700, "nota": "texto" }
 *
 * Edita (o crea) el pago de UN mes de una persona; lo usa el diálogo "Editar mes" del front.
 *   - `monto` y `nota` son opcionales (null o vacío = sin dato).
 *   - Si el mes ya existía lo actualiza; si no, lo crea.
 *   - Lo marca como editado a mano (`editado = 1`, `origen = 'manual'`), y por eso la
 *     sincronización con el Excel madre ya no lo sobrescribe.
 *   - Cada campo que cambia se registra en `historial_cambios` (valor anterior y nuevo).
 *   - Si los datos enviados son idénticos a los guardados responde "Sin cambios" y no escribe nada.
 *   - Pérdida de cobertura: si el mes quedó impago y con eso la persona acumula 3 meses seguidos
 *     sin pago (ver App\Services\Vigencia), pasa de VIGENTE / VAN A CORTE a NO VIGENTE. El cambio
 *     se marca (`estado_manual = 1`) para que la próxima sincronización con el Excel no lo
 *     revierta, y se registra en `historial_cambios`. Solo baja de estado: marcar un mes como
 *     pagado nunca vuelve a dejar vigente a nadie.
 *
 *   - Hoja de Google: si la conexión está configurada (config/hoja.php), el cambio también se
 *     escribe en la celda de la hoja (y la columna ESTADO si la persona pasó a NO VIGENTE) a
 *     través de una cola con reintentos (App\Services\HojaGoogle). Si la hoja no responde, la
 *     edición igual queda guardada en la base y el envío se reintenta solo.
 *
 * Responde: { success, message, pago: {...}, estado: {cambio, anterior, nuevo}, hoja }
 *   pago   = el mes tal como quedó guardado en la base
 *   estado = qué pasó con la vigencia de la persona (cambio = true si acaba de pasar a NO VIGENTE)
 *   hoja   = 'desactivada' | 'enviado' | 'pendiente' (en cola, se reintentará) | 'fallido' (rechazado)
 */

require_once __DIR__ . '/_comun.php';
require_once __DIR__ . '/../src/Services/Vigencia.php';
require_once __DIR__ . '/../src/Services/HojaGoogle.php';

use App\Services\HojaGoogle;
use App\Services\Vigencia;

exigirMetodo('POST');

// ---- Validación de la entrada (nada de lo que llega del navegador se asume correcto) ----

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    responder(400, ['success' => false, 'message' => 'Se esperaba un JSON']);
}

$rut = filter_var($in['rut'] ?? null, FILTER_VALIDATE_INT);
$periodo = (string) ($in['periodo'] ?? '');
if (!$rut || $rut <= 0 || !preg_match('/^(20\d\d|19\d\d)-(0[1-9]|1[0-2])$/', $periodo)) {
    responder(400, ['success' => false, 'message' => 'RUT o período inválido']);
}
// is_bool estricto: "si", 1 o "true" como texto no valen
if (!is_bool($in['pagado'] ?? null)) {
    responder(400, ['success' => false, 'message' => 'Indica si el mes está pagado']);
}

$monto = $in['monto'] ?? null;
if ($monto === '' || $monto === null) {
    $monto = null;
} elseif (!is_int($monto) || $monto < 0 || $monto > 99999999) {
    responder(400, ['success' => false, 'message' => 'El monto debe ser un entero positivo']);
}

$nota = isset($in['nota']) ? trim((string) $in['nota']) : null;
$nota = $nota === '' ? null : $nota;
if ($nota !== null && mb_strlen($nota) > 255) {
    responder(400, ['success' => false, 'message' => 'La nota admite hasta 255 caracteres']);
}

$pagado = (int) $in['pagado'];
$fecha = $periodo . '-01'; // la base guarda el primer día del mes

conManejoDeErrores(function () use ($rut, $fecha, $periodo, $pagado, $monto, $nota) {
    $pdo = conectarBD();

    $existe = $pdo->prepare('SELECT 1 FROM asegurados WHERE rut = ?');
    $existe->execute([$rut]);
    if (!$existe->fetchColumn()) {
        responder(404, ['success' => false, 'message' => 'No existe un asegurado con ese RUT']);
    }

    // Transacción: el cambio del pago y su registro en el historial se guardan juntos o no se guardan
    $pdo->beginTransaction();

    // FOR UPDATE bloquea la fila mientras dura la transacción, para que dos ediciones
    // simultáneas del mismo mes no se pisen
    $stmt = $pdo->prepare('SELECT id, pagado, monto, nota FROM pagos_mensuales WHERE rut = ? AND periodo = ? FOR UPDATE');
    $stmt->execute([$rut, $fecha]);
    $actual = $stmt->fetch();

    $log = $pdo->prepare(
        "INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario)
         VALUES ('pagos_mensuales', ?, ?, ?, ?, 'front')"
    );
    // Misma clave "rut|periodo" que usa la sincronización, para ver todo el historial de un mes junto
    $registro = "$rut|$fecha";

    if ($actual === false) {
        // El mes no existía (ej. un mes sin datos): se crea
        $pdo->prepare(
            "INSERT INTO pagos_mensuales (rut, periodo, pagado, monto, origen, editado, nota)
             VALUES (?, ?, ?, ?, 'manual', 1, ?)"
        )->execute([$rut, $fecha, $pagado, $monto, $nota]);
        $log->execute([$registro, 'pagado', null, (string) $pagado]);
        $cambio = true;
        $notaCambio = $nota !== null;
    } else {
        // El mes existía: se compara campo a campo y solo se registra lo que de verdad cambió
        $nuevo = ['pagado' => $pagado, 'monto' => $monto, 'nota' => $nota];
        $anterior = [
            'pagado' => (int) $actual['pagado'],
            'monto' => $actual['monto'] === null ? null : (int) $actual['monto'],
            'nota' => $actual['nota'],
        ];
        $cambio = false;
        $notaCambio = $nota !== $anterior['nota'];
        foreach ($nuevo as $campo => $valor) {
            if ($valor !== $anterior[$campo]) {
                $log->execute([$registro, $campo, $anterior[$campo], $valor]);
                $cambio = true;
            }
        }
        if ($cambio) {
            $pdo->prepare(
                "UPDATE pagos_mensuales SET pagado = ?, monto = ?, nota = ?, origen = 'manual', editado = 1 WHERE id = ?"
            )->execute([$pagado, $monto, $nota, $actual['id']]);
        }
    }

    // ---- Pérdida de cobertura: solo se evalúa si el mes quedó impago y el dato cambió ----
    $cambioEstado = ['cambio' => false, 'anterior' => null, 'nuevo' => null];
    if ($cambio && $pagado === 0) {
        $cambioEstado = aplicarPerdidaDeCobertura($pdo, $rut, $periodo);
    }

    $pdo->commit();

    // ---- Reflejar el cambio en la hoja de Google (si la conexión está configurada) ----
    $estadoHoja = enviarAHoja($pdo, $cambio, [
        'rut' => $rut,
        'periodo' => $periodo,
        'valor' => valorParaHoja($pagado, $monto),
        // La nota solo se manda si cambió (una clave ausente no toca la nota que ya tenga la celda)
        ...($notaCambio ? ['nota' => $nota ?? ''] : []),
        ...($cambioEstado['cambio'] ? ['estado' => $cambioEstado['nuevo']] : []),
    ]);

    // Se vuelve a leer el mes para devolver exactamente lo que quedó guardado
    $stmt = $pdo->prepare('SELECT pagado, monto, valor_original, nota, editado, origen FROM pagos_mensuales WHERE rut = ? AND periodo = ?');
    $stmt->execute([$rut, $fecha]);
    $p = $stmt->fetch();

    responder(200, [
        'success' => true,
        'message' => $cambio ? 'Mes actualizado' : 'Sin cambios',
        'pago' => [
            'periodo' => $periodo,
            'pagado' => (bool) $p['pagado'],
            'monto' => $p['monto'] === null ? null : (int) $p['monto'],
            'valor_original' => $p['valor_original'],
            'nota' => $p['nota'],
            'editado' => (bool) $p['editado'],
            'origen' => $p['origen'],
        ],
        'estado' => $cambioEstado,
        // 'desactivada' (sin conexión configurada), 'enviado' o 'pendiente' (se reintentará solo)
        'hoja' => $estadoHoja,
    ]);
});

/**
 * Cómo se representa un mes en la celda de la hoja (la celda tiene un solo valor):
 *   pagado con monto → el monto;  pagado sin monto → "PAGADO";  impago → 0.
 */
function valorParaHoja(int $pagado, ?int $monto): int|string
{
    return $pagado ? ($monto ?? 'PAGADO') : 0;
}

/**
 * Anota el cambio en la cola de la hoja e intenta enviarlo de inmediato.
 * Un fallo al enviar NUNCA hace fallar la edición (ya está guardada en la base): el cambio
 * queda pendiente y scripts/procesar_cola.php lo reintenta.
 *
 * @return string 'desactivada' | 'enviado' | 'pendiente' (se reintentará solo) |
 *                'fallido' (la hoja lo rechazó de forma permanente, ej. el RUT no está en la hoja)
 */
function enviarAHoja(PDO $pdo, bool $huboCambio, array $cambio): string
{
    $hoja = new HojaGoogle($pdo, require __DIR__ . '/../config/hoja.php');
    if (!$hoja->activa()) {
        return 'desactivada';
    }
    if (!$huboCambio) {
        return 'enviado'; // no había nada nuevo que escribir
    }

    try {
        $hoja->encolar($cambio);
        $r = $hoja->procesarPendientes(5);
        return $r['fallidos'] > 0 ? 'fallido' : ($r['pendientes'] > 0 ? 'pendiente' : 'enviado');
    } catch (Throwable $e) {
        error_log('pago.php (hoja): ' . $e);
        return 'pendiente';
    }
}

/**
 * Pasa a la persona a NO VIGENTE si, con el mes recién marcado como impago, completa la
 * racha de meses seguidos sin pago. Debe llamarse dentro de la transacción de la edición
 * (así ve el mes ya actualizado y el cambio de estado se confirma o se deshace junto con él).
 *
 * @param string $periodo  mes editado, 'AAAA-MM'
 * @return array{cambio:bool, anterior:?string, nuevo:?string}
 */
function aplicarPerdidaDeCobertura(PDO $pdo, int $rut, string $periodo): array
{
    $sinCambio = ['cambio' => false, 'anterior' => null, 'nuevo' => null];

    // Solo los VIGENTE o VAN A CORTE pueden perder la cobertura; FOR UPDATE evita que una
    // sincronización simultánea cambie el estado mientras se decide
    $stmt = $pdo->prepare('SELECT estado, fecha_alta FROM asegurados WHERE rut = ? FOR UPDATE');
    $stmt->execute([$rut]);
    $persona = $stmt->fetch();
    if (!$persona || $persona['estado'] === 'NO VIGENTE') {
        return $sinCambio;
    }

    $stmt = $pdo->prepare('SELECT DATE_FORMAT(periodo, "%Y-%m") AS periodo, pagado FROM pagos_mensuales WHERE rut = ?');
    $stmt->execute([$rut]);
    $racha = Vigencia::mesesSinPago(
        array_map('boolval', array_column($stmt->fetchAll(), 'pagado', 'periodo')),
        $persona['fecha_alta']
    );
    if (!Vigencia::debePerderCobertura($racha, $periodo)) {
        return $sinCambio;
    }

    $pdo->prepare("UPDATE asegurados SET estado = 'NO VIGENTE', estado_manual = 1, estado_calculado_at = NOW() WHERE rut = ?")
        ->execute([$rut]);
    $pdo->prepare(
        "INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario)
         VALUES ('asegurados', ?, 'estado', ?, 'NO VIGENTE', 'front')"
    )->execute([(string) $rut, $persona['estado']]);

    return ['cambio' => true, 'anterior' => $persona['estado'], 'nuevo' => 'NO VIGENTE'];
}
