/**
 * Diálogo "Editar persona": datos de contacto, especialidad, "otro tipo" y sociedad.
 *
 * Flujo de datos:
 *   clic en "Editar persona" → abrirEditorPersona() pide los catálogos a la API y arma el formulario
 *   con los datos actuales → el usuario edita y guarda → api.guardarPersona() (POST persona.php)
 *   → si la API acepta: se cierra y se llama alGuardar() para recargar la ficha;
 *   si la rechaza: el error se muestra dentro del diálogo.
 *
 * La especialidad decide qué póliza cubre a la persona. Mientras se escribe, el formulario avisa cuál le
 * corresponde y si ya la tiene (no cambia las pólizas: eso se hace con "Editar póliza").
 * El formulario se construye con h() (src/js/dom.js), sin innerHTML.
 */

import { guardarPersona, obtenerCatalogos } from "./api.js";
import { enMayusculas, h, listaEnMayusculas, vaciar } from "./dom.js";
import { nombreProducto } from "./formato.js";

const dialogo = document.getElementById("dialogPersona");
const formulario = document.getElementById("formPersona");

/**
 * Producto que debe cubrir a la persona según su especialidad y su "otro tipo".
 * Es la misma regla de productoSegunEspecialidad() en Backend/api/_comun.php.
 */
export function productoSegunEspecialidad(especialidad, otroTipo) {
    const e = (especialidad ?? "").trim().toUpperCase();
    const t = (otroTipo ?? "").trim().toUpperCase();
    const dentista = e.includes("DENTISTA") || e.includes("ODONT");
    if (dentista && (e.includes("SOEMAF") || t === "DERMO")) return "SOEMAF";
    if (dentista) return "Odontólogo";
    if (e.includes("DERMAT")) return "Derma";
    return e !== "" ? "Individual" : null;
}

/** Lista de sugerencias para un <input list="...">. */
const lista = (id, valores) => h("datalist", { id }, valores.map((v) => h("option", { value: v })));

/**
 * Abre el diálogo.
 * @param {{persona:object, productosActuales:string[]}} ctx  `persona` es `asegurado` de api/asegurado.php;
 *        `productosActuales` son los productos de sus pólizas hoy
 * @param {(respuesta:object) => void} alGuardar  se llama tras guardar con éxito
 */
export async function abrirEditorPersona({ persona, productosActuales }, alGuardar) {
    let cat;
    try {
        cat = await obtenerCatalogos();
    } catch (error) {
        alert(error.message);
        return;
    }
    construir({ persona, productosActuales, cat, alGuardar });
    dialogo.showModal();
}

