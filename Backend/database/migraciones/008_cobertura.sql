-- =====================================================================
-- Migración 008: inicio de la cobertura de cada póliza
-- =====================================================================
-- La cobertura de la persona (lo que contrató: 12 meses desde su inicio, renovada sola cada año mientras no
-- renuncie ni rechace el pago) es distinta de la póliza matriz (el número legal, que cubre un período de 18
-- meses). Ejemplo: Diego contrató en enero de 2026; su cobertura va del 01-01-2026 al 01-01-2027, aunque
-- esa fecha cae en la póliza anterior (24930); el certificado lleva esa vigencia y el número de la última
-- póliza vigente (26158).
--
-- Se guarda solo el INICIO de alguna cobertura de la persona; la ventana vigente se calcula sumando años
-- (ver coberturaActual en api/_comun.php). Lo carga scripts/cargar_cobertura.php desde la nómina
-- (INICIO DE VIGENCIA) y se puede corregir a mano desde "Editar póliza".

ALTER TABLE polizas
    ADD COLUMN cobertura_desde DATE NULL AFTER fecha_alta;
