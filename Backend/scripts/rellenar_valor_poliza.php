<?php

/**
 * Rellena el "Valor póliza" (prima en UF) VACÍO de las pólizas Individual, Derma y SOEMAF con la tabla de primas
 * (ver scripts/cargar_primas.php): prima de la CLASE de la especialidad de la persona para el LÍMITE de la cobertura
 * de la póliza (2000, 2500, 3000, 5000 o 7000 UF), redondeada a 2 decimales como el resto de los valores.
 *
 *   - Cirujano Dentista / Odontólogo / Maxilo Facial en SOEMAF (Dermo): clase 5, 6,12 UF con límite 2000 (hoja DERMO).
 *   - Solo se rellenan los vacíos. Un valor que ya existe NO se cambia nunca; si no coincide con la tabla se lista como
 *     discrepancia (CSV en C:\xampp\respaldos_nominas\informes) para revisarlo antes.
 *   - No se rellena si la especialidad no está en la tabla, la persona no tiene especialidad o la cobertura de la póliza no
 *     es un límite numérico (RECIEN EGRESADO, SIN LEGA, SOLO LEGA, vacía): se listan aparte.
 *   - Odontólogo no entra (su prima no está en esta tabla).
 *
 * Una sola transacción; cada valor rellenado queda en `historial_cambios` (usuario 'rellenar_valor_poliza').
 *
 * Uso:
 *   php scripts/rellenar_valor_poliza.php --informe   calcula y muestra, pero DESHACE los cambios
 *   php scripts/rellenar_valor_poliza.php --aplicar   (hacer antes un respaldo con mysqldump)
 */

require_once __DIR__ . '/../config/database.php';

$modo = $argv[1] ?? '';
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/rellenar_valor_poliza.php --informe|--aplicar\n");

function clave(string $t): string
{
    $t = mb_strtolower(trim($t));
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $t));
}
function n(int $v): string { return number_format($v, 0, ',', '.'); }

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!$pdo->query("SHOW TABLES LIKE 'primas_clase'")->fetchColumn() || !(int) $pdo->query('SELECT COUNT(*) FROM primas_clase')->fetchColumn()) {
    exit("La base `$base` no tiene la tabla de primas: ejecuta scripts/cargar_primas.php primero.\n");
}

$primas = [];
foreach ($pdo->query('SELECT clase, limite_uf, prima_uf FROM primas_clase') as $r) $primas[(int) $r['clase']][(int) $r['limite_uf']] = (float) $r['prima_uf'];
$claseDe = $pdo->query('SELECT clave, clase FROM especialidades_clase')->fetchAll(PDO::FETCH_KEY_PAIR);
// Doctores con siniestro (pagan deducible): su prima es personal, no sale de la clase ni de la especialidad; no se tocan
$conSiniestro = array_flip(array_map('intval', $pdo->query('SELECT rut FROM primas_excepcion WHERE rut IS NOT NULL UNION SELECT rut FROM asegurados WHERE con_siniestro = 1')->fetchAll(PDO::FETCH_COLUMN)));

$sql = "SELECT z.id, z.rut, a.nombre, a.especialidad, pr.nombre AS producto, z.numero, z.estado, z.valor_poliza, c.nombre AS cobertura
        FROM polizas z JOIN asegurados a ON a.rut = z.rut JOIN productos pr ON pr.id = z.producto_id
        LEFT JOIN coberturas c ON c.id = z.cobertura_id
        WHERE pr.nombre IN ('Individual', 'Derma', 'SOEMAF') ORDER BY z.rut, z.id";

$llenar = [];
$cuenta = ['de doctores con siniestro (prima personal, no se tocan)' => 0, 'pólizas evaluadas' => 0, 'con valor y coincide con la tabla' => 0, 'con valor distinto a la tabla' => 0, 'vacías que se rellenan' => 0,
           'vacías sin especialidad' => 0, 'vacías con especialidad que no está en la tabla' => 0, 'vacías sin límite numérico en la cobertura' => 0];
