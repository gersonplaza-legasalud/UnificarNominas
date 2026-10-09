<?php

/**
 * Importador de la nómina maestra y del Excel de pagos por póliza (script de consola).
 *
 * Uso:
 *   --informe  NO escribe nada en la base: muestra lo que se cargaría y deja los CSV.
 *   --aplicar  carga el mismo plan en la base, en una transacción, verificando las cifras antes de confirmar.
 *              ANTES hay que hacer un respaldo (mysqldump). Para probar en una copia: DB_NAME=unificar_nominas_prueba
 *
 *   php scripts/importar_nomina_maestra.php --informe|--aplicar "<Nomina con Poliza.xlsx>" "<PAGOS POLIZAS ...xlsx>" [carpeta_de_salida]
 *
 * Imprime el resumen de lo que se cargaría y deja dos CSV en la carpeta de salida (por defecto
 * C:\xampp\respaldos_nominas\informes, fuera de htdocs porque llevan datos personales):
 *   - importacion_polizas_AAAAMMDD.csv   una fila por póliza que se crearía o actualizaría
 *   - importacion_sociedades_AAAAMMDD.csv una fila por sociedad
 *
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/ImportadorNominaMaestra.php';

use App\Services\ImportadorNominaMaestra;

ini_set('memory_limit', '4G');

$args = array_slice($argv, 1);
$modo = array_shift($args);
if (!in_array($modo, ['--informe', '--aplicar'], true) || count($args) < 2) {
    exit("Uso: php scripts/importar_nomina_maestra.php --informe|--aplicar \"<nomina.xlsx>\" \"<pagos.xlsx>\" [carpeta_de_salida]\n");
}
[$nomina, $pagos] = $args;
$salida = $args[2] ?? 'C:/xampp/respaldos_nominas/informes';
foreach ([$nomina, $pagos] as $f) {
    if (!is_file($f)) exit("No existe el archivo: $f\n");
}
if (!is_dir($salida)) mkdir($salida, 0777, true);

$inicio = microtime(true);
$pdo = conectarBD();
$importador = new ImportadorNominaMaestra($pdo);
$plan = $importador->planificar($nomina, $pagos);
$s = $plan['stats'];
$fecha = date('Ymd');

// ------------------------------------------------------------------ CSV de pólizas
$fh = fopen("$salida/importacion_polizas_$fecha.csv", 'w');
fwrite($fh, "\xEF\xBB\xBF"); // para que Excel abra bien las tildes
fputcsv($fh, ['rut', 'nombre', 'accion_persona', 'campos_que_se_completan', 'otro_tipo', 'sociedad', 'especialidad', 'accion_poliza', 'producto', 'certeza_producto',
    'numero', 'estado', 'cobertura', 'medio_pago', 'tipo_contrato', 'ultimo_periodo_desde', 'ultimo_periodo_hasta', 'periodos', 'lagunas', 'meses_con_pago', 'meses_pagados', 'fuentes'], ';');
foreach ($plan['polizas'] as $z) {
    $p = $plan['personas'][$z['rut']];
    $ultimo = $z['periodos'] ? array_key_last($z['periodos']) : null;
    $pagados = count(array_filter($z['pagos'], fn($c) => $c['pagado']));
    fputcsv($fh, [
        $z['rut'], $p['nombre'], $p['accion'], implode(',', $p['completa'] ?? []), $p['otro_tipo'] ?? '',
        isset($p['sociedad']) ? $plan['sociedades'][$p['sociedad']]['nombre'] : '', $p['especialidad'] ?? '',
        $z['accion'], $z['producto'] ?? '(sin producto)', $z['certeza_producto'] ?? '', $z['numero'], $z['estado'] ?? '', $z['cobertura'] ?? '',
        $z['medio_pago'] ?? '', $z['tipo_contrato'] ?? '', $ultimo ?? '', $ultimo ? $z['periodos'][$ultimo]['hasta'] : '', count($z['periodos']),
        implode(' | ', $z['lagunas'] ?? []), count($z['pagos']), $pagados, implode('+', array_keys($z['fuentes'])),
    ], ';');
}
fclose($fh);

// ------------------------------------------------------------------ CSV de sociedades
$fh = fopen("$salida/importacion_sociedades_$fecha.csv", 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['rut', 'dv', 'nombre', 'direccion', 'comuna', 'giro', 'personas'], ';');
foreach ($plan['sociedades'] as $soc) {
    fputcsv($fh, [$soc['rut'] ?? '', $soc['dv'] ?? '', $soc['nombre'], $soc['direccion'] ?? '', $soc['comuna'] ?? '', $soc['giro'] ?? '', $soc['personas']], ';');
}
fclose($fh);

// ------------------------------------------------------------------ resumen en pantalla
/** "a: 3 | b: 2" ordenado de mayor a menor. */
function lista(array $m, int $max = 12): string
{
    arsort($m);
    $o = [];
    foreach (array_slice($m, 0, $max, true) as $k => $v) $o[] = "$k: " . number_format($v, 0, ',', '.');
    return $o ? implode(' | ', $o) : '(ninguno)';
}
function n(int|float $v): string { return number_format($v, 0, ',', '.'); }

