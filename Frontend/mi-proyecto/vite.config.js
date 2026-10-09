import { defineConfig } from "vite";
import { resolve } from "node:path";

// Dos páginas: la aplicación interna (index.html) y la verificación pública del QR (verificar.html)
export default defineConfig({
    build: {
        rollupOptions: {
            input: {
                main: resolve(import.meta.dirname, "index.html"),
                verificar: resolve(import.meta.dirname, "verificar.html"),
            },
        },
    },
});
