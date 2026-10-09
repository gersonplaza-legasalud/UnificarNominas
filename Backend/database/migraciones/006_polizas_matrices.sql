-- =====================================================================
-- Migración 006: pólizas matrices (la vigencia sale del número de póliza, no del Excel)
-- =====================================================================
-- Cada número de póliza matriz dura 18 meses (inicio = vencimiento - 18 meses) y se renueva solo
-- salvo renuncia o pago rechazado. Las fechas por persona que traía el Excel eran inconsistentes
-- (períodos de 12 meses escalonados, términos vacíos o iguales al inicio), así que la vigencia de
-- cada póliza pasa a ser la de su número matriz. Los números antiguos se ignoran.
--
-- Esta tabla solo define las pólizas matrices. Los datos (qué números hay y sus fechas) los carga
-- scripts/aplicar_matrices.php, que también reasigna productos y arma los períodos.
--
--   aproximada = 1: las fechas se dedujeron (p. ej. el número anterior termina donde empieza el
--                   siguiente) y no están confirmadas.
-- Un mismo número puede servir a dos productos (Individual y Derma comparten el 26158).

CREATE TABLE polizas_matrices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id SMALLINT UNSIGNED NOT NULL,
    numero VARCHAR(30) NOT NULL,
    vigencia_desde DATE NOT NULL,
    vigencia_hasta DATE NOT NULL,
    aproximada TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matriz_producto_numero (producto_id, numero),
    KEY idx_matriz_numero (numero),
    CONSTRAINT fk_matriz_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT ck_matriz_fechas CHECK (vigencia_hasta > vigencia_desde)
) ENGINE=InnoDB;
