<?php

/**
 * Carga el INICIO DE LA COBERTURA de cada póliza desde los Excel.
 *
 * La cobertura es lo que la persona contrató: dura 12 meses desde su inicio y se renueva sola cada año mientras no renuncie
 * ni rechace el pago. No es lo mismo que la póliza matriz (el número legal, que cubre 18 meses). Fuentes, por prioridad:
 *   1. Excel de pagos ("PAGOS POLIZAS MENSUALES INDIVIDUAL ...xlsx"): columna INICIO VIGENCIA → póliza Individual / Derma / SOEMAF.
 *      Son fechas individuales, una por persona; es la fuente que manda.
 *   2. Nómina maestra ("Nomina con Poliza.xlsx"):
 *        hoja INDIVIDUALES, "INICIO VIGENCIA"                → póliza Individual / Derma / SOEMAF (solo si el Excel de pagos no la trae)
 *        hoja ODONTOLOGOS, "FECHA DE INICIO DE POLIZA"       → póliza Odontólogo
 * De cada persona se toma la fila más reciente de cada archivo. Un tramo de más de 13 meses no es una cobertura anual: se toma
 * su último año (término - 1 año). Solo se usan pólizas con el número vigente de su producto.
 *
 * Uso:
 *   php scripts/cargar_cobertura.php --informe|--aplicar "<Nomina con Poliza.xlsx>" ["<PAGOS ...xlsx>"] [--sobrescribir] [--rut=<RUT>]
 *   DB_NAME=unificar_nominas_prueba php scripts/cargar_cobertura.php --aplicar ...   (probar en una copia)
 *
 *   --informe      calcula y muestra, sin guardar.
 *   --sobrescribir además de completar las pólizas sin inicio, CORRIGE las que ya tienen uno distinto del que dice la fuente
 *                  (cada corrección queda en `historial_cambios`). Nunca pisa lo que se editó a mano desde "Editar póliza".
 *   --rut=<RUT>    solo esa persona.
 *
 * No toca pagos ni estados.
 */

require_once __DIR__ . '/../api/_comun.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

ini_set('memory_limit', '4G');

$args = array_slice($argv, 1);
$modo = array_shift($args);
$archivos = [];
$sobrescribir = false;
$soloRut = null;
foreach ($args as $a) {
    if ($a === '--sobrescribir') $sobrescribir = true;
    elseif (preg_match('/^--rut=(\d+)$/', $a, $m)) $soloRut = (int) $m[1];
    elseif (is_file($a)) $archivos[] = $a;
}
if (!in_array($modo, ['--informe', '--aplicar'], true) || !$archivos) {
    exit("Uso: php scripts/cargar_cobertura.php --informe|--aplicar \"<Nomina con Poliza.xlsx>\" [\"<PAGOS ...xlsx>\"] [--sobrescribir] [--rut=<RUT>]\n");
}

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'polizas_matrices'")->fetchColumn()) exit("La base `$base` no tiene la migración 006.\n");
$tieneColumna = (bool) $pdo->query("SHOW COLUMNS FROM polizas LIKE 'cobertura_desde'")->fetchColumn();
if (!$tieneColumna && $modo === '--aplicar') {
    $pdo->exec(file_get_contents(__DIR__ . '/../database/migraciones/008_cobertura.sql'));
    $tieneColumna = true;
    echo "Columna polizas.cobertura_desde creada.\n";
}

function n(int $v): string { return number_format($v, 0, ',', '.'); }
$fecha = fn($v) => (is_numeric($v) && $v > 20000 && $v < 60000) ? Date::excelToDateTimeObject($v)->format('Y-m-d') : null;

/** Inicio de cobertura de una fila: su inicio, o el último año si el tramo dura más de 13 meses. */
$ancla = function (?string $inicio, ?string $termino): ?string {
    if ($inicio === null) return null;
    if ($termino !== null && $termino > $inicio && (strtotime($termino) - strtotime($inicio)) / 86400 > 400) return date('Y-m-d', strtotime($termino . ' -1 year'));
    return $inicio;
};

