-- =====================================================================
-- Migración 011: personas con siniestro (pagan deducible)
-- =====================================================================
-- Quien usó el seguro alguna vez es una persona con siniestro y paga deducible en TODAS sus pólizas (las antiguas y las
-- siguientes). Se marca en la persona y se guarda su deducible en UF (por defecto 16; se puede cambiar, p. ej. 25 si subió
-- su cobertura). Cada póliza conserva su propio deducible_uf, y mientras la persona esté marcada ninguna póliza puede quedar sin él.

ALTER TABLE asegurados
    ADD COLUMN IF NOT EXISTS con_siniestro TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS deducible_uf DECIMAL(6,2) NULL;
