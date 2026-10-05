/**
 * Panel derecho: ficha de la persona seleccionada.
 *
 * Flujo de datos:
 *   cargar(rut) → api.obtenerAsegurado() → pintar() arma, de arriba abajo:
 *     cabecera (nombre, RUT, estado) → aviso de meses sin pago → datos de la póliza
 *     → "paga por" (si es pagador de otros) → grilla de pagos por mes
 *   clic en un mes de la grilla → editorPago.abrirEditor() → al guardar, cargar(rut) de nuevo
 *
 * Todo se construye con h() (src/js/dom.js), sin innerHTML.
 */

import { obtenerAsegurado } from "./api.js";
import { insigniaEstado } from "./buscador.js";
import { h, vaciar } from "./dom.js";
import { abrirEditor } from "./editorPago.js";
import { fechaCorta, formatoMonto, MESES_CORTO, mesCorto, nombreMes, periodo } from "./formato.js";

/** Cada cuántos milisegundos se revisa si la ficha abierta cambió (la consulta es liviana: ~30 ms en el servidor). */
const REFRESCO_MS = 2000;

/**
 * Crea la ficha dentro de `contenedor`.
 * @param {HTMLElement} contenedor
 * @param {{alAbrirRut: (rut:number) => void}} opciones  se llama cuando el usuario hace clic en
 *        otra persona (el pagador o alguien a quien paga) para abrir su ficha
 * @returns {{cargar:(rut:number)=>Promise<void>, limpiar:()=>void, rutActual:()=>?number}}
 */
