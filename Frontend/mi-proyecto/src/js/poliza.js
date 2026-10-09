/**
 * Póliza de la persona (documento legal de Chubb), lista para imprimir o guardar como PDF.
 *
 * Usa el modelo del producto (P_ODONTO, P_INDIVIDUAL, P_SOEMAF o P_DERMA, extraídos del Excel PÓLIZAS_RESPALDO.xlsx a
 * src/plantillas/*.json con Backend/scripts/extraer_plantillas_polizas.php) y le completa:
 *   - el N° de póliza matriz y la vigencia (no los del modelo, que están desactualizados): el número de la última póliza
 *     vigente del producto y la vigencia de la COBERTURA de la persona (12 meses desde su inicio);
 *   - el límite asegurado según el producto (SOEMAF 2.000, Individual y Derma 7.000, Odontólogo 3.000 o el plan numérico de la póliza; ver limiteUF);
 *   - el deducible real de la póliza (CON DEDUCIBLE DE N UF o SIN DEDUCIBLE);
 *   - los antecedentes del asegurado (nombre, RUT, teléfono, mail, especialidad, dirección, comuna y ciudad).
 * El resto del documento (cláusulas, anexos, aseguradora y contratante) es el texto del modelo, sin cambios.
 *
 * Diseño: igual que la póliza original de Excel, con recuadros. La carátula (póliza matriz, coberturas, asegurado, aseguradora y
 * contratante) va en cuadros con borde y etiquetas sombreadas; las cláusulas llevan barras de título (grises) y las exclusiones
 * van en un recuadro con viñetas. Cada página lleva al pie la póliza, el asegurado y el número de página.
 *
 * Igual que el certificado, abre una ventana con el documento y el diálogo de impresión del navegador.
 */

import { fechaCorta, nombreProducto } from "./formato.js";
import { deducibleUF, esc, limiteUF, recurso } from "./certificado.js";

/** Modelo de cada producto (se cargan solo cuando se necesitan: pesan ~35 KB cada uno). */
const PLANTILLAS = {
    "Odontólogo": () => import("../plantillas/p_odonto.json"),
    Individual: () => import("../plantillas/p_individual.json"),
    SOEMAF: () => import("../plantillas/p_soemaf.json"),
    Derma: () => import("../plantillas/p_derma.json"),
};

/** ¿Hay modelo de póliza para este producto? (una póliza sin producto no se puede generar). */
export const tienePlantilla = (producto) => producto in PLANTILLAS;

/** Títulos de cláusula que van en una barra gris (los demás títulos en negrita van como subtítulo). */
const TITULO_DE_CLAUSULA = /^(FECHA DE RETROACTIVIDAD|CONDICIONES GENERALES|SERVICIOS? M[ÉE]DICOS PROFESIONALES|SERVICIOS? PROFESIONAL|ACLARACIONES|MODALIDAD|CL[AÁ]USULAS?|RESOLUCI[ÓO]N|REQUISITOS? DE ASEGURABILIDAD|ANEXO|PROCEDIMIENTO DE LIQUIDACI|INFORMACI[ÓO]N SOBRE|CIRCULAR)/i;

/** Texto con saltos de línea → HTML seguro. */
const texto = (t) => esc(t).replace(/\r?\n/g, "<br>");

/** "18.501.881-4" → "18501881-4" (así lo escribe el modelo). */
const rutSinPuntos = (rut) => String(rut ?? "").replace(/\./g, "");

