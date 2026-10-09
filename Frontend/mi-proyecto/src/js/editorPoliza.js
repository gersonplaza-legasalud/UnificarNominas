/**
 * Diálogo "Editar póliza" / "Agregar póliza".
 *
 * Flujo de datos:
 *   clic en "Editar póliza" (o "Agregar póliza") → abrirEditorPoliza() pide los catálogos a la API,
 *   arma el formulario con los datos actuales → el usuario edita y guarda → api.guardarPoliza()
 *   (POST poliza.php) → si la API acepta: se cierra y se llama alGuardar() para recargar la ficha;
 *   si la rechaza: el error se muestra dentro del diálogo.
 *
 * Al elegir producto y número, las fechas de vigencia se proponen desde la póliza matriz
 * (p. ej. SOEMAF 28581 → 01-02-2026 a 01-08-2027) mientras el usuario no las haya tocado.
 * El formulario se construye con h() (src/js/dom.js), sin innerHTML.
 */

import { guardarPoliza, obtenerCatalogos } from "./api.js";
import { h, vaciar } from "./dom.js";
import { fechaCorta, nombreProducto } from "./formato.js";

const dialogo = document.getElementById("dialogPoliza");
const formulario = document.getElementById("formPoliza");

const ESTADOS = ["VIGENTE", "NO VIGENTE"];
const NUMERO_SIN_POLIZA = "99999";
// Sugerencias frecuentes (el campo acepta cualquier otro valor)
const TIPOS_CONTRATO = ["LEGA + SEGURO", "COLEGIO + SEGURO", "SOLO COLEGIO", "SOLO LEGASALUD"];
const COMPANIAS = ["CHUBB", "ACE"];
const CORREDORES = ["TRUST", "FR GROUP", "MARIA ELENA"];

/** Una opción de lista desplegable. */
const opcion = (valor, texto = valor, seleccionada = false) => h("option", { value: valor, selected: seleccionada }, texto);

/** Lista de sugerencias para un <input list="...">. */
const lista = (id, valores) => h("datalist", { id }, valores.map((v) => h("option", { value: v })));

