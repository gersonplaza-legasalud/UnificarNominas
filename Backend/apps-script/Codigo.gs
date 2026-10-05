/**
 * Conexión entre esta hoja de Google y la base de datos de Unificar Nóminas.
 *
 * ── Qué hace ─────────────────────────────────────────────────────────────────
 *   HOJA → BASE:  cuando alguien edita una celda, el script envía a la API las filas
 *                 afectadas (alEditar → avisarFilas_ → api/fila.php).
 *   BASE → HOJA:  cuando alguien edita un mes en la app, la app llama a este script
 *                 (publicado como aplicación web) y él escribe la celda (doPost).
 *   Escribir una celda desde el script NO vuelve a disparar alEditar, así que no hay ciclos.
 *
 * ── Instalación (una sola vez) ───────────────────────────────────────────────
 *   1. En la hoja: Extensiones → Apps Script. Borra lo que haya y pega este archivo completo.
 *   2. Configuración del proyecto (engranaje) → Propiedades del script → agrega:
 *        API_URL = dirección pública de api/fila.php (ej. https://xxxx.trycloudflare.com/UnificarNominas/Backend/api/fila.php)
 *        TOKEN   = una clave larga y secreta (la misma que va en config/hoja.local.php del backend)
 *   3. Ejecuta la función `activar` una vez (pide permisos; es normal que Google muestre
 *      "app no verificada": Avanzado → Ir a ... → Permitir). Crea el aviso de edición.
 *   4. Implementar → Nueva implementación → tipo "Aplicación web":
 *        Ejecutar como: Yo   ·   Quién tiene acceso: Cualquier persona
 *      Copia la URL que termina en /exec y ponla como `url` en config/hoja.local.php del backend.
 *      (Cualquier persona = cualquiera que tenga la URL puede llamarla, por eso cada pedido exige el TOKEN.)
 *   5. Recarga la hoja: aparece el menú "Nómina" con "Probar conexión" y "Sincronizar toda la hoja".
 *
 * ── Importante ───────────────────────────────────────────────────────────────
 *   - Cada fila se identifica por RUT y cada mes por la fecha de su encabezado, nunca por
 *     número de fila (las filas cambian de lugar al ordenar).
 *   - Si una persona tiene varias filas (re-afiliaciones), la app escribe siempre en la de
 *     fecha de alta más reciente, y al avisar a la base se envían TODAS sus filas.
 *   - Al cambiar el RUT de una fila, la base no se entera de que el RUT anterior dejó de
 *     existir; usa "Sincronizar toda la hoja" después de corregir RUT.
 */

// ---------------------------------------------------------------------------
// Configuración: posiciones (base 1) de las columnas de la hoja
// ---------------------------------------------------------------------------

const CONFIG = {
  HOJA: 'NOMINA ACT 2024',   // nombre de la pestaña que manda
  COL_RUT: 1,                // A  "RUT"
  COL_ALTA: 8,               // H  "FECHA " (fecha de alta)
  COL_ESTADO: 11,            // K  "ESTADO"
  PRIMERA_COL_MES: 17,       // Q  primera columna de meses (desde aquí los encabezados son fechas)
  FILAS_POR_ENVIO: 150,      // filas por llamada a la API al sincronizar toda la hoja
  MAX_FILAS_EDICION: 300,    // una edición más grande que esto no se avisa sola (ver alEditar)
};

// ---------------------------------------------------------------------------
// Menú
// ---------------------------------------------------------------------------

/** Agrega el menú "Nómina" al abrir la hoja. */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Nómina')
    .addItem('Probar conexión', 'probarConexion')
    .addItem('Diagnóstico', 'diagnostico')
    .addItem('Sincronizar toda la hoja', 'sincronizarTodo')
    .addToUi();
}

// ---------------------------------------------------------------------------
// Instalación
// ---------------------------------------------------------------------------

/**
 * Crea el aviso automático de edición. Ejecutar una sola vez (ver instrucciones arriba).
 * Es un "activador instalable" porque el simple `onEdit` no puede llamar a servicios externos.
 */
function activar() {
  const yaExiste = ScriptApp.getProjectTriggers().some(function (t) {
    return t.getHandlerFunction() === 'alEditar';
  });
  if (!yaExiste) {
    ScriptApp.newTrigger('alEditar').forSpreadsheet(SpreadsheetApp.getActive()).onEdit().create();
  }
  const p = propiedades_();
  const faltan = [];
  if (!p.apiUrl) faltan.push('API_URL');
  if (!p.token) faltan.push('TOKEN');
  Logger.log(faltan.length ? 'Aviso de edición activo. FALTA configurar: ' + faltan.join(', ') : 'Aviso de edición activo y configuración completa.');
}

