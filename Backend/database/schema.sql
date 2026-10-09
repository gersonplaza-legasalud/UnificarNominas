-- =====================================================================
-- Esquema de Unificar Nóminas
-- =====================================================================
-- Modelo de datos (resumen):
--
--   sociedades  1 ───< N  asegurados             (una sociedad puede agrupar a varias personas)
--   asegurados  1 ───< N  polizas                (una persona puede tener varias pólizas)
--   polizas     1 ───< N  poliza_periodos        (un año de cobertura: desde-hasta y número de póliza)
--   polizas     1 ───< N  pagos_mensuales        (una fila por póliza y mes)
--   asegurados  N >─── 1  tipos_individuo        ("otro tipo": INDIVIDUAL, DERMO, SOEMAF, CETEP…)
--   polizas     N >─── 1  productos / coberturas / medios_pago / motivos_baja
--   pagos_mensuales N >─ 1 importaciones         (qué carga del Excel lo generó)
--   historial_cambios                            (bitácora de ediciones y cambios)
--
-- Reglas de negocio que el esquema refleja:
--   * La persona (`asegurados`) y sus pólizas (`polizas`) son cosas distintas: una misma persona
--     puede tener, por ejemplo, una póliza de Odontólogo y otra de Dermo/Estética, cada una con
--     su estado y sus pagos, sin que una pise a la otra. Una persona puede tener varias del mismo producto si son de
--     distinto período (distinto número de póliza matriz), cada una con su vigencia y sus pagos.
--   * Una póliza dura 12 meses y se paga mes a mes. Si se pagan las 12 cuotas se renueva sola;
--     si quedan impagas hay deuda y no se renueva hasta pagarla. La renuncia es de UNA póliza.
--   * Lo único que importa de cada mes es si está pagado o no (`pagado`); el monto es
--     informativo porque la cobertura se cobra en UF y cambia con el tiempo.
--   * `polizas.estado` es un campo derivado (VIGENTE / NO VIGENTE; el riesgo de corte es el aviso `va_a_corte`):
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
DROP TABLE IF EXISTS poliza_periodos;
DROP TABLE IF EXISTS polizas;
DROP TABLE IF EXISTS asegurados;
DROP TABLE IF EXISTS motivos_baja;
DROP TABLE IF EXISTS medios_pago;
DROP TABLE IF EXISTS coberturas;
DROP TABLE IF EXISTS tipos_individuo;
DROP TABLE IF EXISTS sociedades;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS tipos_asegurado;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Catálogos
-- Listas de valores que se escriben una sola vez y se referencian por id.
-- Evitan variantes del mismo dato ("PAGADO" / "pagado" / "Pagado").
-- ---------------------------------------------------------------------

-- Qué cubre la póliza: Odontólogo, Dermo/Estética, Médico… Se agrega a medida que llegan los datos.
-- 'Odontólogo' es el de las pólizas que vienen del Excel madre de dentistas.
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
-- Asegurados: una fila por persona (clave: su RUT)
-- ---------------------------------------------------------------------

