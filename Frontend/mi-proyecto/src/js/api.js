/**
 * Cliente de la API del backend (PHP en Apache/XAMPP).
 *
 * Es la ÚNICA parte del front que habla con el servidor: el resto de los módulos llama
 * a estas funciones y no usa fetch directamente. Si la dirección del backend cambia,
 * solo hay que modificar BASE.
 *
 * Cada función devuelve una promesa con el JSON de la respuesta, o lanza un Error con un
 * mensaje listo para mostrar al usuario.
 */

const BASE = "http://localhost/UnificarNominas/Backend/api";

/**
 * Hace la petición y unifica el manejo de errores de todos los endpoints:
 *   - sin conexión con el servidor           → mensaje claro (suele ser XAMPP apagado)
 *   - respuesta que no es JSON               → error con el código HTTP
 *   - HTTP de error o `success: false`       → se lanza el `message` que envió la API
 * Una petición cancelada (AbortError) se relanza tal cual: no es un error real.
 */
async function pedir(url, opciones) {
    let respuesta;
    try {
        respuesta = await fetch(url, opciones);
    } catch (error) {
        if (error.name === "AbortError") throw error;
        throw new Error("No se pudo comunicar con el servidor. ¿Está encendido XAMPP?");
    }

    let datos;
    try {
        datos = await respuesta.json();
    } catch {
        throw new Error(`Respuesta inválida del servidor (HTTP ${respuesta.status})`);
    }

    if (!respuesta.ok || datos.success === false) {
        throw new Error(datos.message ?? `Error HTTP ${respuesta.status}`);
    }
    return datos;
}

/**
 * Busca asegurados por RUT, nombre o mail → GET buscar.php
 * @param {string} texto
 * @param {AbortSignal} [signal] permite cancelar la búsqueda si el usuario sigue escribiendo
 * @returns {Promise<{total:number, resultados:object[]}>}
 */
export function buscar(texto, signal) {
    return pedir(`${BASE}/buscar.php?q=${encodeURIComponent(texto)}`, { signal });
}

/**
 * Ficha completa de una persona (datos, pagador y pagos por mes) → GET asegurado.php
 * @param {number|string} rut RUT sin dígito verificador
 */
export function obtenerAsegurado(rut) {
    return pedir(`${BASE}/asegurado.php?rut=${encodeURIComponent(rut)}`);
}

/**
 * Edita o crea el pago de un mes → POST pago.php (JSON)
 * @param {{rut:number, periodo:string, pagado:boolean, monto:?number, nota:?string}} datos
 */
export function guardarPago(datos) {
    return pedir(`${BASE}/pago.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos),
    });
}

/**
 * Sube el Excel madre para sincronizar la base de datos → POST importar.php (multipart).
 * Puede tardar cerca de un minuto. Responde con `resumen` (cantidad de meses nuevos,
 * modificados, sin cambios, en conflicto…).
 * @param {File} archivo
 */
export function importarNomina(archivo) {
    const formData = new FormData();
    formData.append("archivo", archivo);
    return pedir(`${BASE}/importar.php`, { method: "POST", body: formData });
}