/** Lee la configuración guardada en Propiedades del script. */
function propiedades_() {
  const p = PropertiesService.getScriptProperties();
  return { apiUrl: p.getProperty('API_URL'), token: p.getProperty('TOKEN') };
}

// ---------------------------------------------------------------------------
// HOJA → BASE
// ---------------------------------------------------------------------------

/**
 * Se ejecuta cada vez que se edita una celda (activador instalable por `activar`).
 * Envía a la API las filas editadas, junto con las demás filas de las mismas personas.
 */
function alEditar(e) {
  const hoja = e.range.getSheet();
  if (hoja.getName() !== CONFIG.HOJA) return;

  // Rango editado (puede ser una celda, o muchas si pegaron un bloque). El encabezado (fila 1) se ignora.
  const primera = Math.max(e.range.getRow(), 2);
  const ultima = e.range.getRow() + e.range.getNumRows() - 1;
  if (ultima < primera) return;

  if (ultima - primera + 1 > CONFIG.MAX_FILAS_EDICION) {
    SpreadsheetApp.getActive().toast('Se editaron muchas filas a la vez. Usa Nómina → Sincronizar toda la hoja.', 'Nómina', 10);
    return;
  }

  const filas = [];
  for (let f = primera; f <= ultima; f++) filas.push(f);
  avisarFilas_(hoja, filas);
}

/**
 * Envía a la API las filas indicadas y todas las demás filas de las mismas personas
 * (la base necesita ver todas las filas de un RUT para saber cuál es la afiliación vigente).
 *
 * @param {Sheet} hoja
 * @param {number[]} filasEditadas  números de fila (base 1) que cambiaron
 */
function avisarFilas_(hoja, filasEditadas) {
  const inicio = Date.now();
  const ultimaFila = hoja.getLastRow();
  const ultimaCol = hoja.getLastColumn();
  if (ultimaFila < 2) return;

  // Columna de RUT completa: sirve para encontrar las otras filas de cada persona
  const ruts = hoja.getRange(2, CONFIG.COL_RUT, ultimaFila - 1, 1).getValues();
  const rutDeFila = function (fila) { return Number(ruts[fila - 2][0]); };

  const afectados = {};
  filasEditadas.forEach(function (f) {
    const r = rutDeFila(f);
    if (r > 0) afectados[r] = true;
  });

  const filasAEnviar = [];
  for (let i = 0; i < ruts.length; i++) {
    if (afectados[Number(ruts[i][0])]) filasAEnviar.push(i + 2);
  }
  if (filasAEnviar.length === 0) return; // se editó una fila sin RUT

  const encabezado = leerFila_(hoja, 1, ultimaCol);
  const filas = filasAEnviar.map(function (f) {
    return { fila: f, valores: leerFila_(hoja, f, ultimaCol) };
  });
  const leido = Date.now();
  enviar_(encabezado, filas);
  // Tiempos para saber dónde se va la demora (se ven en Ejecuciones → alEditar → registro).
  // El tiempo que tarda Google en INICIAR este script tras la edición no aparece aquí.
  Logger.log('Aviso de ' + filas.length + ' fila(s): lectura de la hoja ' + (leido - inicio) + ' ms, llamada a la API ' + (Date.now() - leido) + ' ms');
}

/** Lee una fila completa y la deja lista para enviar (fechas como número serial de Excel). */
function leerFila_(hoja, fila, ultimaCol) {
  return hoja.getRange(fila, 1, 1, ultimaCol).getValues()[0].map(function (v) {
    return normalizar_(v);
  });
}

/**
 * Sincroniza TODA la hoja con la base, en tandas. Úsalo después de cambios grandes (pegar
 * muchas filas, ordenar, corregir RUT) o como revisión periódica.
 * Las tandas se arman con personas completas: nunca se separan las filas de un mismo RUT.
 */
