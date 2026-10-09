<?php

/**
 * Pasa al plan SOEMAF a los dentistas de tipo DERMO que tienen póliza Odontólogo VIGENTE (el plan Dermo/SOEMAF es el de los
 * dentistas que trabajan con estética: 2000 UF plano).
 *
 * Por cada persona (todas sus pólizas Odontólogo):
 *   A) Si ya tiene líneas SOEMAF (cargadas por el otro Excel, NO VIGENTE y casi vacías):
 *        - cada pago de Odontólogo pasa a la póliza SOEMAF que le toca por su período (28581, 24935, 22883 o 19035; la que falte
 *          se crea copiando la vigente). Si ese mes ya existe en SOEMAF: un pago real gana sobre un impago; si los dos están
 *          pagados o los dos impagos se conserva el de SOEMAF. Todo pago que sale queda completo en `historial_cambios`.
 *        - la póliza SOEMAF vigente (28581) toma el estado de la Odontólogo vigente (VIGENTE) y su aviso de corte; si le
 *          faltan, el inicio de cobertura, el deducible y las observaciones de la Odontólogo.
 *        - las pólizas Odontólogo, ya sin pagos, se eliminan (la fila completa queda en `historial_cambios`).
 *   B) Si NO tiene líneas SOEMAF: sus pólizas Odontólogo se convierten en SOEMAF (26157→28581, 24818→24935, 22728→22883,
 *      19002→19035), con cobertura 2000 y valor póliza 6,12 (tabla de primas). El valor Lega de Odontólogo está en pesos, no
 *      en UF, así que se deja vacío para cargarlo. Luego los pagos se reparten por las ventanas de SOEMAF
 *      (scripts/redistribuir_por_matrices.php --rut=N).
 *
 * Una transacción por persona con verificación; los pagos solo se eliminan cuando son duplicados archivados.
 *
 * Uso:
 *   php scripts/pasar_a_soemaf.php --informe   calcula y muestra, pero DESHACE los cambios
 *   php scripts/pasar_a_soemaf.php --aplicar   (hacer antes un respaldo con mysqldump)
 */

require_once __DIR__ . '/../config/database.php';

$modo = $argv[1] ?? '';
if (!in_array($modo, ['--informe', '--aplicar'], true)) exit("Uso: php scripts/pasar_a_soemaf.php --informe|--aplicar\n");

const VENTANAS_SOEMAF = [['19035', '2024-01-01', '2024-02-01'], ['22883', '2024-02-01', '2025-01-01'], ['24935', '2025-01-01', '2026-02-01'], ['28581', '2026-02-01', '2027-08-01']];
const CONVERSION = ['26157' => '28581', '24818' => '24935', '22728' => '22883', '19002' => '19035'];

function destinoDe(string $periodo): string
{
    if ($periodo < VENTANAS_SOEMAF[0][1]) return VENTANAS_SOEMAF[0][0];
    foreach (VENTANAS_SOEMAF as [$num, $d, $h]) if ($periodo >= $d && $periodo < $h) return $num;
    return VENTANAS_SOEMAF[count(VENTANAS_SOEMAF) - 1][0];
}
function periodoDe(string $numero): array
{
    foreach (VENTANAS_SOEMAF as [$num, $d, $h]) if ($num === $numero) return [$d, $h];
    throw new RuntimeException("Sin ventana para $numero");
}

