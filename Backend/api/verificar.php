<?php

/**
 * GET api/verificar.php?poliza=918&k=<código>
 *
 * Endpoint PÚBLICO de solo lectura al que llega quien escanea el QR del certificado.
 * Devuelve únicamente lo mínimo para verificar la vigencia de ESA póliza (nombre, RUT,
 * estado, número de póliza vigente y cobertura): nada de
 * especialidad, producto, otro tipo, contacto, dirección, sociedad, pagos ni pagador.
 *
 * `k` es el código del certificado (ver codigoVerificacion en _comun.php); sin él, o con uno
 * que no corresponde a la póliza, se responde 404 igual que si la póliza no existiera.
 */

require_once __DIR__ . '/_comun.php';

exigirMetodo('GET');

$polizaId = (int) preg_replace('/\D/', '', (string) ($_GET['poliza'] ?? ''));
$codigo = (string) ($_GET['k'] ?? '');

if ($polizaId <= 0 || !hash_equals(codigoVerificacion($polizaId), $codigo)) {
    responder(404, ['success' => false, 'message' => 'El enlace de verificación no es válido.']);
}

conManejoDeErrores(function () use ($polizaId) {
    $pdo = conectarBD();
    $stmt = $pdo->prepare(
        'SELECT a.rut, a.dv, a.nombre, a.especialidad, z.estado, z.numero, z.fecha_alta, z.fecha_baja,
                c.nombre AS cobertura, pr.nombre AS producto, ti.nombre AS tipo_individuo,
                pp.numero AS periodo_numero, pp.vigencia_desde, pp.vigencia_hasta, z.cobertura_desde,
                (SELECT m.numero FROM polizas_matrices m WHERE m.producto_id = z.producto_id
                 AND m.vigencia_hasta = (SELECT MAX(vigencia_hasta) FROM polizas_matrices WHERE producto_id = z.producto_id)) AS numero_vigente
         FROM polizas z
         JOIN asegurados a ON a.rut = z.rut
         LEFT JOIN coberturas c ON c.id = z.cobertura_id
         LEFT JOIN productos pr ON pr.id = z.producto_id
         LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id
         LEFT JOIN poliza_periodos pp ON pp.id = (
             SELECT id FROM poliza_periodos WHERE poliza_id = z.id ORDER BY vigencia_desde DESC LIMIT 1)
         WHERE z.id = ?'
    );
    $stmt->execute([$polizaId]);
    $a = $stmt->fetch();
    if (!$a) {
        responder(404, ['success' => false, 'message' => 'El enlace de verificación no es válido.']);
    }

    $vigente = $a['estado'] === 'VIGENTE';

    responder(200, [
        'success' => true,
        'asegurado' => [
            'nombre' => $a['nombre'],
            'rut_formateado' => formatearRut($a['rut'], $a['dv']),
            'estado' => $a['estado'],
            // Una póliza vigente lleva el número de la última póliza vigente de su producto
            'poliza' => $vigente ? ($a['numero_vigente'] ?? $a['numero']) : ($a['periodo_numero'] ?? $a['numero']),
            'cobertura' => $a['cobertura'],
        ],
        'consultado_en' => date('c'),
    ]);
});