CREATE TABLE asegurados (
    -- RUT sin dígito verificador ni puntos (ej. 7779582)
    rut INT UNSIGNED PRIMARY KEY,
    dv CHAR(1) NOT NULL,
    -- 0 si el dígito verificador del Excel no coincide con el que se calcula del RUT
    dv_valido TINYINT(1) NOT NULL DEFAULT 1,
    nombre VARCHAR(150) NOT NULL,
    genero VARCHAR(10) NULL,
    fecha_nacimiento DATE NULL,
    -- Especialidad médica u odontológica (Dermatología, Pediatría…). Es de la persona, no de la póliza.
    especialidad VARCHAR(100) NULL,
    -- Persona con siniestro: pagó un siniestro alguna vez y paga deducible en TODAS sus pólizas (por defecto 16 UF, editable)
    con_siniestro TINYINT(1) NOT NULL DEFAULT 0,
    deducible_uf DECIMAL(6,2) NULL,
    telefono VARCHAR(30) NULL,
    mail VARCHAR(150) NULL,
    direccion VARCHAR(200) NULL,
    comuna VARCHAR(80) NULL,
    ciudad VARCHAR(80) NULL,
    -- Ejecutivo de Legasalud que atiende a la persona
    ejecutivo VARCHAR(60) NULL,
    -- NULL = persona natural; si está en una sociedad, cuál
    sociedad_id INT UNSIGNED NULL,
    -- "Otro tipo": descripción de la persona (INDIVIDUAL, DERMO, SOEMAF, CETEP, SOLO LEGA…).
    -- No define el producto ni la póliza.
    tipo_individuo_id SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_nombre (nombre),
    KEY idx_aseg_sociedad (sociedad_id),
    KEY idx_aseg_tipo_individuo (tipo_individuo_id),
    CONSTRAINT fk_aseg_sociedad FOREIGN KEY (sociedad_id) REFERENCES sociedades(id),
    CONSTRAINT fk_aseg_tipo_ind FOREIGN KEY (tipo_individuo_id) REFERENCES tipos_individuo(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Pólizas: una fila por póliza de cada persona (una por producto y número de póliza)
-- ---------------------------------------------------------------------

CREATE TABLE polizas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rut INT UNSIGNED NOT NULL,
    -- Número de póliza; puede venir vacío (personas históricas) o con texto ("S/N")
    numero VARCHAR(30) NULL,
    -- LEGA + SEGURO, COLEGIO + SEGURO, SOLO COLEGIO, SOLO LEGASALUD…
    tipo_contrato VARCHAR(40) NULL,
    compania VARCHAR(40) NULL,
    corredor VARCHAR(60) NULL,
    producto_id SMALLINT UNSIGNED NULL,
    cobertura_id SMALLINT UNSIGNED NULL,
    medio_pago_id TINYINT UNSIGNED NULL,
    -- Quien paga esta póliza (puede ser otra persona o una empresa). No es clave foránea
    -- porque muchos pagadores no están en la nómina.
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
    -- Estado de vigencia de ESTA póliza (campo derivado, ver el encabezado de este archivo)
    estado ENUM('VIGENTE','NO VIGENTE') NOT NULL DEFAULT 'NO VIGENTE',
    -- Aviso (no es un estado): la póliza sigue VIGENTE pero hay riesgo de que se corte
    va_a_corte TINYINT(1) NOT NULL DEFAULT 0,
    estado_calculado_at DATETIME NULL,
    -- 1 = el estado lo cambió la aplicación al registrar 3 meses seguidos sin pago. Mientras
    -- el Excel siga diciendo otra cosa, la sincronización respeta este estado; cuando el Excel
    -- coincide con él, la marca se limpia sola.
    estado_manual TINYINT(1) NOT NULL DEFAULT 0,
    -- Los valores vienen en la moneda de cada hoja de origen (pesos o UF)
    valor_lega DECIMAL(12,2) NULL,
    valor_poliza DECIMAL(12,2) NULL,
    deducible_uf DECIMAL(6,2) NULL,
    -- Renuncia a ESTA póliza (la persona puede haber pasado a otra)
    fecha_renuncia DATE NULL,
    fecha_envio_certificado DATE NULL,
    fecha_envio_poliza DATE NULL,
    numero_certificado VARCHAR(30) NULL,
    observaciones TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poliza_rut_producto_numero (rut, producto_id, numero),
    KEY idx_poliza_estado (estado),
    KEY idx_poliza_pagador (rut_pagador),
    KEY idx_poliza_numero (numero),
    CONSTRAINT fk_poliza_aseg FOREIGN KEY (rut) REFERENCES asegurados(rut),
    CONSTRAINT fk_poliza_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_poliza_cobertura FOREIGN KEY (cobertura_id) REFERENCES coberturas(id),
    CONSTRAINT fk_poliza_medio FOREIGN KEY (medio_pago_id) REFERENCES medios_pago(id),
    CONSTRAINT fk_poliza_motivo FOREIGN KEY (motivo_baja_id) REFERENCES motivos_baja(id)
) ENGINE=InnoDB;

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
-- Pagos: una fila por póliza y mes de cobertura
-- ---------------------------------------------------------------------
-- `periodo` es el primer día del mes que queda cubierto (2026-10-01 = octubre 2026),
-- que no siempre coincide con el mes en que se pagó (hay pagos adelantados y atrasados).
-- Un mes sin fila significa "sin datos" (la póliza aún no existía, por ejemplo).

CREATE TABLE pagos_mensuales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poliza_id INT UNSIGNED NOT NULL,
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
    UNIQUE KEY uq_poliza_periodo (poliza_id, periodo),
    KEY idx_periodo (periodo),
    CONSTRAINT fk_pago_poliza FOREIGN KEY (poliza_id) REFERENCES polizas(id),
    CONSTRAINT fk_pago_metodo FOREIGN KEY (metodo_pago_id) REFERENCES medios_pago(id),
    CONSTRAINT fk_pago_import FOREIGN KEY (importacion_id) REFERENCES importaciones(id)
) ENGINE=InnoDB;

-- Bitácora: ediciones hechas desde el front, cambios que trajo la sincronización y
-- afiliaciones anteriores de personas que se dieron de baja y volvieron a entrar.
--   tabla/registro/campo identifican qué cambió (ej. 'pagos_mensuales', '7779582|2026-09-01', 'pagado';
--   para pólizas que no son del Excel madre la clave lleva además '|p<id de póliza>').
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

-- Datos iniciales: los productos de las cuatro pólizas matrices. 'Odontólogo' es el de las
-- pólizas del Excel madre de dentistas; el número de póliza cambia cada año, el producto no.
INSERT INTO productos (nombre) VALUES ('Odontólogo'), ('Individual'), ('SOEMAF'), ('Derma');