$pdo = conectarBD();
$base = $pdo->query('SELECT DATABASE()')->fetchColumn();
$prod = $pdo->query('SELECT nombre, id FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
$ODONTO = (int) $prod['Odontólogo'];
$SOEMAF = (int) $prod['SOEMAF'];
$cob2000 = (int) $pdo->query("SELECT id FROM coberturas WHERE nombre = '2000'")->fetchColumn();

$personas = $pdo->query("SELECT a.rut, a.nombre FROM asegurados a JOIN tipos_individuo ti ON ti.id = a.tipo_individuo_id
                         WHERE ti.nombre = 'DERMO' AND EXISTS (SELECT 1 FROM polizas z WHERE z.rut = a.rut AND z.producto_id = $ODONTO AND z.estado = 'VIGENTE')
                         ORDER BY a.nombre")->fetchAll();

$log = $pdo->prepare("INSERT INTO historial_cambios (tabla, registro, campo, valor_anterior, valor_nuevo, usuario) VALUES (?, ?, ?, ?, ?, 'pasar_a_soemaf')");
$resumen = [];
$conversiones = [];

foreach ($personas as $per) {
    $rut = (int) $per['rut'];
    $totalAntes = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn();
    $pdo->beginTransaction();
    try {
        $soemafLineas = (int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $rut AND producto_id = $SOEMAF")->fetchColumn();
        $odontoIds = $pdo->query("SELECT id FROM polizas WHERE rut = $rut AND producto_id = $ODONTO ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $r = ['nombre' => $per['nombre'], 'caso' => $soemafLineas ? 'A (ya tenía SOEMAF)' : 'B (se convierte)', 'movidos' => 0, 'impagos_quitados' => 0, 'sobrescritos' => 0, 'conservados' => 0, 'eliminadas' => 0, 'archivados' => 0];

        if ($soemafLineas) {
            $polizaSoemaf = function (string $numero) use ($pdo, $rut, $SOEMAF, $log): int {
                $s = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ?');
                $s->execute([$rut, $SOEMAF, $numero]);
                $id = $s->fetchColumn();
                if ($id !== false) return (int) $id;
                // falta esa póliza SOEMAF: se crea copiando la vigente (NO VIGENTE, con su período)
                $vig = $pdo->prepare('SELECT id FROM polizas WHERE rut = ? AND producto_id = ? AND numero = ?');
                $vig->execute([$rut, $SOEMAF, '28581']);
                $modelo = (int) $vig->fetchColumn();
                $pdo->prepare('INSERT INTO polizas (rut, numero, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, estado)
                     SELECT rut, ?, tipo_contrato, compania, corredor, producto_id, cobertura_id, medio_pago_id, rut_pagador, dv_pagador,
                        rut_pagador_original, fecha_alta, valor_lega, valor_poliza, deducible_uf, \'NO VIGENTE\' FROM polizas WHERE id = ?')->execute([$numero, $modelo]);
                $nuevo = (int) $pdo->lastInsertId();
                [$d, $h] = periodoDe($numero);
                $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')")->execute([$nuevo, $numero, $d, $h]);
                $log->execute(['polizas', (string) $nuevo, 'creada', null, "Póliza SOEMAF $numero creada al pasar a la persona al plan SOEMAF"]);
                return $nuevo;
            };
            $ocupado = [];
            foreach ($pdo->query("SELECT p.id, p.poliza_id, DATE_FORMAT(p.periodo,'%Y-%m-%d') per, p.pagado FROM pagos_mensuales p JOIN polizas z ON z.id = p.poliza_id WHERE z.rut = $rut AND z.producto_id = $SOEMAF") as $x) $ocupado[$x['per']] = $x;
            $enLista = implode(',', array_map('intval', $odontoIds));
            $pagos = $pdo->query("SELECT p.*, DATE_FORMAT(p.periodo,'%Y-%m-%d') per FROM pagos_mensuales p WHERE p.poliza_id IN ($enLista) ORDER BY p.periodo, p.id")->fetchAll();
            $archivarPago = function (array $p, string $motivo) use ($pdo, $log, $rut, &$r): void {
                $log->execute(['pagos_mensuales', "$rut|{$p['per']}|p{$p['poliza_id']}", 'duplicado_eliminado',
                    json_encode(['pagado' => (int) $p['pagado'], 'monto' => $p['monto'], 'valor_original' => $p['valor_original'] ?? null, 'nota' => $p['nota'] ?? null, 'origen' => $p['origen'] ?? null], JSON_UNESCAPED_UNICODE), $motivo]);
                $pdo->exec('DELETE FROM pagos_mensuales WHERE id = ' . (int) $p['id']);
                $r['archivados']++;
            };
            foreach ($pagos as $p) {
                $destinoId = $polizaSoemaf(destinoDe($p['per']));
                if (isset($ocupado[$p['per']])) {
                    $d = $ocupado[$p['per']];
                    if ((int) $p['pagado'] === 1 && (int) $d['pagado'] === 0) {   // pago real sobre impago
                        $archivarPago(['id' => $d['id'], 'poliza_id' => $d['poliza_id'], 'per' => $p['per'], 'pagado' => 0, 'monto' => null], 'impago de SOEMAF reemplazado por el pago real de Odontólogo');
                        $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?')->execute([$destinoId, $p['id']]);
                        $ocupado[$p['per']] = ['id' => $p['id'], 'poliza_id' => $destinoId, 'per' => $p['per'], 'pagado' => 1];
                        $r['sobrescritos']++;
                    } else {                                                        // se conserva el de SOEMAF
                        $archivarPago($p, 'mes ya registrado en SOEMAF; se conservó el de SOEMAF');
                        $r[(int) $p['pagado'] === 0 ? 'impagos_quitados' : 'conservados']++;
                    }
                } else {
                    $pdo->prepare('UPDATE pagos_mensuales SET poliza_id = ? WHERE id = ?')->execute([$destinoId, $p['id']]);
                    $ocupado[$p['per']] = ['id' => $p['id'], 'poliza_id' => $destinoId, 'per' => $p['per'], 'pagado' => (int) $p['pagado']];
                    $r['movidos']++;
                }
            }
            // La SOEMAF vigente toma el estado de la Odontólogo vigente
            $odVig = $pdo->query("SELECT * FROM polizas WHERE rut = $rut AND producto_id = $ODONTO AND estado = 'VIGENTE' ORDER BY id DESC LIMIT 1")->fetch();
            $soVigId = $polizaSoemaf('28581');
            $so = $pdo->query("SELECT * FROM polizas WHERE id = $soVigId")->fetch();
            $sets = ["estado = 'VIGENTE'", 'va_a_corte = ' . (int) $odVig['va_a_corte'], 'estado_manual = ' . (int) $odVig['estado_manual']];
            $log->execute(['polizas', (string) $soVigId, 'estado', $so['estado'], 'VIGENTE (tomado de la póliza Odontólogo vigente)']);
            $params = [];
            foreach (['cobertura_desde', 'deducible_uf', 'observaciones', 'fecha_envio_certificado', 'fecha_envio_poliza', 'numero_certificado'] as $campo) {
                if ($so[$campo] === null && $odVig[$campo] !== null) { $sets[] = "$campo = ?"; $params[] = $odVig[$campo]; $log->execute(['polizas', (string) $soVigId, $campo, null, (string) $odVig[$campo]]); }
            }
            $pdo->prepare('UPDATE polizas SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([...$params, $soVigId]);
            // Pólizas Odontólogo, ya sin pagos: se archivan completas y se eliminan
            foreach ($odontoIds as $id) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM pagos_mensuales WHERE poliza_id = $id")->fetchColumn() > 0) throw new RuntimeException("La póliza $id aún tiene pagos");
                $fila = $pdo->query("SELECT * FROM polizas WHERE id = $id")->fetch();
                $per2 = $pdo->query("SELECT numero, vigencia_desde, vigencia_hasta, origen FROM poliza_periodos WHERE poliza_id = $id")->fetchAll();
                $log->execute(['polizas', (string) $id, 'pasada_a_soemaf', json_encode(['poliza' => $fila, 'periodos' => $per2], JSON_UNESCAPED_UNICODE), "Odontólogo {$fila['numero']} unificada en SOEMAF"]);
                $pdo->exec("DELETE FROM poliza_periodos WHERE poliza_id = $id");
                $pdo->exec("DELETE FROM polizas WHERE id = $id");
                $r['eliminadas']++;
            }
        } else {
            // B) se convierten las pólizas Odontólogo en SOEMAF
            foreach ($odontoIds as $id) {
                $fila = $pdo->query("SELECT * FROM polizas WHERE id = $id")->fetch();
                $nuevoNum = CONVERSION[$fila['numero']] ?? null;
                if ($nuevoNum === null) throw new RuntimeException("La póliza $id tiene un número ({$fila['numero']}) sin equivalente SOEMAF");
                $pdo->prepare('UPDATE polizas SET producto_id = ?, numero = ?, cobertura_id = ?, valor_poliza = COALESCE(valor_poliza, 6.12), valor_lega = NULL WHERE id = ?')->execute([$SOEMAF, $nuevoNum, $cob2000, $id]);
                [$d, $h] = periodoDe($nuevoNum);
                $pdo->prepare("DELETE FROM poliza_periodos WHERE poliza_id = ? AND origen = 'automatica'")->execute([$id]);
                $pdo->prepare("INSERT INTO poliza_periodos (poliza_id, numero, vigencia_desde, vigencia_hasta, origen) VALUES (?,?,?,?,'automatica')")->execute([$id, $nuevoNum, $d, $h]);
                $log->execute(['polizas', (string) $id, 'pasada_a_soemaf', json_encode(['producto' => 'Odontólogo', 'numero' => $fila['numero'], 'cobertura_id' => $fila['cobertura_id'], 'valor_poliza' => $fila['valor_poliza'], 'valor_lega' => $fila['valor_lega']], JSON_UNESCAPED_UNICODE),
                    "Convertida en SOEMAF $nuevoNum (cobertura 2000, valor póliza 6,12; el valor Lega en pesos se dejó vacío)"]);
                $r['eliminadas']++; // (convertidas)
            }
            $conversiones[] = $rut;
        }

        // Verificación por persona: no queda nada de Odontólogo y no se perdió ningún pago salvo los archivados
        if ((int) $pdo->query("SELECT COUNT(*) FROM polizas WHERE rut = $rut AND producto_id = $ODONTO")->fetchColumn() > 0) throw new RuntimeException('quedaron pólizas Odontólogo');
        $totalDespues = (int) $pdo->query('SELECT COUNT(*) FROM pagos_mensuales')->fetchColumn();
        // Los pagos solo bajan por los duplicados archivados: un pago real que reemplaza a un impago deja el total igual
        if ($totalDespues !== $totalAntes - $r['archivados']) throw new RuntimeException("el total de pagos no cuadra ($totalAntes → $totalDespues, archivados {$r['archivados']})");
        $resumen[$rut] = $r;
        if ($modo === '--aplicar') $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("ERROR con {$per['nombre']} ($rut): " . $e->getMessage() . "\n");
    }
}

echo $modo === '--aplicar' ? "=== APLICADO en `$base` ===\n" : "=== INFORME en `$base` (se deshizo todo: no se guardó nada) ===\n";
foreach ($resumen as $rut => $r) {
    echo str_pad("$rut {$r['nombre']}", 52) . " {$r['caso']} | pagos movidos {$r['movidos']}, impagos quitados {$r['impagos_quitados']}, impagos sobrescritos {$r['sobrescritos']}, pagados duplicados {$r['conservados']} | pólizas Odontólogo " . ($r['caso'][0] === 'A' ? 'eliminadas' : 'convertidas') . " {$r['eliminadas']}\n";
}
echo 'Conversiones (caso B) para repartir pagos por ventana: ' . implode(' ', $conversiones) . "\n";