function sincronizarTodo() {
  const ui = SpreadsheetApp.getUi();
  const hoja = SpreadsheetApp.getActive().getSheetByName(CONFIG.HOJA);
  if (!hoja) { ui.alert('No existe la pestaña "' + CONFIG.HOJA + '".'); return; }

  const ultimaFila = hoja.getLastRow();
  const ultimaCol = hoja.getLastColumn();
  const valores = hoja.getRange(2, 1, ultimaFila - 1, ultimaCol).getValues();
  const encabezado = leerFila_(hoja, 1, ultimaCol);

  // Agrupar las filas por RUT (cada grupo viaja junto)
  const grupos = {};
  valores.forEach(function (fila, i) {
    const rut = Number(fila[CONFIG.COL_RUT - 1]);
    if (!(rut > 0)) return;
    (grupos[rut] = grupos[rut] || []).push({ fila: i + 2, valores: fila.map(function (v) { return normalizar_(v); }) });
  });

  // Tandas de hasta FILAS_POR_ENVIO filas, sin partir ningún grupo
  const tandas = armarTandas_(Object.keys(grupos).map(function (r) { return grupos[r]; }), CONFIG.FILAS_POR_ENVIO);
  tandas.forEach(function (tanda, n) {
    SpreadsheetApp.getActive().toast('Enviando tanda ' + (n + 1) + ' de ' + tandas.length + '…', 'Nómina', 5);
    enviar_(encabezado, tanda);
  });
  ui.alert('Listo: se enviaron ' + tandas.length + ' tandas a la base de datos.');
}

/** Llama a la API. Los errores se muestran como aviso en la hoja y se escriben en el registro. */
function enviar_(encabezado, filas) {
  const p = propiedades_();
  if (!p.apiUrl || !p.token) {
    SpreadsheetApp.getActive().toast('Falta configurar API_URL y TOKEN en las propiedades del script.', 'Nómina', 10);
    return false;
  }
  try {
    const r = UrlFetchApp.fetch(p.apiUrl, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify({ token: p.token, hoja: CONFIG.HOJA, encabezado: encabezado, filas: filas }),
      muteHttpExceptions: true,
    });
    if (r.getResponseCode() !== 200) {
      Logger.log('La API respondió ' + r.getResponseCode() + ': ' + r.getContentText());
      SpreadsheetApp.getActive().toast('La base de datos rechazó el cambio (' + r.getResponseCode() + '). Mira Ejecuciones para el detalle.', 'Nómina', 10);
      return false;
    }
    return true;
  } catch (err) {
    Logger.log('No se pudo llamar a la API: ' + err);
    SpreadsheetApp.getActive().toast('No se pudo comunicar con la base de datos. El cambio no se envió.', 'Nómina', 10);
    return false;
  }
}

/** Menú "Probar conexión": verifica que la API responda y acepte la clave. */
function probarConexion() {
  const ui = SpreadsheetApp.getUi();
  const p = propiedades_();
  if (!p.apiUrl || !p.token) { ui.alert('Falta configurar API_URL y TOKEN en las propiedades del script.'); return; }
  try {
    // Se envía un aviso sin filas: la API valida la clave primero y luego responde 400 por faltar filas
    const r = UrlFetchApp.fetch(p.apiUrl, {
      method: 'post', contentType: 'application/json', muteHttpExceptions: true,
      payload: JSON.stringify({ token: p.token, encabezado: [], filas: [] }),
    });
    const codigo = r.getResponseCode();
    if (codigo === 400) ui.alert('Conexión correcta: la API responde y acepta la clave.');
    else if (codigo === 401) ui.alert('La API responde pero rechazó la clave: revisa que TOKEN coincida con el del backend.');
    else ui.alert('La API respondió con el código ' + codigo + ': ' + r.getContentText());
  } catch (err) {
    ui.alert('No se pudo llegar a la API. Revisa API_URL y que el túnel/servidor esté encendido.\n\n' + err);
  }
}

/**
 * Menú "Diagnóstico": muestra por qué un cambio en la hoja podría no estar llegando a la base.
 * Revisa lo que más suele fallar: nombre de la pestaña, aviso de edición instalado,
 * configuración, y que la pestaña tenga las columnas esperadas.
 */
