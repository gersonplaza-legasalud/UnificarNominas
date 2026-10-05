/**
 * Punto de entrada del front: conecta los tres módulos de la pantalla.
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
 *   importador.js: botón "Actualizar nómina" (diálogo aparte); al terminar recarga la ficha.
 *
 * La URL es la única fuente de verdad de "qué ficha está abierta": cualquier clic (resultado,
 * pagador, "paga por") solo cambia el hash, y la pantalla reacciona a ese cambio. Así el
 * botón Atrás del navegador, recargar la página y compartir el enlace funcionan solos.
 */

import "../css/style.css";

import { iniciarBuscador } from "./buscador.js";
import { crearFicha } from "./ficha.js";
import { iniciarImportador } from "./importador.js";

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

iniciarImportador({
    botonAbrir: document.getElementById("abrirImportador"),
    // Tras actualizar la nómina, la ficha abierta puede haber cambiado
    alTerminar: () => {
        const rut = ficha.rutActual();
        if (rut) ficha.cargar(rut);
    },
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

window.addEventListener("hashchange", sincronizarConUrl); // cada vez que cambia la URL
sincronizarConUrl();                                      // y una vez al cargar la página