echo $modo === '--informe'
    ? "================ INFORME DE IMPORTACIÓN (no se escribió nada en la base) ================\n"
    : "================ PLAN DE IMPORTACIÓN (se carga a continuación) ================\n";
echo 'Archivos leídos en ' . round(microtime(true) - $inicio) . " s\n\n";

echo "FILAS LEÍDAS\n";
foreach ($s['filas_por_fuente'] as $f => $c) echo "  $f: " . n($c) . ' filas, ' . n($s['rut_por_fuente'][$f]) . ' RUT distintos' . (isset($s['filas_sin_rut_valido'][$f]) ? ' (' . n($s['filas_sin_rut_valido'][$f]) . ' filas sin RUT válido, ignoradas)' : '') . "\n";
echo "\n";

echo "PERSONAS (" . n(count($plan['personas'])) . " en total)\n";
echo '  Nuevas, se crearían:        ' . n($s['personas_nuevas']) . "\n";
echo '  Ya existen en la base:      ' . n($s['personas_existentes']) . '  (con campos vacíos que se completarían: ' . n($s['personas_existentes_con_cambios']) . ', sin cambios: ' . n($s['personas_existentes_sin_cambios']) . ")\n";
echo '  Campos que se completarían: ' . lista($s['completa_campos']) . "\n";
echo '  Dígito verificador que no coincide: ' . n($s['dv_invalido']) . "\n";
echo '  Nombres que venían como "APELLIDOS, NOMBRES" y se reordenaron: ' . n($s['nombres_reordenados']) . "\n";
echo '  Ciudad resuelta por comuna: ' . n($s['ciudades_resueltas']) . ' | comunas que no están en REGIONES (la ciudad queda igual a la comuna): ' . n($s['ciudades_sin_resolver']) . "\n";
echo '  Comunas que no están en REGIONES: ' . lista($s['comunas_sin_ciudad'], 14) . "\n\n";

echo "OTRO TIPO (descripción de la persona)\n  " . lista($s['otro_tipo']) . "\n";
echo '  Conflictos entre la nómina y el Excel de pagos (gana la nómina): ' . lista($s['otro_tipo_conflictos'], 8) . "\n\n";

echo "SOCIEDADES\n";
echo '  Se crearían: ' . n($s['sociedades']['nuevas']) . ' | ya existen: ' . n($s['sociedades']['ya_existen']) . ' | personas en una sociedad: ' . n(count(array_filter($plan['personas'], fn($p) => !empty($p['sociedad'])))) . "\n";
echo '  RUT de sociedad ilegible (se usó el nombre): ' . n(count($s['sociedades_rut_ilegible'])) . "\n\n";