function diagnostico() {
  const ss = SpreadsheetApp.getActive();
  const p = propiedades_();
  const lineas = [];

  const pestanas = ss.getSheets().map(function (s) { return s.getName(); });
  const hoja = ss.getSheetByName(CONFIG.HOJA);
  lineas.push((hoja ? 'OK  ' : 'FALLA  ') + 'Pestaña "' + CONFIG.HOJA + '": ' +
    (hoja ? 'existe.' : 'NO existe. Las pestañas de esta hoja son: ' + pestanas.join(' | ') + '. Cambia CONFIG.HOJA al nombre exacto.'));

  const avisos = ScriptApp.getProjectTriggers()
    .filter(function (t) { return t.getHandlerFunction() === 'alEditar'; });
  lineas.push((avisos.length ? 'OK  ' : 'FALLA  ') + 'Aviso de edición (alEditar): ' +
    (avisos.length ? avisos.length + ' instalado(s).' : 'NO está instalado. Ejecuta la función "activar".') +
    (avisos.length > 1 ? ' OJO: hay más de uno; cada edición se enviaría repetida.' : ''));

  lineas.push((p.apiUrl ? 'OK  ' : 'FALLA  ') + 'API_URL: ' + (p.apiUrl || 'no configurada'));
  lineas.push((p.token ? 'OK  ' : 'FALLA  ') + 'TOKEN: ' + (p.token ? 'configurado (' + p.token.length + ' caracteres)' : 'no configurado'));

  if (hoja) {
    const enc = hoja.getRange(1, 1, 1, hoja.getLastColumn()).getValues()[0];
    const esRut = String(enc[CONFIG.COL_RUT - 1]).trim() === 'RUT';
    const primerMes = enc[CONFIG.PRIMERA_COL_MES - 1];
    lineas.push((esRut ? 'OK  ' : 'FALLA  ') + 'Columna A: "' + enc[CONFIG.COL_RUT - 1] + '" (debe decir RUT)');
    lineas.push((primerMes instanceof Date ? 'OK  ' : 'FALLA  ') + 'Columna Q (primer mes): ' +
      (primerMes instanceof Date ? mesDeSerial_(aSerial_(primerMes)) : 'no es una fecha (' + primerMes + ')'));
  }

  SpreadsheetApp.getUi().alert('Diagnóstico\n\n' + lineas.join('\n\n'));
}

// ---------------------------------------------------------------------------
// BASE → HOJA  (aplicación web)
// ---------------------------------------------------------------------------

/** Responde a una visita simple de navegador: sirve para comprobar que la implementación está viva. */
function doGet() {
  return json_({ ok: true, mensaje: 'Conexión con Unificar Nóminas activa' });
}

/**
 * Recibe los cambios de la app. Cuerpo JSON:
 *   { token, accion: 'actualizar', rut, periodo: 'AAAA-MM', valor, nota?, estado? }
 * Responde siempre JSON {ok: true} o {ok: false, error, permanente}; `permanente` = reintentar
 * no lo arreglaría (RUT o mes inexistentes, clave incorrecta).
 */
function doPost(e) {
  const candado = LockService.getScriptLock();
  let tieneCandado = false;
  try {
    const pedido = JSON.parse(e.postData.contents);
    if (!pedido.token || pedido.token !== propiedades_().token) {
      return json_({ ok: false, error: 'Clave incorrecta', permanente: true });
    }
    if (pedido.accion !== 'actualizar') {
      return json_({ ok: false, error: 'Acción desconocida', permanente: true });
    }
    // Un cambio a la vez: evita que dos pedidos simultáneos se pisen al escribir
    candado.waitLock(20000);
    tieneCandado = true;
    return json_(actualizar_(pedido));
  } catch (err) {
    return json_({ ok: false, error: String(err), permanente: false }); // error pasajero: la app reintentará
  } finally {
    if (tieneCandado) candado.releaseLock();
  }
}

/**
 * Escribe un cambio en la hoja: el valor del mes (y su nota) y/o el estado de la persona.
 * @param {{rut:number, periodo?:string, valor?:(number|string), nota?:string, estado?:string}} p
 */
function actualizar_(p) {
  const rut = Number(p.rut);
  if (!(rut > 0)) return { ok: false, error: 'RUT inválido', permanente: true };

  const ss = SpreadsheetApp.getActive();
  const hoja = ss.getSheetByName(CONFIG.HOJA);
  if (!hoja) return { ok: false, error: 'No existe la pestaña ' + CONFIG.HOJA, permanente: true };

  // 1. Fila de la persona (si tiene varias, la de alta más reciente)
  const ultimaFila = hoja.getLastRow();
  const basicas = hoja.getRange(2, 1, ultimaFila - 1, CONFIG.COL_ALTA).getValues();
  const candidatos = [];
  basicas.forEach(function (fila, i) {
    if (Number(fila[CONFIG.COL_RUT - 1]) === rut) {
      candidatos.push({ fila: i + 2, alta: normalizar_(fila[CONFIG.COL_ALTA - 1]) });
    }
  });
  const fila = elegirFila_(candidatos);
  if (fila === null) return { ok: false, error: 'El RUT ' + rut + ' no está en la hoja', permanente: true };

  // 2. Mes: columna cuyo encabezado es esa fecha
  let columna = null;
  if (p.periodo !== undefined && p.valor !== undefined) {
    const encabezados = hoja.getRange(1, 1, 1, hoja.getLastColumn()).getValues()[0];
    columna = columnaDeMes_(encabezados, p.periodo);
    if (columna === null) return { ok: false, error: 'El mes ' + p.periodo + ' no existe en la hoja', permanente: true };
  }

  // 3. Escritura
  if (columna !== null) {
    const celda = hoja.getRange(fila, columna);
    celda.setValue(p.valor);
    if (p.nota !== undefined) celda.setNote(p.nota); // '' borra la nota
  }
  if (p.estado) hoja.getRange(fila, CONFIG.COL_ESTADO).setValue(p.estado);
  SpreadsheetApp.flush();

  return { ok: true, fila: fila, columna: columna };
}

