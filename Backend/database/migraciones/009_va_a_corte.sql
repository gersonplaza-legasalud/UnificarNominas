-- =====================================================================
-- Migración 009: "va a corte" deja de ser un estado y pasa a ser un aviso de la póliza
-- =====================================================================
-- Una póliza "que va a corte" sigue VIGENTE: la cobertura existe, solo hay riesgo de que se corte. Por eso el estado
-- vuelve a tener dos valores (VIGENTE / NO VIGENTE) y el riesgo se guarda aparte, en `va_a_corte` (1 = mostrar el aviso).
-- Las pólizas que estaban en VAN A CORTE quedan VIGENTE con el aviso encendido.

ALTER TABLE polizas
    ADD COLUMN va_a_corte TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;

UPDATE polizas SET va_a_corte = 1, estado = 'VIGENTE' WHERE estado = 'VAN A CORTE';

ALTER TABLE polizas
    MODIFY estado ENUM('VIGENTE','NO VIGENTE') NOT NULL DEFAULT 'NO VIGENTE';
