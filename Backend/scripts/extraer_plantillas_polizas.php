<?php

/**
 * Extrae los modelos de póliza (P_ODONTO, P_INDIVIDUAL, P_SOEMAF, P_DERMA) del Excel "PÓLIZAS_RESPALDO.xlsx" a archivos JSON
 * que usa el front para generar la póliza de cada persona.
 *
 * Uso:  php scripts/extraer_plantillas_polizas.php "<PÓLIZAS_RESPALDO.xlsx>" [carpeta de salida]
 *       (por defecto: Frontend/mi-proyecto/src/plantillas)
 *
 * Cada JSON conserva el texto del modelo TAL CUAL, fila por fila (cada fila es una lista de celdas con su texto y si va en
 * negrita). No guarda los datos del asegurado de ejemplo ni el número y la vigencia del modelo: eso lo completa el front con
 * los datos de la persona y con la tabla de pólizas matrices (en el Excel esos datos están desactualizados).
 *
 * Qué se descarta de las filas "de datos" (el front las vuelve a escribir con los valores reales):
 *   - POLIZA MATRIZ Nº, VIGENCIA y LIMITE ASEGURADO (el límite del modelo queda aparte, como `limite_uf`).
 *   - Los datos del asegurado de ejemplo (nombre, RUT, teléfono, mail, especialidad, dirección, comuna, ciudad).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$ruta = $argv[1] ?? '';
$salida = $argv[2] ?? __DIR__ . '/../../Frontend/mi-proyecto/src/plantillas';
if (!is_file($ruta)) exit("Uso: php scripts/extraer_plantillas_polizas.php \"<PÓLIZAS_RESPALDO.xlsx>\" [carpeta de salida]\n");
if (!is_dir($salida)) mkdir($salida, 0777, true);

const HOJAS = ['P_ODONTO' => 'Odontólogo', 'P_INDIVIDUAL' => 'Individual', 'P_SOEMAF' => 'SOEMAF', 'P_DERMA' => 'Derma'];

$libro = IOFactory::createReaderForFile($ruta)->load($ruta);
foreach (HOJAS as $hoja => $producto) {
    $ws = $libro->getSheetByName($hoja);
    if (!$ws) exit("El archivo no tiene la hoja $hoja.\n");

    $filas = [];
    $limite = null;
    $seccion = ''; // sección en la que estamos (para saber qué filas son del asegurado)
    foreach ($ws->getRowIterator() as $row) {
        $celdas = [];
        foreach ($row->getCellIterator() as $cell) {
            $v = $cell->getValue();
            if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $v = $v->getPlainText();
            if ($v === null || $v === '') continue;
            if (is_float($v) && floor($v) === $v) $v = (int) $v;
            $celdas[] = ['t' => rtrim((string) $v), 'b' => (bool) $cell->getStyle()->getFont()->getBold()];
        }
        if (!$celdas) continue;
        $etiqueta = mb_strtoupper(trim($celdas[0]['t'], ' :'));

        // Cambio de sección
        if (str_starts_with($etiqueta, 'ANTECEDENTES DEL ASEGURADO')) $seccion = 'asegurado';
        elseif (str_starts_with($etiqueta, 'IDENTIFICACION DE LA COMPA')) $seccion = 'aseguradora';
        elseif (str_starts_with($etiqueta, 'ANTECEDENTES DEL CONTRATANTE')) $seccion = 'contratante';

        // Filas de datos que el front completa con los valores reales
        if (str_starts_with($etiqueta, 'POLIZA MATRIZ')) { $filas[] = ['campo' => 'matriz']; continue; }
        if ($etiqueta === 'VIGENCIA') { $filas[] = ['campo' => 'vigencia']; continue; }
        if ($etiqueta === 'LIMITE ASEGURADO') {
            foreach ($celdas as $c) if (is_numeric($c['t'])) $limite = (int) $c['t'];
            $filas[] = ['campo' => 'limite'];
            continue;
        }
        if ($seccion === 'asegurado') {
            $mapa = ['ASEGURADO' => 'nombre', 'RUT' => 'rut', 'TELEFONO' => 'telefono', 'E-MAIL' => 'mail', 'ESPECIALIDAD' => 'especialidad', 'DIRECCION' => 'direccion', 'CIUDAD' => 'ciudad'];
            if (isset($mapa[$etiqueta])) { $filas[] = ['campo' => $mapa[$etiqueta]]; continue; }
        }
        $filas[] = ['seccion' => $seccion, 'celdas' => $celdas];
    }

    $json = ['plantilla' => $hoja, 'producto' => $producto, 'limite_uf' => $limite, 'filas' => $filas];
    $archivo = $salida . '/' . strtolower($hoja) . '.json';
    file_put_contents($archivo, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $campos = array_column(array_filter($filas, fn($f) => isset($f['campo'])), 'campo');
    echo sprintf("%-13s %3d filas, límite por defecto %s UF, campos variables: %s → %s\n", $hoja, count($filas), $limite ?? '?', implode(',', $campos), basename($archivo));
}