/** Texto para usar dentro de un string CSS ("…"). */
const cssTexto = (t) => String(t).replace(/\\/g, "\\\\").replace(/"/g, '\\"').replace(/[\r\n<]/g, " ");

/** Valor de un dato: "--" si falta. */
const v = (x) => (x == null || x === "" ? "--" : esc(x));

/**
 * Genera la póliza y abre el diálogo de impresión.
 * @param {object} a       `asegurado` de api/asegurado.php (la persona)
 * @param {object} poliza  una de sus `polizas` (producto, número, cobertura y vigencia)
 */
export async function generarPoliza(a, poliza) {
    if (!tienePlantilla(poliza.producto)) {
        alert("Esta póliza no tiene producto asignado: no hay modelo de póliza que usar. Asígnalo con «Editar póliza».");
        return;
    }
    // La ventana se abre de inmediato (dentro del clic) para que el navegador no la bloquee mientras se carga el modelo
    const ventana = window.open("", "_blank");
    if (!ventana) {
        alert("El navegador bloqueó la ventana de la póliza. Permite las ventanas emergentes para este sitio.");
        return;
    }
    try {
        const { default: plantilla } = await PLANTILLAS[poliza.producto]();
        ventana.document.write(documento(a, poliza, plantilla));
        ventana.document.close();
    } catch (error) {
        ventana.close();
        alert(`No se pudo generar la póliza: ${error.message}`);
    }
}

/** Texto de una celda del modelo (negrita o no) como HTML. */
const celdaHtml = (c) => (c.b ? `<b>${texto(c.t)}</b>` : texto(c.t));

/** Una fila "ETIQUETA : valor …" del modelo (aseguradora y contratante) → pares [etiqueta, valor] (la dirección trae además la comuna). */
function paresDeFila(fila) {
    const c = fila.celdas.map((x) => ({ ...x, t: x.t.trim() }));
    const pares = [[c[0].t, c.slice(2).filter((x) => !/^COMUNA\s*:?$/i.test(x.t)).map((x) => x.t).filter(Boolean)]];
    // "DIRECCION : AV. … | COMUNA : LAS CONDES": la comuna queda como un segundo par
    const iComuna = c.findIndex((x, i) => i >= 2 && /^COMUNA\s*:?$/i.test(x.t));
    if (iComuna > 0) {
        pares[0][1] = c.slice(2, iComuna).map((x) => x.t).filter(Boolean);
        pares.push(["COMUNA", c.slice(iComuna + 1).map((x) => x.t).filter(Boolean)]);
    } else if (c.length >= 5 && /^COMUNA/i.test(c[3]?.t ?? "")) {
        pares.push(["COMUNA", [c[4].t]]);
    }
    return pares.map(([e, valores]) => [e.replace(/\s*:\s*$/, ""), valores.join(" ")]);
}

/** Cuadro con filas "etiqueta | valor" (aseguradora y contratante). */
function cuadroDeDatos(titulo, filas) {
    const lineas = filas.flatMap(paresDeFila);
    // Si el modelo trae la comuna aparte de la dirección, se junta con ella en una sola fila
    const filasTabla = [];
    for (let i = 0; i < lineas.length; i++) {
        const [e, val] = lineas[i];
        if (e.toUpperCase() === "COMUNA" && filasTabla.length) { filasTabla[filasTabla.length - 1].comuna = val; continue; }
        filasTabla.push({ e, val });
    }
    const cuerpo = filasTabla.map(({ e, val, comuna }) => `<tr><th>${esc(e)}</th><td>${esc(val)}${comuna ? ` <span class="comuna"><b>COMUNA:</b> ${esc(comuna)}</span>` : ""}</td></tr>`).join("");
    return `<div class="cuadro-doble"><div class="barra-azul">${esc(titulo)}</div><table class="datos">${cuerpo}</table></div>`;
}

/** HTML completo del documento. */
export function documento(a, poliza, plantilla) {
    // Vigencia de la cobertura de la persona (si no tiene inicio registrado, el período de la póliza matriz)
    const vigencia = poliza.cobertura_vigente ?? poliza.vigencia_actual;
    const matriz = poliza.numero_vigente ?? poliza.vigencia_actual?.numero ?? poliza.numero ?? "--";
    const limite = limiteUF(poliza) ?? plantilla.limite_uf;
    const deducible = deducibleUF(poliza);
    const filas = plantilla.filas;

    // ---- partes fijas de la carátula, ubicadas por su contenido ----
    const indiceDe = (re) => filas.findIndex((f) => f.celdas && re.test(f.celdas[0]?.t ?? ""));
    const iCobertura = indiceDe(/^COBERTURAS Y MATERIA ASEGURADA/i);
    const iContratante = indiceDe(/^ANTECEDENTES DEL CONTRATANTE/i);
    const iAseguradora = indiceDe(/^IDENTIFICACION DE LA COMPA/i);
    const materia = filas.slice(iCobertura + 1).find((f) => f.celdas)?.celdas.map((c) => c.t.trim()).join(" ") ?? "";
    const aseguradora = filas.slice(iAseguradora + 1, iContratante).filter((f) => f.celdas);
    // Las filas de contratante son pares "ETIQUETA : valor" (celda 0 en negrita y celda 1 ":"); la primera que no lo es empieza el cuerpo
    let iCuerpo = iContratante + 1;
    while (filas[iCuerpo]?.celdas && filas[iCuerpo].celdas.length >= 3 && filas[iCuerpo].celdas[0].b && filas[iCuerpo].celdas[1].t.trim() === ":") iCuerpo++;
    const contratante = filas.slice(iContratante + 1, iCuerpo);

    const datosAsegurado = [
        ["ASEGURADO", v(a.nombre), "RUT", v(rutSinPuntos(a.rut_formateado))],
        ["TELÉFONO", v(a.telefono), "E-MAIL", v(a.mail)],
        ["ESPECIALIDAD", v(a.especialidad ? a.especialidad.toUpperCase() : null), "CIUDAD", v(a.ciudad ?? a.comuna)],
        ["DIRECCIÓN", v(a.direccion), "COMUNA", v(a.comuna)],
    ];

    const caratula = `
<div class="franja">
  <div class="franja-et">PÓLIZA MATRIZ Nº</div>
  <div class="franja-num">${v(matriz)}</div>
  <div class="franja-plan">PLAN ${esc(nombreProducto(poliza.producto) ?? "")}</div>
</div>

<div class="barra-azul">COBERTURAS Y MATERIA ASEGURADA</div>
<div class="cuadro">
  <p class="materia">${texto(materia)}</p>
  <table class="resumen">
    <tr><th>VIGENCIA</th><th>LÍMITE ASEGURADO</th><th>DEDUCIBLE</th></tr>
    <tr>
      <td><span class="chica">DESDE</span> <b>${v(fechaCorta(vigencia?.desde))}</b><br><span class="chica">HASTA</span> <b>${v(fechaCorta(vigencia?.hasta))}</b></td>
      <td><b class="grande">${v(limite)} UF</b><br><span class="chica">POR EVENTO Y EN EL AGREGADO ANUAL</span></td>
      <td>${deducible === null ? '<b class="grande">SIN DEDUCIBLE</b>' : `<b class="grande">CON DEDUCIBLE</b><br><span class="chica">DE</span> <b>${esc(deducible.toLocaleString("es-CL"))} UF</b>`}</td>
    </tr>
  </table>
</div>

<div class="barra-azul">ANTECEDENTES DEL ASEGURADO</div>
<table class="datos asegurado">
${datosAsegurado.map(([e1, v1, e2, v2]) => `  <tr><th>${e1}</th><td>${v1}</td><th>${e2}</th><td>${v2}</td></tr>`).join("\n")}
</table>

<div class="dos-cuadros">
${cuadroDeDatos("IDENTIFICACIÓN DE LA COMPAÑÍA ASEGURADORA", aseguradora)}
${cuadroDeDatos("ANTECEDENTES DEL CONTRATANTE", contratante)}
</div>`;

    // ---- cuerpo: cláusulas y anexos del modelo ----
    const cuerpo = [];
    let tituloAnterior = "";
    const resto = filas.slice(iCuerpo);
    for (let i = 0; i < resto.length; i++) {
        const fila = resto[i];
        const celdas = fila.celdas ?? [];
        if (!celdas.length) continue;
        const t = celdas.map((c) => c.t.trim()).join("  ").trim();
        if (!t) continue;

        // Alcance de la cobertura (el primer texto del cuerpo) va en su propio recuadro
        if (i === 0 && celdas.length === 1 && !celdas[0].b) {
            cuerpo.push(`<div class="barra-azul">ALCANCE DE LA COBERTURA</div><div class="cuadro"><p>${texto(t)}</p></div>`);
            continue;
        }
        // Dos celdas: "A." + texto → ítem con letra
        if (celdas.length === 2 && celdas[0].t.trim().length <= 3) {
            cuerpo.push(`<div class="item"><span class="marca">${esc(celdas[0].t.trim())}</span><span>${texto(celdas[1].t.trim())}</span></div>`);
            continue;
        }
        // Viñetas seguidas: un recuadro con la lista
        if (celdas.length === 1 && t.startsWith("·")) {
            const vinetas = [];
            while (i < resto.length && (resto[i].celdas ?? []).length === 1 && (resto[i].celdas[0].t ?? "").trim().startsWith("·")) {
                vinetas.push(resto[i].celdas[0].t.trim().replace(/^·\s*/, ""));
                i++;
            }
            i--;
            cuerpo.push(`<ul class="vinetas">${vinetas.map((x) => `<li>${texto(x)}</li>`).join("")}</ul>`);
            continue;
        }
        // Títulos de cláusula: en negrita, o cortos y con la forma de un título aunque el modelo no los traiga en negrita (ACLARACIONES DE COBERTURA)
        const parecePrincipal = celdas.length === 1 && !celdas[0].b && t.length <= 60 && !/[.:]$/.test(t) && TITULO_DE_CLAUSULA.test(t);
        if (celdas.length === 1 && (celdas[0].b || parecePrincipal)) {
            if (t === tituloAnterior) continue; // el modelo repite algunos títulos seguidos
            tituloAnterior = t;
            cuerpo.push(TITULO_DE_CLAUSULA.test(t) ? `<div class="barra-gris">${texto(t)}</div>` : `<p class="subtitulo">${texto(t)}</p>`);
            continue;
        }
        if (/^\d+\)/.test(t)) { cuerpo.push(`<p class="subtitulo">${texto(t)}</p>`); continue; }
        // Par "ETIQUETA : valor" suelto o texto de varias celdas
        cuerpo.push(`<p>${celdas.map(celdaHtml).join(" ")}</p>`);
    }

    return `<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>Póliza ${esc(matriz)} - ${esc(a.nombre)}</title>
<style>
  :root { --azul: #184b98; --azul-oscuro: #0f2f63; --azul-claro: #e6edf7; --gris: #dcdcdc; --borde: #5d6f8f; }
  /* Al imprimir, el navegador quita por defecto los colores de fondo: se obliga a imprimirlos (fondos, barras y bordes de color) */
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: Arial, "Segoe UI", sans-serif; font-size: 9pt; line-height: 1.38; color: #111; margin: 0; padding: 22px; background: #fff; }
  .doc { max-width: 790px; margin: 0 auto; }

  /* encabezado */
  .encabezado { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0 0 10px; }
  .logo { display: block; height: 40px; }
  .titulo-doc { text-align: right; line-height: 1.25; }
  .titulo-doc b { display: block; font-size: 12pt; color: var(--azul-oscuro); letter-spacing: .02em; }
  .titulo-doc span { font-size: 8pt; color: #444; }

  /* franja con el número de póliza */
  .franja { display: flex; align-items: stretch; margin: 0 0 12px; border: 1.5px solid var(--azul-oscuro); }
  .franja-et { background: var(--azul-oscuro); color: #fff; font-weight: 700; padding: 8px 14px; display: flex; align-items: center; letter-spacing: .03em; }
  .franja-num { flex: 1; font-size: 17pt; font-weight: 800; padding: 4px 16px; display: flex; align-items: center; color: var(--azul-oscuro); }
  .franja-plan { padding: 0 16px; display: flex; align-items: center; font-weight: 700; background: var(--azul-claro); border-left: 1.5px solid var(--azul-oscuro); }

  /* barras de título */
  .barra-azul { margin: 12px 0 0; padding: 4px 10px; background: var(--azul); color: #fff; font-weight: 700; font-size: 8.5pt; letter-spacing: .04em; break-after: avoid; }
  .barra-gris { margin: 14px 0 6px; padding: 4px 10px; background: var(--gris); border: 1px solid #b5b5b5; font-weight: 700; break-after: avoid; }

  /* recuadros */
  .cuadro { border: 1.5px solid var(--borde); border-top: 0; padding: 8px 10px; break-inside: avoid; }
  .cuadro p { margin: 0 0 6px; text-align: justify; }
  .cuadro p:last-child { margin-bottom: 0; }
  .materia { font-weight: 600; }
  table { border-collapse: collapse; width: 100%; break-inside: avoid; }
  .resumen { margin-top: 8px; }
  .resumen th { background: var(--azul-claro); border: 1px solid var(--borde); padding: 3px 6px; font-size: 8pt; letter-spacing: .03em; }
  .resumen td { border: 1px solid var(--borde); padding: 7px 8px; text-align: center; vertical-align: middle; width: 33.3%; }
  .grande { font-size: 12pt; color: var(--azul-oscuro); }
  .chica { font-size: 7.5pt; color: #444; }
  .datos th { text-align: left; background: var(--azul-claro); border: 1px solid var(--borde); padding: 4px 8px; font-size: 8pt; white-space: nowrap; width: 1%; }
  .datos td { border: 1px solid var(--borde); padding: 4px 8px; }
  .asegurado { border: 1.5px solid var(--borde); }
  .asegurado td { width: 36%; }
  .asegurado td + th { width: 1%; }
  .dos-cuadros { display: flex; gap: 12px; margin-top: 12px; align-items: flex-start; }
  .cuadro-doble { flex: 1; min-width: 0; break-inside: avoid; }
  .cuadro-doble .barra-azul { margin-top: 0; font-size: 8pt; }
  .cuadro-doble .datos { border: 1.5px solid var(--borde); }
  .comuna { white-space: nowrap; }

  /* cláusulas */
  p { margin: 4px 0; text-align: justify; }
  p.subtitulo { font-weight: 700; margin-top: 10px; break-after: avoid; }
  .vinetas { margin: 6px 0; padding: 8px 12px 8px 28px; border: 1px solid var(--borde); border-left: 4px solid var(--azul); background: #f6f8fc; break-inside: avoid; }
  .vinetas li { margin: 3px 0; text-align: justify; }
  .item { display: flex; gap: 8px; margin: 4px 0; text-align: justify; }
  .item .marca { flex: 0 0 24px; font-weight: 700; }

  /* pie de página en cada hoja: póliza y asegurado a la izquierda, número de página a la derecha */
  @page {
    size: letter portrait; margin: 0.6in 0.75in 0.75in;
    @bottom-left { content: "${cssTexto(`Póliza ${matriz} · ${nombreProducto(poliza.producto) ?? ""} · ${a.nombre} · RUT ${rutSinPuntos(a.rut_formateado)}`)}"; font-size: 7.5pt; color: #444; border-top: 1px solid #5d6f8f; padding-top: 4px; vertical-align: top; }
    @bottom-right { content: "Página " counter(page) " de " counter(pages); font-size: 7.5pt; color: #444; border-top: 1px solid #5d6f8f; padding-top: 4px; vertical-align: top; }
  }
  @media print { body { padding: 0; } p, .item { orphans: 2; widows: 2; } }
</style></head>
<body><div class="doc">
  <div class="encabezado">
    <img class="logo" src="${esc(recurso("chubb-logo.png"))}" alt="Chubb" onerror="this.style.display='none'">
    <div class="titulo-doc"><b>RESPONSABILIDAD CIVIL PROFESIONAL</b><span>Póliza de seguro · Chubb Seguros Chile S.A.</span></div>
  </div>
${caratula}
${cuerpo.join("\n")}
</div>
<script>window.onload = () => setTimeout(() => window.print(), 400);<\/script>
</body></html>`;
}