// ---- Lectura: la fila más reciente de cada persona ----
$pagos = []; $ind = []; $odo = []; // rut => inicio de cobertura
foreach ($archivos as $ruta) {
    $reader = IOFactory::createReaderForFile($ruta);
    $reader->setReadDataOnly(true);
    $hojas = $reader->listWorksheetNames($ruta);
    if (in_array('ODONTOLOGOS', $hojas, true) && in_array('INDIVIDUALES', $hojas, true)) { // nómina maestra
        $reader->setLoadSheetsOnly(['ODONTOLOGOS', 'INDIVIDUALES']);
        $wb = $reader->load($ruta);
        $mejor = [];
        foreach ($wb->getSheetByName('ODONTOLOGOS')->toArray(null, true, false, false) as $x) {
            if (!is_numeric($x[0]) || !($ini = $fecha($x[20]))) continue;
            if (!isset($mejor[(int) $x[0]]) || $ini > $mejor[(int) $x[0]][0]) $mejor[(int) $x[0]] = [$ini, $ancla($ini, $fecha($x[21]))];
        }
        foreach ($mejor as $r => [, $a]) $odo[$r] = $a;
        $mejor = [];
        foreach ($wb->getSheetByName('INDIVIDUALES')->toArray(null, true, false, false) as $x) {
            if (!is_numeric($x[1]) || !($ini = $fecha($x[39]))) continue;
            if (!isset($mejor[(int) $x[1]]) || $ini > $mejor[(int) $x[1]][0]) $mejor[(int) $x[1]] = [$ini, $ancla($ini, $fecha($x[40]))];
        }
        foreach ($mejor as $r => [, $a]) $ind[$r] = $a;
        unset($wb);
    } else { // Excel de pagos: una sola hoja; RUT en la columna O, INICIO / TERMINO VIGENCIA en F y G
        foreach ($reader->load($ruta)->getActiveSheet()->toArray(null, true, false, false) as $x) {
            if (!is_numeric($x[14] ?? null) || !$x[14] || !($ini = $fecha($x[5]))) continue;
            $pagos[(int) $x[14]] = $ancla($ini, $fecha($x[6])); // las filas de más abajo son las más recientes
        }
    }
}

// ---- Pólizas con el número vigente de su producto ----
$sql = 'SELECT z.id, z.rut, pr.nombre AS producto, z.numero, z.estado, ' . ($tieneColumna ? 'z.cobertura_desde' : 'NULL AS cobertura_desde') . ',
               a.especialidad, ti.nombre AS otro_tipo
        FROM polizas z
        JOIN productos pr ON pr.id = z.producto_id
        JOIN polizas_matrices m ON m.producto_id = z.producto_id AND m.numero = z.numero
             AND m.vigencia_hasta = (SELECT MAX(vigencia_hasta) FROM polizas_matrices WHERE producto_id = z.producto_id)
        JOIN asegurados a ON a.rut = z.rut
        LEFT JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id' . ($soloRut ? " WHERE z.rut = $soloRut" : '');
$vigentes = [];
foreach ($pdo->query($sql) as $z) $vigentes[(int) $z['rut']][] = $z;

// Pólizas cuyo inicio de cobertura se editó a mano: no se tocan
$editadas = [];
if ($tieneColumna) {
    foreach ($pdo->query("SELECT DISTINCT registro FROM historial_cambios WHERE tabla = 'polizas' AND campo = 'cobertura_desde' AND usuario = 'front'") as $r) $editadas[(int) $r['registro']] = true;
}

/** A qué póliza va el inicio de una persona: la Odontólogo, o la no-Odontólogo que corresponde a su especialidad. */
$elegir = function (int $rut, bool $odontologo) use ($vigentes) {
    $cand = array_values(array_filter($vigentes[$rut] ?? [], fn($z) => ($z['producto'] === 'Odontólogo') === $odontologo));
    if (!$cand) return null;
    if (count($cand) > 1) {
        $deseado = productoSegunEspecialidad($cand[0]['especialidad'], $cand[0]['otro_tipo']);
        foreach ($cand as $c) if ($c['producto'] === $deseado) return $c;
    }
    return $cand[0];
};

