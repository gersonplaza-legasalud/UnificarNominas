<?php

/**
 * GET api/asegurado.php?rut=12345678
 *
 * Ficha completa de una persona; es lo que pinta el panel derecho del front.
 *
 * Responde:
 *   asegurado : datos de la persona y de su póliza (con catálogos ya traducidos a texto)
 *   pagador   : quién paga por ella (null si no tiene). `nombre` viene null cuando el
 *               pagador no está en la nómina (otra persona o empresa)
 *   paga_por  : a quiénes paga ella (si es pagador de otras personas)
 *   pagos     : un elemento por mes con datos, del más antiguo al más reciente
 *   sin_pago  : meses seguidos sin pago contados hacia atrás (dato informativo)
 */

require_once __DIR__ . '/_comun.php';
require_once __DIR__ . '/../src/Services/Vigencia.php';

use App\Services\Vigencia;

exigirMetodo('GET');

// Se aceptan variantes como "7.779.582": solo se conservan los dígitos
$rut = (int) preg_replace('/\D/', '', (string) ($_GET['rut'] ?? ''));
if ($rut <= 0) {
    responder(400, ['success' => false, 'message' => 'RUT inválido']);
}

conManejoDeErrores(function () use ($rut) {
    $pdo = conectarBD();

    // 1. Datos de la persona, con sus catálogos traducidos
    $stmt = $pdo->prepare(
        'SELECT a.*, t.nombre AS tipo, c.nombre AS cobertura, m.nombre AS medio_pago, b.nombre AS motivo_baja
         FROM asegurados a
         JOIN tipos_asegurado t ON t.id = a.tipo_id
         LEFT JOIN coberturas c ON c.id = a.cobertura_id
         LEFT JOIN medios_pago m ON m.id = a.medio_pago_id
         LEFT JOIN motivos_baja b ON b.id = a.motivo_baja_id
         WHERE a.rut = ?'
    );
    $stmt->execute([$rut]);
    $a = $stmt->fetch();
    if (!$a) {
        responder(404, ['success' => false, 'message' => 'No existe un asegurado con ese RUT']);
    }

    // 2. Historial de pagos. DATE_FORMAT deja el período como "2026-09" (año-mes),
    //    que es la clave que usa el front para ubicar cada mes en la grilla.
    $stmt = $pdo->prepare(
        'SELECT DATE_FORMAT(periodo, "%Y-%m") AS periodo, pagado, monto, valor_original, nota, editado, origen
         FROM pagos_mensuales WHERE rut = ? ORDER BY periodo'
    );
    $stmt->execute([$rut]);
    $pagos = array_map(fn($p) => [
        'periodo' => $p['periodo'],
        'pagado' => (bool) $p['pagado'],
        'monto' => $p['monto'] === null ? null : (int) $p['monto'],
        'valor_original' => $p['valor_original'],
        'nota' => $p['nota'],
        'editado' => (bool) $p['editado'],
        'origen' => $p['origen'],
    ], $stmt->fetchAll());

    // 3. Quién paga por esta persona
    $pagador = null;
    if ($a['rut_pagador']) {
        $s = $pdo->prepare('SELECT nombre FROM asegurados WHERE rut = ?');
        $s->execute([$a['rut_pagador']]);
        $pagador = [
            'rut' => (int) $a['rut_pagador'],
            'rut_formateado' => formatearRut($a['rut_pagador'], $a['dv_pagador']),
            // null si el pagador no está en la nómina (otra persona o empresa)
            'nombre' => $s->fetchColumn() ?: null,
        ];
    }

    // 4. A quiénes paga esta persona (se excluye a sí misma)
    $s = $pdo->prepare('SELECT rut, dv, nombre, estado FROM asegurados WHERE rut_pagador = ? AND rut <> ? ORDER BY nombre LIMIT 100');
    $s->execute([$rut, $rut]);
    $paga_por = array_map(fn($r) => [
        'rut' => (int) $r['rut'],
        'rut_formateado' => formatearRut($r['rut'], $r['dv']),
        'nombre' => $r['nombre'],
        'estado' => $r['estado'],
    ], $s->fetchAll());

    responder(200, [
        'success' => true,
        'asegurado' => [
            'rut' => (int) $a['rut'],
            'rut_formateado' => formatearRut($a['rut'], $a['dv']),
            'dv_valido' => (bool) $a['dv_valido'],
            'nombre' => $a['nombre'],
            'genero' => $a['genero'],
            'telefono' => $a['telefono'],
            'mail' => $a['mail'],
            'tipo' => $a['tipo'],
            'poliza' => $a['poliza'],
            'cobertura' => $a['cobertura'],
            'medio_pago' => $a['medio_pago'],
            'rut_pagador_original' => $a['rut_pagador_original'],
            'fecha_alta' => $a['fecha_alta'],
            'fecha_titulacion' => $a['fecha_titulacion'],
            'fecha_baja' => $a['fecha_baja'],
            'fecha_baja_texto' => $a['fecha_baja_texto'],
            'motivo_baja' => $a['motivo_baja'],
            'estado' => $a['estado'],
            // true si la aplicación (no el Excel) lo pasó a NO VIGENTE por 3 meses sin pago
            'estado_manual' => (bool) $a['estado_manual'],
        ],
        'pagador' => $pagador,
        'paga_por' => $paga_por,
        'pagos' => $pagos,
        // Dato informativo para la ficha; la misma cuenta decide el cambio de estado en pago.php
        'sin_pago' => Vigencia::mesesSinPago(
            array_column($pagos, 'pagado', 'periodo'),
            $a['fecha_alta']
        ),
    ]);
});
