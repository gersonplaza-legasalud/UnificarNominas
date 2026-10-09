<?php

/**
 * POST api/persona.php   (cuerpo JSON)
 *
 * Edita los datos de UNA persona (no sus pólizas, para eso está api/poliza.php); lo usa el diálogo
 * "Editar persona" del front. El RUT no se puede cambiar.
 *
 *   {
 *     "rut": 13161636,
 *     "nombre": "JOHANA MARISEL URIBE SILVA",
 *     "especialidad", "mail", "telefono", "direccion", "comuna", "ciudad", "ejecutivo": texto o null,
 *     "fecha_nacimiento": "AAAA-MM-DD" o null,
 *     "otro_tipo": "DERMO" | null,   (SOEMAF se guarda como DERMO)
 *     "con_siniestro": true | false,     usó el seguro alguna vez: paga deducible en TODAS sus pólizas
 *     "deducible_uf": 16,                deducible en UF de la persona (vacío = 16 por defecto; se puede cambiar, p. ej. 25)
 *     "sociedad_nombre": texto o null,   (ambos vacíos = persona natural)
 *     "sociedad_rut": "78.580.620-4" o null
 *   }
 *
 * Reglas:
 *   - Sociedad: si se indica un RUT que ya existe se vincula a esa sociedad; si no existe se crea. Sin RUT
 *     se busca por nombre y, si no existe, se crea. Los datos de una sociedad existente no se modifican.
 *   - "Otro tipo" es solo una descripción (INDIVIDUAL, DERMO, SOEMAF…); si el valor no existe se agrega al catálogo.
 *   - Cada campo que cambia se registra en `historial_cambios` (valor anterior y nuevo).
 *   - Persona con siniestro: sus pólizas sin deducible reciben el de la persona (las que ya tienen uno no se tocan, porque cada
 *     póliza puede tener el suyo: p. ej. 16 en la antigua y 25 desde que subió su cobertura).
 *   - La especialidad decide qué póliza cubre a la persona: la respuesta trae `producto_sugerido` y los productos
 *     que tiene hoy, para que el front avise si no coinciden. Las pólizas no se cambian solas.
 *
 * Responde: { success, message, cambios, producto_sugerido, productos_actuales }
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

$rut = filter_var($in['rut'] ?? null, FILTER_VALIDATE_INT);
if (!$rut || $rut <= 0) error400('RUT inválido');

$nombre = textoCampo($in, 'nombre', 150, 'El nombre');
if ($nombre === null) error400('El nombre no puede quedar vacío');

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

// Sociedad: nombre y RUT (opcionales). El RUT puede venir como "78.580.620-4".
$socNombre = textoCampo($in, 'sociedad_nombre', 200, 'El nombre de la sociedad');
$socRutTexto = textoCampo($in, 'sociedad_rut', 20, 'El RUT de la sociedad');
$socRut = $socDv = null;
if ($socRutTexto !== null) {
    if (!preg_match('/^(\d{1,3}(?:\.?\d{3}){1,2})\s*-\s*([\dkK])$/', $socRutTexto, $m)) error400('El RUT de la sociedad debe ser como 78.580.620-4');
    $socRut = (int) str_replace('.', '', $m[1]);
    $socDv = strtoupper($m[2]);
    if ($socNombre === null) error400('Indica también el nombre de la sociedad');
}

$campos = [
    'nombre' => $nombre,
    'especialidad' => textoCampo($in, 'especialidad', 100, 'La especialidad', true), // siempre en mayúsculas
    'mail' => $mail !== null ? mb_strtolower($mail) : null,
    'telefono' => $telefono,
    'direccion' => textoCampo($in, 'direccion', 200, 'La dirección'),
    'comuna' => textoCampo($in, 'comuna', 80, 'La comuna'),
    'ciudad' => textoCampo($in, 'ciudad', 80, 'La ciudad'),
    'ejecutivo' => textoCampo($in, 'ejecutivo', 60, 'El ejecutivo'),
    'fecha_nacimiento' => $nacimiento,
];
$otroTipo = textoCampo($in, 'otro_tipo', 60, 'El otro tipo', true);
if ($otroTipo === 'SOEMAF') $otroTipo = 'DERMO'; // el tipo SOEMAF se unió a DERMO (la póliza sigue siendo P_SOEMAF)

// Persona con siniestro y su deducible (en UF)
const DEDUCIBLE_POR_DEFECTO = 16;
$conSiniestro = !empty($in['con_siniestro']);
$deducible = null;
if ($conSiniestro) {
    $d = $in['deducible_uf'] ?? null;
    if ($d === null || $d === '') $d = DEDUCIBLE_POR_DEFECTO;
    if (!is_numeric($d) || (float) $d <= 0 || (float) $d > 9999) error400('El deducible debe ser un número de UF mayor que 0');
    $deducible = number_format((float) $d, 2, '.', '');
}

conManejoDeErrores(function () use ($rut, $campos, $otroTipo, $socNombre, $socRut, $socDv, $conSiniestro, $deducible) {
    $pdo = conectarBD();
    $pdo->beginTransaction();

    $s = $pdo->prepare('SELECT a.*, ti.nombre AS otro_tipo, so.nombre AS sociedad_nombre, so.rut AS sociedad_rut
                        FROM asegurados a LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id LEFT JOIN sociedades so ON so.id = a.sociedad_id
                        WHERE a.rut = ? FOR UPDATE');
    $s->execute([$rut]);
    $actual = $s->fetch();
    if (!$actual) {
        $pdo->rollBack();
        responder(404, ['success' => false, 'message' => 'No existe un asegurado con ese RUT']);
    }

    // ---- "otro tipo" (catálogo; se agrega si es nuevo) ----
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

    // ---- sociedad: por RUT, si no por nombre; si no existe se crea. Sin datos = persona natural ----
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

    $nuevos = $campos + ['tipo_individuo_id' => $tipoId, 'sociedad_id' => $sociedadId, 'con_siniestro' => $conSiniestro ? 1 : 0, 'deducible_uf' => $deducible];

    // ---- se guarda solo lo que cambió, y cada cambio queda en el historial ----
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('asegurados', ?, ?, ?, ?, 'front')");
    $nombreDe = function (string $campo, mixed $valor) use ($pdo): ?string {
        if ($valor === null) return null;
        $tabla = ['tipo_individuo_id' => 'tipos_individuo', 'sociedad_id' => 'sociedades'][$campo] ?? null;
        if ($tabla === null) return (string) $valor;
        $s = $pdo->prepare("SELECT nombre FROM $tabla WHERE id = ?");
        $s->execute([$valor]);
        return $s->fetchColumn() ?: (string) $valor;
    };
    $sets = [];
    $valores = [];
    foreach ($nuevos as $campo => $valor) {
        $antes = $actual[$campo];
        $igual = ($antes === null ? null : (string) $antes) === ($valor === null ? null : (string) $valor);
        if ($igual) continue;
        $sets[] = "$campo = ?";
        $valores[] = $valor;
        $log->execute([(string) $rut, str_replace('_id', '', $campo), $nombreDe($campo, $antes), $nombreDe($campo, $valor)]);
    }
    if ($sets) {
        $valores[] = $rut;
        $pdo->prepare('UPDATE asegurados SET ' . implode(', ', $sets) . ' WHERE rut = ?')->execute($valores);
    }

    // Persona con siniestro: ninguna de sus pólizas puede quedar sin deducible (las que ya tienen uno conservan el suyo)
    $completadas = 0;
    if ($conSiniestro) {
        $s = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND (deducible_uf IS NULL OR deducible_uf = 0)');
        $s->execute([$rut]);
        $logPoliza = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'deducible_uf', NULL, ?, 'front')");
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $polizaId) {
            $pdo->prepare('UPDATE polizas SET deducible_uf = ? WHERE id = ?')->execute([$deducible, $polizaId]);
            $logPoliza->execute([(string) $polizaId, $deducible]);
            $completadas++;
        }
    }
    $pdo->commit();

    // ---- ¿qué póliza le corresponde por su especialidad, y cuáles tiene hoy? ----
    $s = $pdo->prepare('SELECT DISTINCT pr.nombre FROM polizas z JOIN productos pr ON pr.id = z.producto_id WHERE z.rut = ?');
    $s->execute([$rut]);

    responder(200, [
        'success' => true,
        'message' => $sets || $completadas ? 'Persona actualizada' : 'Sin cambios',
        'cambios' => count($sets),
        'polizas_con_deducible_completadas' => $completadas,
        // Las personas con siniestro (deducible) siempre van en la póliza Individual y con tipo INDIVIDUAL
        'producto_sugerido' => $conSiniestro ? 'Individual' : productoSegunEspecialidad($campos['especialidad'], $otroTipo),
        'productos_actuales' => $s->fetchAll(PDO::FETCH_COLUMN),
    ]);
});
