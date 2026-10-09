-- =====================================================================
-- Migración 003: ficha completa del cliente (sociedad, datos de contacto, períodos de póliza)
-- =====================================================================
-- Prepara la base para cargar la nómina maestra ("Nomina con Poliza.xlsx") y para la regla de
-- las pólizas anuales:
--   * Una póliza dura 12 meses y se paga mes a mes (12 cuotas).
--   * Si se pagaron las 12 cuotas, se renueva sola (nuevo período desde el término).
--   * Si quedaron cuotas impagas hay deuda: no se renueva hasta pagarla.
--   * La renuncia es de UNA póliza, no de la persona (puede haber pasado a otra póliza).
--
-- Esta migración solo cambia la estructura (y mueve `tipo_individuo` a la persona). Los datos
-- de contacto, sociedades y períodos los carga el importador de la nómina maestra, aparte.
-- Verificada por scripts/migrar_ficha_completa.php.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Sociedades: una sociedad puede agrupar a varias personas
-- ---------------------------------------------------------------------
CREATE TABLE sociedades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- RUT de la sociedad sin dígito verificador; puede faltar (sociedad conocida solo por su nombre)
    rut INT UNSIGNED NULL,
    dv CHAR(1) NULL,
    nombre VARCHAR(200) NOT NULL,
    direccion VARCHAR(200) NULL,
    comuna VARCHAR(80) NULL,
    giro VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sociedad_rut (rut),
    KEY idx_sociedad_nombre (nombre)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Persona: contacto, sociedad y "otro tipo" (INDIVIDUAL, DERMO, SOEMAF, CETEP, SOLO LEGA…)
-- ---------------------------------------------------------------------
ALTER TABLE asegurados
    ADD COLUMN fecha_nacimiento DATE NULL AFTER genero,
    ADD COLUMN direccion VARCHAR(200) NULL AFTER mail,
    ADD COLUMN comuna VARCHAR(80) NULL AFTER direccion,
    ADD COLUMN ciudad VARCHAR(80) NULL AFTER comuna,
    -- Ejecutivo de Legasalud que atiende a la persona
    ADD COLUMN ejecutivo VARCHAR(60) NULL AFTER ciudad,
    -- NULL = persona natural; si está en una sociedad, cuál
    ADD COLUMN sociedad_id INT UNSIGNED NULL AFTER ejecutivo,
    -- Descripción de la persona (no define el producto ni la póliza)
    ADD COLUMN tipo_individuo_id SMALLINT UNSIGNED NULL AFTER sociedad_id,
    ADD KEY idx_aseg_sociedad (sociedad_id),
    ADD KEY idx_aseg_tipo_individuo (tipo_individuo_id),
    ADD CONSTRAINT fk_aseg_sociedad FOREIGN KEY (sociedad_id) REFERENCES sociedades(id),
    ADD CONSTRAINT fk_aseg_tipo_ind FOREIGN KEY (tipo_individuo_id) REFERENCES tipos_individuo(id);

-- El "otro tipo" estaba en la póliza; pasa a la persona (si alguna ya lo tenía, se conserva)
UPDATE asegurados a
JOIN polizas z ON z.rut = a.rut
SET a.tipo_individuo_id = z.tipo_individuo_id, a.updated_at = a.updated_at
WHERE z.tipo_individuo_id IS NOT NULL;

ALTER TABLE polizas
    DROP FOREIGN KEY fk_poliza_tipo,
    DROP COLUMN tipo_individuo_id;

-- ---------------------------------------------------------------------
-- Póliza: condiciones del contrato
-- ---------------------------------------------------------------------
ALTER TABLE polizas
    -- LEGA + SEGURO, COLEGIO + SEGURO, SOLO COLEGIO, SOLO LEGASALUD…
    ADD COLUMN tipo_contrato VARCHAR(40) NULL AFTER numero,
    ADD COLUMN compania VARCHAR(40) NULL AFTER tipo_contrato,
    ADD COLUMN corredor VARCHAR(60) NULL AFTER compania,
    -- Los valores vienen en la moneda de cada hoja de origen (pesos o UF)
    ADD COLUMN valor_lega DECIMAL(12,2) NULL,
    ADD COLUMN valor_poliza DECIMAL(12,2) NULL,
    ADD COLUMN deducible_uf DECIMAL(6,2) NULL,
    -- Renuncia a ESTA póliza (la persona puede haber pasado a otra)
    ADD COLUMN fecha_renuncia DATE NULL,
    ADD COLUMN fecha_envio_certificado DATE NULL,
    ADD COLUMN fecha_envio_poliza DATE NULL,
    ADD COLUMN numero_certificado VARCHAR(30) NULL,
    ADD COLUMN observaciones TEXT NULL;

-- ---------------------------------------------------------------------
-- Períodos anuales de cada póliza
-- ---------------------------------------------------------------------
-- Cada fila es un año de cobertura: desde-hasta y el número de póliza matriz de ese año (el
-- número cambia al renovar). Las cuotas se ubican en su período por el mes; la deuda se calcula
-- como las cuotas impagas de los períodos ya terminados. `polizas.numero` queda como el número
-- del período actual.
--   origen: 'nomina' (viene del Excel), 'automatica' (renovación al pagarse las 12 cuotas),
--           'manual' (agregado desde la aplicación).
CREATE TABLE poliza_periodos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poliza_id INT UNSIGNED NOT NULL,
    numero VARCHAR(30) NULL,
    vigencia_desde DATE NOT NULL,
    vigencia_hasta DATE NOT NULL,
    origen ENUM('nomina','automatica','manual') NOT NULL DEFAULT 'nomina',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_periodo_poliza_desde (poliza_id, vigencia_desde),
    KEY idx_periodo_hasta (vigencia_hasta),
    CONSTRAINT fk_periodo_poliza FOREIGN KEY (poliza_id) REFERENCES polizas(id),
    CONSTRAINT ck_periodo_fechas CHECK (vigencia_hasta > vigencia_desde)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Productos de las cuatro pólizas matrices (el número de póliza cambia cada año; el producto no)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO productos (nombre) VALUES ('Individual'), ('SOEMAF'), ('Derma');