$plan = []; // id de póliza => [inicio, fuente, póliza]
$stats = ['sin_poliza' => ['pagos' => 0, 'nómina INDIVIDUALES' => 0, 'nómina ODONTOLOGOS' => 0]];
$fuentes = [['pagos', $pagos, false], ['nómina INDIVIDUALES', $ind, false], ['nómina ODONTOLOGOS', $odo, true]];
foreach ($fuentes as [$nombre, $datos, $odontologo]) { // el orden es la prioridad: lo que ya está en el plan no se pisa
    foreach ($datos as $rut => $inicio) {
        if ($soloRut && $rut !== $soloRut) continue;
        $z = $elegir($rut, $odontologo);
        if (!$z) { $stats['sin_poliza'][$nombre]++; continue; }
        if (!isset($plan[$z['id']])) $plan[$z['id']] = [$inicio, $nombre, $z];
    }
}

$escribir = []; // id => [anterior, nuevo, fuente]
$c = ['completa' => 0, 'corrige' => 0, 'igual' => 0, 'protegida' => 0, 'distinta_sin_sobrescribir' => 0, 'por_fuente' => []];
foreach ($plan as $id => [$inicio, $fuente, $z]) {
    $actual = $z['cobertura_desde'];
    if ($actual === null) { $escribir[$id] = [null, $inicio, $fuente]; $c['completa']++; }
    elseif ($actual === $inicio) $c['igual']++;
    elseif (isset($editadas[$id])) $c['protegida']++;
    elseif ($sobrescribir) { $escribir[$id] = [$actual, $inicio, $fuente]; $c['corrige']++; }
    else $c['distinta_sin_sobrescribir']++;
    if (isset($escribir[$id])) $c['por_fuente'][$fuente] = ($c['por_fuente'][$fuente] ?? 0) + 1;
}

// ---- Guardar ----
$antes = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn();
if ($modo === '--aplicar' && $escribir) {
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE polizas SET cobertura_desde = ?, updated_at = updated_at WHERE id = ?');
        $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'cobertura_desde', ?, ?, 'cargar_cobertura')");
        foreach ($escribir as $id => [$anterior, $nuevo, $fuente]) {
            $upd->execute([$nuevo, $id]);
            if ($anterior !== null) $log->execute([(string) $id, $anterior, "$nuevo (según $fuente)"]);
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn() !== $antes) throw new RuntimeException('Cambió el total de pagos.');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        exit('ERROR: ' . $e->getMessage() . "\n");
    }
}

// ---- Informe ----
$hoy = date('Y-m-d');
$sin = 0; $conRenovacion = 0; $vigentesTotal = 0;
foreach ($vigentes as $lista) foreach ($lista as $z) {
    if (!in_array($z['estado'], ['VIGENTE', 'VAN A CORTE'], true)) continue;
    $vigentesTotal++;
    $inicio = isset($escribir[$z['id']]) ? $escribir[$z['id']][1] : $z['cobertura_desde'];
    if ($inicio === null) { $sin++; continue; }
    if (coberturaActual($inicio, $z['estado'], $hoy)['renovada']) $conRenovacion++;
}
echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (no se guardó nada) ===\n";
echo 'Fuentes: Excel de pagos ' . n(count($pagos)) . ' personas con inicio | nómina INDIVIDUALES ' . n(count($ind)) . ' | nómina ODONTOLOGOS ' . n(count($odo)) . ($soloRut ? " | solo el RUT $soloRut" : '') . "\n";
echo 'Pólizas que se completan (no tenían inicio): ' . n($c['completa']) . ' | que se corrigen (tenían otro): ' . n($c['corrige']) . ' | ya iguales: ' . n($c['igual']) . ' | editadas a mano (no se tocan): ' . n($c['protegida']) . "\n";
if ($c['distinta_sin_sobrescribir']) echo '  Con inicio distinto al de la fuente, SIN corregir (usa --sobrescribir): ' . n($c['distinta_sin_sobrescribir']) . "\n";
echo '  Según qué fuente: ' . json_encode($c['por_fuente'], JSON_UNESCAPED_UNICODE) . ' | personas de la fuente sin póliza vigente donde ponerlo: ' . json_encode($stats['sin_poliza'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'Pólizas VIGENTES: ' . n($vigentesTotal) . ' | con cobertura calculada: ' . n($vigentesTotal - $sin) . ' (' . n($conRenovacion) . ' renovadas solas por haber pasado su año) | sin inicio de cobertura: ' . n($sin) . "\n";
