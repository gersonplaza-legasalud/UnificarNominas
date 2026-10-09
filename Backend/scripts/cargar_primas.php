<?php

/**
 * Carga en la base la tabla de primas del Excel "PPTA PRIMAS INDIVIDUALES Y DERMO LEGASALUD":
 *   - hoja 1: cada especialidad con su CLASE (1 a 7) y la prima en UF según el límite (2000, 2500, 3000, 5000, 7000);
 *   - hoja DERMO: Cirujano Dentista / Maxilo Facial con prima 6,12 UF y monto asegurado 2000 (clase 5);
 *   - hoja DOCTORES CON SINIESTRO: prima propia de algunos doctores (se liga a su RUT cuando el nombre coincide).
 * Es repetible: reemplaza el contenido de las tres tablas. Crea las tablas si faltan (migración 010).
 *
 * Uso:
 *   php scripts/cargar_primas.php "<ruta del Excel>" [--informe]     (--informe no guarda nada)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$ruta = $argv[1] ?? '';
$informe = in_array('--informe', $argv, true);
if (!is_file($ruta)) exit("Uso: php scripts/cargar_primas.php \"<Excel de primas>\" [--informe]\n");

/** Nombre sin tildes, en minúsculas y solo letras y números (clave para comparar especialidades). */
function clave(string $t): string
{
    $t = mb_strtolower(trim($t));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $t));
}

$lector = IOFactory::createReader('Xlsx');
$lector->setReadDataOnly(true);
$libro = $lector->load($ruta);

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
$pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/010_primas.sql'));

// ---- hoja 1: especialidades y primas por clase ----
$filas = $libro->getSheet(0)->toArray(null, false, false, false);
$limites = [];
foreach ([4, 5, 6, 7, 8] as $c) $limites[$c] = (int) $filas[1][$c]; // fila 2: 2000 | 2500 | 3000 | 5000 | 7000
$primas = [];
$especialidades = [];
foreach ($filas as $i => $f) {
    if ($i < 2 || !is_numeric($f[2] ?? null) || trim((string) $f[1]) === '') continue;
    $clase = (int) $f[2];
    $especialidades[clave((string) $f[1])] = [trim((string) $f[1]), $clase];
    if (is_numeric($f[4] ?? null)) foreach ($limites as $c => $lim) $primas[$clase][$lim] = (float) $f[$c];
}

// ---- hoja DERMO: Cirujano Dentista / Maxilo Facial (clase 5) ----
foreach ($libro->getSheetByName('DERMO')->toArray(null, false, false, false) as $i => $f) {
    if ($i === 0 || trim((string) $f[0]) === '') continue;
    $prima = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string) $f[2]));
    $especialidades[clave((string) $f[0])] = [trim((string) $f[0]), 5];
    $primas[5][(int) $f[3]] = $prima;
}

// ---- equivalencias: cómo se escribe en la base => nombre de la especialidad del Excel ----
// Solo las claras. Las ambiguas (COSMETOLOGA, MEDICINA ESTETICA, SALUD PUBLICA, MATRONA-ENFERMERA, odontología en Individual…) no se
// mapean: se consultan antes.
const EQUIVALENCIAS = [
    'MEDICO GENERAL' => ['MEDICO CIRUJANO', 'MEDICO CIRUJANO GENERAL', 'MEDICO GERNERAL', 'MEDICO CIRUANO', 'MEDICINA GENERAL'],
    'Dermatología' => ['DERMATOLOGO', 'MEDICO DERMATOLOGO'],
    'Farmacia/Químico Farmaceútico' => ['QUIMICO FARMACEUTICO'],
    'Pediatría' => ['PEDIATRA', 'MEDICO PEDIATRA'],
    'Anestesiología' => ['ANESTESIOLOGO'],
    'Psiquiatría' => ['PSIQUIATRA', 'PSIQUIATRIA ADULTOS', 'MEDICO CIRUJANO-PSIQUIATRA'],
    'Medicina Interna' => ['MEDICO INTERNISTA'],
    'Traumatología' => ['TRAUMATOLOGO', 'TRAUMATOLOGIA Y ORTOPEDIA', 'ORTOPEDIA Y TRAUMATOLOGIA'],
    'Oftalmología' => ['OFTALMOLOGO'],
    'Otorrinolaringología (20)' => ['OTORRINOLARINGOLOGO'],
    'Obstetricia y ginecología' => ['GINECOLOGO-OBSTETRA', 'GINECO-OBSTETRA', 'GINECOLOGO OBSTETRA', 'MEDICO GINECO OBSTETRA', 'GINECOLOGIA OBSTRETICIA'],
    'Ginecología' => ['GINECOLOGO'],
    'Cardiología' => ['CARDIOLOGO'],
    'Diagnóstico por Imagen (Radiología sin Punción)' => ['IMAGENOLOGIA'],
    'Radiología' => ['MEDICO RADIOLOGO'],
    'Enfermera' => ['ENFERMERO'],
    'Kinesiología (No Atletas Profesionales)' => ['KINESIOLOGO', 'KINESIOLOGA'],
    'Fonoaudiología' => ['FONOAUDIOLOGA', 'FONOAUDIOLOGO'],
    'Matrona' => ['MATRON'],
    'Medicina de Emergencia/Urgencia' => ['MEDICINA DE URGENCIA', 'URGENCIOLOGO', 'MEDICO URGENCIOLOGO'],
    'Bronco Pulmonar' => ['BRONCOPULMONAR'],
    'Urología' => ['UROLOGO'],
    'Gastroenterología' => ['GASTROENTEROLOGO'],
    'Psicología' => ['PSICOLOGO'],
    'Endocrinología' => ['ENDOCRINOLOGO'],
    'Neurología' => ['NEUROLOGIA ADULTO'],
    'Medicina Intensiva' => ['MEDICO INTENSIVISTA'],
    'Diabetología' => ['MEDICO DIABELOGO'],
    'Medicina Física y Rehabilitación' => ['MEDICO FISICO Y REHABILITACION', 'MEDICO ESP. EN FISICA Y REHABILITACION'],
    'Prevención Social, Comunidad y Medicina de Familia/Medicina de la Salud' => ['MEDICO FAMILIAR'],
    'Angiología y Cirugía Vascular' => ['CIRUJANO VASCULAR'],
    'Cirugía cardíaca' => ['CIRUJANO CARDIOVASCULAR'],
    'Nutrición / Nutricionista' => ['NUTRICIONISTA'],
    'Bioquímica' => ['BIOQUIMICO'],
    'Tecnología Médica' => ['TECNOLOGO MEDICO'],
];
$claseDeNombre = [];
foreach ($especialidades as [$nombre, $clase]) $claseDeNombre[$nombre] = $clase;
$equivalencias = [];
foreach (EQUIVALENCIAS as $destino => $alias) {
    if (!isset($claseDeNombre[$destino])) throw new RuntimeException("La especialidad del Excel \"$destino\" no existe (revisa EQUIVALENCIAS).");
    foreach ($alias as $a) $equivalencias[clave($a)] = [$a, $claseDeNombre[$destino], $destino];
}

