-- =====================================================================
-- Migración 010: tabla de primas por clase y límite (PPTA PRIMAS INDIVIDUALES Y DERMO LEGASALUD)
-- =====================================================================
-- El "Valor póliza" de una póliza (la prima en UF que paga el cliente) sale de la CLASE de su especialidad y del LÍMITE
-- de su cobertura (2000, 2500, 3000, 5000 o 7000 UF). Se guarda aquí para rellenar los datos que faltan y, más adelante, para
-- proponer el valor al crear clientes nuevos (a mano o automático). Lo carga scripts/cargar_primas.php desde el Excel.

CREATE TABLE IF NOT EXISTS primas_clase (
    clase     TINYINT UNSIGNED  NOT NULL,
    limite_uf SMALLINT UNSIGNED NOT NULL,
    prima_uf  DECIMAL(8,4)      NOT NULL,
    PRIMARY KEY (clase, limite_uf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS especialidades_clase (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clave         VARCHAR(120) NOT NULL,   -- nombre sin tildes, en minúsculas, solo letras y números
    especialidad  VARCHAR(120) NOT NULL,   -- nombre tal como viene en el Excel
    clase         TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_especialidad_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doctores con siniestro: prima propia (neta) distinta de la tabla
CREATE TABLE IF NOT EXISTS primas_excepcion (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asegurado     VARCHAR(120) NOT NULL,
    rut           INT UNSIGNED NULL,
    limite_uf     DECIMAL(10,3) NOT NULL,
    deducible     VARCHAR(60) NULL,
    prima_neta_uf DECIMAL(8,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equivalencias: especialidades que se escriben distinto en la base (MEDICO CIRUJANO, DERMATOLOGO…) apuntan a la del Excel
ALTER TABLE especialidades_clase ADD COLUMN IF NOT EXISTS equivale_a VARCHAR(120) NULL AFTER clase;
