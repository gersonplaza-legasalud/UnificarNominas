/**
 * Panel izquierdo: caja de búsqueda y lista de resultados.
 *
 * Flujo de datos:
 *   el usuario escribe → (espera 300 ms) → api.buscar() → se pinta la lista
 *   el usuario hace clic en un resultado → alSeleccionar(rut) → main.js cambia la URL
 *   → se abre la ficha de esa persona
 */

import { buscar } from "./api.js";
import { h, vaciar } from "./dom.js";
import { nombreProducto } from "./formato.js";

const MIN_CARACTERES = 3; // igual que el mínimo que exige la API
const ESPERA_MS = 300;    // pausa tras dejar de teclear antes de buscar

/** Estado de la nómina → sufijo de clase CSS (colores de la insignia). */
export const claseEstado = (estado) =>
    ({ VIGENTE: "vigente", "NO VIGENTE": "novigente" })[estado] ?? "novigente";

/** Etiqueta de color con el estado (verde / rojo). También la usa la ficha. */
export function insigniaEstado(estado) {
    return h("span", { class: `insignia insignia-${claseEstado(estado)}` }, estado);
}

/** Aviso amarillo "Va a corte": no es un estado, la póliza sigue VIGENTE pero hay riesgo de que se corte. */
export function avisoCorte() {
    return h("span", { class: "insignia insignia-corte", title: "Sigue vigente, pero hay riesgo de que se corte" }, "Va a corte");
}

/**
 * Activa el buscador. Busca mientras se escribe (con espera) o al presionar Enter;
 * si Enter devuelve un único resultado, lo abre directamente.
 *
 * @param {object} opciones
 * @param {HTMLFormElement} opciones.formulario     el <form> de búsqueda (Enter lo envía)
 * @param {HTMLInputElement} opciones.input         la caja de texto
 * @param {HTMLElement} opciones.contenedor         donde se pinta la lista de resultados
 * @param {(rut:number) => void} opciones.alSeleccionar  se llama al elegir una persona
 * @returns {{marcar: (rut:?number) => void}}
 */
export function iniciarBuscador({ formulario, input, contenedor, alSeleccionar }) {
    let temporizador = null; // espera pendiente antes de buscar
    let peticion = null;     // AbortController de la búsqueda en curso
    let seleccionado = null; // RUT de la persona abierta en la ficha (se resalta en la lista)

    /** Reemplaza la lista por un mensaje (cargando, sin resultados, error…). */
    function mensaje(texto, clase = "") {
        vaciar(contenedor);
        contenedor.append(h("p", { class: `mensaje ${clase}` }, texto));
    }

    /**
     * Lanza la búsqueda con lo que haya en la caja.
     * @param {boolean} abrirSiEsUnico  true cuando el usuario presionó Enter
     */
    async function ejecutar(abrirSiEsUnico) {
        const texto = input.value.trim();
        // Si había una búsqueda en curso se cancela: una respuesta lenta y vieja no debe
        // pisar a la más reciente
        peticion?.abort();

        if (texto.length < MIN_CARACTERES) {
            mensaje(`Escribe al menos ${MIN_CARACTERES} caracteres.`);
            return;
        }

        peticion = new AbortController();
        mensaje("Buscando…");

        try {
            const { resultados, total } = await buscar(texto, peticion.signal);

            if (resultados.length === 1 && abrirSiEsUnico) {
                alSeleccionar(resultados[0].rut);
            }
            mostrar(resultados, total);
        } catch (error) {
            if (error.name !== "AbortError") mensaje(error.message, "error");
        }
    }

    /** Pinta la lista de resultados con su conteo ("Mostrando 25 de 82…"). */
    function mostrar(resultados, total) {
        if (resultados.length === 0) {
            mensaje("Sin resultados.");
            return;
        }

        vaciar(contenedor);
        contenedor.append(
            h("p", { class: "conteo" },
                total > resultados.length
                    ? `Mostrando ${resultados.length} de ${total}. Afina la búsqueda para ver el resto.`
                    : `${total} resultado${total === 1 ? "" : "s"}`),
            h("ul", { class: "lista-resultados" },
                resultados.map((r) =>
                    h("li", {},
                        h("button", {
                            type: "button",
                            class: "resultado",
                            "data-rut": r.rut, // lo usa marcar() para encontrar el botón
                            "aria-current": r.rut === seleccionado ? "true" : null,
                            onclick: () => alSeleccionar(r.rut),
                        },
                            h("span", { class: "resultado-nombre" }, r.nombre,
                                r.con_siniestro ? h("span", { class: "insignia insignia-siniestro" }, "Con siniestro") : null),
                            h("span", { class: "resultado-detalle" },
                                [r.rut_formateado, r.especialidad, r.tipo_individuo].filter(Boolean).join(" · ")),
                            // Una insignia por póliza (con su producto si la persona tiene varias)
                            h("span", { class: "resultado-polizas" }, r.polizas.map((z) =>
                                h("span", { class: "resultado-poliza" },
                                    r.polizas.length > 1 ? h("span", { class: "resultado-producto" }, nombreProducto(z.producto) ?? "--") : null,
                                    insigniaEstado(z.estado),
                                    z.va_a_corte ? avisoCorte() : null))))))));
    }

    // Al teclear: se reinicia la espera y se busca cuando el usuario hace una pausa
    input.addEventListener("input", () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => ejecutar(false), ESPERA_MS);
    });

    // Enter: busca de inmediato y, si hay un solo resultado, lo abre
    formulario.addEventListener("submit", (evento) => {
        evento.preventDefault();
        clearTimeout(temporizador);
        ejecutar(true);
    });

    mensaje("Escribe un RUT, nombre o mail.");

    return {
        /**
         * Resalta en la lista a la persona abierta en la ficha (o quita el resalte con null).
         * Lo llama main.js cuando cambia la URL.
         */
        marcar(rut) {
            seleccionado = rut;
            contenedor.querySelectorAll(".resultado").forEach((boton) => {
                if (boton.dataset.rut === String(rut)) boton.setAttribute("aria-current", "true");
                else boton.removeAttribute("aria-current");
            });
        },
    };
}
