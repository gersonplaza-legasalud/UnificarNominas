/**
 * Funciones de formato para mostrar fechas, meses y montos en español.
 *
 * Los períodos viajan entre el front y la API como texto "AAAA-MM" (ej. "2026-09").
 */

/** Nombres abreviados de los 12 meses; el orden coincide con el número de mes - 1. */
export const MESES_CORTO = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];

const MESES_LARGO = [
    "enero", "febrero", "marzo", "abril", "mayo", "junio",
    "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre",
];

/** "2026-09" → "septiembre de 2026" (títulos y descripciones). */
export function nombreMes(periodo) {
    const [anio, mes] = periodo.split("-");
    return `${MESES_LARGO[Number(mes) - 1]} de ${anio}`;
}

/** "2026-09" → "sep 2026" (textos breves). */
export function mesCorto(periodo) {
    const [anio, mes] = periodo.split("-");
    return `${MESES_CORTO[Number(mes) - 1].toLowerCase()} ${anio}`;
}

/** Arma la clave de período: (2026, 9) → "2026-09". `mes` va de 1 a 12. */
export function periodo(anio, mes) {
    return `${anio}-${String(mes).padStart(2, "0")}`;
}

/** 33700 → "33.700" (separador de miles chileno). Devuelve "" si no hay monto. */
export function formatoMonto(monto) {
    return monto == null ? "" : monto.toLocaleString("es-CL");
}

/** "2012-10-31" → "31-10-2012". Devuelve null si no hay fecha. */
export function fechaCorta(fecha) {
    if (!fecha) return null;
    const [anio, mes, dia] = fecha.split("-");
    return `${dia}-${mes}-${anio}`;
}
