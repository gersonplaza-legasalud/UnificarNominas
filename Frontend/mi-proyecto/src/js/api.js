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

// VITE_API_BASE permite apuntar a otra dirección (p. ej. "/api" en la build pública del túnel)
const BASE = import.meta.env.VITE_API_BASE ?? "http://localhost/UnificarNominas/Backend/api";

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
 * Estado de vigencia para quien escanea el QR del certificado → GET verificar.php (público).
 * @param {number|string} poliza  id de la póliza del certificado
 * @param {string} codigo         código `k` del enlace (lo emite asegurado.php)
 */
export function verificarVigencia(poliza, codigo) {
    return pedir(`${BASE}/verificar.php?poliza=${encodeURIComponent(poliza)}&k=${encodeURIComponent(codigo)}`);
}

/**
 * Listas para los formularios (productos, coberturas, medios de pago y pólizas matrices) → GET catalogos.php.
 * Cambian muy poco: se piden una sola vez y se reutilizan.
 */
let catalogos = null;
export function obtenerCatalogos() {
    catalogos ??= pedir(`${BASE}/catalogos.php`).catch((error) => {
        catalogos = null; // si falla, la próxima vez se vuelve a intentar
        throw error;
    });
    return catalogos;
}

/**
 * Crea o edita una póliza → POST poliza.php (JSON). Con `id` edita esa póliza; con `rut` crea una nueva.
 * @param {object} datos  ver el encabezado de Backend/api/poliza.php
 */
export function guardarPoliza(datos) {
    return pedir(`${BASE}/poliza.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos),
    });
}

/**
 * Edita los datos de una persona (contacto, especialidad, otro tipo, sociedad) → POST persona.php (JSON).
 * Responde también con el producto que le corresponde por su especialidad y los que tiene hoy.
 * @param {object} datos  ver el encabezado de Backend/api/persona.php
 */
export function guardarPersona(datos) {
    return pedir(`${BASE}/persona.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos),
    });
}

/**
 * Edita o crea el pago de un mes de una póliza → POST pago.php (JSON)
 * @param {{poliza_id:number, periodo:string, pagado:boolean, monto:?number, nota:?string}} datos
 */
export function guardarPago(datos) {
    return pedir(`${BASE}/pago.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos),
    });
}


/**
 * Crea un cliente (persona) nuevo → POST cliente.php (JSON). Responde con el RUT y el producto que le corresponde.
 * @param {object} datos  ver el encabezado de Backend/api/cliente.php
 */
export function crearCliente(datos) {
    return pedir(`${BASE}/cliente.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos),
    });
}
