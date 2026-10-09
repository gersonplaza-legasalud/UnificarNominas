/**
 * Certificado de cobertura (Chubb).
 *
 * Abre una ventana con el certificado listo para imprimir o guardar como PDF
 * (el diálogo de impresión del navegador permite "Guardar como PDF").
 *
 * Imágenes (se leen de Frontend/mi-proyecto/public/): chubb-logo.png y firma.png.
 * Si faltan, el logo se reemplaza por texto y la firma queda en blanco.
 */

import QRCode from "qrcode";
import { fechaCorta } from "./formato.js";

const MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

const DIRECCION = "Chubb Av. Presidente Riesco 5435, Piso 9, Las Condes, Chile";
const CONTACTO = "T +562.2549.8300 &nbsp; F +562.2632.6289 &nbsp; www.chubb.com/cl";

/** Escapa texto para insertarlo de forma segura en el HTML del certificado. */
export function esc(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

/** Texto en negrita para los datos de la persona. */
const neg = (valor) => `<b class="dato">${esc(valor)}</b>`;

/** Fecha de hoy como "6 de octubre de 2026". */
function fechaEmision() {
    const d = new Date();
    return `${d.getDate()} de ${MESES[d.getMonth()]} de ${d.getFullYear()}`;
}

/** URL absoluta de un archivo de /public (la ventana nueva no resuelve rutas relativas). */
export const recurso = (nombre) => new URL(nombre, document.baseURI).href;

/** Deducible de la póliza en UF (null si no tiene: la mayoría de las pólizas son sin deducible). */
export const deducibleUF = (poliza) => (Number(poliza.deducible_uf) > 0 ? Number(poliza.deducible_uf) : null);

/** Texto del deducible para el certificado: "SIN DEDUCIBLE" o "CON DEDUCIBLE DE 16 UF" (personas con siniestro). */
function textoDeducible(poliza) {
    const d = deducibleUF(poliza);
    return d === null ? neg("SIN DEDUCIBLE") : `${neg("CON DEDUCIBLE")} DE ${neg(`${d.toLocaleString("es-CL")} UF`)}`;
}

/**
 * Límite asegurado (UF) que lleva el certificado y la póliza:
 *   1. Si la cobertura de la póliza es un plan numérico (2000, 2500, 3000, 5000, 7000…) se usa ese valor, sin cambiarlo.
 *   2. Si no (RECIEN EGRESADO, SIN LEGA, SOLO LEGA o sin cobertura) se usa el mínimo del producto:
 *      Odontólogo 3.000 (el dentista recién egresado parte en 3.000 como mínimo) | Individual 7.000 | Derma 7.000 | SOEMAF 2.000.
 * Reglas de planes: el médico recién egresado puede contratar 2.000 UF con póliza Individual y luego pasa a 3.000, 5.000 o
 * 7.000; el dentista recién egresado parte en 3.000 con póliza Odontólogo y luego 3.000, 5.000 o 7.000; el dentista que trabaja
 * con estética (SOEMAF) es 2.000 plano y no cambia.
 */
export function limiteUF(poliza) {
    if (/^\d+$/.test(poliza.cobertura ?? "")) return Number(poliza.cobertura);
    switch (poliza.producto) {
        case "SOEMAF": return 2000;
        case "Individual":
        case "Derma": return 7000;
        case "Odontólogo": return 3000;
        default: return null;
    }
}


/**
 * Enlace a la página pública de verificación de una póliza (es lo que codifica el QR).
 * @param {object} poliza  una póliza de api/asegurado.php (trae `id` y `codigo_verificacion`)
 * VITE_URL_PUBLICA (ej. https://nominas.legasalud.cl/) debe apuntar a donde esté publicado el front;
 * sin ella usa la dirección actual, que un celular no puede abrir si es localhost.
 */
export function urlVerificacion(poliza) {
    const enlace = new URL("verificar.html", import.meta.env.VITE_URL_PUBLICA ?? document.baseURI);
    enlace.search = new URLSearchParams({ poliza: poliza.id, k: poliza.codigo_verificacion }).toString();
    return enlace.href;
}

/**
 * Genera el certificado de UNA póliza de la persona y abre el diálogo de impresión.
 * @param {object} a       `asegurado` de api/asegurado.php (la persona)
 * @param {object} poliza  una de sus `polizas` (número, cobertura, fechas y código del QR)
 */
export async function generarCertificado(a, poliza) {
    // La vigencia del certificado es la de la COBERTURA de la persona (12 meses desde que contrató, renovada cada año),
    // no la de la póliza matriz. Si aún no tiene inicio de cobertura se usa el período de la póliza como respaldo.
    const vigencia = poliza.cobertura_vigente ?? poliza.vigencia_actual;
    const desde = fechaCorta(vigencia?.desde ?? poliza.fecha_alta) ?? "--";
    const hasta = fechaCorta(vigencia?.hasta ?? poliza.fecha_baja) ?? "--";

    // El QR abre la página de verificación con los datos de la persona ya cargados
    const qr = await QRCode.toDataURL(urlVerificacion(poliza), { width: 480, margin: 4, errorCorrectionLevel: "L" });

    const ventana = window.open("", "_blank");
    if (!ventana) {
        alert("El navegador bloqueó la ventana del certificado. Permite las ventanas emergentes para este sitio.");
        return;
    }

    ventana.document.write(`<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>Certificado - ${esc(a.nombre)}</title>
<style>
  :root { --azul: #1f5fbf; --azul-oscuro: #184b98; --texto: #1f2937; --suave: #6b7280; }
  /* Al imprimir, el navegador quita por defecto los colores de fondo: se obliga a imprimirlos (fondos, barras y bordes de color) */
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; padding: 24px; color: var(--texto); background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .hoja { max-width: 760px; margin: 0 auto; border: 1px solid #d5dfef; }
  .franja { height: 10px; background: linear-gradient(90deg, var(--azul-oscuro), var(--azul)); }
  .cabecera { display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: 28px 44px 20px; border-bottom: 1px solid #e3eaf6; }
  .logo img { height: 46px; display: block; }
  .logo .texto { font-size: 2rem; font-weight: 800; letter-spacing: -1px; color: var(--azul-oscuro); }
  .contacto { text-align: right; font-size: .75rem; line-height: 1.6; color: var(--suave); }
  .cuerpo { padding: 40px 44px 28px; }
  h1 { margin: 0 0 8px; text-align: center; font-size: 1.35rem; letter-spacing: 3px; color: var(--azul-oscuro); }
  .linea { width: 80px; height: 3px; margin: 0 auto 32px; background: var(--azul); border-radius: 2px; }
  .texto-cert { text-align: justify; line-height: 1.9; font-size: .98rem; margin: 0; }
  .texto-cert b { color: var(--azul-oscuro); }
  .texto-cert .dato { white-space: nowrap; } /* nombre, RUT, fechas y póliza nunca se cortan entre líneas */
  .lugar { margin: 44px 0 0; font-size: .95rem; }
  .pie { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-top: 8px; }
  .firmante { flex: 1; font-size: .85rem; line-height: 1.5; }
  .firmante .empresa { font-weight: 700; }
  .firmante img { height: 70px; display: block; margin: 6px 0 -8px; }
  .firmante .hueco { height: 64px; }
  .firmante .nombre { border-top: 1px solid var(--texto); padding-top: 6px; display: inline-block; min-width: 260px; }
  .qr { text-align: center; }
  .qr-texto { font-size: .7rem; color: var(--suave); margin-top: -6px; }
  .qr img { display: block; width: 130px; height: 130px; image-rendering: pixelated; }
  .base { padding: 12px 44px; font-size: .72rem; color: #fff; background: var(--azul-oscuro); text-align: center; }
  @page { size: letter portrait; margin: 0; }
  @media print {
    body { padding: 0; }
    .hoja { width: 8.5in; height: 11in; max-width: none; border: 0; display: flex; flex-direction: column; page-break-after: avoid; overflow: hidden; }
    .cuerpo { flex: 1; display: flex; flex-direction: column; padding: 0.9in 0.8in 0.6in; }
    .lugar { margin-top: auto; padding-top: 0.4in; } /* fecha, firma y QR quedan pegados al fondo de la hoja */
    .cabecera { padding: 0.5in 0.8in 0.25in; }
    .texto-cert { font-size: 11.5pt; }
  }
</style></head>
<body>
 <div class="hoja">
  <div class="franja"></div>
  <div class="cabecera">
    <div class="logo">
      <img src="${esc(recurso("chubb-logo.png"))}" alt="Chubb"
           onerror="this.outerHTML='<div class=&quot;texto&quot;>CHUBB</div>'">
    </div>
    <div class="contacto">${esc(DIRECCION)}<br>${CONTACTO}</div>
  </div>
  <div class="cuerpo">
    <h1>CERTIFICADO DE COBERTURA</h1>
    <div class="linea"></div>
    <p class="texto-cert">
      A TRAVÉS DEL PRESENTE CERTIFICADO DE COBERTURA, CHUBB SEGUROS CHILE S.A., RUT: 99.225.000-3,
      DEJA CONSTANCIA QUE ${neg(a.nombre)} RUT: ${neg(a.rut_formateado)}
      CUENTA CON UNA PÓLIZA ${neg(poliza.numero_vigente ?? poliza.vigencia_actual?.numero ?? poliza.numero ?? "--")} DE RESPONSABILIDAD CIVIL DE MÉDICOS Y/O DENTISTAS,
      ERRORES Y OMISIONES POL ${neg("120190002")}, INTERMEDIADO A TRAVÉS DE DUARTE BECERRA PEDRO ENRIQUE,
      CUYA VIGENCIA ES DESDE EL ${neg(desde)} HASTA EL ${neg(hasta)}
      CON UN LÍMITE ASEGURADO EN UF DE ${neg(limiteUF(poliza) ?? "--")}
      POR EVENTO Y EN EL AGREGADO ANUAL, ${textoDeducible(poliza)}.
    </p>
    <p class="lugar">SANTIAGO, ${esc(fechaEmision())}</p>
    <div class="pie">
      <div class="firmante">
        <div class="empresa">CHUBB SEGUROS CHILE S.A.<br>99.225.000-3</div>
        <img src="${esc(recurso("firma.png"))}" alt="" onerror="this.outerHTML='<div class=&quot;hueco&quot;></div>'">
        <div class="nombre">CRISTOBAL ROJAS S.<br>SUBGERENTE LINEAS FINANCIERAS<br>CHUBB CHILE</div>
      </div>
      <div class="qr"><img src="${qr}" alt="QR de verificación de vigencia"><div class="qr-texto">Verificar vigencia</div></div>
    </div>
  </div>
  <div class="base">www.chubb.com/cl</div>
 </div>
  <script>window.onload = () => setTimeout(() => window.print(), 300);<\/script>
</body></html>`);
    ventana.document.close();
}
