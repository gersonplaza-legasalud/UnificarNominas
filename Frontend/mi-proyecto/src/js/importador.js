/**
 * Diálogo "Actualizar nómina": sube el Excel madre y sincroniza la base de datos.
 *
 * Flujo de datos:
 *   botón "Actualizar nómina" → se abre el diálogo → el usuario elige el .xlsx
 *   → api.importarNomina() (POST importar.php, tarda cerca de un minuto)
 *   → la API devuelve el resumen de lo que cambió → mostrarResumen() lo muestra
 *   → alTerminar() para que la ficha abierta (si hay) se recargue con los datos nuevos
 *
 * El HTML del diálogo está en index.html (#dialogImportar); aquí solo se maneja su comportamiento.
 */

import { importarNomina } from "./api.js";
import { h, vaciar } from "./dom.js";

// Elementos del diálogo definidos en index.html
const dialogo = document.getElementById("dialogImportar");
const formulario = document.getElementById("formImportar");
const campoArchivo = document.getElementById("excelFile");
const estado = document.getElementById("estadoImportar");
const resumen = document.getElementById("resumenImportar");
const botonImportar = document.getElementById("botonImportar");

// Contadores del resumen que devuelve la API, en el orden en que se muestran: [clave, etiqueta]
const ETIQUETAS = [
    ["asegurados_nuevos", "Asegurados nuevos"],
    ["asegurados_actualizados", "Asegurados actualizados"],
    ["pagos_nuevos", "Meses nuevos"],
    ["pagos_actualizados", "Meses modificados"],
    ["pagos_sin_cambios", "Meses sin cambios"],
    ["pagos_editados_en_conflicto", "Meses editados a mano (no se tocaron)"],
    ["estados_mantenidos_manualmente", "Personas dadas de baja por la app (se mantienen)"],
];

/** Pinta la tabla de contadores y, si hubo conflictos, explica qué significan. */
function mostrarResumen(datos) {
    vaciar(resumen);

    resumen.append(
        h("dl", { class: "resumen-importacion" },
            ETIQUETAS.flatMap(([clave, etiqueta]) => [
                h("dt", {}, etiqueta),
                h("dd", {}, (datos[clave] ?? 0).toLocaleString("es-CL")),
            ])));

    if (datos.pagos_editados_en_conflicto > 0) {
        resumen.append(h("p", { class: "ayuda" },
            "Esos meses difieren del Excel pero se mantuvieron porque los editaste desde la aplicación."));
    }
}

/**
 * Conecta el botón de la barra superior con el diálogo.
 * @param {object} opciones
 * @param {HTMLElement} opciones.botonAbrir   botón "Actualizar nómina"
 * @param {() => void} opciones.alTerminar    se llama cuando la sincronización terminó bien
 */
export function iniciarImportador({ botonAbrir, alTerminar }) {
    // Cada vez que se abre, el diálogo parte limpio (sin el resultado de la vez anterior)
    botonAbrir.addEventListener("click", () => {
        formulario.reset();
        estado.textContent = "";
        estado.className = "mensaje";
        vaciar(resumen);
        botonImportar.disabled = false;
        dialogo.showModal();
    });

    document.getElementById("cerrarImportar").addEventListener("click", () => dialogo.close());

    formulario.addEventListener("submit", async (evento) => {
        evento.preventDefault();
        vaciar(resumen);

        const archivo = campoArchivo.files[0];
        if (!archivo) {
            estado.className = "mensaje error";
            estado.textContent = "Selecciona el Excel madre (.xlsx).";
            return;
        }

        // Se deshabilita el botón mientras corre para evitar una segunda sincronización simultánea
        botonImportar.disabled = true;
        estado.className = "mensaje";
        estado.textContent = "Procesando el archivo… puede tardar cerca de un minuto, no cierres esta ventana.";

        try {
            const { resumen: datos } = await importarNomina(archivo);
            estado.className = "mensaje exito";
            estado.textContent = "Nómina actualizada correctamente.";
            mostrarResumen(datos);
            alTerminar();
        } catch (error) {
            estado.className = "mensaje error";
            estado.textContent = error.message;
        } finally {
            botonImportar.disabled = false;
        }
    });
}