export function crearFicha(contenedor, { alAbrirRut }) {
    let rutActual = null; // RUT de la ficha abierta (null = ninguna)
    let ultimaRespuesta = null; // JSON de la última respuesta pintada, para detectar cambios
    let temporizador = null;    // refresco automático mientras hay una ficha abierta

    /** Reemplaza todo el panel por un mensaje (cargando, error, "busca un asegurado"…). */
    function mensaje(texto, clase = "") {
        vaciar(contenedor);
        contenedor.append(h("p", { class: `mensaje ${clase}` }, texto));
    }

    /**
     * Trae los datos de la API y los pinta.
     * @param {?string} aviso  mensaje destacado para mostrar arriba de la ficha (opcional), por
     *        ejemplo cuando la persona acaba de pasar a NO VIGENTE
     */
    async function cargar(rut, aviso = null) {
        rutActual = rut;
        mensaje("Cargando ficha…");
        try {
            const datos = await obtenerAsegurado(rut);
            if (rut !== rutActual) return; // el usuario abrió otra ficha mientras tanto: se descarta esta
            ultimaRespuesta = JSON.stringify(datos);
            pintar(datos, aviso);
            iniciarRefresco();
        } catch (error) {
            if (rut === rutActual) mensaje(error.message, "error");
        }
    }

    /** Vuelve al estado inicial (sin ficha abierta). */
    function limpiar() {
        rutActual = null;
        detenerRefresco();
        mensaje("Busca un asegurado para ver su ficha.");
    }

    // ------------------------------------------------------------ refresco automático

    /**
     * Mientras hay una ficha abierta, la consulta de nuevo cada pocos segundos para que los
     * cambios hechos en la hoja de Google (o por otra persona) aparezcan sin recargar la página.
     */
    function iniciarRefresco() {
        detenerRefresco();
        temporizador = setInterval(refrescar, REFRESCO_MS);
    }

    function detenerRefresco() {
        clearInterval(temporizador);
        temporizador = null;
    }

    /**
     * Una vuelta del refresco. Solo repinta si los datos cambiaron, y no hace nada si la pestaña
     * está en segundo plano o hay un diálogo abierto (para no pisar lo que el usuario escribe).
     * Los errores de red se ignoran: la siguiente vuelta lo vuelve a intentar.
     */
    async function refrescar() {
        if (!rutActual || document.hidden || document.querySelector("dialog[open]")) return;

        const rut = rutActual;
        try {
            const datos = await obtenerAsegurado(rut);
            const json = JSON.stringify(datos);
            if (rut !== rutActual || json === ultimaRespuesta) return;
            ultimaRespuesta = json;
            pintar(datos, null);
        } catch {
            /* sin conexión por un momento: se reintenta en el próximo ciclo */
        }
    }

    // ------------------------------------------------------------ pintado

    /** Arma la ficha completa a partir de la respuesta de api/asegurado.php. */
    function pintar({ asegurado: a, pagador, paga_por, pagos, sin_pago }, aviso) {
        vaciar(contenedor);
        // Las secciones opcionales vienen como null cuando no aplican. Se descartan antes de
        // insertar porque append() nativo escribiría la palabra "null" como texto.
        const secciones = [
            aviso ? h("p", { class: "aviso-estado", role: "status" }, aviso) : null,
            h("header", { class: "ficha-cabecera" },
                h("div", {},
                    h("h2", {}, a.nombre),
                    h("p", { class: "rut" }, a.rut_formateado,
                        a.dv_valido ? null : h("span", { class: "aviso-dv", title: "El dígito verificador no coincide con el RUT" }, " (dígito verificador dudoso)"))),
                insigniaEstado(a.estado)),
            // Aclara por qué está NO VIGENTE cuando lo decidió la aplicación y no el Excel
            a.estado_manual ? h("p", { class: "nota-estado" }, "Estado cambiado por la aplicación al registrar 3 meses seguidos sin pago.") : null,
            resumenSinPago(sin_pago),
            datos(a, pagador),
            pagaPor(paga_por),
            seccionPagos(a, pagos),
        ];
        contenedor.append(...secciones.filter((seccion) => seccion != null));
    }

    /**
     * Aviso destacado tras guardar un mes, según lo que respondió la API (o null si no hay nada que avisar):
     *   - la persona pasó a NO VIGENTE por completar 3 meses seguidos sin pago;
     *   - el cambio quedó guardado en la base pero no llegó a la hoja de Google
     *     (`pendiente` = se reintentará solo; `fallido` = la hoja lo rechazó).
     */
    function avisoDeRespuesta({ estado, hoja }) {
        const avisos = [];
        if (estado?.cambio) {
            avisos.push(`Con este cambio la persona acumula 3 meses seguidos sin pago y pasó de ${estado.anterior} a ${estado.nuevo}.`);
        }
        if (hoja === "pendiente") {
            avisos.push("El cambio se guardó, pero todavía no llega a la hoja de Google; se reintentará solo.");
        } else if (hoja === "fallido") {
            avisos.push("El cambio se guardó en la base, pero la hoja de Google lo rechazó: revisa que la persona y el mes existan en la hoja.");
        }
        return avisos.length ? avisos.join(" ") : null;
    }

    /**
     * Línea de aviso con los meses seguidos sin pago. Es solo informativa: se pinta en rojo
     * desde 3 meses (el umbral de pérdida de cobertura), pero no cambia el estado de la persona.
     */
    function resumenSinPago({ mes_referencia: mes, consecutivos }) {
        const critico = consecutivos >= 3;
        const texto = consecutivos === 0
            ? `Con pago al cierre de ${mesCorto(mes)}.`
            : `${consecutivos} mes${consecutivos === 1 ? "" : "es"} seguido${consecutivos === 1 ? "" : "s"} sin pago hasta ${mesCorto(mes)}.`;
        return h("p", { class: `sin-pago ${critico ? "sin-pago-critico" : ""}` }, texto);
    }

    /** Un par etiqueta/valor de la lista de datos; un valor vacío se muestra como "—". */
    function dato(etiqueta, valor) {
        return h("div", { class: "dato" },
            h("dt", {}, etiqueta),
            h("dd", {}, valor ?? h("span", { class: "vacio-texto" }, "—")));
    }

    /** Cuadrícula con los datos de la persona y de su póliza. */
    function datos(a, pagador) {
        // La baja puede tener fecha, texto libre ("VA A CORTE JULIO 2026") o ambas
        const baja = [fechaCorta(a.fecha_baja), a.fecha_baja_texto].filter(Boolean).join(" · ") || null;

        return h("dl", { class: "datos" },
            dato("Tipo", a.tipo),
            dato("Cobertura", a.cobertura),
            dato("Póliza", a.poliza),
            dato("Medio de pago", a.medio_pago),
            dato("Mail", a.mail),
            dato("Teléfono", a.telefono),
            dato("Fecha de alta", fechaCorta(a.fecha_alta)),
            dato("Fecha de titulación", fechaCorta(a.fecha_titulacion)),
            dato("Baja", baja),
            dato("Motivo de baja", a.motivo_baja),
            dato("Pagador", textoPagador(a, pagador)));
    }

    /**
     * Contenido del campo "Pagador" según el caso:
     *   - sin pagador, con una nota en vez de RUT en el Excel → se muestra la nota
     *   - pagador que no está en la nómina                    → solo su RUT
     *   - pagador que sí está en la nómina                    → enlace para abrir su ficha
     */
    function textoPagador(a, pagador) {
        if (!pagador) return a.rut_pagador_original ? `Nota: ${a.rut_pagador_original}` : null;
        if (!pagador.nombre) return `${pagador.rut_formateado} (no está en la nómina)`;
        return h("button", { type: "button", class: "enlace", onclick: () => alAbrirRut(pagador.rut) },
            `${pagador.nombre} · ${pagador.rut_formateado}`);
    }

    /** Lista de personas por las que esta persona paga (si lo hace); cada una abre su ficha. */
    function pagaPor(lista) {
        if (lista.length === 0) return null;

        return h("section", { class: "paga-por" },
            h("h3", {}, `Paga por ${lista.length} persona${lista.length === 1 ? "" : "s"}`),
            h("ul", {}, lista.map((p) =>
                h("li", {},
                    h("button", { type: "button", class: "enlace", onclick: () => alAbrirRut(p.rut) },
                        `${p.nombre} · ${p.rut_formateado}`),
                    insigniaEstado(p.estado)))));
    }

    // ------------------------------------------------------------ grilla de meses

    /**
     * Sección "Pagos por mes": una fila por año (el más reciente arriba) con sus 12 meses.
     * El rango de años va desde el primero con datos hasta el actual (o el último con datos,
     * si hay meses adelantados). No se dibujan años anteriores al primer dato: el Excel madre
     * no trae pagos antes de 2018, aunque la persona se haya afiliado antes.
     */
    function seccionPagos(a, pagos) {
        // Índice rápido: "2026-09" → datos del mes
        const porPeriodo = new Map(pagos.map((p) => [p.periodo, p]));
        const anioActual = new Date().getFullYear();
        const anios = pagos.map((p) => Number(p.periodo.slice(0, 4)));

        const desde = anios.length ? Math.min(...anios) : anioActual;
        const hasta = Math.max(anioActual, ...anios);

        const filas = [];
        for (let anio = hasta; anio >= desde; anio--) {
            filas.push(filaAnio(a, anio, porPeriodo));
        }

        return h("section", { class: "pagos" },
            h("h3", {}, "Pagos por mes"),
            h("p", { class: "ayuda" }, "Haz clic en un mes para editarlo."),
            leyenda(),
            h("div", { class: "grilla-anios" }, filas));
    }

    /** Explicación de los colores de la grilla. */
    function leyenda() {
        const item = (clase, texto) => h("li", {}, h("span", { class: `muestra ${clase}` }), texto);
        return h("ul", { class: "leyenda" },
            item("pagado", "Pagado"),
            item("impago", "Impago"),
            item("sin-datos", "Sin datos"),
            h("li", {}, h("span", { class: "marca-editado" }), "Editado a mano"));
    }

    /** Una fila de la grilla: el año y sus 12 meses. */
    function filaAnio(a, anio, porPeriodo) {
        const celdas = MESES_CORTO.map((nombre, i) => {
            const clavePeriodo = periodo(anio, i + 1);
            return celdaMes(a, clavePeriodo, nombre, porPeriodo.get(clavePeriodo) ?? null);
        });
        return h("div", { class: "anio" }, h("h4", {}, anio), h("div", { class: "meses" }, celdas));
    }

    /**
     * Un mes de la grilla, como botón que abre el editor.
     * El estado no depende solo del color (que no todos distinguen): lleva un símbolo
     * (✓ pagado, ✗ impago, · sin datos) y un texto accesible con el detalle completo.
     *
     * @param {object} a            la persona
     * @param {string} clavePeriodo "AAAA-MM"
     * @param {string} nombre       "Ene", "Feb"…
     * @param {object|null} pago    datos del mes, o null si no tiene
     */
    function celdaMes(a, clavePeriodo, nombre, pago) {
        const clase = !pago ? "sin-datos" : pago.pagado ? "pagado" : "impago";
        const estadoTexto = !pago ? "sin datos" : pago.pagado ? "pagado" : "impago";
        const detalle = [
            pago?.monto != null ? `$${formatoMonto(pago.monto)}` : null,
            pago?.valor_original ? `Excel: ${pago.valor_original}` : null,
            pago?.nota ? `Nota: ${pago.nota}` : null,
            pago?.editado ? "editado a mano" : null,
        ].filter(Boolean);

        return h("button", {
            type: "button",
            class: `mes ${clase}${pago?.editado ? " editado" : ""}`,
            title: [nombreMes(clavePeriodo), estadoTexto, ...detalle].join(" · "), // tooltip al pasar el mouse
            "aria-label": [nombreMes(clavePeriodo), estadoTexto, ...detalle].join(", "), // lectores de pantalla
            // Al guardar en el editor, se recarga la ficha para reflejar el cambio; si hay algo
            // que avisar (pérdida de vigencia, cambio que no llegó a la hoja) se muestra destacado
            onclick: () => abrirEditor(
                { rut: a.rut, nombre: a.nombre, periodo: clavePeriodo, pago },
                (respuesta) => cargar(a.rut, avisoDeRespuesta(respuesta))),
        },
            h("span", { class: "mes-nombre" }, nombre),
            h("span", { class: "mes-valor", "aria-hidden": "true" },
                !pago ? "·" : pago.monto != null ? formatoMonto(pago.monto) : pago.pagado ? "✓" : "✗"));
    }

    limpiar(); // estado inicial
    return { cargar, limpiar, rutActual: () => rutActual };
}
