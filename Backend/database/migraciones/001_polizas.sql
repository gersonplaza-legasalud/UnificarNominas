-- =====================================================================
-- Migración 001: separar a la persona de sus pólizas
-- =====================================================================
-- Antes:  asegurados (una fila por RUT, con su única póliza) ──< pagos_mensuales
-- Ahora:  asegurados (persona) ──< polizas ──< pagos_mensuales
--
-- Una persona puede tener varias pólizas a la vez (p. ej. odontólogo y dermo/estética),
-- cada una con su estado y sus pagos, sin que una pise a la otra.
--
-- ESTA MIGRACIÓN SOLO AGREGA: no borra ni cambia columnas existentes de `asegurados` ni de
-- `pagos_mensuales`, para que la aplicación actual siga funcionando mientras se adapta.
-- La limpieza (quitar las columnas viejas) es una migración posterior (002).
--
-- Se ejecuta con scripts/migrar_polizas.php, que además verifica que no se perdió nada.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Catálogos nuevos
-- ---------------------------------------------------------------------

-- Qué cubre la póliza: Odontólogo, Dermo/Estética, Médico… Se agrega a medida que llegan los datos.
CREATE TABLE productos (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- El TIPO que trae el Excel de pólizas: INDIVIDUAL, DERMO, CETEP (psiquiatras), CLINICA, GREY CAPITAL…
-- Es distinto del producto: indica de qué grupo o convenio viene la persona.
CREATE TABLE tipos_individuo (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Todos los asegurados cargados hasta ahora vienen del Excel madre de dentistas
INSERT INTO productos (nombre) VALUES ('Odontólogo');

-- ---------------------------------------------------------------------
-- La especialidad es de la persona (sirve aunque tenga varias pólizas)
-- ---------------------------------------------------------------------
ALTER TABLE asegurados
    ADD COLUMN especialidad VARCHAR(100) NULL AFTER genero;

-- ---------------------------------------------------------------------
-- Pólizas: una fila por póliza de cada persona
-- ---------------------------------------------------------------------
CREATE TABLE polizas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rut INT UNSIGNED NOT NULL,
    -- Número de póliza; puede venir vacío (personas históricas) o con texto ("S/N")
    numero VARCHAR(30) NULL,
    producto_id SMALLINT UNSIGNED NULL,
    tipo_individuo_id SMALLINT UNSIGNED NULL,
    cobertura_id SMALLINT UNSIGNED NULL,
    medio_pago_id TINYINT UNSIGNED NULL,
    -- Quien paga esta póliza (otra persona o una empresa). Sin FK: muchos no están en la nómina.
    rut_pagador INT UNSIGNED NULL,
    dv_pagador CHAR(1) NULL,
    rut_pagador_original VARCHAR(30) NULL,
    fecha_alta DATE NULL,
    fecha_titulacion DATE NULL,
    fecha_baja DATE NULL,
    fecha_baja_texto VARCHAR(255) NULL,
    motivo_baja_id SMALLINT UNSIGNED NULL,
    -- Estado de vigencia de ESTA póliza (campo derivado, igual que antes en asegurados)
    estado ENUM('VIGENTE','VAN A CORTE','NO VIGENTE') NOT NULL DEFAULT 'NO VIGENTE',
    estado_calculado_at DATETIME NULL,
    estado_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_poliza_rut (rut),
    KEY idx_poliza_estado (estado),
    KEY idx_poliza_pagador (rut_pagador),
    KEY idx_poliza_numero (numero),
    CONSTRAINT fk_poliza_aseg FOREIGN KEY (rut) REFERENCES asegurados(rut),
    CONSTRAINT fk_poliza_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_poliza_tipo FOREIGN KEY (tipo_individuo_id) REFERENCES tipos_individuo(id),
    CONSTRAINT fk_poliza_cobertura FOREIGN KEY (cobertura_id) REFERENCES coberturas(id),
    CONSTRAINT fk_poliza_medio FOREIGN KEY (medio_pago_id) REFERENCES medios_pago(id),
    CONSTRAINT fk_poliza_motivo FOREIGN KEY (motivo_baja_id) REFERENCES motivos_baja(id)
) ENGINE=InnoDB;

-- Cada persona actual pasa a tener una póliza de Odontólogo con sus mismos datos
INSERT INTO polizas (
    rut, numero, producto_id, cobertura_id, medio_pago_id,
    rut_pagador, dv_pagador, rut_pagador_original,
    fecha_alta, fecha_titulacion, fecha_baja, fecha_baja_texto, motivo_baja_id,
    estado, estado_calculado_at, estado_manual, created_at, updated_at
)
SELECT
    a.rut, a.poliza, (SELECT id FROM productos WHERE nombre = 'Odontólogo'), a.cobertura_id, a.medio_pago_id,
    a.rut_pagador, a.dv_pagador, a.rut_pagador_original,
    a.fecha_alta, a.fecha_titulacion, a.fecha_baja, a.fecha_baja_texto, a.motivo_baja_id,
    a.estado, a.estado_calculado_at, a.estado_manual, a.created_at, a.updated_at
FROM asegurados a
ORDER BY a.rut;

-- ---------------------------------------------------------------------
-- Los pagos pasan a colgar de la póliza
-- ---------------------------------------------------------------------
ALTER TABLE pagos_mensuales
    ADD COLUMN poliza_id INT UNSIGNED NULL AFTER id;

-- `updated_at = updated_at` evita que MySQL lo reescriba con la hora de hoy en cada fila
UPDATE pagos_mensuales p
JOIN polizas z ON z.rut = p.rut
SET p.poliza_id = z.id, p.updated_at = p.updated_at;

ALTER TABLE pagos_mensuales
    MODIFY poliza_id INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_poliza_periodo (poliza_id, periodo),
    ADD CONSTRAINT fk_pago_poliza FOREIGN KEY (poliza_id) REFERENCES polizas(id);
