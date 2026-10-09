<?php

/**
 * GET api/catalogos.php
 *
 * Listas para los formularios del front (editar o agregar una póliza):
 *   productos   : nombres de los productos (Odontólogo, Individual, Derma, SOEMAF)
 *   coberturas  : valores usados hasta ahora (3000, 5000, SOLO LEGA…)
 *   medios_pago : valores usados hasta ahora (PAT, PAC, MERCADO PAGO…)
 *   tipos_individuo, especialidades, comunas, ciudades, ejecutivos : valores usados hasta ahora, para las
 *                 sugerencias del formulario de la persona
 *   primas      : tabla de primas (Excel PPTA PRIMAS INDIVIDUALES Y DERMO LEGASALUD) para proponer el "Valor póliza" al crear
 *                 clientes: `por_clase` {clase: {limite_uf: prima_uf}} y `especialidades` [{especialidad, clase}]. La prima
 *                 de una póliza es la de la clase de la especialidad para el límite de su cobertura.
 *   matrices    : pólizas matrices con su vigencia. `actual` = true en la vigente de cada producto;
 *                 el front la usa para proponer número y fechas al elegir un producto.
 */

require_once __DIR__ . '/_comun.php';

exigirMetodo('GET');

conManejoDeErrores(function () {
    $pdo = conectarBD();

    $matrices = $pdo->query(
        'SELECT pr.nombre AS producto, m.numero, m.vigencia_desde AS desde, m.vigencia_hasta AS hasta, m.aproximada
         FROM polizas_matrices m JOIN productos pr ON pr.id = m.producto_id
         ORDER BY pr.nombre, m.vigencia_hasta DESC'
    )->fetchAll();

    // La matriz vigente de cada producto es la de término más lejano (viene primero por el ORDER BY)
    $vistos = [];
    foreach ($matrices as &$m) {
        $m['actual'] = !isset($vistos[$m['producto']]);
        $vistos[$m['producto']] = true;
        $m['aproximada'] = (bool) $m['aproximada'];
    }
    unset($m);

    // Valores distintos de una columna de asegurados (sin vacíos), para las sugerencias de los formularios
    $distintos = fn(string $columna) => $pdo->query("SELECT DISTINCT $columna FROM asegurados WHERE $columna IS NOT NULL AND $columna <> '' ORDER BY $columna")->fetchAll(PDO::FETCH_COLUMN);

    // Tabla de primas: clase de cada especialidad y prima en UF por límite (vacía si aún no se cargó con scripts/cargar_primas.php)
    $porClase = [];
    $especialidadesClase = [];
    if ($pdo->query("SHOW TABLES LIKE 'primas_clase'")->fetchColumn()) {
        foreach ($pdo->query('SELECT clase, limite_uf, prima_uf FROM primas_clase ORDER BY clase, limite_uf') as $r) {
            $porClase[(int) $r['clase']][(int) $r['limite_uf']] = (float) $r['prima_uf'];
        }
        $especialidadesClase = array_map(fn($r) => ['especialidad' => $r['especialidad'], 'clase' => (int) $r['clase']],
            $pdo->query('SELECT especialidad, clase FROM especialidades_clase ORDER BY clase, especialidad')->fetchAll());
    }

    responder(200, [
        'success' => true,
        'productos' => $pdo->query('SELECT nombre FROM productos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        'coberturas' => $pdo->query('SELECT nombre FROM coberturas ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN),
        'medios_pago' => $pdo->query('SELECT nombre FROM medios_pago ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN),
        'tipos_individuo' => $pdo->query('SELECT nombre FROM tipos_individuo ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN),
        'especialidades' => $distintos('especialidad'),
        'comunas' => $distintos('comuna'),
        'ciudades' => $distintos('ciudad'),
        'ejecutivos' => $distintos('ejecutivo'),
        'matrices' => $matrices,
        'primas' => ['por_clase' => (object) $porClase, 'especialidades' => $especialidadesClase],
    ]);
});
