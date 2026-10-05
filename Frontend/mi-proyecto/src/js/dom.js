/**
 * Ayudantes para construir elementos del DOM desde JavaScript.
 *
 * Se usan en lugar de `innerHTML` a propósito: los textos que vienen del Excel (nombres,
 * notas, mails) se insertan siempre como TEXTO y nunca se interpretan como HTML, así que
 * un dato con "<script>" no puede ejecutar código en la página.
 */

/**
 * Crea un elemento. Equivale a escribir el HTML pero seguro.
 *
 *   h("button", { class: "boton", onclick: () => ... }, "Guardar")
 *
 * @param {string} tag      nombre de la etiqueta ("div", "button"…)
 * @param {object} props    atributos. `class` fija la clase; las claves que empiezan con
 *                          "on" (onclick, onsubmit…) registran un listener; `true` crea un
 *                          atributo sin valor (disabled); null/undefined/false se omiten.
 * @param {...any} hijos    textos, elementos o arreglos de ellos; los null/false se descartan
 *                          (permite escribir `condicion ? elemento : null` directamente).
 * @returns {HTMLElement}
 */
export function h(tag, props = {}, ...hijos) {
    const el = document.createElement(tag);

    for (const [clave, valor] of Object.entries(props ?? {})) {
        if (valor == null || valor === false) continue;

        if (clave === "class") el.className = valor;
        else if (clave.startsWith("on")) el.addEventListener(clave.slice(2).toLowerCase(), valor);
        else if (valor === true) el.setAttribute(clave, "");
        else el.setAttribute(clave, valor);
    }

    // append() inserta los strings como nodos de texto (no como HTML)
    el.append(...hijos.flat().filter((hijo) => hijo != null && hijo !== false));
    return el;
}

/** Elimina todo el contenido de un elemento (para volver a pintarlo desde cero). */
export function vaciar(el) {
    el.replaceChildren();
}
