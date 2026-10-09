/**
 * Diálogo "Nuevo cliente": crea una persona y, a continuación, abre "Agregar póliza" con los valores sugeridos.
 *
 * Paso 1 (este archivo): datos de la persona (RUT con dígito verificador, nombre, especialidad, tipo, contacto, sociedad y si es
 *   persona con siniestro). Mientras se escribe, avisa qué póliza le corresponde por su especialidad.
 * Paso 2 (editorPoliza.js): su primera póliza, con el producto sugerido, el número vigente, las fechas, el deducible y la prima de la
 *   tabla de primas (rellenados solos, pero se pueden cambiar a mano).
 *
 * El HTML del diálogo está en index.html (#dialogCliente); aquí solo se arma su contenido.
 */

import { crearCliente, obtenerCatalogos } from "./api.js";
import { enMayusculas, h, listaEnMayusculas, vaciar } from "./dom.js";
import { nombreProducto } from "./formato.js";
import { productoSegunEspecialidad } from "./editorPersona.js";

const dialogo = document.getElementById("dialogCliente");
const formulario = document.getElementById("formCliente");

const lista = (id, valores) => h("datalist", { id }, valores.map((v) => h("option", { value: v })));

/**
 * Abre el diálogo.
 * @param {(cliente:object) => void} alCrear  se llama con la respuesta de la API (rut, nombre, producto_sugerido…) tras crear al cliente
 */
export async function abrirEditorCliente(alCrear) {
    let cat;
    try {
        cat = await obtenerCatalogos();
    } catch (error) {
        alert(error.message);
        return;
    }
    construir({ cat, alCrear });
    dialogo.showModal();
}