echo "PÓLIZAS (" . n(count($plan['polizas'])) . " en total)\n";
echo '  Se crearían:      ' . lista($s['polizas']['CREAR']) . "\n";
echo '  Se actualizarían: ' . lista($s['polizas']['ACTUALIZAR']) . "\n";
echo '  Con número 99999 (sin póliza registrada): ' . n($s['polizas_99999']) . "\n";
echo '  Números sin producto conocido (quedan sin producto): ' . lista($s['numeros_sin_producto'], 10) . "\n";
echo "  Números sin producto y con qué \"otro tipo\" vienen (para decidir su producto):\n";
$todos = $s['numeros_sin_producto_tipos'];
uasort($todos, fn($a, $b) => array_sum($b) <=> array_sum($a));
foreach (array_slice($todos, 0, 10, true) as $num => $tipos) echo "     $num (" . n(array_sum($tipos)) . "): " . lista($tipos, 4) . "\n";
echo '  Productos deducidos de los datos (a confirmar): ' . lista($s['productos_inferidos']) . "\n";
echo '  Estado de las pólizas nuevas: ' . lista($s['estados_nuevas']) . ' (pasadas a NO VIGENTE por tener fecha de renuncia: ' . n($s['estado_corregido_por_renuncia']) . ")\n\n";

echo "PERÍODOS ANUALES\n";
echo '  Total: ' . n($s['periodos']['total']) . ' (término del Excel: ' . n($s['periodos']['termino_del_excel']) . ', término = inicio + 1 año: ' . n($s['periodos']['termino_calculado']) . ', tramos largos recortados al último año: ' . n($s['periodos_recortados']) . ")\n";
echo '  Pólizas con lagunas (>31 días entre períodos): ' . n($s['polizas_con_lagunas']) . ' | con períodos solapados: ' . n($s['polizas_con_solapes']) . "\n\n";

echo "PAGOS MENSUALES\n";
echo '  Nuevos: ' . n($s['pagos']['nuevos']) . ' (pagados: ' . n($s['pagos']['nuevos_pagados']) . ', impagos: ' . n($s['pagos']['nuevos_impagos']) . ")\n";
echo '  Ya están en la base y coinciden: ' . n($s['pagos']['ya_en_base_iguales']) . ' | distintos a la base (no se tocan): ' . n($s['pagos']['en_conflicto_no_se_tocan']) . "\n";
echo '  Celdas "PAT" en un mes (cuota pagada con PAT, contadas como pagadas): ' . n($s['celdas_pat_pagadas']) . "\n\n";

echo "Detalle en:\n  $salida/importacion_polizas_$fecha.csv\n  $salida/importacion_sociedades_$fecha.csv\n";

// ------------------------------------------------------------------ carga
if ($modo === '--aplicar') {
    $base = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "\n================ CARGANDO EN LA BASE `$base` ================\n";
    $inicio = microtime(true);
    $r = $importador->aplicar($plan);
    echo 'Listo en ' . round(microtime(true) - $inicio) . " s. Verificación aprobada antes de confirmar.\n\n";
    echo sprintf("  %-26s %10s %10s\n", '', 'antes', 'después');
    foreach (['personas', 'sociedades', 'polizas', 'periodos', 'pagos'] as $k) {
        echo sprintf("  %-26s %10s %10s\n", $k, n($r['antes'][$k]), n($r['despues'][$k]));
    }
    $i = $r['insertados'];
    echo "\n  Personas creadas: " . n($i['personas']) . ' | personas existentes completadas: ' . n($i['personas_completadas']) . "\n";
    echo '  Sociedades creadas: ' . n($i['sociedades']) . ' | pólizas creadas: ' . n($i['polizas']) . ' | pólizas existentes completadas: ' . n($i['polizas_completadas']) . "\n";
    echo '  Períodos creados: ' . n($i['periodos']) . ' | pagos cargados: ' . n($i['pagos']) . "\n";
    echo "  Los pagos y el estado de las pólizas que ya existían no cambiaron (comprobado).\n";
}