$pdo->beginTransaction();
$pdo->exec('DELETE FROM primas_clase');
$pdo->exec('DELETE FROM especialidades_clase');
$pdo->exec('DELETE FROM primas_excepcion');
$insP = $pdo->prepare('INSERT INTO primas_clase (clase, limite_uf, prima_uf) VALUES (?,?,?)');
foreach ($primas as $clase => $porLimite) foreach ($porLimite as $lim => $prima) $insP->execute([$clase, $lim, $prima]);
$insE = $pdo->prepare('INSERT INTO especialidades_clase (clave, especialidad, clase) VALUES (?,?,?)');
foreach ($especialidades as $k => [$nombre, $clase]) $insE->execute([$k, $nombre, $clase]);
$insQ = $pdo->prepare('INSERT INTO especialidades_clase (clave, especialidad, clase, equivale_a) VALUES (?,?,?,?)');
foreach ($equivalencias as $k => [$nombre, $clase, $destino]) if (!isset($especialidades[$k])) $insQ->execute([$k, $nombre, $clase, $destino]);

// ---- hoja de siniestros: prima propia por doctor ----
const RUT_POR_NOMBRE = ['cesar serrano soussa' => 25448335];
$nombres = [];
foreach ($pdo->query('SELECT rut, nombre FROM asegurados') as $a) $nombres[clave($a['nombre'])][] = (int) $a['rut'];
$insX = $pdo->prepare('INSERT INTO primas_excepcion (asegurado, rut, limite_uf, deducible, prima_neta_uf) VALUES (?,?,?,?,?)');
$sinRut = [];
foreach ($libro->getSheetByName('DOCTORES CON SINIESTRO PRIMAS')->toArray(null, false, false, false) as $i => $f) {
    if ($i === 0 || trim((string) $f[0]) === '') continue;
    $nombre = trim((string) $f[0]);
    // los nombres del Excel vienen cortados o sin el segundo nombre: se busca el que empiece igual (solo si es uno)
    $k = clave($nombre);
    $tk = array_filter(explode(' ', $k));
    $coinciden = [];
    foreach ($nombres as $kn => $ruts) {
        $tn = array_filter(explode(' ', $kn));
        // mismo nombre, uno cortado, o los mismos nombres y apellidos en otro orden (ej. "FUENTES PEÑA ALBERTO")
        $mismos = !array_diff($tk, $tn) || !array_diff($tn, $tk);
        if ($kn === $k || str_starts_with($kn, $k) || str_starts_with($k, $kn) || ($mismos && min(count($tk), count($tn)) >= 3)) array_push($coinciden, ...$ruts);
    }
    $coinciden = array_values(array_unique($coinciden));
    // El Excel escribe distinto algunos nombres (SOUSSA en vez de SOUSA): el RUT se fija a mano
    $rut = RUT_POR_NOMBRE[$k] ?? (count($coinciden) === 1 ? $coinciden[0] : null);
    if ($rut === null) $sinRut[] = $nombre . (count($coinciden) > 1 ? ' (varios)' : '');
    $insX->execute([$nombre, $rut, (float) $f[1], trim((string) $f[2]) ?: null, (float) $f[3]]);
}

$resumen = 'Clases con prima: ' . implode(', ', array_keys($primas)) . ' | especialidades: ' . count($especialidades)
    . ' | excepciones por siniestro: ' . (int) $pdo->query('SELECT COUNT(*) FROM primas_excepcion')->fetchColumn()
    . ' (sin RUT: ' . ($sinRut ? implode('; ', $sinRut) : 'ninguna') . ")\n";
if ($informe) $pdo->rollBack(); else $pdo->commit();
echo ($informe ? "=== INFORME en `$base` (no se guardó nada) ===\n" : "=== CARGADO en `$base` ===\n") . $resumen;
