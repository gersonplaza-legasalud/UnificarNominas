/**
 * Diálogo "Editar mes": permite corregir si un mes está pagado, su monto y una nota.
 *
 * Flujo de datos:
 *   clic en un mes de la ficha → abrirEditor() rellena el formulario con los datos actuales
 *   → el usuario edita y guarda → api.guardarPago() (POST pago.php)
 *   → si la API acepta: se cierra el diálogo y se llama alGuardar() para que la ficha se recargue
 *   → si la API rechaza: se muestra el error dentro del diálogo y no se cierra
 *
 * El HTML del diálogo está en index.html (#dialogPago); aquí solo se maneja su comportamiento.
 */

import { guardarPago } from "./api.js";
import { nombreMes } from "./formato.js";

// Elementos del diálogo definidos en index.html
const dialogo = document.getElementById("dialogPago");
const formulario = document.getElementById("formPago");
const titulo = document.getElementById("tituloPago");
const subtitulo = document.getElementById("subtituloPago");
const campoMonto = document.getElementById("pagoMonto");
const campoNota = document.getElementById("pagoNota");
const info = document.getElementById("infoPago");
const error = document.getElementById("errorPago");
const botonGuardar = document.getElementById("guardarPago");

// Mes que se está editando: { rut, nombre, periodo, pago, alGuardar }
let contexto = null;

document.getElementById("cancelarPago").addEventListener("click", () => dialogo.close());

/**
 * Texto informativo bajo el formulario: de dónde viene el dato actual y qué pasará al guardar.
 * @param {object|null} pago  null cuando el mes no tiene datos
 */
function descripcionOrigen(pago) {
    if (!pago) return "Este mes no tiene datos en la nómina. Al guardar se creará.";

    const partes = [];
    if (pago.valor_original) partes.push(`En el Excel decía «${pago.valor_original}».`);
    if (pago.editado) partes.push("Este mes fue editado a mano: la próxima actualización de la nómina no lo modificará.");
    return partes.join(" ");
}

/**
 * Abre el diálogo para un mes concreto.
 * @param {{rut:number, nombre:string, periodo:string, pago:object|null}} ctx
 *        `periodo` es "AAAA-MM"; `pago` son los datos actuales del mes o null si no tiene
 * @param {(respuesta: object) => void} alGuardar  se llama tras guardar con éxito, con la respuesta de
 *        la API (incluye `estado`: si la persona pasó a NO VIGENTE por este cambio). La ficha lo usa
 *        para recargarse y avisar.
 */
export function abrirEditor(ctx, alGuardar) {
    contexto = { ...ctx, alGuardar };

    // Se rellena el formulario con lo que hay hoy
    titulo.textContent = `Editar ${nombreMes(ctx.periodo)}`;
    subtitulo.textContent = ctx.nombre;
    formulario.elements.estado.value = ctx.pago?.pagado ? "pagado" : "impago";
    campoMonto.value = ctx.pago?.monto ?? "";
    campoNota.value = ctx.pago?.nota ?? "";
    info.textContent = descripcionOrigen(ctx.pago);
    error.textContent = "";
    botonGuardar.disabled = false;

    dialogo.showModal();
}

// Guardar: valida en el navegador y envía a la API (que valida otra vez en el servidor)
formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    error.textContent = "";

    // Monto vacío = sin monto; si hay valor debe ser un entero >= 0
    const textoMonto = campoMonto.value.trim();
    const monto = textoMonto === "" ? null : Number(textoMonto);
    if (monto !== null && (!Number.isInteger(monto) || monto < 0)) {
        error.textContent = "El monto debe ser un número entero, sin decimales.";
        campoMonto.focus();
        return;
    }

    // Se deshabilita el botón mientras se guarda para evitar doble envío
    botonGuardar.disabled = true;
    try {
        const respuesta = await guardarPago({
            rut: contexto.rut,
            periodo: contexto.periodo,
            pagado: formulario.elements.estado.value === "pagado",
            monto,
            nota: campoNota.value.trim() || null,
        });
        dialogo.close();
        contexto.alGuardar(respuesta);
    } catch (e) {
        error.textContent = e.message;
        botonGuardar.disabled = false;
    }
});
