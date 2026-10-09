<?php

/**
 * Deja a las personas CON SINIESTRO (las que pagan deducible) solo en la póliza INDIVIDUAL y con tipo INDIVIDUAL: son los únicos
 * casos excepcionales de ese plan. Toda póliza suya de otro producto (Odontólogo, SOEMAF, Derma) se pasa a Individual:
 *   - Si la persona no tiene líneas Individual, las pólizas de UNO de esos productos (en este orden: SOEMAF, Derma, Odontólogo) se
 *     convierten en Individual conservando su posición (26158 / 24930 / 22882 / 19035), sus datos y sus pagos.
 *   - Las pólizas de los demás productos se unen a Individual: cada pago pasa a la póliza Individual que le toca por su período
 *     (la que falte se crea copiando la vigente); si ese mes ya existe, un pago real gana sobre un impago y, si no, se conserva el
 *     de Individual. Todo pago que sale queda completo en `historial_cambios`. Las pólizas de origen, ya sin pagos, se eliminan
 *     (su fila completa también queda archivada).
 *   - El tipo de la persona pasa a INDIVIDUAL y todas sus pólizas conservan/reciben su deducible.
 * Una transacción por persona, con verificación.
 *
 * Uso:
 *   php scripts/pasar_a_individual.php --informe   calcula y muestra, pero DESHACE los cambios
 *   php scripts/pasar_a_individual.php --aplicar   (hacer antes un respaldo con mysqldump)
 */

require_once __DIR__ . '/../config/database.php';

$modo = $argv[1] ?? '';
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/pasar_a_individual.php --informe|--aplicar\n");

/** Pólizas matrices de cada producto, de la más antigua a la más reciente (mismo orden = misma posición). */
const VENTANAS = [
    'Odontólogo' => [['19002', '2023-01-01', '2024-01-01'], ['22728', '2024-01-01', '2025-01-01'], ['24818', '2025-01-01', '2026-01-01'], ['26157', '2026-01-01', '2027-07-01']],
    'Individual' => [['19035', '2024-01-01', '2024-02-01'], ['22882', '2024-02-01', '2025-01-01'], ['24930', '2025-01-01', '2026-02-01'], ['26158', '2026-02-01', '2027-08-01']],
    'Derma' => [['19035', '2024-01-01', '2024-02-01'], ['22882', '2024-02-01', '2025-01-01'], ['24930', '2025-01-01', '2026-02-01'], ['26158', '2026-02-01', '2027-08-01']],
    'SOEMAF' => [['19035', '2024-01-01', '2024-02-01'], ['22883', '2024-02-01', '2025-01-01'], ['24935', '2025-01-01', '2026-02-01'], ['28581', '2026-02-01', '2027-08-01']],
];
const ORDEN_CONVERSION = ['SOEMAF', 'Derma', 'Odontólogo'];

