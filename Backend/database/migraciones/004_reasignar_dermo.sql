-- =====================================================================
-- Migración 004: corrección de producto (datos)
-- =====================================================================
-- Al importar la nómina maestra, los números de póliza 28581 y 24935 se cargaron con el producto
-- SOEMAF (una deducción a partir de los datos). El usuario confirmó que ambos son del producto
-- Dermo: 28581 es la póliza Dermo vigente (vence el 01-08-2027) y 24935 la anterior.
--
-- Solo cambia el producto de esas pólizas; no toca pagos, estados ni períodos.
-- (Una persona no puede tener dos pólizas del mismo producto; como hoy no existe ninguna póliza
-- Derma, el cambio no puede chocar con la clave única (rut, producto).)

UPDATE polizas
SET producto_id = (SELECT id FROM productos WHERE nombre = 'Derma'),
    updated_at = updated_at
WHERE numero IN ('28581', '24935')
  AND producto_id = (SELECT id FROM productos WHERE nombre = 'SOEMAF');
