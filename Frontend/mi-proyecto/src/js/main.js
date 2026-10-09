/**
 * Punto de entrada del front: conecta los módulos de la pantalla.
 *
 *   ┌──────────────┐  clic en resultado   ┌─────────────┐
 *   │ buscador.js  │ ───────────────────▶ │  la URL     │ (#/rut/12345678)
 *   │ (izquierda)  │ ◀─ marcar(rut) ───── │  (hash)     │
 *   └──────────────┘                      └──────┬──────┘
 *                                                │ hashchange
 *   ┌──────────────┐   cargar(rut)               ▼
 *   │  ficha.js    │ ◀──────────────────  sincronizarConUrl()
 *   │  (derecha)   │
 *   └──────────────┘
 *
 * La URL es la única fuente de verdad de "qué ficha está abierta": cualquier clic (resultado,
 * pagador, "paga por") solo cambia el hash, y la pantalla reacciona a ese cambio. Así el
 * botón Atrás del navegador, recargar la página y compartir el enlace funcionan solos.
 */

import "../css/style.css";

import { iniciarBuscador } from "./buscador.js";
import { crearFicha } from "./ficha.js";
import { obtenerAsegurado } from "./api.js";
import { abrirEditorCliente } from "./editorCliente.js";
import { abrirEditorPoliza } from "./editorPoliza.js";

const input = document.getElementById("busqueda");

const ficha = crearFicha(document.getElementById("ficha"), {
    alAbrirRut: abrirRut, // clic en el pagador o en alguien a quien esta persona paga
});

const buscador = iniciarBuscador({
    formulario: document.getElementById("formBusqueda"),
    input,
    contenedor: document.getElementById("resultados"),
    alSeleccionar: abrirRut, // clic en un resultado de la búsqueda
});

/** Abre la ficha de un RUT. Solo cambia la URL; el resto lo hace sincronizarConUrl(). */
function abrirRut(rut) {
    location.hash = `#/rut/${rut}`;
}

/**
 * Lee la URL y deja la pantalla coherente con ella:
 *   "#/rut/12345678" → resalta a la persona en la lista y carga su ficha
 *   cualquier otra   → ficha vacía
 */
function sincronizarConUrl() {
    const coincidencia = location.hash.match(/^#\/rut\/(\d+)$/);

    if (!coincidencia) {
        ficha.limpiar();
        buscador.marcar(null);
        return;
    }
    const rut = Number(coincidencia[1]);
    buscador.marcar(rut);
    ficha.cargar(rut);
}

/**
 * "Nuevo cliente": paso 1 crea a la persona; paso 2 abre su ficha y el formulario "Agregar póliza" con el producto, el número, las
 * fechas, el deducible y la prima ya propuestos. Si se cancela el paso 2, la persona queda creada y la póliza se agrega después.
 */
document.getElementById("botonNuevoCliente").addEventListener("click", () => {
    abrirEditorCliente(async (cliente) => {
        abrirRut(cliente.rut);
        try {
            const { asegurado: a } = await obtenerAsegurado(cliente.rut);
            abrirEditorPoliza({
                rut: a.rut, nombre: a.nombre, especialidad: a.especialidad, conSiniestro: a.con_siniestro,
                deducibleSugerido: a.con_siniestro ? a.deducible_uf : null, sugerenciaProducto: cliente.producto_sugerido,
            }, () => ficha.cargar(a.rut, "Cliente creado con su póliza."));
        } catch (error) {
            alert(`El cliente se creó, pero no se pudo abrir su póliza: ${error.message}`);
        }
    });
});

window.addEventListener("hashchange", sincronizarConUrl); // cada vez que cambia la URL
sincronizarConUrl();                                      // y una vez al cargar la página
