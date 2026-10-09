<?php

/**
 * POST api/cliente.php   (cuerpo JSON)
 *
 * Crea una PERSONA nueva (cliente). Lo usa el botón "Nuevo cliente" del front; después el front abre "Agregar póliza" con
 * los valores sugeridos (producto según su especialidad, prima de la tabla de primas, deducible…) para crear su primera póliza.
 *
 *   {
 *     "rut_texto": "12.345.678-9",       RUT con dígito verificador (se valida)
 *     "nombre": "JUAN PEREZ SOTO",
 *     "especialidad", "mail", "telefono", "direccion", "comuna", "ciudad", "ejecutivo": texto o null,
 *     "fecha_nacimiento": "AAAA-MM-DD" o null,
 *     "otro_tipo": "INDIVIDUAL" | "DERMO" | … | null,        (SOEMAF se guarda como DERMO)
 *     "sociedad_nombre": texto o null, "sociedad_rut": "78.580.620-4" o null,
 *     "con_siniestro": true | false,     paga deducible en todas sus pólizas: va siempre con tipo INDIVIDUAL y póliza Individual
 *     "deducible_uf": 16                 (vacío = 16 por defecto)
 *   }
 *
 * Reglas:
 *   - El RUT no puede existir ya (409, con el nombre de quien lo tiene) y su dígito verificador debe coincidir.
 *   - El nombre se guarda en mayúsculas y el mail en minúsculas (como el resto de la base).
 *   - Una persona con siniestro queda con tipo INDIVIDUAL; sin tipo, un médico u otro profesional (póliza Individual o Derma) también.
 *   - La creación queda en `historial_cambios` (usuario 'front').
 *
 * Responde: { success, message, rut, nombre, producto_sugerido, con_siniestro, deducible_uf }
 */

require_once __DIR__ . '/_comun.php';

exigirMetodo('POST');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    responder(400, ['success' => false, 'message' => 'Se esperaba un JSON']);
}

function error400(string $mensaje): never
{
    responder(400, ['success' => false, 'message' => $mensaje]);
}

/** Texto recortado; null si viene vacío. Rechaza lo que supere el largo de su columna. */
function textoCampo(array $in, string $clave, int $max, string $etiqueta, bool $mayusculas = false): ?string
{
    $v = $in[$clave] ?? null;
    if ($v === null) return null;
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    if ($v === '') return null;
    if (mb_strlen($v) > $max) error400("$etiqueta admite hasta $max caracteres");
    return $mayusculas ? mb_strtoupper($v) : $v;
}

/** Dígito verificador (módulo 11) de un RUT sin dígito. */
function dvDe(int $n): string
{
    $suma = 0;
    $f = 2;
    foreach (array_reverse(str_split((string) $n)) as $d) {
        $suma += (int) $d * $f;
        $f = $f === 7 ? 2 : $f + 1;
    }
    $r = 11 - ($suma % 11);
    return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
}

// ---- RUT ----
$rutTexto = trim((string) ($in['rut_texto'] ?? ''));
if (!preg_match('/^(\d{1,3}(?:\.?\d{3}){1,2})\s*-?\s*([\dkK])$/', $rutTexto, $m)) error400('El RUT debe ser como 12.345.678-9');
$rut = (int) str_replace('.', '', $m[1]);
$dv = strtoupper($m[2]);
if ($rut < 1000000) error400('El RUT no parece válido');
if (dvDe($rut) !== $dv) error400('El dígito verificador no coincide: el RUT ' . number_format($rut, 0, ',', '.') . ' termina en ' . dvDe($rut));

// ---- datos ----
$nombre = textoCampo($in, 'nombre', 150, 'El nombre', true);
if ($nombre === null) error400('Indica el nombre');

$mail = textoCampo($in, 'mail', 150, 'El mail');
if ($mail !== null && !filter_var($mail, FILTER_VALIDATE_EMAIL)) error400('El mail no parece válido');

$telefono = textoCampo($in, 'telefono', 30, 'El teléfono');
if ($telefono !== null && !preg_match('/^[\d\s+()\-\/.]{6,30}$/', $telefono)) error400('El teléfono solo admite números, espacios y + - ( )');

$nacimiento = $in['fecha_nacimiento'] ?? null;
if ($nacimiento === '') $nacimiento = null;
if ($nacimiento !== null) {
    $d = DateTime::createFromFormat('!Y-m-d', (string) $nacimiento);
    if (!$d || $d->format('Y-m-d') !== $nacimiento || $nacimiento < '1900-01-01' || $nacimiento > date('Y-m-d')) error400('La fecha de nacimiento no es válida');
}

$socNombre = textoCampo($in, 'sociedad_nombre', 200, 'El nombre de la sociedad');
$socRutTexto = textoCampo($in, 'sociedad_rut', 20, 'El RUT de la sociedad');
$socRut = $socDv = null;
if ($socRutTexto !== null) {
    if (!preg_match('/^(\d{1,3}(?:\.?\d{3}){1,2})\s*-\s*([\dkK])$/', $socRutTexto, $s)) error400('El RUT de la sociedad debe ser como 78.580.620-4');
    $socRut = (int) str_replace('.', '', $s[1]);
    $socDv = strtoupper($s[2]);
    if ($socNombre === null) error400('Indica también el nombre de la sociedad');
}

$otroTipo = textoCampo($in, 'otro_tipo', 60, 'El otro tipo', true);
if ($otroTipo === 'SOEMAF') $otroTipo = 'DERMO'; // el tipo SOEMAF se unió a DERMO (la póliza sigue siendo P_SOEMAF)

