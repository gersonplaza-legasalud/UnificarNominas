/**
 * Página de verificación de vigencia (verificar.html), destino del QR del certificado.
 *
 * El enlace trae la póliza y el código del certificado (?poliza=...&k=...), así que los datos
 * se cargan solos y se muestra de inmediato el estado del profesional en esa póliza.
 */

import "../css/style.css";

import { insigniaEstado } from "./buscador.js";
import { verificarVigencia } from "./api.js";
import { h } from "./dom.js";

const contenedor = document.getElementById("resultado");
const params = new URLSearchParams(location.search);

/** Mensaje de error o de espera en lugar del resultado. */
function mensaje(texto, clase = "") {
    contenedor.replaceChildren(h("p", { class: `mensaje ${clase}` }, texto));
}

/** Texto grande que resume el estado, para leerlo de un vistazo desde el celular. */
function veredicto(estado) {
    if (estado === "VIGENTE") return "Cobertura vigente";
    return "Sin cobertura vigente";
}

async function iniciar() {
    const poliza = params.get("poliza");
    const codigo = params.get("k");
    if (!poliza || !codigo) {
        mensaje("El enlace está incompleto. Escanea nuevamente el QR del certificado.", "error");
        return;
    }

    mensaje("Verificando…");
    try {
        const { asegurado: a, consultado_en: consultado } = await verificarVigencia(poliza, codigo);
        const fila = (etiqueta, valor) => h("div", { class: "dato" },
            h("dt", {}, etiqueta), h("dd", {}, valor ?? "--"));

        contenedor.replaceChildren(
            h("div", { class: "verif-estado" }, insigniaEstado(a.estado), h("p", {}, veredicto(a.estado))),
            h("dl", { class: "datos" },
                fila("Nombre", a.nombre),
                fila("RUT", a.rut_formateado),
                fila("N° de póliza", a.poliza),
                fila("Cobertura (UF)", a.cobertura)),
            h("p", { class: "ayuda" }, `Consulta realizada el ${new Date(consultado).toLocaleString("es-CL")}.`));
    } catch (error) {
        mensaje(error.message, "error");
    }
}

iniciar();
