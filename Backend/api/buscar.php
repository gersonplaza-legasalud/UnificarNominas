<?php

/**
 * GET api/buscar.php?q=...
 *
 * Búsqueda de asegurados para la lista de resultados del front.
 * Según lo que se escriba busca por:
 *   - RUT:    solo dígitos, con o sin puntos, guion y dígito verificador
 *             ("7779582", "7.779.582-0", "7779582K"). Coincide por RUT exacto, por
 *             RUT+dv completo o por prefijo, y los exactos salen primero.
 *   - mail:   si el texto contiene "@".
 *   - nombre: en cualquier otro caso; todas las palabras escritas deben aparecer en el
 *             nombre, en cualquier orden (la colación ignora mayúsculas y tildes).
 *
 * Responde: { success, total, resultados: [{rut, rut_formateado, nombre, especialidad, tipo_individuo,
 *             polizas: [{id, estado, producto, cobertura, medio_pago, anteriores}]}] }
 * De cada producto se muestra solo la póliza más reciente; `anteriores` dice cuántas pólizas de períodos
 * anteriores del mismo producto tiene (se ven en la ficha).
 * Devuelve como máximo LIMITE filas; `total` indica cuántas coinciden en realidad
 * para que el front avise "mostrando 25 de N".
 */

require_once __DIR__ . '/_comun.php';

exigirMetodo('GET');

const LIMITE = 25;

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 3) {
    responder(400, ['success' => false, 'message' => 'Escribe al menos 3 caracteres']);
}

conManejoDeErrores(function () use ($q) {
    $pdo = conectarBD();
    $params = [];
    $orden = 'a.nombre';
    $limpio = strtoupper(preg_replace('/[.\s-]/', '', $q)); // sin puntos, espacios ni guion

    if (preg_match('/^\d+K?$/', $limpio)) {
        // --- Búsqueda por RUT ---
        $where = 'a.rut = :exacto OR CONCAT(a.rut, a.dv) = :completo OR a.rut LIKE :prefijo';
        $params = [
            ':exacto' => (int) rtrim($limpio, 'K'),
            ':completo' => $limpio,
            ':prefijo' => rtrim($limpio, 'K') . '%',
        ];
        // Los coincidentes exactos primero; el resto por RUT. (Parámetros distintos a los del
        // WHERE porque PDO no permite repetir el mismo nombre de parámetro.)
        $orden = '(a.rut = :exacto2 OR CONCAT(a.rut, a.dv) = :completo2) DESC, a.rut';
        $paramsOrden = [':exacto2' => $params[':exacto'], ':completo2' => $limpio];
    } elseif (str_contains($q, '@')) {
        // --- Búsqueda por mail ---
        $where = 'a.mail LIKE :mail';
        $params = [':mail' => '%' . addcslashes($q, '%_\\') . '%'];
    } else {
        // --- Búsqueda por nombre: cada palabra es una condición LIKE unida con AND ---
        $condiciones = [];
        foreach (preg_split('/\s+/u', $q) as $i => $palabra) {
            $condiciones[] = "a.nombre LIKE :p$i";
            // addcslashes: que un % o _ escrito por el usuario no actúe como comodín
            $params[":p$i"] = '%' . addcslashes($palabra, '%_\\') . '%';
        }
        $where = implode(' AND ', $condiciones);
    }

    // Total real de coincidencias (puede ser mayor que lo que se devuelve)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM asegurados a WHERE $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Las primeras LIMITE personas
    $stmt = $pdo->prepare(
        "SELECT a.rut, a.dv, a.nombre, a.especialidad, a.con_siniestro, ti.nombre AS tipo_individuo
         FROM asegurados a
         LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id
         WHERE $where
         ORDER BY $orden
         LIMIT " . LIMITE
    );
    $stmt->execute($params + ($paramsOrden ?? []));
    $personas = $stmt->fetchAll();

    // Sus pólizas, con cobertura y medio de pago ya traducidos. Una persona puede tener varias del mismo producto
    // (una por período): en la lista solo se muestra la más reciente de cada producto.
    $polizasPorRut = [];
    if ($personas) {
        $ruts = array_column($personas, 'rut');
        $stmt = $pdo->prepare(
            'SELECT z.rut, z.id, z.estado, z.va_a_corte, pr.nombre AS producto,
                    c.nombre AS cobertura, m.nombre AS medio_pago,
                    (SELECT MAX(vigencia_hasta) FROM poliza_periodos WHERE poliza_id = z.id) AS hasta
             FROM polizas z
             LEFT JOIN productos pr ON pr.id = z.producto_id
             LEFT JOIN coberturas c ON c.id = z.cobertura_id
             LEFT JOIN medios_pago m ON m.id = z.medio_pago_id
             WHERE z.rut IN (' . implode(',', array_fill(0, count($ruts), '?')) . ')
             ORDER BY z.rut, (z.producto_id IS NULL), z.producto_id, COALESCE(hasta, "0000-00-00") DESC, z.id DESC'
        );
        $stmt->execute($ruts);
        $posicion = []; // "rut|producto" → posición de su póliza más reciente en la lista de la persona
        foreach ($stmt->fetchAll() as $z) {
            $clave = $z['producto'] === null ? 'id' . $z['id'] : $z['rut'] . '|' . $z['producto'];
            if (isset($posicion[$clave])) { // un período anterior del mismo producto
                $polizasPorRut[$z['rut']][$posicion[$clave]]['anteriores']++;
                continue;
            }
            $polizasPorRut[$z['rut']][] = [
                'id' => (int) $z['id'],
                'estado' => $z['estado'],
                'va_a_corte' => (bool) $z['va_a_corte'],
                'producto' => $z['producto'],
                'cobertura' => $z['cobertura'],
                'medio_pago' => $z['medio_pago'],
                'anteriores' => 0,
            ];
            $posicion[$clave] = array_key_last($polizasPorRut[$z['rut']]);
        }
        // Las vigentes primero
        foreach ($polizasPorRut as &$lista) usort($lista, fn($a, $b) => [$a['estado'] === 'NO VIGENTE', $a['id']] <=> [$b['estado'] === 'NO VIGENTE', $b['id']]);
        unset($lista);
    }

    $resultados = array_map(fn($r) => [
        'rut' => (int) $r['rut'],
        'rut_formateado' => formatearRut($r['rut'], $r['dv']),
        'nombre' => $r['nombre'],
        'especialidad' => $r['especialidad'],
        'tipo_individuo' => $r['tipo_individuo'],
        'con_siniestro' => (bool) $r['con_siniestro'],
        'polizas' => $polizasPorRut[$r['rut']] ?? [],
    ], $personas);

    responder(200, ['success' => true, 'total' => $total, 'resultados' => $resultados]);
});
