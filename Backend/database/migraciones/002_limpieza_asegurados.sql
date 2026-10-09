-- =====================================================================
-- Migración 002: limpieza tras separar persona y póliza
-- =====================================================================
-- Después de la 001 los datos de la póliza viven en `polizas`, pero `asegurados` y
-- `pagos_mensuales` conservaban las columnas viejas. Aquí se quitan, para que haya UN solo
-- lugar donde está cada dato (si no, una edición en un sitio y no en el otro los desincroniza).
--
-- Solo se ejecuta cuando el código ya usa el modelo nuevo (ver scripts/migrar_limpieza.php,
-- que además comprueba que no se pierde nada). Hay respaldo previo en C:\xampp\respaldos_nominas.
-- =====================================================================

-- Una persona tiene como máximo una póliza de cada producto. El Excel madre (Odontólogo) se
-- sincroniza usando esta clave para saber cuál es "su" póliza.
ALTER TABLE polizas
    ADD UNIQUE KEY uq_poliza_rut_producto (rut, producto_id);

-- ---------------------------------------------------------------------
-- pagos_mensuales: la persona se obtiene a través de la póliza
-- ---------------------------------------------------------------------
ALTER TABLE pagos_mensuales
    DROP FOREIGN KEY fk_pago_aseg,
    DROP INDEX uq_rut_periodo,
    DROP COLUMN rut;

-- ---------------------------------------------------------------------
-- asegurados: queda solo la identidad de la persona
-- ---------------------------------------------------------------------
ALTER TABLE asegurados
    DROP FOREIGN KEY fk_aseg_tipo,
    DROP FOREIGN KEY fk_aseg_cobertura,
    DROP FOREIGN KEY fk_aseg_medio,
    DROP FOREIGN KEY fk_aseg_motivo;

ALTER TABLE asegurados
    DROP INDEX idx_estado,
    DROP INDEX idx_rut_pagador,
    DROP COLUMN tipo_id,
    DROP COLUMN poliza,
    DROP COLUMN cobertura_id,
    DROP COLUMN medio_pago_id,
    DROP COLUMN rut_pagador,
    DROP COLUMN dv_pagador,
    DROP COLUMN rut_pagador_original,
    DROP COLUMN fecha_alta,
    DROP COLUMN fecha_titulacion,
    DROP COLUMN fecha_baja,
    DROP COLUMN fecha_baja_texto,
    DROP COLUMN motivo_baja_id,
    DROP COLUMN estado,
    DROP COLUMN estado_calculado_at,
    DROP COLUMN estado_manual;

-- Reemplazada por `productos` (qué cubre la póliza) y `tipos_individuo` (grupo del Excel)
DROP TABLE tipos_asegurado;
