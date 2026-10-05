-- =====================================================================
-- Esquema de Unificar Nóminas
-- =====================================================================
-- Modelo de datos (resumen):
--
--   asegurados  1 ───< N  pagos_mensuales        (una fila por persona y mes)
--   asegurados  N >─── 1  tipos_asegurado / coberturas / medios_pago / motivos_baja
--   pagos_mensuales N >─ 1 importaciones         (qué carga del Excel lo generó)
--   historial_cambios                            (bitácora de ediciones y cambios)
--
-- Reglas de negocio que el esquema refleja:
--   * Una póliza por persona: los datos de la póliza viven en `asegurados`.
--   * Lo único que importa de cada mes es si está pagado o no (`pagado`); el monto es
--     informativo porque la cobertura se cobra en UF y cambia con el tiempo.
--   * `asegurados.estado` es un campo derivado (VIGENTE / VAN A CORTE / NO VIGENTE):
--     una copia rápida del cálculo sobre `pagos_mensuales`, no la fuente de verdad.
--
-- OJO: este archivo BORRA y recrea las tablas. Se ejecuta con scripts/instalar_bd.php.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS unificar_nominas
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unificar_nominas;

-- Se desactivan las claves foráneas solo para poder borrar las tablas en cualquier orden
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS cola_hoja;
DROP TABLE IF EXISTS historial_cambios;
DROP TABLE IF EXISTS pagos_mensuales;
DROP TABLE IF EXISTS importaciones;
DROP TABLE IF EXISTS asegurados;
DROP TABLE IF EXISTS motivos_baja;
DROP TABLE IF EXISTS medios_pago;
DROP TABLE IF EXISTS coberturas;
DROP TABLE IF EXISTS tipos_asegurado;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Catálogos
-- Listas de valores que se escriben una sola vez y se referencian por id.
-- Evitan variantes del mismo dato ("PAGADO" / "pagado" / "Pagado").
-- ---------------------------------------------------------------------

