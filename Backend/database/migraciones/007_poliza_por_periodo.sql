-- =====================================================================
-- Migración 007: una persona puede tener varias pólizas del mismo producto (una por período)
-- =====================================================================
-- Hasta ahora la clave única era (persona, producto): una sola póliza de Odontólogo por persona, con
-- todos sus pagos juntos. Pero cada número de póliza matriz dura 18 meses, y una persona que lleva
-- varios períodos con el mismo producto (p. ej. Odontólogo 24818 y después 26157) debe verse separada
-- por período, cada uno con su número, su vigencia y sus pagos.
--
-- La clave pasa a ser (persona, producto, número): lo que no puede haber es dos pólizas iguales.
-- (Las pólizas sin producto no chocan: MySQL no compara los NULL en una clave única.)
-- Los datos no cambian; scripts/separar_por_periodos.php hace la separación de una persona.

ALTER TABLE polizas
    DROP INDEX uq_poliza_rut_producto,
    ADD UNIQUE KEY uq_poliza_rut_producto_numero (rut, producto_id, numero);