const DEDUCIBLE_POR_DEFECTO = 16;
$conSiniestro = !empty($in['con_siniestro']);
$deducible = null;
if ($conSiniestro) {
    $d = $in['deducible_uf'] ?? null;
    if ($d === null || $d === '') $d = DEDUCIBLE_POR_DEFECTO;
    if (!is_numeric($d) || (float) $d <= 0 || (float) $d > 9999) error400('El deducible debe ser un número de UF mayor que 0');
    $deducible = number_format((float) $d, 2, '.', '');
    $otroTipo = 'INDIVIDUAL'; // las personas con siniestro siempre son tipo INDIVIDUAL
}

$campos = [
    'especialidad' => textoCampo($in, 'especialidad', 100, 'La especialidad', true), // siempre en mayúsculas
    'mail' => $mail !== null ? mb_strtolower($mail) : null,
    'telefono' => $telefono,
    'direccion' => textoCampo($in, 'direccion', 200, 'La dirección'),
    'comuna' => textoCampo($in, 'comuna', 80, 'La comuna'),
    'ciudad' => textoCampo($in, 'ciudad', 80, 'La ciudad'),
    'ejecutivo' => textoCampo($in, 'ejecutivo', 60, 'El ejecutivo'),
    'fecha_nacimiento' => $nacimiento,
];

conManejoDeErrores(function () use ($rut, $dv, $nombre, $campos, $otroTipo, $socNombre, $socRut, $socDv, $conSiniestro, $deducible) {
    $pdo = conectarBD();
    $pdo->beginTransaction();

    $s = $pdo->prepare('SELECT nombre FROM asegurados WHERE rut = ? FOR UPDATE');
    $s->execute([$rut]);
    $existente = $s->fetchColumn();
    if ($existente !== false) {
        $pdo->rollBack();
        responder(409, ['success' => false, 'message' => "Ese RUT ya existe: $existente. Búscalo en la lista y agrégale la póliza desde su ficha.", 'rut' => $rut]);
    }

    // "otro tipo": catálogo (se agrega si es nuevo)
    $tipoId = null;
    if ($otroTipo !== null) {
        $s = $pdo->prepare('SELECT id FROM tipos_individuo WHERE nombre = ?');
        $s->execute([$otroTipo]);
        $tipoId = $s->fetchColumn();
        if ($tipoId === false) {
            $pdo->prepare('INSERT INTO tipos_individuo (nombre) VALUES (?)')->execute([$otroTipo]);
            $tipoId = $pdo->lastInsertId();
        }
        $tipoId = (int) $tipoId;
    }

    // sociedad: por RUT, si no por nombre; si no existe se crea. Sin datos = persona natural
    $sociedadId = null;
    if ($socRut !== null || $socNombre !== null) {
        if ($socRut !== null) {
            $s = $pdo->prepare('SELECT id FROM sociedades WHERE rut = ?');
            $s->execute([$socRut]);
        } else {
            $s = $pdo->prepare('SELECT id FROM sociedades WHERE nombre = ? LIMIT 1');
            $s->execute([$socNombre]);
        }
        $sociedadId = $s->fetchColumn();
        if ($sociedadId === false) {
            $pdo->prepare('INSERT INTO sociedades (rut, dv, nombre) VALUES (?,?,?)')->execute([$socRut, $socDv, $socNombre]);
            $sociedadId = $pdo->lastInsertId();
        }
        $sociedadId = (int) $sociedadId;
    }

    // Sin "otro tipo": los médicos y demás profesionales (póliza Individual o Derma) son tipo INDIVIDUAL; los dentistas quedan sin tipo
    $sugerido = $conSiniestro ? 'Individual' : productoSegunEspecialidad($campos['especialidad'], $otroTipo);
    if ($tipoId === null && in_array($sugerido, ['Individual', 'Derma'], true)) {
        $s = $pdo->prepare("SELECT id FROM tipos_individuo WHERE nombre = 'INDIVIDUAL'");
        $s->execute();
        $id = $s->fetchColumn();
        if ($id !== false) { $tipoId = (int) $id; $otroTipo = 'INDIVIDUAL'; }
    }

    $fila = ['rut' => $rut, 'dv' => $dv, 'dv_valido' => 1, 'nombre' => $nombre] + $campos
        + ['tipo_individuo_id' => $tipoId, 'sociedad_id' => $sociedadId, 'con_siniestro' => $conSiniestro ? 1 : 0, 'deducible_uf' => $deducible];
    $pdo->prepare('INSERT INTO asegurados (' . implode(', ', array_keys($fila)) . ') VALUES (' . implode(', ', array_fill(0, count($fila), '?')) . ')')
        ->execute(array_values($fila));
    $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('asegurados', ?, 'creada', NULL, ?, 'front')")
        ->execute([(string) $rut, json_encode(['nombre' => $nombre, 'otro_tipo' => $otroTipo, 'especialidad' => $campos['especialidad'], 'con_siniestro' => $conSiniestro], JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();

    responder(200, [
        'success' => true,
        'message' => 'Cliente creado',
        'rut' => $rut,
        'nombre' => $nombre,
        // Una persona con siniestro siempre va en la póliza Individual
        'producto_sugerido' => $sugerido,
        'con_siniestro' => $conSiniestro,
        'deducible_uf' => $deducible === null ? null : (float) $deducible,
    ]);
});
