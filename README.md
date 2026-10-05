# Unificar Nóminas

Aplicación de **consulta de vigencia** de asegurados. Se busca a una persona por RUT, nombre o
mail y se ve si está vigente, sus datos de póliza y su historial de pagos mes a mes (que se
puede corregir a mano). La base de datos se alimenta del **Excel madre** (`NOMINA PAGOS
DENTISTAS`, hoja `NOMINA ACT 2024`), que se vuelve a importar cada vez que se actualiza.

- **Frontend:** JavaScript sin framework + Vite (`Frontend/mi-proyecto`), en `http://localhost:5173`.
- **Backend:** PHP + PhpSpreadsheet (`Backend`), servido por Apache de XAMPP.
- **Base de datos:** MySQL/MariaDB de XAMPP, base `unificar_nominas`.

## Puesta en marcha

1. Encender **Apache** y **MySQL** en XAMPP.
2. Crear la base (⚠ borra los datos existentes): `php Backend/scripts/instalar_bd.php`
3. Cargar el Excel madre: `php Backend/scripts/sincronizar_nomina.php "ruta\al\excel.xlsx"`
   (o, con la app abierta, botón **Actualizar nómina**).
4. Front: `cd Frontend/mi-proyecto && npm install && npm run dev`, y abrir `http://localhost:5173`.

## Flujo de datos

```
                      Excel madre (.xlsx)
                              │  botón "Actualizar nómina" (o scripts/sincronizar_nomina.php)
                              ▼
   Frontend ── POST ──▶ api/importar.php ──▶ NominaSync::sincronizar() ──▶ MySQL
                                                │
                                                ├─ lee la hoja y agrupa por RUT
                                                ├─ asegurados      (insert / update)
                                                ├─ pagos_mensuales (insert / update, sin pisar lo editado a mano)
                                                └─ historial_cambios, importaciones

   Frontend ── GET  ──▶ api/buscar.php    ──▶ lista de resultados
   Frontend ── GET  ──▶ api/asegurado.php ──▶ ficha: datos + pagador + pagos por mes
   Frontend ── POST ──▶ api/pago.php      ──▶ edita un mes (queda "editado a mano" + historial)
```

El Excel **manda sobre los datos de la persona**; en cambio, un mes editado a mano desde la
aplicación **no se sobrescribe** al volver a importar (se informa como "conflicto").

## Reglas de negocio

- **Sin cobertura:** 3 meses seguidos sin pago (contados hacia atrás desde el último mes completo;
  un mes sin datos cuenta como sin pago). Al editar un mes a impago desde la aplicación, si con eso
  la persona completa los 3 meses, pasa de VIGENTE / VAN A CORTE a NO VIGENTE (`api/pago.php` +
  `src/Services/Vigencia.php`). Solo baja de estado: marcar un mes como pagado no la devuelve a
  vigente. La sincronización con el Excel respeta ese cambio (`asegurados.estado_manual`) hasta que
  el Excel lo refleje. Fuera de esas ediciones, `estado` se copia de la hoja.
- **Mes pagado o no:** lo único que importa es si cada mes está pagado. El monto es
  informativo (la cobertura se cobra en UF).
- **El mes de cobertura no es el mes de pago:** hay pagos adelantados y atrasados. En el
  Excel, `PAGADO` marca un mes pagado de forma desfasada.
- **Una póliza por persona.** Un pagador puede pagar por varias personas.
- **Celdas del Excel:** número > 0 = pagado; `0` = impago; `c` = aún no afiliado (no genera
  pago); `tribunal` = se ignora; `PAGADO`, `webpay`, `transfirio`… = pagado; otros textos
  (`falta de fondos`, `prorroga`…) = impago. El texto original se conserva.
- **Mismo RUT en varias filas:** son re-afiliaciones. Se unifica en una persona con los datos
  de la fila más reciente; las anteriores se guardan en `historial_cambios`.

## Conexión con Google Sheets (edición en las dos direcciones)

La hoja de Google y la app se editan entre sí. Todo pasa por un script de Apps Script pegado en
la hoja (`Backend/apps-script/Codigo.gs`; las instrucciones de instalación están al inicio del archivo).

