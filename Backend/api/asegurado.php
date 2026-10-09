<?php

/**
 * GET api/asegurado.php?rut=12345678
 *
 * Ficha completa de una persona; es lo que pinta el panel derecho del front.
 *
 * Responde:
 *   asegurado : datos de la persona (identidad, contacto, especialidad, "otro tipo" y su
 *               sociedad si está en una)
 *   polizas   : todas sus pólizas (una persona puede tener varias, p. ej. Odontólogo y Dermo).
 *               Cada una trae sus condiciones, su estado, su pagador, su cobertura (`cobertura_vigente`: 12 meses
 *               desde su inicio, renovada sola cada año mientras la persona siga vigente), los períodos de su
 *               póliza matriz (`periodos`, el más reciente primero, y `vigencia_actual`), sus pagos mes a mes,
 *               los meses seguidos sin pago y el código del QR del certificado
 *   paga_por  : a quiénes paga ella (si es pagador de pólizas de otras personas)
 *
 * Los campos vacíos vienen como null; el front los muestra como "--".
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

    // 1. Datos de la persona, con su "otro tipo" y su sociedad (si está en una)
    $stmt = $pdo->prepare(
        'SELECT a.*, ti.nombre AS tipo_individuo, s.rut AS soc_rut, s.dv AS soc_dv, s.nombre AS soc_nombre,
                s.direccion AS soc_direccion, s.comuna AS soc_comuna, s.giro AS soc_giro
         FROM asegurados a
         LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id
         LEFT JOIN sociedades s ON s.id = a.sociedad_id
         WHERE a.rut = ?'
    );
    $stmt->execute([$rut]);
    $a = $stmt->fetch();
    if (!$a) {
        responder(404, ['success' => false, 'message' => 'No existe un asegurado con ese RUT']);
    }

    // 2. Sus pólizas, con los catálogos traducidos. De la más reciente a la más antigua según el período de la póliza
    //    (las que no tienen período, al final).
    $stmt = $pdo->prepare(
        'SELECT z.*, pr.nombre AS producto, c.nombre AS cobertura,
                m.nombre AS medio_pago, b.nombre AS motivo_baja
         FROM polizas z
         LEFT JOIN productos pr ON pr.id = z.producto_id
         LEFT JOIN coberturas c ON c.id = z.cobertura_id
         LEFT JOIN medios_pago m ON m.id = z.medio_pago_id
         LEFT JOIN motivos_baja b ON b.id = z.motivo_baja_id
         WHERE z.rut = ?
         ORDER BY (SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id) IS NULL,
                  (SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id) DESC,
                  (SELECT MAX(vigencia_desde) FROM poliza_periodos WHERE poliza_id = z.id) DESC, z.id DESC'
    );
    $stmt->execute([$rut]);
    $filas = $stmt->fetchAll();

    // 3. Pagos de todas sus pólizas de una sola vez. DATE_FORMAT deja el período como "2026-09"
    //    (año-mes), que es la clave que usa el front para ubicar cada mes en la grilla.
    $pagosPorPoliza = [];
    if ($filas) {
        $ids = array_column($filas, 'id');
        $stmt = $pdo->prepare(
            'SELECT poliza_id, DATE_FORMAT(periodo, "%Y-%m") AS periodo, pagado, monto, valor_original, nota, editado, origen
             FROM pagos_mensuales WHERE poliza_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY periodo'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $p) {
            $pagosPorPoliza[$p['poliza_id']][] = [
                'periodo' => $p['periodo'],
                'pagado' => (bool) $p['pagado'],
                'monto' => $p['monto'] === null ? null : (int) $p['monto'],
                'valor_original' => $p['valor_original'],
                'nota' => $p['nota'],
                'editado' => (bool) $p['editado'],
                'origen' => $p['origen'],
            ];
        }
    }

    // 3b. Períodos anuales de cada póliza (el más reciente primero)
    $periodosPorPoliza = [];
    if ($filas) {
        $ids = array_column($filas, 'id');
        $stmt = $pdo->prepare(
            'SELECT poliza_id, numero, vigencia_desde, vigencia_hasta, origen
             FROM poliza_periodos WHERE poliza_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY vigencia_desde DESC'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $p) {
            $periodosPorPoliza[$p['poliza_id']][] = [
                'numero' => $p['numero'],
                'desde' => $p['vigencia_desde'],
                'hasta' => $p['vigencia_hasta'],
                'origen' => $p['origen'],
            ];
        }
    }

    // Número de la póliza matriz vigente de cada producto: es el que lleva el certificado de una póliza vigente
    $numeroVigente = $pdo->query(
        'SELECT pr.nombre, m.numero FROM polizas_matrices m JOIN productos pr ON pr.id = m.producto_id
         WHERE m.vigencia_hasta = (SELECT MAX(vigencia_hasta) FROM polizas_matrices WHERE producto_id = m.producto_id)'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // 3c. Meses seguidos sin pago: se cuentan sobre TODAS las pólizas del mismo producto de la persona (un mes cuenta como
    //     pagado si lo está en alguna). Cada póliza solo guarda los meses de su período; contarla sola daría meses "sin datos"
    //     de más (el historial anterior vive en las pólizas anteriores).
    $pagadoPorGrupo = [];
    $altaPorGrupo = [];
    foreach ($filas as $z) {
        $g = $z['producto_id'] !== null ? 'p' . $z['producto_id'] : 'z' . $z['id'];
        foreach ($pagosPorPoliza[$z['id']] ?? [] as $pg) {
            $pagadoPorGrupo[$g][$pg['periodo']] = ($pagadoPorGrupo[$g][$pg['periodo']] ?? false) || $pg['pagado'];
        }
        if ($z['fecha_alta'] !== null && (!isset($altaPorGrupo[$g]) || $z['fecha_alta'] < $altaPorGrupo[$g])) $altaPorGrupo[$g] = $z['fecha_alta'];
    }

    // 4. Quién paga cada póliza (si el pagador está en la nómina, se trae su nombre)
    $nombrePagador = $pdo->prepare('SELECT nombre FROM asegurados WHERE rut = ?');

    $polizas = [];
    foreach ($filas as $z) {
        $pagador = null;
        if ($z['rut_pagador']) {
            $nombrePagador->execute([$z['rut_pagador']]);
            $pagador = [
                'rut' => (int) $z['rut_pagador'],
                'rut_formateado' => formatearRut($z['rut_pagador'], $z['dv_pagador']),
                // null si el pagador no está en la nómina (otra persona o empresa)
                'nombre' => $nombrePagador->fetchColumn() ?: null,
            ];
        }
        $pagos = $pagosPorPoliza[$z['id']] ?? [];
        $periodos = $periodosPorPoliza[$z['id']] ?? [];

        $polizas[] = [
            'id' => (int) $z['id'],
            'numero' => $z['numero'],
            'tipo_contrato' => $z['tipo_contrato'],
            'producto' => $z['producto'],
            'compania' => $z['compania'],
            'corredor' => $z['corredor'],
            'cobertura' => $z['cobertura'],
            'medio_pago' => $z['medio_pago'],
            'valor_lega' => $z['valor_lega'] === null ? null : (float) $z['valor_lega'],
            'valor_poliza' => $z['valor_poliza'] === null ? null : (float) $z['valor_poliza'],
            'deducible_uf' => $z['deducible_uf'] === null ? null : (float) $z['deducible_uf'],
            'rut_pagador_original' => $z['rut_pagador_original'],
            'pagador' => $pagador,
            'fecha_alta' => $z['fecha_alta'],
            'fecha_titulacion' => $z['fecha_titulacion'],
            'fecha_baja' => $z['fecha_baja'],
            'fecha_baja_texto' => $z['fecha_baja_texto'],
            'motivo_baja' => $z['motivo_baja'],
            'fecha_renuncia' => $z['fecha_renuncia'],
            'fecha_envio_certificado' => $z['fecha_envio_certificado'],
            'fecha_envio_poliza' => $z['fecha_envio_poliza'],
            'numero_certificado' => $z['numero_certificado'],
            'observaciones' => $z['observaciones'],
            'estado' => $z['estado'],
            // Aviso (no es un estado): la póliza sigue VIGENTE pero hay riesgo de que se corte
            'va_a_corte' => (bool) $z['va_a_corte'],
            // Cobertura de la persona (no es el período de la póliza matriz): inicio registrado y ventana vigente hoy
            'cobertura_desde' => $z['cobertura_desde'],
            'cobertura_vigente' => coberturaActual($z['cobertura_desde'], $z['estado']),
            // Una póliza vigente se certifica con el número de la última póliza vigente de su producto
            'numero_vigente' => $z['estado'] === 'VIGENTE' ? ($numeroVigente[$z['producto']] ?? $z['numero']) : $z['numero'],
            // true si la aplicación (no el Excel) la pasó a NO VIGENTE por 2 meses sin pago
            'estado_manual' => (bool) $z['estado_manual'],
            // Código que va en el QR del certificado de esta póliza (ver api/verificar.php)
            'codigo_verificacion' => codigoVerificacion((int) $z['id']),
            // Períodos anuales; el primero es el más reciente (null si aún no se cargó ninguno)
            'periodos' => $periodos,
            'vigencia_actual' => $periodos[0] ?? null,
            'pagos' => $pagos,
            // Dato informativo para la ficha; la misma cuenta decide el cambio de estado en pago.php
            'sin_pago' => Vigencia::mesesSinPago(
                $pagadoPorGrupo[$z['producto_id'] !== null ? 'p' . $z['producto_id'] : 'z' . $z['id']] ?? [],
                $altaPorGrupo[$z['producto_id'] !== null ? 'p' . $z['producto_id'] : 'z' . $z['id']] ?? null
            ),
        ];
    }

    // 5. A quiénes paga esta persona (pólizas de otras personas donde ella figura como pagadora). Si una persona tiene
    //    varios períodos del mismo producto se lista solo el más reciente.
    $s = $pdo->prepare(
        'SELECT a.rut, a.dv, a.nombre, z.id AS poliza_id, z.estado, pr.nombre AS producto,
                (SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id) AS hasta
         FROM polizas z
         JOIN asegurados a ON a.rut = z.rut
         LEFT JOIN productos pr ON pr.id = z.producto_id
         WHERE z.rut_pagador = ? AND z.rut <> ?
         ORDER BY a.nombre, z.producto_id, COALESCE(hasta, "0000-00-00") DESC, z.id DESC LIMIT 300'
    );
    $s->execute([$rut, $rut]);
    $paga_por = [];
    $vistos = [];
    foreach ($s->fetchAll() as $r) {
        $clave = $r['rut'] . '|' . ($r['producto'] ?? 'id' . $r['poliza_id']);
        if (isset($vistos[$clave]) || count($paga_por) >= 100) continue;
        $vistos[$clave] = true;
        $paga_por[] = [
            'rut' => (int) $r['rut'],
            'rut_formateado' => formatearRut($r['rut'], $r['dv']),
            'nombre' => $r['nombre'],
            'poliza_id' => (int) $r['poliza_id'],
            'producto' => $r['producto'],
            'estado' => $r['estado'],
        ];
    }

    responder(200, [
        'success' => true,
        'asegurado' => [
            'rut' => (int) $a['rut'],
            'rut_formateado' => formatearRut($a['rut'], $a['dv']),
            'dv_valido' => (bool) $a['dv_valido'],
            'nombre' => $a['nombre'],
            'genero' => $a['genero'],
            'fecha_nacimiento' => $a['fecha_nacimiento'],
            'especialidad' => $a['especialidad'],
            'tipo_individuo' => $a['tipo_individuo'],
            // Persona con siniestro: paga deducible en todas sus pólizas (16 UF por defecto, editable)
            'con_siniestro' => (bool) $a['con_siniestro'],
            'deducible_uf' => $a['deducible_uf'] === null ? null : (float) $a['deducible_uf'],
            'telefono' => $a['telefono'],
            'mail' => $a['mail'],
            'direccion' => $a['direccion'],
            'comuna' => $a['comuna'],
            'ciudad' => $a['ciudad'],
            'ejecutivo' => $a['ejecutivo'],
            // null = persona natural
            'sociedad' => $a['sociedad_id'] === null ? null : [
                'nombre' => $a['soc_nombre'],
                'rut_formateado' => $a['soc_rut'] ? formatearRut($a['soc_rut'], (string) $a['soc_dv']) : null,
                'direccion' => $a['soc_direccion'],
                'comuna' => $a['soc_comuna'],
                'giro' => $a['soc_giro'],
            ],
        ],
        'polizas' => $polizas,
        'paga_por' => $paga_por,
    ]);
});