function construir({ cat, alCrear }) {
    const texto = (id, extra = {}) => h("input", { type: "text", id, ...extra });
    const rut = texto("cliRut", { maxlength: 14, placeholder: "12.345.678-9", autocomplete: "off", required: true });
    const nombre = texto("cliNombre", { maxlength: 150, required: true });
    const especialidad = enMayusculas(texto("cliEspecialidad", { list: "cliListaEspecialidades", maxlength: 100 }));
    const otroTipo = texto("cliOtroTipo", { list: "cliListaTipos", maxlength: 60, placeholder: "INDIVIDUAL, DERMO…" });
    const mail = texto("cliMail", { maxlength: 150, inputmode: "email" });
    const telefono = texto("cliTelefono", { maxlength: 30, inputmode: "tel" });
    const nacimiento = h("input", { type: "date", id: "cliNacimiento" });
    const direccion = texto("cliDireccion", { maxlength: 200 });
    const comuna = texto("cliComuna", { list: "cliListaComunas", maxlength: 80 });
    const ciudad = texto("cliCiudad", { list: "cliListaCiudades", maxlength: 80 });
    const ejecutivo = texto("cliEjecutivo", { list: "cliListaEjecutivos", maxlength: 60 });
    const sociedadNombre = texto("cliSociedad", { maxlength: 200 });
    const sociedadRut = texto("cliSociedadRut", { maxlength: 20, placeholder: "78.580.620-4" });

    // Persona con siniestro: paga deducible en todas sus pólizas; va siempre en la póliza y el tipo INDIVIDUAL
    const conSiniestro = h("input", { type: "checkbox", id: "cliSiniestro" });
    const deducible = h("input", { type: "number", id: "cliDeducible", min: 0, step: "any", value: 16 });
    deducible.disabled = true;

    const aviso = h("p", { class: "ayuda campo-completo aviso-producto" });
    const error = h("p", { class: "mensaje error", role: "alert" });
    const guardar = h("button", { type: "submit", class: "boton boton-primario" }, "Crear y agregar póliza");

    const campo = (etiqueta, control, completo = false) =>
        h("div", { class: completo ? "campo campo-completo" : "campo" }, h("label", { for: control.id }, etiqueta), control);

    /** Qué póliza le corresponde con lo escrito. */
    function actualizarAviso() {
        const sugerido = conSiniestro.checked ? "Individual" : productoSegunEspecialidad(especialidad.value, otroTipo.value);
        aviso.className = "ayuda campo-completo aviso-producto";
        aviso.textContent = sugerido
            ? `Con estos datos le corresponde la póliza ${nombreProducto(sugerido)}${conSiniestro.checked ? " (las personas con siniestro siempre van en INDIVIDUAL)" : ""}. En el siguiente paso se propone, y la puedes cambiar.`
            : "Sin especialidad no se puede proponer una póliza: la podrás elegir a mano en el siguiente paso.";
    }
    for (const c of [especialidad, otroTipo]) c.addEventListener("input", actualizarAviso);
    conSiniestro.addEventListener("change", () => {
        deducible.disabled = !conSiniestro.checked;
        if (conSiniestro.checked) otroTipo.value = "INDIVIDUAL";
        actualizarAviso();
    });
    actualizarAviso();

    // El RUT se va formateando solo: 123456789 → 12.345.678-9
    rut.addEventListener("input", () => {
        const limpio = rut.value.replace(/[^0-9kK]/g, "").toUpperCase();
        if (limpio.length < 2) { rut.value = limpio; return; }
        const cuerpo = limpio.slice(0, -1).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        rut.value = `${cuerpo}-${limpio.slice(-1)}`;
    });

    vaciar(formulario);
    formulario.append(
        h("h2", {}, "Nuevo cliente"),
        h("p", { class: "subtitulo" }, "Paso 1 de 2: datos de la persona. Después se abre su póliza con los valores sugeridos."),
        h("div", { class: "campos" },
            campo("RUT (con dígito verificador)", rut),
            campo("Nombre completo", nombre),
            campo("Especialidad", especialidad),
            campo("Otro tipo (INDIVIDUAL, DERMO…)", otroTipo),
            aviso,
            campo("Persona con siniestro", h("label", { class: "casilla" }, conSiniestro, " Usó el seguro: paga deducible en todas sus pólizas")),
            campo("Deducible (UF)", deducible),
            campo("Mail", mail),
            campo("Teléfono", telefono),
            campo("Fecha de nacimiento", nacimiento),
            campo("Ejecutivo", ejecutivo),
            campo("Dirección", direccion, true),
            campo("Comuna", comuna),
            campo("Ciudad", ciudad),
            campo("Sociedad (vacío = persona natural)", sociedadNombre),
            campo("RUT de la sociedad", sociedadRut)),
        lista("cliListaEspecialidades", listaEnMayusculas(cat.especialidades)), lista("cliListaTipos", cat.tipos_individuo),
        lista("cliListaComunas", cat.comunas), lista("cliListaCiudades", cat.ciudades), lista("cliListaEjecutivos", cat.ejecutivos),
        error,
        h("div", { class: "acciones" },
            h("button", { type: "button", class: "boton", onclick: () => dialogo.close() }, "Cancelar"),
            guardar));

    formulario.onsubmit = async (evento) => {
        evento.preventDefault();
        error.textContent = "";
        const t = (el) => el.value.trim() || null;

        guardar.disabled = true; // evita el doble envío
        try {
            const respuesta = await crearCliente({
                rut_texto: t(rut), nombre: t(nombre), especialidad: t(especialidad), otro_tipo: t(otroTipo), mail: t(mail), telefono: t(telefono),
                fecha_nacimiento: t(nacimiento), direccion: t(direccion), comuna: t(comuna), ciudad: t(ciudad), ejecutivo: t(ejecutivo),
                sociedad_nombre: t(sociedadNombre), sociedad_rut: t(sociedadRut),
                con_siniestro: conSiniestro.checked, deducible_uf: conSiniestro.checked && deducible.value.trim() !== "" ? Number(deducible.value) : null,
            });
            dialogo.close();
            alCrear(respuesta);
        } catch (e) {
            error.textContent = e.message;
            guardar.disabled = false;
        }
    };
}