```
 HOJA → BASE   edición de una celda ─▶ alEditar() ─▶ envía las filas de esa(s) persona(s)
                                        ─▶ api/fila.php ─▶ NominaSync::sincronizarFilas() ─▶ MySQL

 BASE → HOJA   edición en la app ─▶ api/pago.php ─▶ guarda en MySQL ─▶ cola_hoja
                                        ─▶ HojaGoogle ─▶ doPost() del script ─▶ escribe la celda
```

- **Identificación:** cada fila se identifica por RUT y cada mes por la fecha del encabezado, nunca por
  número de fila. Con RUT repetido, la app escribe en la fila de alta más reciente, y el aviso de la
  hoja envía todas las filas de ese RUT.
- **Qué se escribe en la celda de un mes:** pagado con monto → el monto; pagado sin monto → `PAGADO`;
  impago → `0`. La nota de la app va como nota de la celda. Si la persona pasa a NO VIGENTE, también
  se escribe en la columna ESTADO.
- **Quién gana:** el cambio más reciente. Un aviso de la hoja puede pisar un mes editado en la app (y
  queda en `historial_cambios` con usuario `hoja`). Solo la carga completa del Excel respeta los meses
  editados a mano.
- **Si la hoja no responde:** la edición igual queda guardada en la base y el cambio espera en
  `cola_hoja`. Se reintenta solo, en orden, con `php Backend/scripts/procesar_cola.php` (conviene
  programarlo cada minuto en el Programador de tareas de Windows). Un rechazo permanente (el RUT o el
  mes no existen en la hoja) marca el cambio como `fallido` y no bloquea a los demás.
- **Sin ciclos:** escribir una celda desde el script no dispara `alEditar`.
- **Seguridad:** `api/fila.php` y el script exigen una clave secreta compartida (`TOKEN`). Va en
  `Backend/config/hoja.local.php` (no se sube al repositorio; hay un `.example`) y en las propiedades
  del script. Sin ese archivo, la conexión está desactivada y la app funciona como antes.
- **Para la prueba desde tu PC:** la hoja de Google necesita una dirección pública para llamar a
  `api/fila.php`; se usa un túnel (ej. Cloudflare Tunnel) apuntando solo a la API, nunca a todo `htdocs`.

## Estructura

```
Backend/
  config/database.php          conexión a MySQL (conectarBD)
  database/schema.sql          tablas (documentadas dentro del archivo)
  scripts/instalar_bd.php      crea la base desde schema.sql
  scripts/sincronizar_nomina.php   sincroniza el Excel desde la consola
  src/Services/NominaSync.php  lógica de sincronización (lectura, reglas, upsert)
  src/Services/Vigencia.php    regla de los 3 meses sin pago (ficha y api/pago.php)
  src/Services/HojaGoogle.php  cola y envío de cambios hacia la hoja de Google
  apps-script/Codigo.gs        script que se pega en la hoja de Google
  scripts/procesar_cola.php    reintenta los cambios que no llegaron a la hoja
  api/_comun.php               cabeceras, CORS y utilidades comunes de la API
  api/buscar.php | asegurado.php | pago.php | importar.php | fila.php   endpoints

Frontend/mi-proyecto/
  index.html                   estructura de la página y diálogos
  src/css/style.css            estilos
  src/js/main.js               punto de entrada; la URL (#/rut/N) decide qué ficha se ve
  src/js/api.js                único módulo que habla con el servidor
  src/js/buscador.js           búsqueda y lista de resultados
  src/js/ficha.js              ficha y grilla de meses
  src/js/editorPago.js         diálogo para editar un mes
  src/js/importador.js         diálogo para subir el Excel madre
  src/js/dom.js, formato.js    utilidades (crear elementos de forma segura, formatos)
```

## Pendiente

- Probar de punta a punta con la hoja de Google real (hoy está probado con simulaciones).
- Revisión periódica que compare la hoja completa con la base (hoy: menú Nómina → Sincronizar toda la hoja).
- Aplicar también la regla de 3 meses a las importaciones del Excel (hoy solo actúa al editar desde la app).
- Cargar el Excel de médicos y clínicas.
- Autenticación: hoy cualquiera que alcance la API puede editar pagos (aceptable solo en local).