// ---------------------------------------------------------------------------
// Utilidades puras (sin acceso a la hoja; se pueden probar por separado)
// ---------------------------------------------------------------------------

/** Respuesta JSON de la aplicación web. */
function json_(objeto) {
  return ContentService.createTextOutput(JSON.stringify(objeto)).setMimeType(ContentService.MimeType.JSON);
}

/**
 * Fecha de una celda → número serial de Excel (días desde 1899-12-30), el formato que entiende la API.
 *
 * NO usa zona horaria a propósito. Google entrega las fechas de las celdas como la medianoche de
 * la zona horaria de la hoja; si esa zona no está definida (hojas subidas desde Excel) o no
 * coincide con la que se usara para formatear, el día salia corrido (el 1 de octubre pasaba a ser
 * el 30 de septiembre). Aquí se redondea al día más cercano: una medianoche local queda a menos
 * de 12 horas de su día en UTC para cualquier zona de América o Europa, y las celdas de esta hoja
 * son siempre fechas sin hora.
 */
function aSerial_(fecha) {
  return Math.round((fecha.getTime() - Date.UTC(1899, 11, 30)) / 86400000);
}

/** Número serial de Excel → 'AAAA-MM-DD'. */
function fechaDeSerial_(serial) {
  return new Date(Date.UTC(1899, 11, 30) + serial * 86400000).toISOString().slice(0, 10);
}

/** Número serial de Excel → 'AAAA-MM'. */
function mesDeSerial_(serial) {
  return fechaDeSerial_(serial).slice(0, 7);
}

/** Deja una celda lista para enviar: fechas como serial, celdas vacías como null, el resto igual. */
function normalizar_(valor) {
  if (valor instanceof Date) return aSerial_(valor);
  if (valor === '') return null;
  return valor;
}

/**
 * Entre varias filas de una misma persona, elige la de fecha de alta más reciente.
 * A igualdad (o sin fecha) gana la que está más abajo en la hoja.
 * @param {{fila:number, alta:(number|null)}[]} candidatos
 * @return {number|null} número de fila, o null si no hay candidatos
 */
function elegirFila_(candidatos) {
  let mejor = null;
  candidatos.forEach(function (c) {
    const alta = typeof c.alta === 'number' ? c.alta : -1;
    if (mejor === null || alta >= mejor.alta) mejor = { fila: c.fila, alta: alta };
  });
  return mejor === null ? null : mejor.fila;
}

/**
 * Busca, entre los encabezados de la hoja, la columna del mes 'AAAA-MM'.
 * Solo se consideran las columnas desde PRIMERA_COL_MES cuyo encabezado es una fecha.
 * @return {number|null} número de columna (base 1), o null si no existe
 */
function columnaDeMes_(encabezados, periodo) {
  for (let i = CONFIG.PRIMERA_COL_MES - 1; i < encabezados.length; i++) {
    const h = encabezados[i];
    if (h instanceof Date && mesDeSerial_(aSerial_(h)) === periodo) return i + 1;
  }
  return null;
}

/**
 * Reparte grupos de filas (un grupo = todas las filas de un RUT) en tandas de hasta `maximo`
 * filas sin partir nunca un grupo. Un grupo más grande que el máximo viaja solo.
 * @param {Array<Array>} grupos
 * @return {Array<Array>} lista de tandas, cada una una lista plana de filas
 */
function armarTandas_(grupos, maximo) {
  const tandas = [];
  let actual = [];
  grupos.forEach(function (g) {
    if (actual.length > 0 && actual.length + g.length > maximo) {
      tandas.push(actual);
      actual = [];
    }
    actual = actual.concat(g);
  });
  if (actual.length > 0) tandas.push(actual);
  return tandas;
}
