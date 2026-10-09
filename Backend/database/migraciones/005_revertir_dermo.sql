-- =====================================================================
-- Migración 005: se revierte la 004
-- =====================================================================
-- La 004 pasó a Derma las pólizas 28581 y 24935 según un mensaje del usuario. Después el usuario
-- aclaró la tabla definitiva: 28581 es SOEMAF, y Individual y Derma comparten el 26158.
-- Se devuelven esas pólizas al producto SOEMAF, que era el que tenían tras la importación.
--
-- Solo cambia el producto; no toca pagos, estados ni períodos.

UPDATE polizas
SET producto_id = (SELECT id FROM productos WHERE nombre = 'SOEMAF'),
    updated_at = updated_at
WHERE numero IN ('28581', '24935')
  AND producto_id = (SELECT id FROM productos WHERE nombre = 'Derma');
