/**
 * Panel derecho: ficha de la persona seleccionada.
 *
 * Flujo de datos:
 *   cargar(rut) → api.obtenerAsegurado() → pintar() arma, de arriba abajo:
 *     cabecera (nombre, RUT) → datos de la persona (especialidad, otro tipo, contacto, sociedad)
 *     → UN bloque por cada póliza (una persona puede tener varias), y en cada uno:
 *         cabecera (producto, estado, botones) → aviso de meses sin pago → datos de la póliza
 *         → grilla de pagos por mes
 *     → "paga por" (si es pagador de otras personas)
 *   clic en un mes de la grilla → editorPago.abrirEditor() → al guardar, cargar(rut) de nuevo
 *
 * Los datos vacíos se muestran como "--".
 * Todo se construye con h() (src/js/dom.js), sin innerHTML.
 */

import { obtenerAsegurado } from "./api.js";
import { avisoCorte, insigniaEstado } from "./buscador.js";
import { generarCertificado, urlVerificacion } from "./certificado.js";
import { generarPoliza, tienePlantilla } from "./poliza.js";
import { h, vaciar } from "./dom.js";
import { abrirEditor } from "./editorPago.js";
import { abrirEditorPersona, productoSegunEspecialidad } from "./editorPersona.js";
import { abrirEditorPoliza } from "./editorPoliza.js";
import { fechaCorta, formatoMonto, MESES_CORTO, mesCorto, nombreMes, nombreProducto, periodo } from "./formato.js";

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
    const minimizadas = new Set(); // ids de las pólizas minimizadas (se conservan cuando la ficha se refresca sola)

    /** Reemplaza todo el panel por un mensaje (cargando, error, "busca un asegurado"…). */
    function mensaje(texto, clase = "") {
        vaciar(contenedor);
        contenedor.append(h("p", { class: `mensaje ${clase}` }, texto));
    }

    /**
     * Trae los datos de la API y los pinta.
     * @param {?string} aviso  mensaje destacado para mostrar arriba de la ficha (opcional), por
     *        ejemplo cuando una póliza acaba de pasar a NO VIGENTE
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
    function pintar({ asegurado: a, polizas, paga_por }, aviso) {
        vaciar(contenedor);
        // Las secciones opcionales vienen como null cuando no aplican. Se descartan antes de
        // insertar porque append() nativo escribiría la palabra "null" como texto.
        const secciones = [
            aviso ? h("p", { class: "aviso-estado", role: "status" }, aviso) : null,
            h("header", { class: "ficha-cabecera" },
                h("div", {},
                    h("h2", {}, a.nombre),
                    h("p", { class: "rut" }, a.rut_formateado,
                        a.dv_valido ? null : h("span", { class: "aviso-dv", title: "El dígito verificador no coincide con el RUT" }, " (dígito verificador dudoso)")),
                    a.con_siniestro
                        ? h("span", { class: "insignia insignia-siniestro", title: "Usó el seguro: paga deducible en todas sus pólizas" }, `Con siniestro · deducible ${a.deducible_uf ?? "--"} UF`)
                        : null),
                h("div", { class: "ficha-acciones" },
                    h("button", { type: "button", class: "boton", onclick: () => abrirEditorPersona({ persona: a, productosActuales: [...new Set(polizas.map((z) => z.producto).filter(Boolean))] },
                        (respuesta) => cargar(a.rut, avisoDePersona(respuesta))) }, "Editar persona"),
                    h("button", { type: "button", class: "boton", onclick: () => abrirEditorPoliza({ rut: a.rut, nombre: a.nombre, especialidad: a.especialidad, conSiniestro: a.con_siniestro, deducibleSugerido: a.con_siniestro ? a.deducible_uf : null, sugerenciaProducto: a.con_siniestro ? "Individual" : productoSegunEspecialidad(a.especialidad, a.tipo_individuo) }, () => cargar(a.rut, "Póliza agregada.")) },
                        "Agregar póliza"))),
            datosPersona(a),
            ...(polizas.length
                ? polizas.map((poliza) => seccionPoliza(a, poliza))
                : [h("p", { class: "mensaje" }, "Esta persona no tiene pólizas registradas.")]),
            pagaPor(paga_por),
        ];
        contenedor.append(...secciones.filter((seccion) => seccion != null));
    }

    /**
     * Aviso destacado tras guardar un mes, según lo que respondió la API (o null si no hay nada que avisar):
     *   - la póliza pasó a NO VIGENTE por completar 2 meses seguidos sin pago;
     *   - la póliza sigue VIGENTE pero quedó con el aviso de corte por 1 mes sin pago;
     *   - el cambio quedó guardado en la base pero no llegó a la hoja de Google
     *     (`pendiente` = se reintentará solo; `fallido` = la hoja lo rechazó).
     * Las pólizas que no están en la hoja (`no_aplica`) no generan aviso de hoja.
     */
    function avisoDeRespuesta({ estado, hoja }) {
        const avisos = [];
        if (estado?.cambio) {
            avisos.push(`Con este cambio la póliza acumula 2 meses seguidos sin pago y pasó de ${estado.anterior} a ${estado.nuevo}.`);
        } else if (estado?.aviso_corte) {
            avisos.push("La póliza queda vigente con aviso de corte por 1 mes sin pago. Puedes quitar el aviso en «Editar póliza» si es una excepción.");
        }
        if (hoja === "pendiente") {
            avisos.push("El cambio se guardó, pero todavía no llega a la hoja de Google; se reintentará solo.");
        } else if (hoja === "fallido") {
            avisos.push("El cambio se guardó en la base, pero la hoja de Google lo rechazó: revisa que la persona y el mes existan en la hoja.");
        }
        return avisos.length ? avisos.join(" ") : null;
    }

    /**
     * Aviso tras editar a la persona: si con su especialidad le corresponde una póliza que no tiene,
     * se dice cuál (las pólizas no se cambian solas); si no, solo se confirma el guardado.
     */
    function avisoDePersona({ message, producto_sugerido: sugerido, productos_actuales: actuales, polizas_con_deducible_completadas: completadas }) {
        if (completadas) message += `. Se puso el deducible a ${completadas} póliza${completadas === 1 ? "" : "s"} que no lo tenía${completadas === 1 ? "" : "n"}`;
        if (sugerido && !actuales.includes(sugerido)) {
            return `${message}. Con su especialidad le corresponde la póliza ${nombreProducto(sugerido)}; hoy tiene ${actuales.length ? actuales.map(nombreProducto).join(", ") : "ninguna con producto"}. Puedes ajustarla con «Editar póliza».`;
        }
        return `${message}.`;
    }

    /** Un par etiqueta/valor de la lista de datos; un valor vacío se muestra como "--". */
    function dato(etiqueta, valor) {
        return h("div", { class: "dato" },
            h("dt", {}, etiqueta),
            h("dd", {}, valor ?? h("span", { class: "vacio-texto" }, "--")));
    }

    /** Datos de la persona (iguales para todas sus pólizas). */
    function datosPersona(a) {
        const ubicacion = [a.comuna, a.ciudad].filter(Boolean).join(" · ") || null;
        return h("dl", { class: "datos" },
            dato("Especialidad", a.especialidad),
            dato("Otro tipo", a.tipo_individuo),
            dato("Mail", a.mail),
            dato("Teléfono", a.telefono),
            dato("Fecha de nacimiento", fechaCorta(a.fecha_nacimiento)),
            dato("Dirección", a.direccion),
            dato("Comuna · Ciudad", ubicacion),
            dato("Ejecutivo", a.ejecutivo),
            dato("Sociedad", textoSociedad(a.sociedad)));
    }

    /** Nombre de la sociedad con su RUT; "--" si no tiene (aún no se cargó o es persona natural). */
    function textoSociedad(sociedad) {
        if (!sociedad) return null;
        return [sociedad.nombre, sociedad.rut_formateado].filter(Boolean).join(" · ");
    }

    /** Un valor en dinero o UF: enteros con separador de miles, decimales con coma. */
    function textoValor(valor) {
        if (valor == null) return null;
        return Number.isInteger(valor) ? formatoMonto(valor) : String(valor).replace(".", ",");
    }

    /** 99999 es el número provisorio de las pólizas que aún no se han registrado: se aclara en pantalla. */
    function textoNumero(numero) {
        return numero === "99999" ? "99999 (sin póliza asignada)" : numero;
    }

    /** "01-06-2026 a 01-06-2027" del período actual (null si aún no hay períodos cargados). */
    function textoVigencia(vigencia) {
        if (!vigencia) return null;
        return `${fechaCorta(vigencia.desde)} a ${fechaCorta(vigencia.hasta)}`;
    }

    // ------------------------------------------------------------ una póliza

    /**
     * Bloque de UNA póliza: su cabecera con estado y botones, los meses seguidos sin pago,
     * sus datos y su grilla de pagos. Cada póliza tiene su propio estado y sus propios pagos.
     */
    function seccionPoliza(a, poliza) {
        const minimizada = minimizadas.has(poliza.id);
        const boton = h("button", { type: "button", class: "boton alternar", "aria-expanded": String(!minimizada) }, minimizada ? "Expandir" : "Minimizar");
        const seccion = h("section", { class: `poliza${minimizada ? " minimizada" : ""}` },
            h("header", { class: "poliza-cabecera" },
                h("div", {},
                    h("h3", {}, nombreProducto(poliza.producto) ?? "Sin producto asignado"),
                    h("p", { class: "poliza-sub" },
                        `Cobertura: ${textoVigencia(poliza.cobertura_vigente) ?? "--"}${poliza.cobertura_vigente?.renovada ? " (renovada automáticamente)" : ""}`),
                    // Solo se ve con la póliza minimizada: el plan de cobertura contratado
                    h("p", { class: "poliza-sub solo-min" }, `Plan de cobertura: ${poliza.cobertura ?? "--"}${/^\d+$/.test(poliza.cobertura ?? "") ? " UF" : ""}`)),
                h("div", { class: "ficha-acciones" },
                    insigniaEstado(poliza.estado),
                    poliza.va_a_corte ? avisoCorte() : null,
                    h("button", { type: "button", class: "boton", onclick: () => abrirEditorPoliza({ rut: a.rut, nombre: a.nombre, poliza, especialidad: a.especialidad, conSiniestro: a.con_siniestro }, (respuesta) => cargar(a.rut, `${respuesta?.message ?? "Póliza actualizada"}.`)) }, "Editar póliza"),
                    h("a", { class: "boton", target: "_blank", rel: "noopener", href: urlVerificacion(poliza) }, "Verificar vigencia"),
                    tienePlantilla(poliza.producto)
                        ? h("button", { type: "button", class: "boton", onclick: () => generarPoliza(a, poliza) }, "Generar póliza")
                        : null,
                    h("button", { type: "button", class: "boton boton-primario", onclick: () => generarCertificado(a, poliza) }, "Generar certificado"),
                    boton)),
            // Aclara por qué está NO VIGENTE cuando lo decidió la aplicación y no el Excel
            poliza.estado_manual ? h("p", { class: "nota-estado" }, "Estado cambiado por la aplicación al registrar 2 meses seguidos sin pago.") : null,
            poliza.va_a_corte ? h("p", { class: "nota-corte" }, "Aviso: esta póliza va a corte. Sigue vigente, pero hay riesgo de que se corte." + (poliza.fecha_baja_texto ? ` (${poliza.fecha_baja_texto})` : "")) : null,
            resumenSinPago(poliza),
            datosPoliza(poliza),
            seccionPagos(a, poliza));

        // Minimizar deja solo el producto, la cobertura y los pagos por mes (el resto se oculta con CSS: ver .minimizada)
        boton.addEventListener("click", () => {
            const ahoraMinimizada = seccion.classList.toggle("minimizada");
            if (ahoraMinimizada) minimizadas.add(poliza.id); else minimizadas.delete(poliza.id);
            boton.textContent = ahoraMinimizada ? "Expandir" : "Minimizar";
            boton.setAttribute("aria-expanded", String(!ahoraMinimizada));
        });
        return seccion;
    }

    /**
     * Línea de aviso con los meses seguidos sin pago (o, si el período de la póliza ya terminó, su vencimiento y
     * su último pago). Es solo informativa: se pinta en rojo desde 2 meses (el umbral de pérdida de cobertura),
     * pero no cambia el estado de la póliza.
     */
    function resumenSinPago(poliza) {
        const { mes_referencia: mes, consecutivos } = poliza.sin_pago;
        if (poliza.pagos.length === 0) return h("p", { class: "sin-pago" }, "Sin pagos registrados.");

        // Una póliza de un período ya terminado no está "sin pagar": se informa su vencimiento y su último pago
        const hasta = poliza.vigencia_actual?.hasta;
        if (hasta && hasta < new Date().toISOString().slice(0, 10)) {
            const ultimoPago = [...poliza.pagos].reverse().find((p) => p.pagado);
            return h("p", { class: "sin-pago" }, `Póliza vencida el ${fechaCorta(hasta)}.${ultimoPago ? ` Último pago registrado: ${mesCorto(ultimoPago.periodo)}.` : ""}`);
        }

        const critico = consecutivos >= 2;
        const texto = consecutivos === 0
            ? `Con pago al cierre de ${mesCorto(mes)}.`
            : `${consecutivos} mes${consecutivos === 1 ? "" : "es"} seguido${consecutivos === 1 ? "" : "s"} sin pago hasta ${mesCorto(mes)}.`;
        return h("p", { class: `sin-pago ${critico ? "sin-pago-critico" : ""}` }, texto);
    }

    /** Cuadrícula con los datos de una póliza. */
    function datosPoliza(poliza) {
        // La baja puede tener fecha, texto libre ("VA A CORTE JULIO 2026") o ambas
        const baja = [fechaCorta(poliza.fecha_baja), poliza.fecha_baja_texto].filter(Boolean).join(" · ") || null;

        return h("dl", { class: "datos" },
            dato("N° de póliza", textoNumero(poliza.vigencia_actual?.numero ?? poliza.numero)),
            dato("Período de la póliza", textoVigencia(poliza.vigencia_actual)),
            dato("Inicio de la cobertura", fechaCorta(poliza.cobertura_desde)),
            dato("Tipo de contrato", poliza.tipo_contrato),
            dato("Cobertura (UF)", poliza.cobertura),
            dato("Medio de pago", poliza.medio_pago),
            dato("Compañía", poliza.compania),
            dato("Corredor", poliza.corredor),
            dato("Valor Lega", textoValor(poliza.valor_lega)),
            dato("Valor póliza", textoValor(poliza.valor_poliza)),
            dato("Deducible (UF)", textoValor(poliza.deducible_uf)),
            dato("Fecha de alta", fechaCorta(poliza.fecha_alta)),
            dato("Fecha de titulación", fechaCorta(poliza.fecha_titulacion)),
            dato("Baja", baja),
            dato("Motivo de baja", poliza.motivo_baja),
            dato("Fecha de renuncia", fechaCorta(poliza.fecha_renuncia)),
            dato("Envío de certificado", fechaCorta(poliza.fecha_envio_certificado)),
            dato("Envío de póliza", fechaCorta(poliza.fecha_envio_poliza)),
            dato("N° de certificado", poliza.numero_certificado),
            dato("Pagador", textoPagador(poliza)),
            dato("Observaciones", poliza.observaciones));
    }

    /**
     * Contenido del campo "Pagador" según el caso:
     *   - sin pagador, con una nota en vez de RUT en el Excel → se muestra la nota
     *   - pagador que no está en la nómina                    → solo su RUT
     *   - pagador que sí está en la nómina                    → enlace para abrir su ficha
     */
    function textoPagador({ pagador, rut_pagador_original: original }) {
        if (!pagador) return original ? `Nota: ${original}` : null;
        if (!pagador.nombre) return `${pagador.rut_formateado} (no está en la nómina)`;
        return h("button", { type: "button", class: "enlace", onclick: () => alAbrirRut(pagador.rut) },
            `${pagador.nombre} · ${pagador.rut_formateado}`);
    }

    /** Lista de personas por las que esta persona paga (si lo hace); cada una abre su ficha. */
    function pagaPor(lista) {
        if (lista.length === 0) return null;

        return h("section", { class: "paga-por" },
            h("h3", {}, `Paga por ${lista.length} póliza${lista.length === 1 ? "" : "s"}`),
            h("ul", {}, lista.map((p) =>
                h("li", {},
                    h("button", { type: "button", class: "enlace", onclick: () => alAbrirRut(p.rut) },
                        `${p.nombre} · ${p.rut_formateado}${p.producto ? ` · ${nombreProducto(p.producto)}` : ""}`),
                    insigniaEstado(p.estado)))));
    }

    // ------------------------------------------------------------ grilla de meses

    /**
     * Sección "Pagos por mes" de una póliza: una fila por año (el más reciente arriba) con sus 12
     * meses. El rango de años va desde el primero con datos hasta el actual (o el último con datos,
     * si hay meses adelantados). No se dibujan años anteriores al primer dato.
     */
    function seccionPagos(a, poliza) {
        const pagos = poliza.pagos;
        // Índice rápido: "2026-09" → datos del mes
        const porPeriodo = new Map(pagos.map((p) => [p.periodo, p]));
        const anioActual = new Date().getFullYear();
        const anios = pagos.map((p) => Number(p.periodo.slice(0, 4)));

        const desde = anios.length ? Math.min(...anios) : anioActual;
        const hasta = Math.max(anioActual, ...anios);

        const filas = [];
        for (let anio = hasta; anio >= desde; anio--) {
            filas.push(filaAnio(a, poliza, anio, porPeriodo));
        }

        return h("section", { class: "pagos" },
            h("h4", { class: "pagos-titulo" }, "Pagos por mes"),
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
    function filaAnio(a, poliza, anio, porPeriodo) {
        const celdas = MESES_CORTO.map((nombre, i) => {
            const clavePeriodo = periodo(anio, i + 1);
            return celdaMes(a, poliza, clavePeriodo, nombre, porPeriodo.get(clavePeriodo) ?? null);
        });
        return h("div", { class: "anio" }, h("h4", {}, anio), h("div", { class: "meses" }, celdas));
    }

    /**
     * Un mes de la grilla, como botón que abre el editor.
     * El estado no depende solo del color (que no todos distinguen): lleva un símbolo
     * (✓ pagado, ✗ impago, · sin datos) y un texto accesible con el detalle completo.
     *
     * @param {object} a            la persona
     * @param {object} poliza       la póliza a la que pertenece el mes
     * @param {string} clavePeriodo "AAAA-MM"
     * @param {string} nombre       "Ene", "Feb"…
     * @param {object|null} pago    datos del mes, o null si no tiene
     */
    function celdaMes(a, poliza, clavePeriodo, nombre, pago) {
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
                { polizaId: poliza.id, nombre: `${a.nombre} · ${nombreProducto(poliza.producto) ?? "sin producto"}`, periodo: clavePeriodo, pago },
                (respuesta) => cargar(a.rut, avisoDeRespuesta(respuesta))),
        },
            h("span", { class: "mes-nombre" }, nombre),
            h("span", { class: "mes-valor", "aria-hidden": "true" },
                !pago ? "·" : pago.monto != null ? formatoMonto(pago.monto) : pago.pagado ? "✓" : "✗"));
    }

    limpiar(); // estado inicial
    return { cargar, limpiar, rutActual: () => rutActual };
}