function construir({ persona: a, productosActuales, cat, alGuardar }) {
    const texto = (id, valor, extra = {}) => h("input", { type: "text", id, value: valor ?? "", ...extra });
    const nombre = texto("perNombre", a.nombre, { maxlength: 150, required: true });
    const especialidad = enMayusculas(texto("perEspecialidad", a.especialidad, { list: "listaEspecialidades", maxlength: 100 }));
    const otroTipo = texto("perOtroTipo", a.tipo_individuo, { list: "listaTipos", maxlength: 60 });
    const mail = texto("perMail", a.mail, { maxlength: 150, inputmode: "email" });
    const telefono = texto("perTelefono", a.telefono, { maxlength: 30, inputmode: "tel" });
    const nacimiento = h("input", { type: "date", id: "perNacimiento", value: a.fecha_nacimiento ?? "" });
    const direccion = texto("perDireccion", a.direccion, { maxlength: 200 });
    const comuna = texto("perComuna", a.comuna, { list: "listaComunas", maxlength: 80 });
    const ciudad = texto("perCiudad", a.ciudad, { list: "listaCiudades", maxlength: 80 });
    const ejecutivo = texto("perEjecutivo", a.ejecutivo, { list: "listaEjecutivos", maxlength: 60 });
    const sociedadNombre = texto("perSociedad", a.sociedad?.nombre, { maxlength: 200 });
    const sociedadRut = texto("perSociedadRut", a.sociedad?.rut_formateado, { maxlength: 20, placeholder: "78.580.620-4" });
    // Persona con siniestro: usó el seguro alguna vez y paga deducible en todas sus pólizas (16 UF por defecto, editable)
    const conSiniestro = h("input", { type: "checkbox", id: "perSiniestro" });
    conSiniestro.checked = Boolean(a.con_siniestro);
    const deducible = h("input", { type: "number", id: "perDeducible", min: 0, step: "any", value: a.deducible_uf ?? 16 });
    const sincronizarSiniestro = () => { deducible.disabled = !conSiniestro.checked; };
    conSiniestro.addEventListener("change", sincronizarSiniestro);
    sincronizarSiniestro();
    const aviso = h("p", { class: "ayuda campo-completo aviso-producto" });
    const error = h("p", { class: "mensaje error", role: "alert" });
    const guardar = h("button", { type: "submit", class: "boton boton-primario" }, "Guardar");

    const campo = (etiqueta, control, completo = false) =>
        h("div", { class: completo ? "campo campo-completo" : "campo" }, h("label", { for: control.id }, etiqueta), control);

    /** Qué póliza le corresponde con lo que hay escrito, y si ya la tiene. */
    function actualizarAviso() {
        // Una persona con siniestro (deducible) siempre va en la póliza INDIVIDUAL
        const sugerido = conSiniestro.checked ? "Individual" : productoSegunEspecialidad(especialidad.value, otroTipo.value);
        aviso.className = "ayuda campo-completo aviso-producto";
        if (!sugerido) {
            aviso.textContent = "Sin especialidad no se puede decidir qué póliza le corresponde.";
        } else if (productosActuales.includes(sugerido)) {
            aviso.textContent = `Con esta especialidad le corresponde la póliza ${nombreProducto(sugerido)}, que ya tiene.`;
        } else {
            aviso.classList.add("aviso-producto-alerta");
            aviso.textContent = `Con esta especialidad le corresponde la póliza ${nombreProducto(sugerido)}; hoy tiene ${productosActuales.length ? productosActuales.map(nombreProducto).join(", ") : "ninguna con producto"}. Puedes ajustarla con «Editar póliza».`;
        }
    }
    for (const c of [especialidad, otroTipo]) c.addEventListener("input", actualizarAviso);
    conSiniestro.addEventListener("change", actualizarAviso);
    actualizarAviso();

    vaciar(formulario);
    formulario.append(
        h("h2", {}, "Editar persona"),
        h("p", { class: "subtitulo" }, `${a.nombre} · ${a.rut_formateado} (el RUT no se puede cambiar)`),
        h("div", { class: "campos" },
            campo("Nombre", nombre, true),
            campo("Especialidad", especialidad),
            campo("Otro tipo (INDIVIDUAL, DERMO…)", otroTipo),
            aviso,
            campo("Persona con siniestro", h("label", { class: "casilla" }, conSiniestro, " Usó el seguro: paga deducible en todas sus pólizas")),
            campo("Deducible (UF)", deducible),
            h("p", { class: "ayuda campo-completo" }, "Las pólizas que no tengan deducible reciben este valor; las que ya tienen uno lo conservan (cada póliza puede tener el suyo)."),
            campo("Mail", mail),
            campo("Teléfono", telefono),
            campo("Fecha de nacimiento", nacimiento),
            campo("Ejecutivo", ejecutivo),
            campo("Dirección", direccion, true),
            campo("Comuna", comuna),
            campo("Ciudad", ciudad),
            campo("Sociedad (vacío = persona natural)", sociedadNombre),
            campo("RUT de la sociedad", sociedadRut),
            h("p", { class: "ayuda campo-completo" }, "Si el RUT ya existe, la persona se vincula a esa sociedad; si no, se crea una nueva.")),
        lista("listaEspecialidades", listaEnMayusculas(cat.especialidades)), lista("listaTipos", cat.tipos_individuo),
        lista("listaComunas", cat.comunas), lista("listaCiudades", cat.ciudades), lista("listaEjecutivos", cat.ejecutivos),
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
            const respuesta = await guardarPersona({
                rut: a.rut, nombre: t(nombre), especialidad: t(especialidad), otro_tipo: t(otroTipo), mail: t(mail), telefono: t(telefono),
                fecha_nacimiento: t(nacimiento), direccion: t(direccion), comuna: t(comuna), ciudad: t(ciudad), ejecutivo: t(ejecutivo),
                sociedad_nombre: t(sociedadNombre), sociedad_rut: t(sociedadRut),
                con_siniestro: conSiniestro.checked, deducible_uf: conSiniestro.checked && deducible.value.trim() !== "" ? Number(deducible.value) : null,
            });
            dialogo.close();
            alGuardar(respuesta);
        } catch (e) {
            error.textContent = e.message;
            guardar.disabled = false;
        }
    };
}