function destinoDe(string $periodo): string
{
    $v = VENTANAS['Individual'];
    if ($periodo < $v[0][1]) return $v[0][0];
    foreach ($v as [$num, $d, $h]) if ($periodo >= $d && $periodo < $h) return $num;
    return $v[count($v) - 1][0];
}
function ventanaIndividual(string $numero): array
{
    foreach (VENTANAS['Individual'] as [$num, $d, $h]) if ($num === $numero) return [$d, $h];
    throw new RuntimeException("Sin ventana Individual para $numero");
}
/** Número Individual que ocupa la misma posición que un número de otro producto. */
function equivalente(string $producto, string $numero): ?string
{
    foreach (VENTANAS[$producto] as $i => [$num]) if ($num === $numero) return VENTANAS['Individual'][$i][0];
    // póliza de otro producto que ya lleva un número de Individual (p. ej. SOEMAF 26158 puesto a mano): se conserva
    foreach (VENTANAS['Individual'] as [$num]) if ($num === $numero) return $numero;
    return null;
}

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
$prod = $pdo->query('SELECT nombre, id FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
$nombreDe = array_flip($prod);
$INDIVIDUAL = (int) $prod['Individual'];
$tipoIndividual = (int) $pdo->query("SELECT id FROM tipos_individuo WHERE nombre = 'INDIVIDUAL'")->fetchColumn();

$personas = $pdo->query('SELECT rut, nombre FROM asegurados WHERE con_siniestro = 1 ORDER BY nombre')->fetchAll();
$log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES (?, ?, ?, ?, ?, 'pasar_a_individual')");
$resumen = [];

foreach ($personas as $per) {
    $rut = (int) $per['rut'];
    $totalAntes = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn();
    $pdo->beginTransaction();
    try {
        $r = ['nombre' => $per['nombre'], 'convertidas' => [], 'unidas' => [], 'movidos' => 0, 'sobrescritos' => 0, 'impagos_quitados' => 0, 'duplicados' => 0, 'archivados' => 0, 'tipo' => false, 'creadas' => 0, 'eliminadas' => 0];
        $otros = $pdo->query("SELECT DISTINCT producto_id FROM polizas WHERE rut = $rut AND producto_id IS NOT NULL AND producto_id <> $INDIVIDUAL")->fetchAll(PDO::FETCH_COLUMN);
        $otros = array_map(fn($id) => $nombreDe[(int) $id], $otros);
        $tieneIndividual = (int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $rut AND producto_id = $INDIVIDUAL")->fetchColumn() > 0;

        // 1) Sin líneas Individual: un producto se convierte en Individual (mismas posiciones)
        if (!$tieneIndividual && $otros) {
            $elegido = null;
            foreach (ORDEN_CONVERSION as $p) if (in_array($p, $otros, true)) { $elegido = $p; break; }
            $filas = $pdo->query("SELECT id, numero FROM polizas WHERE rut = {$rut} AND producto_id = {$prod[$elegido]} ORDER BY id")->fetchAll();
            foreach ($filas as $f) {
                $nuevo = equivalente($elegido, $f['numero']);
                if ($nuevo === null) throw new RuntimeException("La póliza {$f['id']} ($elegido {$f['numero']}) no tiene posición equivalente en Individual");
                $pdo->prepare('UPDATE polizas SET producto_id = ?, numero = ? WHERE id = ?')->execute([$INDIVIDUAL, $nuevo, $f['id']]);
                [$d, $h] = ventanaIndividual($nuevo);
                $pdo->prepare("DELETE FROM poliza_periodos WHERE poliza_id = ? AND origen = 'automatica'")->execute([$f['id']]);
                $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')")->execute([$f['id'], $nuevo, $d, $h]);
                $log->execute(['polizas', (string) $f['id'], 'producto', "$elegido {$f['numero']}", "Individual $nuevo"]);
            }
            $r['convertidas'][] = "$elegido (" . count($filas) . ')';
            $otros = array_values(array_diff($otros, [$elegido]));
        }

        // 2) Los demás productos se unen a Individual
        foreach ($otros as $origenNombre) {
            $origenId = (int) $prod[$origenNombre];
            $polizaIndividual = function (string $numero) use ($pdo, $rut, $INDIVIDUAL, $log, &$r): int {
                $s = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ?');
                $s->execute([$rut, $INDIVIDUAL, $numero]);
                $id = $s->fetchColumn();
                if ($id !== false) return (int) $id;
                $s->execute([$rut, $INDIVIDUAL, '26158']);
                $modelo = (int) $s->fetchColumn();
                if (!$modelo) throw new RuntimeException('no hay póliza Individual vigente (26158) para copiar');
                $pdo->prepare('INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, estado)
                     SELECT rut, ?, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, \'NO VIGENTE\' FROM polizas WHERE id = ?')->execute([$numero, $modelo]);
                $nuevo = (int) $pdo->lastInsertId();
                [$d, $h] = ventanaIndividual($numero);
                $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')")->execute([$nuevo, $numero, $d, $h]);
                $log->execute(['polizas', (string) $nuevo, 'creada', null, "Póliza Individual $numero creada al unir las pólizas de otro producto"]);
                $r['creadas']++;
                return $nuevo;
            };
            $ocupado = [];
            foreach ($pdo->query("SELECT p.id, p.poliza_id, p.pagado, DATE_FORMAT(p.periodo,'%Y-%m-%d') per FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE z.rut = $rut AND z.producto_id = $INDIVIDUAL") as $x) $ocupado[$x['per']] = $x;
            $ids = $pdo->query("SELECT id FROM polizas WHERE rut = $rut AND producto_id = $origenId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $enLista = implode(',', array_map('intval', $ids));
            $pagos = $pdo->query("SELECT p.*, DATE_FORMAT(p.periodo,'%Y-%m-%d') per FROM pagos_mensuales p WHERE p.poliza_id IN ($enLista) ORDER BY p.periodo, p.id")->fetchAll();
            $archivar = function (array $p, string $motivo) use ($pdo, $log, $rut, &$r): void {
                $log->execute(['pagos_mensuales', "$rut|{$p['per']}|p{$p['poliza_id']}", 'duplicado_eliminado',
                    json_encode(['pagado' => (int) $p['pagado'], 'monto' => $p['monto'] ?? null, 'valor_original' => $p['valor_original'] ?? null, 'nota' => $p['nota'] ?? null, 'origen' => $p['origen'] ?? null], JSON_UNESCAPED_UNICODE), $motivo]);
                $pdo->exec('DELETE FROM pagos_mensuales WHERE id = ' . (int) $p['id']);
                $r['archivados']++;
            };
            foreach ($pagos as $p) {
                $destinoId = $polizaIndividual(destinoDe($p['per']));
                if (isset($ocupado[$p['per']])) {
                    $d = $ocupado[$p['per']];
                    if ((int) $p['pagado'] === 1 && (int) $d['pagado'] === 0) {
                        $archivar(['id' => $d['id'], 'poliza_id' => $d['poliza_id'], 'per' => $p['per'], 'pagado' => 0], 'impago de Individual reemplazado por el pago real de otro producto');
                        $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?')->execute([$destinoId, $p['id']]);
                        $ocupado[$p['per']] = ['id' => $p['id'], 'poliza_id' => $destinoId, 'pagado' => 1];
                        $r['sobrescritos']++;
                    } else {
                        $archivar($p, "mes ya registrado en Individual; se conservó el de Individual");
                        $r[(int) $p['pagado'] === 0 ? 'impagos_quitados' : 'duplicados']++;
                    }
                } else {
                    $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?')->execute([$destinoId, $p['id']]);
                    $ocupado[$p['per']] = ['id' => $p['id'], 'poliza_id' => $destinoId, 'pagado' => (int) $p['pagado']];
                    $r['movidos']++;
                }
            }
            foreach ($ids as $id) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = $id")->fetchColumn() > 0) throw new RuntimeException("La póliza $id aún tiene pagos");
                $fila = $pdo->query("SELECT * FROM polizas WHERE id = $id")->fetch();
                $per2 = $pdo->query("SELECT numero, vigencia_desde, vigencia_hasta, origen FROM poliza_periodos WHERE poliza_id = $id")->fetchAll();
                $log->execute(['polizas', (string) $id, 'pasada_a_individual', json_encode(['poliza' => $fila, 'periodos' => $per2], JSON_UNESCAPED_UNICODE), "$origenNombre {$fila['numero']} unida a Individual"]);
                $pdo->exec("DELETE FROM poliza_periodos WHERE poliza_id = $id");
                $pdo->exec("DELETE FROM polizas WHERE id = $id");
                $r['eliminadas']++;
            }
            $r['unidas'][] = $origenNombre . ' (' . count($ids) . ')';
        }

        // 3) Tipo INDIVIDUAL y deducible en todas sus pólizas
        $tipoActual = $pdo->query("SELECT tipo_individuo_id FROM asegurados WHERE rut = $rut")->fetchColumn();
        if ((int) $tipoActual !== $tipoIndividual) {
            $antes = $pdo->query("SELECT nombre FROM tipos_individuo WHERE id = " . (int) $tipoActual)->fetchColumn() ?: 'sin tipo';
            $pdo->prepare('UPDATE asegurados SET tipo_individuo_id = ? WHERE rut = ?')->execute([$tipoIndividual, $rut]);
            $log->execute(['asegurados', (string) $rut, 'tipo_individuo', $antes, 'INDIVIDUAL']);
            $r['tipo'] = $antes;
        }
        $ded = $pdo->query("SELECT deducible_uf FROM asegurados WHERE rut = $rut")->fetchColumn();
        $pdo->prepare('UPDATE polizas SET deducible_uf = ? WHERE rut = ? AND (deducible_uf IS NULL OR deducible_uf = 0)')->execute([$ded, $rut]);

        // Verificación por persona
        if ((int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $rut AND (producto_id <> $INDIVIDUAL OR producto_id IS NULL)")->fetchColumn() > 0) throw new RuntimeException('quedaron pólizas que no son Individual');
        if ((int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $rut AND (deducible_uf IS NULL OR deducible_uf = 0)")->fetchColumn() > 0) throw new RuntimeException('quedaron pólizas sin deducible');
        $totalDespues = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn();
        if ($totalDespues !== $totalAntes - $r['archivados']) throw new RuntimeException("el total de pagos no cuadra ($totalAntes → $totalDespues, archivados {$r['archivados']})");
        $resumen[$rut] = $r;
        if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("ERROR con {$per['nombre']} ($rut): " . $e->getMessage() . "\n");
    }
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
$cambiadas = 0;
foreach ($resumen as $rut => $r) {
    if (!$r['convertidas'] && !$r['unidas'] && !$r['tipo']) continue;
    $cambiadas++;
    echo "$rut {$r['nombre']}:"
        . ($r['tipo'] ? " tipo {$r['tipo']} → INDIVIDUAL;" : '')
        . ($r['convertidas'] ? ' convertidas en Individual: ' . implode(', ', $r['convertidas']) . ';' : '')
        . ($r['unidas'] ? ' unidas a Individual: ' . implode(', ', $r['unidas']) . " (pagos movidos {$r['movidos']}, impagos reemplazados {$r['sobrescritos']}, impagos quitados {$r['impagos_quitados']}, pagados repetidos {$r['duplicados']}, pólizas creadas {$r['creadas']}, pólizas eliminadas {$r['eliminadas']})" : '')
        . "\n";
}
echo 'Personas con siniestro: ' . count($resumen) . " | con cambios: $cambiadas | ya estaban bien: " . (count($resumen) - $cambiadas) . "\n";