$discrepancias = [];
$sinEspecialidad = [];
$sinLimite = [];
foreach ($pdo->query($sql) as $z) {
    if (isset($conSiniestro[(int) $z['rut']])) { $cuenta['de doctores con siniestro (prima personal, no se tocan)']++; continue; }
    $cuenta['pólizas evaluadas']++;
    $esp = trim((string) $z['especialidad']);
    $clave = clave($esp);
    $dentista = str_contains($clave, 'dentista') || str_contains($clave, 'odont') || str_contains($clave, 'maxilo');
    // Dermo (SOEMAF) de dentistas: clase 5, límite 2000
    $clase = ($z['producto'] === 'SOEMAF' && $dentista) ? 5 : ($claseDe[$clave] ?? null);
    $limite = ctype_digit((string) $z['cobertura']) ? (int) $z['cobertura'] : null;
    if ($clase === 5) $limite = 2000;
    $esperado = ($clase !== null && $limite !== null && isset($primas[$clase][$limite])) ? round($primas[$clase][$limite], 2) : null;

    if ($z['valor_poliza'] !== null) {
        if ($esperado !== null) {
            if (abs((float) $z['valor_poliza'] - $esperado) <= 0.011) $cuenta['con valor y coincide con la tabla']++;
            else { $cuenta['con valor distinto a la tabla']++; $discrepancias[] = [$z['rut'], $z['nombre'], $esp, "clase $clase", $z['producto'], $z['numero'], $z['estado'], $z['cobertura'], $z['valor_poliza'], $esperado]; }
        }
        continue;
    }
    if ($esp === '') { $cuenta['vacías sin especialidad']++; $sinEspecialidad[] = [$z['rut'], $z['nombre'], '(sin especialidad)', $z['producto'], $z['numero'], $z['estado']]; continue; }
    if ($clase === null) { $cuenta['vacías con especialidad que no está en la tabla']++; $sinEspecialidad[] = [$z['rut'], $z['nombre'], $esp, $z['producto'], $z['numero'], $z['estado']]; continue; }
    if ($esperado === null) { $cuenta['vacías sin límite numérico en la cobertura']++; $sinLimite[] = [$z['rut'], $z['nombre'], $esp, "clase $clase", $z['producto'], $z['numero'], $z['estado'], $z['cobertura'] ?? '(sin cobertura)']; continue; }
    $llenar[] = [(int) $z['id'], $esperado];
    $cuenta['vacías que se rellenan']++;
}

$excluir = $llenar ? implode(',', array_column($llenar, 0)) : '0';
$huellaExistentes = fn() => (string) $pdo->query("SELECT CONCAT(COUNT(*), '/', COALESCE(SUM(CRC32(CONCAT(id, ':', valor_poliza))), 0)) FROM polizas WHERE valor_poliza IS NOT NULL AND id NOT IN ($excluir)")->fetchColumn();
$antesExistentes = $huellaExistentes();
$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE polizas SET valor_poliza = ? WHERE id = ? AND valor_poliza IS NULL');
    $log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES ('polizas', ?, 'valor_poliza', NULL, ?, 'rellenar_valor_poliza')");
    foreach ($llenar as [$id, $valor]) {
        $upd->execute([$valor, $id]);
        $log->execute([(string) $id, (string) $valor]);
    }
    // Verificación: ningún valor que ya existía cambió
    if ($huellaExistentes() !== $antesExistentes) throw new RuntimeException('cambió un valor existente');
    $vacios = (int) $pdo->query("SELECT COUNT(*) FROM polizas z JOIN productos pr ON pr.id = z.producto_id WHERE pr.nombre IN ('Individual','Derma','SOEMAF') AND z.valor_poliza IS NULL")->fetchColumn();
    if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    exit('ERROR: ' . $e->getMessage() . "\n");
}

$carpeta = 'C:/xampp/respaldos_nominas/informes';
if (!is_dir($carpeta)) mkdir($carpeta, 0777, true);
$csv = function (string $nombre, array $cabecera, array $filas) use ($carpeta): string {
    $ruta = "$carpeta/{$nombre}_" . date('Ymd') . '.csv';
    $fh = fopen($ruta, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $cabecera, ';');
    foreach ($filas as $f) fputcsv($fh, $f, ';');
    fclose($fh);
    return $ruta;
};

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
foreach ($cuenta as $k => $v) echo '   ' . str_pad($k, 52) . n($v) . "\n";
echo "Vacías que seguirían vacías tras aplicar (Individual, Derma y SOEMAF): " . n($vacios) . "\n";
echo 'Discrepancias (no se cambian): ' . $csv('valor_poliza_discrepancias', ['rut', 'nombre', 'especialidad', 'clase', 'producto', 'numero', 'estado', 'cobertura', 'valor_actual', 'valor_tabla'], $discrepancias) . "\n";
echo 'Vacías sin especialidad válida: ' . $csv('valor_poliza_vacias_sin_especialidad', ['rut', 'nombre', 'especialidad', 'producto', 'numero', 'estado'], $sinEspecialidad) . "\n";
echo 'Vacías sin límite numérico: ' . $csv('valor_poliza_vacias_sin_limite', ['rut', 'nombre', 'especialidad', 'clase', 'producto', 'numero', 'estado', 'cobertura'], $sinLimite) . "\n";