/** Nombre sin tildes, en minúsculas y solo letras y números (la misma clave que usa el servidor para las especialidades). */
const claveEspecialidad = (t) => String(t ?? "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, " ").trim();

const dosDigitos = (n) => String(n).padStart(2, "0");
const hoyISO = () => { const d = new Date(); return `${d.getFullYear()}-${dosDigitos(d.getMonth() + 1)}-${dosDigitos(d.getDate())}`; };
const primeroDelMesSiguiente = () => { const d = new Date(); const s = new Date(d.getFullYear(), d.getMonth() + 1, 1); return `${s.getFullYear()}-${dosDigitos(s.getMonth() + 1)}-01`; };

/**
 * Prima (Valor póliza, en UF) que propone la tabla de primas: la de la CLASE de la especialidad para el LÍMITE de la cobertura.
 * No se propone para personas con siniestro (su prima es personal) ni para Odontólogo (no está en la tabla).
 * @returns {{prima:?number, texto:string}}
 */
function primaSugerida(cat, { producto, especialidad, conSiniestro, cobertura }) {
    if (conSiniestro) return { prima: null, texto: "Persona con siniestro: su prima es personal, ingrésala a mano." };
    if (producto === "Odontólogo") return { prima: null, texto: "La prima de ODONTÓLOGO no está en la tabla de primas: ingrésala a mano." };
    if (!producto) return { prima: null, texto: "Elige el producto para proponer la prima." };
    const clave = claveEspecialidad(especialidad);
    const dentista = /dentista|odont|maxilo/.test(clave);
    const porClase = cat.primas?.por_clase ?? {};
    const clasePorEspecialidad = new Map((cat.primas?.especialidades ?? []).map((e) => [claveEspecialidad(e.especialidad), e.clase]));
    let clase;
    let limite = /^\d+$/.test(cobertura) ? Number(cobertura) : null;
    if (producto === "SOEMAF" && dentista) { clase = 5; limite = 2000; } // dentista con estética (Dermo): 2000 UF plano
    else clase = clasePorEspecialidad.get(clave);
    if (!clase) return { prima: null, texto: especialidad ? `La especialidad «${especialidad}» no está en la tabla de primas: ingrésala a mano.` : "Sin especialidad no se puede proponer la prima: ingrésala a mano." };
    if (!limite) return { prima: null, texto: `Clase ${clase}. Indica la cobertura (2000, 2500, 3000, 5000 o 7000) para proponer la prima.` };
    const prima = porClase[clase]?.[limite];
    if (prima === undefined) return { prima: null, texto: `La tabla no tiene prima para la clase ${clase} con ${limite} UF: ingrésala a mano.` };
    const redondeada = Math.round(prima * 100) / 100;
    return { prima: redondeada, texto: `Tabla de primas: clase ${clase} · ${limite} UF → ${String(redondeada).replace(".", ",")} UF.` };
}

/**
 * Abre el diálogo.
 * @param {{rut:number, nombre:string, poliza?:object}} ctx  `poliza` (de api/asegurado.php) para editar; sin ella se agrega una nueva.
 *        `especialidad` y `conSiniestro` son de la persona (sirven para proponer la prima); `sugerenciaProducto` es el producto que
 *        le corresponde (la póliza nueva lo trae elegido).
 * @param {(respuesta:object) => void} alGuardar  se llama tras guardar con éxito
 */
export async function abrirEditorPoliza({ rut, nombre, poliza = null, deducibleSugerido = null, especialidad = null, conSiniestro = false, sugerenciaProducto = null }, alGuardar) {
    let cat;
    try {
        cat = await obtenerCatalogos();
    } catch (error) {
        alert(error.message);
        return;
    }
    construir({ rut, nombre, poliza, deducibleSugerido, especialidad, conSiniestro, sugerenciaProducto, cat, alGuardar });
    dialogo.showModal();
}

function construir({ rut, nombre, poliza, deducibleSugerido, especialidad, conSiniestro, sugerenciaProducto, cat, alGuardar }) {
    const editando = poliza !== null;
    const vigencia = poliza?.vigencia_actual ?? null;
    let fechasTocadas = false; // si el usuario ya cambió las fechas a mano, no se pisan con la sugerencia

    // Una póliza nueva parte con el producto sugerido, el número vigente y los valores habituales (todo se puede cambiar)
    const productoInicial = poliza?.producto ?? sugerenciaProducto ?? null;
    const numeroInicial = poliza?.vigencia_actual?.numero ?? poliza?.numero
        ?? cat.matrices.find((m) => m.producto === productoInicial && m.actual)?.numero ?? NUMERO_SIN_POLIZA;
    const planInicial = { SOEMAF: "2000", "Odontólogo": "3000" }[productoInicial] ?? ""; // SOEMAF es 2000 plano; Odontólogo parte en 3000

    // ---- controles ----
    const producto = h("select", { id: "polProducto" },
        opcion("", "Sin producto asignado", !productoInicial),
        cat.productos.map((p) => opcion(p, nombreProducto(p), p === productoInicial)));
    const numero = h("input", { type: "text", id: "polNumero", inputmode: "numeric", maxlength: 30, value: numeroInicial });
    const estado = h("select", { id: "polEstado" }, ESTADOS.map((e) => opcion(e, e, e === (poliza?.estado ?? "VIGENTE"))));
    // "Va a corte" es un aviso (la póliza sigue vigente), no un estado
    const vaACorte = h("input", { type: "checkbox", id: "polVaACorte" });
    vaACorte.checked = Boolean(poliza?.va_a_corte);
    const sincronizarCorte = () => { vaACorte.disabled = estado.value !== "VIGENTE"; if (vaACorte.disabled) vaACorte.checked = false; };
    estado.addEventListener("change", sincronizarCorte);
    sincronizarCorte();
    const desde = h("input", { type: "date", id: "polDesde", value: vigencia?.desde ?? "" });
    const hasta = h("input", { type: "date", id: "polHasta", value: vigencia?.hasta ?? "" });
    const inicioCobertura = h("input", { type: "date", id: "polInicioCob", value: poliza ? (poliza.cobertura_desde ?? "") : primeroDelMesSiguiente() });
    const ayudaVigencia = h("p", { class: "ayuda campo-completo" });
    // Cambiar el plan (cobertura) reinicia el inicio de la cobertura: se propone el 1.º del mes siguiente (editable)
    const ayudaPlan = h("p", { class: "ayuda campo-completo aviso-producto aviso-producto-alerta" });
    ayudaPlan.hidden = true;

    const texto = (id, valor, extra = {}) => h("input", { type: "text", id, value: valor ?? "", ...extra });
    const numerico = (id, valor) => h("input", { type: "number", id, min: 0, step: "any", value: valor ?? "" });
    const tipoContrato = texto("polContrato", poliza ? poliza.tipo_contrato : "LEGA + SEGURO", { list: "listaContratos", maxlength: 40 });
    const compania = texto("polCompania", poliza ? poliza.compania : "CHUBB", { list: "listaCompanias", maxlength: 40 });
    const corredor = texto("polCorredor", poliza ? poliza.corredor : "TRUST", { list: "listaCorredores", maxlength: 60 });
    const cobertura = texto("polCobertura", poliza ? poliza.cobertura : planInicial, { list: "listaCoberturas", maxlength: 40 });
    // Al cambiar el plan de una póliza que ya existe, el inicio de la cobertura se reinicia (1.º del mes siguiente al cambio)
    cobertura.addEventListener("change", () => {
        if (!poliza || cobertura.value.trim() === (poliza.cobertura ?? "")) { ayudaPlan.hidden = true; return; }
        const hoy = new Date();
        const siguiente = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 1);
        inicioCobertura.value = `${siguiente.getFullYear()}-${String(siguiente.getMonth() + 1).padStart(2, "0")}-01`;
        ayudaPlan.hidden = false;
        ayudaPlan.textContent = "Cambió el plan: el inicio de la cobertura se reinició al 1.º del mes siguiente. Ajústalo si el cambio rige desde otra fecha; la póliza vigente tomará la nueva fecha.";
    });
    const medioPago = texto("polMedio", poliza?.medio_pago, { list: "listaMedios", maxlength: 40 });
    const fechaAlta = h("input", { type: "date", id: "polAlta", value: poliza ? (poliza.fecha_alta ?? "") : hoyISO() });
    const fechaRenuncia = h("input", { type: "date", id: "polRenuncia", value: poliza?.fecha_renuncia ?? "" });
    const valorLega = numerico("polValorLega", poliza?.valor_lega);
    const valorPoliza = numerico("polValorPoliza", poliza?.valor_poliza);
    // Persona con siniestro: la póliza nueva propone su deducible (si no se indica, el servidor igual se lo pone)
    const deducible = numerico("polDeducible", poliza?.deducible_uf ?? deducibleSugerido);
    // Prima de la tabla: se rellena sola en una póliza nueva mientras no se escriba a mano; en una existente solo se ofrece
    const ayudaPrima = h("p", { class: "ayuda" });
    const usarPrima = h("button", { type: "button", class: "boton boton-chico" }, "Usar este valor");
    usarPrima.hidden = true;
    let primaTocada = Boolean(poliza?.valor_poliza);
    valorPoliza.addEventListener("input", () => { primaTocada = true; });
    function actualizarPrima() {
        const r = primaSugerida(cat, { producto: producto.value, especialidad, conSiniestro, cobertura: cobertura.value.trim() });
        ayudaPrima.textContent = r.texto;
        usarPrima.hidden = r.prima === null;
        usarPrima.onclick = () => { valorPoliza.value = r.prima; primaTocada = true; };
        if (!poliza && r.prima !== null && !primaTocada) valorPoliza.value = r.prima;
    }
    producto.addEventListener("change", actualizarPrima);
    cobertura.addEventListener("input", actualizarPrima);
    const certificado = texto("polCertificado", poliza?.numero_certificado, { maxlength: 30 });
    const pagador = texto("polPagador", poliza?.rut_pagador_original, { maxlength: 30, placeholder: "RUT o una nota" });
    const observaciones = h("textarea", { id: "polObservaciones", rows: 3, maxlength: 2000 }, poliza?.observaciones ?? "");
    const error = h("p", { class: "mensaje error", role: "alert" });
    const guardar = h("button", { type: "submit", class: "boton boton-primario" }, "Guardar");

    /** Etiqueta + control (ocupa las dos columnas si es `completo`). */
    const campo = (etiqueta, control, completo = false) =>
        h("div", { class: completo ? "campo campo-completo" : "campo" }, h("label", { for: control.id }, etiqueta), control);

    // ---- sugerencias desde la póliza matriz ----
    const matriz = () => cat.matrices.find((m) => m.producto === producto.value && m.numero === numero.value.trim());
    const matrizActual = () => cat.matrices.find((m) => m.producto === producto.value && m.actual);

    function actualizarVigencia() {
        const m = matriz();
        if (m && !fechasTocadas) {
            desde.value = m.desde;
            hasta.value = m.hasta;
        }
        ayudaVigencia.textContent = m
            ? `Póliza matriz ${m.numero} de ${nombreProducto(m.producto)}: ${fechaCorta(m.desde)} a ${fechaCorta(m.hasta)}${m.aproximada ? " (fechas aproximadas)" : ""}.`
            : "Este número no es una póliza matriz conocida de ese producto: ingresa las fechas a mano o déjalas vacías.";
    }

    producto.addEventListener("change", () => {
        // Si aún no hay número real, se propone el vigente del producto
        const actual = matrizActual();
        if (actual && (numero.value.trim() === "" || numero.value.trim() === NUMERO_SIN_POLIZA)) numero.value = actual.numero;
        actualizarVigencia();
    });
    numero.addEventListener("input", actualizarVigencia);
    for (const f of [desde, hasta]) f.addEventListener("input", () => { fechasTocadas = true; });
    actualizarVigencia();
    actualizarPrima();
    // Al abrir una póliza que ya tiene fechas propias, se respetan
    fechasTocadas = Boolean(vigencia);

    // ---- formulario ----
    vaciar(formulario);
    formulario.append(
        h("h2", {}, editando ? "Editar póliza" : "Agregar póliza"),
        h("p", { class: "subtitulo" }, `${nombre} · ${rut}`),
        h("div", { class: "campos" },
            campo("Producto", producto),
            campo("N° de póliza", numero),
            campo("Estado", estado),
            campo("Aviso", h("label", { class: "casilla" }, vaACorte, " Va a corte (sigue vigente, con riesgo de corte)")),
            campo("Medio de pago", medioPago),
            campo("Inicio de la cobertura", inicioCobertura),
            ayudaPlan,
            h("p", { class: "ayuda" }, "La cobertura dura 12 meses y se renueva sola cada año mientras siga vigente. Es la fecha que lleva el certificado."),
            campo("Período de la póliza: desde", desde),
            campo("Período de la póliza: hasta", hasta),
            ayudaVigencia,
            campo("Tipo de contrato", tipoContrato),
            campo("Cobertura (UF)", cobertura),
            campo("Compañía", compania),
            campo("Corredor", corredor),
            campo("Fecha de alta", fechaAlta),
            campo("Fecha de renuncia", fechaRenuncia),
            campo("Valor Lega", valorLega),
            campo("Valor póliza", valorPoliza),
            h("div", { class: "campo-completo ayuda-prima" }, ayudaPrima, usarPrima),
            campo("Deducible (UF)", deducible),
            campo("N° de certificado", certificado),
            campo("Pagador (RUT o nota)", pagador, true),
            campo("Observaciones", observaciones, true)),
        lista("listaContratos", TIPOS_CONTRATO), lista("listaCompanias", COMPANIAS), lista("listaCorredores", CORREDORES),
        lista("listaCoberturas", cat.coberturas), lista("listaMedios", cat.medios_pago),
        error,
        h("div", { class: "acciones" },
            h("button", { type: "button", class: "boton", onclick: () => dialogo.close() }, "Cancelar"),
            guardar));

    // ---- envío ----
    formulario.onsubmit = async (evento) => {
        evento.preventDefault();
        error.textContent = "";
        const t = (el) => el.value.trim() || null;
        const n = (el) => (el.value.trim() === "" ? null : Number(el.value));

        guardar.disabled = true; // evita el doble envío
        try {
            const respuesta = await guardarPoliza({
                ...(editando ? { id: poliza.id } : { rut }),
                producto: t(producto), numero: t(numero), estado: estado.value, va_a_corte: vaACorte.checked,
                vigencia_desde: t(desde), vigencia_hasta: t(hasta),
                tipo_contrato: t(tipoContrato), compania: t(compania), corredor: t(corredor), cobertura: t(cobertura), medio_pago: t(medioPago),
                fecha_alta: t(fechaAlta), fecha_renuncia: t(fechaRenuncia), cobertura_desde: t(inicioCobertura),
                valor_lega: n(valorLega), valor_poliza: n(valorPoliza), deducible_uf: n(deducible),
                numero_certificado: t(certificado), pagador: t(pagador), observaciones: t(observaciones),
            });
            dialogo.close();
            alGuardar(respuesta);
        } catch (e) {
            error.textContent = e.message;
            guardar.disabled = false;
        }
    };
}
