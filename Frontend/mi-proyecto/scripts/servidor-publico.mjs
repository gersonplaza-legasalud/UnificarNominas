/**
 * Servidor mínimo para exponer SOLO la verificación de vigencia por un túnel público.
 *
 * Sirve la build de dist-publico/ (verificar.html + assets) y reenvía únicamente
 * /api/verificar.php a Apache. Cualquier otra ruta responde 404, así la aplicación interna
 * y los endpoints que editan datos (pago, importar…) nunca quedan accesibles desde fuera.
 *
 * Uso: npm run publico   (puerto 8080; cambia con PUERTO=...)
 */

import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { extname, join, normalize } from "node:path";

const PUERTO = Number(process.env.PUERTO ?? 8080);
const RAIZ = join(import.meta.dirname, "..", "dist-publico");
const API = "http://localhost/UnificarNominas/Backend/api/verificar.php";
const TIPOS = { ".html": "text/html; charset=utf-8", ".js": "text/javascript", ".css": "text/css" };

createServer(async (req, res) => {
    const url = new URL(req.url, "http://x");

    if (url.pathname === "/api/verificar.php") {
        const r = await fetch(API + url.search).catch(() => null);
        res.writeHead(r ? r.status : 502, { "Content-Type": "application/json; charset=utf-8" });
        res.end(r ? await r.text() : '{"success":false,"message":"Servidor no disponible"}');
        return;
    }

    // Solo la página de verificación y sus archivos compilados
    const ruta = url.pathname === "/verificar.html" ? "/verificar.html" : url.pathname;
    if (ruta !== "/verificar.html" && !ruta.startsWith("/assets/")) {
        res.writeHead(404).end("No encontrado");
        return;
    }
    try {
        const archivo = join(RAIZ, normalize(ruta).replace(/^(\.\.[\\/])+/, ""));
        const contenido = await readFile(archivo); // se lee antes de responder: si falta, cae al 404
        res.writeHead(200, { "Content-Type": TIPOS[extname(archivo)] ?? "application/octet-stream" });
        res.end(contenido);
    } catch {
        res.writeHead(404).end("No encontrado");
    }
}).listen(PUERTO, () => console.log(`Verificación pública en http://localhost:${PUERTO}/verificar.html`));
