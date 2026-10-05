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
 * Responde: { success, total, resultados: [{rut, rut_formateado, nombre, estado, cobertura, medio_pago}] }
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

    // Las primeras LIMITE filas, con cobertura y medio de pago ya traducidos desde sus catálogos
    $stmt = $pdo->prepare(
        "SELECT a.rut, a.dv, a.nombre, a.estado, c.nombre AS cobertura, m.nombre AS medio_pago
         FROM asegurados a
         LEFT JOIN coberturas c ON c.id = a.cobertura_id
         LEFT JOIN medios_pago m ON m.id = a.medio_pago_id
         WHERE $where
         ORDER BY $orden
         LIMIT " . LIMITE
    );
    $stmt->execute($params + ($paramsOrden ?? []));

    $resultados = array_map(fn($r) => [
        'rut' => (int) $r['rut'],
        'rut_formateado' => formatearRut($r['rut'], $r['dv']),
        'nombre' => $r['nombre'],
        'estado' => $r['estado'],
        'cobertura' => $r['cobertura'],
        'medio_pago' => $r['medio_pago'],
    ], $stmt->fetchAll());

    responder(200, ['success' => true, 'total' => $total, 'resultados' => $resultados]);
});