-- Dentista, médico o clínica. (Hoy solo se carga la nómina de dentistas.)
CREATE TABLE tipos_asegurado (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Cobertura contratada: 3000, 5000, 7000 (UF) o RECIEN EGRESADO.
-- Se llena sola durante la sincronización con los valores que trae el Excel.
CREATE TABLE coberturas (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- PAT, PAC, MERCADO PAGO, TRANSFERENCIA, ANUALIDAD, SEMESTRE, INVALIDA, etc.
-- Se llena sola durante la sincronización (columna "ACTUALIZO MANDATO" del Excel).
CREATE TABLE medios_pago (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Motivo de baja normalizado a mayúsculas (RENUNCIA..., BAJA POR NO PAGO, etc.).
-- Se llena sola durante la sincronización.
CREATE TABLE motivos_baja (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Control
-- ---------------------------------------------------------------------

-- Una fila por cada vez que se sincroniza el Excel madre: qué archivo, cuántas filas
-- leyó y el resumen de lo que cambió (JSON en `resumen`).
CREATE TABLE importaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Hoy solo se usa 'madre'; los otros valores quedaron de la primera versión
    origen ENUM('madre','cuenta_corriente','mercado_pago','transbank') NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    -- Primer y último mes que traía el Excel
    cobertura_desde DATE NULL,
    cobertura_hasta DATE NULL,
    filas_leidas INT UNSIGNED NOT NULL DEFAULT 0,
    filas_cargadas INT UNSIGNED NOT NULL DEFAULT 0,
    resumen TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Asegurados
-- Una fila por persona (clave: su RUT). Incluye los datos de su póliza.
-- ---------------------------------------------------------------------

CREATE TABLE asegurados (
    -- RUT sin dígito verificador ni puntos (ej. 7779582)
    rut INT UNSIGNED PRIMARY KEY,
    dv CHAR(1) NOT NULL,
    -- 0 si el dígito verificador del Excel no coincide con el que se calcula del RUT
    dv_valido TINYINT(1) NOT NULL DEFAULT 1,
    nombre VARCHAR(150) NOT NULL,
    genero VARCHAR(10) NULL,
    telefono VARCHAR(30) NULL,
    mail VARCHAR(150) NULL,
    tipo_id TINYINT UNSIGNED NOT NULL,
    poliza VARCHAR(30) NULL,
    cobertura_id SMALLINT UNSIGNED NULL,
    medio_pago_id TINYINT UNSIGNED NULL,
    -- Quien paga por esta persona (puede ser otra persona o una empresa). No es clave
    -- foránea porque muchos pagadores no están en la nómina.
    rut_pagador INT UNSIGNED NULL,
    dv_pagador CHAR(1) NULL,
    -- Lo que decía literalmente la celda del Excel; a veces trae notas en vez de un RUT
    rut_pagador_original VARCHAR(30) NULL,
    fecha_alta DATE NULL,
    fecha_titulacion DATE NULL,
    fecha_baja DATE NULL,
    -- Cuando la celda de baja traía texto en vez de fecha ("VA A CORTE JULIO 2026")
    fecha_baja_texto VARCHAR(255) NULL,
    motivo_baja_id SMALLINT UNSIGNED NULL,
    -- Estado de vigencia (campo derivado, ver el encabezado de este archivo)
    estado ENUM('VIGENTE','VAN A CORTE','NO VIGENTE') NOT NULL DEFAULT 'NO VIGENTE',
    estado_calculado_at DATETIME NULL,
    -- 1 = el estado lo cambió la aplicación al registrar 3 meses seguidos sin pago. Mientras
    -- el Excel siga diciendo otra cosa, la sincronización respeta este estado; cuando el Excel
    -- coincide con él, la marca se limpia sola.
    estado_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_estado (estado),
    KEY idx_rut_pagador (rut_pagador),
    KEY idx_nombre (nombre),
    CONSTRAINT fk_aseg_tipo FOREIGN KEY (tipo_id) REFERENCES tipos_asegurado(id),
    CONSTRAINT fk_aseg_cobertura FOREIGN KEY (cobertura_id) REFERENCES coberturas(id),
    CONSTRAINT fk_aseg_medio FOREIGN KEY (medio_pago_id) REFERENCES medios_pago(id),
    CONSTRAINT fk_aseg_motivo FOREIGN KEY (motivo_baja_id) REFERENCES motivos_baja(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Pagos: una fila por persona y mes de cobertura
-- ---------------------------------------------------------------------
-- `periodo` es el primer día del mes que queda cubierto (2026-10-01 = octubre 2026),
-- que no siempre coincide con el mes en que se pagó (hay pagos adelantados y atrasados).
-- Un mes sin fila significa "sin datos" (la persona aún no estaba afiliada, por ejemplo).

CREATE TABLE pagos_mensuales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rut INT UNSIGNED NOT NULL,
    periodo DATE NOT NULL,
    pagado TINYINT(1) NOT NULL,
    -- Informativo: la cobertura se cobra en UF, así que el monto varía mes a mes
    monto INT UNSIGNED NULL,
    metodo_pago_id TINYINT UNSIGNED NULL,
    importacion_id INT UNSIGNED NULL,
    -- 'madre' = vino del Excel; 'manual' = editado desde el front
    origen ENUM('madre','archivo','manual') NOT NULL DEFAULT 'archivo',
    -- 1 = editado a mano: la sincronización con el Excel NO lo sobrescribe
    editado TINYINT(1) NOT NULL DEFAULT 0,
    -- Lo que decía la celda del Excel cuando era texto (PAGADO, falta de fondos, ...)
    valor_original VARCHAR(255) NULL,
    nota VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Impide duplicar un mes al volver a sincronizar
    UNIQUE KEY uq_rut_periodo (rut, periodo),
    KEY idx_periodo (periodo),
    CONSTRAINT fk_pago_aseg FOREIGN KEY (rut) REFERENCES asegurados(rut),
    CONSTRAINT fk_pago_metodo FOREIGN KEY (metodo_pago_id) REFERENCES medios_pago(id),
    CONSTRAINT fk_pago_import FOREIGN KEY (importacion_id) REFERENCES importaciones(id)
) ENGINE=InnoDB;

-- Bitácora: ediciones hechas desde el front, cambios que trajo la sincronización y
-- afiliaciones anteriores de personas que se dieron de baja y volvieron a entrar.
--   tabla/registro/campo identifican qué cambió (ej. 'pagos_mensuales', '7779582|2026-09-01', 'pagado').
--   usuario: 'front', 'sincronizacion' o 'migracion'.
CREATE TABLE historial_cambios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tabla VARCHAR(40) NOT NULL,
    registro VARCHAR(64) NOT NULL,
    campo VARCHAR(60) NOT NULL,
    valor_anterior TEXT NULL,
    valor_nuevo TEXT NULL,
    usuario VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_registro (tabla, registro)
) ENGINE=InnoDB;

-- Cola de cambios pendientes de escribir en la hoja de Google. Cuando se edita un mes en la app,
-- el cambio se guarda aquí y se envía al script de la hoja; si falla (sin internet, script caído)
-- queda pendiente y se reintenta, en orden, hasta que se entregue. Así no se pierde ninguna edición.
--   estado: pendiente (por enviar), enviado, fallido (se agotaron los reintentos).
CREATE TABLE cola_hoja (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payload TEXT NOT NULL,
    estado ENUM('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_at DATETIME NULL,
    KEY idx_estado (estado, id)
) ENGINE=InnoDB;

-- Datos iniciales del único catálogo que no se llena desde el Excel
INSERT INTO tipos_asegurado (nombre) VALUES ('dentista'), ('medico'), ('clinica');
