/* =====================================================================
   cloud / app.js
   SPA: hash routing + render del contenido. Ver STACK.md §4.
   ===================================================================== */
'use strict';

// ------------------------- Utilidades -------------------------
const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function fmtNum(n) {
  if (n == null || isNaN(n)) return '—';
  return Number(n).toLocaleString('es-AR');
}

function fmtFecha(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const pad = (x) => String(x).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Tiempo relativo compacto en castellano ("hace 3 min", "hace 2 h", "hace 4 d",
// "hace 2 mes", "hace 1 a"). Sin dependencias; equivalente al
// $oTiempo->traducir($oTiempo->diferencia(...)) del legacy.
function fmtHace(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const diff = Math.max(0, (Date.now() - d.getTime()) / 1000); // en segundos
  if (diff < 60)         return 'hace ' + Math.floor(diff)         + ' s';
  if (diff < 3600)       return 'hace ' + Math.floor(diff / 60)    + ' min';
  if (diff < 86400)      return 'hace ' + Math.floor(diff / 3600)  + ' h';
  if (diff < 2592000)    return 'hace ' + Math.floor(diff / 86400) + ' d';
  if (diff < 31536000)   return 'hace ' + Math.floor(diff / 2592000)  + ' mes';
  return 'hace ' + Math.floor(diff / 31536000) + ' a';
}

// YYYY-MM-DD HH:MM:SS — para listados donde importa el segundo (log de mensajes).
function fmtFechaLarga(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const pad = (x) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

// Convierte texto libre a un slug dot-separated compatible con la regex
// `^[a-z0-9][a-z0-9._-]*$` que usan `roles.slug` y `permisos.slug`.
// Normaliza a minusculas, quita diacriticos comunes en espanol y colapsa
// cualquier corrida de caracteres no [a-z0-9] a un unico punto. Trunca a 100
// (el maxlength de las columnas `slug` en la BD).
function slugificarConPuntos(txt) {
  if (txt == null) return '';
  return String(txt)
    .toLowerCase()
    .replace(/[áàäâã]/g, 'a')
    .replace(/[éèëê]/g,  'e')
    .replace(/[íìïî]/g,  'i')
    .replace(/[óòöôõ]/g, 'o')
    .replace(/[úùüû]/g,  'u')
    .replace(/ñ/g,       'n')
    .replace(/ç/g,       'c')
    .replace(/[^a-z0-9]+/g, '.')
    .replace(/^\.+/, '')
    .replace(/\.+$/, '')
    .substring(0, 100)
    .replace(/\.+$/, '');
}

async function apiGet(url) {
  const r = await fetch(url, { credentials: 'same-origin' });
  const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta no JSON' }));
  if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
  return j.data;
}

async function apiSend(url, method, body) {
  const r = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: body == null ? null : JSON.stringify(body),
  });
  const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta no JSON' }));
  if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
  return j.data;
}

// ------------------------- Polling de versión -------------------------
// Muestra el banner "nueva versión disponible" (#versionBanner, oculto por
// default en index.php) cuando `version.txt` en el servidor cambia respecto
// al que cargó la página al abrir la pestaña. Se activa después de un
// `git push` + deploy: al próximo poll cada cliente ve el aviso y recarga.
const VERSION_INICIAL = document.getElementById('appVersion')?.textContent?.trim() || '';
async function chequearVersion() {
  try {
    const r = await fetch('api/version.php', { cache: 'no-store' });
    const data = await r.json();
    if (data.ok && data.version && VERSION_INICIAL && data.version !== VERSION_INICIAL) {
      const b = document.getElementById('versionBanner');
      if (b) b.style.display = '';
    }
  } catch (_) { /* silencioso: se reintenta en el próximo tick */ }
}
document.addEventListener('DOMContentLoaded', () => {
  setInterval(chequearVersion, 5000);
});

// ------------------------- Toast -------------------------
function toast(msg, opts = {}) {
  let t = $('#toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'toast';
    t.className = 'toast';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.classList.toggle('error', !!opts.error);
  t.classList.add('show');
  clearTimeout(toast._h);
  // Errores viven 10s (dan tiempo a leer un stack de mTLS / API); resto 2.4s.
  const defaultDuration = opts.error ? 10000 : 2400;
  const duration = typeof opts.duration === 'number' ? opts.duration : defaultDuration;
  toast._h = setTimeout(() => t.classList.remove('show'), duration);
}

// ------------------------- Modal helpers -------------------------
function openModal(html) {
  closeModal();
  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.id = 'modalRoot';
  wrap.innerHTML = html;
  document.body.appendChild(wrap);
  requestAnimationFrame(() => wrap.classList.add('open'));
  wrap.addEventListener('click', (ev) => { if (ev.target === wrap) closeModal(); });
  return wrap;
}
function closeModal() {
  const m = $('#modalRoot');
  if (!m) return;
  // Liberar el id ya para que un openModal() inmediato no quede compitiendo con
  // este nodo durante los 200 ms de animacion de cierre.
  m.id = '';
  m.classList.remove('open');
  setTimeout(() => m.remove(), 200);
}

function confirmar({ title, message, confirmText = 'Confirmar', danger = true }) {
  return new Promise((resolve) => {
    const wrap = document.createElement('div');
    wrap.className = 'confirm-backdrop';
    wrap.innerHTML = `
      <div class="confirm-box">
        <div class="confirm-title">${esc(title)}</div>
        <div class="confirm-msg">${esc(message)}</div>
        <div class="confirm-actions">
          <button class="btn btn-ghost" data-act="no">Cancelar</button>
          <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-act="yes">${esc(confirmText)}</button>
        </div>
      </div>`;
    document.body.appendChild(wrap);
    requestAnimationFrame(() => wrap.classList.add('open'));
    const close = (val) => {
      wrap.classList.remove('open');
      setTimeout(() => wrap.remove(), 150);
      resolve(val);
    };
    wrap.addEventListener('click', (ev) => {
      if (ev.target === wrap) close(false);
      const a = ev.target.closest('[data-act]');
      if (!a) return;
      close(a.dataset.act === 'yes');
    });
  });
}

// ------------------------- Menú contextual genérico (ABM.md) -------------------------
// Un único menú por sección (ya renderizado en el DOM). Lo abrimos posicionándolo
// como `position: fixed` cerca del clic / botón hamburguesa. El módulo guarda el
// contexto (id del registro, datos) en `getCtxMenuData()` para que los handlers
// `data-action` puedan resolverlo al disparar.
let _ctxMenuActual = null;
let _ctxMenuData   = null;

function abrirCtxMenu(menuEl, x, y, data) {
  cerrarCtxMenu();
  if (!menuEl) return;
  _ctxMenuActual = menuEl;
  _ctxMenuData   = data || null;
  menuEl.classList.add('open');
  // medir y reposicionar dentro del viewport
  const rect = menuEl.getBoundingClientRect();
  const w    = rect.width  || 200;
  const h    = rect.height || 200;
  let nx = x, ny = y;
  if (nx + w > window.innerWidth  - 8) nx = window.innerWidth  - w - 8;
  if (ny + h > window.innerHeight - 8) ny = window.innerHeight - h - 8;
  if (nx < 8) nx = 8;
  if (ny < 8) ny = 8;
  menuEl.style.left = nx + 'px';
  menuEl.style.top  = ny + 'px';
}

function cerrarCtxMenu() {
  if (!_ctxMenuActual) return;
  _ctxMenuActual.classList.remove('open');
  _ctxMenuActual = null;
  _ctxMenuData   = null;
}

function getCtxMenuData() { return _ctxMenuData; }

document.addEventListener('click', (ev) => {
  if (!_ctxMenuActual) return;
  if (_ctxMenuActual.contains(ev.target)) return;
  cerrarCtxMenu();
});
document.addEventListener('scroll', () => cerrarCtxMenu(), true);
window.addEventListener('resize',  () => cerrarCtxMenu());
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') cerrarCtxMenu();
});

// ------------------------- Router -------------------------
const routes = {};
function route(path, handler, title) {
  routes[path] = { handler, title: title || path };
}

// Mapeo ruta → permiso requerido para navegar.
// - `perm`   = slug exacto que el usuario debe tener.
// - `prefix` = habilita la ruta si el usuario tiene AL MENOS UN permiso con
//              ese prefijo (para landings de plataformas y Herramientas cuyo
//              contenido son sub-modulos con permisos propios).
// Rutas ausentes de este mapa quedan libres (fallback: si no se declaro, no se filtra).
const ROUTE_PERMS = {
  '/dashboard':                { perm:   'inicio.dashboard.consultar' },

  '/datacountcomprobantes':    { perm:   'datacount.comprobantes.consultar' },
  '/datacountfacturacion':     { perm:   'datacount.facturacion.consultar' },
  '/datacountasientos':        { perm:   'datacount.asientos.consultar' },
  '/datacountempleados':       { perm:   'datacount.empleados.consultar' },
  '/datacountrecurrentes':     { perm:   'datacount.recurrentes.consultar' },
  '/datacountcuentas':         { perm:   'datacount.cuentas.consultar' },
  '/datacountempresas':        { perm:   'datacount.empresas.consultar' },

  '/datarocketcontactos':      { perm:   'datarocket.contactos.consultar' },
  '/datarocketmensajes':       { perm:   'datarocket.mensajes.consultar' },

  '/prospectos':               { perm:   'datasale.prospectos.consultar' },

  '/aws':                      { prefix: 'plataformas.aws.' },
  '/awscuentas':               { perm:   'plataformas.aws.cuentas.consultar' },

  '/awsses':                   { prefix: 'plataformas.awsses.' },
  '/awssescanales':            { perm:   'plataformas.awsses.canales.consultar' },
  '/awssesmensajes':           { perm:   'plataformas.awsses.mensajes.consultar' },

  '/evolution':                { prefix: 'plataformas.evolution.' },
  '/evolutioncanales':         { perm:   'plataformas.evolution.canales.consultar' },
  '/evolutioncontactos':       { perm:   'plataformas.evolution.contactos.consultar' },
  '/evolutionmensajes':        { perm:   'plataformas.evolution.mensajes.consultar' },

  '/mercadopago':              { prefix: 'plataformas.mercadopago.' },
  '/mercadopagopagos':         { perm:   'plataformas.mercadopago.pagos.consultar' },
  '/mercadopagocuentas':       { perm:   'plataformas.mercadopago.cuentas.consultar' },
  '/mercadopagoregistros':     { perm:   'plataformas.mercadopago.registros.consultar' },
  '/mercadopagosuscripciones': { perm:   'plataformas.mercadopago.suscripciones.consultar' },
  '/mercadopagodebitos':       { perm:   'plataformas.mercadopago.debitos.consultar' },

  '/dolarhoy':                 { prefix: 'plataformas.dolarhoy.' },
  '/dolarhoycotizaciones':     { perm:   'plataformas.dolarhoy.cotizaciones.consultar' },

  '/movistar':                 { prefix: 'plataformas.movistar.' },
  '/movistarsims':             { perm:   'plataformas.movistar.sims.consultar' },

  '/claro':                    { prefix: 'plataformas.claro.' },
  '/clarosims':                { perm:   'plataformas.claro.sims.consultar' },

  '/openai':                   { prefix: 'plataformas.openai.' },
  '/openaiconsumos':           { perm:   'plataformas.openai.consumos.consultar' },

  '/anthropic':                { prefix: 'plataformas.anthropic.' },

  '/usuarios':                 { perm:   'seguridad.usuarios.consultar' },
  '/roles':                    { perm:   'seguridad.roles.consultar' },
  '/permisos':                 { perm:   'seguridad.permisos.consultar' },

  '/herramientas':             { prefix: 'administracion.herramientas.' },
};

// Devuelve true si el usuario logueado puede navegar a la ruta indicada
// segun `ROUTE_PERMS`. Si la ruta no esta declarada, asume libre acceso.
function puedeAccederRuta(path) {
  const g = ROUTE_PERMS[path];
  if (!g)         return true;
  if (g.perm)     return hasPermission(g.perm);
  if (g.prefix)   return hasPermissionPrefix(g.prefix);
  return true;
}

// Camina el sidebar (ya filtrado por `aplicarPermisosSidebar`) y devuelve la
// primera ruta visible. Se usa como fallback cuando el usuario cae en una
// ruta a la que no tiene acceso (redirect a algo que si puede ver).
function primerRutaAccesible() {
  for (const a of $$('.sidebar-nav .nav-sub-item')) {
    if (a.style.display !== 'none') return a.dataset.route;
  }
  return null;
}

function currentPath() {
  const h = location.hash || '#/dashboard';
  return h.startsWith('#') ? h.slice(1) : h;
}

async function render() {
  const path = currentPath();

  // Route guard: si la ruta esta declarada en ROUTE_PERMS y el usuario no
  // tiene el permiso requerido, redirigir a la primera ruta accesible.
  // Si no tiene NINGUNA ruta accesible, mostrar la pantalla "sin acceso".
  if (!puedeAccederRuta(path)) {
    const fallback = primerRutaAccesible();
    if (fallback && '#' + fallback !== location.hash) {
      // El cambio de hash dispara hashchange -> render(), asi que salimos aca.
      location.hash = '#' + fallback;
      return;
    }
    $('#topbarTitle').textContent = 'Sin acceso';
    $('#view').innerHTML = `
      <div class="table-empty" style="padding:60px;text-align:center">
        <div style="font-size:2.5rem;margin-bottom:10px">🔒</div>
        <div style="font-weight:600;margin-bottom:6px">No tenés permisos para acceder a esta sección</div>
        <div style="color:var(--muted)">Contactá al administrador si necesitás acceso.</div>
      </div>
    `;
    return;
  }

  const def = routes[path] || routes['/dashboard'];
  // sidebar activo
  $$('.nav-item').forEach((el) => {
    el.classList.toggle('active', el.getAttribute('data-route') === path);
  });
  // abrir el grupo colapsable que contiene la ruta activa
  $$('.nav-group-wrap').forEach((g) => {
    const hasActive = !!g.querySelector('.nav-item.active');
    if (hasActive) g.classList.add('open');
  });
  // topbar
  $('#topbarTitle').textContent = def.title;
  // cerrar sidebar en mobile
  $('#sidebar').classList.remove('open');
  $('#sidebarOverlay').classList.remove('active');
  // render
  try {
    await def.handler($('#view'));
  } catch (e) {
    $('#view').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// ------------------------- Catálogo de enlaces externos -------------------------
// Datos hardcodeados que alimentan el launcher cascada de la topbar
// (setupEnlacesMenu). La agrupación replica la del legacy
// databox-admin/plataformas|herramientas/inicio.php.

const PLATAFORMAS_GRUPOS = [
  {
    id: 'alojamiento', label: 'Alojamiento', icono: '☁️',
    items: [
      { icono: '☁️', titulo: 'AWS',           desc: 'Consola EC2 (us-east-1).',           url: 'https://us-east-1.console.aws.amazon.com/ec2/v2/home?region=us-east-1#Instances:' },
      { icono: '🌩️', titulo: 'Google Cloud',  desc: 'Consola GCP.',                       url: 'https://console.cloud.google.com/' },
      { icono: '🌐', titulo: 'Cloudflare',    desc: 'Panel principal.',                   url: 'https://dash.cloudflare.com/' },
      { icono: '🖥️', titulo: 'LatinCloud',    desc: 'WHM cPanel.',                        url: 'https://ar151.xvserver.com:2087/cpsess3116822283/scripts4/listaccts' },
      { icono: '🖥️', titulo: 'DonWeb',        desc: 'Panel de hosting.',                  url: 'https://donweb.com/clientes/' },
      { icono: '🎮', titulo: 'Play Console',  desc: 'Google Play — publicación Android.', url: 'https://play.google.com/console/u/0/developers/6570590227569156980/inbox' },
      { icono: '🐳', titulo: 'Portainer',     desc: 'Gestión de contenedores Docker.',    url: 'http://localhost:9000/#!/home' },
    ],
  },
  {
    id: 'automatizacion', label: 'Automatización', icono: '🤖',
    items: [
      { icono: '⚙️', titulo: 'Make',            desc: 'Escenarios de automatización.',      url: 'https://us2.make.com/organization/2163125' },
      { icono: '🧠', titulo: 'OpenAI Platform', desc: 'API, uso y claves de OpenAI.',       url: 'https://platform.openai.com/docs/overview' },
      { icono: '💬', titulo: 'ChatGPT',         desc: 'Chat de OpenAI.',                    url: 'https://chatgpt.com/' },
      { icono: '🔎', titulo: 'Perplexity',      desc: 'Búsqueda conversacional.',           url: 'https://www.perplexity.ai/' },
    ],
  },
  {
    id: 'comunicaciones', label: 'Comunicaciones', icono: '📨',
    items: [
      { icono: '📧', titulo: 'AWS SES',        desc: 'Simple Email Service — envío de correos.', url: 'https://console.aws.amazon.com/ses/' },
      { icono: '📧', titulo: 'Mailjet',        desc: 'Panel de envío de correos.',               url: 'https://app.mailjet.com/' },
      { icono: '💬', titulo: 'Evolution API',  desc: 'Manager de instancias WhatsApp.',          url: 'https://evolution.york.databox.net.ar/manager/' },
      { icono: '💬', titulo: 'Whapi',          desc: 'API WhatsApp — panel.',                    url: 'https://panel.whapi.cloud/' },
      { icono: '💬', titulo: 'Ultramsg',       desc: 'API WhatsApp — panel de usuarios.',        url: 'https://user.ultramsg.com/' },
      { icono: '📱', titulo: 'SMS Masivos',    desc: 'Envío de SMS a Argentina.',                url: 'https://www.smsmasivos.com.ar/' },
      { icono: '📱', titulo: 'Gobsoa',         desc: 'SMS y notificaciones.',                    url: 'https://www.gobsoa.com.ar/' },
      { icono: '📱', titulo: 'Twilio',         desc: 'SMS / Voz / WhatsApp — consola.',          url: 'https://console.twilio.com/' },
      { icono: '🔔', titulo: 'Airship',        desc: 'Push notifications.',                      url: 'https://go.airship.com/' },
      { icono: '🔔', titulo: 'Firebase',       desc: 'Push, hosting y BD — consola.',            url: 'https://console.firebase.google.com/' },
      { icono: '📡', titulo: 'Movistar M2M',   desc: 'Kite — gestión de SIMs M2M.',              url: 'https://kiteplatform-movistar-ar.telefonica.com/' },
      { icono: '📡', titulo: 'Claro M2M',      desc: 'Autogestión empresas.',                    url: 'https://autogestion-empresas.claro.com.ar/sites/launchpad#Shell-home' },
    ],
  },
  {
    id: 'diseno', label: 'Diseño', icono: '🎨',
    items: [
      { icono: '🎨', titulo: 'Canva',           desc: 'Diseño gráfico online.',             url: 'https://www.canva.com/' },
      { icono: '🔤', titulo: 'Google Fonts',    desc: 'Tipografías libres.',                url: 'https://fonts.google.com/' },
      { icono: '⭐', titulo: 'FontAwesome 4',   desc: 'Iconos v4 (legacy).',                url: 'https://fontawesome.com/v4/icons/' },
      { icono: '⭐', titulo: 'FontAwesome 5',   desc: 'Iconos v5 (free).',                  url: 'https://fontawesome.com/v5/search?ic=free-collection' },
      { icono: '⭐', titulo: 'FontAwesome 6',   desc: 'Iconos v6 (free) — el que usamos.',  url: 'https://fontawesome.com/v6/search?ic=free' },
      { icono: '🧱', titulo: 'Elementor',       desc: 'Builder de WordPress.',              url: 'https://my.elementor.com/websites/' },
      { icono: '🔣', titulo: 'Unicode Map',     desc: 'Símbolos y pictogramas Unicode.',    url: 'https://symbl.cc/es/unicode/blocks/miscellaneous-symbols-and-pictographs/#subblock-1F58E' },
      { icono: '📄', titulo: 'Templates',       desc: 'Plantillas Databox.',                url: 'https://www.databox.net.ar/templates' },
    ],
  },
  {
    id: 'dominios', label: 'Dominios', icono: '🌍',
    items: [
      { icono: '🇦🇷', titulo: 'NIC Argentina',    desc: 'Registro de dominios .ar.',   url: 'https://www.nic.ar' },
      { icono: '🏷️', titulo: 'Namecheap',        desc: 'Registrador internacional.',   url: 'https://ap.www.namecheap.com/' },
      { icono: '🏷️', titulo: 'Network Solutions', desc: 'Registrador legacy.',         url: 'https://www.networksolutions.com/' },
    ],
  },
  {
    id: 'marketing', label: 'Marketing', icono: '📢',
    items: [
      { icono: '📈', titulo: 'Google Ads',            desc: 'Campañas de Ads.',                     url: 'https://ads.google.com/aw/overview' },
      { icono: '📊', titulo: 'Google Analytics',      desc: 'Analítica web.',                       url: 'https://analytics.google.com/analytics/web/#/p402561541/reports/intelligenthome' },
      { icono: '🏬', titulo: 'Google Negocios',       desc: 'Perfiles de Empresa.',                 url: 'https://business.google.com/locations' },
      { icono: '🔍', titulo: 'Google Search Console', desc: 'Indexación y búsqueda.',               url: 'https://search.google.com/search-console?resource_id=https%3A%2F%2Fwww.repo.com.ar%2F&hl=es' },
      { icono: '📘', titulo: 'Meta Business',         desc: 'Facebook / Instagram — administración.', url: 'https://business.facebook.com/latest/home?nav_ref=pages_you_manage_navigation' },
    ],
  },
  {
    id: 'logistica', label: 'Logística', icono: '🚚',
    items: [
      { icono: '📦', titulo: 'Correo Argentino', desc: 'Mi Correo — dashboard.', url: 'https://www.correoargentino.com.ar/MiCorreo/public/dashboard' },
      { icono: '📦', titulo: 'Aerobox',          desc: 'Logística internacional.', url: 'https://aeroboxarg.logisticainbox.com/client/new_home.php' },
    ],
  },
  {
    id: 'administracion', label: 'Administración', icono: '💼',
    items: [
      { icono: '🏛️', titulo: 'AFIP',        desc: 'Servicios con clave fiscal.', url: 'https://www.afip.gob.ar/' },
      { icono: '💳', titulo: 'MercadoPago', desc: 'Panel de vendedor.',          url: 'https://www.mercadopago.com.ar/' },
      { icono: '💵', titulo: 'DolarHoy',    desc: 'Cotizaciones del día.',       url: 'https://dolarhoy.com/' },
    ],
  },
];

// El legacy de "Herramientas" tiene un único grupo (Privacidad, mal rotulado):
// lo dejamos plano en la vista y usamos un rótulo más honesto.
const UTILIDADES_GRUPOS = [
  {
    id: 'utilidades', label: 'Utilidades web', icono: '🧰',
    items: [
      { icono: '🎨', titulo: 'AI WebDesign',          desc: 'bolt.new — sitios generados por IA.',        url: 'https://bolt.new/' },
      { icono: '🖼️', titulo: 'Favicon Generator',    desc: 'Genera todos los tamaños de favicon.',       url: 'https://www.favicon-generator.org/' },
      { icono: '📧', titulo: 'Internxt Temp Mail',    desc: 'Correo temporal descartable.',               url: 'https://internxt.com/es/temporary-email' },
      { icono: '🧾', titulo: 'JSON Designer',         desc: 'Editor visual de JSON.',                     url: 'https://jsoneditoronline.org' },
      { icono: '👁️', titulo: 'JSON Viewer',          desc: 'Formatea y colorea JSON.',                   url: 'https://jsonviewer.stack.hu/' },
      { icono: '🔑', titulo: 'JWT Testing',           desc: 'Decodificar y firmar JWTs.',                 url: 'https://jwt.io/' },
      { icono: '📮', titulo: 'Mail Tester',           desc: 'Testeá la reputación de tu correo.',         url: 'https://www.mail-tester.com/' },
      { icono: '🔐', titulo: 'Password Generator',    desc: 'Contraseñas aleatorias — Avast.',            url: 'https://www.avast.com/random-password-generator#pc' },
      { icono: '📱', titulo: 'PWA Can Do Today',      desc: 'Capacidades disponibles en PWAs.',           url: 'https://whatpwacando.today/' },
      { icono: '🔳', titulo: 'QR Codes',              desc: 'Generador de QR — variante EN.',             url: 'https://www.codigos-qr.com/en/qr-code-generator/' },
      { icono: '🔳', titulo: 'QR Generator Basic',    desc: 'Generador de QR — variante ES.',             url: 'https://www.codigos-qr.com/generador-de-codigos-qr/' },
      { icono: '🔳', titulo: 'QR Generator Monkey',   desc: 'QRs personalizados con logo.',               url: 'https://www.qrcode-monkey.com/es/' },
      { icono: '🗺️', titulo: 'Sitemap Generator',    desc: 'Genera sitemap.xml.',                        url: 'https://www.xml-sitemaps.com/' },
      { icono: '📄', titulo: 'Small PDF Tools',       desc: 'Convertir, comprimir y firmar PDFs.',        url: 'https://smallpdf.com/es' },
      { icono: '🎤', titulo: 'TTS VozFly',            desc: 'Text-to-speech en español.',                 url: 'https://vozfly.com/' },
      { icono: '✅', titulo: 'Web Check',             desc: 'Auditoría técnica de un dominio.',           url: 'https://www.web-check.xyz' },
      { icono: '🪝', titulo: 'Webhook Cool',          desc: 'Endpoint efímero para inspeccionar webhooks.', url: 'https://webhook.cool' },
    ],
  },
];

// ------------------------- Vista: Dashboard -------------------------
route('/dashboard', async (mount) => {
  mount.innerHTML = `<div style="text-align:center;padding:60px 0"><div class="spin"></div></div>`;

  const data = await apiGet('api/dashboard.php');
  const s = data.stats || {};

  mount.innerHTML = `
    <div class="stats-bar">
      <div class="stat-card">
        <span class="stat-label">Correos enviados hoy</span>
        <span class="stat-value blue">${fmtNum(s.correos_hoy)}</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">WhatsApp enviados hoy</span>
        <span class="stat-value green">${fmtNum(s.whatsapp_hoy)}</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Campañas activas</span>
        <span class="stat-value orange">${fmtNum(s.campanias_activas)}</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Clientes</span>
        <span class="stat-value">${fmtNum(s.clientes)}</span>
      </div>
    </div>

    <div class="dash-grid">
      <div class="table-card">
        <div class="dash-table-header">
          <span>Últimas campañas</span>
          <span class="dash-ver-mas">Ver más</span>
        </div>
        <table>
          <thead>
            <tr>
              <th>Nombre</th><th>Canal</th><th>Estado</th>
              <th style="text-align:right">Enviados</th><th>Fecha</th>
            </tr>
          </thead>
          <tbody>${renderCampanias(data.ultimas_campanias)}</tbody>
        </table>
      </div>

      <div class="table-card">
        <div class="dash-table-header">
          <span>Últimas conversaciones</span>
          <span class="dash-ver-mas">Ver más</span>
        </div>
        <table>
          <thead>
            <tr><th>Contacto</th><th>Teléfono</th><th>Último mensaje</th><th>Fecha</th></tr>
          </thead>
          <tbody>${renderMensajes(data.ultimos_mensajes)}</tbody>
        </table>
      </div>
    </div>
  `;
});

function renderCampanias(rows) {
  if (!rows || !rows.length) {
    return `<tr><td colspan="5" class="table-empty">Sin campañas todavía.</td></tr>`;
  }
  const badgeFor = (e) => {
    const cls = e === 'enviada' ? 'badge-success'
              : e === 'enviando' ? 'badge-info'
              : e === 'pausada'  ? 'badge-warn'
              : e === 'fallida'  ? 'badge-danger'
              : 'badge-info';
    return `<span class="badge ${cls}">${esc(e)}</span>`;
  };
  return rows.map((c) => `
    <tr>
      <td class="td-nombre">${esc(c.nombre)}</td>
      <td>${c.canal === 'whatsapp' ? '💬 WhatsApp' : '📧 Email'}</td>
      <td>${badgeFor(c.estado)}</td>
      <td style="text-align:right">${fmtNum(c.enviados)}</td>
      <td>${fmtFecha(c.fecha)}</td>
    </tr>
  `).join('');
}

function renderMensajes(rows) {
  if (!rows || !rows.length) {
    return `<tr><td colspan="4" class="table-empty">Sin conversaciones todavía.</td></tr>`;
  }
  return rows.map((m) => `
    <tr>
      <td class="td-nombre">${esc(m.contacto)}</td>
      <td class="td-id">${esc(m.telefono)}</td>
      <td>${esc(m.ultimo_mensaje)}</td>
      <td>${fmtFecha(m.fecha)}</td>
    </tr>
  `).join('');
}

// ------------------------- Vista: Usuarios (ABM) -------------------------
// Cache de sesion del catalogo de roles cloud (GET api/roles.php ya filtra los
// legacy por slug — ver 20260711_1200_limpiar_slug_y_descripcion_legacy.sql).
// Se usa en el picker de roles del editor de usuarios. Misma politica de
// invalidacion que `permisosCatalogo`: la pagina no invalida despues de altas
// desde el ABM de roles; el catalogo se refresca al recargar la pestana.
let rolesCatalogo = null;

async function getRolesCatalogo() {
  if (rolesCatalogo) return rolesCatalogo;
  // limite=1000 porque los roles cloud rondan las decenas; con esto entra todo
  // el set sin paginar. order_by=nombre para que el picker salga ordenado.
  const data = await apiGet('api/roles.php?order_by=nombre&dir=asc&limite=1000');
  rolesCatalogo = data.items || [];
  return rolesCatalogo;
}

// Tokeniza un CSV de IDs de rol a array de strings sin vacios.
// Se comparte entre el render del picker y el save (para separar los IDs
// legacy — que no aparecen en el picker — de los cloud tildados).
function tokenizarRoles(raw) {
  if (raw == null) return [];
  return String(raw)
    .split(/[,;\s]+/)
    .map((t) => t.trim())
    .filter(Boolean);
}

const USR_ESTADOS = {
  '1': { label: 'Activo',   badge: 'badge-success' },
  '0': { label: 'Inactivo', badge: 'badge-danger'  },
};
const usuariosFiltrosDefaults = {
  q: '', codigo: '', nombre: '', dni: '', correo: '', celular: '', estado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const usuariosFiltros = { ...usuariosFiltrosDefaults };
let usuariosBuscadorTimer  = null;
let usuariosFiltrosSnapshot = null;
let usuariosCacheRows       = [];

route('/usuarios', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">👥</div>
        <div class="module-help-text">
          Los usuarios son las personas con acceso a la plataforma: cada uno se identifica
          con su correo y contraseña, y los roles asignados determinan qué pueden hacer.
        </div>
      </div>

      <div class="stats-bar" id="usrStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Activos</span><span class="stat-value green">—</span></div>
        <div class="stat-card"><span class="stat-label">Inactivos</span><span class="stat-value red">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="usrSearch"
                   placeholder="🔍 Buscar nombre, correo o DNI…">
            <button class="search-clear" id="usrSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="usrFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="usrFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="usrRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="usrNuevoBtn">+ Nuevo usuario</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>DNI</th>
              <th>Correo</th>
              <th>Celular</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="usrTbody">
            <tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="usrCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md §Modal de filtros) -->
    <div class="modal-backdrop" id="filtrosUsuariosBackdrop"
         onclick="if(event.target===this)cancelarFiltrosUsuarios()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosUsuarios()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fUsrCodigo" min="1" placeholder="ID …" oninput="onFiltroUsuarios('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Nombre</label>
              <input type="text" id="fUsrNombre" oninput="onFiltroUsuarios('nombre', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>DNI</label>
              <input type="text" id="fUsrDni" oninput="onFiltroUsuarios('dni', this.value)">
            </div>
            <div class="form-group">
              <label>Correo</label>
              <input type="text" id="fUsrCorreo" oninput="onFiltroUsuarios('correo', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Celular</label>
            <input type="text" id="fUsrCelular" oninput="onFiltroUsuarios('celular', this.value)">
          </div>
          <div class="form-group">
            <label>Estado del usuario</label>
            <div id="fUsrEstadoChips" style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip" data-estado="" >Todos</button>
              <button type="button" class="filter-chip" data-estado="1">Activo</button>
              <button type="button" class="filter-chip" data-estado="0">Inactivo</button>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fUsrLimite" min="1" max="1000" value="100" onchange="onFiltroUsuarios('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fUsrOrderBy" onchange="onFiltroUsuarios('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="dni">DNI</option>
                <option value="correo">Correo</option>
                <option value="registrado">Fecha de registro</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fUsrDir" onchange="onFiltroUsuarios('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosUsuarios()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosUsuarios()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosUsuarios()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#usrNuevoBtn').addEventListener('click', () => abrirAltaEdicion(null));
  $('#usrFiltrosBtn').addEventListener('click', () => abrirModalFiltrosUsuarios());
  $('#usrRefrescarBtn').addEventListener('click', () => cargarUsuarios());

  const inp = $('#usrSearch');
  const clr = $('#usrSearchClear');
  inp.value = usuariosFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    usuariosFiltros.q = inp.value.trim();
    clearTimeout(usuariosBuscadorTimer);
    usuariosBuscadorTimer = setTimeout(() => { cargarUsuarios(); refrescarBadgeFiltrosUsuarios(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    usuariosFiltros.q = '';
    cargarUsuarios();
    refrescarBadgeFiltrosUsuarios();
  });

  // Acciones del menú contextual (mismo menú para hamburguesa y clic derecho)
  $('#usrCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarUsuario(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicion(data.id);
    if (b.dataset.action === 'eliminar')  eliminarUsuario(data.id);
  });

  // Clic en fila → consultar; clic en hamburguesa → menú
  $('#usrTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#usrCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarUsuario(Number(tr.dataset.id));
  });
  // Clic derecho en fila → menú
  $('#usrTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#usrCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosUsuarios();
  await cargarUsuarios();
}, 'Usuarios');

async function cargarUsuarios() {
  const tbody = $('#usrTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(usuariosFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/usuarios.php?' + qs.toString());
    pintarStatsUsuarios(data.stats);
    usuariosCacheRows = data.items || [];
    pintarTablaUsuarios(usuariosCacheRows);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsUsuarios(s) {
  const cards = $$('#usrStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.activos);
  cards[2].textContent = fmtNum(s.inactivos);
}

function pintarTablaUsuarios(rows) {
  const tbody = $('#usrTbody');
  if (!rows || !rows.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin usuarios.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((u) => {
    const est = USR_ESTADOS[u.estado] || { label: u.estado || '—', badge: 'badge-info' };
    return `
      <tr data-id="${u.id}" class="row-clickable">
        <td class="td-id">#${esc(u.id)}</td>
        <td class="td-nombre">${esc(u.nombre || '—')}</td>
        <td>${esc(u.dni || '—')}</td>
        <td>${esc(u.correo || '—')}</td>
        <td>${esc(u.celular || '—')}</td>
        <td><span class="badge ${est.badge}">${esc(est.label)}</span></td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center">
            <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${u.id}">
              <i class="fa-solid fa-bars"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// ---- Modal de Filtros (Usuarios) ----
function onFiltroUsuarios(key, value) {
  if (key === 'codigo' || key === 'nombre' || key === 'dni' ||
      key === 'correo' || key === 'celular') {
    usuariosFiltros[key] = String(value).trim();
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    usuariosFiltros.limite = n;
  } else {
    usuariosFiltros[key] = value;
  }
  refrescarBadgeFiltrosUsuarios();
  cargarUsuarios();
}

function refrescarBadgeFiltrosUsuarios() {
  const btn   = $('#usrFiltrosBtn');
  const badge = $('#usrFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(usuariosFiltrosDefaults)) {
    if (k === 'q') continue; // el campo rápido tiene su propio control
    if (String(usuariosFiltros[k]) !== String(usuariosFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosUsuarios() {
  const f = usuariosFiltros;
  $('#fUsrCodigo').value  = f.codigo;
  $('#fUsrNombre').value  = f.nombre;
  $('#fUsrDni').value     = f.dni;
  $('#fUsrCorreo').value  = f.correo;
  $('#fUsrCelular').value = f.celular;
  $('#fUsrLimite').value  = f.limite;
  $('#fUsrOrderBy').value = f.order_by;
  $('#fUsrDir').value     = f.dir;
  $$('#fUsrEstadoChips .filter-chip').forEach((c) => {
    c.classList.toggle('active', (c.dataset.estado || '') === (f.estado || ''));
  });
}

function abrirModalFiltrosUsuarios() {
  usuariosFiltrosSnapshot = { ...usuariosFiltros };
  sincronizarControlesFiltrosUsuarios();
  // Bind chips de estado (idempotente)
  $$('#fUsrEstadoChips .filter-chip').forEach((c) => {
    c.onclick = () => { onFiltroUsuarios('estado', c.dataset.estado || ''); sincronizarControlesFiltrosUsuarios(); };
  });
  $('#filtrosUsuariosBackdrop').classList.add('open');
}

function cerrarModalFiltrosUsuarios() {
  $('#filtrosUsuariosBackdrop').classList.remove('open');
}

function cancelarFiltrosUsuarios() {
  if (usuariosFiltrosSnapshot) {
    Object.assign(usuariosFiltros, usuariosFiltrosSnapshot);
    refrescarBadgeFiltrosUsuarios();
    cargarUsuarios();
  }
  cerrarModalFiltrosUsuarios();
}

function limpiarFiltrosUsuarios() {
  Object.assign(usuariosFiltros, usuariosFiltrosDefaults);
  // Mantener el buscador rápido tal cual lo dejó el usuario
  usuariosFiltros.q = $('#usrSearch')?.value.trim() || '';
  sincronizarControlesFiltrosUsuarios();
  refrescarBadgeFiltrosUsuarios();
  cargarUsuarios();
}

// Exponer para los onclick del HTML
window.onFiltroUsuarios          = onFiltroUsuarios;
window.cancelarFiltrosUsuarios   = cancelarFiltrosUsuarios;
window.limpiarFiltrosUsuarios    = limpiarFiltrosUsuarios;
window.cerrarModalFiltrosUsuarios = cerrarModalFiltrosUsuarios;

// ---- Modal Consultar (Usuario) ----
async function abrirConsultarUsuario(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Consultar usuario <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicion(id); }
  });

  try {
    const u   = await apiGet(`api/usuarios.php?id=${id}`);
    const est = USR_ESTADOS[u.estado] || { label: u.estado || '—' };
    const fila = (label, value, full = false, isCode = false) => {
      const empty = value == null || value === '';
      const inner = empty ? 'Sin dato'
                  : isCode ? `<code>${esc(value)}</code>`
                  : esc(value);
      return `
        <div class="data-row${full ? ' full' : ''}">
          <span class="data-label">${esc(label)}</span>
          <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
        </div>
      `;
    };
    $('#modalRoot .modal-body').innerHTML = `
      <dl class="data-list">
        ${fila('Código',    '#' + u.id)}
        ${fila('Estado',    est.label)}
        ${fila('Nombre',    u.nombre,   true)}
        ${fila('DNI',       u.dni)}
        ${fila('Nacimiento',u.nacimiento)}
        ${fila('Correo',    u.correo,   true)}
        ${fila('Celular',   u.celular)}
        ${fila('Sistemas',  u.sistemas)}
        ${fila('Roles',     u.roles,    true)}
        ${fila('Terminal',  u.terminal)}
        ${fila('UUID',      u.uuid,     true, true)}
        ${fila('Registrado',fmtFecha(u.registrado))}
        ${fila('Último ingreso', fmtFecha(u.ingresado))}
      </dl>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicion(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar usuario <span class="modal-subtitle">#${id}</span>` : 'Nuevo usuario'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:40px"><div class="spin"></div></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  try {
    const [u, roles] = await Promise.all([
      esEdicion ? apiGet(`api/usuarios.php?id=${id}`) : Promise.resolve({}),
      getRolesCatalogo(),
    ]);
    $('#modalRoot .modal-body').innerHTML = formUsuarioHtml(u, roles);
    bindRolesBuscadorUsuario();
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close') closeModal();
    if (a.dataset.act === 'guardar') await guardarUsuario(id, a);
    if (a.dataset.act === 'urole-todos')   marcarRolesUsuario(true);
    if (a.dataset.act === 'urole-ninguno') marcarRolesUsuario(false);
  });
}

function formUsuarioHtml(u, rolesCatalogoLocal) {
  const v = (k) => esc(u?.[k] ?? '');
  const sel = (k, val) => (u?.[k] ?? '') === val ? 'selected' : '';

  // Separamos los IDs que el usuario ya tiene asignados en dos grupos:
  //  - Los que matchean el catalogo cloud actual  -> tildan el checkbox
  //  - Los que NO matchean (legacy o borrados)    -> se preservan invisibles
  //    en un input hidden y se re-emiten al guardar, para no destruir data
  //    legacy que este panel no debe manipular.
  const catalogo   = rolesCatalogoLocal || [];
  const idsCloud   = new Set(catalogo.map((r) => String(r.id)));
  const asignados  = tokenizarRoles(u?.roles);
  const tildados   = new Set(asignados.filter((id) => idsCloud.has(String(id))).map(String));
  const preservados = asignados.filter((id) => !idsCloud.has(String(id)));

  const checks = catalogo.map((r) => {
    const checked = tildados.has(String(r.id)) ? 'checked' : '';
    return `
      <label class="perm-item" data-nombre="${esc((r.nombre || '').toLowerCase())}">
        <input type="checkbox" class="perm-check" value="${esc(r.id)}" ${checked}>
        <span class="perm-text">
          <span class="perm-name">${esc(r.nombre || '—')}</span>
          ${r.descripcion ? `<span class="perm-desc">${esc(r.descripcion)}</span>` : ''}
        </span>
        <span class="perm-id">#${esc(r.id)}</span>
      </label>
    `;
  }).join('');

  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre *</label>
        <input type="text" id="uNombre" value="${v('nombre')}" required>
      </div>
      <div class="form-group">
        <label>DNI</label>
        <input type="text" id="uDni" value="${v('dni')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Correo</label>
        <input type="email" id="uCorreo" value="${v('correo')}">
      </div>
      <div class="form-group">
        <label>Celular</label>
        <input type="text" id="uCelular" value="${v('celular')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Fecha de nacimiento</label>
        <input type="date" id="uNacimiento" value="${v('nacimiento')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="uEstado">
          <option value="1" ${sel('estado','1') || (!u?.estado ? 'selected' : '')}>Activo</option>
          <option value="0" ${sel('estado','0')}>Inactivo</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Sistemas</label>
        <input type="text" id="uSistemas" maxlength="10" value="${v('sistemas')}">
      </div>
      <div class="form-group">
        <label>Contraseña${u?.id ? ' <span style="color:var(--muted);font-weight:normal;font-size:.85em">(doble clic para ver)</span>' : ''}</label>
        <input type="password" id="uContrasena" autocomplete="new-password"
               value="${v('contrasena')}"
               title="${u?.id ? 'Doble clic para mostrar / ocultar' : ''}"
               ondblclick="this.type = this.type === 'password' ? 'text' : 'password'">
      </div>
    </div>
    <div class="form-group">
      <label>Roles</label>
      <input type="hidden" id="uRolesLegacy" value="${esc(preservados.join(','))}">
      <div class="perm-toolbar">
        <div class="search-wrap" style="flex:1">
          <input type="search" id="uRolSearch" class="search-input" placeholder="Filtrar roles…" style="width:100%">
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-act="urole-todos">Marcar todos</button>
        <button type="button" class="btn btn-ghost btn-sm" data-act="urole-ninguno">Quitar todos</button>
      </div>
      <div class="perm-list" id="uRolList">
        ${checks || '<div class="table-empty" style="padding:20px">No hay roles definidos en el catálogo.</div>'}
      </div>
    </div>
    <div class="field-error" id="uError" style="display:none"></div>
  `;
}

function bindRolesBuscadorUsuario() {
  const inp = $('#uRolSearch');
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q = inp.value.trim().toLowerCase();
    $$('#uRolList .perm-item').forEach((el) => {
      const nombre = el.dataset.nombre || '';
      el.style.display = !q || nombre.includes(q) ? '' : 'none';
    });
  });
}

function marcarRolesUsuario(checked) {
  $$('#uRolList .perm-item').forEach((el) => {
    if (el.style.display === 'none') return; // respetar el filtro activo
    const c = el.querySelector('.perm-check');
    if (c) c.checked = checked;
  });
}

async function guardarUsuario(id, btn) {
  const nombre = $('#uNombre').value.trim();
  const err    = $('#uError');
  err.style.display = 'none';
  $('#uNombre').classList.remove('input-invalid');

  if (!nombre) {
    $('#uNombre').classList.add('input-invalid');
    err.textContent = 'El nombre es obligatorio.';
    err.style.display = '';
    return;
  }

  // El CSV de roles a guardar concatena:
  //  - Los IDs cloud tildados por el usuario en el picker
  //  - Los IDs legacy (no presentes en el catalogo cloud) que trajimos ocultos
  //    en #uRolesLegacy para no perder asignaciones que este panel no muestra.
  const idsCloud    = $$('#uRolList .perm-check').filter((c) => c.checked).map((c) => c.value);
  const idsPreserv  = tokenizarRoles($('#uRolesLegacy')?.value ?? '');
  const rolesCsv    = [...idsPreserv, ...idsCloud].join(',');

  const payload = {
    nombre,
    dni:        $('#uDni').value.trim(),
    correo:     $('#uCorreo').value.trim(),
    celular:    $('#uCelular').value.trim(),
    nacimiento: $('#uNacimiento').value || null,
    estado:     $('#uEstado').value,
    sistemas:   $('#uSistemas').value.trim(),
    roles:      rolesCsv,
    contrasena: $('#uContrasena').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/usuarios.php', 'POST', payload);
      toast('Usuario creado.');
    } else {
      await apiSend(`api/usuarios.php?id=${id}`, 'PUT', payload);
      toast('Usuario actualizado.');
    }
    closeModal();
    cargarUsuarios();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarUsuario(id) {
  const ok = await confirmar({
    title: 'Eliminar usuario',
    message: `Se eliminará el usuario #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/usuarios.php?id=${id}`, 'DELETE');
    toast('Usuario eliminado.');
    cargarUsuarios();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Roles (ABM) -------------------------
const rolesFiltrosDefaults = {
  q: '', codigo: '', slug: '', nombre: '', descripcion: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const rolesFiltros = { ...rolesFiltrosDefaults };
let rolesBuscadorTimer  = null;
let rolesFiltrosSnapshot = null;
let permisosCatalogo     = null; // cache de GET ?listar=permisos

async function getPermisosCatalogo() {
  if (permisosCatalogo) return permisosCatalogo;
  const data = await apiGet('api/roles.php?listar=permisos');
  permisosCatalogo = data.items || [];
  return permisosCatalogo;
}

// Tokeniza un string CSV/espacios/punto-y-coma a array de ids string (sin vacios).
function tokenizarPermisos(raw) {
  if (raw == null) return [];
  return String(raw)
    .split(/[,;\s]+/)
    .map((t) => t.trim())
    .filter((t) => t !== '');
}

route('/roles', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">🛡️</div>
        <div class="module-help-text">
          Los roles son conjuntos de permisos que se asignan a los usuarios para
          concederles capacidades en la plataforma; cada rol agrupa un perfil de uso típico.
        </div>
      </div>

      <div class="stats-bar" id="rolStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Sin permisos</span><span class="stat-value red">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="rolSearch"
                   placeholder="🔍 Buscar slug, nombre o descripción…">
            <button class="search-clear" id="rolSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="rolFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="rolFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="rolRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="rolNuevoBtn">+ Nuevo rol</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Slug</th>
              <th>Descripción</th>
              <th style="text-align:right">Permisos</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="rolTbody">
            <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="rolCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md §Modal de filtros) -->
    <div class="modal-backdrop" id="filtrosRolesBackdrop"
         onclick="if(event.target===this)cancelarFiltrosRoles()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosRoles()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fRolCodigo" min="1" placeholder="ID …" oninput="onFiltroRoles('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Slug</label>
              <input type="text" id="fRolSlug" style="font-family:var(--font-mono,monospace)" oninput="onFiltroRoles('slug', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Nombre</label>
            <input type="text" id="fRolNombre" oninput="onFiltroRoles('nombre', this.value)">
          </div>
          <div class="form-group">
            <label>Descripción</label>
            <input type="text" id="fRolDescripcion" oninput="onFiltroRoles('descripcion', this.value)">
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fRolLimite" min="1" max="1000" value="100" onchange="onFiltroRoles('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fRolOrderBy" onchange="onFiltroRoles('order_by', this.value)">
                <option value="id">Código</option>
                <option value="slug">Slug</option>
                <option value="nombre">Nombre</option>
                <option value="descripcion">Descripción</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fRolDir" onchange="onFiltroRoles('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosRoles()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosRoles()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosRoles()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#rolNuevoBtn').addEventListener('click', () => abrirAltaEdicionRol(null));
  $('#rolFiltrosBtn').addEventListener('click', () => abrirModalFiltrosRoles());
  $('#rolRefrescarBtn').addEventListener('click', () => cargarRoles());

  const inp = $('#rolSearch');
  const clr = $('#rolSearchClear');
  inp.value = rolesFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    rolesFiltros.q = inp.value.trim();
    clearTimeout(rolesBuscadorTimer);
    rolesBuscadorTimer = setTimeout(() => { cargarRoles(); refrescarBadgeFiltrosRoles(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    rolesFiltros.q = '';
    cargarRoles();
    refrescarBadgeFiltrosRoles();
  });

  $('#rolCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarRol(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionRol(data.id);
    if (b.dataset.action === 'eliminar')  eliminarRol(data.id);
  });

  $('#rolTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#rolCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarRol(Number(tr.dataset.id));
  });
  $('#rolTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#rolCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosRoles();
  await cargarRoles();
}, 'Roles');

async function cargarRoles() {
  const tbody = $('#rolTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(rolesFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/roles.php?' + qs.toString());
    pintarStatsRoles(data.stats);
    pintarTablaRoles(data.items);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsRoles(s) {
  const cards = $$('#rolStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.sin_permisos);
}

function pintarTablaRoles(rows) {
  const tbody = $('#rolTbody');
  if (!rows || !rows.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Sin roles.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td class="td-nombre">${esc(r.nombre || '—')}</td>
      <td>${r.slug ? `<code>${esc(r.slug)}</code>` : '—'}</td>
      <td>${esc(r.descripcion || '—')}</td>
      <td style="text-align:right">${fmtNum(r.permisos_count || 0)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de Filtros (Roles) ----
function onFiltroRoles(key, value) {
  if (key === 'codigo' || key === 'nombre' || key === 'descripcion') {
    rolesFiltros[key] = String(value).trim();
  } else if (key === 'slug') {
    rolesFiltros.slug = String(value).trim().toLowerCase();
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    rolesFiltros.limite = n;
  } else {
    rolesFiltros[key] = value;
  }
  refrescarBadgeFiltrosRoles();
  cargarRoles();
}

function refrescarBadgeFiltrosRoles() {
  const btn   = $('#rolFiltrosBtn');
  const badge = $('#rolFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(rolesFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(rolesFiltros[k]) !== String(rolesFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosRoles() {
  const f = rolesFiltros;
  $('#fRolCodigo').value      = f.codigo;
  $('#fRolSlug').value        = f.slug;
  $('#fRolNombre').value      = f.nombre;
  $('#fRolDescripcion').value = f.descripcion;
  $('#fRolLimite').value      = f.limite;
  $('#fRolOrderBy').value     = f.order_by;
  $('#fRolDir').value         = f.dir;
}

function abrirModalFiltrosRoles() {
  rolesFiltrosSnapshot = { ...rolesFiltros };
  sincronizarControlesFiltrosRoles();
  $('#filtrosRolesBackdrop').classList.add('open');
}

function cerrarModalFiltrosRoles() {
  $('#filtrosRolesBackdrop').classList.remove('open');
}

function cancelarFiltrosRoles() {
  if (rolesFiltrosSnapshot) {
    Object.assign(rolesFiltros, rolesFiltrosSnapshot);
    refrescarBadgeFiltrosRoles();
    cargarRoles();
  }
  cerrarModalFiltrosRoles();
}

function limpiarFiltrosRoles() {
  Object.assign(rolesFiltros, rolesFiltrosDefaults);
  rolesFiltros.q = $('#rolSearch')?.value.trim() || '';
  sincronizarControlesFiltrosRoles();
  refrescarBadgeFiltrosRoles();
  cargarRoles();
}

window.onFiltroRoles           = onFiltroRoles;
window.cancelarFiltrosRoles    = cancelarFiltrosRoles;
window.limpiarFiltrosRoles     = limpiarFiltrosRoles;
window.cerrarModalFiltrosRoles = cerrarModalFiltrosRoles;

// ---- Modal Consultar (rol) ----
async function abrirConsultarRol(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Consultar rol <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionRol(id); }
  });

  try {
    const [r, catalogo] = await Promise.all([
      apiGet(`api/roles.php?id=${id}`),
      getPermisosCatalogo().catch(() => []),
    ]);
    const ids   = tokenizarPermisos(r.permisos);
    const byId  = new Map(catalogo.map((p) => [String(p.id), p]));
    const chips = ids.length
      ? ids.map((pid) => {
          const p = byId.get(String(pid));
          const label = p ? `${p.nombre} (#${p.id})` : `#${pid}`;
          return `<span class="badge badge-info" style="margin:2px 4px 2px 0">${esc(label)}</span>`;
        }).join('')
      : '';

    const fila = (label, value, full = false, isCode = false, html = null) => {
      const empty = html == null && (value == null || value === '');
      const inner = html != null ? html
                  : empty ? 'Sin dato'
                  : isCode ? `<code>${esc(value)}</code>`
                  : esc(value);
      return `
        <div class="data-row${full ? ' full' : ''}">
          <span class="data-label">${esc(label)}</span>
          <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
        </div>
      `;
    };
    $('#modalRoot .modal-body').innerHTML = `
      <dl class="data-list">
        ${fila('Código',      '#' + r.id)}
        ${fila('Slug',        r.slug, false, true)}
        ${fila('Nombre',      r.nombre, true, false)}
        ${fila('Descripción', r.descripcion, true, false)}
        ${fila('Permisos',    String(ids.length), false, false)}
        ${fila('Detalle de permisos', null, true, false, chips || '<span class="data-value muted">Sin permisos asignados</span>')}
      </dl>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// ---- Modal Alta / Edición (rol) ----
async function abrirAltaEdicionRol(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar rol <span class="modal-subtitle">#${id}</span>` : 'Nuevo rol'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:40px"><div class="spin"></div></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  try {
    const [rol, catalogo] = await Promise.all([
      esEdicion ? apiGet(`api/roles.php?id=${id}`) : Promise.resolve({}),
      getPermisosCatalogo(),
    ]);
    $('#modalRoot .modal-body').innerHTML = formRolHtml(rol, catalogo);
    bindPermisosBuscador();
    bindAutoSlugRol();
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close') closeModal();
    if (a.dataset.act === 'guardar') await guardarRol(id, a);
    if (a.dataset.act === 'perm-todos')   marcarPermisos(true);
    if (a.dataset.act === 'perm-ninguno') marcarPermisos(false);
  });
}

function formRolHtml(r, catalogo) {
  const v = (k) => esc(r?.[k] ?? '');
  const seleccionados = new Set(tokenizarPermisos(r?.permisos).map(String));

  const checks = (catalogo || []).map((p) => {
    const checked = seleccionados.has(String(p.id)) ? 'checked' : '';
    return `
      <label class="perm-item" data-nombre="${esc((p.nombre || '').toLowerCase())}">
        <input type="checkbox" class="perm-check" value="${esc(p.id)}" ${checked}>
        <span class="perm-text">
          <span class="perm-name">${esc(p.nombre || '—')}</span>
          ${p.descripcion ? `<span class="perm-desc">${esc(p.descripcion)}</span>` : ''}
        </span>
        <span class="perm-id">#${esc(p.id)}</span>
      </label>
    `;
  }).join('');

  return `
    <div class="form-row">
      <div class="form-group">
        <label>Código</label>
        <input type="text" value="${r?.id ? '#' + r.id : '(se asigna al crear)'}" readonly>
      </div>
      <div class="form-group">
        <label class="label-with-help">
          <span>Slug *</span>
          <i class="fa-solid fa-circle-question label-help" tabindex="0"
             title="Identificador que la aplicación usa para validar los permisos a las distintas áreas del sistema. Minúsculas, números, punto, guion y guion bajo. Se sugiere automáticamente a partir del nombre."></i>
        </label>
        <input type="text" id="rSlug" value="${v('slug')}" required
               style="font-family:var(--font-mono,monospace)"
               placeholder="ej: administrador.general, editor.contenidos"
               pattern="^[a-z0-9][a-z0-9._-]*$" maxlength="100">
      </div>
    </div>
    <div class="form-group">
      <label>Nombre *</label>
      <input type="text" id="rNombre" value="${v('nombre')}" required>
    </div>
    <div class="form-group">
      <label>Descripción</label>
      <input type="text" id="rDescripcion" value="${v('descripcion')}">
    </div>
    <div class="form-group">
      <label>Permisos</label>
      <div class="perm-toolbar">
        <div class="search-wrap" style="flex:1">
          <input type="search" id="rPermSearch" class="search-input" placeholder="Filtrar permisos…" style="width:100%">
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-act="perm-todos">Marcar todos</button>
        <button type="button" class="btn btn-ghost btn-sm" data-act="perm-ninguno">Quitar todos</button>
      </div>
      <div class="perm-list" id="rPermList">
        ${checks || '<div class="table-empty" style="padding:20px">No hay permisos definidos en el catálogo.</div>'}
      </div>
    </div>
    <div class="field-error" id="rError" style="display:none"></div>
  `;
}

function bindPermisosBuscador() {
  const inp = $('#rPermSearch');
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q = inp.value.trim().toLowerCase();
    $$('#rPermList .perm-item').forEach((el) => {
      const nombre = el.dataset.nombre || '';
      el.style.display = !q || nombre.includes(q) ? '' : 'none';
    });
  });
}

// Auto-genera el slug del rol a partir del nombre mientras el usuario tipea,
// respetando la manualidad: si el usuario edita el campo slug directamente
// (o abre el modal en edicion con un slug ya cargado), dejamos de sincronizar.
// Si vuelve a vaciarlo, retomamos la auto-sincronizacion.
function bindAutoSlugRol() {
  const nombreEl = $('#rNombre');
  const slugEl   = $('#rSlug');
  if (!nombreEl || !slugEl) return;

  let slugManualmenteTocado = slugEl.value.trim() !== '';

  nombreEl.addEventListener('input', () => {
    if (slugManualmenteTocado) return;
    slugEl.value = slugificarConPuntos(nombreEl.value);
  });

  slugEl.addEventListener('input', () => {
    slugManualmenteTocado = slugEl.value.trim() !== '';
  });
}

function marcarPermisos(checked) {
  $$('#rPermList .perm-item').forEach((el) => {
    if (el.style.display === 'none') return; // respetar el filtro activo
    const c = el.querySelector('.perm-check');
    if (c) c.checked = checked;
  });
}

async function guardarRol(id, btn) {
  const slug   = $('#rSlug').value.trim().toLowerCase();
  const nombre = $('#rNombre').value.trim();
  const err    = $('#rError');
  err.style.display = 'none';
  $('#rSlug').classList.remove('input-invalid');
  $('#rNombre').classList.remove('input-invalid');

  if (!slug) {
    $('#rSlug').classList.add('input-invalid');
    err.textContent = 'El slug es obligatorio.';
    err.style.display = '';
    return;
  }
  if (!/^[a-z0-9][a-z0-9._-]*$/.test(slug)) {
    $('#rSlug').classList.add('input-invalid');
    err.textContent = 'El slug solo admite minúsculas, números, punto, guion y guion bajo, y debe empezar con letra o número.';
    err.style.display = '';
    return;
  }
  if (!nombre) {
    $('#rNombre').classList.add('input-invalid');
    err.textContent = 'El nombre es obligatorio.';
    err.style.display = '';
    return;
  }

  const ids = $$('#rPermList .perm-check')
    .filter((c) => c.checked)
    .map((c) => c.value);

  const payload = {
    slug,
    nombre,
    descripcion: $('#rDescripcion').value.trim(),
    permisos:    ids.join(','),
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/roles.php', 'POST', payload);
      toast('Rol creado.');
    } else {
      await apiSend(`api/roles.php?id=${id}`, 'PUT', payload);
      toast('Rol actualizado.');
    }
    closeModal();
    cargarRoles();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarRol(id) {
  const ok = await confirmar({
    title: 'Eliminar rol',
    message: `Se eliminará el rol #${id}. Los usuarios que lo tengan asignado quedarán con la referencia colgada hasta editarlos.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/roles.php?id=${id}`, 'DELETE');
    toast('Rol eliminado.');
    cargarRoles();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Permisos (ABM) -------------------------
const permisosFiltrosDefaults = {
  q: '', codigo: '', slug: '', nombre: '', descripcion: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const permisosFiltros = { ...permisosFiltrosDefaults };
let permisosBuscadorTimer  = null;
let permisosFiltrosSnapshot = null;

route('/permisos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">🔑</div>
        <div class="module-help-text">
          Los permisos son las capacidades individuales del sistema (por ejemplo, gestionar
          usuarios o enviar campañas) que se agrupan en roles y luego se asignan a los usuarios.
        </div>
      </div>

      <div class="stats-bar" id="permStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Sin descripción</span><span class="stat-value red">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="permSearch"
                   placeholder="🔍 Buscar slug, nombre o descripción…">
            <button class="search-clear" id="permSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="permFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="permFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="permRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="permNuevoBtn">+ Nuevo permiso</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Slug</th>
              <th>Descripción</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="permTbody">
            <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="permCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md §Modal de filtros) -->
    <div class="modal-backdrop" id="filtrosPermisosBackdrop"
         onclick="if(event.target===this)cancelarFiltrosPermisos()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosPermisos()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fPermCodigo" min="1" placeholder="ID …" oninput="onFiltroPermisos('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Slug</label>
              <input type="text" id="fPermSlug" style="font-family:var(--font-mono,monospace)" oninput="onFiltroPermisos('slug', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Nombre</label>
            <input type="text" id="fPermNombre" oninput="onFiltroPermisos('nombre', this.value)">
          </div>
          <div class="form-group">
            <label>Descripción</label>
            <input type="text" id="fPermDescripcion" oninput="onFiltroPermisos('descripcion', this.value)">
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fPermLimite" min="1" max="1000" value="100" onchange="onFiltroPermisos('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fPermOrderBy" onchange="onFiltroPermisos('order_by', this.value)">
                <option value="id">Código</option>
                <option value="slug">Slug</option>
                <option value="nombre">Nombre</option>
                <option value="descripcion">Descripción</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fPermDir" onchange="onFiltroPermisos('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosPermisos()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosPermisos()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosPermisos()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#permNuevoBtn').addEventListener('click', () => abrirAltaEdicionPermiso(null));
  $('#permFiltrosBtn').addEventListener('click', () => abrirModalFiltrosPermisos());
  $('#permRefrescarBtn').addEventListener('click', () => cargarPermisos());

  const inp = $('#permSearch');
  const clr = $('#permSearchClear');
  inp.value = permisosFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    permisosFiltros.q = inp.value.trim();
    clearTimeout(permisosBuscadorTimer);
    permisosBuscadorTimer = setTimeout(() => { cargarPermisos(); refrescarBadgeFiltrosPermisos(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    permisosFiltros.q = '';
    cargarPermisos();
    refrescarBadgeFiltrosPermisos();
  });

  $('#permCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarPermiso(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionPermiso(data.id);
    if (b.dataset.action === 'eliminar')  eliminarPermiso(data.id);
  });

  $('#permTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#permCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarPermiso(Number(tr.dataset.id));
  });
  $('#permTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#permCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosPermisos();
  await cargarPermisos();
}, 'Permisos');

async function cargarPermisos() {
  const tbody = $('#permTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(permisosFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/permisos.php?' + qs.toString());
    pintarStatsPermisos(data.stats);
    pintarTablaPermisos(data.items);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsPermisos(s) {
  const cards = $$('#permStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.sin_descripcion);
}

function pintarTablaPermisos(rows) {
  const tbody = $('#permTbody');
  if (!rows || !rows.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Sin permisos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((p) => `
    <tr data-id="${p.id}" class="row-clickable">
      <td class="td-id">#${esc(p.id)}</td>
      <td class="td-nombre">${esc(p.nombre || '—')}</td>
      <td>${p.slug ? `<code>${esc(p.slug)}</code>` : '—'}</td>
      <td>${esc(p.descripcion || '—')}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${p.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de Filtros (Permisos) ----
function onFiltroPermisos(key, value) {
  if (key === 'codigo' || key === 'nombre' || key === 'descripcion') {
    permisosFiltros[key] = String(value).trim();
  } else if (key === 'slug') {
    permisosFiltros.slug = String(value).trim().toLowerCase();
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    permisosFiltros.limite = n;
  } else {
    permisosFiltros[key] = value;
  }
  refrescarBadgeFiltrosPermisos();
  cargarPermisos();
}

function refrescarBadgeFiltrosPermisos() {
  const btn   = $('#permFiltrosBtn');
  const badge = $('#permFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(permisosFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(permisosFiltros[k]) !== String(permisosFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosPermisos() {
  const f = permisosFiltros;
  $('#fPermCodigo').value      = f.codigo;
  $('#fPermSlug').value        = f.slug;
  $('#fPermNombre').value      = f.nombre;
  $('#fPermDescripcion').value = f.descripcion;
  $('#fPermLimite').value      = f.limite;
  $('#fPermOrderBy').value     = f.order_by;
  $('#fPermDir').value         = f.dir;
}

function abrirModalFiltrosPermisos() {
  permisosFiltrosSnapshot = { ...permisosFiltros };
  sincronizarControlesFiltrosPermisos();
  $('#filtrosPermisosBackdrop').classList.add('open');
}

function cerrarModalFiltrosPermisos() {
  $('#filtrosPermisosBackdrop').classList.remove('open');
}

function cancelarFiltrosPermisos() {
  if (permisosFiltrosSnapshot) {
    Object.assign(permisosFiltros, permisosFiltrosSnapshot);
    refrescarBadgeFiltrosPermisos();
    cargarPermisos();
  }
  cerrarModalFiltrosPermisos();
}

function limpiarFiltrosPermisos() {
  Object.assign(permisosFiltros, permisosFiltrosDefaults);
  permisosFiltros.q = $('#permSearch')?.value.trim() || '';
  sincronizarControlesFiltrosPermisos();
  refrescarBadgeFiltrosPermisos();
  cargarPermisos();
}

window.onFiltroPermisos           = onFiltroPermisos;
window.cancelarFiltrosPermisos    = cancelarFiltrosPermisos;
window.limpiarFiltrosPermisos     = limpiarFiltrosPermisos;
window.cerrarModalFiltrosPermisos = cerrarModalFiltrosPermisos;

// ---- Modal Consultar (permiso) ----
async function abrirConsultarPermiso(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Consultar permiso <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionPermiso(id); }
  });

  try {
    const p = await apiGet(`api/permisos.php?id=${id}`);
    const fila = (label, value, full = false, isCode = false) => {
      const empty = value == null || value === '';
      const inner = empty ? 'Sin dato'
                  : isCode ? `<code>${esc(value)}</code>`
                  : esc(value);
      return `
        <div class="data-row${full ? ' full' : ''}">
          <span class="data-label">${esc(label)}</span>
          <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
        </div>
      `;
    };
    $('#modalRoot .modal-body').innerHTML = `
      <dl class="data-list">
        ${fila('Código',      '#' + p.id)}
        ${fila('Slug',        p.slug,        false, true)}
        ${fila('Nombre',      p.nombre,      true)}
        ${fila('Descripción', p.descripcion, true)}
      </dl>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// ---- Modal Alta / Edición (permiso) ----
async function abrirAltaEdicionPermiso(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar permiso <span class="modal-subtitle">#${id}</span>` : 'Nuevo permiso'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formPermisoHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const p = await apiGet(`api/permisos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formPermisoHtml(p);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close') closeModal();
    if (a.dataset.act === 'guardar') await guardarPermiso(id, a);
  });
}

function formPermisoHtml(p) {
  const v = (k) => esc(p?.[k] ?? '');
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Código</label>
        <input type="text" value="${p?.id ? '#' + p.id : '(se asigna al crear)'}" readonly>
      </div>
      <div class="form-group">
        <label class="label-with-help">
          <span>Slug *</span>
          <i class="fa-solid fa-circle-question label-help" tabindex="0"
             title="Identificador que la aplicación usa para validar los permisos a las distintas áreas del sistema. Minúsculas, números, punto, guion y guion bajo."></i>
        </label>
        <input type="text" id="pSlug" value="${v('slug')}" required
               style="font-family:var(--font-mono,monospace)"
               placeholder="ej: usuarios.editar, campanas.enviar"
               pattern="^[a-z0-9][a-z0-9._-]*$" maxlength="100">
      </div>
    </div>
    <div class="form-group">
      <label>Nombre *</label>
      <input type="text" id="pNombre" value="${v('nombre')}" required>
    </div>
    <div class="form-group">
      <label>Descripción</label>
      <input type="text" id="pDescripcion" value="${v('descripcion')}">
    </div>
    <div class="field-error" id="pError" style="display:none"></div>
  `;
}

async function guardarPermiso(id, btn) {
  const slug   = $('#pSlug').value.trim().toLowerCase();
  const nombre = $('#pNombre').value.trim();
  const err    = $('#pError');
  err.style.display = 'none';
  $('#pSlug').classList.remove('input-invalid');
  $('#pNombre').classList.remove('input-invalid');

  if (!slug) {
    $('#pSlug').classList.add('input-invalid');
    err.textContent = 'El slug es obligatorio.';
    err.style.display = '';
    return;
  }
  if (!/^[a-z0-9][a-z0-9._-]*$/.test(slug)) {
    $('#pSlug').classList.add('input-invalid');
    err.textContent = 'El slug solo admite minúsculas, números, punto, guion y guion bajo, y debe empezar con letra o número.';
    err.style.display = '';
    return;
  }
  if (!nombre) {
    $('#pNombre').classList.add('input-invalid');
    err.textContent = 'El nombre es obligatorio.';
    err.style.display = '';
    return;
  }

  const payload = {
    slug,
    nombre,
    descripcion: $('#pDescripcion').value.trim(),
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/permisos.php', 'POST', payload);
      toast('Permiso creado.');
    } else {
      await apiSend(`api/permisos.php?id=${id}`, 'PUT', payload);
      toast('Permiso actualizado.');
    }
    permisosCatalogo = null; // invalidar cache del selector de permisos en Roles
    closeModal();
    cargarPermisos();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarPermiso(id) {
  const ok = await confirmar({
    title: 'Eliminar permiso',
    message: `Se eliminará el permiso #${id}. Los roles que lo tengan asignado quedarán con la referencia colgada hasta editarlos.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/permisos.php?id=${id}`, 'DELETE');
    toast('Permiso eliminado.');
    permisosCatalogo = null;
    cargarPermisos();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: AWS (grilla de herramientas) -------------------------
route('/aws', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">AWS</div>
      <div class="page-subtitle">Herramientas y recursos de la plataforma AWS.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/awscuentas'">
        <span class="tile-icon">🔐</span>
        <span class="tile-title">Cuentas</span>
        <span class="tile-desc">Cuentas de AWS: usuario, número, credenciales de acceso (access key + secreto) y contraseña de consola.</span>
      </button>
    </div>
  `;
}, 'AWS');

// ------------------------- Vista: AWS Cuentas (ABM) -------------------------
const awsCuentasFiltrosDefaults = {
  q: '', codigo: '', nombre: '', numero: '', accesskey: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const awsCuentasFiltros = { ...awsCuentasFiltrosDefaults };
let awsCuentasBuscadorTimer  = null;
let awsCuentasFiltrosSnapshot = null;

route('/awscuentas', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a AWS" onclick="location.hash='#/aws'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div class="module-help-icon">☁️</div>
        <div class="module-help-text">
          Las cuentas AWS son los accesos a las cuentas de Amazon Web Services que usan
          las apps del grupo, con su número de cuenta, contraseña de consola y
          credenciales programáticas (access key + secreto).
        </div>
      </div>

      <div class="stats-bar" id="awsCuentasStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="awsCuentasSearch"
                   placeholder="🔍 Buscar nombre, número o access key…">
            <button class="search-clear" id="awsCuentasSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="awsCuentasFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="awsCuentasFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="awsCuentasRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="awsCuentasNuevoBtn">+ Nueva cuenta</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Número</th>
              <th>Usuario</th>
              <th style="text-align:right">Facturas</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="awsCuentasTbody">
            <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="awsCuentasCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md §Modal de filtros) -->
    <div class="modal-backdrop" id="filtrosAwsCuentasBackdrop"
         onclick="if(event.target===this)cancelarFiltrosAwsCuentas()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosAwsCuentas()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fAwsCuentasCodigo" min="1" placeholder="ID …" oninput="onFiltroAwsCuentas('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Nombre</label>
              <input type="text" id="fAwsCuentasNombre" oninput="onFiltroAwsCuentas('nombre', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Número</label>
              <input type="text" id="fAwsCuentasNumero" oninput="onFiltroAwsCuentas('numero', this.value)">
            </div>
            <div class="form-group">
              <label>Access Key</label>
              <input type="text" id="fAwsCuentasAccessKey" oninput="onFiltroAwsCuentas('accesskey', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fAwsCuentasLimite" min="1" max="1000" value="100" onchange="onFiltroAwsCuentas('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fAwsCuentasOrderBy" onchange="onFiltroAwsCuentas('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="numero">Número</option>
                <option value="accesskey">Access Key</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fAwsCuentasDir" onchange="onFiltroAwsCuentas('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosAwsCuentas()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosAwsCuentas()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosAwsCuentas()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#awsCuentasNuevoBtn').addEventListener('click', () => abrirAltaEdicionAwsCuenta(null));
  $('#awsCuentasFiltrosBtn').addEventListener('click', () => abrirModalFiltrosAwsCuentas());
  $('#awsCuentasRefrescarBtn').addEventListener('click', () => cargarAwsCuentas());

  const inp = $('#awsCuentasSearch');
  const clr = $('#awsCuentasSearchClear');
  inp.value = awsCuentasFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    awsCuentasFiltros.q = inp.value.trim();
    clearTimeout(awsCuentasBuscadorTimer);
    awsCuentasBuscadorTimer = setTimeout(() => { cargarAwsCuentas(); refrescarBadgeFiltrosAwsCuentas(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    awsCuentasFiltros.q = '';
    cargarAwsCuentas();
    refrescarBadgeFiltrosAwsCuentas();
  });

  $('#awsCuentasCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarAwsCuenta(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionAwsCuenta(data.id);
    if (b.dataset.action === 'eliminar')  eliminarAwsCuenta(data.id);
  });

  $('#awsCuentasTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#awsCuentasCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarAwsCuenta(Number(tr.dataset.id));
  });
  $('#awsCuentasTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#awsCuentasCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosAwsCuentas();
  await cargarAwsCuentas();
}, 'AWS Cuentas');

async function cargarAwsCuentas() {
  const tbody = $('#awsCuentasTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(awsCuentasFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/awscuentas.php?' + qs.toString());
    pintarStatsAwsCuentas(data.stats);
    pintarTablaAwsCuentas(data.items);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsAwsCuentas(s) {
  const cards = $$('#awsCuentasStats .stat-card .stat-value');
  if (!cards.length) return;
  cards[0].textContent = fmtNum(s.total);
}

function pintarTablaAwsCuentas(rows) {
  const tbody = $('#awsCuentasTbody');
  if (!rows || !rows.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Sin cuentas AWS.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => {
    const sinData    = r.facturas_actualizado == null;
    const cantidad   = r.facturas_cantidad != null ? Number(r.facturas_cantidad) : 0;
    const total      = r.facturas_total    != null ? Number(r.facturas_total)    : 0;
    let cellHtml;
    if (sinData) {
      cellHtml = '<span style="color:var(--muted)">—</span>';
    } else {
      // Sincronizada. Si hay deuda pero no matcheo con facturas, cantidad='?'.
      const cantTxt = (cantidad === 0 && total > 0) ? '?' : cantidad;
      const moneda  = r.facturas_moneda || 'USD';
      cellHtml = `${esc(cantTxt)} x ${esc(moneda)} ${esc(total.toFixed(2))}`;
    }
    return `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td class="td-nombre">${esc(r.nombre || '—')}</td>
      <td><code>${esc(r.numero || '—')}</code></td>
      <td><code>${esc(r.usuario || '—')}</code></td>
      <td style="text-align:right;white-space:nowrap">${cellHtml}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `;
  }).join('');
}

// ---- Modal de Filtros (AWS Cuentas) ----
function onFiltroAwsCuentas(key, value) {
  if (key === 'codigo' || key === 'nombre' || key === 'numero' || key === 'accesskey') {
    awsCuentasFiltros[key] = String(value).trim();
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    awsCuentasFiltros.limite = n;
  } else {
    awsCuentasFiltros[key] = value;
  }
  refrescarBadgeFiltrosAwsCuentas();
  cargarAwsCuentas();
}

function refrescarBadgeFiltrosAwsCuentas() {
  const btn   = $('#awsCuentasFiltrosBtn');
  const badge = $('#awsCuentasFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(awsCuentasFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(awsCuentasFiltros[k]) !== String(awsCuentasFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosAwsCuentas() {
  const f = awsCuentasFiltros;
  $('#fAwsCuentasCodigo').value    = f.codigo;
  $('#fAwsCuentasNombre').value    = f.nombre;
  $('#fAwsCuentasNumero').value    = f.numero;
  $('#fAwsCuentasAccessKey').value = f.accesskey;
  $('#fAwsCuentasLimite').value    = f.limite;
  $('#fAwsCuentasOrderBy').value   = f.order_by;
  $('#fAwsCuentasDir').value       = f.dir;
}

function abrirModalFiltrosAwsCuentas() {
  awsCuentasFiltrosSnapshot = { ...awsCuentasFiltros };
  sincronizarControlesFiltrosAwsCuentas();
  $('#filtrosAwsCuentasBackdrop').classList.add('open');
}

function cerrarModalFiltrosAwsCuentas() {
  $('#filtrosAwsCuentasBackdrop').classList.remove('open');
}

function cancelarFiltrosAwsCuentas() {
  if (awsCuentasFiltrosSnapshot) {
    Object.assign(awsCuentasFiltros, awsCuentasFiltrosSnapshot);
    refrescarBadgeFiltrosAwsCuentas();
    cargarAwsCuentas();
  }
  cerrarModalFiltrosAwsCuentas();
}

function limpiarFiltrosAwsCuentas() {
  Object.assign(awsCuentasFiltros, awsCuentasFiltrosDefaults);
  awsCuentasFiltros.q = $('#awsCuentasSearch')?.value.trim() || '';
  sincronizarControlesFiltrosAwsCuentas();
  refrescarBadgeFiltrosAwsCuentas();
  cargarAwsCuentas();
}

window.onFiltroAwsCuentas           = onFiltroAwsCuentas;
window.cancelarFiltrosAwsCuentas    = cancelarFiltrosAwsCuentas;
window.limpiarFiltrosAwsCuentas     = limpiarFiltrosAwsCuentas;
window.cerrarModalFiltrosAwsCuentas = cerrarModalFiltrosAwsCuentas;

// ---- Modal Consultar (cuenta AWS) ----
async function abrirConsultarAwsCuenta(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Consultar cuenta AWS <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))    closeModal();
    if (ev.target.closest('[data-act="editar"]'))   { closeModal(); abrirAltaEdicionAwsCuenta(id); }
    if (ev.target.closest('[data-act="facturas"]')) consultarFacturasAwsCuenta(id, ev.target.closest('[data-act="facturas"]'));

    const tabBtn = ev.target.closest('[data-tab]');
    if (tabBtn) {
      const target = tabBtn.dataset.tab;
      $$('#modalRoot .modal-tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === target));
      $$('#modalRoot .modal-tabpanel').forEach((p) => p.hidden = p.dataset.panel !== target);
    }
  });

  try {
    const r = await apiGet(`api/awscuentas.php?id=${id}`);
    const fila = (label, value, full = false, isCode = false) => {
      const empty = value == null || value === '';
      const inner = empty ? 'Sin dato' : (isCode ? `<code>${esc(value)}</code>` : esc(value));
      return `
        <div class="data-row${full ? ' full' : ''}">
          <span class="data-label">${esc(label)}</span>
          <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
        </div>
      `;
    };
    const facturasHtml = (() => {
      const cantidad = r.facturas_cantidad != null ? Number(r.facturas_cantidad) : 0;
      const total    = r.facturas_total    != null ? Number(r.facturas_total)    : 0;
      if (r.facturas_actualizado == null) {
        return '<span class="muted">—</span>';
      }
      const cantTxt = (cantidad === 0 && total > 0) ? '?' : cantidad;
      const moneda  = r.facturas_moneda || 'USD';
      return `${esc(cantTxt)} x ${esc(moneda)} ${esc(total.toFixed(2))}`;
    })();
    $('#modalRoot .modal-body').innerHTML = `
      <div class="modal-tabs">
        <button type="button" class="modal-tab active" data-tab="general">General</button>
        <button type="button" class="modal-tab"        data-tab="facturacion">Facturación</button>
      </div>

      <div class="modal-tabpanel" data-panel="general">
        <dl class="data-list">
          ${fila('Código',      '#' + r.id)}
          ${fila('Nombre',      r.nombre)}
          ${fila('Número',      r.numero, false, true)}
          ${fila('Usuario',     r.usuario, false, true)}
          ${fila('Contraseña',  r.contrasena, false, true)}
          ${fila('Access Key',  r.accesskey, true, true)}
          ${fila('Secreto',     r.secreto, true, true)}
          <div class="data-row full">
            <span class="data-label">Facturas</span>
            <span class="data-value">${facturasHtml}</span>
          </div>
        </dl>
      </div>

      <div class="modal-tabpanel" data-panel="facturacion" hidden>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px">
          <div id="awsFacturasSubtitulo" style="color:var(--muted);font-size:.85rem">
            ${r.facturas_json
              ? 'Última sincronización: ' + esc(fmtFechaCorta(r.facturas_actualizado)) + '. Actualizá para consultar de nuevo a AWS.'
              : 'Presioná «Consultar en AWS» para traer BCM (deuda) + Invoicing (facturas).'}
          </div>
          <button class="btn btn-primary btn-sm" data-act="facturas">
            ${r.facturas_json ? 'Actualizar' : 'Consultar en AWS'}
          </button>
        </div>
        <div id="awsFacturasResult"${r.facturas_json ? '' : ' class="table-empty"'}>
          ${r.facturas_json
            ? renderFacturasAwsCuenta(r.facturas_json)
            : 'Sin datos cacheados de AWS todavía.'}
        </div>
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function fmtFechaCorta(iso) {
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T'));
  if (isNaN(d)) return iso;
  const pad = (x) => String(x).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function consultarFacturasAwsCuenta(id, btn) {
  const box = $('#awsFacturasResult');
  if (!box) return;
  btn.disabled = true;
  btn.textContent = 'Consultando…';
  box.className = '';
  box.style.padding = '';
  box.innerHTML = `<div style="text-align:center;padding:24px"><div class="spin"></div></div>`;

  try {
    const r = await apiGet(`api/awscuentas_facturas.php?id=${id}`);
    box.innerHTML = renderFacturasAwsCuenta(r);
    const sub = $('#awsFacturasSubtitulo');
    if (sub) {
      const ahora = new Date();
      const pad   = (x) => String(x).padStart(2, '0');
      const stamp = `${pad(ahora.getDate())}/${pad(ahora.getMonth()+1)}/${ahora.getFullYear()} ${pad(ahora.getHours())}:${pad(ahora.getMinutes())}`;
      sub.textContent = `Última sincronización: ${stamp}. Actualizá para consultar de nuevo a AWS.`;
    }
    btn.textContent = 'Actualizar';
  } catch (e) {
    box.className = 'table-empty';
    box.style.padding = '12px';
    box.textContent = 'Error: ' + e.message;
    btn.textContent = 'Reintentar';
  } finally {
    btn.disabled = false;
  }
}

function renderFacturasAwsCuenta(r) {
  const adeudadas = new Set();
  (r.match?.matches || []).forEach((m) => (m.invoice_ids || []).forEach((id) => adeudadas.add(id)));
  return renderPaymentsAwsCuenta(r.payments, r.match)
       + renderInvoicingAwsCuenta(r.invoicing, adeudadas);
}

function renderPaymentsAwsCuenta(p, match) {
  const titulo = `<div style="font-weight:600;margin:4px 0 6px;color:var(--text)">💳 Deuda actual (AWS Billing Recommended Actions)</div>`;
  if (!p || !p.ok) {
    return titulo + `<div class="table-empty">AWS BCM no respondió: ${esc(p?.error || 'desconocido')}</div>`;
  }
  if (!p.actions.length) {
    return titulo + `<div class="table-empty" style="background:rgba(34,197,94,.10);color:var(--success)">
      ✅ Sin acciones de pago pendientes. AWS no reporta deuda vencida ni por vencer.
    </div>`;
  }
  const filas = p.actions.map((a) => {
    const critico = a.type === 'PAYMENTS_PAST_DUE' || a.severity === 'CRITICAL' || a.severity === 'HIGH';
    const monto = a.amount != null
      ? `${esc(a.amount)}${a.currency ? ' ' + esc(a.currency) : ''}`
      : '—';
    return `
      <tr${critico ? ' style="background:rgba(230,42,42,.12)"' : ''}>
        <td>
          <div style="font-family:monospace;font-size:.85rem">${esc(a.type || '—')}</div>
          ${critico ? '<span class="badge badge-danger" style="font-size:.7rem">crítico</span>' : ''}
        </td>
        <td>${esc(a.severity || '—')}</td>
        <td style="text-align:right;white-space:nowrap;font-weight:600">${monto}</td>
        <td style="font-size:.85rem">${esc(a.next_steps || '—')}</td>
      </tr>
    `;
  }).join('');
  const notaMatch = (match?.matches?.length)
    ? `<div style="font-size:.8rem;color:var(--success);margin-top:6px">
         ✓ La deuda coincide exactamente con ${match.matches[0].invoice_ids.length} factura${match.matches[0].invoice_ids.length === 1 ? '' : 's'} emitida${match.matches[0].invoice_ids.length === 1 ? '' : 's'} — marcadas abajo.
       </div>`
    : (p.actions.length
        ? `<div style="font-size:.8rem;color:var(--muted);margin-top:6px">
             Sin match exacto contra facturas emitidas: podría ser saldo parcial de una factura mayor.
           </div>`
        : '');
  return titulo + `
    <div class="table-card" style="margin-bottom:8px">
      <table>
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Severidad</th>
            <th style="text-align:right">Monto</th>
            <th>Acción sugerida</th>
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>
    </div>
    ${notaMatch}
    <div style="height:16px"></div>
  `;
}

function renderInvoicingAwsCuenta(inv, adeudadas) {
  const titulo = `<div style="font-weight:600;margin:4px 0 6px;color:var(--text)">🧾 Facturas emitidas (AWS Invoicing)</div>`;
  if (!inv || !inv.ok) {
    return titulo + `<div class="table-empty">AWS Invoicing no respondió: ${esc(inv?.error || 'desconocido')}</div>`;
  }
  if (!inv.invoices || !inv.invoices.length) {
    return titulo + `<div class="table-empty">
      Sin facturas emitidas en el rango ${esc(inv.range.start)} — ${esc(inv.range.end)}.
    </div>`;
  }
  const fmt = (iso) => {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  };
  const filas = inv.invoices.map((f) => {
    const adeudada = adeudadas && adeudadas.has(f.invoice_id);
    const monto = f.total != null
      ? `${esc(f.total)}${f.currency ? ' ' + esc(f.currency) : ''}`
      : '—';
    return `
      <tr${adeudada ? ' style="background:rgba(230,42,42,.15)"' : ''}>
        <td>
          <code>${esc(f.invoice_id || '—')}</code>
          ${adeudada ? ' <span class="badge badge-danger" style="font-size:.7rem">adeudada</span>' : ''}
        </td>
        <td>${esc(fmt(f.issued_date))}</td>
        <td>${esc(fmt(f.due_date))}</td>
        <td>${esc(f.invoice_type || '—')}</td>
        <td style="text-align:right;white-space:nowrap;${adeudada ? 'font-weight:600' : ''}">${monto}</td>
      </tr>
    `;
  }).join('');
  return titulo + `
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:6px">
      ${inv.count} factura${inv.count === 1 ? '' : 's'} entre ${esc(inv.range.start)} y ${esc(inv.range.end)}.
    </div>
    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th>Factura</th>
            <th>Emisión</th>
            <th>Vencimiento</th>
            <th>Tipo</th>
            <th style="text-align:right">Total</th>
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>
    </div>
  `;
}

// ---- Modal Alta / Edición (cuenta AWS) ----
async function abrirAltaEdicionAwsCuenta(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar cuenta AWS <span class="modal-subtitle">#${id}</span>` : 'Nueva cuenta AWS'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:40px"><div class="spin"></div></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  try {
    const r = esEdicion ? await apiGet(`api/awscuentas.php?id=${id}`) : {};
    $('#modalRoot .modal-body').innerHTML = formAwsCuentaHtml(r);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarAwsCuenta(id, a);
  });
}

function formAwsCuentaHtml(r) {
  const v = (k) => esc(r?.[k] ?? '');
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre *</label>
        <input type="text" id="awsNombre" value="${v('nombre')}" required>
      </div>
      <div class="form-group">
        <label>Código</label>
        <input type="text" value="${r?.id ? '#' + r.id : '(se asigna al crear)'}" readonly>
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Número</label>
        <input type="text" id="awsNumero" value="${v('numero')}" maxlength="20" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Usuario</label>
        <input type="text" id="awsUsuario" value="${v('usuario')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="text" id="awsContrasena" value="${v('contrasena')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Access Key</label>
      <input type="text" id="awsAccessKey" value="${v('accesskey')}" style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>Secreto</label>
      <input type="text" id="awsSecreto" value="${v('secreto')}" style="font-family:monospace">
    </div>
    <div class="field-error" id="awsError" style="display:none"></div>
  `;
}

async function guardarAwsCuenta(id, btn) {
  const nombre = $('#awsNombre').value.trim();
  const err    = $('#awsError');
  err.style.display = 'none';
  $('#awsNombre').classList.remove('input-invalid');

  if (!nombre) {
    $('#awsNombre').classList.add('input-invalid');
    err.textContent = 'El nombre es obligatorio.';
    err.style.display = '';
    return;
  }

  const payload = {
    nombre,
    numero:     $('#awsNumero').value.trim(),
    usuario:    $('#awsUsuario').value.trim(),
    contrasena: $('#awsContrasena').value.trim(),
    accesskey:  $('#awsAccessKey').value.trim(),
    secreto:    $('#awsSecreto').value.trim(),
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/awscuentas.php', 'POST', payload);
      toast('Cuenta AWS creada.');
    } else {
      await apiSend(`api/awscuentas.php?id=${id}`, 'PUT', payload);
      toast('Cuenta AWS actualizada.');
    }
    closeModal();
    cargarAwsCuentas();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarAwsCuenta(id) {
  const ok = await confirmar({
    title: 'Eliminar cuenta AWS',
    message: `Se eliminará la cuenta AWS #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/awscuentas.php?id=${id}`, 'DELETE');
    toast('Cuenta AWS eliminada.');
    cargarAwsCuentas();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Herramientas -------------------------
route('/herramientas', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Herramientas</div>
      <div class="page-subtitle">Utilidades para distintas áreas de la plataforma.</div>
    </div>

    <div class="tile-grid" id="toolsGrid">
      <!-- Tarjetas ordenadas alfabéticamente por <span class="tile-title">.
           Al agregar, renombrar o reordenar cualquier herramienta, mantener
           el orden alfabético estricto por título visible. -->
      <button type="button" class="tile-card" onclick="abrirEstados()">
        <span class="tile-icon">🎚️</span>
        <span class="tile-title">Editor de estados</span>
        <span class="tile-desc">Catálogo de valores posibles (<code>campo</code> / <code>valor</code> / <code>texto</code>) para columnas de estado de las distintas tablas.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirParametros()">
        <span class="tile-icon">🧩</span>
        <span class="tile-title">Editor de parámetros</span>
        <span class="tile-desc">Variables runtime (variable / valor) que el resto del sistema lee para configurarse sin redeploy.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirExploradorDB()">
        <span class="tile-icon">🗄️</span>
        <span class="tile-title">Explorador DB</span>
        <span class="tile-desc">Recorrá las tablas de la base del entorno actual, ojeá su estructura y los últimos registros.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirExploradorS3()">
        <span class="tile-icon">📁</span>
        <span class="tile-title">Explorador S3</span>
        <span class="tile-desc">Navegá, subí, descargá y eliminá carpetas y archivos del bucket de media del entorno actual.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirMigraciones()">
        <span class="tile-icon">📜</span>
        <span class="tile-title">Migrador DB</span>
        <span class="tile-desc">Aplicá las migraciones pendientes de <code>cloud/sql/migrations/</code> contra la BD del entorno actual.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirTareas()">
        <span class="tile-icon">⏰</span>
        <span class="tile-title">Programador de tareas</span>
        <span class="tile-desc">Administrá los procesos automáticos (tabla <code>tareas</code>) que el scheduler dispara cada minuto, y revisá el historial y el log en vivo de cada ejecución.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirSincronizador()">
        <span class="tile-icon">🔄</span>
        <span class="tile-title">Sincronizador de tablas</span>
        <span class="tile-desc">Copiá una tabla entera entre desarrollo y producción preservando los IDs. Solo disponible en el panel de dev.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirVisorSucesos()">
        <span class="tile-icon">📰</span>
        <span class="tile-title">Visor de sucesos</span>
        <span class="tile-desc">Recorré el log de actividad (tabla <code>sucesos</code>) que los distintos módulos van registrando al trabajar.</span>
      </button>
    </div>
  `;
}, 'Herramientas');

// ------------------------- Herramientas: Explorador S3 -------------------------
let s3ExpPrefix      = '';
let s3ExpNextToken   = null;
let s3ExpBucket      = '';
let s3ExpCargando    = false;
let s3ExpCtxKey      = null;
let s3ExpCtxIsFolder = false;
let s3ExpCtxUrl      = '';
let s3ExpUltimaLista = { folders: [], objects: [] };

function abrirExploradorS3() {
  s3ExpPrefix    = '';
  s3ExpNextToken = null;
  s3ExpBucket    = '';
  document.getElementById('s3ExpBucket').textContent = '—';
  document.getElementById('s3ExpModalBackdrop').classList.add('open');
  s3ExpCargar(true);
}

function cerrarExploradorS3() {
  document.getElementById('s3ExpModalBackdrop').classList.remove('open');
  s3ExpCerrarCtx();
}

function s3ExpRecargar() { s3ExpCargar(true); }

function s3ExpNavegar(prefix) {
  s3ExpPrefix = prefix || '';
  s3ExpCargar(true);
}

async function s3ExpCargar(reiniciar) {
  if (s3ExpCargando) return;
  s3ExpCargando = true;

  const tbody  = document.getElementById('s3ExpTbody');
  const btnMas = document.getElementById('s3ExpBtnMas');
  if (reiniciar) {
    s3ExpNextToken = null;
    s3ExpUltimaLista = { folders: [], objects: [] };
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>';
    btnMas.style.display = 'none';
  } else {
    btnMas.disabled = true;
    btnMas.textContent = 'Cargando…';
  }

  s3ExpRenderBreadcrumbs(s3ExpPrefix);

  const params = new URLSearchParams();
  if (s3ExpPrefix) params.set('prefix', s3ExpPrefix);
  if (!reiniciar && s3ExpNextToken) params.set('token', s3ExpNextToken);

  try {
    const res  = await fetch('api/herramientas_s3_list.php?' + params.toString(), { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.ok) {
      tbody.innerHTML = '<tr><td colspan="5" class="s3-exp-empty">✗ ' + esc(data.error || 'Error al listar') + '</td></tr>';
      s3ExpCargando = false;
      return;
    }

    s3ExpBucket    = data.bucket;
    s3ExpNextToken = data.next_token;
    document.getElementById('s3ExpBucket').textContent = data.bucket;
    s3ExpRenderBreadcrumbs(data.prefix);

    if (reiniciar) {
      s3ExpUltimaLista = { folders: [], objects: [] };
    }
    s3ExpUltimaLista.folders = s3ExpUltimaLista.folders.concat(data.folders || []);
    s3ExpUltimaLista.objects = s3ExpUltimaLista.objects.concat(data.objects || []);

    s3ExpRenderTabla(data.prefix);

    if (data.truncated && s3ExpNextToken) {
      btnMas.style.display = '';
      btnMas.disabled = false;
      btnMas.textContent = 'Cargar más';
    } else {
      btnMas.style.display = 'none';
    }
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="5" class="s3-exp-empty">✗ Error de conexión</td></tr>';
  } finally {
    s3ExpCargando = false;
  }
}

function s3ExpRenderTabla(prefix) {
  const tbody = document.getElementById('s3ExpTbody');
  const info  = document.getElementById('s3ExpFooterInfo');
  const folders = s3ExpUltimaLista.folders;
  const objects = s3ExpUltimaLista.objects;

  let html = '';

  // Fila "..": volver a la carpeta padre (solo si no estamos en la raíz).
  if (prefix) {
    const parts = prefix.replace(/\/$/, '').split('/');
    parts.pop();
    const parent = parts.length ? parts.join('/') + '/' : '';
    html +=
      '<tr class="row-clickable" onclick="s3ExpNavegar(\'' + esc(parent) + '\')">'
      + '<td><i class="fa-solid fa-turn-up" style="color:var(--muted);transform:rotate(-90deg)"></i></td>'
      + '<td><div class="s3-exp-nombre">..</div></td>'
      + '<td class="s3-exp-size">—</td>'
      + '<td class="s3-exp-date">—</td>'
      + '<td></td>'
      + '</tr>';
  }

  folders.forEach((folder) => {
    const nombre = folder.substring(prefix.length).replace(/\/$/, '');
    html +=
      '<tr class="row-clickable"'
      + ' onclick="s3ExpNavegar(\'' + esc(folder) + '\')"'
      + ' oncontextmenu="event.preventDefault(); s3ExpAbrirCtx(event, \'' + esc(folder) + '\', true, \'\')">'
      + '<td><i class="fa-solid fa-folder" style="color:var(--warn)"></i></td>'
      + '<td><div class="s3-exp-nombre">' + esc(nombre) + '/</div></td>'
      + '<td class="s3-exp-size">—</td>'
      + '<td class="s3-exp-date">—</td>'
      + '<td style="text-align:center">'
      + '<button class="btn-icon-sm" title="Más acciones"'
      + ' onclick="event.stopPropagation(); s3ExpAbrirCtx(event, \'' + esc(folder) + '\', true, \'\')">'
      + '<i class="fa-solid fa-bars"></i></button>'
      + '</td>'
      + '</tr>';
  });

  objects.forEach((obj) => {
    const nombre = obj.key.substring(prefix.length);
    const fecha  = obj.last_modified ? s3ExpFormatFecha(obj.last_modified) : '';
    const url    = obj.url || '';
    const icono  = s3ExpEsImagen(nombre)
      ? '<img class="s3-exp-thumb" loading="lazy" src="' + esc(url) + '" alt="" onerror="this.outerHTML=\'<i class=&quot;fa-solid fa-file-image&quot; style=&quot;color:var(--info)&quot;></i>\'">'
      : '<i class="fa-solid ' + s3ExpIconoArchivo(nombre) + '" style="color:var(--info)"></i>';
    html +=
      '<tr class="row-clickable"'
      + ' onclick="s3ExpAbrirArchivo(\'' + esc(url) + '\')"'
      + ' oncontextmenu="event.preventDefault(); s3ExpAbrirCtx(event, \'' + esc(obj.key) + '\', false, \'' + esc(url) + '\')">'
      + '<td>' + icono + '</td>'
      + '<td><div class="s3-exp-nombre">' + esc(nombre) + '</div></td>'
      + '<td class="s3-exp-size">' + s3ExpFormatSize(obj.size) + '</td>'
      + '<td class="s3-exp-date">' + esc(fecha) + '</td>'
      + '<td style="text-align:center">'
      + '<button class="btn-icon-sm" title="Más acciones"'
      + ' onclick="event.stopPropagation(); s3ExpAbrirCtx(event, \'' + esc(obj.key) + '\', false, \'' + esc(url) + '\')">'
      + '<i class="fa-solid fa-bars"></i></button>'
      + '</td>'
      + '</tr>';
  });

  if (html === '') {
    tbody.innerHTML = '<tr><td colspan="5" class="s3-exp-empty">Esta carpeta está vacía.</td></tr>';
    info.textContent = '0 elementos';
  } else {
    tbody.innerHTML = html;
    const totalSize = objects.reduce((a, f) => a + (f.size || 0), 0);
    info.innerHTML =
      '<span>' + folders.length + ' carpetas · ' + objects.length + ' archivos · ' + s3ExpFormatSize(totalSize) + ' en esta carpeta</span>';
  }
}

function s3ExpCargarMas() { s3ExpCargar(false); }

function s3ExpRenderBreadcrumbs(prefix) {
  const cont = document.getElementById('s3ExpBreadcrumbs');
  const partes = (prefix || '').split('/').filter(Boolean);
  let html = '<button class="s3-exp-crumb" onclick="s3ExpNavegar(\'\')"><i class="fa-solid fa-house"></i> raíz</button>';
  let acum = '';
  for (let i = 0; i < partes.length; i++) {
    acum += partes[i] + '/';
    const isLast = (i === partes.length - 1);
    html += '<span class="s3-exp-crumb-sep">/</span>';
    if (isLast) {
      html += '<span class="s3-exp-crumb current">' + esc(partes[i]) + '</span>';
    } else {
      html += '<button class="s3-exp-crumb" onclick="s3ExpNavegar(\'' + esc(acum) + '\')">' + esc(partes[i]) + '</button>';
    }
  }
  cont.innerHTML = html;
}

function s3ExpEsImagen(nombre) {
  const ext = (nombre.split('.').pop() || '').toLowerCase();
  return ['jpg','jpeg','png','gif','webp','bmp','svg','avif'].indexOf(ext) >= 0;
}

function s3ExpIconoArchivo(nombre) {
  const ext = (nombre.split('.').pop() || '').toLowerCase();
  if (['jpg','jpeg','png','gif','webp','bmp','svg','avif','ico'].indexOf(ext) >= 0) return 'fa-file-image';
  if (['mp4','mov','avi','mkv','webm'].indexOf(ext) >= 0)                            return 'fa-file-video';
  if (['mp3','wav','ogg','flac','m4a'].indexOf(ext) >= 0)                            return 'fa-file-audio';
  if (['pdf'].indexOf(ext) >= 0)                                                     return 'fa-file-pdf';
  if (['zip','rar','7z','tar','gz'].indexOf(ext) >= 0)                               return 'fa-file-zipper';
  if (['doc','docx'].indexOf(ext) >= 0)                                              return 'fa-file-word';
  if (['xls','xlsx','csv'].indexOf(ext) >= 0)                                        return 'fa-file-excel';
  if (['txt','md','log'].indexOf(ext) >= 0)                                          return 'fa-file-lines';
  if (['js','php','html','css','json','xml','sql'].indexOf(ext) >= 0)                return 'fa-file-code';
  return 'fa-file';
}

function s3ExpFormatFecha(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  const pad = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
       + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function s3ExpFormatSize(bytes) {
  if (bytes == null || isNaN(bytes)) return '—';
  if (bytes < 1024) return bytes + ' B';
  const kb = bytes / 1024;
  if (kb < 1024) return kb.toFixed(1) + ' KB';
  const mb = kb / 1024;
  if (mb < 1024) return mb.toFixed(1) + ' MB';
  return (mb / 1024).toFixed(2) + ' GB';
}

function s3ExpAbrirArchivo(url) {
  if (!url) return;
  window.open(url, '_blank', 'noopener');
}

async function s3ExpSubirArchivo(fileList) {
  if (!fileList || !fileList.length) return;
  const file  = fileList[0];
  const input = document.getElementById('s3ExpUploadInput');
  const fd = new FormData();
  fd.append('archivo', file);
  fd.append('prefix', s3ExpPrefix);
  fd.append('nombre', file.name);
  toast('Subiendo ' + file.name + '…');
  try {
    const r = await fetch('api/herramientas_s3_upload.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    });
    const data = await r.json();
    if (!data.ok) {
      toast(data.error || 'Error al subir', { error: true });
      input.value = '';
      return;
    }
    toast('Archivo subido');
    input.value = '';
    s3ExpCargar(true);
  } catch (e) {
    toast('Error de red al subir', { error: true });
    input.value = '';
  }
}

async function s3ExpCrearCarpeta() {
  const nombre = prompt('Nombre de la nueva carpeta:');
  if (!nombre) return;
  try {
    const r = await fetch('api/herramientas_s3_create_folder.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prefix: s3ExpPrefix, nombre }),
    });
    const data = await r.json();
    if (!data.ok) { toast(data.error || 'Error al crear carpeta', { error: true }); return; }
    toast('Carpeta creada');
    s3ExpCargar(true);
  } catch (e) {
    toast('Error de red al crear carpeta', { error: true });
  }
}

async function s3ExpEliminar(key, esCarpeta) {
  const tipo = esCarpeta ? 'carpeta' : 'archivo';
  const msg  = esCarpeta
    ? `Vas a eliminar la carpeta "${key}" y TODO su contenido de forma recursiva. Esta acción no se puede deshacer.`
    : `Vas a eliminar "${key}". Esta acción no se puede deshacer.`;
  const ok = await confirmar({
    title: 'Eliminar ' + tipo,
    message: msg,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    const r = await fetch('api/herramientas_s3_delete.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key, recursivo: !!esCarpeta }),
    });
    const data = await r.json();
    if (!data.ok) { toast(data.error || 'Error al eliminar', { error: true }); return; }
    const txt = esCarpeta
      ? `Carpeta eliminada (${data.eliminados || 0} objetos)`
      : 'Archivo eliminado';
    toast(txt);
    s3ExpCargar(true);
  } catch (e) {
    toast('Error de red al eliminar', { error: true });
  }
}

function s3ExpAbrirCtx(ev, key, esCarpeta, url) {
  ev.preventDefault();
  ev.stopPropagation();
  s3ExpCtxKey      = key;
  s3ExpCtxIsFolder = !!esCarpeta;
  s3ExpCtxUrl      = url || '';
  const menu = document.getElementById('s3ExpCtxMenu');
  menu.querySelector('[data-action="abrir"]').style.display      = esCarpeta ? 'none' : '';
  menu.querySelector('[data-action="copiar-url"]').style.display = esCarpeta ? 'none' : '';
  const x = ev.clientX || (ev.currentTarget && ev.currentTarget.getBoundingClientRect().left) || 0;
  const y = ev.clientY || (ev.currentTarget && ev.currentTarget.getBoundingClientRect().bottom) || 0;
  menu.style.left = Math.min(x, window.innerWidth - 220) + 'px';
  menu.style.top  = Math.min(y, window.innerHeight - 180) + 'px';
  menu.classList.add('open');
}

function s3ExpCerrarCtx() {
  const menu = document.getElementById('s3ExpCtxMenu');
  if (menu) menu.classList.remove('open');
  s3ExpCtxKey = null;
  s3ExpCtxUrl = '';
}

async function s3ExpCopiarUrlPublica(url) {
  if (!url) { toast('Sin URL disponible', { error: true }); return; }
  try {
    await navigator.clipboard.writeText(url);
    toast('URL copiada al portapapeles');
  } catch (e) {
    prompt('URL del archivo:', url);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('s3ExpCtxMenu');
  if (!menu) return;
  menu.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action   = btn.getAttribute('data-action');
    const key      = s3ExpCtxKey;
    const isFolder = s3ExpCtxIsFolder;
    const url      = s3ExpCtxUrl;
    s3ExpCerrarCtx();
    if (!key) return;
    if      (action === 'abrir')       s3ExpAbrirArchivo(url);
    else if (action === 'copiar-url')  s3ExpCopiarUrlPublica(url);
    else if (action === 'eliminar')    s3ExpEliminar(key, isFolder);
  });
});
document.addEventListener('click', (e) => {
  const menu = document.getElementById('s3ExpCtxMenu');
  if (menu && menu.classList.contains('open') && !menu.contains(e.target)) s3ExpCerrarCtx();
});
document.addEventListener('scroll', s3ExpCerrarCtx, true);
window.addEventListener('resize',   s3ExpCerrarCtx);
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const ctx = document.getElementById('s3ExpCtxMenu');
  if (ctx && ctx.classList.contains('open')) { s3ExpCerrarCtx(); return; }
  const back = document.getElementById('s3ExpModalBackdrop');
  if (back && back.classList.contains('open')) cerrarExploradorS3();
});

// ------------------------- Herramientas: Explorador DB -------------------------
let dbExpTablas       = [];        // listado completo
let dbExpFiltro       = '';        // filtro del buscador (vista de tablas)
let dbExpTablaActual  = null;      // nombre de la tabla abierta (vista detalle) o null
let dbExpDbName       = '';
let dbExpEnv          = '';
let dbExpCargando     = false;
let dbExpRegistros    = [];        // último set de filas cargadas
let dbExpPkCols       = [];        // columnas PK de la tabla abierta
let dbExpAutoIncCols  = [];        // columnas auto_increment
let dbExpNullableCols = [];        // columnas que permiten NULL
let dbExpColsTabla    = [];        // orden de columnas de la tabla abierta
let dbExpRegsTotal    = 0;         // total real (COUNT(*))
let dbExpLimite       = 50;        // cuántos registros pedir al backend
let dbExpFiltroRegs   = '';        // filtro client-side de la pestaña Registros

function abrirExploradorDB() {
  dbExpTablas      = [];
  dbExpFiltro      = '';
  dbExpTablaActual = null;
  document.getElementById('dbExpModalBackdrop').classList.add('open');
  dbExpMostrarVista('tables');
  dbExpCargarTablas();
}

function cerrarExploradorDB() {
  document.getElementById('dbExpModalBackdrop').classList.remove('open');
}

function dbExpRecargar() {
  if (dbExpTablaActual) dbExpAbrirTabla(dbExpTablaActual);
  else                  dbExpCargarTablas();
}

function dbExpMostrarVista(vista) {
  const vT = document.getElementById('dbExpViewTables');
  const vD = document.getElementById('dbExpViewDetail');
  const buscador = document.getElementById('dbExpSearchWrap');
  if (vista === 'tables') {
    vT.style.display = '';
    vD.style.display = 'none';
    buscador.style.display = '';
  } else {
    vT.style.display = 'none';
    vD.style.display = '';
    buscador.style.display = 'none';
  }
  dbExpRenderBreadcrumbs();
}

function dbExpRenderBreadcrumbs() {
  const cont = document.getElementById('dbExpBreadcrumbs');
  let html = '<button class="db-exp-crumb" onclick="dbExpVolverATablas()">'
           + '<i class="fa-solid fa-database"></i> ' + esc(dbExpDbName || 'base de datos') + '</button>';
  if (dbExpTablaActual) {
    html += '<span class="db-exp-crumb-sep">/</span>'
          + '<span class="db-exp-crumb current">' + esc(dbExpTablaActual) + '</span>';
  }
  cont.innerHTML = html;
}

function dbExpVolverATablas() {
  dbExpTablaActual = null;
  dbExpMostrarVista('tables');
  dbExpRenderTablas();
}

async function dbExpCargarTablas() {
  if (dbExpCargando) return;
  dbExpCargando = true;
  const tbody = document.getElementById('dbExpTablesTbody');
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>';
  try {
    const data = await apiGet('api/herramientas_db_tables.php');
    dbExpTablas = data.tablas || [];
    dbExpDbName = data.database || '';
    dbExpEnv    = data.env || '';
    document.getElementById('dbExpDbName').textContent = dbExpDbName || '—';
    const envBadge = document.getElementById('dbExpEnvBadge');
    envBadge.textContent = dbExpEnv;
    envBadge.className = 'badge ' + (dbExpEnv === 'production' ? 'badge-danger' : 'badge-success');
    dbExpRenderBreadcrumbs();
    dbExpRenderTablas();
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="4" class="db-exp-empty">✗ ' + esc(e.message) + '</td></tr>';
  } finally {
    dbExpCargando = false;
  }
}

function dbExpRenderTablas() {
  const tbody = document.getElementById('dbExpTablesTbody');
  const info  = document.getElementById('dbExpTablesInfo');
  const q     = (dbExpFiltro || '').toLowerCase();
  const lista = q
    ? dbExpTablas.filter((t) => (t.nombre || '').toLowerCase().includes(q))
    : dbExpTablas;
  if (!lista.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="db-exp-empty">'
                    + (dbExpTablas.length ? 'Sin resultados para el filtro.' : 'No hay tablas en esta base.')
                    + '</td></tr>';
    info.textContent = '';
    return;
  }
  let html = '';
  lista.forEach((t) => {
    const nombre = t.nombre || '';
    const filas  = (t.filas_aprox == null) ? '—' : fmtNum(t.filas_aprox);
    const engine = t.engine || '—';
    html +=
      '<tr class="row-clickable" onclick="dbExpAbrirTabla(\'' + esc(nombre) + '\')">'
      + '<td><i class="fa-solid fa-table" style="color:var(--info)"></i></td>'
      + '<td><div class="db-exp-nombre">' + esc(nombre) + '</div>'
      + (t.comentario ? '<div class="db-exp-coment">' + esc(t.comentario) + '</div>' : '')
      + '</td>'
      + '<td class="db-exp-num">' + filas + '</td>'
      + '<td class="db-exp-mono">' + esc(engine) + '</td>'
      + '</tr>';
  });
  tbody.innerHTML = html;
  info.textContent = lista.length + ' tabla' + (lista.length === 1 ? '' : 's')
                   + (q ? ' (filtradas de ' + dbExpTablas.length + ')' : '');
}

function dbExpFiltrarTablas() {
  dbExpFiltro = document.getElementById('dbExpSearch').value || '';
  dbExpRenderTablas();
}

function dbExpLimpiarBuscador() {
  const inp = document.getElementById('dbExpSearch');
  inp.value = '';
  dbExpFiltro = '';
  dbExpRenderTablas();
  inp.focus();
}

async function dbExpAbrirTabla(nombre) {
  dbExpTablaActual = nombre;
  dbExpMostrarVista('detail');
  dbExpCambiarTab('recs');
  // Reset del filtro de registros al cambiar de tabla (el límite se mantiene).
  dbExpFiltroRegs = '';
  const inpSearch = document.getElementById('dbExpRecsSearch');
  if (inpSearch) inpSearch.value = '';

  const colsTbody = document.getElementById('dbExpColsTbody');
  document.getElementById('dbExpColsMeta').textContent = '';
  colsTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>';

  const [colsRes] = await Promise.allSettled([
    apiGet('api/herramientas_db_describe.php?tabla=' + encodeURIComponent(nombre)),
    dbExpCargarRegistros(nombre),
  ]);

  if (colsRes.status === 'fulfilled') {
    dbExpRenderColumnas(colsRes.value.columnas || []);
  } else {
    colsTbody.innerHTML = '<tr><td colspan="7" class="db-exp-empty">✗ ' + esc(colsRes.reason.message) + '</td></tr>';
  }
}

async function dbExpCargarRegistros(nombre) {
  const recsTable = document.getElementById('dbExpRecsTable');
  const recsTbody = document.getElementById('dbExpRecsTbody');
  document.getElementById('dbExpRecsMeta').textContent = '';
  recsTable.querySelector('thead').innerHTML = '<tr><th></th></tr>';
  recsTbody.innerHTML = '<tr><td style="text-align:center;padding:24px"><div class="spin"></div></td></tr>';
  try {
    const data = await apiGet('api/herramientas_db_records.php?tabla='
      + encodeURIComponent(nombre) + '&limite=' + dbExpLimite);
    dbExpRenderRegistros(data);
  } catch (e) {
    recsTbody.innerHTML = '<tr><td class="db-exp-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function dbExpCambiarLimite() {
  const sel = document.getElementById('dbExpLimite');
  dbExpLimite = parseInt(sel.value, 10) || 50;
  if (dbExpTablaActual) dbExpCargarRegistros(dbExpTablaActual);
}

function dbExpFiltrarRegistros() {
  dbExpFiltroRegs = document.getElementById('dbExpRecsSearch').value || '';
  dbExpPintarRegistros();
}

function dbExpLimpiarBuscadorRegs() {
  const inp = document.getElementById('dbExpRecsSearch');
  inp.value = '';
  dbExpFiltroRegs = '';
  dbExpPintarRegistros();
  inp.focus();
}

function dbExpCambiarTab(tab) {
  $$('.db-exp-tab').forEach((b) => {
    b.classList.toggle('active', b.getAttribute('data-tab') === tab);
  });
  const cols = document.getElementById('dbExpTabCols');
  const recs = document.getElementById('dbExpTabRecs');
  cols.hidden = (tab !== 'cols');
  recs.hidden = (tab !== 'recs');
}

function dbExpRenderColumnas(columnas) {
  const tbody = document.getElementById('dbExpColsTbody');
  const meta  = document.getElementById('dbExpColsMeta');
  if (!columnas.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="db-exp-empty">Sin columnas.</td></tr>';
    meta.textContent = '';
    return;
  }
  let html = '';
  columnas.forEach((c) => {
    const claveBadge = dbExpClaveBadge(c.clave);
    const nullable   = c.nullable === 'YES'
      ? '<span class="badge badge-warn">YES</span>'
      : '<span class="badge" style="background:rgba(255,255,255,.06);color:var(--muted)">NO</span>';
    const def = (c.predeterminado === null || c.predeterminado === undefined)
      ? '<span class="db-exp-null">NULL</span>'
      : '<code>' + esc(c.predeterminado) + '</code>';
    const extra = c.extra ? '<code>' + esc(c.extra) + '</code>' : '';
    html +=
      '<tr>'
      + '<td class="db-exp-num">' + esc(c.posicion) + '</td>'
      + '<td><div class="db-exp-col-nombre">' + esc(c.nombre) + '</div>'
      + (c.comentario ? '<div class="db-exp-coment">' + esc(c.comentario) + '</div>' : '')
      + '</td>'
      + '<td class="db-exp-mono">' + esc(c.tipo) + '</td>'
      + '<td>' + nullable + '</td>'
      + '<td>' + claveBadge + '</td>'
      + '<td class="db-exp-mono">' + def + '</td>'
      + '<td class="db-exp-mono">' + extra + '</td>'
      + '</tr>';
  });
  tbody.innerHTML = html;
  meta.textContent = String(columnas.length);
}

function dbExpClaveBadge(k) {
  if (k === 'PRI') return '<span class="badge badge-warn" title="Primary key">PK</span>';
  if (k === 'UNI') return '<span class="badge badge-info" title="Unique">UQ</span>';
  if (k === 'MUL') return '<span class="badge" style="background:rgba(139,92,246,.18);color:#c4b5fd" title="Index">IDX</span>';
  return '';
}

function dbExpRenderRegistros(payload) {
  dbExpRegistros    = payload.registros || [];
  dbExpPkCols       = payload.pk || [];
  dbExpAutoIncCols  = payload.auto_inc || [];
  dbExpNullableCols = payload.nullable || [];
  dbExpColsTabla    = payload.columnas || [];
  dbExpRegsTotal    = payload.total || 0;

  const thead = document.getElementById('dbExpRecsTable').querySelector('thead');
  if (!dbExpColsTabla.length) {
    thead.innerHTML = '<tr><th></th></tr>';
  } else {
    thead.innerHTML = '<tr>' + dbExpColsTabla.map((c) => {
      const tag = dbExpPkCols.includes(c)
        ? ' <i class="fa-solid fa-key" title="Primary key" style="color:var(--warn);font-size:.7rem"></i>'
        : '';
      return '<th>' + esc(c) + tag + '</th>';
    }).join('') + '</tr>';
  }
  dbExpPintarRegistros();
}

function dbExpPintarRegistros() {
  const tbody = document.getElementById('dbExpRecsTbody');
  const meta  = document.getElementById('dbExpRecsMeta');
  const cols  = dbExpColsTabla;
  const pk    = dbExpPkCols;
  const ai    = dbExpAutoIncCols;

  if (!cols.length) {
    tbody.innerHTML = '<tr><td class="db-exp-empty">Sin columnas.</td></tr>';
    meta.textContent = '';
    return;
  }

  // Filtro client-side: match case-insensitive contra cualquier columna.
  const q = (dbExpFiltroRegs || '').trim().toLowerCase();
  const visible = [];
  dbExpRegistros.forEach((r, idx) => {
    if (!q) { visible.push(idx); return; }
    for (const c of cols) {
      const v = r[c];
      if (v == null) continue;
      if (String(v).toLowerCase().includes(q)) { visible.push(idx); return; }
    }
  });

  if (!dbExpRegistros.length) {
    tbody.innerHTML = '<tr><td colspan="' + cols.length + '" class="db-exp-empty">Esta tabla está vacía.</td></tr>';
  } else if (!visible.length) {
    tbody.innerHTML = '<tr><td colspan="' + cols.length + '" class="db-exp-empty">Sin resultados para "' + esc(dbExpFiltroRegs) + '".</td></tr>';
  } else {
    const editable = pk.length > 0;
    let html = '';
    visible.forEach((rowIdx) => {
      const r = dbExpRegistros[rowIdx];
      html += '<tr data-row="' + rowIdx + '">' + cols.map((c) => {
        const esPk = pk.includes(c);
        const esAi = ai.includes(c);
        const lock = esPk || esAi || !editable;
        const cls  = lock ? 'db-exp-cell-lock' : 'db-exp-cell-edit';
        const title = !editable    ? 'No editable: la tabla no tiene PK'
                    : esPk          ? 'No editable: forma parte de la PK'
                    : esAi          ? 'No editable: auto_increment'
                                    : 'Doble click para editar';
        return '<td class="' + cls + '" data-col="' + esc(c) + '" title="' + esc(title) + '"'
             + (lock ? '' : ' ondblclick="dbExpEditarCelda(this)"')
             + '>' + dbExpFmtValor(r[c]) + '</td>';
      }).join('') + '</tr>';
    });
    tbody.innerHTML = html;
  }

  let metaTxt = visible.length + '/' + fmtNum(dbExpRegsTotal);
  if (q && visible.length !== dbExpRegistros.length) {
    metaTxt += ' (filtrados de ' + dbExpRegistros.length + ')';
  }
  if (!pk.length && dbExpRegistros.length) metaTxt += ' · solo lectura';
  meta.textContent = metaTxt;
}

function dbExpFmtValor(v) {
  if (v === null || v === undefined) return '<span class="db-exp-null">NULL</span>';
  if (v === '') return '<span class="db-exp-null">""</span>';
  if (typeof v === 'boolean') return v ? '1' : '0';
  return esc(String(v));
}

// ----- Edición inline de celdas -----
function dbExpEditarCelda(td) {
  if (td.querySelector('input, textarea')) return;
  const rowIdx = +td.parentElement.getAttribute('data-row');
  const col    = td.getAttribute('data-col');
  const reg    = dbExpRegistros[rowIdx];
  if (!reg) return;
  const valOrig = reg[col];

  td.classList.add('db-exp-cell-editing');
  const isNull = (valOrig === null || valOrig === undefined);
  const valTxt = isNull ? '' : String(valOrig);

  td.innerHTML =
    '<div class="db-exp-edit-wrap">'
    + '<input type="text" class="db-exp-edit-input" value="' + esc(valTxt) + '">'
    + '<div class="db-exp-edit-actions">'
    +   '<button type="button" class="btn-icon-sm" data-act="guardar" title="Guardar (Enter)"><i class="fa-solid fa-check" style="color:var(--success)"></i></button>'
    +   '<button type="button" class="btn-icon-sm" data-act="cancelar" title="Cancelar (Esc)"><i class="fa-solid fa-xmark"></i></button>'
    +   (dbExpNullableCols.includes(col)
          ? '<button type="button" class="btn-icon-sm" data-act="null" title="Setear NULL"><i class="fa-solid fa-ban" style="color:var(--muted)"></i></button>'
          : '')
    + '</div>'
    + '</div>';

  const input = td.querySelector('input');
  input.focus();
  input.select();

  const cerrar = () => {
    td.classList.remove('db-exp-cell-editing');
    td.innerHTML = dbExpFmtValor(reg[col]);
  };
  const guardar = async (nuevoValor) => {
    if (nuevoValor === valOrig) { cerrar(); return; }
    td.classList.add('db-exp-cell-saving');
    try {
      const data = await apiSend('api/herramientas_db_update.php', 'POST', {
        tabla:   dbExpTablaActual,
        columna: col,
        pk:      Object.fromEntries(dbExpPkCols.map((c) => [c, reg[c]])),
        valor:   nuevoValor,
      });
      reg[col] = (data.valor_guardado === undefined ? nuevoValor : data.valor_guardado);
      td.classList.remove('db-exp-cell-editing', 'db-exp-cell-saving');
      td.innerHTML = dbExpFmtValor(reg[col]);
      td.classList.add('db-exp-cell-ok');
      setTimeout(() => td.classList.remove('db-exp-cell-ok'), 800);
    } catch (e) {
      td.classList.remove('db-exp-cell-saving');
      toast(e.message || 'No se pudo actualizar', { error: true });
      const inp = td.querySelector('input');
      if (inp) inp.focus();
    }
  };

  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter')      { ev.preventDefault(); ev.stopPropagation(); guardar(input.value); }
    else if (ev.key === 'Escape'){ ev.preventDefault(); ev.stopPropagation(); cerrar(); }
  });
  td.querySelector('[data-act="guardar"]').addEventListener('click', () => guardar(input.value));
  td.querySelector('[data-act="cancelar"]').addEventListener('click', cerrar);
  const btnNull = td.querySelector('[data-act="null"]');
  if (btnNull) btnNull.addEventListener('click', () => guardar(null));
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const back = document.getElementById('dbExpModalBackdrop');
  if (back && back.classList.contains('open')) cerrarExploradorDB();
});

// ------------------------- Vista: Datacount > Comprobantes (ABM) -------------------------
const dcCompFiltrosDefaults = {
  q: '', codigo: '', tipo: '', punto: '', serie: '', cliente: '',
  razon: '', cuit: '', estado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const dcCompFiltros = { ...dcCompFiltrosDefaults };
let dcCompBuscadorTimer  = null;
let dcCompFiltrosSnapshot = null;
// Catalogo de estados posibles para comprobantes, leido de la tabla `estados`
// donde `campo = 'datacount_comprobante_estado'`. Se cachea entre navegaciones.
let dcCompEstadosCatalogo = null;
let dcCompEstadosPromesa  = null;

async function dcCompCargarCatalogoEstados() {
  if (dcCompEstadosCatalogo) return dcCompEstadosCatalogo;
  if (dcCompEstadosPromesa)  return dcCompEstadosPromesa;
  dcCompEstadosPromesa = (async () => {
    const data = await apiGet('api/estados.php?campo=datacount_comprobante_estado&order_by=campo_orden&dir=asc&limite=500');
    dcCompEstadosCatalogo = (data.items || []).map((r) => ({
      valor: String(r.valor ?? ''),
      texto: String(r.texto ?? '').trim() || String(r.valor ?? ''),
    }));
    return dcCompEstadosCatalogo;
  })();
  try { return await dcCompEstadosPromesa; }
  finally { dcCompEstadosPromesa = null; }
}

function dcCompPintarChipsEstado() {
  const box = document.getElementById('fDcCompEstadoChips');
  if (!box) return;
  const actual = dcCompFiltros.estado || '';
  const items  = dcCompEstadosCatalogo || [];
  box.innerHTML =
    `<button type="button" class="filter-chip${actual === '' ? ' active' : ''}" data-estado="">Todos</button>` +
    items.map((e) =>
      `<button type="button" class="filter-chip${actual === e.valor ? ' active' : ''}" data-estado="${esc(e.valor)}">${esc(e.texto)}</button>`
    ).join('');
  // Re-vincular click si el modal esta abierto (sincronizarControlesFiltrosDcComp
  // ya hace el toggle .active; aca solo conectamos onclick).
  box.querySelectorAll('.filter-chip').forEach((c) => {
    c.onclick = () => { onFiltroDcComp('estado', c.dataset.estado || ''); sincronizarControlesFiltrosDcComp(); };
  });
}

function dcCompFmtComprobante(punto, serie) {
  const p = punto != null && punto !== '' ? String(punto).padStart(4, '0') : '----';
  const s = serie != null && serie !== '' ? String(serie).padStart(8, '0') : '--------';
  return `${p}-${s}`;
}

function dcCompFmtImporte(v) {
  if (v == null || v === '' || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function dcCompEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  // El texto del estado se toma del catalogo `estados` (campo
  // 'datacount_comprobante_estado'). Si el catalogo aun no cargo o no
  // contiene el codigo, mostramos el valor crudo como fallback.
  // Los colores siguen la convencion heredada para A/P/C/B; el resto
  // cae en badge-info.
  const colorMap = {
    A: 'badge-success',
    P: 'badge-warn',
    C: 'badge-danger',
    B: 'badge-danger',
  };
  const cls  = colorMap[e] || 'badge-info';
  const item = (dcCompEstadosCatalogo || []).find((x) => String(x.valor) === String(e));
  const label = item ? item.texto : String(e);
  return `<span class="badge ${cls}">${esc(label)}</span>`;
}

route('/datacountcomprobantes', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">🧾</div>
        <div class="module-help-text">
          Los comprobantes son las facturas, recibos y demás documentos que Datacount
          emite a los clientes, con su numeración, importes, datos fiscales y estado de autorización.
        </div>
      </div>

      <div class="stats-bar" id="dcCompStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Importe total</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="dcCompSearch"
                   placeholder="🔍 Buscar razón, CUIT, correo o CAE…">
            <button class="search-clear" id="dcCompSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dcCompFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dcCompFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dcCompRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dcCompNuevoBtn">+ Nuevo comprobante</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Tipo</th>
              <th>Comprobante</th>
              <th>Emisión</th>
              <th>Razón social</th>
              <th>CUIT</th>
              <th style="text-align:right">Total</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dcCompTbody">
            <tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="dcCompCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros -->
    <div class="modal-backdrop" id="filtrosDcCompBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDcComp()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDcComp()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDcCompCodigo" min="1" placeholder="ID …" oninput="onFiltroDcComp('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Tipo</label>
              <input type="text" id="fDcCompTipo" maxlength="2" oninput="onFiltroDcComp('tipo', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Punto</label>
              <input type="number" id="fDcCompPunto" min="0" oninput="onFiltroDcComp('punto', this.value)">
            </div>
            <div class="form-group">
              <label>Serie</label>
              <input type="number" id="fDcCompSerie" min="0" oninput="onFiltroDcComp('serie', this.value)">
            </div>
            <div class="form-group">
              <label>Cliente (ID)</label>
              <input type="number" id="fDcCompCliente" min="1" oninput="onFiltroDcComp('cliente', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Razón social</label>
              <input type="text" id="fDcCompRazon" oninput="onFiltroDcComp('razon', this.value)">
            </div>
            <div class="form-group">
              <label>CUIT</label>
              <input type="text" id="fDcCompCuit" oninput="onFiltroDcComp('cuit', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Estado del comprobante</label>
            <div id="fDcCompEstadoChips" style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip active" data-estado="">Todos</button>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDcCompLimite" min="1" max="1000" value="100" onchange="onFiltroDcComp('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDcCompOrderBy" onchange="onFiltroDcComp('order_by', this.value)">
                <option value="id">Código</option>
                <option value="emision">Emisión</option>
                <option value="vencimiento">Vencimiento</option>
                <option value="tipo">Tipo</option>
                <option value="punto">Punto</option>
                <option value="serie">Serie</option>
                <option value="razon">Razón social</option>
                <option value="cuit">CUIT</option>
                <option value="total">Total</option>
                <option value="registrado">Fecha de registro</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDcCompDir" onchange="onFiltroDcComp('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDcComp()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDcComp()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDcComp()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#dcCompNuevoBtn').addEventListener('click', () => abrirAltaEdicionDcComp(null));
  $('#dcCompFiltrosBtn').addEventListener('click', () => abrirModalFiltrosDcComp());
  $('#dcCompRefrescarBtn').addEventListener('click', () => cargarDcComp());

  const inp = $('#dcCompSearch');
  const clr = $('#dcCompSearchClear');
  inp.value = dcCompFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dcCompFiltros.q = inp.value.trim();
    clearTimeout(dcCompBuscadorTimer);
    dcCompBuscadorTimer = setTimeout(() => { cargarDcComp(); refrescarBadgeFiltrosDcComp(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    dcCompFiltros.q = '';
    cargarDcComp();
    refrescarBadgeFiltrosDcComp();
  });

  // Acciones del menú contextual
  $('#dcCompCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarDcComp(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDcComp(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDcComp(data.id);
  });

  // Clic en fila → consultar; clic en hamburguesa → menú
  $('#dcCompTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dcCompCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarDcComp(Number(tr.dataset.id));
  });
  $('#dcCompTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dcCompCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosDcComp();
  // Cargamos el catalogo de estados en paralelo con el listado, asi los chips
  // del modal de filtros ya estan listos cuando el usuario lo abre.
  dcCompCargarCatalogoEstados().then(dcCompPintarChipsEstado).catch(() => {});
  await cargarDcComp();
}, 'Comprobantes');

async function cargarDcComp() {
  const tbody = $('#dcCompTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(dcCompFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    // En paralelo: traer comprobantes y asegurar que el catalogo de estados
    // este cargado, asi `dcCompEstadoBadge` puede mostrar el `texto` y no el
    // codigo crudo en la primera pintada.
    const [data] = await Promise.all([
      apiGet('api/datacountcomprobantes.php?' + qs.toString()),
      dcCompCargarCatalogoEstados().catch(() => null),
    ]);
    pintarStatsDcComp(data.stats);
    pintarTablaDcComp(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDcComp(s) {
  const cards = $$('#dcCompStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = '$ ' + dcCompFmtImporte(s.importe_total);
}

function pintarTablaDcComp(rows) {
  const tbody = $('#dcCompTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Sin comprobantes.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td>${esc(c.tipo || '—')}</td>
      <td style="font-family:monospace">${esc(dcCompFmtComprobante(c.punto, c.serie))}</td>
      <td>${esc(c.emision || '—')}</td>
      <td class="td-nombre">${esc(c.razon || '—')}</td>
      <td>${esc(c.cuit || '—')}</td>
      <td style="text-align:right">${c.total != null ? '$ ' + dcCompFmtImporte(c.total) : '—'}</td>
      <td>${dcCompEstadoBadge(c.estado)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de Filtros ----
function onFiltroDcComp(key, value) {
  if (key === 'tipo' || key === 'razon' || key === 'cuit') {
    dcCompFiltros[key] = String(value).trim();
  } else if (key === 'codigo' || key === 'punto' || key === 'serie' || key === 'cliente') {
    const v = String(value).trim();
    dcCompFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    dcCompFiltros.limite = n;
  } else {
    dcCompFiltros[key] = value;
  }
  refrescarBadgeFiltrosDcComp();
  cargarDcComp();
}

function refrescarBadgeFiltrosDcComp() {
  const btn   = $('#dcCompFiltrosBtn');
  const badge = $('#dcCompFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(dcCompFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(dcCompFiltros[k]) !== String(dcCompFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosDcComp() {
  const f = dcCompFiltros;
  $('#fDcCompCodigo').value  = f.codigo;
  $('#fDcCompTipo').value    = f.tipo;
  $('#fDcCompPunto').value   = f.punto;
  $('#fDcCompSerie').value   = f.serie;
  $('#fDcCompCliente').value = f.cliente;
  $('#fDcCompRazon').value   = f.razon;
  $('#fDcCompCuit').value    = f.cuit;
  $('#fDcCompLimite').value  = f.limite;
  $('#fDcCompOrderBy').value = f.order_by;
  $('#fDcCompDir').value     = f.dir;
  $$('#fDcCompEstadoChips .filter-chip').forEach((c) => {
    c.classList.toggle('active', (c.dataset.estado || '') === (f.estado || ''));
  });
}

function abrirModalFiltrosDcComp() {
  dcCompFiltrosSnapshot = { ...dcCompFiltros };
  // Si el catalogo aun no llego, reintentamos (mantiene visible "Todos" mientras tanto).
  if (!dcCompEstadosCatalogo) {
    dcCompCargarCatalogoEstados().then(dcCompPintarChipsEstado).catch(() => {});
  } else {
    dcCompPintarChipsEstado();
  }
  sincronizarControlesFiltrosDcComp();
  $$('#fDcCompEstadoChips .filter-chip').forEach((c) => {
    c.onclick = () => { onFiltroDcComp('estado', c.dataset.estado || ''); sincronizarControlesFiltrosDcComp(); };
  });
  $('#filtrosDcCompBackdrop').classList.add('open');
}

function cerrarModalFiltrosDcComp() {
  $('#filtrosDcCompBackdrop').classList.remove('open');
}

function cancelarFiltrosDcComp() {
  if (dcCompFiltrosSnapshot) {
    Object.assign(dcCompFiltros, dcCompFiltrosSnapshot);
    refrescarBadgeFiltrosDcComp();
    cargarDcComp();
  }
  cerrarModalFiltrosDcComp();
}

function limpiarFiltrosDcComp() {
  Object.assign(dcCompFiltros, dcCompFiltrosDefaults);
  dcCompFiltros.q = $('#dcCompSearch')?.value.trim() || '';
  sincronizarControlesFiltrosDcComp();
  refrescarBadgeFiltrosDcComp();
  cargarDcComp();
}

// Exponer para los onclick del HTML
window.onFiltroDcComp           = onFiltroDcComp;
window.cancelarFiltrosDcComp    = cancelarFiltrosDcComp;
window.limpiarFiltrosDcComp     = limpiarFiltrosDcComp;
window.cerrarModalFiltrosDcComp = cerrarModalFiltrosDcComp;

// ---- Modal Consultar (formato factura) ----
async function abrirConsultarDcComp(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1400px">
      <div class="modal-header">
        <div class="modal-title">Comprobante <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDcComp(id); }
  });

  try {
    const c = await apiGet(`api/datacountcomprobantes.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaDcComp(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaDcComp(c) {
  const numero  = dcCompFmtComprobante(c.punto, c.serie);
  const tipoEnc = [c.tipo, c.fiscal].filter(Boolean).join(' ') || '—';
  const money   = (v) => (v == null || v === '') ? '—' : '$ ' + dcCompFmtImporte(v);
  const cantidad = (v) => (v == null || v === '') ? '—' : dcCompFmtImporte(v);

  // Tarjetas tipo data-row (mismo estilo que ABM consultar).
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  // Línea "Etiqueta: valor" dentro de una tarjeta agrupadora.
  const linea = (label, value) => {
    const empty = value == null || value === '';
    const valHtml = empty
      ? `<span style="color:var(--muted);font-style:italic">Sin dato</span>`
      : esc(value);
    return `
      <div style="font-size:.9rem;line-height:1.55;padding:3px 0">
        <span style="color:var(--muted);font-weight:600">${esc(label)}:</span>
        ${valHtml}
      </div>`;
  };

  // Renglones
  const renglones = c.renglones || [];
  const renglonesHtml = !renglones.length
    ? `<tr><td colspan="6" class="table-empty">Este comprobante no tiene renglones.</td></tr>`
    : renglones.map((r, i) => `
        <tr>
          <td class="td-id" style="text-align:center">${esc(r.orden ?? i + 1)}</td>
          <td style="text-align:right">${cantidad(r.cantidad)}</td>
          <td>${esc(r.detalle || '—')}</td>
          <td style="text-align:right">${money(r.unitario)}</td>
          <td style="text-align:right">${money(r.iva)}</td>
          <td style="text-align:right;font-weight:600">${money(r.monto)}</td>
        </tr>
      `).join('');

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  return `
    <!-- Encabezado tipo factura -->
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.5rem;font-weight:700">${esc(tipoEnc)}</span>
          <span style="font-family:monospace;font-size:1.2rem;color:var(--muted)">${esc(numero)}</span>
        </div>
        <div style="font-size:.78rem;color:var(--muted);margin-top:6px">#${esc(c.id)} · UUID <code>${esc(c.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:180px">
        <div>${dcCompEstadoBadge(c.estado)}</div>
        <div style="margin-top:10px;font-size:.85rem;line-height:1.6">
          <div><span style="color:var(--muted)">Emisión:</span> ${esc(c.emision || '—')}</div>
          <div><span style="color:var(--muted)">Vencimiento:</span> ${esc(c.vencimiento || '—')}</div>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start">
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Cliente</div>
        <div style="background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;padding:14px 18px">
          ${linea('Razón social',  c.razon)}
          ${linea('Domicilio',     c.domicilio)}
          ${linea('Correo',        c.correo)}
          ${linea('Celular',       c.celular)}
          ${linea('CUIT',          c.cuit)}
          ${linea('Condición IVA', c.condicion)}
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Datos fiscales</div>
        <div style="background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;padding:14px 18px">
          ${linea('CAE',            c.caenro)}
          ${linea('Vto. CAE',       c.caevto)}
          ${linea('Talonario',      c.talonario)}
          ${linea('Punto de venta', c.punto)}
          ${linea('Proyecto',       c.proyecto)}
          ${linea('Empresa',        c.empresa)}
        </div>
      </div>
    </div>

    ${seccion('Detalle')}
    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th style="width:48px;text-align:center">#</th>
            <th style="width:90px;text-align:right">Cant.</th>
            <th>Detalle</th>
            <th style="width:120px;text-align:right">Unitario</th>
            <th style="width:110px;text-align:right">IVA</th>
            <th style="width:130px;text-align:right">Monto</th>
          </tr>
        </thead>
        <tbody>${renglonesHtml}</tbody>
      </table>
    </div>

    <!-- Totales -->
    <div style="display:flex;justify-content:flex-end">
      <table style="width:340px;font-size:.9rem">
        <tr>
          <td style="padding:6px 12px;color:var(--muted)">Neto</td>
          <td style="padding:6px 12px;text-align:right;font-family:monospace">${money(c.neto)}</td>
        </tr>
        <tr>
          <td style="padding:6px 12px;color:var(--muted)">IVA</td>
          <td style="padding:6px 12px;text-align:right;font-family:monospace">${money(c.iva)}</td>
        </tr>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Total</td>
          <td style="padding:12px;text-align:right;font-weight:700;font-size:1.2rem;font-family:monospace;color:var(--primary)">${money(c.total)}</td>
        </tr>
      </table>
    </div>

    ${seccion('Notas y referencias')}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start">
      ${card('Observaciones', c.observaciones)}
      ${card('Comentarios',   c.comentarios)}
    </div>
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Medio de pago', c.medio)}
      ${card('Asociado',      c.asociado)}
      ${card('Contrato',      c.contrato)}
      ${card('Registrado',    fmtFecha(c.registrado))}
      ${card('Autorizado',    fmtFecha(c.autorizado))}
      ${card('Respuesta CAE', c.caeres, true)}
    </dl>
  `;
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicionDcComp(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar comprobante <span class="modal-subtitle">#${id}</span>` : 'Nuevo comprobante'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formDcCompHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/datacountcomprobantes.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formDcCompHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarDcComp(id, a);
  });
}

function formDcCompHtml(c) {
  const v   = (k) => esc(c?.[k] ?? '');
  const sel = (k, val) => (c?.[k] ?? '') === val ? 'selected' : '';
  // datetime-local quiere 'YYYY-MM-DDTHH:MM'
  const dt  = (k) => {
    const raw = c?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Tipo</label>
        <input type="text" id="dcTipo" maxlength="2" value="${v('tipo')}">
      </div>
      <div class="form-group">
        <label>Punto de venta</label>
        <input type="number" id="dcPunto" min="0" value="${v('punto')}">
      </div>
      <div class="form-group">
        <label>Número</label>
        <input type="number" id="dcSerie" min="0" value="${v('serie')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Fiscal</label>
        <input type="text" id="dcFiscal" maxlength="1" value="${v('fiscal')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="dcEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="A" ${sel('estado','A')}>Autorizado</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="B" ${sel('estado','B')}>Baja</option>
        </select>
      </div>
      <div class="form-group">
        <label>Talonario</label>
        <input type="number" id="dcTalonario" min="1" value="${v('talonario')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Proyecto</label>
        <input type="number" id="dcProyecto" min="1" value="${v('proyecto')}">
      </div>
      <div class="form-group">
        <label>Empresa</label>
        <input type="number" id="dcEmpresa" min="1" value="${v('empresa')}">
      </div>
      <div class="form-group">
        <label>Medio</label>
        <input type="number" id="dcMedio" min="1" value="${v('medio')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Emisión</label>
        <input type="date" id="dcEmision" value="${v('emision')}">
      </div>
      <div class="form-group">
        <label>Vencimiento</label>
        <input type="date" id="dcVencimiento" value="${v('vencimiento')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Asociado</label>
        <input type="number" id="dcAsociado" min="1" value="${v('asociado')}">
      </div>
      <div class="form-group">
        <label>Contrato</label>
        <input type="number" id="dcContrato" min="1" value="${v('contrato')}">
      </div>
      <div class="form-group">
        <label>Cliente (ID)</label>
        <input type="number" id="dcCliente" min="1" value="${v('cliente')}">
      </div>
    </div>
    <div class="form-group">
      <label>Razón social</label>
      <input type="text" id="dcRazon" maxlength="250" value="${v('razon')}">
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Condición IVA</label>
        <input type="text" id="dcCondicion" maxlength="2" value="${v('condicion')}">
      </div>
      <div class="form-group">
        <label>CUIT</label>
        <input type="text" id="dcCuit" maxlength="50" value="${v('cuit')}">
      </div>
      <div class="form-group">
        <label>Correo</label>
        <input type="email" id="dcCorreo" maxlength="100" value="${v('correo')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Celular</label>
        <input type="text" id="dcCelular" maxlength="100" value="${v('celular')}">
      </div>
      <div class="form-group">
        <label>Domicilio</label>
        <input type="text" id="dcDomicilio" maxlength="250" value="${v('domicilio')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Neto</label>
        <input type="number" id="dcNeto" step="0.01" value="${v('neto')}">
      </div>
      <div class="form-group">
        <label>IVA</label>
        <input type="number" id="dcIva" step="0.01" value="${v('iva')}">
      </div>
      <div class="form-group">
        <label>Total</label>
        <input type="number" id="dcTotal" step="0.01" value="${v('total')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>CAE (Nº)</label>
        <input type="text" id="dcCaenro" maxlength="50" value="${v('caenro')}">
      </div>
      <div class="form-group">
        <label>CAE (Vto.)</label>
        <input type="text" id="dcCaevto" maxlength="50" value="${v('caevto')}">
      </div>
    </div>
    <div class="form-group">
      <label>Respuesta CAE</label>
      <textarea id="dcCaeres">${v('caeres')}</textarea>
    </div>
    <div class="form-group">
      <label>Observaciones</label>
      <textarea id="dcObservaciones" maxlength="2000">${v('observaciones')}</textarea>
    </div>
    <div class="form-group">
      <label>Comentarios</label>
      <textarea id="dcComentarios" maxlength="2000">${v('comentarios')}</textarea>
    </div>
    <div class="form-group">
      <label>Autorizado</label>
      <input type="datetime-local" id="dcAutorizado" value="${dt('autorizado')}">
    </div>
    <div class="field-error" id="dcError" style="display:none"></div>
  `;
}

async function guardarDcComp(id, btn) {
  const err = $('#dcError');
  err.style.display = 'none';

  const payload = {
    tipo:          $('#dcTipo').value.trim(),
    punto:         $('#dcPunto').value,
    serie:         $('#dcSerie').value,
    fiscal:        $('#dcFiscal').value.trim(),
    estado:        $('#dcEstado').value,
    talonario:     $('#dcTalonario').value,
    proyecto:      $('#dcProyecto').value,
    empresa:       $('#dcEmpresa').value,
    medio:         $('#dcMedio').value,
    emision:       $('#dcEmision').value || null,
    vencimiento:   $('#dcVencimiento').value || null,
    asociado:      $('#dcAsociado').value,
    contrato:      $('#dcContrato').value,
    cliente:       $('#dcCliente').value,
    razon:         $('#dcRazon').value.trim(),
    condicion:     $('#dcCondicion').value.trim(),
    cuit:          $('#dcCuit').value.trim(),
    correo:        $('#dcCorreo').value.trim(),
    celular:       $('#dcCelular').value.trim(),
    domicilio:     $('#dcDomicilio').value.trim(),
    neto:          $('#dcNeto').value,
    iva:           $('#dcIva').value,
    total:         $('#dcTotal').value,
    caenro:        $('#dcCaenro').value.trim(),
    caevto:        $('#dcCaevto').value.trim(),
    caeres:        $('#dcCaeres').value,
    observaciones: $('#dcObservaciones').value,
    comentarios:   $('#dcComentarios').value,
    autorizado:    $('#dcAutorizado').value || null,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/datacountcomprobantes.php', 'POST', payload);
      toast('Comprobante creado.');
    } else {
      await apiSend(`api/datacountcomprobantes.php?id=${id}`, 'PUT', payload);
      toast('Comprobante actualizado.');
    }
    closeModal();
    cargarDcComp();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarDcComp(id) {
  const ok = await confirmar({
    title: 'Eliminar comprobante',
    message: `Se eliminará el comprobante #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/datacountcomprobantes.php?id=${id}`, 'DELETE');
    toast('Comprobante eliminado.');
    cargarDcComp();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Datacount > Facturación (motor + log) -------------------------
// El motor de facturación corre por fuera de esta app (proceso externo); lee el
// parámetro `datacount.motor` de la tabla `parametros` para saber si tiene que
// trabajar. Esta vista solo prende/apaga ese parámetro y muestra el log que el
// motor va dejando. El log todavía no tiene fuente real (ver api/datacountfacturacion.php).

const FACT_REFRESH_MS = 1000;
let factPollTimer  = null;
let factLastLogId  = 0;
let factMotorPrev  = null;   // ultimo valor confirmado por el server, para revertir si falla el toggle

function factDetener() {
  if (factPollTimer) { clearInterval(factPollTimer); factPollTimer = null; }
}

function factSetMotorUI(motor) {
  factMotorPrev = motor;
  const badge = document.getElementById('factMotorBadge');
  const sw    = document.getElementById('factMotorSwitch');
  const lbl   = document.getElementById('factMotorLabel');
  if (badge) {
    if (motor === '1') {
      badge.className = 'badge badge-success';
      badge.textContent = 'Encendido';
    } else {
      badge.className = 'badge badge-danger';
      badge.textContent = 'Apagado';
    }
  }
  if (sw) sw.checked = (motor === '1');
  if (lbl) lbl.textContent = motor === '1' ? 'Encendido' : 'Apagado';
}

async function factCargarStatus() {
  try {
    const s = await apiGet('api/datacountfacturacion.php?action=status');
    factSetMotorUI(s.motor);
  } catch (e) { /* silencioso: es polling */ }
}

async function factCargarLog() {
  try {
    const d = await apiGet('api/datacountfacturacion.php?action=log&since=' + factLastLogId);
    if (!Array.isArray(d.items) || d.items.length === 0) return;
    factAppendLineas(d.items);
    if (d.last_id) factLastLogId = Math.max(factLastLogId, Number(d.last_id) || 0);
  } catch (e) { /* silencioso */ }
}

function factAppendLineas(items) {
  const cont = document.getElementById('factLog');
  if (!cont) return;
  const placeholder = cont.querySelector('.term-log-empty');
  if (placeholder) placeholder.remove();
  const stickToBottom = (cont.scrollTop + cont.clientHeight >= cont.scrollHeight - 20);
  const html = items.map((it) => {
    const ts  = it.fecha   ? esc(it.fecha)   : '';
    const lvl = String(it.nivel || 'info').toLowerCase();
    const lvlCls = ['info','ok','warn','error'].includes(lvl) ? lvl : 'info';
    const msg = esc(it.mensaje || '');
    return `<span class="term-log-line"><span class="ts">${ts}</span><span class="lvl-${lvlCls}">${msg}</span></span>`;
  }).join('');
  cont.insertAdjacentHTML('beforeend', html);
  if (stickToBottom) cont.scrollTop = cont.scrollHeight;
}

async function factToggleMotor(ev) {
  const sw = ev.target;
  const nuevo = sw.checked ? '1' : '0';
  const anterior = factMotorPrev;
  factSetMotorUI(nuevo); // optimista
  try {
    const r = await apiSend('api/datacountfacturacion.php?action=motor', 'POST', { valor: nuevo });
    factSetMotorUI(r.motor);
    toast(r.motor === '1' ? 'Motor de facturación encendido.' : 'Motor de facturación apagado.');
  } catch (e) {
    factSetMotorUI(anterior || (nuevo === '1' ? '0' : '1'));
    toast(e.message, { error: true });
  }
}

route('/datacountfacturacion', async (mount) => {
  factDetener();
  factLastLogId = 0;
  factMotorPrev = null;

  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">🤖</div>
        <div class="module-help-text">
          El motor de facturación es el proceso que registra automáticamente los comprobantes
          de Datacount ante AFIP. Desde acá se lo prende o apaga y se sigue su actividad en vivo.
        </div>
      </div>

      <div class="fact-controls">
        <span class="fact-controls-title">Motor de facturación</span>
        <span class="badge" id="factMotorBadge">…</span>
        <label class="toggle-switch" title="Encender / Apagar motor">
          <input type="checkbox" id="factMotorSwitch">
          <span class="toggle-track"><span class="toggle-thumb"></span></span>
          <span class="toggle-label" id="factMotorLabel">—</span>
        </label>
        <div class="fact-controls-right">
          <button class="btn btn-ghost btn-icon" id="factLimpiarBtn" title="Limpiar pantalla">
            <i class="fa-solid fa-eraser"></i>
          </button>
          <button class="btn btn-ghost btn-icon" id="factRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
      </div>

      <div class="term-log" id="factLog">
        <span class="term-log-empty">Sin registros aún.</span>
      </div>
    </div>
  `;

  document.getElementById('factMotorSwitch').addEventListener('change', factToggleMotor);
  document.getElementById('factRefrescarBtn').addEventListener('click', () => {
    factCargarStatus();
    factCargarLog();
  });
  document.getElementById('factLimpiarBtn').addEventListener('click', () => {
    const cont = document.getElementById('factLog');
    if (cont) cont.innerHTML = '<span class="term-log-empty">Sin registros aún.</span>';
    factLastLogId = 0;
  });

  await factCargarStatus();
  await factCargarLog();
  // Polling 1s. Si el usuario navega a otra vista, render() reemplaza #view y el
  // elemento #factLog desaparece — el propio tick se autodetiene.
  factPollTimer = setInterval(() => {
    if (!document.getElementById('factLog')) { factDetener(); return; }
    factCargarStatus();
    factCargarLog();
  }, FACT_REFRESH_MS);
}, 'Facturación');

// ------------------------- Contexto compartido: empresa activa -------------------------
// Contexto de "empresa activa" para todos los módulos de Datacount (Plan de
// cuentas, Recurrentes, etc). Se persiste en localStorage para que la
// selección se mantenga al navegar entre módulos y entre sesiones.

const DC_EMPRESA_LS_KEY = 'datacount:empresaId';
let dcEmpresasCache = [];

async function dcGetEmpresas(force = false) {
  if (!force && dcEmpresasCache.length) return dcEmpresasCache;
  try {
    const d = await apiGet('api/datacountempresas.php?limite=1000&orden=nombre&dir=asc');
    dcEmpresasCache = d.items || [];
  } catch {
    dcEmpresasCache = [];
  }
  return dcEmpresasCache;
}

function dcGetEmpresaId() {
  const raw = localStorage.getItem(DC_EMPRESA_LS_KEY);
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function dcSetEmpresaId(id) {
  const n = Number(id);
  if (Number.isFinite(n) && n > 0) {
    localStorage.setItem(DC_EMPRESA_LS_KEY, String(n));
  } else {
    localStorage.removeItem(DC_EMPRESA_LS_KEY);
  }
}

// Elige empresa activa: la persistida en localStorage si sigue siendo
// válida, o la primera empresa disponible como fallback (y la persiste).
// Devuelve null si no hay empresas.
async function dcAsegurarEmpresaId() {
  const empresas = await dcGetEmpresas();
  if (!empresas.length) return null;
  const current = dcGetEmpresaId();
  if (current && empresas.some((e) => e.id === current)) return current;
  const primera = empresas[0].id;
  dcSetEmpresaId(primera);
  return primera;
}

// ------------------------- Vista: Datacount > Plan de cuentas -------------------------
// Plan de cuentas jerárquico sobre `datacount_cuentas` (misma estructura que
// `repo.cuentas`) — un plan independiente por empresa. Se pinta como árbol:
// cada fila puede colapsarse, y hay buscador que aplana la vista. El botón
// "+ Nueva cuenta" abre un formulario que hereda tipo/naturaleza del padre
// si se pasa parentId.

const DCC_API = 'api/datacountcuentas.php';
const DCC_TIPO_LABEL = {
  activo:     'Activo',
  pasivo:     'Pasivo',
  patrimonio: 'Patrimonio',
  ingreso:    'Ingreso',
  egreso:     'Egreso',
};
const DCC_TIPO_BADGE = {
  activo:     'badge-info',
  pasivo:     'badge-danger',
  patrimonio: 'badge-warn',
  ingreso:    'badge-success',
  egreso:     'badge-warn',
};

let dccCuentas       = [];
let dccBusqueda      = '';
let dccFiltroTipo    = '';
let dccColapsadas    = new Set();
let dccEditandoId    = null;
let dccBuscadorTimer = null;

function dccFmtMoney(n) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

route('/datacountcuentas', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">📒</div>
        <div class="module-help-text">
          El plan de cuentas es la lista jerárquica de cuentas contables que Datacount usa
          para clasificar movimientos: cada cuenta tiene un código (ej. 1.1.01.02), un tipo
          (activo, pasivo, patrimonio, ingreso o egreso) y una naturaleza (deudora o acreedora).
        </div>
      </div>

      <div class="stats-bar" id="dccStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value orange" id="dccStatTotal">—</span></div>
        <div class="stat-card"><span class="stat-label">Activo</span><span class="stat-value" style="color:#93c5fd" id="dccStatActivo">—</span></div>
        <div class="stat-card"><span class="stat-label">Pasivo</span><span class="stat-value red" id="dccStatPasivo">—</span></div>
        <div class="stat-card"><span class="stat-label">Patrimonio</span><span class="stat-value" style="color:#c4b5fd" id="dccStatPatrimonio">—</span></div>
        <div class="stat-card"><span class="stat-label">Ingresos</span><span class="stat-value green" id="dccStatIngreso">—</span></div>
        <div class="stat-card"><span class="stat-label">Egresos</span><span class="stat-value" style="color:#fcd34d" id="dccStatEgreso">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <select id="dccEmpresaSel" style="min-width:200px" title="Empresa">
            <option value="">— Cargando empresas… —</option>
          </select>
          <div class="search-wrap">
            <input type="search" class="search-input" id="dccSearch"
                   placeholder="🔍 Buscar por código o nombre…">
            <button class="search-clear" id="dccSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dccRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right" style="gap:6px">
          <button class="btn btn-ghost btn-sm" id="dccExpandirBtn" title="Expandir todo">
            <i class="fa-solid fa-angles-down"></i> Expandir
          </button>
          <button class="btn btn-ghost btn-sm" id="dccColapsarBtn" title="Colapsar todo">
            <i class="fa-solid fa-angles-up"></i> Colapsar
          </button>
          <button class="btn btn-primary" id="dccNuevoBtn">+ Nueva cuenta</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:160px">Código</th>
              <th>Nombre</th>
              <th style="width:130px">Tipo</th>
              <th style="width:100px;text-align:center">Naturaleza</th>
              <th style="width:90px;text-align:center">Imputable</th>
              <th style="width:90px;text-align:center">Estado</th>
              <th style="width:140px;text-align:right">Saldo</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dccTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="dccCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <button type="button" data-action="agregar-sub" role="menuitem">
        <i class="fa-solid fa-plus"></i><span>Agregar subcuenta</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>
  `;

  const inp = $('#dccSearch');
  const clr = $('#dccSearchClear');
  inp.value = dccBusqueda;
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dccBusqueda = inp.value.trim();
    clearTimeout(dccBuscadorTimer);
    dccBuscadorTimer = setTimeout(cargarDcc, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = ''; clr.style.display = 'none'; dccBusqueda = ''; cargarDcc();
  });

  // Selector de empresa (contexto compartido con otros módulos Datacount).
  const selEmp = $('#dccEmpresaSel');
  const empresas = await dcGetEmpresas();
  const empresaId = await dcAsegurarEmpresaId();
  if (empresas.length) {
    selEmp.innerHTML = empresas.map((e) =>
      `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    selEmp.value = String(empresaId || empresas[0].id);
  } else {
    selEmp.innerHTML = `<option value="">— Sin empresas —</option>`;
    selEmp.disabled = true;
  }
  selEmp.addEventListener('change', (ev) => {
    dcSetEmpresaId(ev.target.value);
    cargarDcc();
  });

  $('#dccRefrescarBtn').addEventListener('click', cargarDcc);
  $('#dccExpandirBtn').addEventListener('click', () => dccExpandirTodo(true));
  $('#dccColapsarBtn').addEventListener('click', () => dccExpandirTodo(false));
  $('#dccNuevoBtn').addEventListener('click', () => abrirAltaEdicionDcc(null, null));

  // Menú contextual y clicks en filas.
  $('#dccCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar')   abrirConsultaDcc(data.id);
    if (b.dataset.action === 'agregar-sub') abrirAltaEdicionDcc(null, data.id);
    if (b.dataset.action === 'editar')      abrirAltaEdicionDcc(data.id, null);
    if (b.dataset.action === 'eliminar')    eliminarDcc(data.id);
  });

  $('#dccTbody').addEventListener('click', (ev) => {
    const tog = ev.target.closest('[data-act="toggle"]');
    if (tog) {
      const id = Number(tog.dataset.id);
      if (dccColapsadas.has(id)) dccColapsadas.delete(id);
      else dccColapsadas.add(id);
      renderDcc();
      return;
    }
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dccCtxMenu'), r.right - 200, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultaDcc(Number(tr.dataset.id));
  });
  $('#dccTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dccCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  await cargarDcc();
}, 'Plan de cuentas');

async function cargarDcc() {
  const tbody = $('#dccTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const empresaId = await dcAsegurarEmpresaId();
  if (!empresaId) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">No hay empresas registradas — creá una antes de armar su plan de cuentas.</td></tr>`;
    return;
  }

  const qs = new URLSearchParams();
  qs.set('empresa_id', String(empresaId));
  if (dccBusqueda)   qs.set('q', dccBusqueda);
  if (dccFiltroTipo) qs.set('tipo', dccFiltroTipo);

  try {
    const data = await apiGet(DCC_API + '?' + qs.toString());
    dccCuentas = data.items || [];
    pintarStatsDcc(data.stats || {});
    renderDcc();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDcc(s) {
  $('#dccStatTotal').textContent      = fmtNum(s.total ?? dccCuentas.length);
  $('#dccStatActivo').textContent     = fmtNum(s.activo ?? 0);
  $('#dccStatPasivo').textContent     = fmtNum(s.pasivo ?? 0);
  $('#dccStatPatrimonio').textContent = fmtNum(s.patrimonio ?? 0);
  $('#dccStatIngreso').textContent    = fmtNum(s.ingreso ?? 0);
  $('#dccStatEgreso').textContent     = fmtNum(s.egreso ?? 0);
}

function renderDcc() {
  const tbody = $('#dccTbody');
  if (!tbody) return;
  if (!dccCuentas.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin cuentas registradas.</td></tr>`;
    return;
  }

  // Cuando hay búsqueda o filtro por tipo aplanamos, sin jerarquía.
  const aplanado = !!(dccBusqueda || dccFiltroTipo);

  let html = '';
  if (aplanado) {
    html = dccCuentas.map((c) => renderFilaDcc(c, 0, false, false)).join('');
  } else {
    const byId = {};
    dccCuentas.forEach((c) => { byId[c.id] = Object.assign({}, c, { children: [] }); });
    const raices = [];
    dccCuentas.forEach((c) => {
      const n = byId[c.id];
      if (c.parent_id && byId[c.parent_id]) byId[c.parent_id].children.push(n);
      else raices.push(n);
    });
    const walk = (nodo, depth) => {
      const tieneHijos = nodo.children.length > 0;
      const colapsada  = dccColapsadas.has(nodo.id);
      html += renderFilaDcc(nodo, depth, tieneHijos, colapsada);
      if (tieneHijos && !colapsada) {
        nodo.children.forEach((h) => walk(h, depth + 1));
      }
    };
    raices.forEach((r) => walk(r, 0));
  }
  tbody.innerHTML = html;
}

function renderFilaDcc(c, depth, tieneHijos, colapsada) {
  const indent = depth * 22;
  const toggle = tieneHijos
    ? `<span class="dcc-toggle" data-act="toggle" data-id="${c.id}"
             style="cursor:pointer;display:inline-block;width:18px;text-align:center;user-select:none;color:var(--muted)">${colapsada ? '▶' : '▼'}</span>`
    : `<span style="display:inline-block;width:18px"></span>`;

  const tipoBadge = `<span class="badge ${DCC_TIPO_BADGE[c.tipo] || 'badge-info'}">${esc(DCC_TIPO_LABEL[c.tipo] || c.tipo)}</span>`;

  const naturaleza = c.naturaleza === 'deudora'
    ? `<span style="color:#93c5fd;font-weight:600">D</span>`
    : `<span style="color:#f5a8a8;font-weight:600">A</span>`;

  const imputable = Number(c.imputable) === 1
    ? `<span style="color:var(--success)">✓</span>`
    : `<span style="color:var(--muted)">—</span>`;

  const activa = Number(c.activa) === 1
    ? `<span style="color:var(--success);font-size:.78rem">● Activa</span>`
    : `<span style="color:var(--muted);font-size:.78rem">○ Inactiva</span>`;

  const boldNombre = Number(c.imputable) === 0 ? 'font-weight:700' : '';

  const saldoVal   = parseFloat(c.saldo || 0);
  const saldoColor = saldoVal > 0 ? 'var(--success)' : saldoVal < 0 ? 'var(--danger)' : 'var(--muted)';
  const saldoHtml  = `<span style="font-family:monospace;font-size:.85rem;font-weight:600;color:${saldoColor}">${saldoVal < 0 ? '-' : ''}$ ${dccFmtMoney(Math.abs(saldoVal))}</span>`;

  return `
    <tr data-id="${c.id}" class="row-clickable">
      <td><span style="display:inline-block;margin-left:${indent}px">${toggle} <code style="font-size:.82rem">${esc(c.codigo)}</code></span></td>
      <td style="${boldNombre}">${esc(c.nombre)}</td>
      <td>${tipoBadge}</td>
      <td style="text-align:center">${naturaleza}</td>
      <td style="text-align:center">${imputable}</td>
      <td style="text-align:center">${activa}</td>
      <td style="text-align:right">${saldoHtml}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `;
}

function dccExpandirTodo(expandir) {
  if (expandir) {
    dccColapsadas.clear();
  } else {
    const conHijos = new Set();
    dccCuentas.forEach((c) => { if (c.parent_id) conHijos.add(c.parent_id); });
    conHijos.forEach((id) => dccColapsadas.add(id));
  }
  renderDcc();
}

// ---- Modal Alta / Edición ----
function dccPoblarSelectPadre(sel, excludeId, seleccionado) {
  sel.innerHTML = `<option value="">— Sin padre (cuenta raíz) —</option>`;
  // Excluir la cuenta editada + sus descendientes (previene ciclos).
  const excluidos = new Set();
  if (excludeId) {
    excluidos.add(excludeId);
    let hubo = true;
    while (hubo) {
      hubo = false;
      dccCuentas.forEach((c) => {
        if (c.parent_id && excluidos.has(c.parent_id) && !excluidos.has(c.id)) {
          excluidos.add(c.id); hubo = true;
        }
      });
    }
  }
  dccCuentas.forEach((c) => {
    if (excluidos.has(c.id)) return;
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = `${c.codigo} — ${c.nombre}`;
    sel.appendChild(opt);
  });
  if (seleccionado != null) sel.value = String(seleccionado);
}

function abrirAltaEdicionDcc(id, parentIdPreseleccionado) {
  dccEditandoId = id;
  const editando = !!id;
  const c = editando ? dccCuentas.find((x) => x.id === id) : null;

  const titulo = editando ? 'Editar cuenta' : 'Nueva cuenta';

  openModal(`
    <div class="modal" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-title">${esc(titulo)}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label for="dccCodigo">Código *</label>
            <input type="text" id="dccCodigo" placeholder="Ej. 1.1.05" style="font-family:monospace"
                   autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="20">
          </div>
          <div class="form-group">
            <label for="dccNombre">Nombre *</label>
            <input type="text" id="dccNombre" placeholder="Nombre de la cuenta" maxlength="160">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dccTipo">Tipo *</label>
            <select id="dccTipo">
              <option value="activo">Activo</option>
              <option value="pasivo">Pasivo</option>
              <option value="patrimonio">Patrimonio</option>
              <option value="ingreso">Ingreso</option>
              <option value="egreso">Egreso</option>
            </select>
          </div>
          <div class="form-group">
            <label for="dccNaturaleza">Naturaleza *</label>
            <select id="dccNaturaleza">
              <option value="deudora">Deudora</option>
              <option value="acreedora">Acreedora</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label for="dccParent">Cuenta padre</label>
          <select id="dccParent"></select>
        </div>
        <div class="form-group">
          <label for="dccDescripcion">Descripción <span style="font-weight:400;color:var(--muted)">— opcional</span></label>
          <textarea id="dccDescripcion" rows="2" placeholder="Detalle u observaciones"></textarea>
        </div>
        <div class="form-group" style="display:flex;gap:18px;align-items:center">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" id="dccImputable" checked> Permite movimientos (imputable)
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" id="dccActiva" checked> Activa
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">Guardar</button>
      </div>
    </div>
  `);

  const sel = $('#dccParent');
  dccPoblarSelectPadre(sel, editando ? id : null, null);

  if (editando && c) {
    $('#dccCodigo').value      = c.codigo || '';
    $('#dccNombre').value      = c.nombre || '';
    $('#dccTipo').value        = c.tipo || 'activo';
    $('#dccNaturaleza').value  = c.naturaleza || 'deudora';
    $('#dccDescripcion').value = c.descripcion || '';
    $('#dccImputable').checked = Number(c.imputable) === 1;
    $('#dccActiva').checked    = Number(c.activa) === 1;
    sel.value = c.parent_id != null ? String(c.parent_id) : '';
  } else if (parentIdPreseleccionado) {
    // Nueva subcuenta: heredar tipo/naturaleza del padre para ahorrar clicks.
    const padre = dccCuentas.find((x) => x.id === parentIdPreseleccionado);
    if (padre) {
      sel.value = String(parentIdPreseleccionado);
      $('#dccTipo').value       = padre.tipo;
      $('#dccNaturaleza').value = padre.naturaleza;
    }
  }

  setTimeout(() => $('#dccCodigo')?.focus(), 50);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))   closeModal();
    if (ev.target.closest('[data-act="guardar"]')) guardarDcc();
  });
}

async function guardarDcc() {
  const codigo      = $('#dccCodigo').value.trim();
  const nombre      = $('#dccNombre').value.trim();
  const tipo        = $('#dccTipo').value;
  const naturaleza  = $('#dccNaturaleza').value;
  const parent_id   = $('#dccParent').value || null;
  const descripcion = $('#dccDescripcion').value.trim();
  const imputable   = $('#dccImputable').checked ? 1 : 0;
  const activa      = $('#dccActiva').checked ? 1 : 0;

  if (!codigo) { toast('El código es obligatorio', { error: true }); return; }
  if (!nombre) { toast('El nombre es obligatorio', { error: true }); return; }

  const body = { codigo, nombre, tipo, naturaleza, parent_id, descripcion, imputable, activa };

  // Empresa activa (contexto compartido). Solo aplica al alta; en edición el
  // endpoint conserva la empresa original y rechaza cambios.
  if (!dccEditandoId) {
    const empresaId = dcGetEmpresaId();
    if (!empresaId) { toast('Elegí una empresa antes de crear cuentas', { error: true }); return; }
    body.empresa_id = empresaId;
  }

  try {
    if (dccEditandoId) {
      await apiSend(`${DCC_API}?id=${dccEditandoId}`, 'PUT', body);
      toast('Cuenta actualizada');
    } else {
      await apiSend(DCC_API, 'POST', body);
      toast('Cuenta creada');
    }
    closeModal();
    dccEditandoId = null;
    await cargarDcc();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ---- Modal Consulta ----
async function abrirConsultaDcc(id) {
  const c = dccCuentas.find((x) => x.id === id);
  if (!c) return;
  const padre    = c.parent_id ? dccCuentas.find((x) => x.id === c.parent_id) : null;
  const padreStr = padre ? `${padre.codigo} — ${padre.nombre}` : '—';

  const saldoVal   = parseFloat(c.saldo || 0);
  const saldoColor = saldoVal > 0 ? 'var(--success)' : saldoVal < 0 ? 'var(--danger)' : 'var(--muted)';
  const saldoStr   = `${saldoVal < 0 ? '-' : ''}$ ${dccFmtMoney(Math.abs(saldoVal))}`;

  openModal(`
    <div class="modal" style="max-width:520px">
      <div class="modal-header">
        <div class="modal-title">
          <code style="font-family:monospace">${esc(c.codigo)}</code>
          <span class="modal-subtitle">${esc(c.nombre)}</span>
        </div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:20px">
        <div style="text-align:center;padding:24px 16px;background:var(--bg);border-radius:12px;border:1px solid var(--border)">
          <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:8px">Saldo actual</div>
          <div style="font-size:2.4rem;font-weight:800;font-family:monospace;letter-spacing:-.02em;color:${saldoColor}">${esc(saldoStr)}</div>
          <div style="font-size:.75rem;color:var(--muted);margin-top:6px">Nivel ${c.nivel}</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Código</div>
            <div style="font-family:monospace;font-weight:700;font-size:1rem">${esc(c.codigo)}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Tipo</div>
            <div>${`<span class="badge ${DCC_TIPO_BADGE[c.tipo] || 'badge-info'}">${esc(DCC_TIPO_LABEL[c.tipo] || c.tipo)}</span>`}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Naturaleza</div>
            <div>${c.naturaleza === 'deudora' ? 'Deudora' : 'Acreedora'}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Estado</div>
            <div>${Number(c.activa) === 1 ? 'Activa' : 'Inactiva'}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Imputable</div>
            <div>${Number(c.imputable) === 1 ? 'Sí' : 'No (agrupación)'}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Cuenta padre</div>
            <div style="font-size:.85rem">${esc(padreStr)}</div>
          </div>
        </div>

        <div>
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Nombre completo</div>
          <div style="font-weight:600;font-size:1rem">${esc(c.nombre)}</div>
        </div>

        <div>
          <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Descripción</div>
          <div style="color:var(--muted);font-size:.9rem;line-height:1.5">${esc(c.descripcion || '—')}</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDcc(id, null); }
  });
}

async function eliminarDcc(id) {
  const c = dccCuentas.find((x) => x.id === id);
  if (!c) return;
  const ok = await confirmar({
    title:       'Eliminar cuenta',
    message:     `¿Eliminás la cuenta "${c.codigo} — ${c.nombre}"?`,
    confirmText: 'Eliminar',
    danger:      true,
  });
  if (!ok) return;
  try {
    await apiSend(`${DCC_API}?id=${id}`, 'DELETE');
    toast('Cuenta eliminada');
    await cargarDcc();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Datacount > Empresas -------------------------
// ABM de empresas para las que Datacount lleva la contabilidad. Cada fila
// representa una entidad con sus datos identificatorios (nombre de fantasía,
// razón social, domicilio) y fiscales (condición ante AFIP, CUIT, IIBB,
// inicio de actividades). Listado + toolbar mínima con buscador rápido +
// modal de filtros según ABM.md.

const DCE_API = 'api/datacountempresas.php';

const DCE_CONDICIONES = [
  { v: 'responsable_inscripto', label: 'Responsable Inscripto', badge: 'badge-info'    },
  { v: 'monotributista',        label: 'Monotributista',        badge: 'badge-success' },
  { v: 'exento',                label: 'Exento',                badge: 'badge-warn'    },
  { v: 'consumidor_final',      label: 'Consumidor Final',      badge: 'badge-info'    },
  { v: 'no_responsable',        label: 'No Responsable',        badge: 'badge-warn'    },
  { v: 'no_categorizado',       label: 'No Categorizado',       badge: 'badge-danger'  },
];
const DCE_CONDICION_MAP = Object.fromEntries(DCE_CONDICIONES.map((c) => [c.v, c]));

let dceItems           = [];
let dceBusqueda        = '';
let dceFiltroCodigo    = '';
let dceFiltroCondicion = '';
let dceFiltroLimite    = 100;
let dceFiltroOrden     = 'id';
let dceFiltroDir       = 'desc';
let dceEditandoId      = null;
let dceBuscadorTimer   = null;
let dceFiltrosSnapshot = null;

function dceFmtCuit(cuit) {
  if (!cuit) return '—';
  const s = String(cuit).replace(/\D+/g, '');
  if (s.length === 11) return `${s.slice(0, 2)}-${s.slice(2, 10)}-${s.slice(10)}`;
  return cuit;
}

function dceFmtFecha(iso) {
  if (!iso) return '—';
  const p = String(iso).slice(0, 10).split('-');
  if (p.length !== 3) return iso;
  return `${p[2]}/${p[1]}/${p[0]}`;
}

function dceCondicionLabel(v) { return DCE_CONDICION_MAP[v]?.label || v || '—'; }
function dceCondicionBadge(v) {
  const c = DCE_CONDICION_MAP[v];
  if (!c) return `<span class="badge">${esc(v || '—')}</span>`;
  return `<span class="badge ${c.badge}">${esc(c.label)}</span>`;
}

route('/datacountempresas', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">🏢</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las empresas son las entidades para las que Datacount lleva la contabilidad:
          cada fila reúne el nombre de fantasía, la razón social, la condición fiscal
          ante AFIP, CUIT, IIBB, domicilio y fecha de inicio de actividades.
        </div>
      </div>

      <div class="stats-bar" id="dceStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value orange" id="dceStatTotal">—</span></div>
        <div class="stat-card"><span class="stat-label">Resp. Inscripto</span><span class="stat-value" style="color:#93c5fd" id="dceStatRI">—</span></div>
        <div class="stat-card"><span class="stat-label">Monotributistas</span><span class="stat-value green" id="dceStatMono">—</span></div>
        <div class="stat-card"><span class="stat-label">Exentos</span><span class="stat-value" style="color:#fcd34d" id="dceStatExento">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="dceSearch"
                   placeholder="🔍 Buscar nombre, razón, CUIT o domicilio…">
            <button class="search-clear" id="dceSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dceFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dceFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dceRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dceNuevoBtn">+ Nueva empresa</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:80px">Código</th>
              <th>Nombre</th>
              <th>Razón social</th>
              <th style="width:180px">Condición</th>
              <th style="width:140px">CUIT</th>
              <th style="width:120px">Inicio</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dceTbody">
            <tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="dceCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md) -->
    <div class="modal-backdrop" id="filtrosDceBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDce()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDce()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDceCodigo" min="1" placeholder="ID …"
                     oninput="onFiltroDce('codigo', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Condición fiscal</label>
            <div id="fDceCondChips" style="display:flex;gap:6px;flex-wrap:wrap"></div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDceLimite" min="1" max="1000" value="100"
                     onchange="onFiltroDce('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDceOrden" onchange="onFiltroDce('orden', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="razon">Razón social</option>
                <option value="cuit">CUIT</option>
                <option value="inicio">Inicio</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDceDir" onchange="onFiltroDce('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDce()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDce()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDce()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  const inp = $('#dceSearch');
  const clr = $('#dceSearchClear');
  inp.value = dceBusqueda;
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dceBusqueda = inp.value.trim();
    clearTimeout(dceBuscadorTimer);
    dceBuscadorTimer = setTimeout(cargarDce, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = ''; clr.style.display = 'none'; dceBusqueda = ''; cargarDce();
  });

  $('#dceFiltrosBtn').addEventListener('click', abrirModalFiltrosDce);
  $('#dceRefrescarBtn').addEventListener('click', cargarDce);
  $('#dceNuevoBtn').addEventListener('click', () => abrirAltaEdicionDce(null));

  // Chips de condición dentro del modal.
  const chipsCont = $('#fDceCondChips');
  chipsCont.innerHTML = `
    <button type="button" class="filter-chip" data-cond="">Todas</button>
    ${DCE_CONDICIONES.map((c) => `
      <button type="button" class="filter-chip" data-cond="${c.v}">${esc(c.label)}</button>
    `).join('')}
  `;
  chipsCont.addEventListener('click', (ev) => {
    const b = ev.target.closest('.filter-chip');
    if (!b) return;
    dceFiltroCondicion = b.dataset.cond || '';
    dceSincronizarChipsCondicion();
    dceActualizarBadgeFiltros();
    cargarDce();
  });

  // Menú contextual + interacción con la fila
  $('#dceCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultaDce(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDce(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDce(data.id);
  });

  $('#dceTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dceCtxMenu'), r.right - 200, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultaDce(Number(tr.dataset.id));
  });
  $('#dceTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dceCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  dceActualizarBadgeFiltros();
  await cargarDce();
}, 'Empresas');

async function cargarDce() {
  const tbody = $('#dceTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  if (dceBusqueda)        qs.set('q', dceBusqueda);
  if (dceFiltroCondicion) qs.set('condicion', dceFiltroCondicion);
  if (dceFiltroCodigo)    qs.set('id', dceFiltroCodigo);
  if (dceFiltroLimite)    qs.set('limite', dceFiltroLimite);
  if (dceFiltroOrden)     qs.set('orden', dceFiltroOrden);
  if (dceFiltroDir)       qs.set('dir', dceFiltroDir);

  try {
    const data = await apiGet(DCE_API + (qs.toString() ? '?' + qs.toString() : ''));
    dceItems = data.items || [];
    pintarStatsDce(data.stats || {});
    renderDce();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDce(s) {
  $('#dceStatTotal').textContent  = fmtNum(s.total ?? dceItems.length);
  $('#dceStatRI').textContent     = fmtNum(s.responsable_inscripto ?? 0);
  $('#dceStatMono').textContent   = fmtNum(s.monotributista ?? 0);
  $('#dceStatExento').textContent = fmtNum(s.exento ?? 0);
}

function renderDce() {
  const tbody = $('#dceTbody');
  if (!tbody) return;
  if (!dceItems.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin empresas registradas.</td></tr>`;
    return;
  }

  // Filtro cliente por Código (el buscador rápido y condicion los resuelve el server).
  let filas = dceItems;
  if (dceFiltroCodigo) {
    const cod = Number(dceFiltroCodigo);
    filas = filas.filter((e) => e.id === cod);
  }

  if (!filas.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin resultados con los filtros actuales.</td></tr>`;
    return;
  }

  tbody.innerHTML = filas.map((e) => `
    <tr data-id="${e.id}" class="row-clickable">
      <td><code style="font-size:.82rem">${e.id}</code></td>
      <td style="font-weight:600">${esc(e.nombre)}</td>
      <td style="color:var(--muted)">${esc(e.razon)}</td>
      <td>${dceCondicionBadge(e.condicion)}</td>
      <td style="font-family:monospace;font-size:.85rem">${esc(dceFmtCuit(e.cuit))}</td>
      <td>${esc(dceFmtFecha(e.inicio))}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${e.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de filtros ----
function abrirModalFiltrosDce() {
  dceFiltrosSnapshot = {
    codigo:    dceFiltroCodigo,
    condicion: dceFiltroCondicion,
    limite:    dceFiltroLimite,
    orden:     dceFiltroOrden,
    dir:       dceFiltroDir,
  };
  $('#fDceCodigo').value = dceFiltroCodigo || '';
  $('#fDceLimite').value = dceFiltroLimite || 100;
  $('#fDceOrden').value  = dceFiltroOrden  || 'id';
  $('#fDceDir').value    = dceFiltroDir    || 'desc';
  dceSincronizarChipsCondicion();
  document.getElementById('filtrosDceBackdrop').classList.add('open');
}

function cerrarModalFiltrosDce() {
  document.getElementById('filtrosDceBackdrop').classList.remove('open');
}

function cancelarFiltrosDce() {
  if (dceFiltrosSnapshot) {
    dceFiltroCodigo    = dceFiltrosSnapshot.codigo;
    dceFiltroCondicion = dceFiltrosSnapshot.condicion;
    dceFiltroLimite    = dceFiltrosSnapshot.limite;
    dceFiltroOrden     = dceFiltrosSnapshot.orden;
    dceFiltroDir       = dceFiltrosSnapshot.dir;
    dceActualizarBadgeFiltros();
    cargarDce();
  }
  cerrarModalFiltrosDce();
}

function limpiarFiltrosDce() {
  dceFiltroCodigo    = '';
  dceFiltroCondicion = '';
  dceFiltroLimite    = 100;
  dceFiltroOrden     = 'id';
  dceFiltroDir       = 'desc';
  $('#fDceCodigo').value = '';
  $('#fDceLimite').value = 100;
  $('#fDceOrden').value  = 'id';
  $('#fDceDir').value    = 'desc';
  dceSincronizarChipsCondicion();
  dceActualizarBadgeFiltros();
  cargarDce();
}

function onFiltroDce(campo, valor) {
  if (campo === 'codigo') dceFiltroCodigo = (valor || '').trim();
  if (campo === 'limite') dceFiltroLimite = Math.max(1, Math.min(1000, Number(valor) || 100));
  if (campo === 'orden')  dceFiltroOrden  = valor || 'id';
  if (campo === 'dir')    dceFiltroDir    = valor || 'desc';
  dceActualizarBadgeFiltros();
  cargarDce();
}

function dceSincronizarChipsCondicion() {
  const chips = document.querySelectorAll('#fDceCondChips .filter-chip');
  chips.forEach((b) => {
    b.classList.toggle('active', (b.dataset.cond || '') === (dceFiltroCondicion || ''));
  });
}

function dceActualizarBadgeFiltros() {
  let n = 0;
  if (dceFiltroCodigo)                  n++;
  if (dceFiltroCondicion)               n++;
  if (Number(dceFiltroLimite) !== 100)  n++;
  if (dceFiltroOrden !== 'id')          n++;
  if (dceFiltroDir   !== 'desc')        n++;
  const badge = $('#dceFiltrosBadge');
  const btn   = $('#dceFiltrosBtn');
  if (!badge || !btn) return;
  if (n > 0) {
    badge.style.display = '';
    badge.textContent   = n;
    btn.classList.add('active');
  } else {
    badge.style.display = 'none';
    btn.classList.remove('active');
  }
}

// ---- Modal Alta / Edición ----
function abrirAltaEdicionDce(id) {
  dceEditandoId = id;
  const editando = !!id;
  const e = editando ? dceItems.find((x) => x.id === id) : null;
  const titulo = editando ? 'Editar empresa' : 'Nueva empresa';

  const opciones = DCE_CONDICIONES.map((c) =>
    `<option value="${c.v}">${esc(c.label)}</option>`
  ).join('');

  openModal(`
    <div class="modal" style="max-width:640px">
      <div class="modal-header">
        <div class="modal-title">${esc(titulo)}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label for="dceNombre">Nombre *</label>
            <input type="text" id="dceNombre" placeholder="Nombre de fantasía" maxlength="160" autocomplete="off">
          </div>
          <div class="form-group">
            <label for="dceRazon">Razón social *</label>
            <input type="text" id="dceRazon" placeholder="Razón social completa" maxlength="200" autocomplete="off">
          </div>
        </div>
        <div class="form-group">
          <label for="dceDomicilio">Domicilio</label>
          <input type="text" id="dceDomicilio" placeholder="Calle, número, ciudad, provincia" maxlength="255" autocomplete="off">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dceCondicion">Condición fiscal *</label>
            <select id="dceCondicion">${opciones}</select>
          </div>
          <div class="form-group">
            <label for="dceCuit">CUIT</label>
            <input type="text" id="dceCuit" placeholder="20123456789 o 20-12345678-9" maxlength="15"
                   style="font-family:monospace" autocomplete="off">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dceIibb">IIBB</label>
            <input type="text" id="dceIibb" placeholder="Nº de Ingresos Brutos" maxlength="30" autocomplete="off">
          </div>
          <div class="form-group">
            <label for="dceInicio">Inicio de actividades</label>
            <input type="date" id="dceInicio">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">Guardar</button>
      </div>
    </div>
  `);

  if (editando && e) {
    $('#dceNombre').value    = e.nombre    || '';
    $('#dceRazon').value     = e.razon     || '';
    $('#dceDomicilio').value = e.domicilio || '';
    $('#dceCondicion').value = e.condicion || 'responsable_inscripto';
    $('#dceCuit').value      = e.cuit      || '';
    $('#dceIibb').value      = e.iibb      || '';
    $('#dceInicio').value    = e.inicio    ? String(e.inicio).slice(0, 10) : '';
  } else {
    $('#dceCondicion').value = 'responsable_inscripto';
  }

  setTimeout(() => $('#dceNombre')?.focus(), 50);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))   closeModal();
    if (ev.target.closest('[data-act="guardar"]')) guardarDce();
  });
}

async function guardarDce() {
  const nombre    = $('#dceNombre').value.trim();
  const razon     = $('#dceRazon').value.trim();
  const domicilio = $('#dceDomicilio').value.trim();
  const condicion = $('#dceCondicion').value;
  const cuit      = $('#dceCuit').value.trim();
  const iibb      = $('#dceIibb').value.trim();
  const inicio    = $('#dceInicio').value || '';

  if (!nombre) { toast('El nombre es obligatorio', { error: true }); return; }
  if (!razon)  { toast('La razón social es obligatoria', { error: true }); return; }

  const body = { nombre, razon, domicilio, condicion, cuit, iibb, inicio };

  try {
    if (dceEditandoId) {
      await apiSend(`${DCE_API}?id=${dceEditandoId}`, 'PUT', body);
      toast('Empresa actualizada');
    } else {
      await apiSend(DCE_API, 'POST', body);
      toast('Empresa creada');
    }
    closeModal();
    dceEditandoId = null;
    await cargarDce();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ---- Modal Consulta ----
function abrirConsultaDce(id) {
  const e = dceItems.find((x) => x.id === id);
  if (!e) return;

  const card = (label, valor, ancho) => `
    <div style="flex:${ancho === 'full' ? '1 1 100%' : '1 1 calc(50% - 6px)'};
                background:color-mix(in srgb, var(--surface) 90%, #000);
                border:none;border-radius:12px;padding:12px 14px">
      <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">${esc(label)}</div>
      <div style="font-size:.92rem">${valor}</div>
    </div>
  `;

  openModal(`
    <div class="modal" style="max-width:620px">
      <div class="modal-header">
        <div class="modal-title">
          🏢 <span class="modal-subtitle">${esc(e.nombre)}</span>
        </div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="display:flex;flex-wrap:wrap;gap:12px">
          ${card('Código',            `<code>${e.id}</code>`)}
          ${card('Condición fiscal',  dceCondicionBadge(e.condicion))}
          ${card('Nombre',            esc(e.nombre), 'full')}
          ${card('Razón social',      esc(e.razon), 'full')}
          ${card('Domicilio',         esc(e.domicilio || '—'), 'full')}
          ${card('CUIT',              `<span style="font-family:monospace">${esc(dceFmtCuit(e.cuit))}</span>`)}
          ${card('IIBB',              `<span style="font-family:monospace">${esc(e.iibb || '—')}</span>`)}
          ${card('Inicio actividades', esc(dceFmtFecha(e.inicio)))}
          ${card('Alta',               esc(fmtFecha(e.created_at)))}
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDce(id); }
  });
}

async function eliminarDce(id) {
  const e = dceItems.find((x) => x.id === id);
  if (!e) return;
  const ok = await confirmar({
    title:       'Eliminar empresa',
    message:     `¿Eliminás la empresa "${e.nombre}" (${e.razon})?`,
    confirmText: 'Eliminar',
    danger:      true,
  });
  if (!ok) return;
  try {
    await apiSend(`${DCE_API}?id=${id}`, 'DELETE');
    toast('Empresa eliminada');
    await cargarDce();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ------------------------- Vista: Datacount > Asientos -------------------------
// Asientos contables (misma UI que `repo.cloud > contable > asientos`): listado
// con sub-líneas del detalle, filtros fecha/cuenta/texto, modal de alta/edición
// con líneas (Debe/Haber) y picker jerárquico de cuentas del plan. Cada asiento
// queda asociado a una empresa (misma relación que `datacount_cuentas` y
// `datacount_recurrentes`); el listado filtra por la empresa activa del
// contexto compartido de Datacount.

const DCA_API = 'api/datacountasientos.php';

let dcaAsientos           = [];
let dcaBusqueda           = '';
let dcaFiltroDesde        = '';
let dcaFiltroHasta        = '';
let dcaFiltroCuentaId     = null;
let dcaFiltroCuentaNombre = '';
let dcaBuscadorTimer      = null;

let dcaEditandoId         = null;
let dcaEditandoEmpresaId  = null;  // empresa objetivo del modal alta/edición
let dcaLineas             = [];    // [{cuenta_id, debe, haber, descripcion}]
let dcaCuentasImputables  = [];    // solo imputables+activas — de la empresa cacheada
let dcaTodasCuentas       = [];    // árbol completo (picker) — de la empresa cacheada
let dcaCuentasCacheEmp    = null;  // empresa a la que corresponde el cache
let dcaPickerLineaIdx     = null;
let dcaPickerColapsadas   = new Set();
let dcaPickerBusqueda     = '';

function dcaFmtMoney(n) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function dcaFmtFechaAR(iso) {
  if (!iso) return '—';
  const p = String(iso).slice(0, 10).split('-');
  if (p.length !== 3) return iso;
  return `${p[2]}/${p[1]}/${p[0]}`;
}

route('/datacountasientos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help">
        <div class="module-help-icon">📖</div>
        <div class="module-help-text">
          Los asientos son los movimientos contables de Datacount. Cada asiento agrupa
          dos o más líneas contra cuentas del plan (Debe/Haber) y debe balancear.
          Los saldos del plan de cuentas se recalculan automáticamente al guardar o eliminar.
        </div>
      </div>

      <div class="stats-bar">
        <div class="stat-card"><span class="stat-label">Total asientos</span><span class="stat-value orange" id="dcaStatTotal">—</span></div>
        <div class="stat-card"><span class="stat-label">Del mes</span><span class="stat-value" style="color:#93c5fd" id="dcaStatMes">—</span></div>
        <div class="stat-card"><span class="stat-label">Monto acumulado</span><span class="stat-value green" id="dcaStatMonto">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <select id="dcaEmpresaSel" style="min-width:200px" title="Empresa">
            <option value="">— Cargando empresas… —</option>
          </select>
          <div class="search-wrap">
            <input type="search" class="search-input" id="dcaSearch"
                   placeholder="🔍 Buscar por nº o descripción…">
            <button class="search-clear" id="dcaSearchClear" style="display:none">×</button>
          </div>
          <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
            Desde <input type="date" id="dcaDesde" style="min-width:140px">
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
            Hasta <input type="date" id="dcaHasta" style="min-width:140px">
          </label>
          <div id="dcaFiltroCtaBadge"
               style="display:none;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:.8rem">
            Cuenta: <strong id="dcaFiltroCtaTexto"></strong>
            <span onclick="dcaLimpiarFiltroCuenta()" style="cursor:pointer;font-weight:700;color:var(--muted)" title="Quitar filtro">✕</span>
          </div>
          <button class="btn btn-ghost btn-icon" id="dcaRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dcaNuevoBtn">+ Nuevo asiento</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:80px">N°</th>
              <th style="width:120px">Fecha</th>
              <th>Descripción</th>
              <th style="width:140px;text-align:right">Total</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dcaTbody">
            <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="dcaCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="ver" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Ver detalle</span>
      </button>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>
  `;

  const inp = $('#dcaSearch');
  const clr = $('#dcaSearchClear');
  inp.value = dcaBusqueda;
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dcaBusqueda = inp.value.trim();
    clearTimeout(dcaBuscadorTimer);
    dcaBuscadorTimer = setTimeout(cargarDca, 300);
  });
  clr.addEventListener('click', () => {
    inp.value = ''; clr.style.display = 'none'; dcaBusqueda = ''; cargarDca();
  });

  // Selector de empresa (contexto compartido con otros módulos Datacount).
  const selEmp = $('#dcaEmpresaSel');
  const empresas = await dcGetEmpresas();
  const empresaId = await dcAsegurarEmpresaId();
  if (empresas.length) {
    selEmp.innerHTML = empresas.map((e) =>
      `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    selEmp.value = String(empresaId || empresas[0].id);
  } else {
    selEmp.innerHTML = `<option value="">— Sin empresas —</option>`;
    selEmp.disabled = true;
  }
  selEmp.addEventListener('change', (ev) => {
    dcSetEmpresaId(ev.target.value);
    // Cambió la empresa: invalidar cache de cuentas (era del contexto anterior)
    // y limpiar el filtro por cuenta (los ids ya no aplican).
    dcaCuentasImputables = [];
    dcaTodasCuentas      = [];
    dcaCuentasCacheEmp   = null;
    dcaFiltroCuentaId    = null;
    dcaFiltroCuentaNombre = '';
    dcaActualizarBadgeFiltroCuenta();
    cargarDca();
  });

  $('#dcaDesde').value = dcaFiltroDesde;
  $('#dcaHasta').value = dcaFiltroHasta;
  $('#dcaDesde').addEventListener('change', (ev) => { dcaFiltroDesde = ev.target.value; cargarDca(); });
  $('#dcaHasta').addEventListener('change', (ev) => { dcaFiltroHasta = ev.target.value; cargarDca(); });

  $('#dcaRefrescarBtn').addEventListener('click', cargarDca);
  $('#dcaNuevoBtn').addEventListener('click', () => abrirNuevoAsientoDca());

  $('#dcaCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'ver')      abrirDetalleAsientoDca(data.id);
    if (b.dataset.action === 'editar')   abrirEditarAsientoDca(data.id);
    if (b.dataset.action === 'eliminar') eliminarAsientoDca(data.id, data.numero);
  });

  $('#dcaTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const numero = Number(ham.dataset.numero);
      const r = ham.getBoundingClientRect();
      abrirCtxMenu($('#dcaCtxMenu'), r.right - 200, r.bottom + 4, { id, numero });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirDetalleAsientoDca(Number(tr.dataset.id));
  });
  $('#dcaTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dcaCtxMenu'), ev.clientX, ev.clientY, {
      id: Number(tr.dataset.id),
      numero: Number(tr.dataset.numero),
    });
  });

  dcaActualizarBadgeFiltroCuenta();
  await cargarDca();
}, 'Asientos');

function dcaActualizarBadgeFiltroCuenta() {
  const badge = $('#dcaFiltroCtaBadge');
  if (!badge) return;
  if (dcaFiltroCuentaId) {
    $('#dcaFiltroCtaTexto').textContent = dcaFiltroCuentaNombre;
    badge.style.display = 'flex';
  } else {
    badge.style.display = 'none';
  }
}

function dcaLimpiarFiltroCuenta() {
  dcaFiltroCuentaId     = null;
  dcaFiltroCuentaNombre = '';
  dcaActualizarBadgeFiltroCuenta();
  cargarDca();
}
window.dcaLimpiarFiltroCuenta = dcaLimpiarFiltroCuenta;

async function cargarDca() {
  const tbody = $('#dcaTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const empresaId = await dcAsegurarEmpresaId();
  if (!empresaId) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">No hay empresas registradas — creá una antes de dar de alta asientos.</td></tr>`;
    pintarStatsDca({});
    return;
  }

  const qs = new URLSearchParams();
  qs.set('empresa', String(empresaId));
  if (dcaBusqueda)       qs.set('q', dcaBusqueda);
  if (dcaFiltroDesde)    qs.set('desde', dcaFiltroDesde);
  if (dcaFiltroHasta)    qs.set('hasta', dcaFiltroHasta);
  if (dcaFiltroCuentaId) qs.set('cuenta_id', dcaFiltroCuentaId);

  try {
    const data = await apiGet(DCA_API + '?' + qs.toString());
    dcaAsientos = data.items || [];
    pintarStatsDca(data.stats || {});
    renderDca();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDca(s) {
  $('#dcaStatTotal').textContent = fmtNum(s.total ?? dcaAsientos.length);
  $('#dcaStatMes').textContent   = fmtNum(s.del_mes ?? 0);
  $('#dcaStatMonto').textContent = '$ ' + dcaFmtMoney(s.monto || 0);
}

function renderDca() {
  const tbody = $('#dcaTbody');
  if (!tbody) return;
  if (!dcaAsientos.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">No hay asientos registrados.</td></tr>`;
    return;
  }
  tbody.innerHTML = dcaAsientos.map(renderFilaAsientoDca).join('');
}

function renderFilaAsientoDca(a) {
  const fecha = dcaFmtFechaAR(a.fecha);

  let subFilas = '';
  if (a.detalle && a.detalle.length) {
    subFilas = a.detalle.map((d) => {
      const cuenta = (d.cuenta_codigo
                        ? `<code style="font-size:.72rem;color:var(--muted)">${esc(d.cuenta_codigo)}</code> `
                        : '') + esc(d.cuenta_nombre || '—');
      const esDebe = Number(d.debe) > 0;
      const importeColor = esDebe ? '#93c5fd' : '#f5a8a8';
      const tipoColor    = esDebe ? '#93c5fd' : '#f5a8a8';
      const importe = esDebe
        ? `<span style="color:${importeColor}">$ ${dcaFmtMoney(d.debe)}</span>`
        : `<span style="color:${importeColor}">$ ${dcaFmtMoney(d.haber)}</span>`;
      const tipo = esDebe
        ? `<span style="color:${tipoColor};font-weight:700;font-size:.68rem;letter-spacing:.5px">DEBE</span>`
        : `<span style="color:${tipoColor};font-weight:700;font-size:.68rem;letter-spacing:.5px">HABER</span>`;
      const det = d.descripcion
        ? ` <span style="color:var(--muted);font-size:.78rem">— ${esc(d.descripcion)}</span>`
        : '';
      return `
        <div style="display:flex;align-items:center;gap:10px;padding:3px 0;font-size:.82rem">
          <span style="width:52px;flex-shrink:0">${tipo}</span>
          <span style="flex:1;min-width:0">${cuenta}${det}</span>
          <span style="width:120px;text-align:right;font-weight:600">${importe}</span>
        </div>`;
    }).join('');
  }

  const main = `
    <tr data-id="${a.id}" data-numero="${a.numero}" class="row-clickable" style="border-bottom:none">
      <td><strong>#${a.numero}</strong></td>
      <td>${esc(fecha)}</td>
      <td>${esc(a.descripcion || '')}</td>
      <td style="text-align:right;font-weight:600">$ ${dcaFmtMoney(a.total)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${a.id}" data-numero="${a.numero}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>`;

  const sub = subFilas
    ? `<tr data-id="${a.id}" data-numero="${a.numero}" class="row-clickable">
         <td></td>
         <td colspan="4" style="padding-top:0;padding-bottom:12px">
           <div style="border-left:3px solid var(--border);padding:2px 0 2px 12px;margin-left:4px">${subFilas}</div>
         </td>
       </tr>`
    : '';
  return main + sub;
}

// ---- Cuentas del plan (para alta/edición) ----
// Cachea el plan por empresa. El picker jerárquico y las líneas de detalle
// solo pueden elegir cuentas de la empresa activa del asiento.
async function dcaAsegurarCuentas(empresaId) {
  if (dcaCuentasImputables.length && dcaCuentasCacheEmp === empresaId) return;
  try {
    const d = await apiGet(`${DCC_API}?empresa_id=${empresaId}`);
    dcaTodasCuentas = d.items || [];
    dcaCuentasImputables = dcaTodasCuentas.filter(
      (c) => Number(c.imputable) === 1 && Number(c.activa) === 1
    );
    dcaCuentasCacheEmp = empresaId;
  } catch (_) {
    dcaTodasCuentas      = [];
    dcaCuentasImputables = [];
    dcaCuentasCacheEmp   = empresaId;
    // el modal muestra un aviso más abajo
  }
}

// ---- Modal Alta / Edición ----
function dcaAbrirModalAlta(tituloText, empresaObj) {
  const empresaHtml = empresaObj
    ? `🏢 ${esc(empresaObj.nombre)}`
    : (dcaEditandoEmpresaId ? `🏢 #${dcaEditandoEmpresaId}` : '—');
  openModal(`
    <div class="modal modal-wide" style="max-width:960px">
      <div class="modal-header">
        <div class="modal-title" id="dcaModalTitulo">${esc(tituloText)}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Empresa</label>
          <div style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-weight:600">
            ${empresaHtml}
          </div>
        </div>
        <div class="form-row" style="grid-template-columns:180px 1fr">
          <div class="form-group">
            <label for="dcaFecha">Fecha *</label>
            <input type="date" id="dcaFecha">
          </div>
          <div class="form-group">
            <label for="dcaDescripcion">Descripción *</label>
            <input type="text" id="dcaDescripcion" placeholder="Ej: Venta del día — cobro en efectivo" maxlength="255">
          </div>
        </div>

        <div style="margin-top:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <label style="margin:0">Líneas del asiento *</label>
            <button type="button" class="btn btn-ghost btn-sm" id="dcaAgregarLineaBtn">+ Agregar línea</button>
          </div>
          <div class="table-card" style="margin-top:0">
            <table style="font-size:.88rem">
              <thead>
                <tr>
                  <th style="width:38%">Cuenta</th>
                  <th style="width:140px;text-align:right">Debe</th>
                  <th style="width:140px;text-align:right">Haber</th>
                  <th>Detalle</th>
                  <th style="width:40px"></th>
                </tr>
              </thead>
              <tbody id="dcaLineasBody"></tbody>
              <tfoot>
                <tr style="font-weight:700;background:var(--bg)">
                  <td style="text-align:right">Totales:</td>
                  <td style="text-align:right" id="dcaTotalDebe">0,00</td>
                  <td style="text-align:right" id="dcaTotalHaber">0,00</td>
                  <td colspan="2" id="dcaBalance"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">Guardar</button>
      </div>
    </div>
  `);

  $('#dcaAgregarLineaBtn').addEventListener('click', dcaAgregarLinea);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))   { closeModal(); dcaEditandoId = null; dcaEditandoEmpresaId = null; dcaLineas = []; }
    if (ev.target.closest('[data-act="guardar"]')) guardarAsientoDca();
  });

  // Delegación para inputs y botones de línea (evita ensuciar `window`).
  $('#dcaLineasBody').addEventListener('click', (ev) => {
    const pick = ev.target.closest('[data-act="picker"]');
    if (pick) { dcaAbrirPickerCuenta(Number(pick.dataset.idx)); return; }
    const clr = ev.target.closest('[data-act="clear-cuenta"]');
    if (clr)  { ev.stopPropagation(); dcaUpdateLinea(Number(clr.dataset.idx), 'cuenta_id', ''); return; }
    const del = ev.target.closest('[data-act="del-linea"]');
    if (del)  { dcaQuitarLinea(Number(del.dataset.idx)); return; }
  });
  $('#dcaLineasBody').addEventListener('input', (ev) => {
    const i = ev.target.closest('[data-idx]');
    if (!i) return;
    dcaUpdateLinea(Number(i.dataset.idx), i.dataset.campo, i.value);
  });
}

async function abrirNuevoAsientoDca() {
  const empresaId = await dcAsegurarEmpresaId();
  if (!empresaId) {
    toast('Elegí una empresa antes de crear asientos', { error: true });
    return;
  }
  await dcaAsegurarCuentas(empresaId);
  if (!dcaCuentasImputables.length) {
    toast('No hay cuentas imputables activas para esta empresa. Revisá el plan de cuentas.', { error: true });
    return;
  }
  dcaEditandoId        = null;
  dcaEditandoEmpresaId = empresaId;
  dcaLineas = [
    { cuenta_id: '', debe: '', haber: '', descripcion: '' },
    { cuenta_id: '', debe: '', haber: '', descripcion: '' },
  ];
  const empresas = await dcGetEmpresas();
  const empresaObj = empresas.find((e) => e.id === empresaId);
  dcaAbrirModalAlta('Nuevo asiento', empresaObj);
  const hoy = new Date().toISOString().slice(0, 10);
  $('#dcaFecha').value       = hoy;
  $('#dcaDescripcion').value = '';
  dcaRenderLineas();
  setTimeout(() => $('#dcaDescripcion')?.focus(), 50);
}

async function abrirEditarAsientoDca(id) {
  try {
    const a = await apiGet(`${DCA_API}?id=${id}`);
    // La empresa del asiento manda: cargamos el plan de esa empresa aunque
    // el usuario tenga otra seleccionada en el contexto compartido.
    const empresaId = Number(a.empresa_id) || (await dcAsegurarEmpresaId());
    await dcaAsegurarCuentas(empresaId);
    dcaEditandoId        = id;
    dcaEditandoEmpresaId = empresaId;
    dcaLineas = (a.detalle || []).map((d) => ({
      cuenta_id:   d.cuenta_id,
      debe:        Number(d.debe)  || '',
      haber:       Number(d.haber) || '',
      descripcion: d.descripcion || '',
    }));
    while (dcaLineas.length < 2) {
      dcaLineas.push({ cuenta_id: '', debe: '', haber: '', descripcion: '' });
    }
    const empresas = await dcGetEmpresas();
    const empresaObj = empresas.find((e) => e.id === empresaId);
    dcaAbrirModalAlta(`Editar asiento N° ${a.numero}`, empresaObj);
    $('#dcaFecha').value       = a.fecha || '';
    $('#dcaDescripcion').value = a.descripcion || '';
    dcaRenderLineas();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

function dcaRenderLineas() {
  const tbody = $('#dcaLineasBody');
  if (!tbody) return;
  if (!dcaLineas.length) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:14px">Sin líneas. Hacé clic en "+ Agregar línea".</td></tr>`;
    $('#dcaTotalDebe').textContent  = '0,00';
    $('#dcaTotalHaber').textContent = '0,00';
    $('#dcaBalance').innerHTML = '';
    return;
  }
  tbody.innerHTML = dcaLineas.map((l, i) => {
    const cs  = dcaCuentasImputables.find((c) => c.id === Number(l.cuenta_id));
    const lbl = cs ? `${esc(cs.codigo)} — ${esc(cs.nombre)}` : '— Seleccionar cuenta —';
    const lc  = cs ? '' : 'color:var(--muted)';
    const ico = cs
      ? `<span data-act="clear-cuenta" data-idx="${i}" style="flex-shrink:0;padding:0 2px;color:var(--muted);cursor:pointer" title="Quitar">✕</span>`
      : `<span style="flex-shrink:0;color:var(--muted)">▾</span>`;
    return `
      <tr>
        <td>
          <button type="button" data-act="picker" data-idx="${i}"
                  style="width:100%;text-align:left;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:5px 8px;cursor:pointer;font-size:.88rem;display:flex;align-items:center;gap:6px;min-height:32px;color:var(--text)">
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${lc}">${lbl}</span>${ico}
          </button>
        </td>
        <td><input type="number" step="0.01" min="0" data-idx="${i}" data-campo="debe"
                   value="${l.debe === '' ? '' : l.debe}" style="width:100%;text-align:right"></td>
        <td><input type="number" step="0.01" min="0" data-idx="${i}" data-campo="haber"
                   value="${l.haber === '' ? '' : l.haber}" style="width:100%;text-align:right"></td>
        <td><input type="text" data-idx="${i}" data-campo="descripcion"
                   value="${esc(l.descripcion || '')}" style="width:100%" placeholder="Detalle (opcional)"></td>
        <td><button type="button" class="btn-icon-sm" title="Eliminar línea"
                    data-act="del-linea" data-idx="${i}">🗑️</button></td>
      </tr>`;
  }).join('');
  dcaRecalcularTotales();
}

function dcaRecalcularTotales() {
  let totD = 0, totH = 0;
  dcaLineas.forEach((l) => { totD += Number(l.debe || 0); totH += Number(l.haber || 0); });
  $('#dcaTotalDebe').textContent  = dcaFmtMoney(totD);
  $('#dcaTotalHaber').textContent = dcaFmtMoney(totH);
  const diff = Math.abs(totD - totH);
  const bal = $('#dcaBalance');
  if (diff < 0.01 && totD > 0) {
    bal.innerHTML = `<span style="color:var(--success);font-weight:600">✓ Balanceado</span>`;
  } else if (totD === 0 && totH === 0) {
    bal.innerHTML = '';
  } else {
    bal.innerHTML = `<span style="color:var(--danger);font-weight:600">✗ Diferencia: $ ${dcaFmtMoney(diff)}</span>`;
  }
}

function dcaAgregarLinea() {
  dcaLineas.push({ cuenta_id: '', debe: '', haber: '', descripcion: '' });
  dcaRenderLineas();
}

function dcaQuitarLinea(i) {
  dcaLineas.splice(i, 1);
  dcaRenderLineas();
}

function dcaUpdateLinea(i, campo, valor) {
  if (!dcaLineas[i]) return;
  if (campo === 'debe' || campo === 'haber') {
    dcaLineas[i][campo] = valor === '' ? '' : Number(valor);
    if (campo === 'debe'  && Number(valor) > 0) dcaLineas[i].haber = '';
    if (campo === 'haber' && Number(valor) > 0) dcaLineas[i].debe  = '';
    dcaRenderLineas();
  } else if (campo === 'cuenta_id') {
    dcaLineas[i].cuenta_id = valor ? Number(valor) : '';
    dcaRenderLineas();
  } else {
    dcaLineas[i][campo] = valor;
    // Sin re-render — evita perder el foco del input mientras el usuario escribe.
  }
}

async function guardarAsientoDca() {
  const fecha       = $('#dcaFecha').value;
  const descripcion = $('#dcaDescripcion').value.trim();

  if (!fecha)       { toast('La fecha es obligatoria', { error: true }); return; }
  if (!descripcion) { toast('La descripción es obligatoria', { error: true }); return; }
  if (!dcaEditandoEmpresaId) { toast('Falta la empresa del asiento', { error: true }); return; }

  const detalle = dcaLineas
    .filter((l) => l.cuenta_id || Number(l.debe) > 0 || Number(l.haber) > 0)
    .map((l) => ({
      cuenta_id: l.cuenta_id ? Number(l.cuenta_id) : 0,
      debe:      Number(l.debe)  || 0,
      haber:     Number(l.haber) || 0,
      descripcion: l.descripcion || '',
    }));

  if (detalle.length < 2) { toast('Se requieren al menos 2 líneas con datos', { error: true }); return; }

  const body = { empresa: dcaEditandoEmpresaId, fecha, descripcion, detalle };
  try {
    if (dcaEditandoId) {
      await apiSend(`${DCA_API}?id=${dcaEditandoId}`, 'PUT', body);
      toast('Asiento actualizado');
    } else {
      await apiSend(DCA_API, 'POST', body);
      toast('Asiento creado');
    }
    closeModal();
    dcaEditandoId        = null;
    dcaEditandoEmpresaId = null;
    dcaLineas = [];
    // Invalidar cache local del plan de cuentas: los saldos cambian.
    dcaCuentasImputables = [];
    dcaTodasCuentas      = [];
    dcaCuentasCacheEmp   = null;
    await cargarDca();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ---- Modal Detalle ----
async function abrirDetalleAsientoDca(id) {
  try {
    const a = await apiGet(`${DCA_API}?id=${id}`);
    openModal(`
      <div class="modal" style="max-width:820px">
        <div class="modal-header">
          <div class="modal-title">Asiento N° ${a.numero}</div>
          <button class="btn-icon-sm" data-act="close">×</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
          <div class="form-row">
            <div>
              <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Fecha</div>
              <div style="font-weight:600">${esc(dcaFmtFechaAR(a.fecha))}</div>
            </div>
            <div>
              <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Total</div>
              <div style="font-weight:600;font-family:monospace">$ ${dcaFmtMoney(a.total)}</div>
            </div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">Descripción</div>
            <div style="font-weight:600">${esc(a.descripcion || '—')}</div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:6px">Detalle</div>
            <div class="table-card">
              <table style="font-size:.85rem">
                <thead>
                  <tr>
                    <th>Cuenta</th>
                    <th style="width:130px;text-align:right">Debe</th>
                    <th style="width:130px;text-align:right">Haber</th>
                    <th>Detalle</th>
                  </tr>
                </thead>
                <tbody>
                  ${(a.detalle || []).map((d) => `
                    <tr>
                      <td>${d.cuenta_codigo ? `<code style="font-size:.78rem;color:var(--muted)">${esc(d.cuenta_codigo)}</code> ` : ''}${esc(d.cuenta_nombre || '—')}</td>
                      <td style="text-align:right">${Number(d.debe)  > 0 ? '$ ' + dcaFmtMoney(d.debe)  : '—'}</td>
                      <td style="text-align:right">${Number(d.haber) > 0 ? '$ ' + dcaFmtMoney(d.haber) : '—'}</td>
                      <td>${esc(d.descripcion || '')}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   data-act="close">Cerrar</button>
          <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
        </div>
      </div>
    `);
    $('#modalRoot').addEventListener('click', (ev) => {
      if (ev.target.closest('[data-act="close"]'))  closeModal();
      if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirEditarAsientoDca(id); }
    });
  } catch (e) {
    toast(e.message, { error: true });
  }
}

async function eliminarAsientoDca(id, numero) {
  const ok = await confirmar({
    title:       'Eliminar asiento',
    message:     `¿Eliminás el asiento N° ${numero}? Esto recalcula los saldos afectados.`,
    confirmText: 'Eliminar',
    danger:      true,
  });
  if (!ok) return;
  try {
    await apiSend(`${DCA_API}?id=${id}`, 'DELETE');
    toast('Asiento eliminado');
    dcaCuentasImputables = [];
    dcaTodasCuentas      = [];
    dcaCuentasCacheEmp   = null;
    await cargarDca();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ---- Picker de cuentas (árbol jerárquico) ----
// Se implementa como modal separado (no usa openModal) para no cerrar el
// modal de alta/edición que está por debajo. Vive en su propio nodo con
// id `dcaPickerRoot` y z-index más alto que el `.modal-backdrop` base.
function dcaCerrarPicker() {
  const p = $('#dcaPickerRoot');
  if (p) { p.classList.remove('open'); setTimeout(() => p.remove(), 150); }
  dcaPickerLineaIdx = null;
}

function dcaAbrirPickerCuenta(lineaIdx) {
  dcaCerrarPicker();
  dcaPickerLineaIdx = lineaIdx;
  dcaPickerBusqueda = '';

  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.id = 'dcaPickerRoot';
  wrap.style.zIndex = '160';
  wrap.innerHTML = `
    <div class="modal" style="max-width:560px;display:flex;flex-direction:column;max-height:82vh;overflow:hidden">
      <div class="modal-header">
        <div class="modal-title">Seleccionar cuenta</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <input type="search" id="dcaPickerSearch" class="search-input"
               style="width:100%;box-sizing:border-box"
               placeholder="🔍 Buscar por código o nombre…">
      </div>
      <div id="dcaPickerArbol"
           style="overflow-y:auto;flex:1;padding:6px;min-height:240px;background:var(--bg)"></div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-act="close">Cancelar</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);
  requestAnimationFrame(() => wrap.classList.add('open'));

  wrap.addEventListener('click', (ev) => {
    if (ev.target === wrap) { dcaCerrarPicker(); return; }
    if (ev.target.closest('[data-act="close"]')) { dcaCerrarPicker(); return; }

    const it = ev.target.closest('[data-cuenta-id]');
    if (it) {
      const id = Number(it.dataset.cuentaId);
      const cs = dcaTodasCuentas.find((c) => c.id === id);
      if (cs && Number(cs.imputable) === 1 && Number(cs.activa) === 1) {
        if (dcaPickerLineaIdx !== null) {
          dcaUpdateLinea(dcaPickerLineaIdx, 'cuenta_id', id);
        }
        dcaCerrarPicker();
      }
      return;
    }
    const tog = ev.target.closest('[data-toggle-id]');
    if (tog) {
      ev.stopPropagation();
      const id = Number(tog.dataset.toggleId);
      if (dcaPickerColapsadas.has(id)) dcaPickerColapsadas.delete(id);
      else dcaPickerColapsadas.add(id);
      dcaRenderArbolPicker();
    }
  });

  $('#dcaPickerSearch').addEventListener('input', (ev) => {
    dcaPickerBusqueda = ev.target.value.trim();
    dcaRenderArbolPicker();
  });

  dcaRenderArbolPicker();
  setTimeout(() => $('#dcaPickerSearch')?.focus(), 50);
}

function dcaRenderArbolPicker() {
  const container = $('#dcaPickerArbol');
  if (!container) return;
  const busq = dcaPickerBusqueda.toLowerCase();
  const lineaCuentaId = (dcaPickerLineaIdx !== null && dcaLineas[dcaPickerLineaIdx])
    ? Number(dcaLineas[dcaPickerLineaIdx].cuenta_id) : 0;

  if (!dcaTodasCuentas.length) {
    container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">No hay cuentas disponibles</div>`;
    return;
  }

  let html = '';

  if (busq) {
    const matches = dcaTodasCuentas.filter((c) =>
      Number(c.imputable) === 1 && Number(c.activa) === 1 &&
      (c.codigo + ' ' + c.nombre).toLowerCase().includes(busq)
    );
    if (!matches.length) {
      container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">Sin resultados para "${esc(dcaPickerBusqueda)}"</div>`;
      return;
    }
    matches.forEach((c) => {
      const sel = lineaCuentaId === c.id;
      html += `
        <div data-cuenta-id="${c.id}"
             style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;border-radius:6px;${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600' : ''}">
          <code style="font-size:.78rem;flex-shrink:0">${esc(c.codigo)}</code>
          <span style="flex:1">${esc(c.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0">✓</span>' : ''}
        </div>`;
    });
  } else {
    const byId = {};
    dcaTodasCuentas.forEach((c) => { byId[c.id] = Object.assign({}, c, { children: [] }); });
    const raices = [];
    dcaTodasCuentas.forEach((c) => {
      if (c.parent_id && byId[c.parent_id]) byId[c.parent_id].children.push(byId[c.id]);
      else raices.push(byId[c.id]);
    });

    const walk = (nodo, depth) => {
      const isImputable = Number(nodo.imputable) === 1 && Number(nodo.activa) === 1;
      const tieneHijos  = nodo.children.length > 0;
      const colapsado   = dcaPickerColapsadas.has(nodo.id);
      const sel         = lineaCuentaId === nodo.id;
      const pl          = 8 + depth * 20;

      const toggle = tieneHijos
        ? `<span data-toggle-id="${nodo.id}" style="width:18px;text-align:center;display:inline-block;flex-shrink:0;cursor:pointer">${colapsado ? '▶' : '▼'}</span>`
        : `<span style="width:18px;display:inline-block;flex-shrink:0"></span>`;

      html += `
        <div ${isImputable ? `data-cuenta-id="${nodo.id}"` : ''}
             style="display:flex;align-items:center;gap:4px;padding:7px 8px 7px ${pl}px;border-radius:6px;
                    cursor:${isImputable ? 'pointer' : 'default'};
                    ${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;' : !isImputable ? 'color:var(--muted);' : ''}
                    font-weight:${Number(nodo.imputable) === 0 ? '700' : '400'}">
          ${toggle}
          <code style="font-size:.78rem;flex-shrink:0;margin-right:4px">${esc(nodo.codigo)}</code>
          <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(nodo.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0;margin-left:4px">✓</span>' : ''}
        </div>`;

      if (tieneHijos && !colapsado) {
        nodo.children.forEach((h) => walk(h, depth + 1));
      }
    };
    raices.forEach((r) => walk(r, 0));
  }

  container.innerHTML = html;
}

// ------------------------- Vista: Datacount > Movimientos recurrentes -------------------------
// ABM de movimientos contables recurrentes por empresa + cuenta con montos
// previstos de ingreso/egreso y flag `activo`. Listado + toolbar con
// buscador rápido + modal de filtros según ABM.md.

const DCR_API = 'api/datacountrecurrentes.php';

let dcrItems             = [];
let dcrTodasCuentasCache = [];       // todas las cuentas (con agrupaciones) — para el árbol
let dcrCuentasCache      = [];       // solo imputables+activas — para filtros dropdown
let dcrCuentasCacheEmp   = null;     // empresa a la que corresponde el cache
let dcrBusqueda          = '';
let dcrFiltroCodigo      = '';
let dcrFiltroCuenta      = '';
let dcrFiltroActivo      = '';
let dcrFiltroLimite      = 100;
let dcrFiltroOrden       = 'id';
let dcrFiltroDir         = 'desc';
let dcrEditandoId        = null;
let dcrBuscadorTimer     = null;
let dcrFiltrosSnapshot   = null;

// Estado del picker jerárquico de cuentas (modal secundario del alta/edición).
let dcrPickerColapsadas  = new Set();
let dcrPickerBusqueda    = '';

function dcrFmtMoney(n) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Carga las cuentas de la empresa activa. Cachea por empresa. Publica dos
// vistas: `dcrTodasCuentasCache` (para el árbol) y `dcrCuentasCache` (solo
// imputables+activas, para el dropdown del filtro).
async function dcrCargarCuentasEmpresa(empresaId) {
  if (dcrTodasCuentasCache.length && dcrCuentasCacheEmp === empresaId) return dcrCuentasCache;
  try {
    const d = await apiGet(`api/datacountcuentas.php?empresa_id=${empresaId}`);
    dcrTodasCuentasCache = d.items || [];
    dcrCuentasCache = dcrTodasCuentasCache.filter((c) =>
      Number(c.imputable) === 1 && Number(c.activa) === 1);
    dcrCuentasCacheEmp = empresaId;
  } catch {
    dcrTodasCuentasCache = [];
    dcrCuentasCache = [];
    dcrCuentasCacheEmp = empresaId;
  }
  return dcrCuentasCache;
}

route('/datacountrecurrentes', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">🔁</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los movimientos recurrentes son plantillas de ingresos/egresos esperados por
          empresa y cuenta contable. Cada fila combina una empresa, una cuenta imputable
          del plan de cuentas y los montos previstos de ingreso y egreso.
        </div>
      </div>

      <div class="stats-bar" id="dcrStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value orange" id="dcrStatTotal">—</span></div>
        <div class="stat-card"><span class="stat-label">Activos</span><span class="stat-value green" id="dcrStatActivos">—</span></div>
        <div class="stat-card"><span class="stat-label">Ingresos (activos)</span><span class="stat-value" style="color:#93c5fd" id="dcrStatIngresos">—</span></div>
        <div class="stat-card"><span class="stat-label">Egresos (activos)</span><span class="stat-value red" id="dcrStatEgresos">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <select id="dcrEmpresaSel" style="min-width:200px" title="Empresa">
            <option value="">— Cargando empresas… —</option>
          </select>
          <div class="search-wrap">
            <input type="search" class="search-input" id="dcrSearch"
                   placeholder="🔍 Buscar código o nombre de cuenta…">
            <button class="search-clear" id="dcrSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dcrFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dcrFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dcrRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dcrNuevoBtn">+ Nuevo movimiento recurrente</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:80px">Código</th>
              <th>Nombre</th>
              <th>Cuenta</th>
              <th style="width:140px;text-align:right">Ingreso</th>
              <th style="width:140px;text-align:right">Egreso</th>
              <th style="width:90px;text-align:center">Activo</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dcrTbody">
            <tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="dcrCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md) -->
    <div class="modal-backdrop" id="filtrosDcrBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDcr()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDcr()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDcrCodigo" min="1" placeholder="ID …"
                     oninput="onFiltroDcr('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Cuenta</label>
              <select id="fDcrCuenta" onchange="onFiltroDcr('cuenta', this.value)">
                <option value="">— Todas —</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Estado</label>
            <div id="fDcrActivoChips" style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip" data-val="">Todos</button>
              <button type="button" class="filter-chip" data-val="1">Activos</button>
              <button type="button" class="filter-chip" data-val="0">Inactivos</button>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDcrLimite" min="1" max="1000" value="100"
                     onchange="onFiltroDcr('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDcrOrden" onchange="onFiltroDcr('orden', this.value)">
                <option value="id">Código</option>
                <option value="cuenta">Cuenta</option>
                <option value="ingreso">Ingreso</option>
                <option value="egreso">Egreso</option>
                <option value="activo">Activo</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDcrDir" onchange="onFiltroDcr('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDcr()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDcr()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDcr()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  const inp = $('#dcrSearch');
  const clr = $('#dcrSearchClear');
  inp.value = dcrBusqueda;
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dcrBusqueda = inp.value.trim();
    clearTimeout(dcrBuscadorTimer);
    dcrBuscadorTimer = setTimeout(cargarDcr, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = ''; clr.style.display = 'none'; dcrBusqueda = ''; cargarDcr();
  });

  // Selector de empresa (contexto compartido con otros módulos Datacount).
  const selEmp = $('#dcrEmpresaSel');
  const empresas = await dcGetEmpresas();
  const empresaId = await dcAsegurarEmpresaId();
  if (empresas.length) {
    selEmp.innerHTML = empresas.map((e) =>
      `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    selEmp.value = String(empresaId || empresas[0].id);
  } else {
    selEmp.innerHTML = `<option value="">— Sin empresas —</option>`;
    selEmp.disabled = true;
  }
  selEmp.addEventListener('change', async (ev) => {
    dcSetEmpresaId(ev.target.value);
    // Cambió la empresa: reset filtro por cuenta (era del contexto anterior)
    // y recargar el cache de cuentas.
    dcrFiltroCuenta      = '';
    dcrTodasCuentasCache = [];
    dcrCuentasCache      = [];
    dcrCuentasCacheEmp   = null;
    const nuevaEmp = Number(ev.target.value);
    if (nuevaEmp > 0) await dcrCargarCuentasEmpresa(nuevaEmp);
    dcrPoblarSelectsFiltros();
    dcrActualizarBadgeFiltros();
    await cargarDcr();
  });

  $('#dcrFiltrosBtn').addEventListener('click', abrirModalFiltrosDcr);
  $('#dcrRefrescarBtn').addEventListener('click', cargarDcr);
  $('#dcrNuevoBtn').addEventListener('click', () => abrirAltaEdicionDcr(null));

  // Chips de activo dentro del modal
  const chips = document.querySelectorAll('#fDcrActivoChips .filter-chip');
  chips.forEach((b) => {
    b.addEventListener('click', () => {
      dcrFiltroActivo = b.dataset.val || '';
      dcrSincronizarChipsActivo();
      dcrActualizarBadgeFiltros();
      cargarDcr();
    });
  });

  // Menú contextual + interacción con la fila
  $('#dcrCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultaDcr(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDcr(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDcr(data.id);
  });

  $('#dcrTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dcrCtxMenu'), r.right - 200, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultaDcr(Number(tr.dataset.id));
  });
  $('#dcrTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dcrCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  if (empresaId) await dcrCargarCuentasEmpresa(empresaId);
  dcrPoblarSelectsFiltros();
  dcrActualizarBadgeFiltros();
  await cargarDcr();
}, 'Movimientos recurrentes');

async function cargarDcr() {
  const tbody = $('#dcrTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const empresaId = await dcAsegurarEmpresaId();
  if (!empresaId) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">No hay empresas registradas — creá una antes de dar de alta recurrentes.</td></tr>`;
    return;
  }

  const qs = new URLSearchParams();
  qs.set('empresa', String(empresaId));
  if (dcrBusqueda)                          qs.set('q', dcrBusqueda);
  if (dcrFiltroCuenta)                      qs.set('cuenta', dcrFiltroCuenta);
  if (dcrFiltroActivo === '0' || dcrFiltroActivo === '1') qs.set('activo', dcrFiltroActivo);
  if (dcrFiltroLimite)                      qs.set('limite', dcrFiltroLimite);
  if (dcrFiltroOrden)                       qs.set('orden', dcrFiltroOrden);
  if (dcrFiltroDir)                         qs.set('dir', dcrFiltroDir);

  try {
    const data = await apiGet(DCR_API + '?' + qs.toString());
    dcrItems = data.items || [];
    pintarStatsDcr(data.stats || {});
    renderDcr();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDcr(s) {
  $('#dcrStatTotal').textContent    = fmtNum(s.total ?? dcrItems.length);
  $('#dcrStatActivos').textContent  = fmtNum(s.activos ?? 0);
  $('#dcrStatIngresos').textContent = '$ ' + dcrFmtMoney(s.ingresos ?? 0);
  $('#dcrStatEgresos').textContent  = '$ ' + dcrFmtMoney(s.egresos ?? 0);
}

function renderDcr() {
  const tbody = $('#dcrTbody');
  if (!tbody) return;
  if (!dcrItems.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin movimientos recurrentes registrados.</td></tr>`;
    return;
  }

  // Filtro cliente por Código (el resto lo resuelve el server).
  let filas = dcrItems;
  if (dcrFiltroCodigo) {
    const cod = Number(dcrFiltroCodigo);
    filas = filas.filter((r) => r.id === cod);
  }

  if (!filas.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin resultados con los filtros actuales.</td></tr>`;
    return;
  }

  tbody.innerHTML = filas.map((r) => {
    const activoBadge = Number(r.activo) === 1
      ? '<span class="badge badge-success">Activo</span>'
      : '<span class="badge">Inactivo</span>';
    const cuentaStr = r.cuenta_codigo
      ? `<code style="font-family:monospace;font-size:.82rem">${esc(r.cuenta_codigo)}</code> — ${esc(r.cuenta_nombre || '')}`
      : `#${r.cuenta}`;
    return `
      <tr data-id="${r.id}" class="row-clickable">
        <td><code style="font-size:.82rem">${r.id}</code></td>
        <td style="font-weight:600">${esc(r.nombre || '—')}</td>
        <td>${cuentaStr}</td>
        <td style="text-align:right;font-family:monospace">${Number(r.ingreso) > 0 ? '<span style="color:var(--success);font-weight:600">$ ' + dcrFmtMoney(r.ingreso) + '</span>' : '<span style="color:var(--muted)">—</span>'}</td>
        <td style="text-align:right;font-family:monospace">${Number(r.egreso) > 0 ? '<span style="color:var(--danger);font-weight:600">$ ' + dcrFmtMoney(r.egreso) + '</span>' : '<span style="color:var(--muted)">—</span>'}</td>
        <td style="text-align:center">${activoBadge}</td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center">
            <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
              <i class="fa-solid fa-bars"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// ---- Modal de filtros ----
function dcrPoblarSelectsFiltros() {
  const selC = $('#fDcrCuenta');
  if (selC) {
    selC.innerHTML = `<option value="">— Todas —</option>` +
      dcrCuentasCache.map((c) => `<option value="${c.id}">${esc(c.codigo)} — ${esc(c.nombre)}</option>`).join('');
    selC.value = dcrFiltroCuenta || '';
  }
}

function abrirModalFiltrosDcr() {
  dcrFiltrosSnapshot = {
    codigo:  dcrFiltroCodigo,
    cuenta:  dcrFiltroCuenta,
    activo:  dcrFiltroActivo,
    limite:  dcrFiltroLimite,
    orden:   dcrFiltroOrden,
    dir:     dcrFiltroDir,
  };
  $('#fDcrCodigo').value  = dcrFiltroCodigo || '';
  $('#fDcrCuenta').value  = dcrFiltroCuenta || '';
  $('#fDcrLimite').value  = dcrFiltroLimite || 100;
  $('#fDcrOrden').value   = dcrFiltroOrden  || 'id';
  $('#fDcrDir').value     = dcrFiltroDir    || 'desc';
  dcrSincronizarChipsActivo();
  document.getElementById('filtrosDcrBackdrop').classList.add('open');
}

function cerrarModalFiltrosDcr() {
  document.getElementById('filtrosDcrBackdrop').classList.remove('open');
}

function cancelarFiltrosDcr() {
  if (dcrFiltrosSnapshot) {
    dcrFiltroCodigo  = dcrFiltrosSnapshot.codigo;
    dcrFiltroCuenta  = dcrFiltrosSnapshot.cuenta;
    dcrFiltroActivo  = dcrFiltrosSnapshot.activo;
    dcrFiltroLimite  = dcrFiltrosSnapshot.limite;
    dcrFiltroOrden   = dcrFiltrosSnapshot.orden;
    dcrFiltroDir     = dcrFiltrosSnapshot.dir;
    dcrActualizarBadgeFiltros();
    cargarDcr();
  }
  cerrarModalFiltrosDcr();
}

function limpiarFiltrosDcr() {
  dcrFiltroCodigo  = '';
  dcrFiltroCuenta  = '';
  dcrFiltroActivo  = '';
  dcrFiltroLimite  = 100;
  dcrFiltroOrden   = 'id';
  dcrFiltroDir     = 'desc';
  $('#fDcrCodigo').value  = '';
  $('#fDcrCuenta').value  = '';
  $('#fDcrLimite').value  = 100;
  $('#fDcrOrden').value   = 'id';
  $('#fDcrDir').value     = 'desc';
  dcrSincronizarChipsActivo();
  dcrActualizarBadgeFiltros();
  cargarDcr();
}

function onFiltroDcr(campo, valor) {
  if (campo === 'codigo')  dcrFiltroCodigo  = (valor || '').trim();
  if (campo === 'cuenta')  dcrFiltroCuenta  = valor || '';
  if (campo === 'limite')  dcrFiltroLimite  = Math.max(1, Math.min(1000, Number(valor) || 100));
  if (campo === 'orden')   dcrFiltroOrden   = valor || 'id';
  if (campo === 'dir')     dcrFiltroDir     = valor || 'desc';
  dcrActualizarBadgeFiltros();
  cargarDcr();
}

function dcrSincronizarChipsActivo() {
  const chips = document.querySelectorAll('#fDcrActivoChips .filter-chip');
  chips.forEach((b) => {
    b.classList.toggle('active', (b.dataset.val || '') === (dcrFiltroActivo || ''));
  });
}

function dcrActualizarBadgeFiltros() {
  let n = 0;
  if (dcrFiltroCodigo)                                     n++;
  if (dcrFiltroCuenta)                                     n++;
  if (dcrFiltroActivo === '0' || dcrFiltroActivo === '1')  n++;
  if (Number(dcrFiltroLimite) !== 100)                     n++;
  if (dcrFiltroOrden !== 'id')                             n++;
  if (dcrFiltroDir   !== 'desc')                           n++;
  const badge = $('#dcrFiltrosBadge');
  const btn   = $('#dcrFiltrosBtn');
  if (!badge || !btn) return;
  if (n > 0) {
    badge.style.display = '';
    badge.textContent   = n;
    btn.classList.add('active');
  } else {
    badge.style.display = 'none';
    btn.classList.remove('active');
  }
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicionDcr(id) {
  dcrEditandoId = id;
  const editando = !!id;
  const r = editando ? dcrItems.find((x) => x.id === id) : null;
  const titulo = editando ? 'Editar movimiento recurrente' : 'Nuevo movimiento recurrente';

  // Empresa contextual: en alta usa la del contexto compartido; en edición
  // usa la que ya tiene el registro (no se puede cambiar desde este modal).
  const empresaId = editando ? Number(r?.empresa) : (dcGetEmpresaId() || 0);
  if (!empresaId) {
    toast('Elegí una empresa antes de crear recurrentes', { error: true });
    return;
  }
  await dcrCargarCuentasEmpresa(empresaId);
  const empresas = await dcGetEmpresas();
  const empresaObj = empresas.find((e) => e.id === empresaId);

  openModal(`
    <div class="modal" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-title">${esc(titulo)}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Empresa</label>
          <div style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-weight:600">
            🏢 ${esc(empresaObj?.nombre || '#' + empresaId)}
          </div>
        </div>
        <div class="form-group">
          <label for="dcrNombre">Nombre *</label>
          <input type="text" id="dcrNombre" maxlength="150"
                 placeholder="Ej. Alquiler oficina, Sueldo administrativo…"
                 style="width:100%;box-sizing:border-box">
        </div>
        <div class="form-group">
          <label>Cuenta *</label>
          <button type="button" id="dcrCuentaBtn" data-cuenta-id=""
                  style="text-align:left;width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;gap:8px">
            <span id="dcrCuentaLabel" style="flex:1;color:var(--muted)">— Elegí una cuenta imputable —</span>
            <i class="fa-solid fa-chevron-down" style="color:var(--muted);flex-shrink:0"></i>
          </button>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dcrIngreso">Ingreso</label>
            <input type="number" id="dcrIngreso" min="0" step="0.01" placeholder="0.00"
                   style="font-family:monospace;text-align:right">
          </div>
          <div class="form-group">
            <label for="dcrEgreso">Egreso</label>
            <input type="number" id="dcrEgreso" min="0" step="0.01" placeholder="0.00"
                   style="font-family:monospace;text-align:right">
          </div>
        </div>
        <div class="form-group">
          <label class="toggle-switch">
            <input type="checkbox" id="dcrActivo" checked>
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
            <span class="toggle-label">Activo</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">Guardar</button>
      </div>
    </div>
  `);

  if (editando && r) {
    $('#dcrNombre').value = r.nombre || '';
    dcrSetCuentaSeleccionada(Number(r.cuenta));
    $('#dcrIngreso').value = Number(r.ingreso) > 0 ? r.ingreso : '';
    $('#dcrEgreso').value  = Number(r.egreso)  > 0 ? r.egreso  : '';
    $('#dcrActivo').checked = Number(r.activo) === 1;
  }

  setTimeout(() => $('#dcrNombre')?.focus(), 50);

  $('#modalRoot').dataset.dcrEmpresa = String(empresaId);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))   closeModal();
    if (ev.target.closest('[data-act="guardar"]')) guardarDcr();
    if (ev.target.closest('#dcrCuentaBtn'))        dcrAbrirPickerCuenta();
  });
}

// Refleja la cuenta elegida en el botón: guarda el id en `data-cuenta-id`
// y muestra el label con código y nombre. Si el id es 0/null, deja el
// placeholder.
function dcrSetCuentaSeleccionada(cuentaId) {
  const btn   = $('#dcrCuentaBtn');
  const label = $('#dcrCuentaLabel');
  if (!btn || !label) return;
  const c = cuentaId ? dcrTodasCuentasCache.find((x) => x.id === cuentaId) : null;
  if (c) {
    btn.dataset.cuentaId = String(c.id);
    label.style.color = '';
    label.innerHTML =
      `<code style="font-family:monospace;font-size:.85rem;margin-right:6px">${esc(c.codigo)}</code>${esc(c.nombre)}`;
  } else {
    btn.dataset.cuentaId = '';
    label.style.color = 'var(--muted)';
    label.textContent = '— Elegí una cuenta imputable —';
  }
}

// ---- Picker de cuentas jerárquico (modal secundario del alta/edición) ----
function dcrCerrarPickerCuenta() {
  const p = $('#dcrPickerRoot');
  if (p) { p.classList.remove('open'); setTimeout(() => p.remove(), 150); }
}

function dcrAbrirPickerCuenta() {
  dcrCerrarPickerCuenta();
  dcrPickerBusqueda = '';

  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.id = 'dcrPickerRoot';
  wrap.style.zIndex = '160';
  wrap.innerHTML = `
    <div class="modal" style="max-width:560px;display:flex;flex-direction:column;max-height:82vh;overflow:hidden">
      <div class="modal-header">
        <div class="modal-title">Seleccionar cuenta</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <input type="search" id="dcrPickerSearch" class="search-input"
               style="width:100%;box-sizing:border-box"
               placeholder="🔍 Buscar por código o nombre…">
      </div>
      <div id="dcrPickerArbol"
           style="overflow-y:auto;flex:1;padding:6px;min-height:240px;background:var(--bg)"></div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-act="close">Cancelar</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);
  requestAnimationFrame(() => wrap.classList.add('open'));

  wrap.addEventListener('click', (ev) => {
    if (ev.target === wrap) { dcrCerrarPickerCuenta(); return; }
    if (ev.target.closest('[data-act="close"]')) { dcrCerrarPickerCuenta(); return; }

    const it = ev.target.closest('[data-cuenta-id]');
    if (it) {
      const id = Number(it.dataset.cuentaId);
      const cs = dcrTodasCuentasCache.find((c) => c.id === id);
      if (cs && Number(cs.imputable) === 1 && Number(cs.activa) === 1) {
        dcrSetCuentaSeleccionada(id);
        dcrCerrarPickerCuenta();
      }
      return;
    }
    const tog = ev.target.closest('[data-toggle-id]');
    if (tog) {
      ev.stopPropagation();
      const id = Number(tog.dataset.toggleId);
      if (dcrPickerColapsadas.has(id)) dcrPickerColapsadas.delete(id);
      else dcrPickerColapsadas.add(id);
      dcrRenderArbolPicker();
    }
  });

  $('#dcrPickerSearch').addEventListener('input', (ev) => {
    dcrPickerBusqueda = ev.target.value.trim();
    dcrRenderArbolPicker();
  });

  dcrRenderArbolPicker();
  setTimeout(() => $('#dcrPickerSearch')?.focus(), 50);
}

function dcrRenderArbolPicker() {
  const container = $('#dcrPickerArbol');
  if (!container) return;
  const busq = dcrPickerBusqueda.toLowerCase();
  const cuentaSeleccionada = Number($('#dcrCuentaBtn')?.dataset.cuentaId) || 0;

  if (!dcrTodasCuentasCache.length) {
    container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">No hay cuentas disponibles para esta empresa</div>`;
    return;
  }

  let html = '';

  if (busq) {
    // En modo búsqueda aplanamos: solo cuentas imputables+activas que matcheen.
    const matches = dcrTodasCuentasCache.filter((c) =>
      Number(c.imputable) === 1 && Number(c.activa) === 1 &&
      (c.codigo + ' ' + c.nombre).toLowerCase().includes(busq)
    );
    if (!matches.length) {
      container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">Sin resultados para "${esc(dcrPickerBusqueda)}"</div>`;
      return;
    }
    matches.forEach((c) => {
      const sel = cuentaSeleccionada === c.id;
      html += `
        <div data-cuenta-id="${c.id}"
             style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;border-radius:6px;${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600' : ''}">
          <code style="font-size:.78rem;flex-shrink:0">${esc(c.codigo)}</code>
          <span style="flex:1">${esc(c.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0">✓</span>' : ''}
        </div>`;
    });
  } else {
    // Vista árbol: agrupaciones (imputable=0) se muestran pero no se pueden elegir.
    const byId = {};
    dcrTodasCuentasCache.forEach((c) => { byId[c.id] = Object.assign({}, c, { children: [] }); });
    const raices = [];
    dcrTodasCuentasCache.forEach((c) => {
      if (c.parent_id && byId[c.parent_id]) byId[c.parent_id].children.push(byId[c.id]);
      else raices.push(byId[c.id]);
    });

    const walk = (nodo, depth) => {
      const isImputable = Number(nodo.imputable) === 1 && Number(nodo.activa) === 1;
      const tieneHijos  = nodo.children.length > 0;
      const colapsado   = dcrPickerColapsadas.has(nodo.id);
      const sel         = cuentaSeleccionada === nodo.id;
      const pl          = 8 + depth * 20;

      const toggle = tieneHijos
        ? `<span data-toggle-id="${nodo.id}" style="width:18px;text-align:center;display:inline-block;flex-shrink:0;cursor:pointer">${colapsado ? '▶' : '▼'}</span>`
        : `<span style="width:18px;display:inline-block;flex-shrink:0"></span>`;

      html += `
        <div ${isImputable ? `data-cuenta-id="${nodo.id}"` : ''}
             style="display:flex;align-items:center;gap:4px;padding:7px 8px 7px ${pl}px;border-radius:6px;
                    cursor:${isImputable ? 'pointer' : 'default'};
                    ${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;' : !isImputable ? 'color:var(--muted);' : ''}
                    font-weight:${Number(nodo.imputable) === 0 ? '700' : '400'}">
          ${toggle}
          <code style="font-size:.78rem;flex-shrink:0;margin-right:4px">${esc(nodo.codigo)}</code>
          <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(nodo.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0;margin-left:4px">✓</span>' : ''}
        </div>`;

      if (tieneHijos && !colapsado) {
        nodo.children.forEach((h) => walk(h, depth + 1));
      }
    };
    raices.forEach((r) => walk(r, 0));
  }

  container.innerHTML = html;
}

async function guardarDcr() {
  const nombre  = ($('#dcrNombre').value || '').trim();
  const empresa = Number($('#modalRoot').dataset.dcrEmpresa) || 0;
  const cuenta  = Number($('#dcrCuentaBtn')?.dataset.cuentaId) || 0;
  const ingreso = Number($('#dcrIngreso').value) || 0;
  const egreso  = Number($('#dcrEgreso').value)  || 0;
  const activo  = $('#dcrActivo').checked ? 1 : 0;

  if (!nombre)  { toast('El nombre es obligatorio', { error: true }); return; }
  if (!empresa) { toast('La empresa es obligatoria', { error: true }); return; }
  if (!cuenta)  { toast('La cuenta es obligatoria',  { error: true }); return; }
  if (ingreso < 0 || egreso < 0) { toast('Los montos no pueden ser negativos', { error: true }); return; }

  const body = { nombre, empresa, cuenta, ingreso, egreso, activo };

  try {
    if (dcrEditandoId) {
      await apiSend(`${DCR_API}?id=${dcrEditandoId}`, 'PUT', body);
      toast('Movimiento recurrente actualizado');
    } else {
      await apiSend(DCR_API, 'POST', body);
      toast('Movimiento recurrente creado');
    }
    closeModal();
    dcrEditandoId = null;
    await cargarDcr();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ---- Modal Consulta ----
function abrirConsultaDcr(id) {
  const r = dcrItems.find((x) => x.id === id);
  if (!r) return;

  const card = (label, valor, ancho) => `
    <div style="flex:${ancho === 'full' ? '1 1 100%' : '1 1 calc(50% - 6px)'};
                background:color-mix(in srgb, var(--surface) 90%, #000);
                border:none;border-radius:12px;padding:12px 14px">
      <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">${esc(label)}</div>
      <div style="font-size:.92rem">${valor}</div>
    </div>
  `;

  const cuentaLabel = r.cuenta_codigo
    ? `<code style="font-family:monospace">${esc(r.cuenta_codigo)}</code> — ${esc(r.cuenta_nombre || '')}`
    : `#${r.cuenta}`;
  const ingresoHtml = Number(r.ingreso) > 0
    ? `<span style="color:var(--success);font-weight:600;font-family:monospace">$ ${dcrFmtMoney(r.ingreso)}</span>`
    : `<span style="color:var(--muted)">—</span>`;
  const egresoHtml = Number(r.egreso) > 0
    ? `<span style="color:var(--danger);font-weight:600;font-family:monospace">$ ${dcrFmtMoney(r.egreso)}</span>`
    : `<span style="color:var(--muted)">—</span>`;
  const activoHtml = Number(r.activo) === 1
    ? `<span class="badge badge-success">Activo</span>`
    : `<span class="badge">Inactivo</span>`;

  openModal(`
    <div class="modal" style="max-width:620px">
      <div class="modal-header">
        <div class="modal-title">
          🔁 <span class="modal-subtitle">Movimiento recurrente #${r.id}</span>
        </div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="display:flex;flex-wrap:wrap;gap:12px">
          ${card('Empresa',  esc(r.empresa_nombre || '#' + r.empresa), 'full')}
          ${card('Nombre',   esc(r.nombre || '—'), 'full')}
          ${card('Cuenta',   cuentaLabel, 'full')}
          ${card('Ingreso',  ingresoHtml)}
          ${card('Egreso',   egresoHtml)}
          ${card('Estado',   activoHtml)}
          ${card('Código',   `<code>${r.id}</code>`)}
          ${card('Alta',     esc(fmtFecha(r.created_at)))}
          ${card('Modificación', esc(fmtFecha(r.updated_at)))}
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDcr(id); }
  });
}

async function eliminarDcr(id) {
  const r = dcrItems.find((x) => x.id === id);
  if (!r) return;
  const desc = r.nombre
    ? r.nombre
    : (r.empresa_nombre
        ? `${r.empresa_nombre} / ${r.cuenta_codigo || '#' + r.cuenta}`
        : `#${id}`);
  const ok = await confirmar({
    title:       'Eliminar movimiento recurrente',
    message:     `¿Eliminás el movimiento recurrente "${desc}"?`,
    confirmText: 'Eliminar',
    danger:      true,
  });
  if (!ok) return;
  try {
    await apiSend(`${DCR_API}?id=${id}`, 'DELETE');
    toast('Movimiento recurrente eliminado');
    await cargarDcr();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ------------------------- Vista: Datacount > Empleados -------------------------
// ABM de empleados por empresa. Cada fila combina datos personales (nombre,
// documento, nacimiento, domicilio), de contacto (celular, correo) y laborales
// (cuenta contable donde imputa el sueldo, sueldo mensual, CVU/CBU, estado y
// observaciones). Listado + toolbar con buscador rápido + modal de filtros según
// ABM.md.

const DCM_API = 'api/datacountempleados.php';

let dcmItems             = [];
let dcmTodasCuentasCache = [];       // todas las cuentas (con agrupaciones) — para el árbol
let dcmCuentasCache      = [];       // solo imputables+activas — para el dropdown del filtro
let dcmCuentasCacheEmp   = null;     // empresa a la que corresponde el cache
let dcmBusqueda          = '';
let dcmFiltroCodigo      = '';
let dcmFiltroCuentaId    = '';
let dcmFiltroActivo      = '';
let dcmFiltroLimite      = 100;
let dcmFiltroOrden       = 'id';
let dcmFiltroDir         = 'desc';
let dcmEditandoId        = null;
let dcmBuscadorTimer     = null;
let dcmFiltrosSnapshot   = null;

// Estado del picker jerárquico de cuentas (modal secundario del alta/edición).
let dcmPickerColapsadas  = new Set();
let dcmPickerBusqueda    = '';

function dcmFmtMoney(n) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Carga las cuentas de la empresa activa. Cachea por empresa. Publica dos
// vistas: `dcmTodasCuentasCache` (para el árbol) y `dcmCuentasCache` (solo
// imputables+activas, para el dropdown del filtro).
async function dcmCargarCuentasEmpresa(empresaId) {
  if (dcmTodasCuentasCache.length && dcmCuentasCacheEmp === empresaId) return dcmCuentasCache;
  try {
    const d = await apiGet(`api/datacountcuentas.php?empresa_id=${empresaId}`);
    dcmTodasCuentasCache = d.items || [];
    dcmCuentasCache = dcmTodasCuentasCache.filter((c) =>
      Number(c.imputable) === 1 && Number(c.activa) === 1);
    dcmCuentasCacheEmp = empresaId;
  } catch {
    dcmTodasCuentasCache = [];
    dcmCuentasCache = [];
    dcmCuentasCacheEmp = empresaId;
  }
  return dcmCuentasCache;
}

route('/datacountempleados', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">👤</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los empleados son las personas contratadas por cada empresa. Cada
          fila combina datos personales, de contacto y laborales (cuenta contable
          donde imputa el sueldo, sueldo mensual, CVU/CBU y estado).
        </div>
      </div>

      <div class="stats-bar" id="dcmStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value orange" id="dcmStatTotal">—</span></div>
        <div class="stat-card"><span class="stat-label">Activos</span><span class="stat-value green" id="dcmStatActivos">—</span></div>
        <div class="stat-card"><span class="stat-label">Masa salarial (activos)</span><span class="stat-value" style="color:#93c5fd" id="dcmStatMasa">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <select id="dcmEmpresaSel" style="min-width:200px" title="Empresa">
            <option value="">— Cargando empresas… —</option>
          </select>
          <div class="search-wrap">
            <input type="search" class="search-input" id="dcmSearch"
                   placeholder="🔍 Buscar nombre, documento, correo o celular…">
            <button class="search-clear" id="dcmSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dcmFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dcmFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dcmRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dcmNuevoBtn">+ Nuevo empleado</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:80px">Código</th>
              <th>Nombre</th>
              <th style="width:120px">Documento</th>
              <th>Correo</th>
              <th style="width:130px">Celular</th>
              <th style="width:140px;text-align:right">Sueldo</th>
              <th style="width:90px;text-align:center">Activo</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dcmTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="dcmCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="copiar" role="menuitem">
        <i class="fa-solid fa-copy"></i><span>Copiar</span>
      </button>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros (ABM.md) -->
    <div class="modal-backdrop" id="filtrosDcmBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDcm()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDcm()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDcmCodigo" min="1" placeholder="ID …"
                     oninput="onFiltroDcm('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Cuenta</label>
              <select id="fDcmCuentaId" onchange="onFiltroDcm('cuenta_id', this.value)">
                <option value="">— Todas —</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Estado</label>
            <div id="fDcmActivoChips" style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip" data-val="">Todos</button>
              <button type="button" class="filter-chip" data-val="si">Activos</button>
              <button type="button" class="filter-chip" data-val="no">Inactivos</button>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDcmLimite" min="1" max="1000" value="100"
                     onchange="onFiltroDcm('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDcmOrden" onchange="onFiltroDcm('orden', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="documento">Documento</option>
                <option value="sueldo">Sueldo</option>
                <option value="activo">Activo</option>
                <option value="nacimiento">Nacimiento</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDcmDir" onchange="onFiltroDcm('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDcm()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDcm()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDcm()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  const inp = $('#dcmSearch');
  const clr = $('#dcmSearchClear');
  inp.value = dcmBusqueda;
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dcmBusqueda = inp.value.trim();
    clearTimeout(dcmBuscadorTimer);
    dcmBuscadorTimer = setTimeout(cargarDcm, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = ''; clr.style.display = 'none'; dcmBusqueda = ''; cargarDcm();
  });

  const selEmp = $('#dcmEmpresaSel');
  const empresas = await dcGetEmpresas();
  const empresaId = await dcAsegurarEmpresaId();
  if (empresas.length) {
    selEmp.innerHTML = empresas.map((e) =>
      `<option value="${e.id}">${esc(e.nombre)}</option>`).join('');
    selEmp.value = String(empresaId || empresas[0].id);
  } else {
    selEmp.innerHTML = `<option value="">— Sin empresas —</option>`;
    selEmp.disabled = true;
  }
  selEmp.addEventListener('change', async (ev) => {
    dcSetEmpresaId(ev.target.value);
    dcmFiltroCuentaId    = '';
    dcmTodasCuentasCache = [];
    dcmCuentasCache      = [];
    dcmCuentasCacheEmp   = null;
    const nuevaEmp = Number(ev.target.value);
    if (nuevaEmp > 0) await dcmCargarCuentasEmpresa(nuevaEmp);
    dcmPoblarSelectsFiltros();
    dcmActualizarBadgeFiltros();
    await cargarDcm();
  });

  $('#dcmFiltrosBtn').addEventListener('click', abrirModalFiltrosDcm);
  $('#dcmRefrescarBtn').addEventListener('click', cargarDcm);
  $('#dcmNuevoBtn').addEventListener('click', () => abrirAltaEdicionDcm(null));

  const chips = document.querySelectorAll('#fDcmActivoChips .filter-chip');
  chips.forEach((b) => {
    b.addEventListener('click', () => {
      dcmFiltroActivo = b.dataset.val || '';
      dcmSincronizarChipsActivo();
      dcmActualizarBadgeFiltros();
      cargarDcm();
    });
  });

  $('#dcmCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultaDcm(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDcm(data.id);
    if (b.dataset.action === 'copiar')    abrirCopiarDcm(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDcm(data.id);
  });

  $('#dcmTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dcmCtxMenu'), r.right - 200, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultaDcm(Number(tr.dataset.id));
  });
  $('#dcmTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dcmCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  if (empresaId) await dcmCargarCuentasEmpresa(empresaId);
  dcmPoblarSelectsFiltros();
  dcmActualizarBadgeFiltros();
  await cargarDcm();
}, 'Empleados');

async function cargarDcm() {
  const tbody = $('#dcmTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const empresaId = await dcAsegurarEmpresaId();
  if (!empresaId) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">No hay empresas registradas — creá una antes de dar de alta empleados.</td></tr>`;
    return;
  }

  const qs = new URLSearchParams();
  qs.set('empresa', String(empresaId));
  if (dcmBusqueda)                                       qs.set('q', dcmBusqueda);
  if (dcmFiltroCuentaId)                                 qs.set('cuenta_id', dcmFiltroCuentaId);
  if (dcmFiltroActivo === 'si' || dcmFiltroActivo === 'no') qs.set('activo', dcmFiltroActivo);
  if (dcmFiltroLimite)                                   qs.set('limite', dcmFiltroLimite);
  if (dcmFiltroOrden)                                    qs.set('orden', dcmFiltroOrden);
  if (dcmFiltroDir)                                      qs.set('dir', dcmFiltroDir);

  try {
    const data = await apiGet(DCM_API + '?' + qs.toString());
    dcmItems = data.items || [];
    pintarStatsDcm(data.stats || {});
    renderDcm();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDcm(s) {
  $('#dcmStatTotal').textContent   = fmtNum(s.total ?? dcmItems.length);
  $('#dcmStatActivos').textContent = fmtNum(s.activos ?? 0);
  $('#dcmStatMasa').textContent    = '$ ' + dcmFmtMoney(s.masa_salarial ?? 0);
}

function renderDcm() {
  const tbody = $('#dcmTbody');
  if (!tbody) return;
  if (!dcmItems.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin empleados registrados.</td></tr>`;
    return;
  }

  let filas = dcmItems;
  if (dcmFiltroCodigo) {
    const cod = Number(dcmFiltroCodigo);
    filas = filas.filter((r) => r.id === cod);
  }

  if (!filas.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin resultados con los filtros actuales.</td></tr>`;
    return;
  }

  tbody.innerHTML = filas.map((r) => {
    const activoBadge = r.activo === 'si'
      ? '<span class="badge badge-success">Activo</span>'
      : '<span class="badge">Inactivo</span>';
    const sueldoHtml = Number(r.sueldo) > 0
      ? `<span style="color:var(--success);font-weight:600">$ ${dcmFmtMoney(r.sueldo)}</span>`
      : `<span style="color:var(--muted)">—</span>`;
    return `
      <tr data-id="${r.id}" class="row-clickable">
        <td><code style="font-size:.82rem">${r.id}</code></td>
        <td style="font-weight:600">${esc(r.nombre || '—')}</td>
        <td><code style="font-family:monospace;font-size:.82rem">${esc(r.documento || '—')}</code></td>
        <td>${r.correo ? esc(r.correo) : '<span style="color:var(--muted)">—</span>'}</td>
        <td>${r.celular ? esc(r.celular) : '<span style="color:var(--muted)">—</span>'}</td>
        <td style="text-align:right;font-family:monospace">${sueldoHtml}</td>
        <td style="text-align:center">${activoBadge}</td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center">
            <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
              <i class="fa-solid fa-bars"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// ---- Modal de filtros ----
function dcmPoblarSelectsFiltros() {
  const selC = $('#fDcmCuentaId');
  if (selC) {
    selC.innerHTML = `<option value="">— Todas —</option>` +
      dcmCuentasCache.map((c) => `<option value="${c.id}">${esc(c.codigo)} — ${esc(c.nombre)}</option>`).join('');
    selC.value = dcmFiltroCuentaId || '';
  }
}

function abrirModalFiltrosDcm() {
  dcmFiltrosSnapshot = {
    codigo:    dcmFiltroCodigo,
    cuenta_id: dcmFiltroCuentaId,
    activo:    dcmFiltroActivo,
    limite:    dcmFiltroLimite,
    orden:     dcmFiltroOrden,
    dir:       dcmFiltroDir,
  };
  $('#fDcmCodigo').value    = dcmFiltroCodigo || '';
  $('#fDcmCuentaId').value  = dcmFiltroCuentaId || '';
  $('#fDcmLimite').value    = dcmFiltroLimite || 100;
  $('#fDcmOrden').value     = dcmFiltroOrden  || 'id';
  $('#fDcmDir').value       = dcmFiltroDir    || 'desc';
  dcmSincronizarChipsActivo();
  document.getElementById('filtrosDcmBackdrop').classList.add('open');
}

function cerrarModalFiltrosDcm() {
  document.getElementById('filtrosDcmBackdrop').classList.remove('open');
}

function cancelarFiltrosDcm() {
  if (dcmFiltrosSnapshot) {
    dcmFiltroCodigo   = dcmFiltrosSnapshot.codigo;
    dcmFiltroCuentaId = dcmFiltrosSnapshot.cuenta_id;
    dcmFiltroActivo   = dcmFiltrosSnapshot.activo;
    dcmFiltroLimite   = dcmFiltrosSnapshot.limite;
    dcmFiltroOrden    = dcmFiltrosSnapshot.orden;
    dcmFiltroDir      = dcmFiltrosSnapshot.dir;
    dcmActualizarBadgeFiltros();
    cargarDcm();
  }
  cerrarModalFiltrosDcm();
}

function limpiarFiltrosDcm() {
  dcmFiltroCodigo     = '';
  dcmFiltroCuentaId     = '';
  dcmFiltroActivo = '';
  dcmFiltroLimite     = 100;
  dcmFiltroOrden      = 'id';
  dcmFiltroDir        = 'desc';
  $('#fDcmCodigo').value  = '';
  $('#fDcmCuentaId').value  = '';
  $('#fDcmLimite').value  = 100;
  $('#fDcmOrden').value   = 'id';
  $('#fDcmDir').value     = 'desc';
  dcmSincronizarChipsActivo();
  dcmActualizarBadgeFiltros();
  cargarDcm();
}

function onFiltroDcm(campo, valor) {
  if (campo === 'codigo')     dcmFiltroCodigo   = (valor || '').trim();
  if (campo === 'cuenta_id')  dcmFiltroCuentaId = valor || '';
  if (campo === 'limite')     dcmFiltroLimite   = Math.max(1, Math.min(1000, Number(valor) || 100));
  if (campo === 'orden')      dcmFiltroOrden    = valor || 'id';
  if (campo === 'dir')        dcmFiltroDir      = valor || 'desc';
  dcmActualizarBadgeFiltros();
  cargarDcm();
}

function dcmSincronizarChipsActivo() {
  const chips = document.querySelectorAll('#fDcmActivoChips .filter-chip');
  chips.forEach((b) => {
    b.classList.toggle('active', (b.dataset.val || '') === (dcmFiltroActivo || ''));
  });
}

function dcmActualizarBadgeFiltros() {
  let n = 0;
  if (dcmFiltroCodigo)                                        n++;
  if (dcmFiltroCuentaId)                                      n++;
  if (dcmFiltroActivo === 'si' || dcmFiltroActivo === 'no')   n++;
  if (Number(dcmFiltroLimite) !== 100)                        n++;
  if (dcmFiltroOrden !== 'id')                                n++;
  if (dcmFiltroDir   !== 'desc')                              n++;
  const badge = $('#dcmFiltrosBadge');
  const btn   = $('#dcmFiltrosBtn');
  if (!badge || !btn) return;
  if (n > 0) {
    badge.style.display = '';
    badge.textContent   = n;
    btn.classList.add('active');
  } else {
    badge.style.display = 'none';
    btn.classList.remove('active');
  }
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicionDcm(id) {
  dcmEditandoId = id;
  const editando = !!id;
  const r = editando ? dcmItems.find((x) => x.id === id) : null;
  const titulo = editando ? 'Editar empleado' : 'Nuevo empleado';

  const empresaId = editando ? Number(r?.empresa_id) : (dcGetEmpresaId() || 0);
  if (!empresaId) {
    toast('Elegí una empresa antes de crear empleados', { error: true });
    return;
  }
  await dcmCargarCuentasEmpresa(empresaId);
  const empresas = await dcGetEmpresas();
  const empresaObj = empresas.find((e) => e.id === empresaId);

  openModal(`
    <div class="modal" style="max-width:640px">
      <div class="modal-header">
        <div class="modal-title">${esc(titulo)}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Empresa</label>
          <div style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-weight:600">
            🏢 ${esc(empresaObj?.nombre || '#' + empresaId)}
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dcmNombre">Nombre *</label>
            <input type="text" id="dcmNombre" maxlength="100"
                   placeholder="Ej. Juan Pérez">
          </div>
          <div class="form-group">
            <label for="dcmDocumento">Documento</label>
            <input type="text" id="dcmDocumento" maxlength="15"
                   placeholder="Ej. 30123456"
                   style="font-family:monospace">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="dcmNacimiento">Nacimiento</label>
            <input type="date" id="dcmNacimiento">
          </div>
          <div class="form-group">
            <label for="dcmCelular">Celular</label>
            <input type="text" id="dcmCelular" maxlength="20"
                   placeholder="Ej. 11 2345 6789">
          </div>
        </div>
        <div class="form-group">
          <label for="dcmCorreo">Correo</label>
          <input type="email" id="dcmCorreo" maxlength="120"
                 placeholder="empleado@empresa.com">
        </div>
        <div class="form-group">
          <label for="dcmDomicilio">Domicilio</label>
          <input type="text" id="dcmDomicilio" maxlength="200"
                 placeholder="Calle 123, Ciudad, Provincia">
        </div>
        <div class="form-group">
          <label>Cuenta contable (sueldo)</label>
          <button type="button" id="dcmCuentaBtn" data-cuenta-id=""
                  style="text-align:left;width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;gap:8px">
            <span id="dcmCuentaLabel" style="flex:1;color:var(--muted)">— Elegí una cuenta imputable —</span>
            <i class="fa-solid fa-chevron-down" style="color:var(--muted);flex-shrink:0"></i>
          </button>
        </div>
        <div class="form-group">
          <label for="dcmSueldo">Sueldo</label>
          <input type="number" id="dcmSueldo" min="0" step="0.01" placeholder="0.00"
                 style="font-family:monospace;text-align:right">
        </div>
        <div class="form-group">
          <label for="dcmCvu">CVU / CBU / Alias</label>
          <input type="text" id="dcmCvu" maxlength="50"
                 placeholder="Ej. 0000003100099899582443 o alias.banco.mp"
                 style="font-family:monospace">
        </div>
        <div class="form-group">
          <label for="dcmObservaciones">Observaciones</label>
          <textarea id="dcmObservaciones" rows="3" maxlength="1000"
                    placeholder="Notas internas sobre el empleado"></textarea>
        </div>
        <div class="form-group">
          <label class="toggle-switch">
            <input type="checkbox" id="dcmActivo" checked>
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
            <span class="toggle-label">Activo</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">Guardar</button>
      </div>
    </div>
  `);

  if (editando && r) {
    $('#dcmNombre').value        = r.nombre || '';
    $('#dcmDocumento').value     = r.documento || '';
    $('#dcmNacimiento').value    = r.nacimiento || '';
    $('#dcmCelular').value       = r.celular || '';
    $('#dcmCorreo').value        = r.correo || '';
    $('#dcmDomicilio').value     = r.domicilio || '';
    dcmSetCuentaSeleccionada(Number(r.cuenta_id) || 0);
    $('#dcmSueldo').value        = Number(r.sueldo) > 0 ? r.sueldo : '';
    $('#dcmCvu').value           = r.cvu || '';
    $('#dcmObservaciones').value = r.observaciones || '';
    $('#dcmActivo').checked      = r.activo === 'si';
  }

  setTimeout(() => $('#dcmNombre')?.focus(), 50);

  $('#modalRoot').dataset.dcmEmpresa = String(empresaId);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))   closeModal();
    if (ev.target.closest('[data-act="guardar"]')) guardarDcm();
    if (ev.target.closest('#dcmCuentaBtn'))        dcmAbrirPickerCuenta();
  });
}

// Refleja la cuenta elegida en el botón: guarda el id en `data-cuenta-id`
// y muestra el label con código y nombre. Si el id es 0/null, deja el
// placeholder.
function dcmSetCuentaSeleccionada(cuentaId) {
  const btn   = $('#dcmCuentaBtn');
  const label = $('#dcmCuentaLabel');
  if (!btn || !label) return;
  const c = cuentaId ? dcmTodasCuentasCache.find((x) => x.id === cuentaId) : null;
  if (c) {
    btn.dataset.cuentaId = String(c.id);
    label.style.color = '';
    label.innerHTML =
      `<code style="font-family:monospace;font-size:.85rem;margin-right:6px">${esc(c.codigo)}</code>${esc(c.nombre)}`;
  } else {
    btn.dataset.cuentaId = '';
    label.style.color = 'var(--muted)';
    label.textContent = '— Elegí una cuenta imputable —';
  }
}

// ---- Picker de cuentas jerárquico (modal secundario del alta/edición) ----
function dcmCerrarPickerCuenta() {
  const p = $('#dcmPickerRoot');
  if (p) { p.classList.remove('open'); setTimeout(() => p.remove(), 150); }
}

function dcmAbrirPickerCuenta() {
  dcmCerrarPickerCuenta();
  dcmPickerBusqueda = '';

  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.id = 'dcmPickerRoot';
  wrap.style.zIndex = '160';
  wrap.innerHTML = `
    <div class="modal" style="max-width:560px;display:flex;flex-direction:column;max-height:82vh;overflow:hidden">
      <div class="modal-header">
        <div class="modal-title">Seleccionar cuenta</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <input type="search" id="dcmPickerSearch" class="search-input"
               style="width:100%;box-sizing:border-box"
               placeholder="🔍 Buscar por código o nombre…">
      </div>
      <div id="dcmPickerArbol"
           style="overflow-y:auto;flex:1;padding:6px;min-height:240px;background:var(--bg)"></div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-act="limpiar">Sin cuenta</button>
        <button class="btn btn-ghost" data-act="close">Cancelar</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);
  requestAnimationFrame(() => wrap.classList.add('open'));

  wrap.addEventListener('click', (ev) => {
    if (ev.target === wrap) { dcmCerrarPickerCuenta(); return; }
    if (ev.target.closest('[data-act="close"]')) { dcmCerrarPickerCuenta(); return; }
    if (ev.target.closest('[data-act="limpiar"]')) {
      dcmSetCuentaSeleccionada(0);
      dcmCerrarPickerCuenta();
      return;
    }

    const it = ev.target.closest('[data-cuenta-id]');
    if (it) {
      const id = Number(it.dataset.cuentaId);
      const cs = dcmTodasCuentasCache.find((c) => c.id === id);
      if (cs && Number(cs.imputable) === 1 && Number(cs.activa) === 1) {
        dcmSetCuentaSeleccionada(id);
        dcmCerrarPickerCuenta();
      }
      return;
    }
    const tog = ev.target.closest('[data-toggle-id]');
    if (tog) {
      ev.stopPropagation();
      const id = Number(tog.dataset.toggleId);
      if (dcmPickerColapsadas.has(id)) dcmPickerColapsadas.delete(id);
      else dcmPickerColapsadas.add(id);
      dcmRenderArbolPicker();
    }
  });

  $('#dcmPickerSearch').addEventListener('input', (ev) => {
    dcmPickerBusqueda = ev.target.value.trim();
    dcmRenderArbolPicker();
  });

  dcmRenderArbolPicker();
  setTimeout(() => $('#dcmPickerSearch')?.focus(), 50);
}

function dcmRenderArbolPicker() {
  const container = $('#dcmPickerArbol');
  if (!container) return;
  const busq = dcmPickerBusqueda.toLowerCase();
  const cuentaSeleccionada = Number($('#dcmCuentaBtn')?.dataset.cuentaId) || 0;

  if (!dcmTodasCuentasCache.length) {
    container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">No hay cuentas disponibles para esta empresa</div>`;
    return;
  }

  let html = '';

  if (busq) {
    // En modo búsqueda aplanamos: solo cuentas imputables+activas que matcheen.
    const matches = dcmTodasCuentasCache.filter((c) =>
      Number(c.imputable) === 1 && Number(c.activa) === 1 &&
      (c.codigo + ' ' + c.nombre).toLowerCase().includes(busq)
    );
    if (!matches.length) {
      container.innerHTML = `<div style="text-align:center;padding:24px;color:var(--muted)">Sin resultados para "${esc(dcmPickerBusqueda)}"</div>`;
      return;
    }
    matches.forEach((c) => {
      const sel = cuentaSeleccionada === c.id;
      html += `
        <div data-cuenta-id="${c.id}"
             style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;border-radius:6px;${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;font-weight:600' : ''}">
          <code style="font-size:.78rem;flex-shrink:0">${esc(c.codigo)}</code>
          <span style="flex:1">${esc(c.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0">✓</span>' : ''}
        </div>`;
    });
  } else {
    // Vista árbol: agrupaciones (imputable=0) se muestran pero no se pueden elegir.
    const byId = {};
    dcmTodasCuentasCache.forEach((c) => { byId[c.id] = Object.assign({}, c, { children: [] }); });
    const raices = [];
    dcmTodasCuentasCache.forEach((c) => {
      if (c.parent_id && byId[c.parent_id]) byId[c.parent_id].children.push(byId[c.id]);
      else raices.push(byId[c.id]);
    });

    const walk = (nodo, depth) => {
      const isImputable = Number(nodo.imputable) === 1 && Number(nodo.activa) === 1;
      const tieneHijos  = nodo.children.length > 0;
      const colapsado   = dcmPickerColapsadas.has(nodo.id);
      const sel         = cuentaSeleccionada === nodo.id;
      const pl          = 8 + depth * 20;

      const toggle = tieneHijos
        ? `<span data-toggle-id="${nodo.id}" style="width:18px;text-align:center;display:inline-block;flex-shrink:0;cursor:pointer">${colapsado ? '▶' : '▼'}</span>`
        : `<span style="width:18px;display:inline-block;flex-shrink:0"></span>`;

      html += `
        <div ${isImputable ? `data-cuenta-id="${nodo.id}"` : ''}
             style="display:flex;align-items:center;gap:4px;padding:7px 8px 7px ${pl}px;border-radius:6px;
                    cursor:${isImputable ? 'pointer' : 'default'};
                    ${sel ? 'background:rgba(59,130,246,.18);color:#93c5fd;' : !isImputable ? 'color:var(--muted);' : ''}
                    font-weight:${Number(nodo.imputable) === 0 ? '700' : '400'}">
          ${toggle}
          <code style="font-size:.78rem;flex-shrink:0;margin-right:4px">${esc(nodo.codigo)}</code>
          <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(nodo.nombre)}</span>
          ${sel ? '<span style="flex-shrink:0;margin-left:4px">✓</span>' : ''}
        </div>`;

      if (tieneHijos && !colapsado) {
        nodo.children.forEach((h) => walk(h, depth + 1));
      }
    };
    raices.forEach((r) => walk(r, 0));
  }

  container.innerHTML = html;
}

async function guardarDcm() {
  const nombre        = ($('#dcmNombre').value || '').trim();
  const documento     = ($('#dcmDocumento').value || '').trim();
  const nacimiento    = ($('#dcmNacimiento').value || '').trim();
  const domicilio     = ($('#dcmDomicilio').value || '').trim();
  const celular       = ($('#dcmCelular').value || '').trim();
  const correo        = ($('#dcmCorreo').value || '').trim();
  const cvu           = ($('#dcmCvu').value || '').trim();
  const observaciones = ($('#dcmObservaciones').value || '').trim();
  const empresa_id    = Number($('#modalRoot').dataset.dcmEmpresa) || 0;
  const cuentaRaw     = $('#dcmCuentaBtn')?.dataset.cuentaId || '';
  const cuenta_id     = cuentaRaw ? Number(cuentaRaw) : null;
  const sueldo        = Number($('#dcmSueldo').value) || 0;
  const activo        = $('#dcmActivo').checked ? 'si' : 'no';

  if (!nombre)     { toast('El nombre es obligatorio', { error: true }); return; }
  if (!empresa_id) { toast('La empresa es obligatoria', { error: true }); return; }
  if (sueldo < 0)  { toast('El sueldo no puede ser negativo', { error: true }); return; }

  const body = {
    nombre, documento, nacimiento, domicilio, celular, correo,
    empresa_id, cuenta_id, sueldo, cvu, activo, observaciones,
  };

  try {
    if (dcmEditandoId) {
      await apiSend(`${DCM_API}?id=${dcmEditandoId}`, 'PUT', body);
      toast('Empleado actualizado');
    } else {
      await apiSend(DCM_API, 'POST', body);
      toast('Empleado creado');
    }
    closeModal();
    dcmEditandoId = null;
    await cargarDcm();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ---- Modal Consulta ----
function abrirConsultaDcm(id) {
  const r = dcmItems.find((x) => x.id === id);
  if (!r) return;

  const card = (label, valor, ancho) => `
    <div style="flex:${ancho === 'full' ? '1 1 100%' : '1 1 calc(50% - 6px)'};
                background:color-mix(in srgb, var(--surface) 90%, #000);
                border:none;border-radius:12px;padding:12px 14px">
      <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:4px">${esc(label)}</div>
      <div style="font-size:.92rem">${valor}</div>
    </div>
  `;

  const cuentaLabel = r.cuenta_codigo
    ? `<code style="font-family:monospace">${esc(r.cuenta_codigo)}</code> — ${esc(r.cuenta_nombre || '')}`
    : (r.cuenta_id ? `#${r.cuenta_id}` : '<span style="color:var(--muted)">—</span>');
  const sueldoHtml = Number(r.sueldo) > 0
    ? `<span style="color:var(--success);font-weight:600;font-family:monospace">$ ${dcmFmtMoney(r.sueldo)}</span>`
    : `<span style="color:var(--muted)">—</span>`;
  const activoHtml = r.activo === 'si'
    ? `<span class="badge badge-success">Activo</span>`
    : `<span class="badge">Inactivo</span>`;
  const cvuHtml = r.cvu
    ? `<code style="font-family:monospace">${esc(r.cvu)}</code>`
    : '<span style="color:var(--muted)">—</span>';

  openModal(`
    <div class="modal" style="max-width:680px">
      <div class="modal-header">
        <div class="modal-title">
          👤 <span class="modal-subtitle">Empleado #${r.id}</span>
        </div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="display:flex;flex-wrap:wrap;gap:12px">
          ${card('Empresa',     esc(r.empresa_nombre || '#' + r.empresa_id), 'full')}
          ${card('Nombre',      esc(r.nombre || '—'), 'full')}
          ${card('Documento',   r.documento ? `<code>${esc(r.documento)}</code>` : '<span style="color:var(--muted)">—</span>')}
          ${card('Nacimiento',  r.nacimiento ? esc(fmtFecha(r.nacimiento)) : '<span style="color:var(--muted)">—</span>')}
          ${card('Celular',     r.celular ? esc(r.celular) : '<span style="color:var(--muted)">—</span>')}
          ${card('Correo',      r.correo ? esc(r.correo) : '<span style="color:var(--muted)">—</span>')}
          ${card('Domicilio',   r.domicilio ? esc(r.domicilio) : '<span style="color:var(--muted)">—</span>', 'full')}
          ${card('Cuenta',      cuentaLabel, 'full')}
          ${card('Sueldo',      sueldoHtml)}
          ${card('CVU / CBU',   cvuHtml, 'full')}
          ${card('Estado',      activoHtml)}
          ${card('Observaciones', r.observaciones ? esc(r.observaciones) : '<span style="color:var(--muted)">—</span>', 'full')}
          ${card('Código',      `<code>${r.id}</code>`)}
          ${card('Alta',        esc(fmtFecha(r.created_at)))}
          ${card('Modificación', esc(fmtFecha(r.updated_at)))}
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDcm(id); }
  });
}

// ---- Modal Copiar a otra empresa ----
async function abrirCopiarDcm(id) {
  const r = dcmItems.find((x) => x.id === id);
  if (!r) return;

  const empresas = await dcGetEmpresas();
  const opciones = empresas
    .filter((e) => e.id !== r.empresa_id)
    .map((e) => `<option value="${e.id}">${esc(e.nombre)}</option>`)
    .join('');

  if (!opciones) {
    toast('No hay otras empresas donde copiar el empleado', { error: true });
    return;
  }

  openModal(`
    <div class="modal" style="max-width:480px">
      <div class="modal-header">
        <div class="modal-title">📋 <span class="modal-subtitle">Copiar empleado</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="margin-bottom:14px;font-size:.9rem">
          Vas a copiar el empleado <strong>${esc(r.nombre || '#' + r.id)}</strong>
          desde <strong>${esc(r.empresa_nombre || '#' + r.empresa_id)}</strong> a otra empresa.
        </div>
        <div class="form-group">
          <label for="dcmCopiarEmpresa">Empresa destino *</label>
          <select id="dcmCopiarEmpresa">${opciones}</select>
        </div>
        <div style="font-size:.78rem;color:var(--muted);line-height:1.4;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px">
          <i class="fa-solid fa-circle-info"></i>
          La <strong>cuenta contable</strong> no se copia (queda sin asignar) porque
          las cuentas son específicas de cada empresa. Todos los demás campos se
          replican tal cual.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="copiar">Copiar</button>
      </div>
    </div>
  `);

  setTimeout(() => $('#dcmCopiarEmpresa')?.focus(), 50);

  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]')) closeModal();
    if (ev.target.closest('[data-act="copiar"]')) {
      const destinoId = Number($('#dcmCopiarEmpresa').value) || 0;
      ejecutarCopiaDcm(r, destinoId);
    }
  });
}

async function ejecutarCopiaDcm(origen, empresaDestino) {
  if (!empresaDestino || empresaDestino === origen.empresa_id) {
    toast('Elegí una empresa distinta a la del empleado', { error: true });
    return;
  }
  const body = {
    empresa_id:    empresaDestino,
    nombre:        origen.nombre,
    documento:     origen.documento,
    nacimiento:    origen.nacimiento,
    domicilio:     origen.domicilio,
    celular:       origen.celular,
    correo:        origen.correo,
    cuenta_id:     null,
    sueldo:        origen.sueldo,
    cvu:           origen.cvu,
    activo:        origen.activo,
    observaciones: origen.observaciones,
  };
  try {
    await apiSend(DCM_API, 'POST', body);
    toast('Empleado copiado');
    closeModal();
    await cargarDcm();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

async function eliminarDcm(id) {
  const r = dcmItems.find((x) => x.id === id);
  if (!r) return;
  const desc = r.nombre || `#${id}`;
  const ok = await confirmar({
    title:       'Eliminar empleado',
    message:     `¿Eliminás el empleado "${desc}"?`,
    confirmText: 'Eliminar',
    danger:      true,
  });
  if (!ok) return;
  try {
    await apiSend(`${DCM_API}?id=${id}`, 'DELETE');
    toast('Empleado eliminado');
    await cargarDcm();
  } catch (err) {
    toast(err.message, { error: true });
  }
}

// ------------------------- Vista: Datarocket > Mensajes (ABM) -------------------------
const drMsgFiltrosDefaults = {
  q: '', codigo: '', medio: '', proyecto: '', canal: '', campana: '', contacto: '',
  estado: '', resultado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const drMsgFiltros = { ...drMsgFiltrosDefaults };
let drMsgBuscadorTimer   = null;
let drMsgFiltrosSnapshot = null;

const DR_MSG_MEDIO_MAP = {
  C: { label: 'Correo',   icon: 'fa-envelope' },
  W: { label: 'WhatsApp', icon: 'fa-whatsapp' },
  S: { label: 'SMS',      icon: 'fa-comment-sms' },
  T: { label: 'Telegram', icon: 'fa-telegram' },
  P: { label: 'Push',     icon: 'fa-bell' },
};
const DR_MSG_FORMATO_MAP = {
  T: 'Texto plano',
  H: 'HTML',
  M: 'Markdown',
};
const DR_MSG_PRIORIDAD_MAP = {
  A: 'Alta',
  N: 'Normal',
  B: 'Baja',
};

function drMsgMedioBadge(m) {
  if (m == null || m === '') return `<span class="badge badge-info">—</span>`;
  const info = DR_MSG_MEDIO_MAP[m];
  if (!info) return `<span class="badge badge-info">${esc(m)}</span>`;
  return `<span class="badge badge-info"><i class="fa-solid ${info.icon}"></i> ${esc(info.label)}</span>`;
}

function drMsgEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    P: 'badge-warn',   // Pendiente
    E: 'badge-success',// Enviado
    F: 'badge-danger', // Fallado
    C: 'badge-danger', // Cancelado
    R: 'badge-info',   // Reintento
  };
  const labelMap = {
    P: 'Pendiente', E: 'Enviado', F: 'Fallado', C: 'Cancelado', R: 'Reintento',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

function drMsgResultadoBadge(r) {
  if (r == null || r === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = { O: 'badge-success', F: 'badge-danger', P: 'badge-warn' };
  const labelMap = { O: 'OK', F: 'Fallo', P: 'Pendiente' };
  const cls = colorMap[r] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[r] || r)}</span>`;
}

function drMsgFmtDemora(seg) {
  if (seg == null || seg === '' || isNaN(Number(seg))) return '—';
  const n = Number(seg);
  if (n < 60)    return `${n}s`;
  if (n < 3600)  return `${Math.round(n / 60)}m`;
  return `${(n / 3600).toFixed(1)}h`;
}

route('/datarocketmensajes', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">✉️</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los mensajes de Datarocket son los envíos individuales de correo, WhatsApp,
          SMS y demás medios que el motor genera a partir de las campañas y
          plantillas, con su destinatario, cuerpo, estado y resultado del envío.
        </div>
      </div>

      <div class="stats-bar" id="drMsgStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Enviados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Con error</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="drMsgSearch"
                   placeholder="🔍 Buscar destinatario, destino, asunto o remitente…">
            <button class="search-clear" id="drMsgSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="drMsgFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="drMsgFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="drMsgRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="drMsgNuevoBtn">+ Nuevo mensaje</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Medio</th>
              <th>Destinatario</th>
              <th>Destino</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Resultado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="drMsgTbody">
            <tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual único de la sección -->
    <div id="drMsgCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros -->
    <div class="modal-backdrop" id="filtrosDrMsgBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDrMsg()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDrMsg()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDrMsgCodigo" min="1" placeholder="ID …" oninput="onFiltroDrMsg('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Medio</label>
              <select id="fDrMsgMedio" onchange="onFiltroDrMsg('medio', this.value)">
                <option value="">— Todos —</option>
                <option value="C">Correo</option>
                <option value="W">WhatsApp</option>
                <option value="S">SMS</option>
                <option value="T">Telegram</option>
                <option value="P">Push</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Proyecto (ID)</label>
              <input type="number" id="fDrMsgProyecto" min="1" oninput="onFiltroDrMsg('proyecto', this.value)">
            </div>
            <div class="form-group">
              <label>Canal (ID)</label>
              <input type="number" id="fDrMsgCanal" min="1" oninput="onFiltroDrMsg('canal', this.value)">
            </div>
            <div class="form-group">
              <label>Campaña (ID)</label>
              <input type="number" id="fDrMsgCampana" min="1" oninput="onFiltroDrMsg('campana', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Contacto (ID)</label>
              <input type="number" id="fDrMsgContacto" min="1" oninput="onFiltroDrMsg('contacto', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fDrMsgEstado" onchange="onFiltroDrMsg('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="P">Pendiente</option>
                <option value="E">Enviado</option>
                <option value="F">Fallado</option>
                <option value="C">Cancelado</option>
                <option value="R">Reintento</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Resultado</label>
              <select id="fDrMsgResultado" onchange="onFiltroDrMsg('resultado', this.value)">
                <option value="">— Todos —</option>
                <option value="O">OK</option>
                <option value="F">Fallo</option>
                <option value="P">Pendiente</option>
              </select>
            </div>
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fDrMsgDesde" onchange="onFiltroDrMsg('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fDrMsgHasta" onchange="onFiltroDrMsg('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDrMsgLimite" min="1" max="1000" value="100" onchange="onFiltroDrMsg('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDrMsgOrderBy" onchange="onFiltroDrMsg('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="medio">Medio</option>
                <option value="destinatario">Destinatario</option>
                <option value="destino">Destino</option>
                <option value="asunto">Asunto</option>
                <option value="estado">Estado</option>
                <option value="resultado">Resultado</option>
                <option value="enviado">Enviado</option>
                <option value="demora">Demora</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDrMsgDir" onchange="onFiltroDrMsg('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDrMsg()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDrMsg()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDrMsg()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#drMsgNuevoBtn').addEventListener('click', () => abrirAltaEdicionDrMsg(null));
  $('#drMsgFiltrosBtn').addEventListener('click', () => abrirModalFiltrosDrMsg());
  $('#drMsgRefrescarBtn').addEventListener('click', () => cargarDrMsg());

  const inp = $('#drMsgSearch');
  const clr = $('#drMsgSearchClear');
  inp.value = drMsgFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    drMsgFiltros.q = inp.value.trim();
    clearTimeout(drMsgBuscadorTimer);
    drMsgBuscadorTimer = setTimeout(() => { cargarDrMsg(); refrescarBadgeFiltrosDrMsg(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    drMsgFiltros.q = '';
    cargarDrMsg();
    refrescarBadgeFiltrosDrMsg();
  });

  // Acciones del menú contextual
  $('#drMsgCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarDrMsg(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDrMsg(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDrMsg(data.id);
  });

  // Clic en fila → consultar; clic en hamburguesa → menú
  $('#drMsgTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#drMsgCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarDrMsg(Number(tr.dataset.id));
  });
  $('#drMsgTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#drMsgCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosDrMsg();
  await cargarDrMsg();
}, 'Mensajes');

async function cargarDrMsg() {
  const tbody = $('#drMsgTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(drMsgFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/datarocketmensajes.php?' + qs.toString());
    pintarStatsDrMsg(data.stats);
    pintarTablaDrMsg(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDrMsg(s) {
  const cards = $$('#drMsgStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.enviados);
  cards[2].textContent = fmtNum(s.con_error);
}

function pintarTablaDrMsg(rows) {
  const tbody = $('#drMsgTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Sin mensajes.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((m) => `
    <tr data-id="${m.id}" class="row-clickable">
      <td class="td-id">#${esc(m.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(m.fecha))}</td>
      <td>${drMsgMedioBadge(m.medio)}</td>
      <td class="td-nombre">${esc(m.destinatario || '—')}</td>
      <td style="font-family:monospace">${esc(m.destino || '—')}</td>
      <td>${esc(m.asunto || '—')}</td>
      <td>${drMsgEstadoBadge(m.estado)}</td>
      <td>${drMsgResultadoBadge(m.resultado)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${m.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de Filtros ----
function onFiltroDrMsg(key, value) {
  if (['medio', 'estado', 'resultado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    drMsgFiltros[key] = value;
  } else if (['codigo', 'proyecto', 'canal', 'campana', 'contacto'].includes(key)) {
    const v = String(value).trim();
    drMsgFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    drMsgFiltros.limite = n;
  } else {
    drMsgFiltros[key] = value;
  }
  refrescarBadgeFiltrosDrMsg();
  cargarDrMsg();
}

function refrescarBadgeFiltrosDrMsg() {
  const btn   = $('#drMsgFiltrosBtn');
  const badge = $('#drMsgFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(drMsgFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(drMsgFiltros[k]) !== String(drMsgFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosDrMsg() {
  const f = drMsgFiltros;
  $('#fDrMsgCodigo').value    = f.codigo;
  $('#fDrMsgMedio').value     = f.medio;
  $('#fDrMsgProyecto').value  = f.proyecto;
  $('#fDrMsgCanal').value     = f.canal;
  $('#fDrMsgCampana').value   = f.campana;
  $('#fDrMsgContacto').value  = f.contacto;
  $('#fDrMsgEstado').value    = f.estado;
  $('#fDrMsgResultado').value = f.resultado;
  $('#fDrMsgDesde').value     = f.desde;
  $('#fDrMsgHasta').value     = f.hasta;
  $('#fDrMsgLimite').value    = f.limite;
  $('#fDrMsgOrderBy').value   = f.order_by;
  $('#fDrMsgDir').value       = f.dir;
}

function abrirModalFiltrosDrMsg() {
  drMsgFiltrosSnapshot = { ...drMsgFiltros };
  sincronizarControlesFiltrosDrMsg();
  $('#filtrosDrMsgBackdrop').classList.add('open');
}

function cerrarModalFiltrosDrMsg() {
  $('#filtrosDrMsgBackdrop').classList.remove('open');
}

function cancelarFiltrosDrMsg() {
  if (drMsgFiltrosSnapshot) {
    Object.assign(drMsgFiltros, drMsgFiltrosSnapshot);
    refrescarBadgeFiltrosDrMsg();
    cargarDrMsg();
  }
  cerrarModalFiltrosDrMsg();
}

function limpiarFiltrosDrMsg() {
  Object.assign(drMsgFiltros, drMsgFiltrosDefaults);
  drMsgFiltros.q = $('#drMsgSearch')?.value.trim() || '';
  sincronizarControlesFiltrosDrMsg();
  refrescarBadgeFiltrosDrMsg();
  cargarDrMsg();
}

// Exponer para los onclick del HTML
window.onFiltroDrMsg           = onFiltroDrMsg;
window.cancelarFiltrosDrMsg    = cancelarFiltrosDrMsg;
window.limpiarFiltrosDrMsg     = limpiarFiltrosDrMsg;
window.cerrarModalFiltrosDrMsg = cerrarModalFiltrosDrMsg;

// ---- Modal Consultar ----
async function abrirConsultarDrMsg(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1200px">
      <div class="modal-header">
        <div class="modal-title">Mensaje <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDrMsg(id); }
  });

  try {
    const m = await apiGet(`api/datarocketmensajes.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaDrMsg(m);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaDrMsg(m) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  const cuerpoHtml = m.cuerpo && String(m.cuerpo).trim() !== ''
    ? (m.formato === 'H'
        ? `<iframe srcdoc="${esc(m.cuerpo)}" style="width:100%;min-height:280px;border:1px solid var(--border);border-radius:8px;background:white"></iframe>`
        : `<pre style="white-space:pre-wrap;font-family:monospace;background:color-mix(in srgb, var(--surface) 90%, #000);padding:14px;border-radius:8px;margin:0;font-size:.85rem;line-height:1.5">${esc(m.cuerpo)}</pre>`)
    : `<div style="color:var(--muted);font-style:italic">Sin cuerpo</div>`;

  return `
    <!-- Encabezado -->
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.3rem;font-weight:700">${esc(m.destinatario || '—')}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(m.destino || '')}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(m.asunto || 'Sin asunto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(m.id)} · UUID <code>${esc(m.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${drMsgMedioBadge(m.medio)}</div>
        <div>${drMsgEstadoBadge(m.estado)} ${drMsgResultadoBadge(m.resultado)}</div>
        <div style="margin-top:6px;font-size:.85rem;line-height:1.5">
          <div><span style="color:var(--muted)">Fecha:</span> ${esc(fmtFecha(m.fecha))}</div>
          <div><span style="color:var(--muted)">Enviado:</span> ${esc(fmtFecha(m.enviado))}</div>
        </div>
      </div>
    </div>

    ${seccion('Cuerpo del mensaje')}
    ${cuerpoHtml}

    ${seccion('Remitente y destinatario')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Remitente',    m.remitente)}
      ${card('Remite',       m.remite, false, true)}
      ${card('Destinatario', m.destinatario)}
      ${card('Destino',      m.destino, false, true)}
    </dl>

    ${seccion('Contexto de envío')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Proyecto',    m.proyecto)}
      ${card('Canal',       m.canal)}
      ${card('Servicio',    m.servicio)}
      ${card('Campaña',     m.campana)}
      ${card('Plantilla',   m.plantilla)}
      ${card('Contacto',    m.contacto)}
      ${card('Suscripción', m.suscripcion)}
      ${card('Prioridad',   DR_MSG_PRIORIDAD_MAP[m.prioridad] || m.prioridad)}
      ${card('Formato',     DR_MSG_FORMATO_MAP[m.formato]     || m.formato)}
    </dl>

    ${seccion('Tiempos y resultado')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Fecha',        fmtFecha(m.fecha))}
      ${card('Transmitido',  fmtFecha(m.transmitido))}
      ${card('Enviado',      fmtFecha(m.enviado))}
      ${card('Demora',       drMsgFmtDemora(m.demora))}
      ${card('Estado',       m.estado)}
      ${card('Resultado',    m.resultado)}
    </dl>

    ${seccion('Media y errores')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Media',  m.media, true, true)}
      ${card('Error',  m.error, true)}
    </dl>
  `;
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicionDrMsg(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar mensaje <span class="modal-subtitle">#${id}</span>` : 'Nuevo mensaje'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formDrMsgHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const m = await apiGet(`api/datarocketmensajes.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formDrMsgHtml(m);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarDrMsg(id, a);
  });
}

function formDrMsgHtml(m) {
  const v   = (k) => esc(m?.[k] ?? '');
  const sel = (k, val) => (m?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = m?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="drmFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Medio</label>
        <select id="drmMedio">
          <option value=""  ${sel('medio','')}>—</option>
          <option value="C" ${sel('medio','C')}>Correo</option>
          <option value="W" ${sel('medio','W')}>WhatsApp</option>
          <option value="S" ${sel('medio','S')}>SMS</option>
          <option value="T" ${sel('medio','T')}>Telegram</option>
          <option value="P" ${sel('medio','P')}>Push</option>
        </select>
      </div>
      <div class="form-group">
        <label>Prioridad</label>
        <select id="drmPrioridad">
          <option value=""  ${sel('prioridad','')}>—</option>
          <option value="A" ${sel('prioridad','A')}>Alta</option>
          <option value="N" ${sel('prioridad','N')}>Normal</option>
          <option value="B" ${sel('prioridad','B')}>Baja</option>
        </select>
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Proyecto (ID)</label>
        <input type="number" id="drmProyecto" min="1" value="${v('proyecto')}">
      </div>
      <div class="form-group">
        <label>Servicio (ID)</label>
        <input type="number" id="drmServicio" min="1" value="${v('servicio')}">
      </div>
      <div class="form-group">
        <label>Canal (ID)</label>
        <input type="number" id="drmCanal" min="1" value="${v('canal')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Campaña (ID)</label>
        <input type="number" id="drmCampana" min="1" value="${v('campana')}">
      </div>
      <div class="form-group">
        <label>Plantilla (ID)</label>
        <input type="number" id="drmPlantilla" min="1" value="${v('plantilla')}">
      </div>
      <div class="form-group">
        <label>Suscripción (ID)</label>
        <input type="number" id="drmSuscripcion" min="1" value="${v('suscripcion')}">
      </div>
    </div>
    <div class="form-group">
      <label>Contacto (ID)</label>
      <input type="number" id="drmContacto" min="1" value="${v('contacto')}">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Remitente</label>
        <input type="text" id="drmRemitente" maxlength="255" value="${v('remitente')}">
      </div>
      <div class="form-group">
        <label>Remite</label>
        <input type="text" id="drmRemite" maxlength="255" value="${v('remite')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Destinatario</label>
        <input type="text" id="drmDestinatario" maxlength="255" value="${v('destinatario')}">
      </div>
      <div class="form-group">
        <label>Destino</label>
        <input type="text" id="drmDestino" maxlength="255" value="${v('destino')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Asunto</label>
      <input type="text" id="drmAsunto" maxlength="500" value="${v('asunto')}">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Formato</label>
        <select id="drmFormato">
          <option value=""  ${sel('formato','')}>—</option>
          <option value="T" ${sel('formato','T')}>Texto plano</option>
          <option value="H" ${sel('formato','H')}>HTML</option>
          <option value="M" ${sel('formato','M')}>Markdown</option>
        </select>
      </div>
      <div class="form-group">
        <label>Media (URL/JSON)</label>
        <input type="text" id="drmMedia" maxlength="1000" value="${v('media')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Cuerpo</label>
      <textarea id="drmCuerpo" rows="8" style="font-family:monospace">${v('cuerpo')}</textarea>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Estado</label>
        <select id="drmEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="E" ${sel('estado','E')}>Enviado</option>
          <option value="F" ${sel('estado','F')}>Fallado</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="R" ${sel('estado','R')}>Reintento</option>
        </select>
      </div>
      <div class="form-group">
        <label>Resultado</label>
        <select id="drmResultado">
          <option value=""  ${sel('resultado','')}>—</option>
          <option value="O" ${sel('resultado','O')}>OK</option>
          <option value="F" ${sel('resultado','F')}>Fallo</option>
          <option value="P" ${sel('resultado','P')}>Pendiente</option>
        </select>
      </div>
      <div class="form-group">
        <label>Demora (seg.)</label>
        <input type="number" id="drmDemora" min="0" value="${v('demora')}">
      </div>
    </div>
    <div class="form-group">
      <label>Error</label>
      <textarea id="drmErrorTxt" rows="2" maxlength="1000">${v('error')}</textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Transmitido</label>
        <input type="datetime-local" id="drmTransmitido" value="${dt('transmitido')}">
      </div>
      <div class="form-group">
        <label>Enviado</label>
        <input type="datetime-local" id="drmEnviado" value="${dt('enviado')}">
      </div>
    </div>
    <div class="field-error" id="drmFormError" style="display:none"></div>
  `;
}

async function guardarDrMsg(id, btn) {
  const err = $('#drmFormError');
  err.style.display = 'none';

  const payload = {
    fecha:        $('#drmFecha').value || null,
    medio:        $('#drmMedio').value,
    prioridad:    $('#drmPrioridad').value,
    proyecto:     $('#drmProyecto').value,
    servicio:     $('#drmServicio').value,
    canal:        $('#drmCanal').value,
    campana:      $('#drmCampana').value,
    plantilla:    $('#drmPlantilla').value,
    suscripcion:  $('#drmSuscripcion').value,
    contacto:     $('#drmContacto').value,
    remitente:    $('#drmRemitente').value.trim(),
    remite:       $('#drmRemite').value.trim(),
    destinatario: $('#drmDestinatario').value.trim(),
    destino:      $('#drmDestino').value.trim(),
    asunto:       $('#drmAsunto').value.trim(),
    formato:      $('#drmFormato').value,
    media:        $('#drmMedia').value.trim(),
    cuerpo:       $('#drmCuerpo').value,
    estado:       $('#drmEstado').value,
    resultado:    $('#drmResultado').value,
    demora:       $('#drmDemora').value,
    error:        $('#drmErrorTxt').value,
    transmitido:  $('#drmTransmitido').value || null,
    enviado:      $('#drmEnviado').value || null,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/datarocketmensajes.php', 'POST', payload);
      toast('Mensaje creado.');
    } else {
      await apiSend(`api/datarocketmensajes.php?id=${id}`, 'PUT', payload);
      toast('Mensaje actualizado.');
    }
    closeModal();
    cargarDrMsg();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarDrMsg(id) {
  const ok = await confirmar({
    title: 'Eliminar mensaje',
    message: `Se eliminará el mensaje #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/datarocketmensajes.php?id=${id}`, 'DELETE');
    toast('Mensaje eliminado.');
    cargarDrMsg();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Datarocket > Contactos (ABM) -------------------------
const drCtFiltrosDefaults = {
  q: '', codigo: '', estado: '', verificacion: '', genero: '',
  origen: '', pais: '', provincia: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const drCtFiltros = { ...drCtFiltrosDefaults };
let drCtBuscadorTimer   = null;
let drCtFiltrosSnapshot = null;

const DR_CT_GENERO_MAP = { M: 'Masculino', F: 'Femenino', X: 'Otro' };

function drCtEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  return `<span class="badge badge-info">${esc(e)}</span>`;
}

function drCtVerificacionBadge(v) {
  if (v == null || v === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    O: 'badge-success',
    V: 'badge-success',
    E: 'badge-danger',
    F: 'badge-danger',
    P: 'badge-warn',
  };
  const labelMap = { O: 'OK', V: 'Válido', E: 'Error', F: 'Fallado', P: 'Pendiente' };
  const cls = colorMap[v] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[v] || v)}</span>`;
}

route('/datarocketcontactos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">👥</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los contactos de Datarocket son las personas y empresas registradas en la
          base del motor de envíos, con sus datos personales, medios de contacto,
          suscripciones a listas y el resultado de la verificación previa al envío.
        </div>
      </div>

      <div class="stats-bar" id="drCtStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Verificados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Con error</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="drCtSearch"
                   placeholder="🔍 Buscar nombre, empresa, correo, teléfono, celular, whatsapp, DNI o UUID…">
            <button class="search-clear" id="drCtSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="drCtFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="drCtFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="drCtRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="drCtNuevoBtn">+ Nuevo contacto</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Empresa</th>
              <th>Correo</th>
              <th>Teléfono</th>
              <th>País</th>
              <th>Estado</th>
              <th>Verificación</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="drCtTbody">
            <tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="drCtCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosDrCtBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDrCt()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDrCt()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDrCtCodigo" min="1" placeholder="ID …" oninput="onFiltroDrCt('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Origen</label>
              <input type="text" id="fDrCtOrigen" maxlength="255" placeholder="ej. web, importado…"
                     oninput="onFiltroDrCt('origen', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Estado</label>
              <input type="text" id="fDrCtEstado" maxlength="1" style="font-family:monospace"
                     placeholder="A/I/…" oninput="onFiltroDrCt('estado', this.value)">
            </div>
            <div class="form-group">
              <label>Verificación</label>
              <input type="text" id="fDrCtVerificacion" maxlength="1" style="font-family:monospace"
                     placeholder="O/V/E/P…" oninput="onFiltroDrCt('verificacion', this.value)">
            </div>
            <div class="form-group">
              <label>Género</label>
              <select id="fDrCtGenero" onchange="onFiltroDrCt('genero', this.value)">
                <option value="">— Todos —</option>
                <option value="M">Masculino</option>
                <option value="F">Femenino</option>
                <option value="X">Otro</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>País</label>
              <input type="text" id="fDrCtPais" maxlength="255" oninput="onFiltroDrCt('pais', this.value)">
            </div>
            <div class="form-group">
              <label>Provincia</label>
              <input type="text" id="fDrCtProvincia" maxlength="255" oninput="onFiltroDrCt('provincia', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde (registrado)</label>
              <input type="date" id="fDrCtDesde" onchange="onFiltroDrCt('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta (registrado)</label>
              <input type="date" id="fDrCtHasta" onchange="onFiltroDrCt('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDrCtLimite" min="1" max="1000" value="100" onchange="onFiltroDrCt('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDrCtOrderBy" onchange="onFiltroDrCt('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="empresa">Empresa</option>
                <option value="correo">Correo</option>
                <option value="registrado">Registrado</option>
                <option value="completado">Completado</option>
                <option value="estado">Estado</option>
                <option value="verificacion">Verificación</option>
                <option value="pais">País</option>
                <option value="provincia">Provincia</option>
                <option value="origen">Origen</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDrCtDir" onchange="onFiltroDrCt('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDrCt()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDrCt()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDrCt()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#drCtNuevoBtn').addEventListener('click', () => abrirAltaEdicionDrCt(null));
  $('#drCtFiltrosBtn').addEventListener('click', () => abrirModalFiltrosDrCt());
  $('#drCtRefrescarBtn').addEventListener('click', () => cargarDrCt());

  const inp = $('#drCtSearch');
  const clr = $('#drCtSearchClear');
  inp.value = drCtFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    drCtFiltros.q = inp.value.trim();
    clearTimeout(drCtBuscadorTimer);
    drCtBuscadorTimer = setTimeout(() => { cargarDrCt(); refrescarBadgeFiltrosDrCt(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    drCtFiltros.q = '';
    cargarDrCt();
    refrescarBadgeFiltrosDrCt();
  });

  $('#drCtCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarDrCt(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDrCt(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDrCt(data.id);
  });

  $('#drCtTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#drCtCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarDrCt(Number(tr.dataset.id));
  });
  $('#drCtTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#drCtCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosDrCt();
  await cargarDrCt();
}, 'Contactos');

async function cargarDrCt() {
  const tbody = $('#drCtTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(drCtFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/datarocketcontactos.php?' + qs.toString());
    pintarStatsDrCt(data.stats);
    pintarTablaDrCt(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDrCt(s) {
  const cards = $$('#drCtStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.verificados);
  cards[2].textContent = fmtNum(s.con_error);
}

function pintarTablaDrCt(rows) {
  const tbody = $('#drCtTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Sin contactos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td class="td-nombre">${esc(c.nombre || '—')}</td>
      <td>${esc(c.empresa || '—')}</td>
      <td style="font-family:monospace">${esc(c.correo || '—')}</td>
      <td style="font-family:monospace">${esc(c.telefono || c.celular || c.whatsapp || '—')}</td>
      <td>${esc(c.pais || '—')}</td>
      <td>${drCtEstadoBadge(c.estado)}</td>
      <td>${drCtVerificacionBadge(c.verificacion)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroDrCt(key, value) {
  if (['estado', 'verificacion', 'genero', 'origen', 'pais', 'provincia',
       'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    drCtFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    drCtFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    drCtFiltros.limite = n;
  } else {
    drCtFiltros[key] = value;
  }
  refrescarBadgeFiltrosDrCt();
  cargarDrCt();
}

function refrescarBadgeFiltrosDrCt() {
  const btn   = $('#drCtFiltrosBtn');
  const badge = $('#drCtFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(drCtFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(drCtFiltros[k]) !== String(drCtFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosDrCt() {
  const f = drCtFiltros;
  $('#fDrCtCodigo').value       = f.codigo;
  $('#fDrCtOrigen').value       = f.origen;
  $('#fDrCtEstado').value       = f.estado;
  $('#fDrCtVerificacion').value = f.verificacion;
  $('#fDrCtGenero').value       = f.genero;
  $('#fDrCtPais').value         = f.pais;
  $('#fDrCtProvincia').value    = f.provincia;
  $('#fDrCtDesde').value        = f.desde;
  $('#fDrCtHasta').value        = f.hasta;
  $('#fDrCtLimite').value       = f.limite;
  $('#fDrCtOrderBy').value      = f.order_by;
  $('#fDrCtDir').value          = f.dir;
}

function abrirModalFiltrosDrCt() {
  drCtFiltrosSnapshot = { ...drCtFiltros };
  sincronizarControlesFiltrosDrCt();
  $('#filtrosDrCtBackdrop').classList.add('open');
}
function cerrarModalFiltrosDrCt() { $('#filtrosDrCtBackdrop').classList.remove('open'); }
function cancelarFiltrosDrCt() {
  if (drCtFiltrosSnapshot) {
    Object.assign(drCtFiltros, drCtFiltrosSnapshot);
    refrescarBadgeFiltrosDrCt();
    cargarDrCt();
  }
  cerrarModalFiltrosDrCt();
}
function limpiarFiltrosDrCt() {
  Object.assign(drCtFiltros, drCtFiltrosDefaults);
  drCtFiltros.q = $('#drCtSearch')?.value.trim() || '';
  sincronizarControlesFiltrosDrCt();
  refrescarBadgeFiltrosDrCt();
  cargarDrCt();
}
window.onFiltroDrCt           = onFiltroDrCt;
window.cancelarFiltrosDrCt    = cancelarFiltrosDrCt;
window.limpiarFiltrosDrCt     = limpiarFiltrosDrCt;
window.cerrarModalFiltrosDrCt = cerrarModalFiltrosDrCt;

async function abrirConsultarDrCt(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1000px">
      <div class="modal-header">
        <div class="modal-title">Contacto Datarocket <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDrCt(id); }
  });

  try {
    const c = await apiGet(`api/datarocketcontactos.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaDrCt(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaDrCt(c) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  const linkCard = (label, value) => {
    const empty = value == null || value === '';
    const href  = empty ? '' : (String(value).match(/^https?:\/\//i) ? value : ('https://' + value));
    const inner = empty
      ? 'Sin dato'
      : `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:underline">${esc(value)}</a>`;
    return `
      <div class="data-row">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-size:1.3rem;font-weight:700">${esc(c.nombre || '—')}</div>
        <div style="font-size:.9rem;color:var(--muted);margin-top:4px">${esc(c.empresa || '')}${c.empresa && c.cargo ? ' · ' : ''}${esc(c.cargo || '')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(c.id)} · UUID <code>${esc(c.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${drCtEstadoBadge(c.estado)} ${drCtVerificacionBadge(c.verificacion)}</div>
        <div style="font-size:.85rem;color:var(--muted)">Registrado: ${esc(fmtFecha(c.registrado))}</div>
        ${c.completado ? `<div style="font-size:.85rem;color:var(--muted)">Completado: ${esc(fmtFecha(c.completado))}</div>` : ''}
      </div>
    </div>

    ${seccion('Identidad')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Nombre',     c.nombre)}
      ${card('Empresa',    c.empresa)}
      ${card('Rubro',      c.rubro)}
      ${card('Actividad',  c.actividad)}
      ${card('Cargo',      c.cargo)}
      ${card('Persona',    c.persona)}
      ${card('Género',     DR_CT_GENERO_MAP[c.genero] || c.genero)}
      ${card('Nacimiento', c.nacimiento)}
      ${card('DNI',        c.dni, false, true)}
      ${card('Origen',     c.origen)}
    </dl>

    ${seccion('Ubicación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Domicilio', c.domicilio, true)}
      ${card('Ciudad',    c.ciudad)}
      ${card('Localidad', c.localidad)}
      ${card('Provincia', c.provincia)}
      ${card('País',      c.pais)}
      ${card('Ubicación', c.ubicacion, true, true)}
    </dl>

    ${seccion('Contacto')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Teléfono', c.telefono, false, true)}
      ${card('Celular',  c.celular,  false, true)}
      ${card('WhatsApp', c.whatsapp, false, true)}
      ${card('Correo',   c.correo,   false, true)}
    </dl>

    ${seccion('Web y redes')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${linkCard('Web',       c.web)}
      ${linkCard('Facebook',  c.facebook)}
      ${linkCard('Instagram', c.instagram)}
      ${linkCard('TikTok',    c.tiktok)}
    </dl>

    ${seccion('Comentarios y clasificación')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Comentarios', c.comentarios, true)}
      ${card('Tags',        c.tags,        true)}
      ${card('Listas',      c.listas,      true)}
    </dl>

    ${seccion('Estado y verificación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Suscripciones', c.suscripciones)}
      ${card('Estado',        c.estado)}
      ${card('Verificación',  c.verificacion)}
      ${card('Error',         c.error, true)}
    </dl>
  `;
}

async function abrirAltaEdicionDrCt(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar contacto <span class="modal-subtitle">#${id}</span>` : 'Nuevo contacto'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formDrCtHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/datarocketcontactos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formDrCtHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarDrCt(id, a);
  });
}

function formDrCtHtml(c) {
  const v   = (k) => esc(c?.[k] ?? '');
  const sel = (k, val) => (c?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = c?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Nombre</label>
        <input type="text" id="drcNombre" maxlength="255" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Empresa</label>
        <input type="text" id="drcEmpresa" maxlength="255" value="${v('empresa')}">
      </div>
      <div class="form-group">
        <label>Cargo</label>
        <input type="text" id="drcCargo" maxlength="255" value="${v('cargo')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Rubro</label>
        <input type="text" id="drcRubro" maxlength="255" value="${v('rubro')}">
      </div>
      <div class="form-group">
        <label>Actividad</label>
        <input type="text" id="drcActividad" maxlength="255" value="${v('actividad')}">
      </div>
      <div class="form-group">
        <label>Origen</label>
        <input type="text" id="drcOrigen" maxlength="255" value="${v('origen')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Persona</label>
        <input type="text" id="drcPersona" maxlength="255" value="${v('persona')}">
      </div>
      <div class="form-group">
        <label>Género</label>
        <select id="drcGenero">
          <option value=""  ${sel('genero','')}>—</option>
          <option value="M" ${sel('genero','M')}>Masculino</option>
          <option value="F" ${sel('genero','F')}>Femenino</option>
          <option value="X" ${sel('genero','X')}>Otro</option>
        </select>
      </div>
      <div class="form-group">
        <label>Nacimiento</label>
        <input type="text" id="drcNacimiento" maxlength="255" value="${v('nacimiento')}" placeholder="AAAA-MM-DD">
      </div>
    </div>
    <div class="form-group">
      <label>DNI</label>
      <input type="text" id="drcDni" maxlength="255" value="${v('dni')}" style="font-family:monospace">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Domicilio</label>
        <input type="text" id="drcDomicilio" maxlength="255" value="${v('domicilio')}">
      </div>
      <div class="form-group">
        <label>Ciudad</label>
        <input type="text" id="drcCiudad" maxlength="255" value="${v('ciudad')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Localidad</label>
        <input type="text" id="drcLocalidad" maxlength="255" value="${v('localidad')}">
      </div>
      <div class="form-group">
        <label>Provincia</label>
        <input type="text" id="drcProvincia" maxlength="255" value="${v('provincia')}">
      </div>
      <div class="form-group">
        <label>País</label>
        <input type="text" id="drcPais" maxlength="255" value="${v('pais')}">
      </div>
    </div>
    <div class="form-group">
      <label>Ubicación</label>
      <input type="text" id="drcUbicacion" maxlength="255" value="${v('ubicacion')}" style="font-family:monospace">
    </div>

    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Teléfono</label>
        <input type="text" id="drcTelefono" maxlength="255" value="${v('telefono')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Celular</label>
        <input type="text" id="drcCelular" maxlength="255" value="${v('celular')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>WhatsApp</label>
        <input type="text" id="drcWhatsapp" maxlength="255" value="${v('whatsapp')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Correo</label>
      <input type="email" id="drcCorreo" maxlength="255" value="${v('correo')}" style="font-family:monospace">
    </div>

    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Web</label>
        <input type="text" id="drcWeb" maxlength="255" value="${v('web')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Facebook</label>
        <input type="text" id="drcFacebook" maxlength="255" value="${v('facebook')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Instagram</label>
        <input type="text" id="drcInstagram" maxlength="255" value="${v('instagram')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>TikTok</label>
      <input type="text" id="drcTiktok" maxlength="255" value="${v('tiktok')}" style="font-family:monospace">
    </div>

    <div class="form-group">
      <label>Comentarios</label>
      <textarea id="drcComentarios" rows="3" maxlength="500">${v('comentarios')}</textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Tags</label>
        <input type="text" id="drcTags" maxlength="500" value="${v('tags')}">
      </div>
      <div class="form-group">
        <label>Listas</label>
        <input type="text" id="drcListas" maxlength="500" value="${v('listas')}">
      </div>
    </div>

    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Estado</label>
        <input type="text" id="drcEstado" maxlength="1" value="${v('estado')}"
               style="font-family:monospace" placeholder="A/I/…">
      </div>
      <div class="form-group">
        <label>Verificación</label>
        <input type="text" id="drcVerificacion" maxlength="1" value="${v('verificacion')}"
               style="font-family:monospace" placeholder="O/V/E/P…">
      </div>
      <div class="form-group">
        <label>Suscripciones</label>
        <input type="number" id="drcSuscripciones" min="0" value="${v('suscripciones')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Registrado</label>
        <input type="datetime-local" id="drcRegistrado" value="${dt('registrado')}">
      </div>
      <div class="form-group">
        <label>Completado</label>
        <input type="datetime-local" id="drcCompletado" value="${dt('completado')}">
      </div>
    </div>
    <div class="form-group">
      <label>Error</label>
      <textarea id="drcErrorTxt" rows="2" maxlength="255">${v('error')}</textarea>
    </div>
    <div class="field-error" id="drcFormError" style="display:none"></div>
  `;
}

async function guardarDrCt(id, btn) {
  const err = $('#drcFormError');
  err.style.display = 'none';

  const payload = {
    nombre:        $('#drcNombre').value.trim(),
    empresa:       $('#drcEmpresa').value.trim(),
    cargo:         $('#drcCargo').value.trim(),
    rubro:         $('#drcRubro').value.trim(),
    actividad:     $('#drcActividad').value.trim(),
    origen:        $('#drcOrigen').value.trim(),
    persona:       $('#drcPersona').value.trim(),
    genero:        $('#drcGenero').value,
    nacimiento:    $('#drcNacimiento').value.trim(),
    dni:           $('#drcDni').value.trim(),
    domicilio:     $('#drcDomicilio').value.trim(),
    ciudad:        $('#drcCiudad').value.trim(),
    ubicacion:     $('#drcUbicacion').value.trim(),
    localidad:     $('#drcLocalidad').value.trim(),
    provincia:     $('#drcProvincia').value.trim(),
    pais:          $('#drcPais').value.trim(),
    telefono:      $('#drcTelefono').value.trim(),
    celular:       $('#drcCelular').value.trim(),
    whatsapp:      $('#drcWhatsapp').value.trim(),
    correo:        $('#drcCorreo').value.trim(),
    web:           $('#drcWeb').value.trim(),
    facebook:      $('#drcFacebook').value.trim(),
    instagram:     $('#drcInstagram').value.trim(),
    tiktok:        $('#drcTiktok').value.trim(),
    comentarios:   $('#drcComentarios').value,
    tags:          $('#drcTags').value.trim(),
    listas:        $('#drcListas').value.trim(),
    suscripciones: $('#drcSuscripciones').value,
    estado:        $('#drcEstado').value.trim(),
    verificacion:  $('#drcVerificacion').value.trim(),
    registrado:    $('#drcRegistrado').value || null,
    completado:    $('#drcCompletado').value || null,
    error:         $('#drcErrorTxt').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/datarocketcontactos.php', 'POST', payload);
      toast('Contacto creado.');
    } else {
      await apiSend(`api/datarocketcontactos.php?id=${id}`, 'PUT', payload);
      toast('Contacto actualizado.');
    }
    closeModal();
    cargarDrCt();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarDrCt(id) {
  const ok = await confirmar({
    title: 'Eliminar contacto',
    message: `Se eliminará el contacto #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/datarocketcontactos.php?id=${id}`, 'DELETE');
    toast('Contacto eliminado.');
    cargarDrCt();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Datasale > Prospectos (ABM) -------------------------
const dsProFiltrosDefaults = {
  q: '', codigo: '', proyecto: '', estado: '', asignado: '', atendido: '',
  sentido: '', tipo: '', origen: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const dsProFiltros = { ...dsProFiltrosDefaults };
let dsProBuscadorTimer   = null;
let dsProFiltrosSnapshot = null;

// Diccionarios para poblar selects (proyectos, usuarios, paises) y opciones de
// combos (sentido / origen / tipo / estado / producto) leidas de la tabla
// `estados`. Se piden una vez al montar la vista via ensureDsProLookups().
let dsProLookups = null;

async function ensureDsProLookups() {
  if (dsProLookups) return dsProLookups;
  dsProLookups = await apiGet('api/datasaleprospectos.php?lookups=1');
  return dsProLookups;
}

// Renderiza el texto resuelto contra `estados` (o el valor crudo si no hay
// traduccion) como badge. `texto` = campo `*_texto` que viene enriquecido en
// el response; `valor` = valor crudo guardado en la fila.
function dsProBadge(texto, valor) {
  const label = (texto && String(texto).trim() !== '') ? texto : valor;
  if (label == null || label === '') return `<span class="badge badge-info">—</span>`;
  return `<span class="badge badge-info">${esc(label)}</span>`;
}

// Iconos por valor de `estado` (siguiendo el legacy: reloj para pendiente,
// apreton de manos para atendido, check para despachado). El resto cae en
// icono vacio + label neutro.
const DS_PRO_ESTADO_ICONO = {
  '1': 'fa-solid fa-clock',
  '2': 'fa-solid fa-handshake',
  '3': 'fa-solid fa-check',
};

// Celda "Estado" del listado. Replica el layout del legacy `listar.php`:
//   <icono> | <estado>
//   a/por <asignado|atendido>
//   hace X (desde ingreso si pendiente, desde actualizado si atendido)
// Los pendientes se pintan en rojo (--danger) para llamar la atencion.
function dsProEstadoCelda(p) {
  const estadoStr = p.estado != null ? String(p.estado) : '';
  const pendiente = estadoStr === '1';
  const icono = DS_PRO_ESTADO_ICONO[estadoStr] || '';
  const label = p.estado_texto || p.estado || '—';

  const iconoHtml = icono ? `<i class="${icono}"></i> ` : '';
  const cabecera  = `${iconoHtml}${esc(label)}`;

  const partes = [];
  if (pendiente && p.asignado_nombre) {
    partes.push(`<small style="opacity:.8">a ${esc(p.asignado_nombre)}</small>`);
  } else if (!pendiente && p.atendido_nombre) {
    partes.push(`<small style="opacity:.8">por ${esc(p.atendido_nombre)}</small>`);
  }
  const desde = pendiente ? p.ingreso : (p.actualizado || p.ingreso);
  const hace  = fmtHace(desde);
  if (hace) partes.push(`<small style="opacity:.8">${esc(hace)}</small>`);

  const cuerpo = [cabecera, ...partes].join('<br>');
  const color  = pendiente ? 'color:var(--danger)' : '';
  return `<span style="line-height:1.35;${color}">${cuerpo}</span>`;
}

// Renderiza las <option> de un combo desde dsProLookups.opciones[campo]. Se
// usa tanto en el modal de filtros como en el form de alta/edicion.
function dsProOpcionesHtml(campo, valorActual, incluirTodos = false, todosLabel = '— Todos —') {
  const opts = (dsProLookups?.opciones?.[campo]) || [];
  const parts = [];
  parts.push(`<option value="">${incluirTodos ? esc(todosLabel) : '—'}</option>`);
  for (const o of opts) {
    const sel = String(o.valor) === String(valorActual ?? '') ? ' selected' : '';
    parts.push(`<option value="${esc(o.valor)}"${sel}>${esc(o.texto)}</option>`);
  }
  return parts.join('');
}

// Renderiza las <option> de una lista {id, nombre} (proyectos, usuarios,
// paises, provincias, localidades).
function dsProIdNombreHtml(items, valorActual, incluirTodos = false, todosLabel = '— Todos —') {
  const parts = [];
  parts.push(`<option value="">${incluirTodos ? esc(todosLabel) : '—'}</option>`);
  for (const it of (items || [])) {
    const sel = String(it.id) === String(valorActual ?? '') ? ' selected' : '';
    parts.push(`<option value="${esc(it.id)}"${sel}>${esc(it.nombre)}</option>`);
  }
  return parts.join('');
}

function dsProCalificacion(n) {
  const num = Number(n);
  if (!num || num < 1) return `<span style="color:var(--muted)">—</span>`;
  const stars = Math.max(0, Math.min(5, Math.round(num)));
  return `<span style="color:#f5a623;letter-spacing:1px">${'★'.repeat(stars)}${'☆'.repeat(5 - stars)}</span>`;
}

route('/prospectos', async (mount) => {
  // Los selects del modal de filtros y del formulario dependen de los
  // diccionarios (proyectos, usuarios, opciones de combos). Se cargan una
  // sola vez por sesion antes de renderizar la vista.
  await ensureDsProLookups();

  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <div style="font-size:1.6rem;line-height:1">🎯</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los prospectos son los interesados que llegan al equipo comercial desde los distintos
          canales de captación, con sus datos de contacto, producto de interés, estado del
          seguimiento y el usuario asignado para atenderlos.
        </div>
      </div>

      <div class="stats-bar" id="dsProStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Sin atender</span><span class="stat-value orange">—</span></div>
        <div class="stat-card"><span class="stat-label">Asignados</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="dsProSearch"
                   placeholder="🔍 Buscar nombre, organización, contacto, correo, asunto…">
            <button class="search-clear" id="dsProSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dsProFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dsProFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dsProRapidoBtn" title="Filtro rápido por estado">
            <i class="fa-solid fa-bolt"></i>
          </button>
          <button class="btn btn-ghost btn-icon" id="dsProRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dsProNuevoBtn">+ Nuevo prospecto</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Ingreso</th>
              <th>Proyecto</th>
              <th>Asunto / Nombre</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dsProTbody">
            <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Menú contextual del filtro rápido (icono de rayo). Refleja el legacy:
         Todos / Pendientes / Atendidos / Despachados. -->
    <div id="dsProRapidoMenu" class="ctx-menu" role="menu">
      <button type="button" data-estado="" role="menuitem">
        <i class="fa-solid fa-list-ul"></i><span>Todos</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-estado="1" role="menuitem">
        <i class="fa-solid fa-clock"></i><span>Esperando</span>
      </button>
      <button type="button" data-estado="2" role="menuitem">
        <i class="fa-solid fa-handshake"></i><span>Atendidos</span>
      </button>
      <button type="button" data-estado="3" role="menuitem">
        <i class="fa-solid fa-check"></i><span>Despachados</span>
      </button>
    </div>

    <!-- Menú contextual único de la sección. Los items marcar-* se muestran u
         ocultan segun el estado actual del prospecto (ver abrirMenuDsPro). -->
    <div id="dsProCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep" data-role="sep-transiciones"></div>
      <button type="button" data-action="marcar-esperando" role="menuitem">
        <i class="fa-solid fa-clock"></i><span>Marcar como esperando</span>
      </button>
      <button type="button" data-action="marcar-atendido" role="menuitem">
        <i class="fa-solid fa-handshake"></i><span>Marcar como atendido</span>
      </button>
      <button type="button" data-action="marcar-despachado" role="menuitem">
        <i class="fa-solid fa-check"></i><span>Marcar como despachado</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <!-- Modal de filtros -->
    <div class="modal-backdrop" id="filtrosDsProBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDsPro()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDsPro()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDsProCodigo" min="1" placeholder="ID …" oninput="onFiltroDsPro('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Proyecto</label>
              <select id="fDsProProyecto" onchange="onFiltroDsPro('proyecto', this.value)">
                ${dsProIdNombreHtml(dsProLookups?.proyectos, '', true)}
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Sentido</label>
              <select id="fDsProSentido" onchange="onFiltroDsPro('sentido', this.value)">
                ${dsProOpcionesHtml('sentido', '', true)}
              </select>
            </div>
            <div class="form-group">
              <label>Tipo</label>
              <select id="fDsProTipo" onchange="onFiltroDsPro('tipo', this.value)">
                ${dsProOpcionesHtml('tipo', '', true)}
              </select>
            </div>
            <div class="form-group">
              <label>Origen</label>
              <select id="fDsProOrigen" onchange="onFiltroDsPro('origen', this.value)">
                ${dsProOpcionesHtml('origen', '', true)}
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Estado</label>
              <select id="fDsProEstado" onchange="onFiltroDsPro('estado', this.value)">
                ${dsProOpcionesHtml('estado', '', true)}
              </select>
            </div>
            <div class="form-group">
              <label>Asignado</label>
              <select id="fDsProAsignado" onchange="onFiltroDsPro('asignado', this.value)">
                ${dsProIdNombreHtml(dsProLookups?.usuarios, '', true)}
              </select>
            </div>
            <div class="form-group">
              <label>Atendido</label>
              <select id="fDsProAtendido" onchange="onFiltroDsPro('atendido', this.value)">
                ${dsProIdNombreHtml(dsProLookups?.usuarios, '', true)}
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fDsProDesde" onchange="onFiltroDsPro('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fDsProHasta" onchange="onFiltroDsPro('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDsProLimite" min="1" max="1000" value="100" onchange="onFiltroDsPro('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDsProOrderBy" onchange="onFiltroDsPro('order_by', this.value)">
                <option value="id">Código</option>
                <option value="ingreso">Ingreso</option>
                <option value="proyecto">Proyecto</option>
                <option value="sentido">Sentido</option>
                <option value="origen">Origen</option>
                <option value="tipo">Tipo</option>
                <option value="producto">Producto</option>
                <option value="organizacion">Organización</option>
                <option value="nombre">Nombre</option>
                <option value="estado">Estado</option>
                <option value="calificacion">Calificación</option>
                <option value="asignado">Asignado</option>
                <option value="atendido">Atendido</option>
                <option value="actualizado">Actualizado</option>
                <option value="aplazado">Aplazado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDsProDir" onchange="onFiltroDsPro('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDsPro()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDsPro()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDsPro()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#dsProNuevoBtn').addEventListener('click', () => abrirAltaEdicionDsPro(null));
  $('#dsProFiltrosBtn').addEventListener('click', () => abrirModalFiltrosDsPro());
  $('#dsProRefrescarBtn').addEventListener('click', () => cargarDsPro());

  // Filtro rápido por estado: abre menu contextual anclado al boton del rayo.
  $('#dsProRapidoBtn').addEventListener('click', (ev) => {
    ev.stopPropagation();
    const r = ev.currentTarget.getBoundingClientRect();
    abrirCtxMenu($('#dsProRapidoMenu'), r.right - 200, r.bottom + 4, null);
  });
  $('#dsProRapidoMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-estado]');
    if (!b) return;
    cerrarCtxMenu();
    // El filtro rapido reemplaza al estado del modal de filtros — si estaban
    // pisando el mismo campo, el ultimo click gana.
    dsProFiltros.estado = b.dataset.estado;
    refrescarBadgeFiltrosDsPro();
    cargarDsPro();
  });

  const inp = $('#dsProSearch');
  const clr = $('#dsProSearchClear');
  inp.value = dsProFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dsProFiltros.q = inp.value.trim();
    clearTimeout(dsProBuscadorTimer);
    dsProBuscadorTimer = setTimeout(() => { cargarDsPro(); refrescarBadgeFiltrosDsPro(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    dsProFiltros.q = '';
    cargarDsPro();
    refrescarBadgeFiltrosDsPro();
  });

  // Acciones del menú contextual
  $('#dsProCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar')        abrirConsultarDsPro(data.id);
    if (b.dataset.action === 'editar')           abrirAltaEdicionDsPro(data.id);
    if (b.dataset.action === 'eliminar')         eliminarDsPro(data.id);
    if (b.dataset.action === 'marcar-esperando')  marcarEstadoDsPro(data.id, 1);
    if (b.dataset.action === 'marcar-atendido')   marcarEstadoDsPro(data.id, 2);
    if (b.dataset.action === 'marcar-despachado') marcarEstadoDsPro(data.id, 3);
  });

  // Clic en fila → consultar; clic en hamburguesa → menú
  $('#dsProTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id     = Number(ham.dataset.id);
      const estado = ham.dataset.estado || '';
      const r      = ham.getBoundingClientRect();
      abrirMenuDsPro(r.right - 190, r.bottom + 4, { id, estado });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarDsPro(Number(tr.dataset.id));
  });
  $('#dsProTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirMenuDsPro(ev.clientX, ev.clientY, {
      id:     Number(tr.dataset.id),
      estado: tr.dataset.estado || '',
    });
  });

  refrescarBadgeFiltrosDsPro();
  await cargarDsPro();
}, 'Prospectos');

async function cargarDsPro() {
  const tbody = $('#dsProTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(dsProFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/datasaleprospectos.php?' + qs.toString());
    pintarStatsDsPro(data.stats);
    pintarTablaDsPro(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsDsPro(s) {
  const cards = $$('#dsProStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.sin_atender);
  cards[2].textContent = fmtNum(s.asignados);
}

// Layout de la columna "Asunto / Nombre" — replica del legacy:
//   <asunto>       (small + bold, si viene)
//   <nombre>
//   <ubicacion>    (small; localidad, provincia, pais con etiquetas resueltas)
//   Producto <producto>  (small, si viene)
// Cuando no hay nombre (registros huerfanos), cae a "Prospecto #<id>".
function dsProAsuntoNombreCelda(p) {
  const ubicacion = [p.localidad_nombre, p.provincia_nombre, p.pais_nombre]
    .filter(Boolean).join(', ');
  const producto = p.producto_texto || p.producto || '';
  const nombre = p.nombre || `Prospecto #${p.id}`;

  const partes = [];
  if (p.asunto) partes.push(`<small><b>${esc(p.asunto)}</b></small>`);
  partes.push(esc(nombre));
  if (ubicacion) partes.push(`<small style="opacity:.8">${esc(ubicacion)}</small>`);
  if (producto)  partes.push(`<small style="opacity:.8">Producto ${esc(producto)}</small>`);
  return `<span style="line-height:1.35">${partes.join('<br>')}</span>`;
}

function pintarTablaDsPro(rows) {
  const tbody = $('#dsProTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Sin prospectos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((p) => `
    <tr data-id="${p.id}" data-estado="${esc(p.estado ?? '')}" class="row-clickable">
      <td class="td-id">#${esc(p.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(p.ingreso))}</td>
      <td>${esc(p.proyecto_nombre || p.proyecto || '—')}</td>
      <td>${dsProAsuntoNombreCelda(p)}</td>
      <td>${dsProEstadoCelda(p)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${p.id}" data-estado="${esc(p.estado ?? '')}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

// ---- Modal de Filtros ----
function onFiltroDsPro(key, value) {
  if (['sentido', 'tipo', 'origen', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    dsProFiltros[key] = value;
  } else if (['codigo', 'proyecto', 'estado', 'asignado', 'atendido'].includes(key)) {
    const v = String(value).trim();
    dsProFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    dsProFiltros.limite = n;
  } else {
    dsProFiltros[key] = value;
  }
  refrescarBadgeFiltrosDsPro();
  cargarDsPro();
}

function refrescarBadgeFiltrosDsPro() {
  const btn   = $('#dsProFiltrosBtn');
  const badge = $('#dsProFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(dsProFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(dsProFiltros[k]) !== String(dsProFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosDsPro() {
  const f = dsProFiltros;
  $('#fDsProCodigo').value   = f.codigo;
  $('#fDsProProyecto').value = f.proyecto;
  $('#fDsProSentido').value  = f.sentido;
  $('#fDsProTipo').value     = f.tipo;
  $('#fDsProOrigen').value   = f.origen;
  $('#fDsProEstado').value   = f.estado;
  $('#fDsProAsignado').value = f.asignado;
  $('#fDsProAtendido').value = f.atendido;
  $('#fDsProDesde').value    = f.desde;
  $('#fDsProHasta').value    = f.hasta;
  $('#fDsProLimite').value   = f.limite;
  $('#fDsProOrderBy').value  = f.order_by;
  $('#fDsProDir').value      = f.dir;
}

function abrirModalFiltrosDsPro() {
  dsProFiltrosSnapshot = { ...dsProFiltros };
  sincronizarControlesFiltrosDsPro();
  $('#filtrosDsProBackdrop').classList.add('open');
}

function cerrarModalFiltrosDsPro() {
  $('#filtrosDsProBackdrop').classList.remove('open');
}

function cancelarFiltrosDsPro() {
  if (dsProFiltrosSnapshot) {
    Object.assign(dsProFiltros, dsProFiltrosSnapshot);
    refrescarBadgeFiltrosDsPro();
    cargarDsPro();
  }
  cerrarModalFiltrosDsPro();
}

function limpiarFiltrosDsPro() {
  Object.assign(dsProFiltros, dsProFiltrosDefaults);
  dsProFiltros.q = $('#dsProSearch')?.value.trim() || '';
  sincronizarControlesFiltrosDsPro();
  refrescarBadgeFiltrosDsPro();
  cargarDsPro();
}

// Exponer para los onclick del HTML
window.onFiltroDsPro           = onFiltroDsPro;
window.cancelarFiltrosDsPro    = cancelarFiltrosDsPro;
window.limpiarFiltrosDsPro     = limpiarFiltrosDsPro;
window.cerrarModalFiltrosDsPro = cerrarModalFiltrosDsPro;

// ---- Modal Consultar ----
async function abrirConsultarDsPro(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Prospecto <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDsPro(id); }
    dsProSwitchTab(ev);
  });

  try {
    const p = await apiGet(`api/datasaleprospectos.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaDsPro(p);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// Delegado de tabs para los modales de prospecto (consulta y alta/edicion).
// Recorre solo el modal activo — no toca otros modales-tabs de la SPA.
function dsProSwitchTab(ev) {
  const tabBtn = ev.target.closest('#modalRoot [data-tab]');
  if (!tabBtn) return;
  const target = tabBtn.dataset.tab;
  $$('#modalRoot .modal-tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === target));
  $$('#modalRoot .modal-tabpanel').forEach((p) => { p.hidden = p.dataset.panel !== target; });
}

function renderConsultaDsPro(p) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const accionesTxt = p.acciones && String(p.acciones).trim() !== ''
    ? `<pre style="white-space:pre-wrap;font-family:monospace;background:color-mix(in srgb, var(--surface) 90%, #000);padding:14px;border-radius:8px;margin:0;font-size:.85rem;line-height:1.5">${esc(p.acciones)}</pre>`
    : `<div style="color:var(--muted);font-style:italic">Sin historial de acciones</div>`;

  // El listener de tabs esta enganchado en el mismo modalRoot que ya maneja
  // "close" y "editar" (ver abrirConsultarDsPro).
  return `
    <div class="modal-tabs">
      <button type="button" class="modal-tab active" data-tab="contacto">Contacto</button>
      <button type="button" class="modal-tab"        data-tab="ubicacion">Ubicación</button>
      <button type="button" class="modal-tab"        data-tab="oportunidad">Oportunidad</button>
      <button type="button" class="modal-tab"        data-tab="seguimiento">Seguimiento</button>
      <button type="button" class="modal-tab"        data-tab="comentarios">Comentarios</button>
      <button type="button" class="modal-tab"        data-tab="historial">Historial</button>
    </div>

    <div class="modal-tabpanel" data-panel="contacto">
      <dl class="data-list">
        ${card('Nombre',       p.nombre)}
        ${card('Organización', p.organizacion)}
        ${card('Contacto',     p.contacto)}
        ${card('Celular',      p.celular, false, true)}
        ${card('Correo',       p.correo,  false, true)}
        ${card('Web',          p.web,     false, true)}
      </dl>
    </div>

    <div class="modal-tabpanel" data-panel="ubicacion" hidden>
      <dl class="data-list">
        ${card('Domicilio',  p.domicilio, true)}
        ${card('Ciudad',     p.ciudad)}
        ${card('Localidad',  p.localidad_nombre || p.localidad)}
        ${card('Provincia',  p.provincia_nombre || p.provincia)}
        ${card('País',       p.pais_nombre      || p.pais)}
        ${card('Ubicación',  p.ubicacion, true, true)}
      </dl>
    </div>

    <div class="modal-tabpanel" data-panel="oportunidad" hidden>
      <dl class="data-list">
        ${card('Proyecto',      p.proyecto_nombre || p.proyecto)}
        ${card('Producto',      p.producto_texto  || p.producto)}
        ${card('Asunto',        p.asunto,  true)}
        ${card('Sentido',       p.sentido_texto || p.sentido)}
        ${card('Tipo',          p.tipo_texto    || p.tipo)}
        ${card('Origen',        p.origen_texto  || p.origen)}
        ${card('Estado',        p.estado_texto  || p.estado)}
        ${card('Calificación',  p.calificacion)}
      </dl>
    </div>

    <div class="modal-tabpanel" data-panel="seguimiento" hidden>
      <dl class="data-list">
        ${card('Asignado a',     p.asignado_nombre || p.asignado)}
        ${card('Atendido por',   p.atendido_nombre || p.atendido)}
        ${card('Ingreso',        fmtFecha(p.ingreso))}
        ${card('Actualizado',    fmtFecha(p.actualizado))}
        ${card('Aplazado hasta', fmtFecha(p.aplazado))}
      </dl>
    </div>

    <div class="modal-tabpanel" data-panel="comentarios" hidden>
      <dl class="data-list">
        ${card('Comentarios', p.comentarios, true)}
      </dl>
    </div>

    <div class="modal-tabpanel" data-panel="historial" hidden>
      ${accionesTxt}
    </div>
  `;
}

// ---- Modal Alta / Edición ----
async function abrirAltaEdicionDsPro(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar prospecto <span class="modal-subtitle">#${id}</span>` : 'Nuevo prospecto'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formDsProHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const p = await apiGet(`api/datasaleprospectos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formDsProHtml(p);
      // Los selects encadenados de provincia y localidad se hidratan tras el
      // render: el form ya trae la provincia/localidad persistida como opcion
      // seleccionada, pero la lista completa se pide filtrando por pais.
      if (p.pais) {
        await cargarProvinciasDsPro(p.pais, p.provincia || '');
      }
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    dsProSwitchTab(ev);
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarDsPro(id, a);
  });
}

function formDsProHtml(p) {
  const v  = (k) => esc(p?.[k] ?? '');
  const dt = (k) => {
    const raw = p?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  // Los selects encadenados de provincia y localidad se hidratan tras el render
  // via cargarProvinciasDsPro() / cargarLocalidadesDsPro(), asi el <select>
  // arranca solo con la opcion ya persistida y luego se completa con la lista
  // filtrada por el pais/provincia seleccionados.
  const optProv = p?.provincia
    ? `<option value="${esc(p.provincia)}" selected>${esc(p.provincia_nombre || p.provincia)}</option>`
    : '<option value="">—</option>';
  const optLoc  = p?.localidad
    ? `<option value="${esc(p.localidad)}" selected>${esc(p.localidad_nombre || p.localidad)}</option>`
    : '<option value="">—</option>';
  // Los campos se agrupan en 6 tabs que espejan las secciones de la vista
  // Consultar (Contacto / Ubicacion / Oportunidad / Seguimiento / Comentarios /
  // Historial). El toggle se maneja con el mismo dsProSwitchTab que la consulta.
  // `dspFormError` queda fuera de los paneles para ser visible en cualquier tab.
  return `
    <div class="modal-tabs">
      <button type="button" class="modal-tab active" data-tab="contacto">Contacto</button>
      <button type="button" class="modal-tab"        data-tab="ubicacion">Ubicación</button>
      <button type="button" class="modal-tab"        data-tab="oportunidad">Oportunidad</button>
      <button type="button" class="modal-tab"        data-tab="seguimiento">Seguimiento</button>
      <button type="button" class="modal-tab"        data-tab="comentarios">Comentarios</button>
      <button type="button" class="modal-tab"        data-tab="historial">Historial</button>
    </div>

    <div class="modal-tabpanel" data-panel="contacto">
      <div class="form-row">
        <div class="form-group">
          <label>Nombre</label>
          <input type="text" id="dspNombre" maxlength="255" value="${v('nombre')}">
        </div>
        <div class="form-group">
          <label>Organización</label>
          <input type="text" id="dspOrganizacion" maxlength="255" value="${v('organizacion')}">
        </div>
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Contacto</label>
          <input type="text" id="dspContacto" maxlength="255" value="${v('contacto')}">
        </div>
        <div class="form-group">
          <label>Celular</label>
          <input type="text" id="dspCelular" maxlength="255" value="${v('celular')}" style="font-family:monospace">
        </div>
        <div class="form-group">
          <label>Correo</label>
          <input type="email" id="dspCorreo" maxlength="255" value="${v('correo')}" style="font-family:monospace">
        </div>
      </div>
      <div class="form-group">
        <label>Web</label>
        <input type="text" id="dspWeb" maxlength="255" value="${v('web')}" style="font-family:monospace">
      </div>
    </div>

    <div class="modal-tabpanel" data-panel="ubicacion" hidden>
      <div class="form-group">
        <label>Domicilio</label>
        <input type="text" id="dspDomicilio" maxlength="255" value="${v('domicilio')}">
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label>País</label>
          <select id="dspPais" onchange="cargarProvinciasDsPro(this.value, '')">
            ${dsProIdNombreHtml(dsProLookups?.paises, p?.pais)}
          </select>
        </div>
        <div class="form-group">
          <label>Provincia</label>
          <select id="dspProvincia" onchange="cargarLocalidadesDsPro(this.value, '')">
            ${optProv}
          </select>
        </div>
        <div class="form-group">
          <label>Localidad</label>
          <select id="dspLocalidad">
            ${optLoc}
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Ciudad</label>
          <input type="text" id="dspCiudad" maxlength="255" value="${v('ciudad')}">
        </div>
        <div class="form-group">
          <label>Ubicación (coordenadas)</label>
          <input type="text" id="dspUbicacion" maxlength="255" value="${v('ubicacion')}" style="font-family:monospace"
                 placeholder="lat,lng">
        </div>
      </div>
    </div>

    <div class="modal-tabpanel" data-panel="oportunidad" hidden>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Proyecto</label>
          <select id="dspProyecto">
            ${dsProIdNombreHtml(dsProLookups?.proyectos, p?.proyecto)}
          </select>
        </div>
        <div class="form-group">
          <label>Origen</label>
          <select id="dspOrigen">
            ${dsProOpcionesHtml('origen', p?.origen)}
          </select>
        </div>
        <div class="form-group">
          <label>Producto</label>
          <select id="dspProducto">
            ${dsProOpcionesHtml('producto', p?.producto)}
          </select>
        </div>
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Sentido</label>
          <select id="dspSentido">
            ${dsProOpcionesHtml('sentido', p?.sentido)}
          </select>
        </div>
        <div class="form-group">
          <label>Tipo</label>
          <select id="dspTipo">
            ${dsProOpcionesHtml('tipo', p?.tipo)}
          </select>
        </div>
        <div class="form-group">
          <label>Calificación (0-5)</label>
          <input type="number" id="dspCalificacion" min="0" max="5" value="${v('calificacion')}">
        </div>
      </div>
      <div class="form-group">
        <label>Asunto</label>
        <input type="text" id="dspAsunto" maxlength="255" value="${v('asunto')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="dspEstado">
          ${dsProOpcionesHtml('estado', p?.estado)}
        </select>
      </div>
    </div>

    <div class="modal-tabpanel" data-panel="seguimiento" hidden>
      <div class="form-row">
        <div class="form-group">
          <label>Ingreso</label>
          <input type="datetime-local" id="dspIngreso" value="${dt('ingreso')}">
        </div>
        <div class="form-group">
          <label>Aplazado hasta</label>
          <input type="datetime-local" id="dspAplazado" value="${dt('aplazado')}">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Asignado a</label>
          <select id="dspAsignado">
            ${dsProIdNombreHtml(dsProLookups?.usuarios, p?.asignado)}
          </select>
        </div>
        <div class="form-group">
          <label>Atendido por</label>
          <select id="dspAtendido">
            ${dsProIdNombreHtml(dsProLookups?.usuarios, p?.atendido)}
          </select>
        </div>
      </div>
    </div>

    <div class="modal-tabpanel" data-panel="comentarios" hidden>
      <div class="form-group">
        <label>Comentarios</label>
        <textarea id="dspComentarios" rows="10" maxlength="1000">${v('comentarios')}</textarea>
      </div>
    </div>

    <div class="modal-tabpanel" data-panel="historial" hidden>
      <div class="form-group">
        <label>Historial de acciones</label>
        <textarea id="dspAcciones" rows="12" style="font-family:monospace">${v('acciones')}</textarea>
      </div>
    </div>

    <div class="field-error" id="dspFormError" style="display:none;margin-top:10px"></div>
  `;
}

// Repoblar el select de provincias filtrado por pais. `keepProv` es el valor
// a preservar despues del refresco (util al inicializar el form con datos
// existentes). Al vaciar `keepProv` se limpia tambien el select dependiente de
// localidad.
async function cargarProvinciasDsPro(paisId, keepProv) {
  const selProv = document.getElementById('dspProvincia');
  const selLoc  = document.getElementById('dspLocalidad');
  if (!selProv) return;

  if (!paisId) {
    selProv.innerHTML = '<option value="">—</option>';
    if (selLoc) selLoc.innerHTML = '<option value="">—</option>';
    return;
  }
  selProv.innerHTML = '<option value="">Cargando…</option>';
  if (selLoc) selLoc.innerHTML = '<option value="">—</option>';
  try {
    const items = await apiGet('api/datasaleprospectos.php?provincias=1&pais=' + encodeURIComponent(paisId));
    selProv.innerHTML = dsProIdNombreHtml(items, keepProv || '');
    if (keepProv) {
      await cargarLocalidadesDsPro(keepProv, '');
    }
  } catch (e) {
    selProv.innerHTML = `<option value="">Error: ${esc(e.message)}</option>`;
  }
}

// Analogo a cargarProvinciasDsPro pero para localidades filtradas por
// provincia. `keepLoc` es el valor a mantener seleccionado tras el refresco.
async function cargarLocalidadesDsPro(provinciaId, keepLoc) {
  const selLoc = document.getElementById('dspLocalidad');
  if (!selLoc) return;

  if (!provinciaId) {
    selLoc.innerHTML = '<option value="">—</option>';
    return;
  }
  selLoc.innerHTML = '<option value="">Cargando…</option>';
  try {
    const items = await apiGet('api/datasaleprospectos.php?localidades=1&provincia=' + encodeURIComponent(provinciaId));
    selLoc.innerHTML = dsProIdNombreHtml(items, keepLoc || '');
  } catch (e) {
    selLoc.innerHTML = `<option value="">Error: ${esc(e.message)}</option>`;
  }
}

window.cargarProvinciasDsPro  = cargarProvinciasDsPro;
window.cargarLocalidadesDsPro = cargarLocalidadesDsPro;

async function guardarDsPro(id, btn) {
  const err = $('#dspFormError');
  err.style.display = 'none';

  const payload = {
    ingreso:      $('#dspIngreso').value || null,
    proyecto:     $('#dspProyecto').value,
    sentido:      $('#dspSentido').value,
    origen:       $('#dspOrigen').value.trim(),
    tipo:         $('#dspTipo').value,
    producto:     $('#dspProducto').value.trim(),
    asunto:       $('#dspAsunto').value.trim(),
    organizacion: $('#dspOrganizacion').value.trim(),
    nombre:       $('#dspNombre').value.trim(),
    contacto:     $('#dspContacto').value.trim(),
    celular:      $('#dspCelular').value.trim(),
    correo:       $('#dspCorreo').value.trim(),
    web:          $('#dspWeb').value.trim(),
    domicilio:    $('#dspDomicilio').value.trim(),
    ciudad:       $('#dspCiudad').value.trim(),
    localidad:    $('#dspLocalidad').value.trim(),
    provincia:    $('#dspProvincia').value.trim(),
    pais:         $('#dspPais').value.trim(),
    ubicacion:    $('#dspUbicacion').value.trim(),
    calificacion: $('#dspCalificacion').value,
    estado:       $('#dspEstado').value,
    asignado:     $('#dspAsignado').value,
    atendido:     $('#dspAtendido').value,
    aplazado:     $('#dspAplazado').value || null,
    comentarios:  $('#dspComentarios').value,
    acciones:     $('#dspAcciones').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/datasaleprospectos.php', 'POST', payload);
      toast('Prospecto creado.');
    } else {
      await apiSend(`api/datasaleprospectos.php?id=${id}`, 'PUT', payload);
      toast('Prospecto actualizado.');
    }
    closeModal();
    cargarDsPro();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarDsPro(id) {
  const ok = await confirmar({
    title: 'Eliminar prospecto',
    message: `Se eliminará el prospecto #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/datasaleprospectos.php?id=${id}`, 'DELETE');
    toast('Prospecto eliminado.');
    cargarDsPro();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// Abre el menu contextual del listado ocultando el item "Marcar como ..." que
// coincide con el estado actual del prospecto — no tiene sentido ofrecer la
// transicion hacia el estado en el que ya esta.
function abrirMenuDsPro(x, y, data) {
  const menu = $('#dsProCtxMenu');
  if (!menu) return;
  const estadoActual = String(data?.estado ?? '');
  const mapa = { '1': 'marcar-esperando', '2': 'marcar-atendido', '3': 'marcar-despachado' };
  const actualAction = mapa[estadoActual] || null;

  menu.querySelectorAll('[data-action^="marcar-"]').forEach((btn) => {
    btn.style.display = (btn.dataset.action === actualAction) ? 'none' : '';
  });
  abrirCtxMenu(menu, x, y, data);
}

// Dispara la transicion de estado contra el endpoint POST ?action=estado. El
// backend deriva `atendido` del JWT del usuario logueado; el frontend solo
// manda el nuevo estado. Recarga el listado para reflejar el cambio.
async function marcarEstadoDsPro(id, estado) {
  const labels = { 1: 'esperando', 2: 'atendido', 3: 'despachado' };
  try {
    await apiSend(`api/datasaleprospectos.php?id=${id}&action=estado`, 'POST', { estado });
    toast(`Prospecto marcado como ${labels[estado] || 'actualizado'}.`);
    cargarDsPro();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: AWS SES (landing) -------------------------
route('/awsses', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">AWS SES</div>
      <div class="page-subtitle">Servicio de correo de Amazon: mensajes registrados, canales SMTP y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/awssesmensajes'">
        <span class="tile-icon">✉️</span>
        <span class="tile-title">Mensajes</span>
        <span class="tile-desc">Cada envío individual de correo procesado por AWS SES, con destinatario, cuerpo, estado y tiempo de entrega.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/awssescanales'">
        <span class="tile-icon">📡</span>
        <span class="tile-title">Canales</span>
        <span class="tile-desc">Los canales SMTP de AWS SES: servidor, usuario, contraseña y correo remitente por canal.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/account', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre la consola oficial de AWS SES (region us-east-1) en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'AWS SES');

// ------------------------- Vista: AWS SES > Mensajes (ABM) -------------------------
const sesMsgFiltrosDefaults = {
  q: '', codigo: '', proyecto: '', canal: '', plantilla: '',
  estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const sesMsgFiltros = { ...sesMsgFiltrosDefaults };
let sesMsgBuscadorTimer   = null;
let sesMsgFiltrosSnapshot = null;

const SES_MSG_FORMATO_MAP = {
  T: 'Texto plano',
  H: 'HTML',
  M: 'Markdown',
};
const SES_MSG_PRIORIDAD_MAP = {
  A: 'Alta',
  N: 'Normal',
  B: 'Baja',
};

function sesMsgEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    P: 'badge-warn',
    E: 'badge-success',
    F: 'badge-danger',
    C: 'badge-danger',
    R: 'badge-info',
  };
  const labelMap = {
    P: 'Pendiente', E: 'Enviado', F: 'Fallado', C: 'Cancelado', R: 'Reintento',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

function sesMsgFmtDemora(seg) {
  if (seg == null || seg === '' || isNaN(Number(seg))) return '—';
  const n = Number(seg);
  if (n < 60)    return `${n}s`;
  if (n < 3600)  return `${Math.round(n / 60)}m`;
  return `${(n / 3600).toFixed(1)}h`;
}

route('/awssesmensajes', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a AWS SES" onclick="location.hash='#/awsses'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">✉️</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los mensajes AWS SES son cada correo individual que el motor SES procesa,
          con su remitente, destinatario, asunto, cuerpo y el estado del envío
          registrado por Amazon.
        </div>
      </div>

      <div class="stats-bar" id="sesMsgStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Enviados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Con error</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="sesMsgSearch"
                   placeholder="🔍 Buscar destinatario, destino, asunto o tags…">
            <button class="search-clear" id="sesMsgSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="sesMsgFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="sesMsgFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="sesMsgRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="sesMsgNuevoBtn">+ Nuevo mensaje</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Canal</th>
              <th>Destinatario</th>
              <th>Destino</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Enviado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="sesMsgTbody">
            <tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="sesMsgCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosSesMsgBackdrop"
         onclick="if(event.target===this)cancelarFiltrosSesMsg()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosSesMsg()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fSesMsgCodigo" min="1" placeholder="ID …" oninput="onFiltroSesMsg('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fSesMsgEstado" onchange="onFiltroSesMsg('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="P">Pendiente</option>
                <option value="E">Enviado</option>
                <option value="F">Fallado</option>
                <option value="C">Cancelado</option>
                <option value="R">Reintento</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Proyecto (ID)</label>
              <input type="number" id="fSesMsgProyecto" min="1" oninput="onFiltroSesMsg('proyecto', this.value)">
            </div>
            <div class="form-group">
              <label>Canal (ID)</label>
              <input type="number" id="fSesMsgCanal" min="1" oninput="onFiltroSesMsg('canal', this.value)">
            </div>
            <div class="form-group">
              <label>Plantilla (ID)</label>
              <input type="number" id="fSesMsgPlantilla" min="1" oninput="onFiltroSesMsg('plantilla', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fSesMsgDesde" onchange="onFiltroSesMsg('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fSesMsgHasta" onchange="onFiltroSesMsg('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fSesMsgLimite" min="1" max="1000" value="100" onchange="onFiltroSesMsg('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fSesMsgOrderBy" onchange="onFiltroSesMsg('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="destinatario">Destinatario</option>
                <option value="destino">Destino</option>
                <option value="asunto">Asunto</option>
                <option value="estado">Estado</option>
                <option value="enviado">Enviado</option>
                <option value="demora">Demora</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fSesMsgDir" onchange="onFiltroSesMsg('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosSesMsg()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosSesMsg()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosSesMsg()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#sesMsgNuevoBtn').addEventListener('click', () => abrirAltaEdicionSesMsg(null));
  $('#sesMsgFiltrosBtn').addEventListener('click', () => abrirModalFiltrosSesMsg());
  $('#sesMsgRefrescarBtn').addEventListener('click', () => cargarSesMsg());

  const inp = $('#sesMsgSearch');
  const clr = $('#sesMsgSearchClear');
  inp.value = sesMsgFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    sesMsgFiltros.q = inp.value.trim();
    clearTimeout(sesMsgBuscadorTimer);
    sesMsgBuscadorTimer = setTimeout(() => { cargarSesMsg(); refrescarBadgeFiltrosSesMsg(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    sesMsgFiltros.q = '';
    cargarSesMsg();
    refrescarBadgeFiltrosSesMsg();
  });

  $('#sesMsgCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarSesMsg(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionSesMsg(data.id);
    if (b.dataset.action === 'eliminar')  eliminarSesMsg(data.id);
  });

  $('#sesMsgTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#sesMsgCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarSesMsg(Number(tr.dataset.id));
  });
  $('#sesMsgTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#sesMsgCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosSesMsg();
  await cargarSesMsg();
}, 'Mensajes');

async function cargarSesMsg() {
  const tbody = $('#sesMsgTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(sesMsgFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/awssesmensajes.php?' + qs.toString());
    pintarStatsSesMsg(data.stats);
    pintarTablaSesMsg(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsSesMsg(s) {
  const cards = $$('#sesMsgStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.enviados);
  cards[2].textContent = fmtNum(s.con_error);
}

function pintarTablaSesMsg(rows) {
  const tbody = $('#sesMsgTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Sin mensajes.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((m) => `
    <tr data-id="${m.id}" class="row-clickable">
      <td class="td-id">#${esc(m.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(m.fecha))}</td>
      <td>${esc(m.canal_nombre || (m.canal != null ? '#' + m.canal : '—'))}</td>
      <td class="td-nombre">${esc(m.destinatario || '—')}</td>
      <td style="font-family:monospace">${esc(m.destino || '—')}</td>
      <td>${esc(m.asunto || '—')}</td>
      <td>${sesMsgEstadoBadge(m.estado)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(m.enviado))}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${m.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroSesMsg(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    sesMsgFiltros[key] = value;
  } else if (['codigo', 'proyecto', 'canal', 'plantilla'].includes(key)) {
    const v = String(value).trim();
    sesMsgFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    sesMsgFiltros.limite = n;
  } else {
    sesMsgFiltros[key] = value;
  }
  refrescarBadgeFiltrosSesMsg();
  cargarSesMsg();
}

function refrescarBadgeFiltrosSesMsg() {
  const btn   = $('#sesMsgFiltrosBtn');
  const badge = $('#sesMsgFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(sesMsgFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(sesMsgFiltros[k]) !== String(sesMsgFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosSesMsg() {
  const f = sesMsgFiltros;
  $('#fSesMsgCodigo').value    = f.codigo;
  $('#fSesMsgEstado').value    = f.estado;
  $('#fSesMsgProyecto').value  = f.proyecto;
  $('#fSesMsgCanal').value     = f.canal;
  $('#fSesMsgPlantilla').value = f.plantilla;
  $('#fSesMsgDesde').value     = f.desde;
  $('#fSesMsgHasta').value     = f.hasta;
  $('#fSesMsgLimite').value    = f.limite;
  $('#fSesMsgOrderBy').value   = f.order_by;
  $('#fSesMsgDir').value       = f.dir;
}

function abrirModalFiltrosSesMsg() {
  sesMsgFiltrosSnapshot = { ...sesMsgFiltros };
  sincronizarControlesFiltrosSesMsg();
  $('#filtrosSesMsgBackdrop').classList.add('open');
}
function cerrarModalFiltrosSesMsg() { $('#filtrosSesMsgBackdrop').classList.remove('open'); }
function cancelarFiltrosSesMsg() {
  if (sesMsgFiltrosSnapshot) {
    Object.assign(sesMsgFiltros, sesMsgFiltrosSnapshot);
    refrescarBadgeFiltrosSesMsg();
    cargarSesMsg();
  }
  cerrarModalFiltrosSesMsg();
}
function limpiarFiltrosSesMsg() {
  Object.assign(sesMsgFiltros, sesMsgFiltrosDefaults);
  sesMsgFiltros.q = $('#sesMsgSearch')?.value.trim() || '';
  sincronizarControlesFiltrosSesMsg();
  refrescarBadgeFiltrosSesMsg();
  cargarSesMsg();
}
window.onFiltroSesMsg           = onFiltroSesMsg;
window.cancelarFiltrosSesMsg    = cancelarFiltrosSesMsg;
window.limpiarFiltrosSesMsg     = limpiarFiltrosSesMsg;
window.cerrarModalFiltrosSesMsg = cerrarModalFiltrosSesMsg;

async function abrirConsultarSesMsg(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1200px">
      <div class="modal-header">
        <div class="modal-title">Mensaje SES <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionSesMsg(id); }
  });

  try {
    const m = await apiGet(`api/awssesmensajes.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaSesMsg(m);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaSesMsg(m) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  const cuerpoHtml = m.cuerpo && String(m.cuerpo).trim() !== ''
    ? (m.formato === 'H'
        ? `<iframe srcdoc="${esc(m.cuerpo)}" style="width:100%;min-height:280px;border:1px solid var(--border);border-radius:8px;background:white"></iframe>`
        : `<pre style="white-space:pre-wrap;font-family:monospace;background:color-mix(in srgb, var(--surface) 90%, #000);padding:14px;border-radius:8px;margin:0;font-size:.85rem;line-height:1.5">${esc(m.cuerpo)}</pre>`)
    : `<div style="color:var(--muted);font-style:italic">Sin cuerpo</div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.3rem;font-weight:700">${esc(m.destinatario || '—')}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(m.destino || '')}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(m.asunto || 'Sin asunto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(m.id)}</div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${sesMsgEstadoBadge(m.estado)}</div>
        <div style="margin-top:6px;font-size:.85rem;line-height:1.5">
          <div><span style="color:var(--muted)">Fecha:</span> ${esc(fmtFecha(m.fecha))}</div>
          <div><span style="color:var(--muted)">Encolado:</span> ${esc(fmtFecha(m.encolado))}</div>
          <div><span style="color:var(--muted)">Enviado:</span> ${esc(fmtFecha(m.enviado))}</div>
        </div>
      </div>
    </div>

    ${seccion('Cuerpo del mensaje')}
    ${cuerpoHtml}

    ${seccion('Remitente y destinatario')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Remitente',    m.remitente)}
      ${card('Remite',       m.remite, false, true)}
      ${card('Destinatario', m.destinatario)}
      ${card('Destino',      m.destino, false, true)}
    </dl>

    ${seccion('Contexto de envío')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Proyecto',   m.proyecto)}
      ${card('Canal',      m.canal)}
      ${card('Plantilla',  m.plantilla)}
      ${card('Prioridad',  SES_MSG_PRIORIDAD_MAP[m.prioridad] || m.prioridad)}
      ${card('Formato',    SES_MSG_FORMATO_MAP[m.formato]     || m.formato)}
      ${card('Codificado', m.codificado)}
    </dl>

    ${seccion('Tiempos y resultado')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Fecha',    fmtFecha(m.fecha))}
      ${card('Encolado', fmtFecha(m.encolado))}
      ${card('Enviado',  fmtFecha(m.enviado))}
      ${card('Demora',   sesMsgFmtDemora(m.demora))}
      ${card('Estado',   m.estado)}
      ${card('Tags',     m.tags)}
    </dl>

    ${seccion('Adjunto, variables y errores')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Adjunto',    m.adjunto, true, true)}
      ${card('Variables',  m.variables, true, true)}
      ${card('Parámetros', m.parametros, true, true)}
      ${card('Error',      m.error, true)}
    </dl>
  `;
}

async function abrirAltaEdicionSesMsg(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar mensaje <span class="modal-subtitle">#${id}</span>` : 'Nuevo mensaje'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formSesMsgHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const m = await apiGet(`api/awssesmensajes.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formSesMsgHtml(m);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarSesMsg(id, a);
  });
}

function formSesMsgHtml(m) {
  const v   = (k) => esc(m?.[k] ?? '');
  const sel = (k, val) => (m?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = m?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="sesFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Prioridad</label>
        <select id="sesPrioridad">
          <option value=""  ${sel('prioridad','')}>—</option>
          <option value="A" ${sel('prioridad','A')}>Alta</option>
          <option value="N" ${sel('prioridad','N')}>Normal</option>
          <option value="B" ${sel('prioridad','B')}>Baja</option>
        </select>
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="sesEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="E" ${sel('estado','E')}>Enviado</option>
          <option value="F" ${sel('estado','F')}>Fallado</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="R" ${sel('estado','R')}>Reintento</option>
        </select>
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Proyecto (ID)</label>
        <input type="number" id="sesProyecto" min="1" value="${v('proyecto')}">
      </div>
      <div class="form-group">
        <label>Canal (ID)</label>
        <input type="number" id="sesCanal" min="1" value="${v('canal')}">
      </div>
      <div class="form-group">
        <label>Plantilla (ID)</label>
        <input type="number" id="sesPlantilla" min="1" value="${v('plantilla')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Remitente</label>
        <input type="text" id="sesRemitente" maxlength="255" value="${v('remitente')}">
      </div>
      <div class="form-group">
        <label>Remite</label>
        <input type="text" id="sesRemite" maxlength="255" value="${v('remite')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Destinatario</label>
        <input type="text" id="sesDestinatario" maxlength="255" value="${v('destinatario')}">
      </div>
      <div class="form-group">
        <label>Destino</label>
        <input type="text" id="sesDestino" maxlength="255" value="${v('destino')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Asunto</label>
      <input type="text" id="sesAsunto" maxlength="255" value="${v('asunto')}">
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Formato</label>
        <select id="sesFormato">
          <option value=""  ${sel('formato','')}>—</option>
          <option value="T" ${sel('formato','T')}>Texto plano</option>
          <option value="H" ${sel('formato','H')}>HTML</option>
          <option value="M" ${sel('formato','M')}>Markdown</option>
        </select>
      </div>
      <div class="form-group">
        <label>Codificado</label>
        <input type="text" id="sesCodificado" maxlength="1" value="${v('codificado')}">
      </div>
      <div class="form-group">
        <label>Tags</label>
        <input type="text" id="sesTags" maxlength="255" value="${v('tags')}">
      </div>
    </div>
    <div class="form-group">
      <label>Cuerpo</label>
      <textarea id="sesCuerpo" rows="8" style="font-family:monospace">${v('cuerpo')}</textarea>
    </div>
    <div class="form-group">
      <label>Variables</label>
      <textarea id="sesVariables" rows="3" style="font-family:monospace">${v('variables')}</textarea>
    </div>
    <div class="form-group">
      <label>Parámetros</label>
      <textarea id="sesParametros" rows="3" style="font-family:monospace">${v('parametros')}</textarea>
    </div>
    <div class="form-group">
      <label>Adjunto (URL/ruta)</label>
      <input type="text" id="sesAdjunto" maxlength="500" value="${v('adjunto')}" style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>Error</label>
      <textarea id="sesErrorTxt" rows="2" maxlength="1000">${v('error')}</textarea>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Encolado</label>
        <input type="datetime-local" id="sesEncolado" value="${dt('encolado')}">
      </div>
      <div class="form-group">
        <label>Enviado</label>
        <input type="datetime-local" id="sesEnviado" value="${dt('enviado')}">
      </div>
      <div class="form-group">
        <label>Demora (seg.)</label>
        <input type="number" id="sesDemora" min="0" value="${v('demora')}">
      </div>
    </div>
    <div class="field-error" id="sesFormError" style="display:none"></div>
  `;
}

async function guardarSesMsg(id, btn) {
  const err = $('#sesFormError');
  err.style.display = 'none';

  const payload = {
    fecha:        $('#sesFecha').value || null,
    prioridad:    $('#sesPrioridad').value,
    estado:       $('#sesEstado').value,
    proyecto:     $('#sesProyecto').value,
    canal:        $('#sesCanal').value,
    plantilla:    $('#sesPlantilla').value,
    remitente:    $('#sesRemitente').value.trim(),
    remite:       $('#sesRemite').value.trim(),
    destinatario: $('#sesDestinatario').value.trim(),
    destino:      $('#sesDestino').value.trim(),
    asunto:       $('#sesAsunto').value.trim(),
    formato:      $('#sesFormato').value,
    codificado:   $('#sesCodificado').value.trim(),
    tags:         $('#sesTags').value.trim(),
    cuerpo:       $('#sesCuerpo').value,
    variables:    $('#sesVariables').value,
    parametros:   $('#sesParametros').value,
    adjunto:      $('#sesAdjunto').value.trim(),
    error:        $('#sesErrorTxt').value,
    encolado:     $('#sesEncolado').value || null,
    enviado:      $('#sesEnviado').value || null,
    demora:       $('#sesDemora').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/awssesmensajes.php', 'POST', payload);
      toast('Mensaje creado.');
    } else {
      await apiSend(`api/awssesmensajes.php?id=${id}`, 'PUT', payload);
      toast('Mensaje actualizado.');
    }
    closeModal();
    cargarSesMsg();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarSesMsg(id) {
  const ok = await confirmar({
    title: 'Eliminar mensaje',
    message: `Se eliminará el mensaje #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/awssesmensajes.php?id=${id}`, 'DELETE');
    toast('Mensaje eliminado.');
    cargarSesMsg();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: AWS SES > Canales (ABM) -------------------------
const sesChFiltrosDefaults = {
  q: '', codigo: '', habilitado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const sesChFiltros = { ...sesChFiltrosDefaults };
let sesChBuscadorTimer   = null;
let sesChFiltrosSnapshot = null;

function sesChHabilitadoBadge(h) {
  if (h === '1') return `<span class="badge badge-success">Habilitado</span>`;
  if (h === '0') return `<span class="badge badge-danger">Deshabilitado</span>`;
  return `<span class="badge badge-info">—</span>`;
}

route('/awssescanales', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a AWS SES" onclick="location.hash='#/awsses'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📡</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los canales AWS SES son cada configuración SMTP que el motor puede usar
          para despachar correos, con su servidor, usuario, contraseña y correo
          remitente asociado.
        </div>
      </div>

      <div class="stats-bar" id="sesChStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Habilitados</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="sesChSearch"
                   placeholder="🔍 Buscar nombre, correo, servidor o usuario…">
            <button class="search-clear" id="sesChSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="sesChFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="sesChFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="sesChRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="sesChNuevoBtn">+ Nuevo canal</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Correo</th>
              <th>Servidor</th>
              <th>Usuario</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="sesChTbody">
            <tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="sesChCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosSesChBackdrop"
         onclick="if(event.target===this)cancelarFiltrosSesCh()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosSesCh()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fSesChCodigo" min="1" placeholder="ID …" oninput="onFiltroSesCh('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Habilitado</label>
              <select id="fSesChHabilitado" onchange="onFiltroSesCh('habilitado', this.value)">
                <option value="">— Todos —</option>
                <option value="1">Habilitados</option>
                <option value="0">Deshabilitados</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fSesChLimite" min="1" max="1000" value="100" onchange="onFiltroSesCh('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fSesChOrderBy" onchange="onFiltroSesCh('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="correo">Correo</option>
                <option value="servidor">Servidor</option>
                <option value="usuario">Usuario</option>
                <option value="habilitado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fSesChDir" onchange="onFiltroSesCh('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosSesCh()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosSesCh()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosSesCh()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#sesChNuevoBtn').addEventListener('click', () => abrirAltaEdicionSesCh(null));
  $('#sesChFiltrosBtn').addEventListener('click', () => abrirModalFiltrosSesCh());
  $('#sesChRefrescarBtn').addEventListener('click', () => cargarSesCh());

  const inp = $('#sesChSearch');
  const clr = $('#sesChSearchClear');
  inp.value = sesChFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    sesChFiltros.q = inp.value.trim();
    clearTimeout(sesChBuscadorTimer);
    sesChBuscadorTimer = setTimeout(() => { cargarSesCh(); refrescarBadgeFiltrosSesCh(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    sesChFiltros.q = '';
    cargarSesCh();
    refrescarBadgeFiltrosSesCh();
  });

  $('#sesChCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarSesCh(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionSesCh(data.id);
    if (b.dataset.action === 'eliminar')  eliminarSesCh(data.id);
  });

  $('#sesChTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#sesChCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarSesCh(Number(tr.dataset.id));
  });
  $('#sesChTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#sesChCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosSesCh();
  await cargarSesCh();
}, 'Canales');

async function cargarSesCh() {
  const tbody = $('#sesChTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(sesChFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/awssescanales.php?' + qs.toString());
    pintarStatsSesCh(data.stats);
    pintarTablaSesCh(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsSesCh(s) {
  const cards = $$('#sesChStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.habilitados);
}

function pintarTablaSesCh(rows) {
  const tbody = $('#sesChTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin canales.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td class="td-nombre">${esc(c.nombre || '—')}</td>
      <td>${esc(c.correo || '—')}</td>
      <td style="font-family:monospace">${esc(c.servidor || '—')}</td>
      <td style="font-family:monospace">${esc(c.usuario || '—')}</td>
      <td>${sesChHabilitadoBadge(c.habilitado)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroSesCh(key, value) {
  if (['habilitado', 'order_by', 'dir'].includes(key)) {
    sesChFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    sesChFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    sesChFiltros.limite = n;
  } else {
    sesChFiltros[key] = value;
  }
  refrescarBadgeFiltrosSesCh();
  cargarSesCh();
}

function refrescarBadgeFiltrosSesCh() {
  const btn   = $('#sesChFiltrosBtn');
  const badge = $('#sesChFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(sesChFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(sesChFiltros[k]) !== String(sesChFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosSesCh() {
  const f = sesChFiltros;
  $('#fSesChCodigo').value     = f.codigo;
  $('#fSesChHabilitado').value = f.habilitado;
  $('#fSesChLimite').value     = f.limite;
  $('#fSesChOrderBy').value    = f.order_by;
  $('#fSesChDir').value        = f.dir;
}

function abrirModalFiltrosSesCh() {
  sesChFiltrosSnapshot = { ...sesChFiltros };
  sincronizarControlesFiltrosSesCh();
  $('#filtrosSesChBackdrop').classList.add('open');
}
function cerrarModalFiltrosSesCh() { $('#filtrosSesChBackdrop').classList.remove('open'); }
function cancelarFiltrosSesCh() {
  if (sesChFiltrosSnapshot) {
    Object.assign(sesChFiltros, sesChFiltrosSnapshot);
    refrescarBadgeFiltrosSesCh();
    cargarSesCh();
  }
  cerrarModalFiltrosSesCh();
}
function limpiarFiltrosSesCh() {
  Object.assign(sesChFiltros, sesChFiltrosDefaults);
  sesChFiltros.q = $('#sesChSearch')?.value.trim() || '';
  sincronizarControlesFiltrosSesCh();
  refrescarBadgeFiltrosSesCh();
  cargarSesCh();
}
window.onFiltroSesCh           = onFiltroSesCh;
window.cancelarFiltrosSesCh    = cancelarFiltrosSesCh;
window.limpiarFiltrosSesCh     = limpiarFiltrosSesCh;
window.cerrarModalFiltrosSesCh = cerrarModalFiltrosSesCh;

async function abrirConsultarSesCh(id) {
  openModal(`
    <div class="modal" style="max-width:760px">
      <div class="modal-header">
        <div class="modal-title">Canal SES <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionSesCh(id); }
  });

  try {
    const c = await apiGet(`api/awssescanales.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaSesCh(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaSesCh(c) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  return `
    <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-size:1.15rem;font-weight:700">${esc(c.nombre || '—')}</div>
        <div style="font-size:.8rem;color:var(--muted);margin-top:4px">
          #${esc(c.id)} · UUID <code>${esc(c.uuid || '—')}</code>
        </div>
      </div>
      <div>${sesChHabilitadoBadge(c.habilitado)}</div>
    </div>

    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Nombre',   c.nombre)}
      ${card('Correo',   c.correo)}
      ${card('Servidor', c.servidor, false, true)}
      ${card('Usuario',  c.usuario, false, true)}
      ${card('Contraseña', c.contrasena ? '••••••••' : null, false, true)}
      ${card('Habilitado', c.habilitado === '1' ? 'Sí' : (c.habilitado === '0' ? 'No' : '—'))}
    </dl>
  `;
}

async function abrirAltaEdicionSesCh(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal" style="max-width:680px">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar canal <span class="modal-subtitle">#${id}</span>` : 'Nuevo canal'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formSesChHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/awssescanales.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formSesChHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarSesCh(id, a);
  });
}

function formSesChHtml(c) {
  const v   = (k) => esc(c?.[k] ?? '');
  const sel = (k, val) => (c?.[k] ?? '') === val ? 'selected' : '';
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre</label>
        <input type="text" id="sesChNombre" maxlength="255" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Correo</label>
        <input type="email" id="sesChCorreo" maxlength="255" value="${v('correo')}">
      </div>
    </div>
    <div class="form-group">
      <label>Servidor (SMTP host)</label>
      <input type="text" id="sesChServidor" maxlength="255" value="${v('servidor')}" style="font-family:monospace"
             placeholder="ej. email-smtp.us-east-1.amazonaws.com">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Usuario (SMTP)</label>
        <input type="text" id="sesChUsuario" maxlength="255" value="${v('usuario')}" style="font-family:monospace"
               autocomplete="off">
      </div>
      <div class="form-group">
        <label>Contraseña (SMTP)</label>
        <input type="password" id="sesChContrasena" maxlength="255" value="${v('contrasena')}" style="font-family:monospace"
               autocomplete="new-password">
      </div>
    </div>
    <div class="form-group">
      <label>Estado</label>
      <select id="sesChHabilitado">
        <option value=""  ${sel('habilitado','')}>—</option>
        <option value="1" ${sel('habilitado','1')}>Habilitado</option>
        <option value="0" ${sel('habilitado','0')}>Deshabilitado</option>
      </select>
    </div>
    <div class="field-error" id="sesChFormError" style="display:none"></div>
  `;
}

async function guardarSesCh(id, btn) {
  const err = $('#sesChFormError');
  err.style.display = 'none';

  const payload = {
    nombre:     $('#sesChNombre').value.trim(),
    correo:     $('#sesChCorreo').value.trim(),
    servidor:   $('#sesChServidor').value.trim(),
    usuario:    $('#sesChUsuario').value.trim(),
    contrasena: $('#sesChContrasena').value,
    habilitado: $('#sesChHabilitado').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/awssescanales.php', 'POST', payload);
      toast('Canal creado.');
    } else {
      await apiSend(`api/awssescanales.php?id=${id}`, 'PUT', payload);
      toast('Canal actualizado.');
    }
    closeModal();
    cargarSesCh();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarSesCh(id) {
  const ok = await confirmar({
    title: 'Eliminar canal',
    message: `Se eliminará el canal #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/awssescanales.php?id=${id}`, 'DELETE');
    toast('Canal eliminado.');
    cargarSesCh();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Evolution API (landing) -------------------------
route('/evolution', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Evolution API</div>
      <div class="page-subtitle">Motor de WhatsApp: mensajes registrados, canales conectados y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/evolutionmensajes'">
        <span class="tile-icon">✉️</span>
        <span class="tile-title">Mensajes</span>
        <span class="tile-desc">Cada envío individual de WhatsApp procesado por Evolution API, con destinatario, cuerpo, estado y tiempo de entrega.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/evolutioncanales'">
        <span class="tile-icon">📡</span>
        <span class="tile-title">Canales</span>
        <span class="tile-desc">Los canales de Evolution API: número, token, prefijo, webhook y estado de conexión por canal.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/evolutioncontactos'">
        <span class="tile-icon">👥</span>
        <span class="tile-title">Contactos</span>
        <span class="tile-desc">Registro de destinos verificados por Evolution API: fecha, número, estado y error de validación.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('http://evolution.york.databox.net.ar/manager', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre el manager de Evolution API en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'Evolution API');

// ------------------------- Vista: Evolution API > Mensajes (ABM) -------------------------
const evoMsgFiltrosDefaults = {
  q: '', codigo: '', proyecto: '', canal: '', plantilla: '',
  estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const evoMsgFiltros = { ...evoMsgFiltrosDefaults };
let evoMsgBuscadorTimer   = null;
let evoMsgFiltrosSnapshot = null;

const EVO_MSG_FORMATO_MAP = {
  T: 'Texto plano',
  H: 'HTML',
  M: 'Markdown',
};
const EVO_MSG_PRIORIDAD_MAP = {
  A: 'Alta',
  N: 'Normal',
  B: 'Baja',
};

function evoMsgEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    P: 'badge-warn',
    E: 'badge-success',
    F: 'badge-danger',
    C: 'badge-danger',
    R: 'badge-info',
  };
  const labelMap = {
    P: 'Pendiente', E: 'Enviado', F: 'Fallado', C: 'Cancelado', R: 'Reintento',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

function evoMsgFmtDemora(seg) {
  if (seg == null || seg === '' || isNaN(Number(seg))) return '—';
  const n = Number(seg);
  if (n < 60)    return `${n}s`;
  if (n < 3600)  return `${Math.round(n / 60)}m`;
  return `${(n / 3600).toFixed(1)}h`;
}

route('/evolutionmensajes', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Evolution API" onclick="location.hash='#/evolution'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">✉️</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los mensajes de Evolution API son cada WhatsApp individual que el motor
          procesa, con su remitente, destinatario, asunto, cuerpo y el estado del
          envío registrado por la plataforma.
        </div>
      </div>

      <div class="stats-bar" id="evoMsgStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Enviados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Con error</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="evoMsgSearch"
                   placeholder="🔍 Buscar destinatario, destino, asunto o tags…">
            <button class="search-clear" id="evoMsgSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="evoMsgFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="evoMsgFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="evoMsgRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="evoMsgNuevoBtn">+ Nuevo mensaje</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Destinatario</th>
              <th>Destino</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Enviado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="evoMsgTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="evoMsgCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosEvoMsgBackdrop"
         onclick="if(event.target===this)cancelarFiltrosEvoMsg()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosEvoMsg()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fEvoMsgCodigo" min="1" placeholder="ID …" oninput="onFiltroEvoMsg('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fEvoMsgEstado" onchange="onFiltroEvoMsg('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="P">Pendiente</option>
                <option value="E">Enviado</option>
                <option value="F">Fallado</option>
                <option value="C">Cancelado</option>
                <option value="R">Reintento</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Proyecto (ID)</label>
              <input type="number" id="fEvoMsgProyecto" min="1" oninput="onFiltroEvoMsg('proyecto', this.value)">
            </div>
            <div class="form-group">
              <label>Canal (ID)</label>
              <input type="number" id="fEvoMsgCanal" min="1" oninput="onFiltroEvoMsg('canal', this.value)">
            </div>
            <div class="form-group">
              <label>Plantilla (ID)</label>
              <input type="number" id="fEvoMsgPlantilla" min="1" oninput="onFiltroEvoMsg('plantilla', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fEvoMsgDesde" onchange="onFiltroEvoMsg('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fEvoMsgHasta" onchange="onFiltroEvoMsg('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fEvoMsgLimite" min="1" max="1000" value="100" onchange="onFiltroEvoMsg('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fEvoMsgOrderBy" onchange="onFiltroEvoMsg('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="destinatario">Destinatario</option>
                <option value="destino">Destino</option>
                <option value="asunto">Asunto</option>
                <option value="estado">Estado</option>
                <option value="enviado">Enviado</option>
                <option value="demora">Demora</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fEvoMsgDir" onchange="onFiltroEvoMsg('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosEvoMsg()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosEvoMsg()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosEvoMsg()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#evoMsgNuevoBtn').addEventListener('click', () => abrirAltaEdicionEvoMsg(null));
  $('#evoMsgFiltrosBtn').addEventListener('click', () => abrirModalFiltrosEvoMsg());
  $('#evoMsgRefrescarBtn').addEventListener('click', () => cargarEvoMsg());

  const inp = $('#evoMsgSearch');
  const clr = $('#evoMsgSearchClear');
  inp.value = evoMsgFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    evoMsgFiltros.q = inp.value.trim();
    clearTimeout(evoMsgBuscadorTimer);
    evoMsgBuscadorTimer = setTimeout(() => { cargarEvoMsg(); refrescarBadgeFiltrosEvoMsg(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    evoMsgFiltros.q = '';
    cargarEvoMsg();
    refrescarBadgeFiltrosEvoMsg();
  });

  $('#evoMsgCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarEvoMsg(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionEvoMsg(data.id);
    if (b.dataset.action === 'eliminar')  eliminarEvoMsg(data.id);
  });

  $('#evoMsgTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#evoMsgCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarEvoMsg(Number(tr.dataset.id));
  });
  $('#evoMsgTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#evoMsgCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosEvoMsg();
  await cargarEvoMsg();
}, 'Mensajes');

async function cargarEvoMsg() {
  const tbody = $('#evoMsgTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(evoMsgFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/evolutionmensajes.php?' + qs.toString());
    pintarStatsEvoMsg(data.stats);
    pintarTablaEvoMsg(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsEvoMsg(s) {
  const cards = $$('#evoMsgStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.enviados);
  cards[2].textContent = fmtNum(s.con_error);
}

function pintarTablaEvoMsg(rows) {
  const tbody = $('#evoMsgTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin mensajes.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((m) => `
    <tr data-id="${m.id}" class="row-clickable">
      <td class="td-id">#${esc(m.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(m.fecha))}</td>
      <td class="td-nombre">${esc(m.destinatario || '—')}</td>
      <td style="font-family:monospace">${esc(m.destino || '—')}</td>
      <td>${esc(m.asunto || '—')}</td>
      <td>${evoMsgEstadoBadge(m.estado)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(m.enviado))}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${m.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroEvoMsg(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    evoMsgFiltros[key] = value;
  } else if (['codigo', 'proyecto', 'canal', 'plantilla'].includes(key)) {
    const v = String(value).trim();
    evoMsgFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    evoMsgFiltros.limite = n;
  } else {
    evoMsgFiltros[key] = value;
  }
  refrescarBadgeFiltrosEvoMsg();
  cargarEvoMsg();
}

function refrescarBadgeFiltrosEvoMsg() {
  const btn   = $('#evoMsgFiltrosBtn');
  const badge = $('#evoMsgFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(evoMsgFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(evoMsgFiltros[k]) !== String(evoMsgFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosEvoMsg() {
  const f = evoMsgFiltros;
  $('#fEvoMsgCodigo').value    = f.codigo;
  $('#fEvoMsgEstado').value    = f.estado;
  $('#fEvoMsgProyecto').value  = f.proyecto;
  $('#fEvoMsgCanal').value     = f.canal;
  $('#fEvoMsgPlantilla').value = f.plantilla;
  $('#fEvoMsgDesde').value     = f.desde;
  $('#fEvoMsgHasta').value     = f.hasta;
  $('#fEvoMsgLimite').value    = f.limite;
  $('#fEvoMsgOrderBy').value   = f.order_by;
  $('#fEvoMsgDir').value       = f.dir;
}

function abrirModalFiltrosEvoMsg() {
  evoMsgFiltrosSnapshot = { ...evoMsgFiltros };
  sincronizarControlesFiltrosEvoMsg();
  $('#filtrosEvoMsgBackdrop').classList.add('open');
}
function cerrarModalFiltrosEvoMsg() { $('#filtrosEvoMsgBackdrop').classList.remove('open'); }
function cancelarFiltrosEvoMsg() {
  if (evoMsgFiltrosSnapshot) {
    Object.assign(evoMsgFiltros, evoMsgFiltrosSnapshot);
    refrescarBadgeFiltrosEvoMsg();
    cargarEvoMsg();
  }
  cerrarModalFiltrosEvoMsg();
}
function limpiarFiltrosEvoMsg() {
  Object.assign(evoMsgFiltros, evoMsgFiltrosDefaults);
  evoMsgFiltros.q = $('#evoMsgSearch')?.value.trim() || '';
  sincronizarControlesFiltrosEvoMsg();
  refrescarBadgeFiltrosEvoMsg();
  cargarEvoMsg();
}
window.onFiltroEvoMsg           = onFiltroEvoMsg;
window.cancelarFiltrosEvoMsg    = cancelarFiltrosEvoMsg;
window.limpiarFiltrosEvoMsg     = limpiarFiltrosEvoMsg;
window.cerrarModalFiltrosEvoMsg = cerrarModalFiltrosEvoMsg;

async function abrirConsultarEvoMsg(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1200px">
      <div class="modal-header">
        <div class="modal-title">Mensaje Evolution <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionEvoMsg(id); }
  });

  try {
    const m = await apiGet(`api/evolutionmensajes.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaEvoMsg(m);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaEvoMsg(m) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  const cuerpoHtml = m.cuerpo && String(m.cuerpo).trim() !== ''
    ? (m.formato === 'H'
        ? `<iframe srcdoc="${esc(m.cuerpo)}" style="width:100%;min-height:280px;border:1px solid var(--border);border-radius:8px;background:white"></iframe>`
        : `<pre style="white-space:pre-wrap;font-family:monospace;background:color-mix(in srgb, var(--surface) 90%, #000);padding:14px;border-radius:8px;margin:0;font-size:.85rem;line-height:1.5">${esc(m.cuerpo)}</pre>`)
    : `<div style="color:var(--muted);font-style:italic">Sin cuerpo</div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.3rem;font-weight:700">${esc(m.destinatario || '—')}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(m.destino || '')}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(m.asunto || 'Sin asunto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(m.id)}</div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${evoMsgEstadoBadge(m.estado)}</div>
        <div style="margin-top:6px;font-size:.85rem;line-height:1.5">
          <div><span style="color:var(--muted)">Fecha:</span> ${esc(fmtFecha(m.fecha))}</div>
          <div><span style="color:var(--muted)">Encolado:</span> ${esc(fmtFecha(m.encolado))}</div>
          <div><span style="color:var(--muted)">Enviado:</span> ${esc(fmtFecha(m.enviado))}</div>
        </div>
      </div>
    </div>

    ${seccion('Cuerpo del mensaje')}
    ${cuerpoHtml}

    ${seccion('Remitente y destinatario')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Remitente',    m.remitente)}
      ${card('Remite',       m.remite, false, true)}
      ${card('Destinatario', m.destinatario)}
      ${card('Destino',      m.destino, false, true)}
    </dl>

    ${seccion('Contexto de envío')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Proyecto',   m.proyecto)}
      ${card('Canal',      m.canal)}
      ${card('Plantilla',  m.plantilla)}
      ${card('Prioridad',  EVO_MSG_PRIORIDAD_MAP[m.prioridad] || m.prioridad)}
      ${card('Formato',    EVO_MSG_FORMATO_MAP[m.formato]     || m.formato)}
      ${card('Codificado', m.codificado)}
    </dl>

    ${seccion('Tiempos y resultado')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Fecha',    fmtFecha(m.fecha))}
      ${card('Encolado', fmtFecha(m.encolado))}
      ${card('Enviado',  fmtFecha(m.enviado))}
      ${card('Demora',   evoMsgFmtDemora(m.demora))}
      ${card('Estado',   m.estado)}
      ${card('Tags',     m.tags)}
    </dl>

    ${seccion('Adjunto, variables y errores')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Adjunto',    m.adjunto, true, true)}
      ${card('Variables',  m.variables, true, true)}
      ${card('Parámetros', m.parametros, true, true)}
      ${card('Error',      m.error, true)}
    </dl>
  `;
}

async function abrirAltaEdicionEvoMsg(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar mensaje <span class="modal-subtitle">#${id}</span>` : 'Nuevo mensaje'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formEvoMsgHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const m = await apiGet(`api/evolutionmensajes.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formEvoMsgHtml(m);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarEvoMsg(id, a);
  });
}

function formEvoMsgHtml(m) {
  const v   = (k) => esc(m?.[k] ?? '');
  const sel = (k, val) => (m?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = m?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="evoFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Prioridad</label>
        <select id="evoPrioridad">
          <option value=""  ${sel('prioridad','')}>—</option>
          <option value="A" ${sel('prioridad','A')}>Alta</option>
          <option value="N" ${sel('prioridad','N')}>Normal</option>
          <option value="B" ${sel('prioridad','B')}>Baja</option>
        </select>
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="evoEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="E" ${sel('estado','E')}>Enviado</option>
          <option value="F" ${sel('estado','F')}>Fallado</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="R" ${sel('estado','R')}>Reintento</option>
        </select>
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Proyecto (ID)</label>
        <input type="number" id="evoProyecto" min="1" value="${v('proyecto')}">
      </div>
      <div class="form-group">
        <label>Canal (ID)</label>
        <input type="number" id="evoCanal" min="1" value="${v('canal')}">
      </div>
      <div class="form-group">
        <label>Plantilla (ID)</label>
        <input type="number" id="evoPlantilla" min="1" value="${v('plantilla')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Remitente</label>
        <input type="text" id="evoRemitente" maxlength="255" value="${v('remitente')}">
      </div>
      <div class="form-group">
        <label>Remite</label>
        <input type="text" id="evoRemite" maxlength="255" value="${v('remite')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Destinatario</label>
        <input type="text" id="evoDestinatario" maxlength="255" value="${v('destinatario')}">
      </div>
      <div class="form-group">
        <label>Destino</label>
        <input type="text" id="evoDestino" maxlength="255" value="${v('destino')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Asunto</label>
      <input type="text" id="evoAsunto" maxlength="255" value="${v('asunto')}">
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Formato</label>
        <select id="evoFormato">
          <option value=""  ${sel('formato','')}>—</option>
          <option value="T" ${sel('formato','T')}>Texto plano</option>
          <option value="H" ${sel('formato','H')}>HTML</option>
          <option value="M" ${sel('formato','M')}>Markdown</option>
        </select>
      </div>
      <div class="form-group">
        <label>Codificado</label>
        <input type="text" id="evoCodificado" maxlength="1" value="${v('codificado')}">
      </div>
      <div class="form-group">
        <label>Tags</label>
        <input type="text" id="evoTags" maxlength="255" value="${v('tags')}">
      </div>
    </div>
    <div class="form-group">
      <label>Cuerpo</label>
      <textarea id="evoCuerpo" rows="8" style="font-family:monospace">${v('cuerpo')}</textarea>
    </div>
    <div class="form-group">
      <label>Variables</label>
      <textarea id="evoVariables" rows="3" style="font-family:monospace">${v('variables')}</textarea>
    </div>
    <div class="form-group">
      <label>Parámetros</label>
      <textarea id="evoParametros" rows="3" style="font-family:monospace">${v('parametros')}</textarea>
    </div>
    <div class="form-group">
      <label>Adjunto (URL/ruta)</label>
      <input type="text" id="evoAdjunto" maxlength="500" value="${v('adjunto')}" style="font-family:monospace">
    </div>
    <div class="form-group">
      <label>Error</label>
      <textarea id="evoErrorTxt" rows="2" maxlength="1000">${v('error')}</textarea>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Encolado</label>
        <input type="datetime-local" id="evoEncolado" value="${dt('encolado')}">
      </div>
      <div class="form-group">
        <label>Enviado</label>
        <input type="datetime-local" id="evoEnviado" value="${dt('enviado')}">
      </div>
      <div class="form-group">
        <label>Demora (seg.)</label>
        <input type="number" id="evoDemora" min="0" value="${v('demora')}">
      </div>
    </div>
    <div class="field-error" id="evoFormError" style="display:none"></div>
  `;
}

async function guardarEvoMsg(id, btn) {
  const err = $('#evoFormError');
  err.style.display = 'none';

  const payload = {
    fecha:        $('#evoFecha').value || null,
    prioridad:    $('#evoPrioridad').value,
    estado:       $('#evoEstado').value,
    proyecto:     $('#evoProyecto').value,
    canal:        $('#evoCanal').value,
    plantilla:    $('#evoPlantilla').value,
    remitente:    $('#evoRemitente').value.trim(),
    remite:       $('#evoRemite').value.trim(),
    destinatario: $('#evoDestinatario').value.trim(),
    destino:      $('#evoDestino').value.trim(),
    asunto:       $('#evoAsunto').value.trim(),
    formato:      $('#evoFormato').value,
    codificado:   $('#evoCodificado').value.trim(),
    tags:         $('#evoTags').value.trim(),
    cuerpo:       $('#evoCuerpo').value,
    variables:    $('#evoVariables').value,
    parametros:   $('#evoParametros').value,
    adjunto:      $('#evoAdjunto').value.trim(),
    error:        $('#evoErrorTxt').value,
    encolado:     $('#evoEncolado').value || null,
    enviado:      $('#evoEnviado').value || null,
    demora:       $('#evoDemora').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/evolutionmensajes.php', 'POST', payload);
      toast('Mensaje creado.');
    } else {
      await apiSend(`api/evolutionmensajes.php?id=${id}`, 'PUT', payload);
      toast('Mensaje actualizado.');
    }
    closeModal();
    cargarEvoMsg();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarEvoMsg(id) {
  const ok = await confirmar({
    title: 'Eliminar mensaje',
    message: `Se eliminará el mensaje #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/evolutionmensajes.php?id=${id}`, 'DELETE');
    toast('Mensaje eliminado.');
    cargarEvoMsg();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Evolution API > Canales (ABM) -------------------------
const evoChFiltrosDefaults = {
  q: '', codigo: '', proyecto: '', habilitado: '', online: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const evoChFiltros = { ...evoChFiltrosDefaults };
let evoChBuscadorTimer   = null;
let evoChFiltrosSnapshot = null;

function evoChHabilitadoBadge(h) {
  if (h === '1') return `<span class="badge badge-success">Habilitado</span>`;
  if (h === '0') return `<span class="badge badge-danger">Deshabilitado</span>`;
  return `<span class="badge badge-info">—</span>`;
}

function evoChOnlineBadge(o) {
  if (o === '1') return `<span class="badge badge-success">Online</span>`;
  if (o === '0') return `<span class="badge badge-danger">Offline</span>`;
  return `<span class="badge badge-info">—</span>`;
}

route('/evolutioncanales', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Evolution API" onclick="location.hash='#/evolution'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📡</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los canales de Evolution API son cada instancia conectada de WhatsApp,
          con su número, token, webhook, intervalos de envío y estado de conexión
          con la plataforma.
        </div>
      </div>

      <div class="stats-bar" id="evoChStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Habilitados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Online</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="evoChSearch"
                   placeholder="🔍 Buscar nombre, número, celular o token…">
            <button class="search-clear" id="evoChSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="evoChFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="evoChFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="evoChRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="evoChNuevoBtn">+ Nuevo canal</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Proyecto</th>
              <th>Número</th>
              <th>Celular</th>
              <th>Enviados</th>
              <th>Habilitado</th>
              <th>Online</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="evoChTbody">
            <tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="evoChCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosEvoChBackdrop"
         onclick="if(event.target===this)cancelarFiltrosEvoCh()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosEvoCh()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fEvoChCodigo" min="1" placeholder="ID …" oninput="onFiltroEvoCh('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Proyecto (ID)</label>
              <input type="number" id="fEvoChProyecto" min="1" oninput="onFiltroEvoCh('proyecto', this.value)">
            </div>
            <div class="form-group">
              <label>Habilitado</label>
              <select id="fEvoChHabilitado" onchange="onFiltroEvoCh('habilitado', this.value)">
                <option value="">— Todos —</option>
                <option value="1">Habilitados</option>
                <option value="0">Deshabilitados</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Online</label>
              <select id="fEvoChOnline" onchange="onFiltroEvoCh('online', this.value)">
                <option value="">— Todos —</option>
                <option value="1">Online</option>
                <option value="0">Offline</option>
              </select>
            </div>
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fEvoChLimite" min="1" max="1000" value="100" onchange="onFiltroEvoCh('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fEvoChOrderBy" onchange="onFiltroEvoCh('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="proyecto">Proyecto</option>
                <option value="numero">Número</option>
                <option value="celular">Celular</option>
                <option value="enviados">Enviados</option>
                <option value="habilitado">Habilitado</option>
                <option value="online">Online</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Dirección</label>
              <select id="fEvoChDir" onchange="onFiltroEvoCh('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosEvoCh()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosEvoCh()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosEvoCh()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#evoChNuevoBtn').addEventListener('click', () => abrirAltaEdicionEvoCh(null));
  $('#evoChFiltrosBtn').addEventListener('click', () => abrirModalFiltrosEvoCh());
  $('#evoChRefrescarBtn').addEventListener('click', () => cargarEvoCh());

  const inp = $('#evoChSearch');
  const clr = $('#evoChSearchClear');
  inp.value = evoChFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    evoChFiltros.q = inp.value.trim();
    clearTimeout(evoChBuscadorTimer);
    evoChBuscadorTimer = setTimeout(() => { cargarEvoCh(); refrescarBadgeFiltrosEvoCh(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    evoChFiltros.q = '';
    cargarEvoCh();
    refrescarBadgeFiltrosEvoCh();
  });

  $('#evoChCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarEvoCh(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionEvoCh(data.id);
    if (b.dataset.action === 'eliminar')  eliminarEvoCh(data.id);
  });

  $('#evoChTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#evoChCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarEvoCh(Number(tr.dataset.id));
  });
  $('#evoChTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#evoChCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosEvoCh();
  await cargarEvoCh();
}, 'Canales');

async function cargarEvoCh() {
  const tbody = $('#evoChTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(evoChFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/evolutioncanales.php?' + qs.toString());
    pintarStatsEvoCh(data.stats);
    pintarTablaEvoCh(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsEvoCh(s) {
  const cards = $$('#evoChStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.habilitados);
  cards[2].textContent = fmtNum(s.online);
}

function pintarTablaEvoCh(rows) {
  const tbody = $('#evoChTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="table-empty">Sin canales.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td class="td-nombre">${esc(c.nombre || '—')}</td>
      <td>${esc(c.proyecto ?? '—')}</td>
      <td style="font-family:monospace">${esc((c.prefijo ? '+' + c.prefijo + ' ' : '') + (c.numero || '—'))}</td>
      <td style="font-family:monospace">${esc(c.celular || '—')}</td>
      <td style="font-family:monospace">${esc(fmtNum(c.enviados ?? 0))}</td>
      <td>${evoChHabilitadoBadge(c.habilitado)}</td>
      <td>${evoChOnlineBadge(c.online)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroEvoCh(key, value) {
  if (['habilitado', 'online', 'order_by', 'dir'].includes(key)) {
    evoChFiltros[key] = value;
  } else if (['codigo', 'proyecto'].includes(key)) {
    const v = String(value).trim();
    evoChFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    evoChFiltros.limite = n;
  } else {
    evoChFiltros[key] = value;
  }
  refrescarBadgeFiltrosEvoCh();
  cargarEvoCh();
}

function refrescarBadgeFiltrosEvoCh() {
  const btn   = $('#evoChFiltrosBtn');
  const badge = $('#evoChFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(evoChFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(evoChFiltros[k]) !== String(evoChFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosEvoCh() {
  const f = evoChFiltros;
  $('#fEvoChCodigo').value     = f.codigo;
  $('#fEvoChProyecto').value   = f.proyecto;
  $('#fEvoChHabilitado').value = f.habilitado;
  $('#fEvoChOnline').value     = f.online;
  $('#fEvoChLimite').value     = f.limite;
  $('#fEvoChOrderBy').value    = f.order_by;
  $('#fEvoChDir').value        = f.dir;
}

function abrirModalFiltrosEvoCh() {
  evoChFiltrosSnapshot = { ...evoChFiltros };
  sincronizarControlesFiltrosEvoCh();
  $('#filtrosEvoChBackdrop').classList.add('open');
}
function cerrarModalFiltrosEvoCh() { $('#filtrosEvoChBackdrop').classList.remove('open'); }
function cancelarFiltrosEvoCh() {
  if (evoChFiltrosSnapshot) {
    Object.assign(evoChFiltros, evoChFiltrosSnapshot);
    refrescarBadgeFiltrosEvoCh();
    cargarEvoCh();
  }
  cerrarModalFiltrosEvoCh();
}
function limpiarFiltrosEvoCh() {
  Object.assign(evoChFiltros, evoChFiltrosDefaults);
  evoChFiltros.q = $('#evoChSearch')?.value.trim() || '';
  sincronizarControlesFiltrosEvoCh();
  refrescarBadgeFiltrosEvoCh();
  cargarEvoCh();
}
window.onFiltroEvoCh           = onFiltroEvoCh;
window.cancelarFiltrosEvoCh    = cancelarFiltrosEvoCh;
window.limpiarFiltrosEvoCh     = limpiarFiltrosEvoCh;
window.cerrarModalFiltrosEvoCh = cerrarModalFiltrosEvoCh;

async function abrirConsultarEvoCh(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Canal Evolution <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionEvoCh(id); }
  });

  try {
    const c = await apiGet(`api/evolutioncanales.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaEvoCh(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaEvoCh(c) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  const numeroFull = (c.prefijo ? '+' + c.prefijo + ' ' : '') + (c.numero || '');

  return `
    <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-size:1.15rem;font-weight:700">${esc(c.nombre || '—')}</div>
        <div style="font-size:.8rem;color:var(--muted);margin-top:4px">
          #${esc(c.id)} · UUID <code>${esc(c.uuid || '—')}</code>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${evoChHabilitadoBadge(c.habilitado)}
        ${evoChOnlineBadge(c.online)}
      </div>
    </div>

    ${seccion('Identificación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Nombre',   c.nombre)}
      ${card('Proyecto', c.proyecto)}
      ${card('Número',   numeroFull, false, true)}
      ${card('Celular',  c.celular, false, true)}
      ${card('Token',    c.token ? '••••••••' : null, false, true)}
      ${card('Webhook',  c.webhook, false, true)}
    </dl>

    ${seccion('Comportamiento')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Prompt',          c.prompt)}
      ${card('Intervalo corto', c.intervaloCorto)}
      ${card('Intervalo largo', c.intervaloLargo)}
      ${card('Alerta',          c.alerta)}
      ${card('Límite',          c.limite)}
      ${card('Último',          c.ultimo)}
    </dl>

    ${seccion('Contadores')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Enviados',   fmtNum(c.enviados ?? 0))}
      ${card('Acumulados', fmtNum(c.acumulados ?? 0))}
    </dl>

    ${seccion('Estado interno')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Canal estado',  c.canalEstado, true, true)}
      ${card('Grupos estado', c.gruposEstado, true, true)}
    </dl>
  `;
}

async function abrirAltaEdicionEvoCh(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar canal <span class="modal-subtitle">#${id}</span>` : 'Nuevo canal'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formEvoChHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/evolutioncanales.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formEvoChHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarEvoCh(id, a);
  });
}

function formEvoChHtml(c) {
  const v   = (k) => esc(c?.[k] ?? '');
  const sel = (k, val) => (c?.[k] ?? '') === val ? 'selected' : '';
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre</label>
        <input type="text" id="evoChNombre" maxlength="255" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Proyecto (ID)</label>
        <input type="number" id="evoChProyecto" min="1" value="${v('proyecto')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Prefijo</label>
        <input type="text" id="evoChPrefijo" maxlength="10" value="${v('prefijo')}"
               placeholder="ej. 54" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Número</label>
        <input type="text" id="evoChNumero" maxlength="15" value="${v('numero')}"
               style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Celular</label>
        <input type="text" id="evoChCelular" maxlength="20" value="${v('celular')}"
               style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Token</label>
        <input type="text" id="evoChToken" maxlength="50" value="${v('token')}"
               style="font-family:monospace" autocomplete="off">
      </div>
      <div class="form-group">
        <label>Prompt</label>
        <input type="text" id="evoChPrompt" maxlength="100" value="${v('prompt')}">
      </div>
    </div>
    <div class="form-group">
      <label>Webhook</label>
      <input type="text" id="evoChWebhook" maxlength="1000" value="${v('webhook')}"
             style="font-family:monospace" placeholder="https://…">
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Intervalo corto (seg.)</label>
        <input type="number" id="evoChIntervaloCorto" min="0" value="${v('intervaloCorto')}">
      </div>
      <div class="form-group">
        <label>Intervalo largo (seg.)</label>
        <input type="number" id="evoChIntervaloLargo" min="0" value="${v('intervaloLargo')}">
      </div>
      <div class="form-group">
        <label>Último</label>
        <input type="number" id="evoChUltimo" min="0" value="${v('ultimo')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Alerta</label>
        <input type="number" id="evoChAlerta" min="0" value="${v('alerta')}">
      </div>
      <div class="form-group">
        <label>Límite</label>
        <input type="number" id="evoChLimite" min="0" value="${v('limite')}">
      </div>
      <div class="form-group">
        <label>Enviados</label>
        <input type="number" id="evoChEnviados" min="0" value="${v('enviados')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Acumulados</label>
        <input type="number" id="evoChAcumulados" min="0" value="${v('acumulados')}">
      </div>
      <div class="form-group">
        <label>Habilitado</label>
        <select id="evoChHabilitado">
          <option value=""  ${sel('habilitado','')}>—</option>
          <option value="1" ${sel('habilitado','1')}>Habilitado</option>
          <option value="0" ${sel('habilitado','0')}>Deshabilitado</option>
        </select>
      </div>
      <div class="form-group">
        <label>Online</label>
        <select id="evoChOnline">
          <option value=""  ${sel('online','')}>—</option>
          <option value="1" ${sel('online','1')}>Online</option>
          <option value="0" ${sel('online','0')}>Offline</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Canal estado</label>
      <textarea id="evoChCanalEstado" rows="3" style="font-family:monospace">${v('canalEstado')}</textarea>
    </div>
    <div class="form-group">
      <label>Grupos estado</label>
      <textarea id="evoChGruposEstado" rows="3" style="font-family:monospace">${v('gruposEstado')}</textarea>
    </div>
    <div class="field-error" id="evoChFormError" style="display:none"></div>
  `;
}

async function guardarEvoCh(id, btn) {
  const err = $('#evoChFormError');
  err.style.display = 'none';

  const payload = {
    nombre:         $('#evoChNombre').value.trim(),
    proyecto:       $('#evoChProyecto').value,
    prefijo:        $('#evoChPrefijo').value.trim(),
    numero:         $('#evoChNumero').value.trim(),
    celular:        $('#evoChCelular').value.trim(),
    token:          $('#evoChToken').value.trim(),
    prompt:         $('#evoChPrompt').value.trim(),
    webhook:        $('#evoChWebhook').value.trim(),
    intervaloCorto: $('#evoChIntervaloCorto').value,
    intervaloLargo: $('#evoChIntervaloLargo').value,
    ultimo:         $('#evoChUltimo').value,
    alerta:         $('#evoChAlerta').value,
    limite:         $('#evoChLimite').value,
    enviados:       $('#evoChEnviados').value,
    acumulados:     $('#evoChAcumulados').value,
    habilitado:     $('#evoChHabilitado').value,
    online:         $('#evoChOnline').value,
    canalEstado:    $('#evoChCanalEstado').value,
    gruposEstado:   $('#evoChGruposEstado').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/evolutioncanales.php', 'POST', payload);
      toast('Canal creado.');
    } else {
      await apiSend(`api/evolutioncanales.php?id=${id}`, 'PUT', payload);
      toast('Canal actualizado.');
    }
    closeModal();
    cargarEvoCh();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarEvoCh(id) {
  const ok = await confirmar({
    title: 'Eliminar canal',
    message: `Se eliminará el canal #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/evolutioncanales.php?id=${id}`, 'DELETE');
    toast('Canal eliminado.');
    cargarEvoCh();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Evolution API > Contactos (ABM) -------------------------
const evoCtFiltrosDefaults = {
  q: '', codigo: '', estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const evoCtFiltros = { ...evoCtFiltrosDefaults };
let evoCtBuscadorTimer   = null;
let evoCtFiltrosSnapshot = null;

function evoCtEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    O: 'badge-success',
    V: 'badge-success',
    E: 'badge-danger',
    F: 'badge-danger',
    P: 'badge-warn',
  };
  const labelMap = {
    O: 'OK', V: 'Válido', E: 'Error', F: 'Fallado', P: 'Pendiente',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

route('/evolutioncontactos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Evolution API" onclick="location.hash='#/evolution'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">👥</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los contactos de Evolution API son los destinos que la plataforma verifica
          antes de enviar. Cada registro guarda el número consultado, el estado del
          chequeo y el error si la verificación falló.
        </div>
      </div>

      <div class="stats-bar" id="evoCtStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Con error</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="evoCtSearch"
                   placeholder="🔍 Buscar destino o error…">
            <button class="search-clear" id="evoCtSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="evoCtFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="evoCtFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="evoCtRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="evoCtNuevoBtn">+ Nuevo contacto</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Destino</th>
              <th>Estado</th>
              <th>Error</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="evoCtTbody">
            <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="evoCtCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosEvoCtBackdrop"
         onclick="if(event.target===this)cancelarFiltrosEvoCt()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosEvoCt()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fEvoCtCodigo" min="1" placeholder="ID …" oninput="onFiltroEvoCt('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <input type="text" id="fEvoCtEstado" maxlength="1" placeholder="ej. O, E, P…"
                     style="font-family:monospace" oninput="onFiltroEvoCt('estado', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fEvoCtDesde" onchange="onFiltroEvoCt('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fEvoCtHasta" onchange="onFiltroEvoCt('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fEvoCtLimite" min="1" max="1000" value="100" onchange="onFiltroEvoCt('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fEvoCtOrderBy" onchange="onFiltroEvoCt('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="destino">Destino</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fEvoCtDir" onchange="onFiltroEvoCt('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosEvoCt()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosEvoCt()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosEvoCt()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#evoCtNuevoBtn').addEventListener('click', () => abrirAltaEdicionEvoCt(null));
  $('#evoCtFiltrosBtn').addEventListener('click', () => abrirModalFiltrosEvoCt());
  $('#evoCtRefrescarBtn').addEventListener('click', () => cargarEvoCt());

  const inp = $('#evoCtSearch');
  const clr = $('#evoCtSearchClear');
  inp.value = evoCtFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    evoCtFiltros.q = inp.value.trim();
    clearTimeout(evoCtBuscadorTimer);
    evoCtBuscadorTimer = setTimeout(() => { cargarEvoCt(); refrescarBadgeFiltrosEvoCt(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    evoCtFiltros.q = '';
    cargarEvoCt();
    refrescarBadgeFiltrosEvoCt();
  });

  $('#evoCtCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarEvoCt(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionEvoCt(data.id);
    if (b.dataset.action === 'eliminar')  eliminarEvoCt(data.id);
  });

  $('#evoCtTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#evoCtCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarEvoCt(Number(tr.dataset.id));
  });
  $('#evoCtTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#evoCtCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosEvoCt();
  await cargarEvoCt();
}, 'Contactos');

async function cargarEvoCt() {
  const tbody = $('#evoCtTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(evoCtFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/evolutioncontactos.php?' + qs.toString());
    pintarStatsEvoCt(data.stats);
    pintarTablaEvoCt(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsEvoCt(s) {
  const cards = $$('#evoCtStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.con_error);
}

function pintarTablaEvoCt(rows) {
  const tbody = $('#evoCtTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="table-empty">Sin contactos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(c.fecha))}</td>
      <td style="font-family:monospace">${esc(c.destino || '—')}</td>
      <td>${evoCtEstadoBadge(c.estado)}</td>
      <td class="td-nombre" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.error || '—')}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroEvoCt(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    evoCtFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    evoCtFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    evoCtFiltros.limite = n;
  } else {
    evoCtFiltros[key] = value;
  }
  refrescarBadgeFiltrosEvoCt();
  cargarEvoCt();
}

function refrescarBadgeFiltrosEvoCt() {
  const btn   = $('#evoCtFiltrosBtn');
  const badge = $('#evoCtFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(evoCtFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(evoCtFiltros[k]) !== String(evoCtFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosEvoCt() {
  const f = evoCtFiltros;
  $('#fEvoCtCodigo').value  = f.codigo;
  $('#fEvoCtEstado').value  = f.estado;
  $('#fEvoCtDesde').value   = f.desde;
  $('#fEvoCtHasta').value   = f.hasta;
  $('#fEvoCtLimite').value  = f.limite;
  $('#fEvoCtOrderBy').value = f.order_by;
  $('#fEvoCtDir').value     = f.dir;
}

function abrirModalFiltrosEvoCt() {
  evoCtFiltrosSnapshot = { ...evoCtFiltros };
  sincronizarControlesFiltrosEvoCt();
  $('#filtrosEvoCtBackdrop').classList.add('open');
}
function cerrarModalFiltrosEvoCt() { $('#filtrosEvoCtBackdrop').classList.remove('open'); }
function cancelarFiltrosEvoCt() {
  if (evoCtFiltrosSnapshot) {
    Object.assign(evoCtFiltros, evoCtFiltrosSnapshot);
    refrescarBadgeFiltrosEvoCt();
    cargarEvoCt();
  }
  cerrarModalFiltrosEvoCt();
}
function limpiarFiltrosEvoCt() {
  Object.assign(evoCtFiltros, evoCtFiltrosDefaults);
  evoCtFiltros.q = $('#evoCtSearch')?.value.trim() || '';
  sincronizarControlesFiltrosEvoCt();
  refrescarBadgeFiltrosEvoCt();
  cargarEvoCt();
}
window.onFiltroEvoCt           = onFiltroEvoCt;
window.cancelarFiltrosEvoCt    = cancelarFiltrosEvoCt;
window.limpiarFiltrosEvoCt     = limpiarFiltrosEvoCt;
window.cerrarModalFiltrosEvoCt = cerrarModalFiltrosEvoCt;

async function abrirConsultarEvoCt(id) {
  openModal(`
    <div class="modal" style="max-width:760px">
      <div class="modal-header">
        <div class="modal-title">Contacto Evolution <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionEvoCt(id); }
  });

  try {
    const c = await apiGet(`api/evolutioncontactos.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaEvoCt(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaEvoCt(c) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  return `
    <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-family:monospace;font-size:1.15rem;font-weight:700">${esc(c.destino || '—')}</div>
        <div style="font-size:.8rem;color:var(--muted);margin-top:4px">
          #${esc(c.id)} · ${esc(fmtFecha(c.fecha))}
        </div>
      </div>
      <div>${evoCtEstadoBadge(c.estado)}</div>
    </div>

    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Fecha',   fmtFecha(c.fecha))}
      ${card('Destino', c.destino, false, true)}
      ${card('Estado',  c.estado)}
    </dl>

    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      Error
    </div>
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Error', c.error, true)}
    </dl>
  `;
}

async function abrirAltaEdicionEvoCt(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal" style="max-width:680px">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar contacto <span class="modal-subtitle">#${id}</span>` : 'Nuevo contacto'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formEvoCtHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/evolutioncontactos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formEvoCtHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarEvoCt(id, a);
  });
}

function formEvoCtHtml(c) {
  const v  = (k) => esc(c?.[k] ?? '');
  const dt = (k) => {
    const raw = c?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="evoCtFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <input type="text" id="evoCtEstado" maxlength="1" value="${v('estado')}"
               style="font-family:monospace" placeholder="ej. O, E, P…">
      </div>
    </div>
    <div class="form-group">
      <label>Destino</label>
      <input type="text" id="evoCtDestino" maxlength="50" value="${v('destino')}"
             style="font-family:monospace" placeholder="ej. 5491112345678">
    </div>
    <div class="form-group">
      <label>Error</label>
      <textarea id="evoCtErrorTxt" rows="4" style="font-family:monospace">${v('error')}</textarea>
    </div>
    <div class="field-error" id="evoCtFormError" style="display:none"></div>
  `;
}

async function guardarEvoCt(id, btn) {
  const err = $('#evoCtFormError');
  err.style.display = 'none';

  const payload = {
    fecha:   $('#evoCtFecha').value || null,
    destino: $('#evoCtDestino').value.trim(),
    estado:  $('#evoCtEstado').value.trim(),
    error:   $('#evoCtErrorTxt').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/evolutioncontactos.php', 'POST', payload);
      toast('Contacto creado.');
    } else {
      await apiSend(`api/evolutioncontactos.php?id=${id}`, 'PUT', payload);
      toast('Contacto actualizado.');
    }
    closeModal();
    cargarEvoCt();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarEvoCt(id) {
  const ok = await confirmar({
    title: 'Eliminar contacto',
    message: `Se eliminará el contacto #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/evolutioncontactos.php?id=${id}`, 'DELETE');
    toast('Contacto eliminado.');
    cargarEvoCt();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago (landing) -------------------------
route('/mercadopago', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Mercadopago</div>
      <div class="page-subtitle">Pasarela de pagos: pagos registrados y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/mercadopagocuentas'">
        <span class="tile-icon">🏦</span>
        <span class="tile-title">Cuentas</span>
        <span class="tile-desc">Las cuentas Mercadopago configuradas: CVU, credenciales de producción y testing, webhooks e imputación contable.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/mercadopagopagos'">
        <span class="tile-icon">💳</span>
        <span class="tile-title">Pagos</span>
        <span class="tile-desc">Cada pago procesado por Mercadopago, con cuenta, factura, monto, operación y estado del cobro.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/mercadopagosuscripciones'">
        <span class="tile-icon">🔁</span>
        <span class="tile-title">Suscripciones</span>
        <span class="tile-desc">Suscripciones recurrentes de Mercadopago: suscriptor, período, monto, fechas de ciclo (inicio, pausa, fin) y estado.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/mercadopagodebitos'">
        <span class="tile-icon">📉</span>
        <span class="tile-title">Débitos</span>
        <span class="tile-desc">Débitos ejecutados sobre las suscripciones: cuenta, referencia, monto, operación y resultado del cobro.</span>
      </button>
      <button type="button" class="tile-card" onclick="location.hash='#/mercadopagoregistros'">
        <span class="tile-icon">📰</span>
        <span class="tile-title">Registros</span>
        <span class="tile-desc">Log crudo de eventos y notificaciones recibidos desde Mercadopago, con fecha, tipo y cuerpo del evento.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://www.mercadopago.com.ar/developers', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre el portal de desarrolladores de Mercadopago en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'Mercadopago');

// ------------------------- Vista: Dolarhoy (landing) -------------------------
route('/dolarhoy', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Dolarhoy</div>
      <div class="page-subtitle">Cotizaciones del dólar: registros históricos y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/dolarhoycotizaciones'">
        <span class="tile-icon">💵</span>
        <span class="tile-title">Cotizaciones</span>
        <span class="tile-desc">Cotizaciones históricas del dólar tomadas de Dolarhoy: fecha, precio de compra y precio de venta.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://dolarhoy.com/', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre el sitio de Dolarhoy en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'Dolarhoy');

// ------------------------- Vista: Movistar (landing) -------------------------
route('/movistar', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Movistar</div>
      <div class="page-subtitle">Kite Platform de Movistar: gestión de SIMs M2M y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/movistarsims'">
        <span class="tile-icon">📶</span>
        <span class="tile-title">SIMs</span>
        <span class="tile-desc">Catálogo de SIMs M2M administradas vía Kite Platform: línea, ICC, estado, IMEI, MSISDN y sincronización desde Kite.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://mimovistarempresas.movistar.com.ar/', '_blank', 'noopener')">
        <span class="tile-icon">💼</span>
        <span class="tile-title">Comercial</span>
        <span class="tile-desc">Abre el portal Mi Movistar Empresas en una pestaña nueva.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://kiteplatform-movistar-ar.telefonica.com/', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre la consola de Kite Platform en una pestaña nueva.</span>
      </button>
    </div>

    <div style="display:block;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;margin-top:20px;box-shadow:var(--shadow);font-size:.88rem;color:var(--text);line-height:1.55">
      <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Procedimiento de alta de SIM</div>
      <ul style="margin:0 0 18px 20px;padding:0;color:var(--muted)">
        <li>Filtrar SIM/s necesarias y seleccionarlas</li>
        <li><strong style="color:var(--text)">ASIGNAR</strong> grupo de suscripción correspondiente</li>
        <li><strong style="color:var(--text)">CAMBIAR</strong> "Estado de ciclo de vida" a listo para activación</li>
        <li><strong style="color:var(--text)">CAMBIAR</strong> "Etiquetas personalizables" en field 1 agregar etiqueta correspondiente</li>
        <li><strong style="color:var(--text)">CAMBIAR</strong> "Tecnologías de acceso radio" seleccionar 2G, 3G y LTE/LTE-M</li>
        <li><strong style="color:var(--text)">ACTIVAR</strong> "Servicio de VPN"</li>
        <li><strong style="color:var(--text)">ACTIVAR</strong> "Tráfico de datos originados en operador local"</li>
      </ul>

      <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Procedimiento de reactivación de SIM</div>
      <ul style="margin:0 0 18px 20px;padding:0;color:var(--muted)">
        <li>Filtrar SIM/s necesarias y seleccionarlas</li>
        <li><strong style="color:var(--text)">CAMBIAR</strong> "Activada" a listo para rehabilitar</li>
        <li><strong style="color:var(--text)">ACTIVAR</strong> "Servicio de VPN"</li>
        <li><strong style="color:var(--text)">ACTIVAR</strong> "Tráfico de datos originados en operador local"</li>
      </ul>

      <div style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Credenciales</div>
      <dl style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0">
        <dt style="color:var(--muted)">Website:</dt>
        <dd style="margin:0"><a href="https://kiteplatform-movistar-ar.telefonica.com/" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none;font-weight:600">Abrir</a></dd>
        <dt style="color:var(--muted)">Usuario:</dt>
        <dd style="margin:0"><code style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:2px 8px">administracion@alfatec.net.ar</code></dd>
        <dt style="color:var(--muted)">Contraseña:</dt>
        <dd style="margin:0"><code style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:2px 8px">Alfa123++</code></dd>
      </dl>
    </div>
  `;
}, 'Movistar');

// ------------------------- Vista: Movistar > SIMs (ABM) -------------------------
const msimFiltrosDefaults = {
  q: '', codigo: '', estado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const msimFiltros = { ...msimFiltrosDefaults };
let msimBuscadorTimer   = null;
let msimFiltrosSnapshot = null;

function msimFmtEstado(v) {
  if (v == null || v === '') return `<span class="badge badge-info">—</span>`;
  const s   = String(v).toLowerCase();
  const map = {
    activada: 'badge-success', activa: 'badge-success', active: 'badge-success',
    suspendida: 'badge-warn',  suspended: 'badge-warn',
    baja: 'badge-danger',      terminada: 'badge-danger', terminated: 'badge-danger',
    inventario: 'badge-info',  inventory: 'badge-info',
  };
  const cls = map[s] || 'badge-info';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}

route('/movistarsims', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Movistar" onclick="location.hash='#/movistar'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📶</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las SIMs de Movistar son las líneas M2M administradas desde Kite
          Platform (Telefónica) — cada fila trae el nombre (field1), la línea,
          el ICC, el estado general/GPRS/LTE, el límite de datos, el IMEI del
          equipo asociado y el MSISDN. El botón "Sincronizar con Kite" trae
          las líneas vigentes desde la API de Kite y las mantiene actualizadas.
        </div>
      </div>

      <div class="stats-bar" id="msimStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value" data-slot="total">—</span></div>
        <div class="stat-card"><span class="stat-label">Activas</span><span class="stat-value" data-slot="activas">—</span></div>
        <div class="stat-card"><span class="stat-label">Sin estado</span><span class="stat-value" data-slot="sin_estado">—</span></div>
        <div class="stat-card"><span class="stat-label">Última sync</span><span class="stat-value" data-slot="ultima_sync" style="font-size:1rem">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="msimSearch"
                   placeholder="🔍 Buscar nombre, línea, ICC, IMEI o MSISDN…">
            <button class="search-clear" id="msimSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="msimFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="msimFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="msimRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right" style="gap:8px">
          <button class="btn btn-ghost" id="msimSyncBtn" title="Sincronizar con Kite Platform">
            <i class="fa-solid fa-cloud-arrow-down"></i> Sincronizar con Kite
          </button>
          <button class="btn btn-primary" id="msimNuevoBtn">+ Nueva SIM</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:90px">Código</th>
              <th>Nombre</th>
              <th>Línea</th>
              <th style="width:180px">ICC</th>
              <th style="width:120px">Estado</th>
              <th style="width:130px">Límite datos</th>
              <th>MSISDN</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="msimTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="msimCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMsimBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMsim()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMsim()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMsimCodigo" min="1" placeholder="ID …" oninput="onFiltroMsim('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <input type="text" id="fMsimEstado" placeholder="Ej: Activada" oninput="onFiltroMsim('estado', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMsimLimite" min="1" max="2000" value="100" onchange="onFiltroMsim('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMsimOrderBy" onchange="onFiltroMsim('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="linea">Línea</option>
                <option value="icc">ICC</option>
                <option value="estado">Estado</option>
                <option value="msisdn">MSISDN</option>
                <option value="actualizado">Última sync</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fMsimDir" onchange="onFiltroMsim('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMsim()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMsim()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMsim()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#msimNuevoBtn').addEventListener('click',    () => abrirAltaEdicionMsim(null));
  $('#msimFiltrosBtn').addEventListener('click',  () => abrirModalFiltrosMsim());
  $('#msimRefrescarBtn').addEventListener('click',() => cargarMsim());
  $('#msimSyncBtn').addEventListener('click',     () => sincronizarMsim());

  const inp = $('#msimSearch');
  const clr = $('#msimSearchClear');
  inp.value = msimFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    msimFiltros.q = inp.value.trim();
    clearTimeout(msimBuscadorTimer);
    msimBuscadorTimer = setTimeout(() => { cargarMsim(); refrescarBadgeFiltrosMsim(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    msimFiltros.q = '';
    cargarMsim();
    refrescarBadgeFiltrosMsim();
  });

  $('#msimCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMsim(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMsim(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMsim(data.id);
  });

  $('#msimTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#msimCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMsim(Number(tr.dataset.id));
  });
  $('#msimTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#msimCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMsim();
  await cargarMsim();
}, 'SIMs');

async function cargarMsim() {
  const tbody = $('#msimTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(msimFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });

  try {
    const data = await apiGet('api/movistarsims.php?' + qs.toString());
    pintarStatsMsim(data.stats);
    pintarTablaMsim(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMsim(s) {
  const setSlot = (name, val) => {
    const el = document.querySelector(`#msimStats [data-slot="${name}"]`);
    if (el) el.textContent = val;
  };
  setSlot('total',      fmtNum(s?.total      ?? 0));
  setSlot('activas',    fmtNum(s?.activas    ?? 0));
  setSlot('sin_estado', fmtNum(s?.sin_estado ?? 0));
  setSlot('ultima_sync', s?.ultima_sync ? String(s.ultima_sync).replace('T', ' ').slice(0, 16) : '—');
}

function pintarTablaMsim(rows) {
  const tbody = $('#msimTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin SIMs.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td>${esc(r.nombre || '—')}</td>
      <td style="font-family:monospace">${esc(r.linea || '—')}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(r.icc || '—')}</td>
      <td>${msimFmtEstado(r.estado)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(r.limite_datos || '—')}</td>
      <td style="font-family:monospace">${esc(r.msisdn || '—')}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroMsim(key, value) {
  if (['order_by', 'dir', 'estado'].includes(key)) {
    msimFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    msimFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 2000) n = 2000;
    msimFiltros.limite = n;
  } else {
    msimFiltros[key] = value;
  }
  refrescarBadgeFiltrosMsim();
  cargarMsim();
}

function refrescarBadgeFiltrosMsim() {
  const btn   = $('#msimFiltrosBtn');
  const badge = $('#msimFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(msimFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(msimFiltros[k]) !== String(msimFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMsim() {
  const f = msimFiltros;
  $('#fMsimCodigo').value  = f.codigo;
  $('#fMsimEstado').value  = f.estado;
  $('#fMsimLimite').value  = f.limite;
  $('#fMsimOrderBy').value = f.order_by;
  $('#fMsimDir').value     = f.dir;
}

function abrirModalFiltrosMsim() {
  msimFiltrosSnapshot = { ...msimFiltros };
  sincronizarControlesFiltrosMsim();
  $('#filtrosMsimBackdrop').classList.add('open');
}
function cerrarModalFiltrosMsim() { $('#filtrosMsimBackdrop').classList.remove('open'); }
function cancelarFiltrosMsim() {
  if (msimFiltrosSnapshot) {
    Object.assign(msimFiltros, msimFiltrosSnapshot);
    refrescarBadgeFiltrosMsim();
    cargarMsim();
  }
  cerrarModalFiltrosMsim();
}
function limpiarFiltrosMsim() {
  Object.assign(msimFiltros, msimFiltrosDefaults);
  msimFiltros.q = $('#msimSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMsim();
  refrescarBadgeFiltrosMsim();
  cargarMsim();
}
window.onFiltroMsim           = onFiltroMsim;
window.cancelarFiltrosMsim    = cancelarFiltrosMsim;
window.limpiarFiltrosMsim     = limpiarFiltrosMsim;
window.cerrarModalFiltrosMsim = cerrarModalFiltrosMsim;

async function sincronizarMsim() {
  const btn = $('#msimSyncBtn');
  if (!btn) return;
  const html = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<div class="spin" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:6px"></div> Sincronizando…`;
  try {
    const r = await apiSend('api/movistarsims_sync.php', 'POST', {});
    const ins = r?.insertados ?? 0, act = r?.actualizados ?? 0, tot = r?.fetched ?? 0;
    toast(`Kite: ${tot} SIMs (${ins} nuevas, ${act} actualizadas).`);
    cargarMsim();
  } catch (e) {
    toast(e.message || 'Sync de Kite pendiente de implementación.', { error: true });
  } finally {
    btn.disabled = false;
    btn.innerHTML = html;
  }
}

async function abrirConsultarMsim(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:820px">
      <div class="modal-header">
        <div class="modal-title">SIM Movistar <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMsim(id); }
  });

  try {
    const r = await apiGet(`api/movistarsims.php?id=${id}`);
    const card = (label, val, extra = '') => `
      <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px${extra ? ';' + extra : ''}">
        <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">${label}</div>
        <div style="font-family:monospace">${val}</div>
      </div>
    `;
    const est   = r.estado ? msimFmtEstado(r.estado)      : '—';
    const gprs  = r.estado_gprs ? msimFmtEstado(r.estado_gprs) : '—';
    const lte   = r.estado_lte  ? msimFmtEstado(r.estado_lte)  : '—';
    const sync  = r.actualizado ? String(r.actualizado).replace('T', ' ').slice(0, 19) : '—';
    $('#modalRoot .modal-body').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        ${card('Código',        `#${esc(r.id)}`)}
        ${card('Nombre',        esc(r.nombre || '—'))}
        ${card('Línea',         esc(r.linea  || '—'))}
        ${card('ICC',           esc(r.icc    || '—'))}
        ${card('Estado',        est)}
        ${card('Estado GPRS',   gprs)}
        ${card('Estado LTE',    lte)}
        ${card('Límite datos',  esc(r.limite_datos || '—'))}
        ${card('IMEI',          esc(r.imei   || '—'))}
        ${card('MSISDN',        esc(r.msisdn || '—'))}
        ${card('Última sync',   esc(sync), 'grid-column:1 / -1')}
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

async function abrirAltaEdicionMsim(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal" style="max-width:720px">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar SIM <span class="modal-subtitle">#${id}</span>` : 'Nueva SIM'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMsimHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const r = await apiGet(`api/movistarsims.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMsimHtml(r);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMsim(id, a);
  });
}

function formMsimHtml(r) {
  const v = (k) => esc(r?.[k] ?? '');
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre <span style="color:var(--muted);font-weight:400">(field1)</span></label>
        <input type="text" id="msimNombre" maxlength="255" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Línea</label>
        <input type="text" id="msimLinea" maxlength="30" value="${v('linea')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>ICC</label>
        <input type="text" id="msimIcc" maxlength="25" value="${v('icc')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>MSISDN</label>
        <input type="text" id="msimMsisdn" maxlength="30" value="${v('msisdn')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Estado</label>
        <input type="text" id="msimEstado" maxlength="40" value="${v('estado')}">
      </div>
      <div class="form-group">
        <label>Estado GPRS</label>
        <input type="text" id="msimEstadoGprs" maxlength="40" value="${v('estado_gprs')}">
      </div>
      <div class="form-group">
        <label>Estado LTE</label>
        <input type="text" id="msimEstadoLte" maxlength="40" value="${v('estado_lte')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Límite datos</label>
        <input type="text" id="msimLimiteDatos" maxlength="40" value="${v('limite_datos')}">
      </div>
      <div class="form-group">
        <label>Número IMEI</label>
        <input type="text" id="msimImei" maxlength="30" value="${v('imei')}" style="font-family:monospace">
      </div>
    </div>
    <div class="field-error" id="msimFormError" style="display:none"></div>
  `;
}

async function guardarMsim(id, btn) {
  const err = $('#msimFormError');
  err.style.display = 'none';

  const payload = {
    nombre:       $('#msimNombre').value.trim()       || null,
    linea:        $('#msimLinea').value.trim()        || null,
    icc:          $('#msimIcc').value.trim()          || null,
    estado:       $('#msimEstado').value.trim()       || null,
    estado_gprs:  $('#msimEstadoGprs').value.trim()   || null,
    estado_lte:   $('#msimEstadoLte').value.trim()    || null,
    limite_datos: $('#msimLimiteDatos').value.trim()  || null,
    imei:         $('#msimImei').value.trim()         || null,
    msisdn:       $('#msimMsisdn').value.trim()       || null,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/movistarsims.php', 'POST', payload);
      toast('SIM creada.');
    } else {
      await apiSend(`api/movistarsims.php?id=${id}`, 'PUT', payload);
      toast('SIM actualizada.');
    }
    closeModal();
    cargarMsim();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMsim(id) {
  const ok = await confirmar({
    title: 'Eliminar SIM',
    message: `Se eliminará la SIM #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/movistarsims.php?id=${id}`, 'DELETE');
    toast('SIM eliminada.');
    cargarMsim();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Claro (landing) -------------------------
route('/claro', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Claro</div>
      <div class="page-subtitle">Autogestión Empresas de Claro: gestión de SIMs M2M y consola de la plataforma.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/clarosims'">
        <span class="tile-icon">📶</span>
        <span class="tile-title">SIMs</span>
        <span class="tile-desc">Catálogo de SIMs M2M administradas vía Autogestión Empresas: línea, ICC, estado, IMEI, MSISDN.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://autogestion-empresas.claro.com.ar/sites/launchpad#Shell-home', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre la consola de Autogestión Empresas de Claro en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'Claro');

// ------------------------- Vista: OpenAI (landing) -------------------------
function openaiFmtMoneda(v, moneda) {
  if (v == null || isNaN(Number(v))) return '—';
  const cur = String(moneda || 'usd').toUpperCase();
  try {
    return Number(v).toLocaleString('es-AR', {
      style: 'currency', currency: cur, minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
  } catch {
    return `${cur} ${Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
}

function openaiFmtEntero(v) {
  if (v == null || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR');
}

function openaiFmtFecha(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('es-AR', { day: '2-digit', month: 'short', year: 'numeric' });
}

function openaiFmtFechaHora(iso) {
  if (!iso) return '—';
  const d = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleString('es-AR', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
}

function openaiPintarStats(snapshot) {
  const kpis = (snapshot && snapshot.kpis) || {};
  const moneda = (snapshot && snapshot.moneda) || 'usd';
  document.getElementById('openaiCostoActual').textContent   = openaiFmtMoneda(kpis.costo_mes_actual,   moneda);
  document.getElementById('openaiCostoAnterior').textContent = openaiFmtMoneda(kpis.costo_mes_anterior, moneda);
  document.getElementById('openaiTokensMes').textContent     = openaiFmtEntero(kpis.tokens_mes);
  document.getElementById('openaiRequestsMes').textContent   = openaiFmtEntero(kpis.requests_mes);
}

function openaiPintarTabla(snapshot) {
  const tbody = document.getElementById('openaiApikeysBody');
  if (!tbody) return;
  const items  = (snapshot && snapshot.apikeys) || [];
  const moneda = (snapshot && snapshot.moneda)  || 'usd';
  if (items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">Sin API keys.</td></tr>`;
    return;
  }
  tbody.innerHTML = items.map((k) => {
    const aviso = k.tiene_modelos_sin_precio
      ? ` <span title="La key usó modelos sin precio en la tabla interna. El spend estimado es parcial." style="color:var(--warn,#d97706);cursor:help">⚠️</span>`
      : '';
    return `
      <tr>
        <td style="font-weight:600">${esc(k.name || '—')}</td>
        <td><code style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem">${esc(k.id)}</code></td>
        <td>${esc(k.project_name || '—')}</td>
        <td>${openaiFmtFecha(k.created_at)}</td>
        <td>${openaiFmtFecha(k.last_used)}</td>
        <td style="text-align:right">${openaiFmtEntero(k.tokens_total)}</td>
        <td style="text-align:right">${openaiFmtEntero(k.requests)}</td>
        <td style="text-align:right;font-weight:600">${openaiFmtMoneda(k.spend_estimado, moneda)}${aviso}</td>
      </tr>
    `;
  }).join('');
}

function openaiPintarActualizado(fecha, edadSeg) {
  const el = document.getElementById('openaiActualizado');
  if (!el) return;
  if (!fecha) {
    el.innerHTML = `<span style="color:var(--muted)">Sin datos guardados — pulsá <strong>Refrescar</strong> para tomar el primer snapshot.</span>`;
    return;
  }
  const detalle = openaiFmtFechaHora(fecha);
  let hace = '';
  if (typeof edadSeg === 'number' && !isNaN(edadSeg)) {
    if (edadSeg < 60)       hace = `hace ${edadSeg}s`;
    else if (edadSeg < 3600) hace = `hace ${Math.floor(edadSeg/60)} min`;
    else if (edadSeg < 86400) hace = `hace ${Math.floor(edadSeg/3600)} h`;
    else                     hace = `hace ${Math.floor(edadSeg/86400)} d`;
  }
  el.innerHTML = `<span style="color:var(--muted)">Actualizado ${esc(hace)} — ${esc(detalle)}</span>`;
}

// Abre un modal con la lista de fechas de todos los snapshots guardados.
// Al elegir uno se recarga la vista con ese snapshot puntual (mismo re-render
// que usa openaiRefrescar, sin golpear la API de OpenAI).
async function openaiAbrirHistorial() {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Snapshots guardados</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div style="text-align:center;padding:40px"><div class="spin"></div></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-act="close">Cerrar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]')) closeModal();
    const b = ev.target.closest('[data-snap-id]');
    if (b) {
      const id = Number(b.dataset.snapId);
      closeModal();
      openaiCargarSnapshot(id);
    }
  });

  try {
    const items = await apiGet('api/openai_consumos.php?historial=1');
    const body  = $('#modalRoot .modal-body');
    if (!items.length) {
      body.innerHTML = `<div class="table-empty">No hay snapshots guardados todavía.</div>`;
      return;
    }
    // Tabla simple: fecha grande + edad relativa, fila entera clickeable.
    body.innerHTML = `
      <div class="table-card" style="margin:0">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Fecha</th>
              <th style="text-align:right">Hace</th>
            </tr>
          </thead>
          <tbody>
            ${items.map((s) => `
              <tr class="row-clickable" data-snap-id="${s.id}">
                <td class="td-id">#${esc(s.id)}</td>
                <td style="font-family:monospace">${esc(fmtFechaLarga(s.fecha))}</td>
                <td style="text-align:right;color:var(--muted)">${esc(fmtHace(s.fecha))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML =
      `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

// Carga un snapshot puntual (por id) y re-pinta stats + tabla + label, igual
// que openaiRefrescar pero sin POST.
async function openaiCargarSnapshot(id) {
  try {
    const data = await apiGet('api/openai_consumos.php?id=' + encodeURIComponent(id));
    if (data.snapshot) {
      openaiPintarStats(data.snapshot);
      openaiPintarTabla(data.snapshot);
    } else {
      document.getElementById('openaiApikeysBody').innerHTML =
        `<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">El snapshot #${esc(id)} no tiene datos.</td></tr>`;
    }
    openaiPintarActualizado(data.fecha, data.edad_seg);
    toast(`Snapshot #${id} cargado.`);
  } catch (e) {
    toast('OpenAI: ' + (e.message || 'no se pudo cargar el snapshot'), { error: true });
  }
}

async function openaiRefrescar() {
  const btn = document.getElementById('openaiRefrescarBtn');
  if (!btn) return;
  btn.disabled = true;
  const labelOriginal = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-rotate fa-spin"></i> Refrescando…';
  try {
    const r = await fetch('api/openai_consumos.php', { method: 'POST', credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta no JSON' }));
    if (r.status === 429) {
      toast(j.error || 'Snapshot muy reciente, esperá un momento.', { error: true });
      return;
    }
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    openaiPintarStats(j.data.snapshot);
    openaiPintarTabla(j.data.snapshot);
    openaiPintarActualizado(j.data.fecha, j.data.edad_seg);
    toast('Snapshot actualizado.');
  } catch (e) {
    toast('OpenAI refresh: ' + (e.message || 'falló'), { error: true });
  } finally {
    btn.disabled = false;
    btn.innerHTML = labelOriginal;
  }
}

route('/openai', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">OpenAI</div>
      <div class="page-subtitle">Plataforma de OpenAI: consola de administración de la cuenta.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card" onclick="location.hash='#/openaiconsumos'">
        <span class="tile-icon">📊</span>
        <span class="tile-title">Consumos</span>
        <span class="tile-desc">Snapshots del estado de cuenta: costos, tokens, requests y consumo por API key. Con selector de histórico.</span>
      </button>
      <button type="button" class="tile-card"
              onclick="window.open('https://platform.openai.com', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre la consola de OpenAI en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'OpenAI');

// ------------------------- Vista: OpenAI > Consumos -------------------------
route('/openaiconsumos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a OpenAI" onclick="location.hash='#/openai'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📊</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Snapshots del estado de cuenta OpenAI: costos del mes en curso y anterior, tokens, requests
          y consumo por API key. Cada snapshot queda guardado en <code>openai_consumos</code>;
          desde <strong>Snapshots</strong> podés navegar a versiones históricas.
          El <em>spend estimado</em> por key surge de tokens × tabla de precios interna — puede diferir
          del gasto oficial (no incluye descuentos batch, cached-input reducido ni modelos sin precio).
        </div>
      </div>

      <div class="toolbar" style="margin-bottom:14px">
        <div class="toolbar-left" style="gap:12px;align-items:center;flex-wrap:wrap">
          <button class="btn btn-primary" id="openaiRefrescarBtn">
            <i class="fa-solid fa-rotate"></i> Refrescar
          </button>
          <button class="btn btn-ghost" id="openaiHistorialBtn" title="Ver snapshots guardados">
            <i class="fa-solid fa-clock-rotate-left"></i> Snapshots
          </button>
          <div id="openaiActualizado" style="font-size:.85rem">
            <span style="color:var(--muted)">Cargando…</span>
          </div>
        </div>
      </div>

      <div class="stats-bar" id="openaiStats">
        <div class="stat-card"><span class="stat-label">Costo del mes en curso</span><span class="stat-value orange" id="openaiCostoActual">—</span></div>
        <div class="stat-card"><span class="stat-label">Costo del mes anterior</span><span class="stat-value" id="openaiCostoAnterior">—</span></div>
        <div class="stat-card"><span class="stat-label">Tokens usados (mes)</span><span class="stat-value blue" id="openaiTokensMes">—</span></div>
        <div class="stat-card"><span class="stat-label">Requests (mes)</span><span class="stat-value blue" id="openaiRequestsMes">—</span></div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Tracking ID</th>
              <th>Proyecto</th>
              <th>Creada</th>
              <th>Last used</th>
              <th style="text-align:right">Tokens</th>
              <th style="text-align:right">Requests</th>
              <th style="text-align:right">Spend (est.)</th>
            </tr>
          </thead>
          <tbody id="openaiApikeysBody">
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">Cargando…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  `;

  document.getElementById('openaiRefrescarBtn').onclick = openaiRefrescar;
  document.getElementById('openaiHistorialBtn').onclick = openaiAbrirHistorial;

  try {
    const data = await apiGet('api/openai_consumos.php');
    if (data.snapshot) {
      openaiPintarStats(data.snapshot);
      openaiPintarTabla(data.snapshot);
    } else {
      document.getElementById('openaiApikeysBody').innerHTML =
        `<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">Sin snapshot guardado. Pulsá Refrescar para tomar el primero.</td></tr>`;
    }
    openaiPintarActualizado(data.fecha, data.edad_seg);
  } catch (e) {
    toast('OpenAI: ' + (e.message || 'no se pudo cargar el snapshot'), { error: true });
    document.getElementById('openaiActualizado').innerHTML =
      `<span style="color:var(--danger)">${esc(e.message || 'Error cargando')}</span>`;
  }
}, 'OpenAI · Consumos');

// ------------------------- Vista: Anthropic (landing) -------------------------
route('/anthropic', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Anthropic</div>
      <div class="page-subtitle">Plataforma de Anthropic: consola de administración de la cuenta.</div>
    </div>

    <div class="tile-grid">
      <button type="button" class="tile-card"
              onclick="window.open('https://platform.claude.com', '_blank', 'noopener')">
        <span class="tile-icon">🌐</span>
        <span class="tile-title">Plataforma</span>
        <span class="tile-desc">Abre la consola de Anthropic en una pestaña nueva.</span>
      </button>
    </div>
  `;
}, 'Anthropic');

// ------------------------- Vista: Claro > SIMs (ABM) -------------------------
const csimFiltrosDefaults = {
  q: '', codigo: '', estado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const csimFiltros = { ...csimFiltrosDefaults };
let csimBuscadorTimer   = null;
let csimFiltrosSnapshot = null;

function csimFmtEstado(v) {
  if (v == null || v === '') return `<span class="badge badge-info">—</span>`;
  const s   = String(v).toLowerCase();
  const map = {
    activada: 'badge-success', activa: 'badge-success', active: 'badge-success',
    suspendida: 'badge-warn',  suspended: 'badge-warn',
    baja: 'badge-danger',      terminada: 'badge-danger', terminated: 'badge-danger',
    inventario: 'badge-info',  inventory: 'badge-info',
  };
  const cls = map[s] || 'badge-info';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}

route('/clarosims', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Claro" onclick="location.hash='#/claro'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📶</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las SIMs de Claro son las líneas M2M administradas desde
          Autogestión Empresas — cada fila trae el nombre, la línea,
          el ICC, el estado general/GPRS/LTE, el límite de datos, el IMEI
          del equipo asociado y el MSISDN.
        </div>
      </div>

      <div class="stats-bar" id="csimStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value" data-slot="total">—</span></div>
        <div class="stat-card"><span class="stat-label">Activas</span><span class="stat-value" data-slot="activas">—</span></div>
        <div class="stat-card"><span class="stat-label">Sin estado</span><span class="stat-value" data-slot="sin_estado">—</span></div>
        <div class="stat-card"><span class="stat-label">Última sync</span><span class="stat-value" data-slot="ultima_sync" style="font-size:1rem">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="csimSearch"
                   placeholder="🔍 Buscar nombre, línea, ICC, IMEI o MSISDN…">
            <button class="search-clear" id="csimSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="csimFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="csimFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="csimRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right" style="gap:8px">
          <button class="btn btn-primary" id="csimNuevoBtn">+ Nueva SIM</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:90px">Código</th>
              <th>Nombre</th>
              <th>Línea</th>
              <th style="width:180px">ICC</th>
              <th style="width:120px">Estado</th>
              <th style="width:130px">Límite datos</th>
              <th>MSISDN</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="csimTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="csimCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosCsimBackdrop"
         onclick="if(event.target===this)cancelarFiltrosCsim()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosCsim()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fCsimCodigo" min="1" placeholder="ID …" oninput="onFiltroCsim('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <input type="text" id="fCsimEstado" placeholder="Ej: Activada" oninput="onFiltroCsim('estado', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fCsimLimite" min="1" max="2000" value="100" onchange="onFiltroCsim('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fCsimOrderBy" onchange="onFiltroCsim('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="linea">Línea</option>
                <option value="icc">ICC</option>
                <option value="estado">Estado</option>
                <option value="msisdn">MSISDN</option>
                <option value="actualizado">Última sync</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fCsimDir" onchange="onFiltroCsim('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosCsim()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosCsim()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosCsim()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#csimNuevoBtn').addEventListener('click',    () => abrirAltaEdicionCsim(null));
  $('#csimFiltrosBtn').addEventListener('click',  () => abrirModalFiltrosCsim());
  $('#csimRefrescarBtn').addEventListener('click',() => cargarCsim());

  const inp = $('#csimSearch');
  const clr = $('#csimSearchClear');
  inp.value = csimFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    csimFiltros.q = inp.value.trim();
    clearTimeout(csimBuscadorTimer);
    csimBuscadorTimer = setTimeout(() => { cargarCsim(); refrescarBadgeFiltrosCsim(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    csimFiltros.q = '';
    cargarCsim();
    refrescarBadgeFiltrosCsim();
  });

  $('#csimCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarCsim(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionCsim(data.id);
    if (b.dataset.action === 'eliminar')  eliminarCsim(data.id);
  });

  $('#csimTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#csimCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarCsim(Number(tr.dataset.id));
  });
  $('#csimTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#csimCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosCsim();
  await cargarCsim();
}, 'SIMs');

async function cargarCsim() {
  const tbody = $('#csimTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(csimFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });

  try {
    const data = await apiGet('api/clarosims.php?' + qs.toString());
    pintarStatsCsim(data.stats);
    pintarTablaCsim(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsCsim(s) {
  const setSlot = (name, val) => {
    const el = document.querySelector(`#csimStats [data-slot="${name}"]`);
    if (el) el.textContent = val;
  };
  setSlot('total',      fmtNum(s?.total      ?? 0));
  setSlot('activas',    fmtNum(s?.activas    ?? 0));
  setSlot('sin_estado', fmtNum(s?.sin_estado ?? 0));
  setSlot('ultima_sync', s?.ultima_sync ? String(s.ultima_sync).replace('T', ' ').slice(0, 16) : '—');
}

function pintarTablaCsim(rows) {
  const tbody = $('#csimTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin SIMs.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td>${esc(r.nombre || '—')}</td>
      <td style="font-family:monospace">${esc(r.linea || '—')}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(r.icc || '—')}</td>
      <td>${csimFmtEstado(r.estado)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(r.limite_datos || '—')}</td>
      <td style="font-family:monospace">${esc(r.msisdn || '—')}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroCsim(key, value) {
  if (['order_by', 'dir', 'estado'].includes(key)) {
    csimFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    csimFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 2000) n = 2000;
    csimFiltros.limite = n;
  } else {
    csimFiltros[key] = value;
  }
  refrescarBadgeFiltrosCsim();
  cargarCsim();
}

function refrescarBadgeFiltrosCsim() {
  const btn   = $('#csimFiltrosBtn');
  const badge = $('#csimFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(csimFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(csimFiltros[k]) !== String(csimFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosCsim() {
  const f = csimFiltros;
  $('#fCsimCodigo').value  = f.codigo;
  $('#fCsimEstado').value  = f.estado;
  $('#fCsimLimite').value  = f.limite;
  $('#fCsimOrderBy').value = f.order_by;
  $('#fCsimDir').value     = f.dir;
}

function abrirModalFiltrosCsim() {
  csimFiltrosSnapshot = { ...csimFiltros };
  sincronizarControlesFiltrosCsim();
  $('#filtrosCsimBackdrop').classList.add('open');
}
function cerrarModalFiltrosCsim() { $('#filtrosCsimBackdrop').classList.remove('open'); }
function cancelarFiltrosCsim() {
  if (csimFiltrosSnapshot) {
    Object.assign(csimFiltros, csimFiltrosSnapshot);
    refrescarBadgeFiltrosCsim();
    cargarCsim();
  }
  cerrarModalFiltrosCsim();
}
function limpiarFiltrosCsim() {
  Object.assign(csimFiltros, csimFiltrosDefaults);
  csimFiltros.q = $('#csimSearch')?.value.trim() || '';
  sincronizarControlesFiltrosCsim();
  refrescarBadgeFiltrosCsim();
  cargarCsim();
}
window.onFiltroCsim           = onFiltroCsim;
window.cancelarFiltrosCsim    = cancelarFiltrosCsim;
window.limpiarFiltrosCsim     = limpiarFiltrosCsim;
window.cerrarModalFiltrosCsim = cerrarModalFiltrosCsim;

async function abrirConsultarCsim(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:820px">
      <div class="modal-header">
        <div class="modal-title">SIM Claro <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionCsim(id); }
  });

  try {
    const r = await apiGet(`api/clarosims.php?id=${id}`);
    const card = (label, val, extra = '') => `
      <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px${extra ? ';' + extra : ''}">
        <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">${label}</div>
        <div style="font-family:monospace">${val}</div>
      </div>
    `;
    const est   = r.estado ? csimFmtEstado(r.estado)      : '—';
    const gprs  = r.estado_gprs ? csimFmtEstado(r.estado_gprs) : '—';
    const lte   = r.estado_lte  ? csimFmtEstado(r.estado_lte)  : '—';
    const sync  = r.actualizado ? String(r.actualizado).replace('T', ' ').slice(0, 19) : '—';
    $('#modalRoot .modal-body').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        ${card('Código',        `#${esc(r.id)}`)}
        ${card('Nombre',        esc(r.nombre || '—'))}
        ${card('Línea',         esc(r.linea  || '—'))}
        ${card('ICC',           esc(r.icc    || '—'))}
        ${card('Estado',        est)}
        ${card('Estado GPRS',   gprs)}
        ${card('Estado LTE',    lte)}
        ${card('Límite datos',  esc(r.limite_datos || '—'))}
        ${card('IMEI',          esc(r.imei   || '—'))}
        ${card('MSISDN',        esc(r.msisdn || '—'))}
        ${card('Última sync',   esc(sync), 'grid-column:1 / -1')}
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

async function abrirAltaEdicionCsim(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal" style="max-width:720px">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar SIM <span class="modal-subtitle">#${id}</span>` : 'Nueva SIM'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formCsimHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const r = await apiGet(`api/clarosims.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formCsimHtml(r);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarCsim(id, a);
  });
}

function formCsimHtml(r) {
  const v = (k) => esc(r?.[k] ?? '');
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Nombre</label>
        <input type="text" id="csimNombre" maxlength="255" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Línea</label>
        <input type="text" id="csimLinea" maxlength="30" value="${v('linea')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>ICC</label>
        <input type="text" id="csimIcc" maxlength="25" value="${v('icc')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>MSISDN</label>
        <input type="text" id="csimMsisdn" maxlength="30" value="${v('msisdn')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Estado</label>
        <input type="text" id="csimEstado" maxlength="40" value="${v('estado')}">
      </div>
      <div class="form-group">
        <label>Estado GPRS</label>
        <input type="text" id="csimEstadoGprs" maxlength="40" value="${v('estado_gprs')}">
      </div>
      <div class="form-group">
        <label>Estado LTE</label>
        <input type="text" id="csimEstadoLte" maxlength="40" value="${v('estado_lte')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Límite datos</label>
        <input type="text" id="csimLimiteDatos" maxlength="40" value="${v('limite_datos')}">
      </div>
      <div class="form-group">
        <label>Número IMEI</label>
        <input type="text" id="csimImei" maxlength="30" value="${v('imei')}" style="font-family:monospace">
      </div>
    </div>
    <div class="field-error" id="csimFormError" style="display:none"></div>
  `;
}

async function guardarCsim(id, btn) {
  const err = $('#csimFormError');
  err.style.display = 'none';

  const payload = {
    nombre:       $('#csimNombre').value.trim()       || null,
    linea:        $('#csimLinea').value.trim()        || null,
    icc:          $('#csimIcc').value.trim()          || null,
    estado:       $('#csimEstado').value.trim()       || null,
    estado_gprs:  $('#csimEstadoGprs').value.trim()   || null,
    estado_lte:   $('#csimEstadoLte').value.trim()    || null,
    limite_datos: $('#csimLimiteDatos').value.trim()  || null,
    imei:         $('#csimImei').value.trim()         || null,
    msisdn:       $('#csimMsisdn').value.trim()       || null,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/clarosims.php', 'POST', payload);
      toast('SIM creada.');
    } else {
      await apiSend(`api/clarosims.php?id=${id}`, 'PUT', payload);
      toast('SIM actualizada.');
    }
    closeModal();
    cargarCsim();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarCsim(id) {
  const ok = await confirmar({
    title: 'Eliminar SIM',
    message: `Se eliminará la SIM #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/clarosims.php?id=${id}`, 'DELETE');
    toast('SIM eliminada.');
    cargarCsim();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Dolarhoy > Cotizaciones (ABM) -------------------------
const dhCotFiltrosDefaults = {
  q: '', codigo: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const dhCotFiltros = { ...dhCotFiltrosDefaults };
let dhCotBuscadorTimer   = null;
let dhCotFiltrosSnapshot = null;

function dhCotFmtDec(v) {
  if (v == null || v === '' || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

route('/dolarhoycotizaciones', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Dolarhoy" onclick="location.hash='#/dolarhoy'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">💵</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las cotizaciones de Dolarhoy son el registro histórico del tipo de cambio
          del dólar tomado de la plataforma — cada fila trae la fecha del día con el
          precio de compra y el precio de venta publicados.
        </div>
      </div>

      <div class="stats-bar" id="dhCotStats">
        <div class="stat-card">
          <span class="stat-label">Compra oficial <span style="font-size:.65rem;color:var(--muted);font-weight:500">realtime</span></span>
          <span class="stat-value" data-slot="compra">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Venta oficial <span style="font-size:.65rem;color:var(--muted);font-weight:500">realtime</span></span>
          <span class="stat-value" data-slot="venta">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Total registros</span>
          <span class="stat-value" data-slot="total">—</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Actualizado</span>
          <span class="stat-value" data-slot="actualizado" style="font-size:1rem">—</span>
        </div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="dhCotSearch"
                   placeholder="🔍 Buscar fecha o valor…">
            <button class="search-clear" id="dhCotSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="dhCotFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="dhCotFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="dhCotRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="dhCotNuevoBtn">+ Nueva cotización</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:90px">Código</th>
              <th style="width:160px">Fecha</th>
              <th style="text-align:right">Compra</th>
              <th style="text-align:right">Venta</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="dhCotTbody">
            <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="dhCotCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosDhCotBackdrop"
         onclick="if(event.target===this)cancelarFiltrosDhCot()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosDhCot()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fDhCotCodigo" min="1" placeholder="ID …" oninput="onFiltroDhCot('codigo', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fDhCotDesde" onchange="onFiltroDhCot('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fDhCotHasta" onchange="onFiltroDhCot('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fDhCotLimite" min="1" max="2000" value="100" onchange="onFiltroDhCot('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fDhCotOrderBy" onchange="onFiltroDhCot('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="compra">Compra</option>
                <option value="venta">Venta</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fDhCotDir" onchange="onFiltroDhCot('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosDhCot()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosDhCot()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosDhCot()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#dhCotNuevoBtn').addEventListener('click', () => abrirAltaEdicionDhCot(null));
  $('#dhCotFiltrosBtn').addEventListener('click', () => abrirModalFiltrosDhCot());
  $('#dhCotRefrescarBtn').addEventListener('click', () => cargarDhCot());

  const inp = $('#dhCotSearch');
  const clr = $('#dhCotSearchClear');
  inp.value = dhCotFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    dhCotFiltros.q = inp.value.trim();
    clearTimeout(dhCotBuscadorTimer);
    dhCotBuscadorTimer = setTimeout(() => { cargarDhCot(); refrescarBadgeFiltrosDhCot(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    dhCotFiltros.q = '';
    cargarDhCot();
    refrescarBadgeFiltrosDhCot();
  });

  $('#dhCotCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarDhCot(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionDhCot(data.id);
    if (b.dataset.action === 'eliminar')  eliminarDhCot(data.id);
  });

  $('#dhCotTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#dhCotCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarDhCot(Number(tr.dataset.id));
  });
  $('#dhCotTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#dhCotCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosDhCot();
  await cargarDhCot();
}, 'Cotizaciones');

async function cargarDhCot() {
  const tbody = $('#dhCotTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(dhCotFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });

  cargarDhCotRealtime();

  try {
    const data = await apiGet('api/dolarhoycotizaciones.php?' + qs.toString());
    pintarTotalDhCot(data.stats);
    pintarTablaDhCot(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

async function cargarDhCotRealtime() {
  const setSlot = (name, val) => {
    const el = document.querySelector(`#dhCotStats [data-slot="${name}"]`);
    if (el) el.textContent = val;
  };
  setSlot('compra', '…');
  setSlot('venta',  '…');
  setSlot('actualizado', 'consultando…');
  try {
    const r = await apiGet('api/dolarhoy_realtime.php');
    setSlot('compra', r?.compra != null ? '$ ' + dhCotFmtDec(r.compra) : '—');
    setSlot('venta',  r?.venta  != null ? '$ ' + dhCotFmtDec(r.venta)  : '—');
    const hora = r?.fecha ? String(r.fecha).slice(11, 16) : '—';
    setSlot('actualizado', hora + (r?.cache ? ' (cache)' : ''));
  } catch (e) {
    setSlot('compra', '—');
    setSlot('venta',  '—');
    setSlot('actualizado', 'sin datos');
  }
}

function pintarTotalDhCot(s) {
  const el = document.querySelector('#dhCotStats [data-slot="total"]');
  if (el) el.textContent = fmtNum(s?.total ?? 0);
}

function pintarTablaDhCot(rows) {
  const tbody = $('#dhCotTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Sin cotizaciones.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(r.fecha || '—')}</td>
      <td style="font-family:monospace;text-align:right">${dhCotFmtDec(r.compra)}</td>
      <td style="font-family:monospace;text-align:right">${dhCotFmtDec(r.venta)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroDhCot(key, value) {
  if (['order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    dhCotFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    dhCotFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 2000) n = 2000;
    dhCotFiltros.limite = n;
  } else {
    dhCotFiltros[key] = value;
  }
  refrescarBadgeFiltrosDhCot();
  cargarDhCot();
}

function refrescarBadgeFiltrosDhCot() {
  const btn   = $('#dhCotFiltrosBtn');
  const badge = $('#dhCotFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(dhCotFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(dhCotFiltros[k]) !== String(dhCotFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosDhCot() {
  const f = dhCotFiltros;
  $('#fDhCotCodigo').value  = f.codigo;
  $('#fDhCotDesde').value   = f.desde;
  $('#fDhCotHasta').value   = f.hasta;
  $('#fDhCotLimite').value  = f.limite;
  $('#fDhCotOrderBy').value = f.order_by;
  $('#fDhCotDir').value     = f.dir;
}

function abrirModalFiltrosDhCot() {
  dhCotFiltrosSnapshot = { ...dhCotFiltros };
  sincronizarControlesFiltrosDhCot();
  $('#filtrosDhCotBackdrop').classList.add('open');
}
function cerrarModalFiltrosDhCot() { $('#filtrosDhCotBackdrop').classList.remove('open'); }
function cancelarFiltrosDhCot() {
  if (dhCotFiltrosSnapshot) {
    Object.assign(dhCotFiltros, dhCotFiltrosSnapshot);
    refrescarBadgeFiltrosDhCot();
    cargarDhCot();
  }
  cerrarModalFiltrosDhCot();
}
function limpiarFiltrosDhCot() {
  Object.assign(dhCotFiltros, dhCotFiltrosDefaults);
  dhCotFiltros.q = $('#dhCotSearch')?.value.trim() || '';
  sincronizarControlesFiltrosDhCot();
  refrescarBadgeFiltrosDhCot();
  cargarDhCot();
}
window.onFiltroDhCot           = onFiltroDhCot;
window.cancelarFiltrosDhCot    = cancelarFiltrosDhCot;
window.limpiarFiltrosDhCot     = limpiarFiltrosDhCot;
window.cerrarModalFiltrosDhCot = cerrarModalFiltrosDhCot;

async function abrirConsultarDhCot(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:720px">
      <div class="modal-header">
        <div class="modal-title">Cotización Dolarhoy <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionDhCot(id); }
  });

  try {
    const r = await apiGet(`api/dolarhoycotizaciones.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px">
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">Código</div>
          <div style="font-family:monospace">#${esc(r.id)}</div>
        </div>
        <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px">
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">Fecha</div>
          <div style="font-family:monospace">${esc(r.fecha || '—')}</div>
        </div>
        <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px">
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">Compra</div>
          <div style="font-family:monospace">${dhCotFmtDec(r.compra)}</div>
        </div>
        <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px">
          <div style="font-size:.75rem;color:var(--muted);margin-bottom:4px">Venta</div>
          <div style="font-family:monospace">${dhCotFmtDec(r.venta)}</div>
        </div>
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

async function abrirAltaEdicionDhCot(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar cotización <span class="modal-subtitle">#${id}</span>` : 'Nueva cotización'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formDhCotHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const r = await apiGet(`api/dolarhoycotizaciones.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formDhCotHtml(r);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarDhCot(id, a);
  });
}

function formDhCotHtml(r) {
  const v = (k) => esc(r?.[k] ?? '');
  return `
    <div class="form-group">
      <label>Fecha</label>
      <input type="date" id="dhCotFecha" value="${v('fecha')}">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Compra</label>
        <input type="number" id="dhCotCompra" step="0.01" min="0" value="${v('compra')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Venta</label>
        <input type="number" id="dhCotVenta" step="0.01" min="0" value="${v('venta')}" style="font-family:monospace">
      </div>
    </div>
    <div class="field-error" id="dhCotFormError" style="display:none"></div>
  `;
}

async function guardarDhCot(id, btn) {
  const err = $('#dhCotFormError');
  err.style.display = 'none';

  const payload = {
    fecha:  $('#dhCotFecha').value  || null,
    compra: $('#dhCotCompra').value || null,
    venta:  $('#dhCotVenta').value  || null,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/dolarhoycotizaciones.php', 'POST', payload);
      toast('Cotización creada.');
    } else {
      await apiSend(`api/dolarhoycotizaciones.php?id=${id}`, 'PUT', payload);
      toast('Cotización actualizada.');
    }
    closeModal();
    cargarDhCot();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarDhCot(id) {
  const ok = await confirmar({
    title: 'Eliminar cotización',
    message: `Se eliminará la cotización #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/dolarhoycotizaciones.php?id=${id}`, 'DELETE');
    toast('Cotización eliminada.');
    cargarDhCot();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago > Pagos (ABM) -------------------------
const mpPagFiltrosDefaults = {
  q: '', codigo: '', cuenta: '', factura: '', recibo: '',
  estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const mpPagFiltros = { ...mpPagFiltrosDefaults };
let mpPagBuscadorTimer   = null;
let mpPagFiltrosSnapshot = null;

function mpPagEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    A: 'badge-success',
    P: 'badge-warn',
    R: 'badge-danger',
    C: 'badge-danger',
    X: 'badge-danger',
  };
  const labelMap = {
    A: 'Aprobado', P: 'Pendiente', R: 'Rechazado', C: 'Cancelado', X: 'Anulado',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

function mpPagFmtMonto(v) {
  if (v == null || v === '' || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

route('/mercadopagopagos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Mercadopago" onclick="location.hash='#/mercadopago'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">💳</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los pagos de Mercadopago son cada cobro procesado por la pasarela,
          con la cuenta origen, factura o recibo asociado, monto, número de
          operación y el estado devuelto por la plataforma.
        </div>
      </div>

      <div class="stats-bar" id="mpPagStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Finalizados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Monto cobrado</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="mpPagSearch"
                   placeholder="🔍 Buscar UUID, concepto, operación o retorno…">
            <button class="search-clear" id="mpPagSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="mpPagFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="mpPagFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="mpPagRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="mpPagNuevoBtn">+ Nuevo pago</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Iniciado</th>
              <th>Cuenta</th>
              <th>Factura</th>
              <th>Concepto</th>
              <th style="text-align:right">Monto</th>
              <th>Operación</th>
              <th>Estado</th>
              <th>Finalizado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="mpPagTbody">
            <tr><td colspan="10" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="mpPagCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMpPagBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMpPag()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMpPag()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMpPagCodigo" min="1" placeholder="ID …" oninput="onFiltroMpPag('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fMpPagEstado" onchange="onFiltroMpPag('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="A">Aprobado</option>
                <option value="P">Pendiente</option>
                <option value="R">Rechazado</option>
                <option value="C">Cancelado</option>
                <option value="X">Anulado</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Cuenta (ID)</label>
              <input type="number" id="fMpPagCuenta" min="1" oninput="onFiltroMpPag('cuenta', this.value)">
            </div>
            <div class="form-group">
              <label>Factura (ID)</label>
              <input type="number" id="fMpPagFactura" min="1" oninput="onFiltroMpPag('factura', this.value)">
            </div>
            <div class="form-group">
              <label>Recibo (ID)</label>
              <input type="number" id="fMpPagRecibo" min="1" oninput="onFiltroMpPag('recibo', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fMpPagDesde" onchange="onFiltroMpPag('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fMpPagHasta" onchange="onFiltroMpPag('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMpPagLimite" min="1" max="1000" value="100" onchange="onFiltroMpPag('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMpPagOrderBy" onchange="onFiltroMpPag('order_by', this.value)">
                <option value="id">Código</option>
                <option value="iniciado">Iniciado</option>
                <option value="finalizado">Finalizado</option>
                <option value="cuenta">Cuenta</option>
                <option value="factura">Factura</option>
                <option value="recibo">Recibo</option>
                <option value="concepto">Concepto</option>
                <option value="monto">Monto</option>
                <option value="operacion">Operación</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fMpPagDir" onchange="onFiltroMpPag('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMpPag()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMpPag()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMpPag()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#mpPagNuevoBtn').addEventListener('click', () => abrirAltaEdicionMpPag(null));
  $('#mpPagFiltrosBtn').addEventListener('click', () => abrirModalFiltrosMpPag());
  $('#mpPagRefrescarBtn').addEventListener('click', () => cargarMpPag());

  const inp = $('#mpPagSearch');
  const clr = $('#mpPagSearchClear');
  inp.value = mpPagFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    mpPagFiltros.q = inp.value.trim();
    clearTimeout(mpPagBuscadorTimer);
    mpPagBuscadorTimer = setTimeout(() => { cargarMpPag(); refrescarBadgeFiltrosMpPag(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    mpPagFiltros.q = '';
    cargarMpPag();
    refrescarBadgeFiltrosMpPag();
  });

  $('#mpPagCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMpPag(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMpPag(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMpPag(data.id);
  });

  $('#mpPagTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#mpPagCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMpPag(Number(tr.dataset.id));
  });
  $('#mpPagTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#mpPagCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMpPag();
  await cargarMpPag();
}, 'Pagos');

async function cargarMpPag() {
  const tbody = $('#mpPagTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(mpPagFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/mercadopagopagos.php?' + qs.toString());
    pintarStatsMpPag(data.stats);
    pintarTablaMpPag(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="10" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMpPag(s) {
  const cards = $$('#mpPagStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.finalizados);
  cards[2].textContent = mpPagFmtMonto(s.monto_total);
}

function pintarTablaMpPag(rows) {
  const tbody = $('#mpPagTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="10" class="table-empty">Sin pagos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((p) => `
    <tr data-id="${p.id}" class="row-clickable">
      <td class="td-id">#${esc(p.id)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(p.iniciado))}</td>
      <td>${p.cuenta != null ? '#' + esc(p.cuenta) : '—'}</td>
      <td>${p.factura != null ? '#' + esc(p.factura) : '—'}</td>
      <td>${esc(p.concepto || '—')}</td>
      <td style="text-align:right;font-family:monospace">${mpPagFmtMonto(p.monto)}</td>
      <td style="font-family:monospace">${esc(p.operacion || '—')}</td>
      <td>${mpPagEstadoBadge(p.estado)}</td>
      <td style="font-family:monospace">${esc(fmtFechaLarga(p.finalizado))}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${p.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroMpPag(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    mpPagFiltros[key] = value;
  } else if (['codigo', 'cuenta', 'factura', 'recibo'].includes(key)) {
    const v = String(value).trim();
    mpPagFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    mpPagFiltros.limite = n;
  } else {
    mpPagFiltros[key] = value;
  }
  refrescarBadgeFiltrosMpPag();
  cargarMpPag();
}

function refrescarBadgeFiltrosMpPag() {
  const btn   = $('#mpPagFiltrosBtn');
  const badge = $('#mpPagFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(mpPagFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(mpPagFiltros[k]) !== String(mpPagFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMpPag() {
  const f = mpPagFiltros;
  $('#fMpPagCodigo').value  = f.codigo;
  $('#fMpPagEstado').value  = f.estado;
  $('#fMpPagCuenta').value  = f.cuenta;
  $('#fMpPagFactura').value = f.factura;
  $('#fMpPagRecibo').value  = f.recibo;
  $('#fMpPagDesde').value   = f.desde;
  $('#fMpPagHasta').value   = f.hasta;
  $('#fMpPagLimite').value  = f.limite;
  $('#fMpPagOrderBy').value = f.order_by;
  $('#fMpPagDir').value     = f.dir;
}

function abrirModalFiltrosMpPag() {
  mpPagFiltrosSnapshot = { ...mpPagFiltros };
  sincronizarControlesFiltrosMpPag();
  $('#filtrosMpPagBackdrop').classList.add('open');
}
function cerrarModalFiltrosMpPag() { $('#filtrosMpPagBackdrop').classList.remove('open'); }
function cancelarFiltrosMpPag() {
  if (mpPagFiltrosSnapshot) {
    Object.assign(mpPagFiltros, mpPagFiltrosSnapshot);
    refrescarBadgeFiltrosMpPag();
    cargarMpPag();
  }
  cerrarModalFiltrosMpPag();
}
function limpiarFiltrosMpPag() {
  Object.assign(mpPagFiltros, mpPagFiltrosDefaults);
  mpPagFiltros.q = $('#mpPagSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMpPag();
  refrescarBadgeFiltrosMpPag();
  cargarMpPag();
}
window.onFiltroMpPag           = onFiltroMpPag;
window.cancelarFiltrosMpPag    = cancelarFiltrosMpPag;
window.limpiarFiltrosMpPag     = limpiarFiltrosMpPag;
window.cerrarModalFiltrosMpPag = cerrarModalFiltrosMpPag;

async function abrirConsultarMpPag(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Pago Mercadopago <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMpPag(id); }
  });

  try {
    const p = await apiGet(`api/mercadopagopagos.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaMpPag(p);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaMpPag(p) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.3rem;font-weight:700">$ ${mpPagFmtMonto(p.monto)}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(p.operacion || '')}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(p.concepto || 'Sin concepto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(p.id)} · <code>${esc(p.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${mpPagEstadoBadge(p.estado)}</div>
        <div style="margin-top:6px;font-size:.85rem;line-height:1.5">
          <div><span style="color:var(--muted)">Iniciado:</span> ${esc(fmtFecha(p.iniciado))}</div>
          <div><span style="color:var(--muted)">Finalizado:</span> ${esc(fmtFecha(p.finalizado))}</div>
        </div>
      </div>
    </div>

    ${seccion('Identificación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Código',   p.id)}
      ${card('UUID',     p.uuid, false, true)}
      ${card('Cuenta',   p.cuenta)}
      ${card('Factura',  p.factura)}
      ${card('Recibo',   p.recibo)}
      ${card('Operación', p.operacion, false, true)}
    </dl>

    ${seccion('Importe y estado')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Monto',      mpPagFmtMonto(p.monto))}
      ${card('Estado',     p.estado)}
      ${card('Concepto',   p.concepto)}
      ${card('Iniciado',   fmtFecha(p.iniciado))}
      ${card('Finalizado', fmtFecha(p.finalizado))}
      ${card('Retorno',    p.retorno)}
    </dl>

    ${seccion('Notificación y propiedades')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Notificación', p.notificacion, true, true)}
      ${card('Propiedades',  p.propiedades,  true, true)}
    </dl>
  `;
}

async function abrirAltaEdicionMpPag(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar pago <span class="modal-subtitle">#${id}</span>` : 'Nuevo pago'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMpPagHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const p = await apiGet(`api/mercadopagopagos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMpPagHtml(p);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMpPag(id, a);
  });
}

function formMpPagHtml(p) {
  const v   = (k) => esc(p?.[k] ?? '');
  const sel = (k, val) => (p?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = p?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>UUID</label>
        <input type="text" id="mpPagUuid" maxlength="50" value="${v('uuid')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Iniciado</label>
        <input type="datetime-local" id="mpPagIniciado" value="${dt('iniciado')}">
      </div>
      <div class="form-group">
        <label>Finalizado</label>
        <input type="datetime-local" id="mpPagFinalizado" value="${dt('finalizado')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Cuenta (ID)</label>
        <input type="number" id="mpPagCuenta" min="1" value="${v('cuenta')}">
      </div>
      <div class="form-group">
        <label>Factura (ID)</label>
        <input type="number" id="mpPagFactura" min="1" value="${v('factura')}">
      </div>
      <div class="form-group">
        <label>Recibo (ID)</label>
        <input type="number" id="mpPagRecibo" min="1" value="${v('recibo')}">
      </div>
    </div>
    <div class="form-group">
      <label>Concepto</label>
      <input type="text" id="mpPagConcepto" maxlength="255" value="${v('concepto')}">
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Monto</label>
        <input type="number" id="mpPagMonto" step="0.01" min="0" value="${v('monto')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Operación</label>
        <input type="text" id="mpPagOperacion" maxlength="255" value="${v('operacion')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="mpPagEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="A" ${sel('estado','A')}>Aprobado</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="R" ${sel('estado','R')}>Rechazado</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="X" ${sel('estado','X')}>Anulado</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Retorno</label>
      <textarea id="mpPagRetorno" rows="2" maxlength="1000" style="font-family:monospace">${v('retorno')}</textarea>
    </div>
    <div class="form-group">
      <label>Notificación</label>
      <textarea id="mpPagNotificacion" rows="4" style="font-family:monospace">${v('notificacion')}</textarea>
    </div>
    <div class="form-group">
      <label>Propiedades</label>
      <textarea id="mpPagPropiedades" rows="4" style="font-family:monospace">${v('propiedades')}</textarea>
    </div>
    <div class="field-error" id="mpPagFormError" style="display:none"></div>
  `;
}

async function guardarMpPag(id, btn) {
  const err = $('#mpPagFormError');
  err.style.display = 'none';

  const payload = {
    uuid:         $('#mpPagUuid').value.trim(),
    iniciado:     $('#mpPagIniciado').value || null,
    finalizado:   $('#mpPagFinalizado').value || null,
    cuenta:       $('#mpPagCuenta').value,
    factura:      $('#mpPagFactura').value,
    recibo:       $('#mpPagRecibo').value,
    concepto:     $('#mpPagConcepto').value.trim(),
    monto:        $('#mpPagMonto').value,
    operacion:    $('#mpPagOperacion').value.trim(),
    estado:       $('#mpPagEstado').value,
    retorno:      $('#mpPagRetorno').value,
    notificacion: $('#mpPagNotificacion').value,
    propiedades:  $('#mpPagPropiedades').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/mercadopagopagos.php', 'POST', payload);
      toast('Pago creado.');
    } else {
      await apiSend(`api/mercadopagopagos.php?id=${id}`, 'PUT', payload);
      toast('Pago actualizado.');
    }
    closeModal();
    cargarMpPag();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMpPag(id) {
  const ok = await confirmar({
    title: 'Eliminar pago',
    message: `Se eliminará el pago #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/mercadopagopagos.php?id=${id}`, 'DELETE');
    toast('Pago eliminado.');
    cargarMpPag();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago > Cuentas (ABM) -------------------------
const mpCtaFiltrosDefaults = {
  q: '', codigo: '', estado: '', modo: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const mpCtaFiltros = { ...mpCtaFiltrosDefaults };
let mpCtaBuscadorTimer   = null;
let mpCtaFiltrosSnapshot = null;

function mpCtaEstadoBadge(e) {
  if (e === '1') return `<span class="badge badge-success">Habilitada</span>`;
  if (e === '0') return `<span class="badge badge-danger">Deshabilitada</span>`;
  return `<span class="badge badge-info">—</span>`;
}

function mpCtaModoBadge(m) {
  if (m === 'P') return `<span class="badge badge-success">Producción</span>`;
  if (m === 'T') return `<span class="badge badge-warn">Testing</span>`;
  return `<span class="badge badge-info">—</span>`;
}

function mpCtaMask(s) {
  if (s == null || s === '') return '—';
  const str = String(s);
  if (str.length <= 8) return '••••';
  return str.slice(0, 4) + '…' + str.slice(-4);
}

route('/mercadopagocuentas', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Mercadopago" onclick="location.hash='#/mercadopago'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">🏦</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las cuentas Mercadopago concentran las credenciales (public key y access
          token, en producción y testing), el CVU / alias de acreditación, los
          webhooks configurados y la imputación contable de cada cuenta.
        </div>
      </div>

      <div class="stats-bar" id="mpCtaStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Habilitadas</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Producción</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="mpCtaSearch"
                   placeholder="🔍 Buscar nombre, alias, CVU, imputación o UUID…">
            <button class="search-clear" id="mpCtaSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="mpCtaFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="mpCtaFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="mpCtaRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="mpCtaNuevoBtn">+ Nueva cuenta</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Alias</th>
              <th>CVU</th>
              <th>Imputación</th>
              <th>Modo</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="mpCtaTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="mpCtaCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMpCtaBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMpCta()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMpCta()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMpCtaCodigo" min="1" placeholder="ID …" oninput="onFiltroMpCta('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fMpCtaEstado" onchange="onFiltroMpCta('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="1">Habilitada</option>
                <option value="0">Deshabilitada</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Modo</label>
              <select id="fMpCtaModo" onchange="onFiltroMpCta('modo', this.value)">
                <option value="">— Todos —</option>
                <option value="P">Producción</option>
                <option value="T">Testing</option>
              </select>
            </div>
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMpCtaLimite" min="1" max="1000" value="100" onchange="onFiltroMpCta('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMpCtaOrderBy" onchange="onFiltroMpCta('order_by', this.value)">
                <option value="id">Código</option>
                <option value="nombre">Nombre</option>
                <option value="cvuAlias">Alias</option>
                <option value="cvuNumero">CVU</option>
                <option value="imputacion">Imputación</option>
                <option value="modo">Modo</option>
                <option value="estado">Estado</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Dirección</label>
            <select id="fMpCtaDir" onchange="onFiltroMpCta('dir', this.value)">
              <option value="desc">Descendente</option>
              <option value="asc">Ascendente</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMpCta()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMpCta()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMpCta()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#mpCtaNuevoBtn').addEventListener('click', () => abrirAltaEdicionMpCta(null));
  $('#mpCtaFiltrosBtn').addEventListener('click', () => abrirModalFiltrosMpCta());
  $('#mpCtaRefrescarBtn').addEventListener('click', () => cargarMpCta());

  const inp = $('#mpCtaSearch');
  const clr = $('#mpCtaSearchClear');
  inp.value = mpCtaFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    mpCtaFiltros.q = inp.value.trim();
    clearTimeout(mpCtaBuscadorTimer);
    mpCtaBuscadorTimer = setTimeout(() => { cargarMpCta(); refrescarBadgeFiltrosMpCta(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    mpCtaFiltros.q = '';
    cargarMpCta();
    refrescarBadgeFiltrosMpCta();
  });

  $('#mpCtaCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMpCta(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMpCta(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMpCta(data.id);
  });

  $('#mpCtaTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#mpCtaCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMpCta(Number(tr.dataset.id));
  });
  $('#mpCtaTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#mpCtaCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMpCta();
  await cargarMpCta();
}, 'Cuentas');

async function cargarMpCta() {
  const tbody = $('#mpCtaTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(mpCtaFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/mercadopagocuentas.php?' + qs.toString());
    pintarStatsMpCta(data.stats);
    pintarTablaMpCta(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMpCta(s) {
  const cards = $$('#mpCtaStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.habilitadas);
  cards[2].textContent = fmtNum(s.produccion);
}

function pintarTablaMpCta(rows) {
  const tbody = $('#mpCtaTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="table-empty">Sin cuentas.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((c) => `
    <tr data-id="${c.id}" class="row-clickable">
      <td class="td-id">#${esc(c.id)}</td>
      <td class="td-nombre">${esc(c.nombre || '—')}</td>
      <td style="font-family:monospace">${esc(c.cvuAlias || '—')}</td>
      <td style="font-family:monospace">${esc(c.cvuNumero || '—')}</td>
      <td>${esc(c.imputacion || '—')}</td>
      <td>${mpCtaModoBadge(c.modo)}</td>
      <td>${mpCtaEstadoBadge(c.estado)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${c.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroMpCta(key, value) {
  if (['estado', 'modo', 'order_by', 'dir'].includes(key)) {
    mpCtaFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    mpCtaFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    mpCtaFiltros.limite = n;
  } else {
    mpCtaFiltros[key] = value;
  }
  refrescarBadgeFiltrosMpCta();
  cargarMpCta();
}

function refrescarBadgeFiltrosMpCta() {
  const btn   = $('#mpCtaFiltrosBtn');
  const badge = $('#mpCtaFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(mpCtaFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(mpCtaFiltros[k]) !== String(mpCtaFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMpCta() {
  const f = mpCtaFiltros;
  $('#fMpCtaCodigo').value  = f.codigo;
  $('#fMpCtaEstado').value  = f.estado;
  $('#fMpCtaModo').value    = f.modo;
  $('#fMpCtaLimite').value  = f.limite;
  $('#fMpCtaOrderBy').value = f.order_by;
  $('#fMpCtaDir').value     = f.dir;
}

function abrirModalFiltrosMpCta() {
  mpCtaFiltrosSnapshot = { ...mpCtaFiltros };
  sincronizarControlesFiltrosMpCta();
  $('#filtrosMpCtaBackdrop').classList.add('open');
}
function cerrarModalFiltrosMpCta() { $('#filtrosMpCtaBackdrop').classList.remove('open'); }
function cancelarFiltrosMpCta() {
  if (mpCtaFiltrosSnapshot) {
    Object.assign(mpCtaFiltros, mpCtaFiltrosSnapshot);
    refrescarBadgeFiltrosMpCta();
    cargarMpCta();
  }
  cerrarModalFiltrosMpCta();
}
function limpiarFiltrosMpCta() {
  Object.assign(mpCtaFiltros, mpCtaFiltrosDefaults);
  mpCtaFiltros.q = $('#mpCtaSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMpCta();
  refrescarBadgeFiltrosMpCta();
  cargarMpCta();
}
window.onFiltroMpCta           = onFiltroMpCta;
window.cancelarFiltrosMpCta    = cancelarFiltrosMpCta;
window.limpiarFiltrosMpCta     = limpiarFiltrosMpCta;
window.cerrarModalFiltrosMpCta = cerrarModalFiltrosMpCta;

async function abrirConsultarMpCta(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Cuenta Mercadopago <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMpCta(id); }
  });

  try {
    const c = await apiGet(`api/mercadopagocuentas.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaMpCta(c);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaMpCta(c) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };

  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-size:1.3rem;font-weight:700">${esc(c.nombre || '—')}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(c.cvuAlias || '')}</span>
        </div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(c.id)} · <code>${esc(c.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:220px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${mpCtaEstadoBadge(c.estado)}</div>
        <div>${mpCtaModoBadge(c.modo)}</div>
      </div>
    </div>

    ${seccion('Identificación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Código',      c.id)}
      ${card('UUID',        c.uuid, false, true)}
      ${card('Nombre',      c.nombre)}
      ${card('Logo (URL)',  c.logo, false, true)}
      ${card('Alias (CVU)', c.cvuAlias, false, true)}
      ${card('CVU',         c.cvuNumero, false, true)}
      ${card('Imputación',  c.imputacion)}
      ${card('Modo',        c.modo === 'P' ? 'Producción' : c.modo === 'T' ? 'Testing' : c.modo)}
    </dl>

    ${seccion('Credenciales producción')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Public key',   c.publicKey,   false, true)}
      ${card('Access token', c.accessToken, false, true)}
    </dl>

    ${seccion('Credenciales testing')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Public key',   c.publicKeyTesting,   false, true)}
      ${card('Access token', c.accessTokenTesting, false, true)}
    </dl>

    ${seccion('Webhooks')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Endpoint prod',    c.webhookEndpoint,        false, true)}
      ${card('Key prod',         c.webhookKey,             false, true)}
      ${card('Endpoint testing', c.webhookEndpointTesting, false, true)}
      ${card('Key testing',      c.webhookKeyTesting,      false, true)}
    </dl>
  `;
}

async function abrirAltaEdicionMpCta(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar cuenta <span class="modal-subtitle">#${id}</span>` : 'Nueva cuenta'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMpCtaHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const c = await apiGet(`api/mercadopagocuentas.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMpCtaHtml(c);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMpCta(id, a);
  });
}

function formMpCtaHtml(c) {
  const v   = (k) => esc(c?.[k] ?? '');
  const sel = (k, val) => (c?.[k] ?? '') === val ? 'selected' : '';
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>UUID</label>
        <input type="text" id="mpCtaUuid" maxlength="50" value="${v('uuid')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Modo</label>
        <select id="mpCtaModo">
          <option value=""  ${sel('modo','')}>—</option>
          <option value="P" ${sel('modo','P')}>Producción</option>
          <option value="T" ${sel('modo','T')}>Testing</option>
        </select>
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="mpCtaEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="1" ${sel('estado','1')}>Habilitada</option>
          <option value="0" ${sel('estado','0')}>Deshabilitada</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Nombre</label>
      <input type="text" id="mpCtaNombre" maxlength="255" value="${v('nombre')}">
    </div>
    <div class="form-group">
      <label>Logo (URL)</label>
      <input type="text" id="mpCtaLogo" maxlength="255" value="${v('logo')}" style="font-family:monospace">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Alias (CVU)</label>
        <input type="text" id="mpCtaCvuAlias" maxlength="255" value="${v('cvuAlias')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>CVU (número)</label>
        <input type="text" id="mpCtaCvuNumero" maxlength="255" value="${v('cvuNumero')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Imputación</label>
      <input type="text" id="mpCtaImputacion" maxlength="255" value="${v('imputacion')}">
    </div>

    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:12px 0 4px">
      Credenciales producción
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Public key</label>
        <input type="text" id="mpCtaPublicKey" maxlength="255" value="${v('publicKey')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Access token</label>
        <input type="text" id="mpCtaAccessToken" maxlength="255" value="${v('accessToken')}" style="font-family:monospace">
      </div>
    </div>

    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:12px 0 4px">
      Credenciales testing
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Public key testing</label>
        <input type="text" id="mpCtaPublicKeyTesting" maxlength="255" value="${v('publicKeyTesting')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Access token testing</label>
        <input type="text" id="mpCtaAccessTokenTesting" maxlength="255" value="${v('accessTokenTesting')}" style="font-family:monospace">
      </div>
    </div>

    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:12px 0 4px">
      Webhooks
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Endpoint (producción)</label>
        <input type="text" id="mpCtaWebhookEndpoint" maxlength="255" value="${v('webhookEndpoint')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Key (producción)</label>
        <input type="text" id="mpCtaWebhookKey" maxlength="255" value="${v('webhookKey')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Endpoint (testing)</label>
        <input type="text" id="mpCtaWebhookEndpointTesting" maxlength="255" value="${v('webhookEndpointTesting')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Key (testing)</label>
        <input type="text" id="mpCtaWebhookKeyTesting" maxlength="255" value="${v('webhookKeyTesting')}" style="font-family:monospace">
      </div>
    </div>
    <div class="field-error" id="mpCtaFormError" style="display:none"></div>
  `;
}

async function guardarMpCta(id, btn) {
  const err = $('#mpCtaFormError');
  err.style.display = 'none';

  const payload = {
    uuid:                   $('#mpCtaUuid').value.trim(),
    nombre:                 $('#mpCtaNombre').value.trim(),
    logo:                   $('#mpCtaLogo').value.trim(),
    cvuAlias:               $('#mpCtaCvuAlias').value.trim(),
    cvuNumero:              $('#mpCtaCvuNumero').value.trim(),
    publicKey:              $('#mpCtaPublicKey').value.trim(),
    accessToken:            $('#mpCtaAccessToken').value.trim(),
    publicKeyTesting:       $('#mpCtaPublicKeyTesting').value.trim(),
    accessTokenTesting:     $('#mpCtaAccessTokenTesting').value.trim(),
    webhookEndpoint:        $('#mpCtaWebhookEndpoint').value.trim(),
    webhookKey:             $('#mpCtaWebhookKey').value.trim(),
    webhookEndpointTesting: $('#mpCtaWebhookEndpointTesting').value.trim(),
    webhookKeyTesting:      $('#mpCtaWebhookKeyTesting').value.trim(),
    imputacion:             $('#mpCtaImputacion').value.trim(),
    modo:                   $('#mpCtaModo').value,
    estado:                 $('#mpCtaEstado').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/mercadopagocuentas.php', 'POST', payload);
      toast('Cuenta creada.');
    } else {
      await apiSend(`api/mercadopagocuentas.php?id=${id}`, 'PUT', payload);
      toast('Cuenta actualizada.');
    }
    closeModal();
    cargarMpCta();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMpCta(id) {
  const ok = await confirmar({
    title: 'Eliminar cuenta',
    message: `Se eliminará la cuenta #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/mercadopagocuentas.php?id=${id}`, 'DELETE');
    toast('Cuenta eliminada.');
    cargarMpCta();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago > Registros (ABM) -------------------------
const mpRegFiltrosDefaults = {
  q: '', codigo: '', tipo: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 200,
};
const mpRegFiltros = { ...mpRegFiltrosDefaults };
let mpRegBuscadorTimer   = null;
let mpRegFiltrosSnapshot = null;
let mpRegTiposCache      = [];

route('/mercadopagoregistros', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Mercadopago" onclick="location.hash='#/mercadopago'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📰</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los registros de Mercadopago son el log crudo de eventos y notificaciones
          recibidos de la plataforma — cada línea trae fecha, tipo y el cuerpo
          completo del evento tal como llegó al webhook.
        </div>
      </div>

      <div class="stats-bar" id="mpRegStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Tipos distintos</span><span class="stat-value">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="mpRegSearch"
                   placeholder="🔍 Buscar tipo o cuerpo del evento…">
            <button class="search-clear" id="mpRegSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="mpRegFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="mpRegFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="mpRegRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="mpRegNuevoBtn">+ Nuevo registro</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th style="width:90px">Código</th>
              <th style="width:180px">Fecha</th>
              <th style="width:180px">Tipo</th>
              <th>Cuerpo</th>
              <th style="width:60px;text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="mpRegTbody">
            <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="mpRegCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMpRegBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMpReg()">
      <div class="modal" style="max-width:560px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMpReg()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMpRegCodigo" min="1" placeholder="ID …" oninput="onFiltroMpReg('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Tipo</label>
              <select id="fMpRegTipo" onchange="onFiltroMpReg('tipo', this.value)">
                <option value="">— Todos —</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fMpRegDesde" onchange="onFiltroMpReg('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fMpRegHasta" onchange="onFiltroMpReg('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMpRegLimite" min="1" max="2000" value="200" onchange="onFiltroMpReg('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMpRegOrderBy" onchange="onFiltroMpReg('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="tipo">Tipo</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fMpRegDir" onchange="onFiltroMpReg('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMpReg()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMpReg()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMpReg()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#mpRegNuevoBtn').addEventListener('click', () => abrirAltaEdicionMpReg(null));
  $('#mpRegFiltrosBtn').addEventListener('click', () => abrirModalFiltrosMpReg());
  $('#mpRegRefrescarBtn').addEventListener('click', () => cargarMpReg());

  const inp = $('#mpRegSearch');
  const clr = $('#mpRegSearchClear');
  inp.value = mpRegFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    mpRegFiltros.q = inp.value.trim();
    clearTimeout(mpRegBuscadorTimer);
    mpRegBuscadorTimer = setTimeout(() => { cargarMpReg(); refrescarBadgeFiltrosMpReg(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    mpRegFiltros.q = '';
    cargarMpReg();
    refrescarBadgeFiltrosMpReg();
  });

  $('#mpRegCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMpReg(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMpReg(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMpReg(data.id);
  });

  $('#mpRegTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#mpRegCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMpReg(Number(tr.dataset.id));
  });
  $('#mpRegTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#mpRegCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMpReg();
  await cargarMpReg();
}, 'Registros');

async function cargarMpReg() {
  const tbody = $('#mpRegTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(mpRegFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/mercadopagoregistros.php?' + qs.toString());
    mpRegTiposCache = data.tipos || [];
    pintarStatsMpReg(data.stats);
    pintarTablaMpReg(data.items || []);
    poblarSelectTiposMpReg();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMpReg(s) {
  const cards = $$('#mpRegStats .stat-card .stat-value');
  if (cards.length < 2) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.tipos_distintos);
}

function pintarTablaMpReg(rows) {
  const tbody = $('#mpRegTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Sin registros.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => {
    const cuerpoInline = String(r.cuerpo ?? '').replace(/\s+/g, ' ').trim();
    return `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(fmtFechaLarga(r.fecha))}</td>
      <td style="white-space:nowrap"><span class="badge badge-info">${esc(r.tipo || '—')}</span></td>
      <td style="font-family:monospace;font-size:.82rem;color:var(--muted);max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="${esc(cuerpoInline)}">${esc(cuerpoInline)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${r.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `;
  }).join('');
}

function poblarSelectTiposMpReg() {
  const sel = $('#fMpRegTipo');
  if (!sel) return;
  const actual = mpRegFiltros.tipo || '';
  sel.innerHTML = `<option value="">— Todos —</option>` +
    mpRegTiposCache.map((t) => `<option value="${esc(t)}" ${t === actual ? 'selected' : ''}>${esc(t)}</option>`).join('');
}

function onFiltroMpReg(key, value) {
  if (['tipo', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    mpRegFiltros[key] = value;
  } else if (key === 'codigo') {
    const v = String(value).trim();
    mpRegFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 2000) n = 2000;
    mpRegFiltros.limite = n;
  } else {
    mpRegFiltros[key] = value;
  }
  refrescarBadgeFiltrosMpReg();
  cargarMpReg();
}

function refrescarBadgeFiltrosMpReg() {
  const btn   = $('#mpRegFiltrosBtn');
  const badge = $('#mpRegFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(mpRegFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(mpRegFiltros[k]) !== String(mpRegFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMpReg() {
  const f = mpRegFiltros;
  $('#fMpRegCodigo').value  = f.codigo;
  poblarSelectTiposMpReg();
  $('#fMpRegDesde').value   = f.desde;
  $('#fMpRegHasta').value   = f.hasta;
  $('#fMpRegLimite').value  = f.limite;
  $('#fMpRegOrderBy').value = f.order_by;
  $('#fMpRegDir').value     = f.dir;
}

function abrirModalFiltrosMpReg() {
  mpRegFiltrosSnapshot = { ...mpRegFiltros };
  sincronizarControlesFiltrosMpReg();
  $('#filtrosMpRegBackdrop').classList.add('open');
}
function cerrarModalFiltrosMpReg() { $('#filtrosMpRegBackdrop').classList.remove('open'); }
function cancelarFiltrosMpReg() {
  if (mpRegFiltrosSnapshot) {
    Object.assign(mpRegFiltros, mpRegFiltrosSnapshot);
    refrescarBadgeFiltrosMpReg();
    cargarMpReg();
  }
  cerrarModalFiltrosMpReg();
}
function limpiarFiltrosMpReg() {
  Object.assign(mpRegFiltros, mpRegFiltrosDefaults);
  mpRegFiltros.q = $('#mpRegSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMpReg();
  refrescarBadgeFiltrosMpReg();
  cargarMpReg();
}
window.onFiltroMpReg           = onFiltroMpReg;
window.cancelarFiltrosMpReg    = cancelarFiltrosMpReg;
window.limpiarFiltrosMpReg     = limpiarFiltrosMpReg;
window.cerrarModalFiltrosMpReg = cerrarModalFiltrosMpReg;

async function abrirConsultarMpReg(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Registro Mercadopago <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMpReg(id); }
  });

  try {
    const r = await apiGet(`api/mercadopagoregistros.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = `
      <div style="padding:14px 18px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <div style="font-family:monospace">${esc(fmtFechaLarga(r.fecha))}</div>
        <span class="badge badge-info">${esc(r.tipo || '—')}</span>
        <div style="margin-left:auto;font-size:.75rem;color:var(--muted)">#${esc(r.id)}</div>
      </div>
      <div class="form-group" style="margin-top:14px">
        <label>Cuerpo</label>
        <textarea class="json-editor" readonly spellcheck="false" autocomplete="off"
                  style="min-height:340px;font-family:monospace">${esc(r.cuerpo || '')}</textarea>
      </div>
    `;
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

async function abrirAltaEdicionMpReg(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar registro <span class="modal-subtitle">#${id}</span>` : 'Nuevo registro'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMpRegHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const r = await apiGet(`api/mercadopagoregistros.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMpRegHtml(r);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMpReg(id, a);
  });
}

function formMpRegHtml(r) {
  const v  = (k) => esc(r?.[k] ?? '');
  const dt = (k) => {
    const raw = r?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row">
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="mpRegFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Tipo</label>
        <input type="text" id="mpRegTipo" maxlength="50" value="${v('tipo')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Cuerpo</label>
      <textarea id="mpRegCuerpo" rows="12" style="font-family:monospace">${v('cuerpo')}</textarea>
    </div>
    <div class="field-error" id="mpRegFormError" style="display:none"></div>
  `;
}

async function guardarMpReg(id, btn) {
  const err = $('#mpRegFormError');
  err.style.display = 'none';

  const payload = {
    fecha:  $('#mpRegFecha').value || null,
    tipo:   $('#mpRegTipo').value.trim(),
    cuerpo: $('#mpRegCuerpo').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/mercadopagoregistros.php', 'POST', payload);
      toast('Registro creado.');
    } else {
      await apiSend(`api/mercadopagoregistros.php?id=${id}`, 'PUT', payload);
      toast('Registro actualizado.');
    }
    closeModal();
    cargarMpReg();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMpReg(id) {
  const ok = await confirmar({
    title: 'Eliminar registro',
    message: `Se eliminará el registro #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/mercadopagoregistros.php?id=${id}`, 'DELETE');
    toast('Registro eliminado.');
    cargarMpReg();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago > Suscripciones (ABM) -------------------------
const mpSubFiltrosDefaults = {
  q: '', codigo: '', cuenta: '', estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const mpSubFiltros = { ...mpSubFiltrosDefaults };
let mpSubBuscadorTimer   = null;
let mpSubFiltrosSnapshot = null;
let mpSubEstadosCache    = [];

function mpSubEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const lower = String(e).toLowerCase();
  let cls = 'badge-info';
  if (lower.startsWith('auth') || lower === 'active' || lower === 'activa')      cls = 'badge-success';
  else if (lower.startsWith('paus'))                                             cls = 'badge-warn';
  else if (lower === 'pending' || lower === 'pendiente')                         cls = 'badge-warn';
  else if (lower.startsWith('cancel') || lower === 'finalized' || lower === 'finalizada') cls = 'badge-danger';
  return `<span class="badge ${cls}">${esc(e)}</span>`;
}

function mpSubFmtMonto(v) {
  if (v == null || v === '' || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function mpSubFmtCiclo(s) {
  const p = s.periodo || '';
  const f = s.frecuencia || '';
  if (!p && !f) return '—';
  return `${esc(f || '?')} × ${esc(p || '?')}`;
}

route('/mercadopagosuscripciones', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Mercadopago" onclick="location.hash='#/mercadopago'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">🔁</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Las suscripciones de Mercadopago definen los cobros recurrentes de la
          plataforma — cada una lleva el suscriptor, el ciclo (frecuencia y período),
          el monto, las fechas del ciclo de vida (inicio, pausa, reactivación, fin)
          y el estado devuelto por Mercadopago.
        </div>
      </div>

      <div class="stats-bar" id="mpSubStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Activas</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Pausadas</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="mpSubSearch"
                   placeholder="🔍 Buscar nombre, correo, celular, referencia o UUID…">
            <button class="search-clear" id="mpSubSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="mpSubFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="mpSubFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="mpSubRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="mpSubNuevoBtn">+ Nueva suscripción</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Registrada</th>
              <th>Cuenta</th>
              <th>Suscriptor</th>
              <th>Concepto</th>
              <th>Ciclo</th>
              <th style="text-align:right">Monto</th>
              <th>Estado</th>
              <th>Iniciada</th>
              <th>Finalizada</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="mpSubTbody">
            <tr><td colspan="11" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="mpSubCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMpSubBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMpSub()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMpSub()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMpSubCodigo" min="1" placeholder="ID …" oninput="onFiltroMpSub('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Cuenta (ID)</label>
              <input type="number" id="fMpSubCuenta" min="1" oninput="onFiltroMpSub('cuenta', this.value)">
            </div>
          </div>
          <div class="form-group">
            <label>Estado</label>
            <select id="fMpSubEstado" onchange="onFiltroMpSub('estado', this.value)">
              <option value="">— Todos —</option>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde (registrada)</label>
              <input type="date" id="fMpSubDesde" onchange="onFiltroMpSub('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta (registrada)</label>
              <input type="date" id="fMpSubHasta" onchange="onFiltroMpSub('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMpSubLimite" min="1" max="1000" value="100" onchange="onFiltroMpSub('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMpSubOrderBy" onchange="onFiltroMpSub('order_by', this.value)">
                <option value="id">Código</option>
                <option value="registrada">Registrada</option>
                <option value="actualizada">Actualizada</option>
                <option value="iniciada">Iniciada</option>
                <option value="finalizada">Finalizada</option>
                <option value="cuenta">Cuenta</option>
                <option value="nombre">Nombre</option>
                <option value="correo">Correo</option>
                <option value="concepto">Concepto</option>
                <option value="monto">Monto</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fMpSubDir" onchange="onFiltroMpSub('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMpSub()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMpSub()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMpSub()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#mpSubNuevoBtn').addEventListener('click', () => abrirAltaEdicionMpSub(null));
  $('#mpSubFiltrosBtn').addEventListener('click', () => abrirModalFiltrosMpSub());
  $('#mpSubRefrescarBtn').addEventListener('click', () => cargarMpSub());

  const inp = $('#mpSubSearch');
  const clr = $('#mpSubSearchClear');
  inp.value = mpSubFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    mpSubFiltros.q = inp.value.trim();
    clearTimeout(mpSubBuscadorTimer);
    mpSubBuscadorTimer = setTimeout(() => { cargarMpSub(); refrescarBadgeFiltrosMpSub(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    mpSubFiltros.q = '';
    cargarMpSub();
    refrescarBadgeFiltrosMpSub();
  });

  $('#mpSubCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMpSub(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMpSub(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMpSub(data.id);
  });

  $('#mpSubTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#mpSubCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMpSub(Number(tr.dataset.id));
  });
  $('#mpSubTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#mpSubCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMpSub();
  await cargarMpSub();
}, 'Suscripciones');

async function cargarMpSub() {
  const tbody = $('#mpSubTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(mpSubFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/mercadopagosuscripciones.php?' + qs.toString());
    mpSubEstadosCache = data.estados || [];
    pintarStatsMpSub(data.stats);
    pintarTablaMpSub(data.items || []);
    poblarSelectEstadosMpSub();
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="11" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMpSub(s) {
  const cards = $$('#mpSubStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.activas);
  cards[2].textContent = fmtNum(s.pausadas);
}

function pintarTablaMpSub(rows) {
  const tbody = $('#mpSubTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="11" class="table-empty">Sin suscripciones.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((s) => `
    <tr data-id="${s.id}" class="row-clickable">
      <td class="td-id">#${esc(s.id)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(fmtFechaLarga(s.registrada))}</td>
      <td>${s.cuenta != null ? '#' + esc(s.cuenta) : '—'}</td>
      <td class="td-nombre">${esc(s.nombre || s.correo || s.celular || '—')}</td>
      <td>${esc(s.concepto || '—')}</td>
      <td style="font-family:monospace">${mpSubFmtCiclo(s)}</td>
      <td style="text-align:right;font-family:monospace">${mpSubFmtMonto(s.monto)}</td>
      <td>${mpSubEstadoBadge(s.estado)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(fmtFechaLarga(s.iniciada))}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(fmtFechaLarga(s.finalizada))}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${s.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function poblarSelectEstadosMpSub() {
  const sel = $('#fMpSubEstado');
  if (!sel) return;
  const actual = mpSubFiltros.estado || '';
  sel.innerHTML = `<option value="">— Todos —</option>` +
    mpSubEstadosCache.map((e) => `<option value="${esc(e)}" ${e === actual ? 'selected' : ''}>${esc(e)}</option>`).join('');
}

function onFiltroMpSub(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    mpSubFiltros[key] = value;
  } else if (['codigo', 'cuenta'].includes(key)) {
    const v = String(value).trim();
    mpSubFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    mpSubFiltros.limite = n;
  } else {
    mpSubFiltros[key] = value;
  }
  refrescarBadgeFiltrosMpSub();
  cargarMpSub();
}

function refrescarBadgeFiltrosMpSub() {
  const btn   = $('#mpSubFiltrosBtn');
  const badge = $('#mpSubFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(mpSubFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(mpSubFiltros[k]) !== String(mpSubFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMpSub() {
  const f = mpSubFiltros;
  $('#fMpSubCodigo').value  = f.codigo;
  $('#fMpSubCuenta').value  = f.cuenta;
  poblarSelectEstadosMpSub();
  $('#fMpSubDesde').value   = f.desde;
  $('#fMpSubHasta').value   = f.hasta;
  $('#fMpSubLimite').value  = f.limite;
  $('#fMpSubOrderBy').value = f.order_by;
  $('#fMpSubDir').value     = f.dir;
}

function abrirModalFiltrosMpSub() {
  mpSubFiltrosSnapshot = { ...mpSubFiltros };
  sincronizarControlesFiltrosMpSub();
  $('#filtrosMpSubBackdrop').classList.add('open');
}
function cerrarModalFiltrosMpSub() { $('#filtrosMpSubBackdrop').classList.remove('open'); }
function cancelarFiltrosMpSub() {
  if (mpSubFiltrosSnapshot) {
    Object.assign(mpSubFiltros, mpSubFiltrosSnapshot);
    refrescarBadgeFiltrosMpSub();
    cargarMpSub();
  }
  cerrarModalFiltrosMpSub();
}
function limpiarFiltrosMpSub() {
  Object.assign(mpSubFiltros, mpSubFiltrosDefaults);
  mpSubFiltros.q = $('#mpSubSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMpSub();
  refrescarBadgeFiltrosMpSub();
  cargarMpSub();
}
window.onFiltroMpSub           = onFiltroMpSub;
window.cancelarFiltrosMpSub    = cancelarFiltrosMpSub;
window.limpiarFiltrosMpSub     = limpiarFiltrosMpSub;
window.cerrarModalFiltrosMpSub = cerrarModalFiltrosMpSub;

async function abrirConsultarMpSub(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Suscripción Mercadopago <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMpSub(id); }
  });

  try {
    const s = await apiGet(`api/mercadopagosuscripciones.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaMpSub(s);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaMpSub(s) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };
  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-size:1.3rem;font-weight:700">${esc(s.nombre || s.correo || '—')}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">$ ${mpSubFmtMonto(s.monto)}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(s.concepto || 'Sin concepto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(s.id)} · <code>${esc(s.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${mpSubEstadoBadge(s.estado)}</div>
        <div style="margin-top:6px;font-size:.85rem;line-height:1.5">
          <div><span style="color:var(--muted)">Ciclo:</span> ${mpSubFmtCiclo(s)}</div>
        </div>
      </div>
    </div>

    ${seccion('Suscriptor')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Nombre',     s.nombre)}
      ${card('Correo',     s.correo, false, true)}
      ${card('Celular',    s.celular, false, true)}
      ${card('Referencia', s.referencia, false, true)}
      ${card('Cuenta',     s.cuenta)}
      ${card('UUID',       s.uuid, false, true)}
    </dl>

    ${seccion('Cobro recurrente')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Concepto',          s.concepto)}
      ${card('Monto',             mpSubFmtMonto(s.monto))}
      ${card('Frecuencia',        s.frecuencia)}
      ${card('Período',           s.periodo)}
      ${card('Frecuencia prueba', s.pruebaFrecuencia)}
      ${card('Período prueba',    s.pruebaPeriodo)}
    </dl>

    ${seccion('Destino y estado')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Destino',     s.destino, true, true)}
      ${card('Estado',      s.estado, true)}
      ${card('Propiedades', s.propiedades, true, true)}
    </dl>

    ${seccion('Ciclo de vida')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Registrada',  fmtFecha(s.registrada))}
      ${card('Actualizada', fmtFecha(s.actualizada))}
      ${card('Iniciada',    fmtFecha(s.iniciada))}
      ${card('Pausada',     fmtFecha(s.pausada))}
      ${card('Reactivada',  fmtFecha(s.reactivada))}
      ${card('Finalizada',  fmtFecha(s.finalizada))}
    </dl>
  `;
}

async function abrirAltaEdicionMpSub(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar suscripción <span class="modal-subtitle">#${id}</span>` : 'Nueva suscripción'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMpSubHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const s = await apiGet(`api/mercadopagosuscripciones.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMpSubHtml(s);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMpSub(id, a);
  });
}

function formMpSubHtml(s) {
  const v  = (k) => esc(s?.[k] ?? '');
  const dt = (k) => {
    const raw = s?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>UUID</label>
        <input type="text" id="mpSubUuid" maxlength="100" value="${v('uuid')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Cuenta (ID)</label>
        <input type="number" id="mpSubCuenta" min="1" value="${v('cuenta')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <input type="text" id="mpSubEstado" maxlength="50" value="${v('estado')}" placeholder="authorized, paused, cancelled…" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Nombre</label>
        <input type="text" id="mpSubNombre" maxlength="100" value="${v('nombre')}">
      </div>
      <div class="form-group">
        <label>Correo</label>
        <input type="email" id="mpSubCorreo" maxlength="100" value="${v('correo')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Celular</label>
        <input type="text" id="mpSubCelular" maxlength="100" value="${v('celular')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Referencia</label>
        <input type="text" id="mpSubReferencia" maxlength="100" value="${v('referencia')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Concepto</label>
        <input type="text" id="mpSubConcepto" maxlength="255" value="${v('concepto')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Monto</label>
        <input type="number" id="mpSubMonto" step="0.01" min="0" value="${v('monto')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Frecuencia</label>
        <input type="text" id="mpSubFrecuencia" maxlength="10" value="${v('frecuencia')}" placeholder="1" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Período</label>
        <input type="text" id="mpSubPeriodo" maxlength="10" value="${v('periodo')}" placeholder="months" style="font-family:monospace">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Frecuencia prueba</label>
        <input type="text" id="mpSubPruebaFrecuencia" maxlength="10" value="${v('pruebaFrecuencia')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Período prueba</label>
        <input type="text" id="mpSubPruebaPeriodo" maxlength="10" value="${v('pruebaPeriodo')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Destino (URL de retorno)</label>
      <textarea id="mpSubDestino" rows="2" maxlength="1000" style="font-family:monospace">${v('destino')}</textarea>
    </div>

    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:12px 0 4px">
      Ciclo de vida
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Registrada</label>
        <input type="datetime-local" id="mpSubRegistrada" value="${dt('registrada')}">
      </div>
      <div class="form-group">
        <label>Actualizada</label>
        <input type="datetime-local" id="mpSubActualizada" value="${dt('actualizada')}">
      </div>
      <div class="form-group">
        <label>Iniciada</label>
        <input type="datetime-local" id="mpSubIniciada" value="${dt('iniciada')}">
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Pausada</label>
        <input type="datetime-local" id="mpSubPausada" value="${dt('pausada')}">
      </div>
      <div class="form-group">
        <label>Reactivada</label>
        <input type="datetime-local" id="mpSubReactivada" value="${dt('reactivada')}">
      </div>
      <div class="form-group">
        <label>Finalizada</label>
        <input type="datetime-local" id="mpSubFinalizada" value="${dt('finalizada')}">
      </div>
    </div>

    <div class="form-group">
      <label>Propiedades</label>
      <textarea id="mpSubPropiedades" rows="4" style="font-family:monospace">${v('propiedades')}</textarea>
    </div>
    <div class="field-error" id="mpSubFormError" style="display:none"></div>
  `;
}

async function guardarMpSub(id, btn) {
  const err = $('#mpSubFormError');
  err.style.display = 'none';

  const payload = {
    uuid:             $('#mpSubUuid').value.trim(),
    cuenta:           $('#mpSubCuenta').value,
    nombre:           $('#mpSubNombre').value.trim(),
    celular:          $('#mpSubCelular').value.trim(),
    correo:           $('#mpSubCorreo').value.trim(),
    referencia:       $('#mpSubReferencia').value.trim(),
    concepto:         $('#mpSubConcepto').value.trim(),
    monto:            $('#mpSubMonto').value,
    periodo:          $('#mpSubPeriodo').value.trim(),
    frecuencia:       $('#mpSubFrecuencia').value.trim(),
    pruebaPeriodo:    $('#mpSubPruebaPeriodo').value.trim(),
    pruebaFrecuencia: $('#mpSubPruebaFrecuencia').value.trim(),
    destino:          $('#mpSubDestino').value.trim(),
    registrada:       $('#mpSubRegistrada').value  || null,
    actualizada:      $('#mpSubActualizada').value || null,
    iniciada:         $('#mpSubIniciada').value    || null,
    pausada:          $('#mpSubPausada').value     || null,
    reactivada:       $('#mpSubReactivada').value  || null,
    finalizada:       $('#mpSubFinalizada').value  || null,
    estado:           $('#mpSubEstado').value.trim(),
    propiedades:      $('#mpSubPropiedades').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/mercadopagosuscripciones.php', 'POST', payload);
      toast('Suscripción creada.');
    } else {
      await apiSend(`api/mercadopagosuscripciones.php?id=${id}`, 'PUT', payload);
      toast('Suscripción actualizada.');
    }
    closeModal();
    cargarMpSub();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMpSub(id) {
  const ok = await confirmar({
    title: 'Eliminar suscripción',
    message: `Se eliminará la suscripción #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/mercadopagosuscripciones.php?id=${id}`, 'DELETE');
    toast('Suscripción eliminada.');
    cargarMpSub();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Vista: Mercadopago > Débitos (ABM) -------------------------
const mpDebFiltrosDefaults = {
  q: '', codigo: '', cuenta: '', suscripcion: '', recibo: '',
  estado: '', desde: '', hasta: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
const mpDebFiltros = { ...mpDebFiltrosDefaults };
let mpDebBuscadorTimer   = null;
let mpDebFiltrosSnapshot = null;

function mpDebEstadoBadge(e) {
  if (e == null || e === '') return `<span class="badge badge-info">—</span>`;
  const colorMap = {
    A: 'badge-success',
    P: 'badge-warn',
    R: 'badge-danger',
    C: 'badge-danger',
    X: 'badge-danger',
  };
  const labelMap = {
    A: 'Aprobado', P: 'Pendiente', R: 'Rechazado', C: 'Cancelado', X: 'Anulado',
  };
  const cls = colorMap[e] || 'badge-info';
  return `<span class="badge ${cls}">${esc(labelMap[e] || e)}</span>`;
}

function mpDebFmtMonto(v) {
  if (v == null || v === '' || isNaN(Number(v))) return '—';
  return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

route('/mercadopagodebitos', async (mount) => {
  mount.innerHTML = `
    <div class="section">
      <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
        <button type="button" class="btn btn-primary btn-icon" title="Volver a Mercadopago" onclick="location.hash='#/mercadopago'">
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div style="font-size:1.6rem;line-height:1">📉</div>
        <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
          Los débitos son cada ejecución de cobro que Mercadopago aplica sobre una
          suscripción — con cuenta, suscripción, referencia, monto, número de
          operación y el resultado del cobro (aprobado, rechazado, pendiente).
        </div>
      </div>

      <div class="stats-bar" id="mpDebStats">
        <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Aprobados</span><span class="stat-value">—</span></div>
        <div class="stat-card"><span class="stat-label">Monto cobrado</span><span class="stat-value orange">—</span></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
          <div class="search-wrap">
            <input type="search" class="search-input" id="mpDebSearch"
                   placeholder="🔍 Buscar UUID, referencia, concepto u operación…">
            <button class="search-clear" id="mpDebSearchClear" style="display:none">×</button>
          </div>
          <button class="btn btn-ghost btn-icon" id="mpDebFiltrosBtn" title="Filtros">
            <i class="fa-solid fa-filter"></i>
            <span class="btn-icon-badge" id="mpDebFiltrosBadge" style="display:none">0</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="mpDebRefrescarBtn" title="Refrescar">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" id="mpDebNuevoBtn">+ Nuevo débito</button>
        </div>
      </div>

      <div class="table-card">
        <table>
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Cuenta</th>
              <th>Suscripción</th>
              <th>Referencia</th>
              <th>Concepto</th>
              <th style="text-align:right">Monto</th>
              <th>Operación</th>
              <th>Estado</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="mpDebTbody">
            <tr><td colspan="10" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div id="mpDebCtxMenu" class="ctx-menu" role="menu">
      <button type="button" data-action="consultar" role="menuitem">
        <i class="fa-solid fa-eye"></i><span>Consultar</span>
      </button>
      <div class="ctx-menu-sep"></div>
      <button type="button" data-action="editar" role="menuitem">
        <i class="fa-solid fa-pen"></i><span>Editar</span>
      </button>
      <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
        <i class="fa-solid fa-trash"></i><span>Eliminar</span>
      </button>
    </div>

    <div class="modal-backdrop" id="filtrosMpDebBackdrop"
         onclick="if(event.target===this)cancelarFiltrosMpDeb()">
      <div class="modal" style="max-width:620px">
        <div class="modal-header">
          <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
          <button class="btn btn-ghost" onclick="cancelarFiltrosMpDeb()" title="Cerrar">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group">
              <label>Código</label>
              <input type="number" id="fMpDebCodigo" min="1" placeholder="ID …" oninput="onFiltroMpDeb('codigo', this.value)">
            </div>
            <div class="form-group">
              <label>Estado</label>
              <select id="fMpDebEstado" onchange="onFiltroMpDeb('estado', this.value)">
                <option value="">— Todos —</option>
                <option value="A">Aprobado</option>
                <option value="P">Pendiente</option>
                <option value="R">Rechazado</option>
                <option value="C">Cancelado</option>
                <option value="X">Anulado</option>
              </select>
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Cuenta (ID)</label>
              <input type="number" id="fMpDebCuenta" min="1" oninput="onFiltroMpDeb('cuenta', this.value)">
            </div>
            <div class="form-group">
              <label>Suscripción (ID)</label>
              <input type="number" id="fMpDebSuscripcion" min="1" oninput="onFiltroMpDeb('suscripcion', this.value)">
            </div>
            <div class="form-group">
              <label>Recibo (ID)</label>
              <input type="number" id="fMpDebRecibo" min="1" oninput="onFiltroMpDeb('recibo', this.value)">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Desde</label>
              <input type="date" id="fMpDebDesde" onchange="onFiltroMpDeb('desde', this.value)">
            </div>
            <div class="form-group">
              <label>Hasta</label>
              <input type="date" id="fMpDebHasta" onchange="onFiltroMpDeb('hasta', this.value)">
            </div>
          </div>
          <div class="form-row form-row-3">
            <div class="form-group">
              <label>Límite</label>
              <input type="number" id="fMpDebLimite" min="1" max="1000" value="100" onchange="onFiltroMpDeb('limite', this.value)">
            </div>
            <div class="form-group">
              <label>Ordenar por</label>
              <select id="fMpDebOrderBy" onchange="onFiltroMpDeb('order_by', this.value)">
                <option value="id">Código</option>
                <option value="fecha">Fecha</option>
                <option value="cuenta">Cuenta</option>
                <option value="suscripcion">Suscripción</option>
                <option value="referencia">Referencia</option>
                <option value="recibo">Recibo</option>
                <option value="concepto">Concepto</option>
                <option value="monto">Monto</option>
                <option value="operacion">Operación</option>
                <option value="estado">Estado</option>
              </select>
            </div>
            <div class="form-group">
              <label>Dirección</label>
              <select id="fMpDebDir" onchange="onFiltroMpDeb('dir', this.value)">
                <option value="desc">Descendente</option>
                <option value="asc">Ascendente</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost"   onclick="cancelarFiltrosMpDeb()">Cerrar</button>
          <button class="btn btn-ghost"   onclick="limpiarFiltrosMpDeb()">Limpiar</button>
          <button class="btn btn-primary" onclick="cerrarModalFiltrosMpDeb()">Aplicar</button>
        </div>
      </div>
    </div>
  `;

  $('#mpDebNuevoBtn').addEventListener('click', () => abrirAltaEdicionMpDeb(null));
  $('#mpDebFiltrosBtn').addEventListener('click', () => abrirModalFiltrosMpDeb());
  $('#mpDebRefrescarBtn').addEventListener('click', () => cargarMpDeb());

  const inp = $('#mpDebSearch');
  const clr = $('#mpDebSearchClear');
  inp.value = mpDebFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    mpDebFiltros.q = inp.value.trim();
    clearTimeout(mpDebBuscadorTimer);
    mpDebBuscadorTimer = setTimeout(() => { cargarMpDeb(); refrescarBadgeFiltrosMpDeb(); }, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    mpDebFiltros.q = '';
    cargarMpDeb();
    refrescarBadgeFiltrosMpDeb();
  });

  $('#mpDebCtxMenu').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const data = getCtxMenuData();
    if (!data) return;
    cerrarCtxMenu();
    if (b.dataset.action === 'consultar') abrirConsultarMpDeb(data.id);
    if (b.dataset.action === 'editar')    abrirAltaEdicionMpDeb(data.id);
    if (b.dataset.action === 'eliminar')  eliminarMpDeb(data.id);
  });

  $('#mpDebTbody').addEventListener('click', (ev) => {
    const ham = ev.target.closest('[data-act="menu"]');
    if (ham) {
      ev.stopPropagation();
      const id = Number(ham.dataset.id);
      const r  = ham.getBoundingClientRect();
      abrirCtxMenu($('#mpDebCtxMenu'), r.right - 190, r.bottom + 4, { id });
      return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    abrirConsultarMpDeb(Number(tr.dataset.id));
  });
  $('#mpDebTbody').addEventListener('contextmenu', (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    ev.preventDefault();
    abrirCtxMenu($('#mpDebCtxMenu'), ev.clientX, ev.clientY, { id: Number(tr.dataset.id) });
  });

  refrescarBadgeFiltrosMpDeb();
  await cargarMpDeb();
}, 'Débitos');

async function cargarMpDeb() {
  const tbody = $('#mpDebTbody');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(mpDebFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/mercadopagodebitos.php?' + qs.toString());
    pintarStatsMpDeb(data.stats);
    pintarTablaMpDeb(data.items || []);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="10" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
  }
}

function pintarStatsMpDeb(s) {
  const cards = $$('#mpDebStats .stat-card .stat-value');
  if (cards.length < 3) return;
  cards[0].textContent = fmtNum(s.total);
  cards[1].textContent = fmtNum(s.aprobados);
  cards[2].textContent = mpDebFmtMonto(s.monto_cobrado);
}

function pintarTablaMpDeb(rows) {
  const tbody = $('#mpDebTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="10" class="table-empty">Sin débitos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((d) => `
    <tr data-id="${d.id}" class="row-clickable">
      <td class="td-id">#${esc(d.id)}</td>
      <td style="font-family:monospace;white-space:nowrap">${esc(fmtFechaLarga(d.fecha))}</td>
      <td>${d.cuenta      != null ? '#' + esc(d.cuenta)      : '—'}</td>
      <td>${d.suscripcion != null ? '#' + esc(d.suscripcion) : '—'}</td>
      <td style="font-family:monospace">${esc(d.referencia || '—')}</td>
      <td>${esc(d.concepto || '—')}</td>
      <td style="text-align:right;font-family:monospace">${mpDebFmtMonto(d.monto)}</td>
      <td style="font-family:monospace">${esc(d.operacion || '—')}</td>
      <td>${mpDebEstadoBadge(d.estado)}</td>
      <td style="text-align:center">
        <div class="actions" style="justify-content:center">
          <button class="btn-icon-sm" title="Más acciones" data-act="menu" data-id="${d.id}">
            <i class="fa-solid fa-bars"></i>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function onFiltroMpDeb(key, value) {
  if (['estado', 'order_by', 'dir', 'desde', 'hasta'].includes(key)) {
    mpDebFiltros[key] = value;
  } else if (['codigo', 'cuenta', 'suscripcion', 'recibo'].includes(key)) {
    const v = String(value).trim();
    mpDebFiltros[key] = v === '' ? '' : Math.max(0, Number(v) || 0);
  } else if (key === 'limite') {
    let n = Number(value); if (!n || n < 1) n = 1; if (n > 1000) n = 1000;
    mpDebFiltros.limite = n;
  } else {
    mpDebFiltros[key] = value;
  }
  refrescarBadgeFiltrosMpDeb();
  cargarMpDeb();
}

function refrescarBadgeFiltrosMpDeb() {
  const btn   = $('#mpDebFiltrosBtn');
  const badge = $('#mpDebFiltrosBadge');
  if (!btn || !badge) return;
  let count = 0;
  for (const k of Object.keys(mpDebFiltrosDefaults)) {
    if (k === 'q') continue;
    if (String(mpDebFiltros[k]) !== String(mpDebFiltrosDefaults[k])) count++;
  }
  if (count > 0) { btn.classList.add('active'); badge.textContent = String(count); badge.style.display = ''; }
  else           { btn.classList.remove('active'); badge.style.display = 'none'; }
}

function sincronizarControlesFiltrosMpDeb() {
  const f = mpDebFiltros;
  $('#fMpDebCodigo').value      = f.codigo;
  $('#fMpDebEstado').value      = f.estado;
  $('#fMpDebCuenta').value      = f.cuenta;
  $('#fMpDebSuscripcion').value = f.suscripcion;
  $('#fMpDebRecibo').value      = f.recibo;
  $('#fMpDebDesde').value       = f.desde;
  $('#fMpDebHasta').value       = f.hasta;
  $('#fMpDebLimite').value      = f.limite;
  $('#fMpDebOrderBy').value     = f.order_by;
  $('#fMpDebDir').value         = f.dir;
}

function abrirModalFiltrosMpDeb() {
  mpDebFiltrosSnapshot = { ...mpDebFiltros };
  sincronizarControlesFiltrosMpDeb();
  $('#filtrosMpDebBackdrop').classList.add('open');
}
function cerrarModalFiltrosMpDeb() { $('#filtrosMpDebBackdrop').classList.remove('open'); }
function cancelarFiltrosMpDeb() {
  if (mpDebFiltrosSnapshot) {
    Object.assign(mpDebFiltros, mpDebFiltrosSnapshot);
    refrescarBadgeFiltrosMpDeb();
    cargarMpDeb();
  }
  cerrarModalFiltrosMpDeb();
}
function limpiarFiltrosMpDeb() {
  Object.assign(mpDebFiltros, mpDebFiltrosDefaults);
  mpDebFiltros.q = $('#mpDebSearch')?.value.trim() || '';
  sincronizarControlesFiltrosMpDeb();
  refrescarBadgeFiltrosMpDeb();
  cargarMpDeb();
}
window.onFiltroMpDeb           = onFiltroMpDeb;
window.cancelarFiltrosMpDeb    = cancelarFiltrosMpDeb;
window.limpiarFiltrosMpDeb     = limpiarFiltrosMpDeb;
window.cerrarModalFiltrosMpDeb = cerrarModalFiltrosMpDeb;

async function abrirConsultarMpDeb(id) {
  openModal(`
    <div class="modal" style="width:80vw;max-width:1100px">
      <div class="modal-header">
        <div class="modal-title">Débito Mercadopago <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cerrar</button>
        <button class="btn btn-primary" data-act="editar">✏️ Editar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]'))  closeModal();
    if (ev.target.closest('[data-act="editar"]')) { closeModal(); abrirAltaEdicionMpDeb(id); }
  });

  try {
    const d = await apiGet(`api/mercadopagodebitos.php?id=${id}`);
    $('#modalRoot .modal-body').innerHTML = renderConsultaMpDeb(d);
  } catch (e) {
    $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
  }
}

function renderConsultaMpDeb(d) {
  const card = (label, value, full = false, isCode = false) => {
    const empty = value == null || value === '';
    const inner = empty ? 'Sin dato'
                : isCode ? `<code>${esc(value)}</code>`
                : esc(value);
    return `
      <div class="data-row${full ? ' full' : ''}">
        <span class="data-label">${esc(label)}</span>
        <span class="data-value${empty ? ' muted' : ''}">${inner}</span>
      </div>`;
  };
  const seccion = (titulo) => `
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:6px 0 -4px">
      ${esc(titulo)}
    </div>`;

  return `
    <div style="padding:18px 20px;background:color-mix(in srgb, var(--surface) 90%, #000);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:1.3rem;font-weight:700">$ ${mpDebFmtMonto(d.monto)}</span>
          <span style="font-family:monospace;font-size:.95rem;color:var(--muted)">${esc(d.operacion || '')}</span>
        </div>
        <div style="font-size:.85rem;color:var(--muted);margin-top:6px">${esc(d.concepto || 'Sin concepto')}</div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:6px">#${esc(d.id)} · <code>${esc(d.uuid || '—')}</code></div>
      </div>
      <div style="text-align:right;min-width:200px;display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <div>${mpDebEstadoBadge(d.estado)}</div>
        <div style="margin-top:6px;font-size:.85rem"><span style="color:var(--muted)">Fecha:</span> ${esc(fmtFecha(d.fecha))}</div>
      </div>
    </div>

    ${seccion('Identificación')}
    <dl class="data-list" style="grid-template-columns:repeat(2,1fr)">
      ${card('Código',      d.id)}
      ${card('UUID',        d.uuid, false, true)}
      ${card('Cuenta',      d.cuenta)}
      ${card('Suscripción', d.suscripcion)}
      ${card('Referencia',  d.referencia, false, true)}
      ${card('Recibo',      d.recibo)}
    </dl>

    ${seccion('Cobro y estado')}
    <dl class="data-list" style="grid-template-columns:repeat(3,1fr)">
      ${card('Fecha',     fmtFecha(d.fecha))}
      ${card('Monto',     mpDebFmtMonto(d.monto))}
      ${card('Estado',    d.estado)}
      ${card('Concepto',  d.concepto)}
      ${card('Operación', d.operacion, false, true)}
    </dl>

    ${seccion('Propiedades')}
    <dl class="data-list" style="grid-template-columns:1fr">
      ${card('Propiedades', d.propiedades, true, true)}
    </dl>
  `;
}

async function abrirAltaEdicionMpDeb(id) {
  const esEdicion = id != null;
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">${esEdicion ? `Editar débito <span class="modal-subtitle">#${id}</span>` : 'Nuevo débito'}</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formMpDebHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const d = await apiGet(`api/mercadopagodebitos.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formMpDebHtml(d);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'guardar') await guardarMpDeb(id, a);
  });
}

function formMpDebHtml(d) {
  const v   = (k) => esc(d?.[k] ?? '');
  const sel = (k, val) => (d?.[k] ?? '') === val ? 'selected' : '';
  const dt  = (k) => {
    const raw = d?.[k];
    if (!raw) return '';
    return esc(String(raw).replace(' ', 'T').slice(0, 16));
  };
  return `
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>UUID</label>
        <input type="text" id="mpDebUuid" maxlength="50" value="${v('uuid')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Fecha</label>
        <input type="datetime-local" id="mpDebFecha" value="${dt('fecha')}">
      </div>
      <div class="form-group">
        <label>Estado</label>
        <select id="mpDebEstado">
          <option value=""  ${sel('estado','')}>—</option>
          <option value="A" ${sel('estado','A')}>Aprobado</option>
          <option value="P" ${sel('estado','P')}>Pendiente</option>
          <option value="R" ${sel('estado','R')}>Rechazado</option>
          <option value="C" ${sel('estado','C')}>Cancelado</option>
          <option value="X" ${sel('estado','X')}>Anulado</option>
        </select>
      </div>
    </div>
    <div class="form-row form-row-3">
      <div class="form-group">
        <label>Cuenta (ID)</label>
        <input type="number" id="mpDebCuenta" min="1" value="${v('cuenta')}">
      </div>
      <div class="form-group">
        <label>Suscripción (ID)</label>
        <input type="number" id="mpDebSuscripcion" min="1" value="${v('suscripcion')}">
      </div>
      <div class="form-group">
        <label>Recibo (ID)</label>
        <input type="number" id="mpDebRecibo" min="1" value="${v('recibo')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Referencia</label>
        <input type="text" id="mpDebReferencia" maxlength="100" value="${v('referencia')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Concepto</label>
        <input type="text" id="mpDebConcepto" maxlength="255" value="${v('concepto')}">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Monto</label>
        <input type="number" id="mpDebMonto" step="0.01" min="0" value="${v('monto')}" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Operación</label>
        <input type="text" id="mpDebOperacion" maxlength="255" value="${v('operacion')}" style="font-family:monospace">
      </div>
    </div>
    <div class="form-group">
      <label>Propiedades</label>
      <textarea id="mpDebPropiedades" rows="4" style="font-family:monospace">${v('propiedades')}</textarea>
    </div>
    <div class="field-error" id="mpDebFormError" style="display:none"></div>
  `;
}

async function guardarMpDeb(id, btn) {
  const err = $('#mpDebFormError');
  err.style.display = 'none';

  const payload = {
    uuid:        $('#mpDebUuid').value.trim(),
    cuenta:      $('#mpDebCuenta').value,
    suscripcion: $('#mpDebSuscripcion').value,
    referencia:  $('#mpDebReferencia').value.trim(),
    recibo:      $('#mpDebRecibo').value,
    fecha:       $('#mpDebFecha').value || null,
    concepto:    $('#mpDebConcepto').value.trim(),
    monto:       $('#mpDebMonto').value,
    operacion:   $('#mpDebOperacion').value.trim(),
    estado:      $('#mpDebEstado').value,
    propiedades: $('#mpDebPropiedades').value,
  };

  btn.disabled = true;
  try {
    if (id == null) {
      await apiSend('api/mercadopagodebitos.php', 'POST', payload);
      toast('Débito creado.');
    } else {
      await apiSend(`api/mercadopagodebitos.php?id=${id}`, 'PUT', payload);
      toast('Débito actualizado.');
    }
    closeModal();
    cargarMpDeb();
  } catch (e) {
    err.textContent = e.message;
    err.style.display = '';
    btn.disabled = false;
  }
}

async function eliminarMpDeb(id) {
  const ok = await confirmar({
    title: 'Eliminar débito',
    message: `Se eliminará el débito #${id}. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
  });
  if (!ok) return;
  try {
    await apiSend(`api/mercadopagodebitos.php?id=${id}`, 'DELETE');
    toast('Débito eliminado.');
    cargarMpDeb();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// ------------------------- Chrome (sidebar / topbar / dropdown) -------------------------
function bindChrome() {
  // hamburger
  $('#hamburger')?.addEventListener('click', () => {
    $('#sidebar').classList.toggle('open');
    $('#sidebarOverlay').classList.toggle('active');
  });
  $('#sidebarOverlay')?.addEventListener('click', () => {
    $('#sidebar').classList.remove('open');
    $('#sidebarOverlay').classList.remove('active');
  });

  // toggle de grupos colapsables del sidebar
  $$('.nav-group-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      btn.closest('.nav-group-wrap').classList.toggle('open');
    });
  });

  // user dropdown
  const userBtn = $('#userBtn');
  const userDd  = $('#userDropdown');
  userBtn?.addEventListener('click', (ev) => {
    ev.stopPropagation();
    userDd.classList.toggle('open');
  });
  document.addEventListener('click', (ev) => {
    if (!userDd) return;
    if (!userDd.contains(ev.target) && ev.target !== userBtn) {
      userDd.classList.remove('open');
    }
  });
}

// ------------------------- Auth -------------------------
// AUTH.user: perfil basico del usuario logueado (id, uuid, nombre, correo).
// AUTH.perms: Set<string> con los slugs de permisos efectivos, computados por
//             el backend en cada respuesta de /login y /me (no viven en el JWT).
// Los helpers `hasPermission()` y `aplicarPermisosSidebar()` leen de AUTH.perms.
const AUTH = { user: null, perms: new Set() };

function hasPermission(slug) {
  return AUTH.perms.has(slug);
}

// Devuelve true si el usuario tiene AL MENOS UN permiso cuyo slug empieza con
// el prefijo indicado. Se usa para los items del sidebar que son "landings" de
// una plataforma con sub-modulos (ej. Plataformas > Evolution API, cuya visibilidad
// depende de tener acceso a canales, contactos o mensajes).
function hasPermissionPrefix(prefix) {
  for (const p of AUTH.perms) if (p.startsWith(prefix)) return true;
  return false;
}

async function checkSession() {
  try {
    const r = await fetch('api/auth.php?action=me', { credentials: 'same-origin' });
    if (!r.ok) return null;
    const j = await r.json().catch(() => null);
    if (!j || !j.ok) return null;
    AUTH.perms = new Set(j.data.perms || []);
    return j.data.user;
  } catch {
    return null;
  }
}

function showLoginScreen() {
  document.body.classList.remove('auth-checking', 'is-app');
  document.body.classList.add('is-login');
  $('#loginScreen').hidden = false;
  const correo = $('#loginCorreo');
  const pass   = $('#loginContrasena');
  (correo.value ? pass : correo).focus();
}

function showAppShell(user) {
  AUTH.user = user;
  document.body.classList.remove('auth-checking', 'is-login');
  document.body.classList.add('is-app');
  $('#loginScreen').hidden = true;
  $('#userBtnName').textContent = user?.nombre || user?.correo || '—';
  aplicarPermisosSidebar();
  setupEnlacesMenu();
}

// Recorre el sidebar y oculta los items para los que el usuario no tiene el
// permiso declarado en `data-perm` (slug exacto) o `data-perm-prefix` (any-of
// para landings de plataformas y para Herramientas). Despues oculta el grupo
// padre completo si todos sus hijos quedaron ocultos, para no dejar toggles
// vacios en el sidebar.
//
// Aclaracion: esta filtracion es SOLO UX — no es un gate de seguridad real.
// La proxima fase debe agregar el check en cada endpoint (`requirePermission`)
// y route-guards en el SPA. Un usuario podria tipear el hash a mano y ver la
// vista igual (aunque el API le negara la data si el endpoint estuviese gateado).
function aplicarPermisosSidebar() {
  $$('.sidebar-nav .nav-sub-item').forEach((el) => {
    const perm    = el.getAttribute('data-perm');
    const prefijo = el.getAttribute('data-perm-prefix');
    let visible;
    if (perm)         visible = hasPermission(perm);
    else if (prefijo) visible = hasPermissionPrefix(prefijo);
    else              visible = true; // items sin declarar quedan visibles (compat)
    el.style.display = visible ? '' : 'none';
  });

  $$('.sidebar-nav .nav-group-wrap').forEach((grupo) => {
    const hijosVisibles = $$('.nav-sub-item', grupo).some((el) => el.style.display !== 'none');
    grupo.style.display = hijosVisibles ? '' : 'none';
  });
}

// ------------------------- Launcher de enlaces (topbar) -------------------------
// Menú cascada de 2 columnas anclado al botón "Enlaces" de la topbar.
// Flujo: 1 click abre el panel → hover sobre categoría muestra sus items a la
// derecha → 1 click en el item lo abre en pestaña nueva y cierra el panel.
let _enlacesMenuInit = false;

function setupEnlacesMenu() {
  if (_enlacesMenuInit) return;
  const btn     = document.getElementById('enlacesBtn');
  const menu    = document.getElementById('enlacesMenu');
  const catsEl  = document.getElementById('enlacesMenuCats');
  const itemsEl = document.getElementById('enlacesMenuItems');
  if (!btn || !menu || !catsEl || !itemsEl) return;
  _enlacesMenuInit = true;

  // Aplano las 2 fuentes en una sola lista de categorías con un separador visual.
  const cats = [
    { kind: 'header', label: 'Plataformas' },
    ...PLATAFORMAS_GRUPOS,
    { kind: 'header', label: 'Herramientas web' },
    ...UTILIDADES_GRUPOS,
  ];
  // Mapa idx → índice dentro de PLATAFORMAS_GRUPOS/UTILIDADES_GRUPOS para no
  // depender de la posición absoluta en `cats` (headers desplazan índices).
  catsEl.innerHTML = cats.map((c, i) => {
    if (c.kind === 'header') {
      return `<div class="enlaces-menu-cat-header">${esc(c.label)}</div>`;
    }
    return `
      <button type="button" class="enlaces-menu-item" data-cat="${i}" role="menuitem">
        <span class="enlaces-menu-arrow">◀</span>
        <span class="enlaces-menu-icon">${c.icono}</span>
        <span class="enlaces-menu-item-label">${esc(c.label)}</span>
      </button>`;
  }).join('');

  let hoverTimer = null;
  let activeIdx  = -1;

  const mostrarCategoria = (idx) => {
    if (idx === activeIdx) return;
    const cat = cats[idx];
    if (!cat || cat.kind === 'header') return;
    activeIdx = idx;
    catsEl.querySelectorAll('[data-cat]').forEach((el) => {
      el.classList.toggle('active', Number(el.dataset.cat) === idx);
    });
    itemsEl.innerHTML = cat.items.map((it) => `
      <a href="${esc(it.url)}" target="_blank" rel="noopener noreferrer"
         class="enlaces-menu-item" data-item title="${esc(it.desc)}">
        <span class="enlaces-menu-icon">${it.icono}</span>
        <span class="enlaces-menu-item-label">${esc(it.titulo)}</span>
      </a>
    `).join('');
    itemsEl.scrollTop = 0;
  };

  // Hover con delay corto para no dispararlo al pasar rápido de largo.
  catsEl.addEventListener('mouseover', (ev) => {
    const el = ev.target.closest('[data-cat]');
    if (!el) return;
    clearTimeout(hoverTimer);
    const idx = Number(el.dataset.cat);
    hoverTimer = setTimeout(() => mostrarCategoria(idx), 80);
  });
  // Click en categoría → adelanta el timer del hover (útil en touch / accesibilidad).
  catsEl.addEventListener('click', (ev) => {
    const el = ev.target.closest('[data-cat]');
    if (!el) return;
    clearTimeout(hoverTimer);
    mostrarCategoria(Number(el.dataset.cat));
  });

  const primeraCategoriaIdx = cats.findIndex((c) => c.kind !== 'header');

  const abrirMenu = () => {
    menu.classList.add('open');
    btn.classList.add('open');
    activeIdx = -1;
    if (primeraCategoriaIdx >= 0) mostrarCategoria(primeraCategoriaIdx);
  };
  const cerrarMenu = () => {
    menu.classList.remove('open');
    btn.classList.remove('open');
    clearTimeout(hoverTimer);
  };

  btn.addEventListener('click', (ev) => {
    ev.stopPropagation();
    if (menu.classList.contains('open')) cerrarMenu(); else abrirMenu();
  });
  itemsEl.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-item]')) cerrarMenu();
  });
  document.addEventListener('click', (ev) => {
    if (!menu.classList.contains('open')) return;
    if (menu.contains(ev.target) || btn.contains(ev.target)) return;
    cerrarMenu();
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && menu.classList.contains('open')) cerrarMenu();
    // Alt+E como acelerador de teclado.
    if (ev.altKey && !ev.ctrlKey && !ev.metaKey && ev.key.toLowerCase() === 'e') {
      ev.preventDefault();
      menu.classList.contains('open') ? cerrarMenu() : abrirMenu();
    }
  });
}

function bindLogin() {
  const form   = $('#loginForm');
  const err    = $('#loginError');
  const submit = $('#loginSubmit');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    err.textContent = '';
    const correo     = $('#loginCorreo').value.trim();
    const contrasena = $('#loginContrasena').value;
    if (!correo || !contrasena) {
      err.textContent = 'Correo y contraseña son obligatorios.';
      err.hidden = false;
      return;
    }
    submit.disabled = true;
    try {
      const r = await fetch('api/auth.php?action=login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ correo, contrasena }),
      });
      const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta no JSON' }));
      if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
      AUTH.perms = new Set(j.data.perms || []);
      showAppShell(j.data.user);
      $('#loginContrasena').value = '';
      if (!location.hash) location.hash = '#/dashboard';
      render();
    } catch (e) {
      err.textContent = e.message;
      err.hidden = false;
    } finally {
      submit.disabled = false;
    }
  });
}

async function doLogout() {
  try {
    await fetch('api/auth.php?action=logout', {
      method: 'POST',
      credentials: 'same-origin',
    });
  } catch { /* ignorar */ }
  AUTH.user  = null;
  AUTH.perms = new Set();
  $('#userDropdown')?.classList.remove('open');
  $('#view').innerHTML = '';
  showLoginScreen();
}

// ------------------------- Herramientas: Editor de parámetros -------------------------
// Editor de parámetros runtime. Sobre la tabla `parametros` (columnas
// `variable` / `valor` / `comentario`) compartida con otras apps del grupo.
let parametrosCache         = [];
let parametrosFiltroQ       = '';
let parametrosCtxRegistroId = null;
let _parametrosSearchTimer  = null;
let _parametrosGuardando    = false;

function abrirParametros() {
  document.getElementById('parametrosBackdrop').classList.add('open');
  cargarParametros();
}

function cerrarParametros() {
  document.getElementById('parametrosBackdrop').classList.remove('open');
  parametrosCerrarMenu();
}

function parametrosOnSearch(v) {
  parametrosFiltroQ = String(v ?? '');
  const clearBtn = document.getElementById('parametrosSearchClear');
  if (clearBtn) clearBtn.style.display = parametrosFiltroQ ? '' : 'none';
  clearTimeout(_parametrosSearchTimer);
  _parametrosSearchTimer = setTimeout(cargarParametros, 250);
}

function parametrosLimpiarBusqueda() {
  parametrosFiltroQ = '';
  const input = document.getElementById('parametrosSearch');
  if (input) input.value = '';
  document.getElementById('parametrosSearchClear').style.display = 'none';
  cargarParametros();
}

async function cargarParametros() {
  const tbody = document.getElementById('parametrosTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  const params = new URLSearchParams();
  if (parametrosFiltroQ) params.set('q', parametrosFiltroQ);
  params.set('limite', '500');
  params.set('order_by', 'variable');
  params.set('dir', 'asc');

  try {
    const data = await apiGet('api/parametros.php?' + params.toString());
    parametrosCache = data.items || [];
    renderParametros(parametrosCache);
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="5" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function renderParametros(rows) {
  const tbody = document.getElementById('parametrosTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="table-empty">Sin parámetros para mostrar.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((p) => {
    const variable   = esc(p.variable || '');
    const valor      = esc(p.valor    || '');
    const comentario = esc(p.comentario || '');
    return `
      <tr class="row-clickable" data-id="${p.id}"
          onclick="abrirEditarParametro(${p.id})"
          oncontextmenu="event.preventDefault();parametrosAbrirCtx(event, ${p.id})">
        <td class="td-id">${p.id}</td>
        <td style="font-family:monospace;font-weight:600">${variable}</td>
        <td title="${valor}"
            style="font-family:monospace;color:var(--muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          ${valor || '<span style="color:var(--muted);font-style:italic">— vacío —</span>'}
        </td>
        <td style="font-size:.82rem;color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="${comentario}">
          ${comentario}
        </td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center">
            <button class="btn-icon-sm" title="Más acciones"
                    onclick="event.stopPropagation();parametrosAbrirCtx(event, ${p.id})">
              <i class="fa-solid fa-bars"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function abrirNuevoParametro() {
  limpiarErroresFormParametro();
  document.getElementById('formParametroTitulo').innerHTML =
    '<span style="font-size:1.2rem">🧩</span><span>Nuevo parámetro</span>';
  document.getElementById('formParametroId').value         = '';
  document.getElementById('formParametroVariable').value   = '';
  document.getElementById('formParametroValor').value      = '';
  document.getElementById('formParametroComentario').value = '';
  document.getElementById('formParametroBackdrop').classList.add('open');
  setTimeout(() => document.getElementById('formParametroVariable').focus(), 50);
}

function abrirEditarParametro(id) {
  const p = parametrosCache.find((x) => x.id === id);
  if (!p) { toast('No se encontró el parámetro.', { error: true }); return; }
  limpiarErroresFormParametro();
  document.getElementById('formParametroTitulo').innerHTML =
    '<span style="font-size:1.2rem">🧩</span><span>Editar parámetro</span>';
  document.getElementById('formParametroId').value         = p.id;
  document.getElementById('formParametroVariable').value   = p.variable   || '';
  document.getElementById('formParametroValor').value      = p.valor      || '';
  document.getElementById('formParametroComentario').value = p.comentario || '';
  document.getElementById('formParametroBackdrop').classList.add('open');
  setTimeout(() => document.getElementById('formParametroValor').focus(), 50);
}

function limpiarErroresFormParametro() {
  ['Variable', 'Valor', 'Comentario'].forEach((c) => {
    const input = document.getElementById('formParametro' + c);
    const err   = document.getElementById('formParametro' + c + 'Error');
    if (input) input.classList.remove('input-invalid');
    if (err)   { err.style.display = 'none'; err.textContent = ''; }
  });
}

function mostrarErrorParametro(campo, msg) {
  const input = document.getElementById('formParametro' + campo);
  const err   = document.getElementById('formParametro' + campo + 'Error');
  if (input) { input.classList.add('input-invalid'); input.focus(); }
  if (err)   { err.style.display = ''; err.textContent = msg; }
}

async function guardarParametro() {
  if (_parametrosGuardando) return;
  limpiarErroresFormParametro();

  const idRaw      = document.getElementById('formParametroId').value;
  const id         = idRaw ? parseInt(idRaw, 10) : 0;
  const variable   = document.getElementById('formParametroVariable').value.trim();
  const valor      = document.getElementById('formParametroValor').value;
  const comentario = document.getElementById('formParametroComentario').value.trim();

  if (!variable) {
    mostrarErrorParametro('Variable', 'La variable es obligatoria.');
    return;
  }
  if (!/^[A-Za-z0-9_.\-]+$/.test(variable)) {
    mostrarErrorParametro('Variable', 'Sólo letras, números, punto, guión y guión bajo.');
    return;
  }
  if (variable.length > 255) {
    mostrarErrorParametro('Variable', 'Máximo 255 caracteres.');
    return;
  }
  if (valor.length > 255) {
    mostrarErrorParametro('Valor', 'Máximo 255 caracteres.');
    return;
  }
  if (comentario.length > 1024) {
    mostrarErrorParametro('Comentario', 'Máximo 1024 caracteres.');
    return;
  }

  const btn = document.getElementById('btnGuardarParametro');
  _parametrosGuardando = true;
  if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }

  try {
    const payload = { variable, valor, comentario };
    if (id > 0) {
      await apiSend('api/parametros.php?id=' + id, 'PUT', payload);
      toast('Parámetro actualizado.');
    } else {
      await apiSend('api/parametros.php', 'POST', payload);
      toast('Parámetro creado.');
    }
    document.getElementById('formParametroBackdrop').classList.remove('open');
    cargarParametros();
  } catch (e) {
    const msg = e.message || 'Error al guardar.';
    if (/ya existe/i.test(msg)) {
      mostrarErrorParametro('Variable', msg);
    } else {
      toast(msg, { error: true });
    }
  } finally {
    _parametrosGuardando = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
  }
}

async function eliminarParametro(id) {
  const p = parametrosCache.find((x) => x.id === id);
  if (!p) return;
  const ok = await confirmar({
    title: 'Eliminar parámetro',
    message: `Vas a eliminar el parámetro «${p.variable}». Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiSend('api/parametros.php?id=' + id, 'DELETE');
    toast('Parámetro eliminado.');
    cargarParametros();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

function parametrosAbrirCtx(ev, id) {
  parametrosCtxRegistroId = id;
  const menu = document.getElementById('parametrosCtxMenu');
  if (!menu) return;
  let x = ev.clientX, y = ev.clientY;
  if ((!x && !y) && ev.currentTarget && ev.currentTarget.getBoundingClientRect) {
    const r = ev.currentTarget.getBoundingClientRect();
    x = r.right; y = r.bottom;
  }
  abrirCtxMenu(menu, x, y, { id });
}

function parametrosCerrarMenu() {
  parametrosCtxRegistroId = null;
  cerrarCtxMenu();
}

document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('parametrosCtxMenu');
  if (!menu) return;
  menu.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-action');
    const id     = parametrosCtxRegistroId;
    parametrosCerrarMenu();
    if (!id) return;
    if (action === 'editar') {
      abrirEditarParametro(id);
    } else if (action === 'eliminar') {
      eliminarParametro(id);
    } else if (action === 'copiar-variable') {
      const p = parametrosCache.find((x) => x.id === id);
      if (p && navigator.clipboard) {
        navigator.clipboard.writeText(p.variable).then(
          () => toast('Variable copiada.'),
          () => toast('No se pudo copiar.', { error: true }),
        );
      }
    }
  });
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const ctx     = document.getElementById('parametrosCtxMenu');
  const form    = document.getElementById('formParametroBackdrop');
  const listado = document.getElementById('parametrosBackdrop');
  if (ctx && ctx.classList.contains('open')) { parametrosCerrarMenu(); return; }
  if (form && form.classList.contains('open')) { form.classList.remove('open'); return; }
  if (listado && listado.classList.contains('open')) { cerrarParametros(); }
});

// ------------------------- Herramientas: Editor de estados -------------------------
// CRUD sobre la tabla `estados` (id / campo / texto / valor / orden). Cada fila
// mapea un `valor` crudo guardado en `<campo>` (formato `tabla.columna`) con su
// `texto` amigable. Unicidad logica por (campo, valor) — enforce en backend.
let estadosCache         = [];
let estadosCampos        = [];
let estadosFiltroQ       = '';
let estadosFiltroCampo   = '';
let estadosCtxRegistroId = null;
let _estadosSearchTimer  = null;
let _estadosGuardando    = false;

function abrirEstados() {
  document.getElementById('estadosBackdrop').classList.add('open');
  cargarEstados();
}

function cerrarEstados() {
  document.getElementById('estadosBackdrop').classList.remove('open');
  estadosCerrarMenu();
}

function estadosOnSearch(v) {
  estadosFiltroQ = String(v ?? '');
  const clearBtn = document.getElementById('estadosSearchClear');
  if (clearBtn) clearBtn.style.display = estadosFiltroQ ? '' : 'none';
  clearTimeout(_estadosSearchTimer);
  _estadosSearchTimer = setTimeout(cargarEstados, 250);
}

function estadosLimpiarBusqueda() {
  estadosFiltroQ = '';
  const input = document.getElementById('estadosSearch');
  if (input) input.value = '';
  document.getElementById('estadosSearchClear').style.display = 'none';
  cargarEstados();
}

async function cargarEstados() {
  const tbody = document.getElementById('estadosTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  estadosFiltroCampo = document.getElementById('estadosCampoFiltro').value || '';

  const params = new URLSearchParams();
  if (estadosFiltroQ)     params.set('q',     estadosFiltroQ);
  if (estadosFiltroCampo) params.set('campo', estadosFiltroCampo);
  params.set('limite', '2000');
  params.set('order_by', 'campo_orden');
  params.set('dir', 'asc');

  try {
    const data = await apiGet('api/estados.php?' + params.toString());
    estadosCache  = data.items  || [];
    estadosCampos = data.campos || [];
    estadosRefrescarCombos();
    renderEstados(estadosCache);
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function estadosRefrescarCombos() {
  const sel = document.getElementById('estadosCampoFiltro');
  if (sel) {
    const actual = sel.value;
    sel.innerHTML = '<option value="">— Todos los campos —</option>' +
      estadosCampos.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    if (actual && estadosCampos.includes(actual)) sel.value = actual;
  }
  const dl = document.getElementById('formEstadoCampoLista');
  if (dl) {
    dl.innerHTML = estadosCampos.map((c) => `<option value="${esc(c)}">`).join('');
  }
}

function renderEstados(rows) {
  const tbody = document.getElementById('estadosTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty">Sin estados para mostrar.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((s) => {
    const campo = esc(s.campo || '');
    const valor = esc(s.valor || '');
    const texto = esc(s.texto || '');
    const orden = s.orden == null ? '<span style="color:var(--muted)">—</span>' : String(s.orden);
    return `
      <tr class="row-clickable" data-id="${s.id}"
          onclick="abrirEditarEstado(${s.id})"
          oncontextmenu="event.preventDefault();estadosAbrirCtx(event, ${s.id})">
        <td class="td-id">${s.id}</td>
        <td style="font-family:monospace;font-weight:600">${campo}</td>
        <td style="font-family:monospace">${valor || '<span style="color:var(--muted);font-style:italic">— vacío —</span>'}</td>
        <td>${texto}</td>
        <td style="text-align:center;color:var(--muted);font-family:monospace">${orden}</td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center">
            <button class="btn-icon-sm" title="Más acciones"
                    onclick="event.stopPropagation();estadosAbrirCtx(event, ${s.id})">
              <i class="fa-solid fa-bars"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function abrirNuevoEstado() {
  limpiarErroresFormEstado();
  document.getElementById('formEstadoTitulo').innerHTML =
    '<span style="font-size:1.2rem">🎚️</span><span>Nuevo estado</span>';
  document.getElementById('formEstadoId').value    = '';
  document.getElementById('formEstadoCampo').value = estadosFiltroCampo || '';
  document.getElementById('formEstadoValor').value = '';
  document.getElementById('formEstadoTexto').value = '';
  document.getElementById('formEstadoOrden').value = '';
  document.getElementById('formEstadoBackdrop').classList.add('open');
  const focusTarget = estadosFiltroCampo ? 'formEstadoValor' : 'formEstadoCampo';
  setTimeout(() => document.getElementById(focusTarget).focus(), 50);
}

function abrirEditarEstado(id) {
  const s = estadosCache.find((x) => x.id === id);
  if (!s) { toast('No se encontró el estado.', { error: true }); return; }
  limpiarErroresFormEstado();
  document.getElementById('formEstadoTitulo').innerHTML =
    '<span style="font-size:1.2rem">🎚️</span><span>Editar estado</span>';
  document.getElementById('formEstadoId').value    = s.id;
  document.getElementById('formEstadoCampo').value = s.campo || '';
  document.getElementById('formEstadoValor').value = s.valor || '';
  document.getElementById('formEstadoTexto').value = s.texto || '';
  document.getElementById('formEstadoOrden').value = s.orden == null ? '' : s.orden;
  document.getElementById('formEstadoBackdrop').classList.add('open');
  setTimeout(() => document.getElementById('formEstadoTexto').focus(), 50);
}

function limpiarErroresFormEstado() {
  ['Campo', 'Valor', 'Texto', 'Orden'].forEach((c) => {
    const input = document.getElementById('formEstado' + c);
    const err   = document.getElementById('formEstado' + c + 'Error');
    if (input) input.classList.remove('input-invalid');
    if (err)   { err.style.display = 'none'; err.textContent = ''; }
  });
}

function mostrarErrorEstado(campo, msg) {
  const input = document.getElementById('formEstado' + campo);
  const err   = document.getElementById('formEstado' + campo + 'Error');
  if (input) { input.classList.add('input-invalid'); input.focus(); }
  if (err)   { err.style.display = ''; err.textContent = msg; }
}

async function guardarEstado() {
  if (_estadosGuardando) return;
  limpiarErroresFormEstado();

  const idRaw    = document.getElementById('formEstadoId').value;
  const id       = idRaw ? parseInt(idRaw, 10) : 0;
  const campo    = document.getElementById('formEstadoCampo').value.trim();
  const valor    = document.getElementById('formEstadoValor').value;
  const texto    = document.getElementById('formEstadoTexto').value.trim();
  const ordenRaw = document.getElementById('formEstadoOrden').value.trim();

  if (!campo) {
    mostrarErrorEstado('Campo', 'El campo es obligatorio.');
    return;
  }
  if (!/^[A-Za-z0-9_.\-]+$/.test(campo)) {
    mostrarErrorEstado('Campo', 'Sólo letras, números, punto, guión y guión bajo (ej. tabla.columna).');
    return;
  }
  if (campo.length > 255) {
    mostrarErrorEstado('Campo', 'Máximo 255 caracteres.');
    return;
  }
  if (!texto) {
    mostrarErrorEstado('Texto', 'El texto es obligatorio.');
    return;
  }
  if (texto.length > 255) {
    mostrarErrorEstado('Texto', 'Máximo 255 caracteres.');
    return;
  }
  if (valor.length > 255) {
    mostrarErrorEstado('Valor', 'Máximo 255 caracteres.');
    return;
  }
  if (ordenRaw !== '' && !/^-?\d+$/.test(ordenRaw)) {
    mostrarErrorEstado('Orden', 'Debe ser un número entero.');
    return;
  }

  const btn = document.getElementById('btnGuardarEstado');
  _estadosGuardando = true;
  if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }

  try {
    const payload = {
      campo,
      valor,
      texto,
      orden: ordenRaw === '' ? null : parseInt(ordenRaw, 10),
    };
    if (id > 0) {
      await apiSend('api/estados.php?id=' + id, 'PUT', payload);
      toast('Estado actualizado.');
    } else {
      await apiSend('api/estados.php', 'POST', payload);
      toast('Estado creado.');
    }
    document.getElementById('formEstadoBackdrop').classList.remove('open');
    cargarEstados();
  } catch (e) {
    const msg = e.message || 'Error al guardar.';
    if (/ya existe/i.test(msg)) {
      mostrarErrorEstado('Valor', msg);
    } else {
      toast(msg, { error: true });
    }
  } finally {
    _estadosGuardando = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
  }
}

async function eliminarEstado(id) {
  const s = estadosCache.find((x) => x.id === id);
  if (!s) return;
  const ok = await confirmar({
    title: 'Eliminar estado',
    message: `Vas a eliminar el estado «${s.campo} = ${s.valor || '∅'}» (${s.texto}). Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
    danger: true,
  });
  if (!ok) return;
  try {
    await apiSend('api/estados.php?id=' + id, 'DELETE');
    toast('Estado eliminado.');
    cargarEstados();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

function estadosAbrirCtx(ev, id) {
  estadosCtxRegistroId = id;
  const menu = document.getElementById('estadosCtxMenu');
  if (!menu) return;
  let x = ev.clientX, y = ev.clientY;
  if ((!x && !y) && ev.currentTarget && ev.currentTarget.getBoundingClientRect) {
    const r = ev.currentTarget.getBoundingClientRect();
    x = r.right; y = r.bottom;
  }
  abrirCtxMenu(menu, x, y, { id });
}

function estadosCerrarMenu() {
  estadosCtxRegistroId = null;
  cerrarCtxMenu();
}

document.addEventListener('DOMContentLoaded', () => {
  const menu = document.getElementById('estadosCtxMenu');
  if (!menu) return;
  menu.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-action');
    const id     = estadosCtxRegistroId;
    estadosCerrarMenu();
    if (!id) return;
    if (action === 'editar') {
      abrirEditarEstado(id);
    } else if (action === 'eliminar') {
      eliminarEstado(id);
    } else if (action === 'copiar-campo' || action === 'copiar-valor') {
      const s = estadosCache.find((x) => x.id === id);
      if (!s || !navigator.clipboard) return;
      const txt = action === 'copiar-campo' ? (s.campo || '') : (s.valor || '');
      navigator.clipboard.writeText(txt).then(
        () => toast((action === 'copiar-campo' ? 'Campo' : 'Valor') + ' copiado.'),
        () => toast('No se pudo copiar.', { error: true }),
      );
    }
  });
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const ctx     = document.getElementById('estadosCtxMenu');
  const form    = document.getElementById('formEstadoBackdrop');
  const listado = document.getElementById('estadosBackdrop');
  if (ctx && ctx.classList.contains('open')) { estadosCerrarMenu(); return; }
  if (form && form.classList.contains('open')) { form.classList.remove('open'); return; }
  if (listado && listado.classList.contains('open')) { cerrarEstados(); }
});

// ------------------------- Herramientas: Migrador DB -------------------------
// Lista los .sql de cloud/sql/migrations/ y permite aplicarlos contra la
// BD del entorno actual (panel dev → databox_dev, panel prod → RDS).
let migracionesCache       = [];
let migrPreviewNombreActual = '';
let _migrCargando          = false;
let _migrAplicando         = false;

function abrirMigraciones() {
  document.getElementById('migracionesBackdrop').classList.add('open');
  cargarMigraciones();
}

function cerrarMigraciones() {
  document.getElementById('migracionesBackdrop').classList.remove('open');
}

async function cargarMigraciones() {
  if (_migrCargando) return;
  _migrCargando = true;

  const tbody = document.getElementById('migrTbody');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  try {
    const data = await apiGet('api/herramientas_migraciones_list.php');
    migracionesCache = data.items || [];

    document.getElementById('migrDbName').textContent = data.database || '—';
    const envBadge = document.getElementById('migrEnvBadge');
    const env = (data.env || 'unknown').toLowerCase();
    envBadge.textContent = env;
    envBadge.className = 'badge ' + (env === 'production' ? 'badge-danger'
                                   : env === 'development' ? 'badge-success'
                                   : 'badge-warn');

    renderMigraciones(migracionesCache);
    actualizarResumenMigraciones();
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  } finally {
    _migrCargando = false;
  }
}

function actualizarResumenMigraciones() {
  const total      = migracionesCache.length;
  const aplicadas  = migracionesCache.filter((m) => m.estado === 'aplicada').length;
  const pendientes = total - aplicadas;
  const drift      = migracionesCache.filter((m) => m.hash_drift).length;

  let txt = `${total} archivo${total === 1 ? '' : 's'} · ${aplicadas} aplicada${aplicadas === 1 ? '' : 's'} · ${pendientes} pendiente${pendientes === 1 ? '' : 's'}`;
  if (drift > 0) txt += ` · ⚠ ${drift} con drift de hash`;
  document.getElementById('migrResumen').textContent = txt;

  const btn = document.getElementById('migrBtnAplicarPendientes');
  btn.disabled = pendientes === 0;
  btn.textContent = pendientes === 0
    ? 'Sin pendientes'
    : `Aplicar ${pendientes} pendiente${pendientes === 1 ? '' : 's'}`;
}

function renderMigraciones(rows) {
  const tbody = document.getElementById('migrTbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty">No hay archivos en <code style="font-family:monospace">cloud/sql/migrations/</code>.</td></tr>';
    return;
  }
  // Orden de visualización: pendientes arriba (en orden cronológico de
  // aplicación, para que se lea igual que como se van a aplicar), luego las
  // aplicadas ordenadas por `id` DESC (última aplicada arriba). El cache
  // queda intacto en orden ascendente para que aplicarPendientesMigraciones()
  // siga aplicando en orden cronológico (vieja → nueva).
  const pendientes = rows.filter((m) => m.estado === 'pendiente');
  const aplicadas  = rows.filter((m) => m.estado === 'aplicada')
                          .sort((a, b) => (b.id || 0) - (a.id || 0));
  const ordenadas  = pendientes.concat(aplicadas);
  tbody.innerHTML = ordenadas.map((m) => {
    const nombre   = esc(m.nombre || '');
    const tamano   = formatearTamanoBytes(m.tamano || 0);
    const hashCorto = (m.hash || '').slice(0, 8);
    const aplicada = m.aplicada
      ? `<span style="font-family:monospace;font-size:.82rem">${esc(m.aplicada)}</span>`
      : '<span style="color:var(--muted)">—</span>';

    let badge;
    if (m.estado === 'aplicada' && m.hash_drift) {
      badge = '<span class="badge badge-warn" title="El archivo cambió después de aplicarse">⚠ drift</span>';
    } else if (m.estado === 'aplicada') {
      badge = '<span class="badge badge-success">aplicada</span>';
    } else {
      badge = '<span class="badge badge-info">pendiente</span>';
    }

    const btnAplicar = m.estado === 'pendiente'
      ? `<button class="btn btn-primary btn-sm" onclick="aplicarMigracionDesdeListado('${esc(m.nombre)}')">Aplicar</button>`
      : '';

    return `
      <tr>
        <td>${badge}</td>
        <td style="font-family:monospace;font-weight:600">${nombre}</td>
        <td style="font-size:.82rem;color:var(--muted)">${tamano}</td>
        <td style="font-family:monospace;font-size:.78rem;color:var(--muted)" title="${esc(m.hash || '')}">${hashCorto}</td>
        <td>${aplicada}</td>
        <td style="text-align:center">
          <div class="actions" style="justify-content:center;gap:6px">
            <button class="btn btn-ghost btn-sm" onclick="verMigracion('${esc(m.nombre)}')">Ver SQL</button>
            ${btnAplicar}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function formatearTamanoBytes(n) {
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
  return (n / 1024 / 1024).toFixed(2) + ' MB';
}

async function verMigracion(nombre) {
  migrPreviewNombreActual = nombre;
  document.getElementById('migrPreviewNombre').textContent = nombre;
  const ta = document.getElementById('migrPreviewSql');
  ta.value = 'Cargando…';
  document.getElementById('migrPreviewBackdrop').classList.add('open');

  const m = migracionesCache.find((x) => x.nombre === nombre);
  const btn = document.getElementById('migrPreviewBtnAplicar');
  if (m && m.estado === 'pendiente') {
    btn.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Aplicar';
  } else {
    btn.style.display = 'none';
  }

  try {
    const data = await apiGet('api/herramientas_migraciones_get.php?nombre=' + encodeURIComponent(nombre));
    ta.value = data.contenido || '';
  } catch (e) {
    ta.value = '-- Error al cargar: ' + e.message;
  }
}

async function migrPreviewAplicar() {
  if (!migrPreviewNombreActual) return;
  document.getElementById('migrPreviewBackdrop').classList.remove('open');
  await aplicarMigracionConConfirmacion(migrPreviewNombreActual);
}

async function aplicarMigracionDesdeListado(nombre) {
  await aplicarMigracionConConfirmacion(nombre);
}

async function aplicarMigracionConConfirmacion(nombre) {
  const dbName = document.getElementById('migrDbName').textContent || '?';
  const env    = (document.getElementById('migrEnvBadge').textContent || '').toLowerCase();
  const esProd = env === 'production';

  const ok = await confirmar({
    title: esProd ? '⚠ Aplicar en PRODUCCIÓN' : 'Aplicar migración',
    message: `Vas a aplicar «${nombre}» contra la base ${dbName}${esProd ? ' (PRODUCCIÓN)' : ''}. ` +
             `Las sentencias DDL no se pueden deshacer. ¿Continuar?`,
    confirmText: esProd ? 'Aplicar en prod' : 'Aplicar',
    danger: esProd,
  });
  if (!ok) return;
  await aplicarMigracionSinConfirmar(nombre);
}

async function aplicarMigracionSinConfirmar(nombre) {
  if (_migrAplicando) return;
  _migrAplicando = true;
  try {
    const data = await apiSend('api/herramientas_migraciones_apply.php', 'POST', { nombre });
    toast(`«${nombre}» aplicada en ${data.duracion_ms} ms.`);
    return true;
  } catch (e) {
    toast(e.message || 'Error al aplicar.', { error: true, duration: 10000 });
    return false;
  } finally {
    _migrAplicando = false;
    await cargarMigraciones();
  }
}

async function aplicarPendientesMigraciones() {
  const pendientes = migracionesCache.filter((m) => m.estado === 'pendiente').map((m) => m.nombre);
  if (!pendientes.length) return;

  const dbName = document.getElementById('migrDbName').textContent || '?';
  const env    = (document.getElementById('migrEnvBadge').textContent || '').toLowerCase();
  const esProd = env === 'production';

  const ok = await confirmar({
    title: esProd ? '⚠ Aplicar TODAS en PRODUCCIÓN' : 'Aplicar todas las pendientes',
    message: `Vas a aplicar ${pendientes.length} migración${pendientes.length === 1 ? '' : 'es'} ` +
             `contra la base ${dbName}${esProd ? ' (PRODUCCIÓN)' : ''} en orden alfabético. ` +
             `Si una falla, se detiene la corrida y las anteriores quedan aplicadas. ¿Continuar?`,
    confirmText: esProd ? 'Aplicar en prod' : 'Aplicar todas',
    danger: esProd,
  });
  if (!ok) return;

  const btn = document.getElementById('migrBtnAplicarPendientes');
  btn.disabled = true;

  let aplicadas = 0;
  for (const nombre of pendientes) {
    btn.textContent = `Aplicando ${nombre}…`;
    let exito = false;
    try {
      await apiSend('api/herramientas_migraciones_apply.php', 'POST', { nombre });
      exito = true;
      aplicadas++;
    } catch (e) {
      toast(`Falló «${nombre}»: ${e.message}`, { error: true, duration: 10000 });
    }
    if (!exito) break;
  }
  if (aplicadas === pendientes.length) {
    toast(`Aplicadas ${aplicadas} migración${aplicadas === 1 ? '' : 'es'}.`);
  } else if (aplicadas > 0) {
    toast(`Corrida parcial: ${aplicadas} de ${pendientes.length} aplicadas.`, { error: true, duration: 10000 });
  }
  await cargarMigraciones();
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const prev    = document.getElementById('migrPreviewBackdrop');
  const listado = document.getElementById('migracionesBackdrop');
  if (prev && prev.classList.contains('open')) { prev.classList.remove('open'); return; }
  if (listado && listado.classList.contains('open')) { cerrarMigraciones(); }
});

// ------------------------- Herramientas: Sincronizador de tablas -------------------------
// Copia una tabla completa entre dev y prod preservando los IDs de origen.
// Solo funciona en el panel de desarrollo (los endpoints devuelven 403 en prod).
// Progreso streameado via SSE (Server-Sent Events).
let _sincEnEjecucion = false;
let _sincEventSource = null;

function abrirSincronizador() {
  document.getElementById('sincOrigen').value  = '';
  document.getElementById('sincDestino').value = '';
  const selT = document.getElementById('sincTabla');
  selT.innerHTML = '<option value="">— Elegí primero el origen —</option>';
  selT.disabled = true;
  document.getElementById('sincBtnEjecutar').disabled = true;
  document.getElementById('sincResumen').textContent = '';
  document.getElementById('sincLog').innerHTML =
    '<span class="term-info">Elegí origen y tabla, y hacé click en «Ejecutar sincronización» para empezar.</span>';
  document.getElementById('sincTablaError').style.display = 'none';
  document.getElementById('sincTablaError').textContent = '';
  document.getElementById('sincronizadorBackdrop').classList.add('open');
}

function cerrarSincronizador() {
  if (_sincEnEjecucion) {
    toast('Esperá a que termine la sincronización en curso.', { error: true });
    return;
  }
  document.getElementById('sincronizadorBackdrop').classList.remove('open');
}

async function sincOnCambioOrigen() {
  const origen = document.getElementById('sincOrigen').value;
  const selT   = document.getElementById('sincTabla');
  const inpD   = document.getElementById('sincDestino');
  const btn    = document.getElementById('sincBtnEjecutar');

  btn.disabled = true;
  selT.disabled = true;
  selT.innerHTML = '<option value="">Cargando tablas…</option>';
  document.getElementById('sincTablaError').style.display = 'none';

  if (!origen) {
    selT.innerHTML = '<option value="">— Elegí primero el origen —</option>';
    inpD.value = '';
    return;
  }
  inpD.value = origen === 'dev' ? 'Producción (databox)' : 'Desarrollo (databox_dev)';

  try {
    const data = await apiGet('api/herramientas_sincronizador_tables.php?origen=' + encodeURIComponent(origen));
    const tablas = data.tablas || [];
    if (!tablas.length) {
      selT.innerHTML = '<option value="">No hay tablas en el origen</option>';
      return;
    }
    selT.innerHTML = '<option value="">— Elegí una tabla —</option>' +
      tablas.map((t) => {
        const filas = (t.filas_aprox != null) ? ` (~${fmtNum(t.filas_aprox)} filas)` : '';
        return `<option value="${esc(t.nombre)}">${esc(t.nombre)}${filas}</option>`;
      }).join('');
    selT.disabled = false;
    selT.onchange = () => {
      btn.disabled = !selT.value;
    };
    const meta = data.origen || {};
    document.getElementById('sincResumen').textContent =
      `${meta.host || '?'} · ${meta.database || '?'} · ${tablas.length} tabla${tablas.length === 1 ? '' : 's'}`;
  } catch (e) {
    selT.innerHTML = '<option value="">Error al cargar tablas</option>';
    const err = document.getElementById('sincTablaError');
    err.textContent = e.message || 'Error desconocido';
    err.style.display = '';
  }
}

function sincLogAppend(type, msg) {
  const log = document.getElementById('sincLog');
  const cls = ({
    error:   'term-error',
    warn:    'term-warn',
    success: 'term-success',
    done:    'term-info',
  })[type] || 'term-info';
  const prefix = ({
    error:   '✗ ',
    warn:    '⚠ ',
    success: '✓ ',
  })[type] || '';
  const line = document.createElement('div');
  line.className = cls;
  line.textContent = prefix + msg;
  log.appendChild(line);
  log.scrollTop = log.scrollHeight;
}

async function sincEjecutar() {
  if (_sincEnEjecucion) return;

  const origen  = document.getElementById('sincOrigen').value;
  const tabla   = document.getElementById('sincTabla').value;
  if (!origen || !tabla) return;
  const destino = origen === 'dev' ? 'prod' : 'dev';

  const esProd = destino === 'prod';
  const ok = await confirmar({
    title: esProd ? '⚠ Sincronizar a PRODUCCIÓN' : 'Sincronizar a desarrollo',
    message: `Vas a copiar la tabla «${tabla}» desde ${origen} a ${destino} preservando los IDs. ` +
             `Si la tabla existe en destino, se vaciará (TRUNCATE) antes de insertar. ¿Continuar?`,
    confirmText: esProd ? 'Copiar a prod' : 'Copiar a dev',
    danger: esProd,
  });
  if (!ok) return;

  _sincEnEjecucion = true;
  document.getElementById('sincBtnEjecutar').disabled = true;
  document.getElementById('sincOrigen').disabled = true;
  document.getElementById('sincTabla').disabled  = true;
  document.getElementById('sincLog').innerHTML = '';

  const url = 'api/herramientas_sincronizador_run.php'
    + '?origen='  + encodeURIComponent(origen)
    + '&destino=' + encodeURIComponent(destino)
    + '&tabla='   + encodeURIComponent(tabla);

  // EventSource envia GET con cookies (same-origin) => auth por cookie OK.
  const es = new EventSource(url, { withCredentials: true });
  _sincEventSource = es;

  const finalizar = () => {
    if (_sincEventSource) {
      try { _sincEventSource.close(); } catch (_) {}
      _sincEventSource = null;
    }
    _sincEnEjecucion = false;
    document.getElementById('sincBtnEjecutar').disabled = false;
    document.getElementById('sincOrigen').disabled = false;
    document.getElementById('sincTabla').disabled  = false;
  };

  es.onmessage = (ev) => {
    let obj;
    try { obj = JSON.parse(ev.data); }
    catch (_) { sincLogAppend('info', ev.data); return; }
    sincLogAppend(obj.type || 'info', obj.msg || '');
    if (obj.type === 'done') finalizar();
  };
  es.onerror = () => {
    sincLogAppend('error', 'Conexión con el servidor interrumpida.');
    finalizar();
  };
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const b = document.getElementById('sincronizadorBackdrop');
  if (b && b.classList.contains('open')) cerrarSincronizador();
});

// ------------------------- Herramientas: Visor de sucesos -------------------------
// Visor read-only de la tabla `sucesos`. Los distintos modulos del panel
// escriben ahi su log de actividad (id / fecha / origen / tipo / detalle).
let sucesosCache         = [];
let sucesosFiltroQ       = '';
let sucesosFiltroTipo    = '';
let _sucesosSearchTimer  = null;
let sucesoDetalleActual  = null;

// Mapa de estilos por tipo -- usado por chips, celda de listado y detalle.
const SUCESOS_TIPOS = {
  info:   { label: 'Info',   icon: 'fa-circle-info',          color: 'var(--info)'   },
  alerta: { label: 'Alerta', icon: 'fa-triangle-exclamation', color: 'var(--warn)'   },
  error:  { label: 'Error',  icon: 'fa-circle-exclamation',   color: 'var(--danger)' },
};

function sucesoTipoHtml(tipo) {
  const meta = SUCESOS_TIPOS[tipo] || SUCESOS_TIPOS.info;
  return '<span style="display:inline-flex;align-items:center;gap:6px">' +
           '<i class="fa-solid ' + meta.icon + '" style="color:' + meta.color + '"></i>' +
           '<span>' + meta.label + '</span>' +
         '</span>';
}

function abrirVisorSucesos() {
  document.getElementById('sucesosBackdrop').classList.add('open');
  cargarSucesos();
}

function cerrarVisorSucesos() {
  document.getElementById('sucesosBackdrop').classList.remove('open');
}

function sucesosOnSearch(v) {
  sucesosFiltroQ = String(v ?? '');
  const clearBtn = document.getElementById('sucesosSearchClear');
  if (clearBtn) clearBtn.style.display = sucesosFiltroQ ? '' : 'none';
  clearTimeout(_sucesosSearchTimer);
  _sucesosSearchTimer = setTimeout(cargarSucesos, 250);
}

function sucesosLimpiarBusqueda() {
  sucesosFiltroQ = '';
  const input = document.getElementById('sucesosSearch');
  if (input) input.value = '';
  document.getElementById('sucesosSearchClear').style.display = 'none';
  cargarSucesos();
}

function setFiltroTipoSucesos(chip, valor) {
  sucesosFiltroTipo = valor || '';
  const chips = document.querySelectorAll('#sucesosTipoChips .filter-chip');
  chips.forEach((c) => c.classList.toggle('active', c === chip));
  cargarSucesos();
}

async function cargarSucesos() {
  const tbody = document.getElementById('sucesosTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  const desde  = document.getElementById('sucesosDesde')?.value  || '';
  const hasta  = document.getElementById('sucesosHasta')?.value  || '';
  const limite = document.getElementById('sucesosLimite')?.value || '200';

  const params = new URLSearchParams();
  if (sucesosFiltroQ)    params.set('q', sucesosFiltroQ);
  if (sucesosFiltroTipo) params.set('tipo', sucesosFiltroTipo);
  if (desde)             params.set('desde', desde);
  if (hasta)             params.set('hasta', hasta);
  params.set('limite', limite);

  try {
    const data = await apiGet('api/sucesos.php?' + params.toString());
    sucesosCache = data.items || [];
    const resumen = document.getElementById('sucesosResumen');
    if (resumen && data.stats) {
      const m = data.stats.mostrados ?? sucesosCache.length;
      const t = data.stats.total     ?? m;
      resumen.textContent = `${m.toLocaleString('es-AR')} de ${t.toLocaleString('es-AR')} registros`;
    }
    renderSucesos(sucesosCache);
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="5" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function renderSucesos(rows) {
  const tbody = document.getElementById('sucesosTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="table-empty">Sin sucesos para mostrar.</td></tr>';
    return;
  }
  const dashVacio = '<span style="color:var(--muted);font-style:italic">—</span>';
  tbody.innerHTML = rows.map((s) => {
    const fecha   = esc(s.fecha   || '');
    const origen  = esc(s.origen  || '');
    const detalle = esc(s.detalle || '');
    return `
      <tr class="row-clickable" data-id="${s.id}" onclick="sucesosVerDetalle(${s.id})">
        <td class="td-id">${s.id}</td>
        <td style="font-family:monospace;white-space:nowrap">${fecha || dashVacio}</td>
        <td style="font-family:monospace;font-weight:600">${origen || dashVacio}</td>
        <td>${sucesoTipoHtml(s.tipo)}</td>
        <td style="color:var(--muted);max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="${detalle}">${detalle}</td>
      </tr>
    `;
  }).join('');
}

function sucesosVerDetalle(id) {
  const s = sucesosCache.find((x) => x.id === id);
  if (!s) return;
  sucesoDetalleActual = s;
  document.getElementById('sucesoDetalleId').textContent     = s.id;
  document.getElementById('sucesoDetalleFecha').textContent  = s.fecha  || '—';
  document.getElementById('sucesoDetalleOrigen').textContent = s.origen || '—';
  document.getElementById('sucesoDetalleTipo').innerHTML     = sucesoTipoHtml(s.tipo);
  document.getElementById('sucesoDetalleTexto').value        = s.detalle || '';
  document.getElementById('sucesoDetalleBackdrop').classList.add('open');
}

// Copia el suceso completo al portapapeles con un formato pensado para pegarse
// directo en un asistente de programación (todos los campos etiquetados +
// bloque de detalle delimitado para que el asistente pueda parsearlo sin
// confundir el cuerpo con los metadatos).
function sucesoDetalleCopiar() {
  const s = sucesoDetalleActual;
  if (!s) { toast('No hay suceso para copiar.', { error: true }); return; }
  if (!navigator.clipboard) { toast('El navegador no permite copiar.', { error: true }); return; }
  const tipoMeta  = SUCESOS_TIPOS[s.tipo] || SUCESOS_TIPOS.info;
  const partes = [
    'Suceso #' + (s.id ?? '—') + ' registrado en el panel cloud de Databox.',
    '',
    'Fecha:   ' + (s.fecha  || '—'),
    'Origen:  ' + (s.origen || '—'),
    'Tipo:    ' + tipoMeta.label + ' (' + (s.tipo || 'info') + ')',
    '',
    'Detalle:',
    '```',
    (s.detalle || '').replace(/\r\n/g, '\n'),
    '```',
  ];
  navigator.clipboard.writeText(partes.join('\n')).then(
    () => toast('Suceso copiado al portapapeles.'),
    () => toast('No se pudo copiar.', { error: true }),
  );
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const detalle = document.getElementById('sucesoDetalleBackdrop');
  const listado = document.getElementById('sucesosBackdrop');
  if (detalle && detalle.classList.contains('open')) { detalle.classList.remove('open'); return; }
  if (listado && listado.classList.contains('open')) { cerrarVisorSucesos(); }
});

// ------------------------- Herramientas: Programador de tareas -------------------------
// CRUD sobre `tareas` + historial en `tareas_ejecuciones` + streaming SSE del log
// de cada ejecución en vivo. Ver la skill `crear_programador_de_tareas` y §5–§10.

let tareasCache             = [];
let tareasFiltroQ           = '';
let tareasFiltroActivo      = '1';
let tareasCtxRegistroId     = null;
let _tareasSearchTimer      = null;
let _tareasGuardando        = false;
let _tareasScripts          = [];
let ejecucionesTareaSel     = null;     // { id, nombre }
let ejecucionesFiltroEstado = '';
let ejecucionesCache        = [];
let ejecucionesCtxRegistroId = null;
let terminalES              = null;
let terminalEjecucionActual = null;
let terminalAutoscroll      = true;

// --- Listado de tareas ---

function abrirTareas() {
  document.getElementById('tareasBackdrop').classList.add('open');
  cargarTareas();
}

function cerrarTareas() {
  document.getElementById('tareasBackdrop').classList.remove('open');
  cerrarCtxMenu();
}

function tareasOnSearch(v) {
  tareasFiltroQ = String(v ?? '');
  const clearBtn = document.getElementById('tareasSearchClear');
  if (clearBtn) clearBtn.style.display = tareasFiltroQ ? '' : 'none';
  clearTimeout(_tareasSearchTimer);
  _tareasSearchTimer = setTimeout(cargarTareas, 250);
}

function tareasLimpiarBusqueda() {
  tareasFiltroQ = '';
  const input = document.getElementById('tareasSearch');
  if (input) input.value = '';
  document.getElementById('tareasSearchClear').style.display = 'none';
  cargarTareas();
}

function tareasSetActivo(v, el) {
  tareasFiltroActivo = v;
  $$('.tareas-chip-estado').forEach((c) => c.classList.toggle('active', c === el));
  cargarTareas();
}

async function cargarTareas() {
  const tbody = document.getElementById('tareasTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  const params = new URLSearchParams();
  if (tareasFiltroQ)      params.set('q', tareasFiltroQ);
  if (tareasFiltroActivo) params.set('activo', tareasFiltroActivo);
  params.set('limite',   '500');
  params.set('order_by', 'id');
  params.set('dir',      'asc');

  try {
    const data = await apiGet('api/tareas.php?' + params.toString());
    tareasCache = data.items || [];
    renderTareas(tareasCache);
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function tareaBadgeEstado(estado) {
  const map = {
    ok:        { cls: 'badge-success', txt: 'OK' },
    error:     { cls: 'badge-danger',  txt: 'Error' },
    timeout:   { cls: 'badge-warn',    txt: 'Timeout' },
    killed:    { cls: 'badge-danger',  txt: 'Killed' },
    corriendo: { cls: 'badge-info',    txt: 'Corriendo' },
  };
  const m = map[estado] || { cls: '', txt: 'Sin corrida' };
  return `<span class="badge ${m.cls}">${m.txt}</span>`;
}

function renderTareas(rows) {
  const tbody = document.getElementById('tareasTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Sin tareas para mostrar.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((t) => {
    const nombre  = esc(t.nombre || '');
    const desc    = t.descripcion ? esc(t.descripcion) : '';
    const cron    = esc(t.cron_expr || '');
    const est     = tareaBadgeEstado(t.ultimo_estado);
    const ultimo  = t.ultimo_run ? esc(fmtFecha(t.ultimo_run)) : '<span style="color:var(--muted)">—</span>';
    const activo  = t.activo ? 'checked' : '';
    return `
      <tr class="row-clickable" data-id="${t.id}"
          onclick="abrirEjecuciones(${t.id})"
          oncontextmenu="event.preventDefault();abrirMenuContextoTareas(event, ${t.id})">
        <td class="td-id">${t.id}</td>
        <td>
          <div style="font-weight:600">${nombre}</div>
          ${desc ? `<div style="font-size:.82rem;color:var(--muted)">${desc}</div>` : ''}
        </td>
        <td style="font-family:monospace;font-size:.82rem">${cron}</td>
        <td>${est}</td>
        <td style="font-family:monospace;font-size:.82rem">${ultimo}</td>
        <td style="text-align:center">
          <label class="toggle-switch" onclick="event.stopPropagation()">
            <input type="checkbox" ${activo}
                   onchange="toggleActivoTarea(${t.id}, this.checked)">
            <span class="toggle-track"><span class="toggle-thumb"></span></span>
          </label>
        </td>
        <td style="text-align:center">
          <button class="btn-icon-sm" title="Más acciones"
                  onclick="event.stopPropagation();abrirMenuContextoTareas(event, ${t.id})">
            <i class="fa-solid fa-bars"></i>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

// --- Alta / Edición ---

async function cargarScriptsDisponibles(actualScript) {
  const sel = document.getElementById('formTareaScript');
  if (!sel) return;
  sel.innerHTML = '<option value="">— Cargando… —</option>';
  try {
    const data = await apiGet('api/tareas_scripts_disponibles.php');
    _tareasScripts = data || [];
    const opts = ['<option value="">— Elegí un script —</option>'];
    _tareasScripts.forEach((s) => {
      opts.push(`<option value="${esc(s)}">${esc(s)}</option>`);
    });
    if (actualScript && !_tareasScripts.includes(actualScript)) {
      opts.push(`<option value="${esc(actualScript)}">⚠️ ${esc(actualScript)} (no está en cloud/jobs/)</option>`);
    }
    sel.innerHTML = opts.join('');
    if (actualScript) sel.value = actualScript;
  } catch (e) {
    sel.innerHTML = '<option value="">— Error al cargar —</option>';
    toast(e.message, { error: true });
  }
}

function abrirNuevaTarea() {
  limpiarErroresFormTarea();
  document.getElementById('formTareaTitulo').innerHTML =
    '<span style="font-size:1.2rem">⏰</span><span>Nueva tarea</span>';
  document.getElementById('formTareaId').value          = '';
  document.getElementById('formTareaNombre').value      = '';
  document.getElementById('formTareaDescripcion').value = '';
  document.getElementById('formTareaCron').value        = '* * * * *';
  document.getElementById('formTareaTimeout').value     = '300';
  document.getElementById('formTareaRetencion').value   = '7';
  document.getElementById('formTareaOverlap').value     = 'skip';
  document.getElementById('formTareaActivo').value      = '1';
  cargarScriptsDisponibles('');
  document.getElementById('formTareaBackdrop').classList.add('open');
  setTimeout(() => document.getElementById('formTareaNombre').focus(), 50);
}

function abrirEditarTarea(id) {
  const t = tareasCache.find((x) => x.id === id);
  if (!t) { toast('No se encontró la tarea.', { error: true }); return; }
  limpiarErroresFormTarea();
  document.getElementById('formTareaTitulo').innerHTML =
    '<span style="font-size:1.2rem">⏰</span><span>Editar tarea</span>';
  document.getElementById('formTareaId').value          = t.id;
  document.getElementById('formTareaNombre').value      = t.nombre || '';
  document.getElementById('formTareaDescripcion').value = t.descripcion || '';
  document.getElementById('formTareaCron').value        = t.cron_expr || '* * * * *';
  document.getElementById('formTareaTimeout').value     = t.timeout_seg   || 300;
  document.getElementById('formTareaRetencion').value   = t.retencion_dias || 7;
  document.getElementById('formTareaOverlap').value     = t.overlap || 'skip';
  document.getElementById('formTareaActivo').value      = t.activo ? '1' : '0';
  cargarScriptsDisponibles(t.script || '');
  document.getElementById('formTareaBackdrop').classList.add('open');
  setTimeout(() => document.getElementById('formTareaNombre').focus(), 50);
}

function limpiarErroresFormTarea() {
  ['Nombre', 'Descripcion', 'Script', 'Cron', 'Timeout'].forEach((c) => {
    const input = document.getElementById('formTarea' + c);
    const err   = document.getElementById('formTarea' + c + 'Error');
    if (input) input.classList.remove('input-invalid');
    if (err)   { err.style.display = 'none'; err.textContent = ''; }
  });
}

function mostrarErrorTarea(campo, msg) {
  const input = document.getElementById('formTarea' + campo);
  const err   = document.getElementById('formTarea' + campo + 'Error');
  if (input) { input.classList.add('input-invalid'); input.focus(); }
  if (err)   { err.style.display = ''; err.textContent = msg; }
}

async function guardarTarea() {
  if (_tareasGuardando) return;
  limpiarErroresFormTarea();

  const idRaw       = document.getElementById('formTareaId').value;
  const id          = idRaw ? parseInt(idRaw, 10) : 0;
  const nombre      = document.getElementById('formTareaNombre').value.trim();
  const descripcion = document.getElementById('formTareaDescripcion').value.trim();
  const script      = document.getElementById('formTareaScript').value;
  const cron_expr   = document.getElementById('formTareaCron').value.trim();
  const timeout_seg = parseInt(document.getElementById('formTareaTimeout').value, 10) || 300;
  const retencion_dias = parseInt(document.getElementById('formTareaRetencion').value, 10) || 7;
  const overlap     = document.getElementById('formTareaOverlap').value || 'skip';
  const activo      = document.getElementById('formTareaActivo').value === '1' ? 1 : 0;

  if (!nombre)    { mostrarErrorTarea('Nombre', 'El nombre es obligatorio.'); return; }
  if (nombre.length > 120) { mostrarErrorTarea('Nombre', 'Máximo 120 caracteres.'); return; }
  if (!script)    { mostrarErrorTarea('Script', 'Elegí un script del desplegable.'); return; }
  if (!cron_expr) { mostrarErrorTarea('Cron', 'La expresión cron es obligatoria.'); return; }
  if (cron_expr.split(/\s+/).length !== 5) {
    mostrarErrorTarea('Cron', 'Deben ser exactamente 5 campos, ej: */5 * * * *.');
    return;
  }
  if (timeout_seg < 5 || timeout_seg > 86400) {
    mostrarErrorTarea('Timeout', 'Rango válido: 5 a 86400 segundos.');
    return;
  }

  const btn = document.getElementById('btnGuardarTarea');
  _tareasGuardando = true;
  if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }

  try {
    const payload = { nombre, descripcion, script, cron_expr, timeout_seg, retencion_dias, overlap, activo };
    if (id > 0) {
      await apiSend('api/tareas.php?id=' + id, 'PUT', payload);
      toast('Tarea actualizada.');
    } else {
      await apiSend('api/tareas.php', 'POST', payload);
      toast('Tarea creada.');
    }
    document.getElementById('formTareaBackdrop').classList.remove('open');
    cargarTareas();
  } catch (e) {
    const msg = e.message || 'Error al guardar.';
    if (/nombre_duplicado/i.test(msg)) {
      mostrarErrorTarea('Nombre', 'Ya existe una tarea con ese nombre.');
    } else {
      toast(msg, { error: true });
    }
  } finally {
    _tareasGuardando = false;
    if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
  }
}

async function eliminarTarea(id) {
  const t = tareasCache.find((x) => x.id === id);
  if (!t) return;
  const ok = await confirmar({
    title: 'Eliminar tarea',
    message: `Vas a eliminar «${t.nombre}» y todo su historial de ejecuciones. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar',
    danger: true,
  });
  if (!ok) return;
  try {
    const res = await apiSend('api/tareas.php?id=' + id, 'DELETE');
    const msg = res && res.archivos_borrados
      ? `Tarea eliminada. ${res.archivos_borrados} archivo${res.archivos_borrados === 1 ? '' : 's'} de log borrado${res.archivos_borrados === 1 ? '' : 's'}.`
      : 'Tarea eliminada.';
    toast(msg);
    cargarTareas();
  } catch (e) {
    const msg = e.message || 'Error al eliminar.';
    if (/ejecucion_en_curso/i.test(msg)) {
      toast('La tarea tiene una ejecución en curso. Detenela desde el historial antes de borrarla.', { error: true, duration: 6000 });
    } else {
      toast(msg, { error: true });
    }
  }
}

async function toggleActivoTarea(id, activo) {
  const t = tareasCache.find((x) => x.id === id);
  if (!t) return;
  try {
    await apiSend('api/tareas.php?id=' + id, 'PUT', {
      nombre:         t.nombre,
      descripcion:    t.descripcion,
      script:         t.script,
      cron_expr:      t.cron_expr,
      timeout_seg:    t.timeout_seg,
      retencion_dias: t.retencion_dias,
      overlap:        t.overlap,
      activo:         activo ? 1 : 0,
    });
    toast(activo ? 'Tarea activada.' : 'Tarea desactivada.');
    // Actualizar cache sin recargar toda la tabla.
    t.activo = activo ? 1 : 0;
  } catch (e) {
    toast(e.message, { error: true });
    cargarTareas(); // sincronizar UI con el estado real
  }
}

async function ejecutarAhora(id) {
  try {
    const res = await apiSend('api/tareas_ejecutar.php', 'POST', { tarea_id: id });
    toast('Ejecución iniciada.');
    if (res && res.ejecucion_id) {
      // Refrescar el listado principal para que el snapshot muestre "corriendo".
      cargarTareas();
      abrirTerminal(res.ejecucion_id);
    }
  } catch (e) {
    const msg = e.message || 'Error al ejecutar.';
    if (/ya_esta_corriendo/i.test(msg)) {
      toast('La tarea ya tiene una ejecución en curso.', { error: true });
    } else {
      toast(msg, { error: true });
    }
  }
}

// --- Menú contextual de tareas ---

function abrirMenuContextoTareas(ev, id) {
  tareasCtxRegistroId = id;
  const menu = document.getElementById('tareasCtxMenu');
  if (!menu) return;
  const t = tareasCache.find((x) => x.id === id);
  const lbl = menu.querySelector('[data-action="toggle-activo"] [data-label]');
  if (lbl) lbl.textContent = (t && t.activo) ? 'Desactivar' : 'Activar';
  let x = ev.clientX, y = ev.clientY;
  if ((!x && !y) && ev.currentTarget && ev.currentTarget.getBoundingClientRect) {
    const r = ev.currentTarget.getBoundingClientRect();
    x = r.right; y = r.bottom;
  }
  abrirCtxMenu(menu, x, y, { id });
}

function cerrarMenuContextoTareas() {
  tareasCtxRegistroId = null;
  cerrarCtxMenu();
}

// --- Listado de ejecuciones ---

function abrirEjecuciones(tareaId) {
  const t = tareasCache.find((x) => x.id === tareaId);
  if (!t) { toast('No se encontró la tarea.', { error: true }); return; }
  ejecucionesTareaSel = { id: t.id, nombre: t.nombre };
  ejecucionesFiltroEstado = '';
  $$('.tareas-chip-est').forEach((c) => c.classList.toggle('active', c.getAttribute('data-est') === ''));
  document.getElementById('ejecucionesTareaNombre').textContent = t.nombre;
  document.getElementById('ejecucionesBackdrop').classList.add('open');
  cargarEjecuciones();
}

function cerrarEjecuciones() {
  document.getElementById('ejecucionesBackdrop').classList.remove('open');
  ejecucionesTareaSel = null;
  cerrarCtxMenu();
  // Refrescar el listado principal para reflejar el nuevo snapshot.
  if (document.getElementById('tareasBackdrop').classList.contains('open')) {
    cargarTareas();
  }
}

function ejecucionesSetEstado(v, el) {
  ejecucionesFiltroEstado = v;
  $$('.tareas-chip-est').forEach((c) => c.classList.toggle('active', c === el));
  cargarEjecuciones();
}

async function cargarEjecuciones() {
  if (!ejecucionesTareaSel) return;
  const tbody = document.getElementById('ejecucionesTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  const params = new URLSearchParams();
  params.set('tarea_id', ejecucionesTareaSel.id);
  if (ejecucionesFiltroEstado) params.set('estado', ejecucionesFiltroEstado);
  params.set('limite', '300');

  try {
    const data = await apiGet('api/tareas_ejecuciones.php?' + params.toString());
    ejecucionesCache = data.items || [];
    renderEjecuciones(ejecucionesCache);
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function renderEjecuciones(rows) {
  const tbody = document.getElementById('ejecucionesTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Sin ejecuciones para mostrar.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((e) => {
    const est     = tareaBadgeEstado(e.estado);
    const inicio  = esc(fmtFechaLarga(e.inicio));
    const durTxt  = formatoDuracion(e.inicio, e.fin);
    const disparo = e.disparo === 'manual'
      ? '<span class="badge badge-info">Manual</span>'
      : '<span style="color:var(--muted)">Scheduler</span>';
    const msg     = esc(e.mensaje || '');
    return `
      <tr class="row-clickable" data-id="${e.id}"
          onclick="abrirTerminal(${e.id})"
          oncontextmenu="event.preventDefault();abrirMenuContextoEjecuciones(event, ${e.id})">
        <td class="td-id">${e.id}</td>
        <td style="font-family:monospace;font-size:.82rem">${inicio}</td>
        <td style="font-family:monospace;font-size:.82rem">${durTxt}</td>
        <td>${est}</td>
        <td>${disparo}</td>
        <td title="${msg}"
            style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem;color:var(--muted)">
          ${msg}
        </td>
        <td style="text-align:center">
          <button class="btn-icon-sm" title="Más acciones"
                  onclick="event.stopPropagation();abrirMenuContextoEjecuciones(event, ${e.id})">
            <i class="fa-solid fa-bars"></i>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function formatoDuracion(inicioIso, finIso) {
  if (!inicioIso) return '—';
  const t0 = new Date(inicioIso).getTime();
  if (isNaN(t0)) return '—';
  const t1 = finIso ? new Date(finIso).getTime() : Date.now();
  const seg = Math.max(0, Math.round((t1 - t0) / 1000));
  if (seg < 60)    return seg + 's';
  if (seg < 3600)  return Math.floor(seg / 60) + 'm ' + (seg % 60) + 's';
  return Math.floor(seg / 3600) + 'h ' + Math.floor((seg % 3600) / 60) + 'm';
}

function abrirMenuContextoEjecuciones(ev, id) {
  ejecucionesCtxRegistroId = id;
  const menu = document.getElementById('ejecucionesCtxMenu');
  if (!menu) return;
  const e = ejecucionesCache.find((x) => x.id === id);
  const btnDet = menu.querySelector('[data-action="detener"]');
  if (btnDet) btnDet.style.display = (e && e.estado === 'corriendo') ? '' : 'none';
  let x = ev.clientX, y = ev.clientY;
  if ((!x && !y) && ev.currentTarget && ev.currentTarget.getBoundingClientRect) {
    const r = ev.currentTarget.getBoundingClientRect();
    x = r.right; y = r.bottom;
  }
  abrirCtxMenu(menu, x, y, { id });
}

function cerrarMenuContextoEjecuciones() {
  ejecucionesCtxRegistroId = null;
  cerrarCtxMenu();
}

async function detenerEjecucion(id) {
  try {
    const res = await apiSend('api/tareas_ejecuciones.php', 'POST', { id, accion: 'detener' });
    toast(res && res.killed ? 'Ejecución detenida (SIGKILL).' : 'Ejecución detenida (SIGTERM).');
    cargarEjecuciones();
  } catch (e) {
    toast(e.message, { error: true });
  }
}

// --- Terminal (streaming SSE) ---

function abrirTerminal(ejecucionId) {
  terminalEjecucionActual = ejecucionId;
  terminalAutoscroll = true;
  const btnAuto = document.getElementById('btnTerminalAutoscroll');
  if (btnAuto) btnAuto.classList.add('active');

  const out = document.getElementById('terminalOutput');
  if (out) out.textContent = '';
  document.getElementById('terminalEjecucionNum').textContent = '#' + ejecucionId;
  const badge = document.getElementById('terminalEstadoBadge');
  badge.className = 'badge badge-info';
  badge.textContent = 'corriendo';
  document.getElementById('btnTerminalDetener').style.display = '';
  document.getElementById('terminalBackdrop').classList.add('open');

  if (terminalES) { try { terminalES.close(); } catch (_) {} terminalES = null; }
  terminalES = new EventSource('api/tareas_ejecucion_stream.php?id=' + ejecucionId);

  terminalES.onmessage = (ev) => {
    out.textContent += ev.data + '\n';
    if (terminalAutoscroll) out.scrollTop = out.scrollHeight;
  };

  terminalES.addEventListener('end', (ev) => {
    const estado = ev.data || 'finalizado';
    const map = {
      ok:      'badge-success',
      error:   'badge-danger',
      killed:  'badge-danger',
      timeout: 'badge-warn',
    };
    badge.className = 'badge ' + (map[estado] || 'badge-info');
    badge.textContent = estado;
    document.getElementById('btnTerminalDetener').style.display = 'none';
    out.textContent += `\n── ejecución terminada (${estado}) ──\n`;
    if (terminalAutoscroll) out.scrollTop = out.scrollHeight;
    try { terminalES.close(); } catch (_) {}
    terminalES = null;
    // Refrescar listados de fondo si están abiertos.
    if (ejecucionesTareaSel) cargarEjecuciones();
    if (document.getElementById('tareasBackdrop').classList.contains('open')) cargarTareas();
  });

  terminalES.onerror = () => {
    try { terminalES.close(); } catch (_) {}
    terminalES = null;
    // Si la fila seguía corriendo, marcar como desconectado.
    if (badge.textContent === 'corriendo') {
      badge.className = 'badge badge-warn';
      badge.textContent = 'desconectado';
    }
  };
}

function cerrarTerminal() {
  if (terminalES) { try { terminalES.close(); } catch (_) {} terminalES = null; }
  document.getElementById('terminalBackdrop').classList.remove('open');
  terminalEjecucionActual = null;
  // Refrescar listados de fondo si están abiertos.
  if (ejecucionesTareaSel) cargarEjecuciones();
  if (document.getElementById('tareasBackdrop').classList.contains('open')) cargarTareas();
}

function terminalToggleAutoscroll() {
  terminalAutoscroll = !terminalAutoscroll;
  const btn = document.getElementById('btnTerminalAutoscroll');
  if (btn) btn.classList.toggle('active', terminalAutoscroll);
  toast(terminalAutoscroll ? 'Auto-scroll ON' : 'Auto-scroll OFF');
  if (terminalAutoscroll) {
    const out = document.getElementById('terminalOutput');
    if (out) out.scrollTop = out.scrollHeight;
  }
}

function detenerEjecucionActual() {
  if (!terminalEjecucionActual) return;
  detenerEjecucion(terminalEjecucionActual);
}

// --- Constructor de cron ---

const CRON_CAMPOS = [
  { key: 'min',  label: 'Minuto',           rango: '0-59', min: 0, max: 59 },
  { key: 'hour', label: 'Hora',             rango: '0-23', min: 0, max: 23 },
  { key: 'dom',  label: 'Día del mes',      rango: '1-31', min: 1, max: 31 },
  { key: 'mon',  label: 'Mes',              rango: '1-12', min: 1, max: 12 },
  { key: 'dow',  label: 'Día de la semana', rango: '0=dom..6=sáb', min: 0, max: 6 },
];

const CRON_PICKER_CFG = {
  min:  { min: 0, max: 59, formato: (n) => String(n).padStart(2, '0'), emoji: '⏱️', titulo: 'Elegir minutos' },
  hour: { min: 0, max: 23, formato: (n) => String(n).padStart(2, '0'), emoji: '🕐', titulo: 'Elegir horas' },
  dom:  { min: 1, max: 31, formato: (n) => String(n),                  emoji: '📅', titulo: 'Elegir día del mes' },
  mon:  { min: 1, max: 12, formato: (n) => cronNombreMesCorto(n),      emoji: '🗓️', titulo: 'Elegir mes' },
  dow:  { min: 0, max: 6,  formato: (n) => cronNombreDiaCorto(n),      emoji: '🗓️', titulo: 'Elegir día de la semana',
          orden: [1, 2, 3, 4, 5, 6, 0] },
};

function cronNombreMesCorto(n) {
  return ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][n] || String(n);
}
function cronNombreMes(n) {
  return ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto',
          'septiembre','octubre','noviembre','diciembre'][n] || String(n);
}
function cronNombreDiaCorto(n) {
  return ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][n] || String(n);
}
function cronNombreDia(n) {
  return ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'][n] || String(n);
}
function cronPluralDia(n) {
  // Días terminados en 's' no se pluralizan; sábado/domingo sí.
  const nombre = cronNombreDia(n);
  if (nombre === 'sábado')  return 'sábados';
  if (nombre === 'domingo') return 'domingos';
  return nombre;
}

function abrirCronBuilder() {
  const cont = document.getElementById('cronBuilderCampos');
  cont.innerHTML = CRON_CAMPOS.map((c) => `
    <div class="cron-builder-row" data-campo="${c.key}"
         style="display:grid;grid-template-columns:140px 130px 1fr 34px;gap:8px;align-items:center">
      <label style="font-size:.86rem">${c.label}
        <span style="color:var(--muted);font-size:.72rem;font-family:monospace">(${c.rango})</span>
      </label>
      <select data-cron-modo="${c.key}" onchange="cronBuilderModoChange('${c.key}')">
        <option value="star">Cualquiera</option>
        <option value="exact">Exacto</option>
        <option value="step">Cada</option>
        <option value="range">Rango</option>
        <option value="list">Lista</option>
      </select>
      <input type="text" data-cron-valor="${c.key}" placeholder=""
             style="font-family:monospace" disabled
             oninput="cronBuilderOnChange()"
             onclick="if(!this.disabled) abrirCronPicker('${c.key}')">
      <button class="btn btn-ghost btn-icon-sm" type="button" data-cron-picker="${c.key}"
              title="Elegir con botones" disabled
              onclick="abrirCronPicker('${c.key}')">
        <i class="fa-solid fa-list-check"></i>
      </button>
    </div>
  `).join('');

  const expr = document.getElementById('formTareaCron').value.trim() || '* * * * *';
  cronBuilderPoblar(expr);
  document.getElementById('cronBuilderBackdrop').classList.add('open');
}

function cerrarCronBuilder() {
  document.getElementById('cronBuilderBackdrop').classList.remove('open');
}

function cronBuilderPoblar(expr) {
  const partes = expr.split(/\s+/);
  if (partes.length !== 5) return;
  CRON_CAMPOS.forEach((c, i) => cronBuilderPoblarCampo(c.key, partes[i]));
  cronBuilderOnChange();
}

function cronBuilderPoblarCampo(campo, valor) {
  const modoSel = document.querySelector(`[data-cron-modo="${campo}"]`);
  const valInp  = document.querySelector(`[data-cron-valor="${campo}"]`);
  if (!modoSel || !valInp) return;
  if (valor === '*') {
    modoSel.value = 'star';
    valInp.value = '';
  } else if (valor.startsWith('*/')) {
    modoSel.value = 'step';
    valInp.value = valor.slice(2);
  } else if (valor.includes(',')) {
    modoSel.value = 'list';
    valInp.value = valor;
  } else if (valor.includes('-')) {
    modoSel.value = 'range';
    valInp.value = valor;
  } else {
    modoSel.value = 'exact';
    valInp.value = valor;
  }
  cronBuilderAjustarInput(campo);
}

function cronBuilderAjustarInput(campo) {
  const modoSel = document.querySelector(`[data-cron-modo="${campo}"]`);
  const valInp  = document.querySelector(`[data-cron-valor="${campo}"]`);
  const btnPick = document.querySelector(`[data-cron-picker="${campo}"]`);
  const modo    = modoSel.value;
  const cfg     = CRON_PICKER_CFG[campo];
  const placeholders = {
    star:  '',
    exact: cfg.min + '',
    step:  '15',
    range: `${cfg.min}-${cfg.max}`,
    list:  `${cfg.min},${cfg.max}`,
  };
  valInp.placeholder = placeholders[modo] || '';
  valInp.disabled    = (modo === 'star');
  btnPick.disabled   = (modo === 'star');
  if (modo === 'star') valInp.value = '';
}

function cronBuilderModoChange(campo) {
  cronBuilderAjustarInput(campo);
  cronBuilderOnChange();
  // Abrir el picker en el próximo tick si el modo lo requiere.
  const modo = document.querySelector(`[data-cron-modo="${campo}"]`).value;
  if (modo !== 'star') setTimeout(() => abrirCronPicker(campo), 30);
}

function cronBuilderConstruirCampo(campo) {
  const modo = document.querySelector(`[data-cron-modo="${campo}"]`).value;
  const val  = document.querySelector(`[data-cron-valor="${campo}"]`).value.trim();
  if (modo === 'star') return '*';
  if (modo === 'step') return val ? `*/${val}` : '*';
  return val || '*';
}

function cronBuilderConstruir() {
  return CRON_CAMPOS.map((c) => cronBuilderConstruirCampo(c.key)).join(' ');
}

function cronBuilderOnChange() {
  const expr = cronBuilderConstruir();
  document.getElementById('cronBuilderPreview').textContent = expr;
  document.getElementById('cronBuilderDesc').textContent    = cronDescribir(expr);
}

function cronBuilderAplicar() {
  const expr = cronBuilderConstruir();
  document.getElementById('formTareaCron').value = expr;
  const err = document.getElementById('formTareaCronError');
  if (err) { err.style.display = 'none'; err.textContent = ''; }
  document.getElementById('formTareaCron').classList.remove('input-invalid');
  cerrarCronBuilder();
}

// --- Picker de valores ---

let cronPickerState = null;

function cronPickerRango(modo, cfg) {
  if (modo === 'step') {
    const arr = [];
    for (let n = 1; n <= cfg.max; n++) arr.push(n);
    return arr;
  }
  if (cfg.orden) return cfg.orden.slice();
  const arr = [];
  for (let n = cfg.min; n <= cfg.max; n++) arr.push(n);
  return arr;
}

function abrirCronPicker(campo) {
  const modoSel = document.querySelector(`[data-cron-modo="${campo}"]`);
  const valInp  = document.querySelector(`[data-cron-valor="${campo}"]`);
  if (!modoSel || !valInp) return;
  const modo = modoSel.value;
  if (modo === 'star') {
    toast('Elegí un modo (Exacto / Cada / Rango / Lista) primero.', { error: true });
    return;
  }
  const cfg = CRON_PICKER_CFG[campo];
  cronPickerState = {
    campo, modo, cfg,
    valor1: null, valor2: null,
    seleccionados: [],
  };
  cronPickerPreCargar(valInp.value.trim());
  document.getElementById('cronPickerEmoji').textContent  = cfg.emoji;
  document.getElementById('cronPickerTitulo').textContent = cfg.titulo;
  const hints = {
    exact: 'Modo: Elegí un único valor.',
    step:  'Modo: Cada N — elegí el intervalo.',
    range: 'Modo: Rango — elegí desde y hasta.',
    list:  'Modo: Lista — tocá varios para agregarlos.',
  };
  document.getElementById('cronPickerHint').textContent = hints[modo] || '';
  const wrap2 = document.getElementById('cronPickerGrupo2Wrap');
  document.getElementById('cronPickerLabel1').textContent = (modo === 'range') ? 'Desde' : 'Valores';
  wrap2.style.display = (modo === 'range') ? '' : 'none';
  cronPickerRender();
  document.getElementById('cronPickerBackdrop').classList.add('open');
}

function cerrarCronPicker() {
  document.getElementById('cronPickerBackdrop').classList.remove('open');
  cronPickerState = null;
}

function cronPickerPreCargar(actual) {
  if (!cronPickerState || !actual) return;
  const s = cronPickerState;
  if (s.modo === 'exact' || s.modo === 'step') {
    const n = parseInt(actual, 10);
    if (!isNaN(n)) s.valor1 = n;
  } else if (s.modo === 'range') {
    const m = actual.match(/^(\d+)-(\d+)$/);
    if (m) { s.valor1 = parseInt(m[1], 10); s.valor2 = parseInt(m[2], 10); }
  } else if (s.modo === 'list') {
    s.seleccionados = actual.split(',').map((x) => parseInt(x.trim(), 10)).filter((n) => !isNaN(n));
  }
}

function cronPickerRender() {
  if (!cronPickerState) return;
  const s = cronPickerState;
  const rangoBotones = cronPickerRango(s.modo, s.cfg);
  const g1 = document.getElementById('cronPickerGrupo1');
  g1.innerHTML = rangoBotones.map((n) => {
    let activo = false;
    if (s.modo === 'exact' || s.modo === 'step') activo = (s.valor1 === n);
    else if (s.modo === 'range')                 activo = (s.valor1 === n);
    else if (s.modo === 'list')                  activo = s.seleccionados.includes(n);
    // En modo `step` mostramos números plain (representan N, no un valor real).
    const label = (s.modo === 'step') ? String(n) : s.cfg.formato(n);
    return cronPickerBoton(n, activo, label, 1);
  }).join('');
  if (s.modo === 'range') {
    const g2 = document.getElementById('cronPickerGrupo2');
    g2.innerHTML = rangoBotones.map((n) => {
      const activo = (s.valor2 === n);
      const label = s.cfg.formato(n);
      return cronPickerBoton(n, activo, label, 2);
    }).join('');
  }
}

function cronPickerBoton(n, activo, label, grupo) {
  return `<button type="button" class="filter-chip cron-picker-btn${activo ? ' active' : ''}"
                  onclick="cronPickerSeleccionar(${n}, ${grupo})">${label}</button>`;
}

function cronPickerSeleccionar(n, grupo) {
  if (!cronPickerState) return;
  const s = cronPickerState;
  if (s.modo === 'exact' || s.modo === 'step') {
    s.valor1 = (s.valor1 === n) ? null : n;
  } else if (s.modo === 'range') {
    if (grupo === 1) s.valor1 = (s.valor1 === n) ? null : n;
    else             s.valor2 = (s.valor2 === n) ? null : n;
  } else if (s.modo === 'list') {
    const i = s.seleccionados.indexOf(n);
    if (i >= 0) s.seleccionados.splice(i, 1);
    else        s.seleccionados.push(n);
    s.seleccionados.sort((a, b) => a - b);
  }
  cronPickerRender();
}

function cronPickerLimpiar() {
  if (!cronPickerState) return;
  cronPickerState.valor1 = null;
  cronPickerState.valor2 = null;
  cronPickerState.seleccionados = [];
  cronPickerRender();
}

function cronPickerAplicar() {
  if (!cronPickerState) return;
  const s = cronPickerState;
  let out = '';
  if (s.modo === 'exact') {
    if (s.valor1 == null) { toast('Elegí un valor.', { error: true }); return; }
    out = String(s.valor1);
  } else if (s.modo === 'step') {
    if (s.valor1 == null) { toast('Elegí un intervalo.', { error: true }); return; }
    out = String(s.valor1);
  } else if (s.modo === 'range') {
    if (s.valor1 == null || s.valor2 == null) { toast('Elegí Desde y Hasta.', { error: true }); return; }
    if (s.valor1 > s.valor2) { toast('Desde debe ser ≤ Hasta.', { error: true }); return; }
    out = s.valor1 + '-' + s.valor2;
  } else if (s.modo === 'list') {
    if (!s.seleccionados.length) { toast('Elegí al menos un valor.', { error: true }); return; }
    out = s.seleccionados.join(',');
  }
  const inp = document.querySelector(`[data-cron-valor="${s.campo}"]`);
  if (inp) inp.value = out;
  cerrarCronPicker();
  cronBuilderOnChange();
}

// --- Descripción en español (best-effort) de una expresión cron ---

function cronDescribir(expr) {
  const partes = expr.split(/\s+/);
  if (partes.length !== 5) return '(expresión inválida)';
  const [m, h, dom, mon, dow] = partes;
  const partesTexto = [];
  partesTexto.push(cronDescHorario(m, h));
  if (dom !== '*') partesTexto.push(cronDescDom(dom));
  if (mon !== '*') partesTexto.push('en ' + cronDescMon(mon));
  if (dow !== '*') partesTexto.push(cronDescDow(dow));
  else if (dom === '*' && mon === '*') partesTexto.push('todos los días');
  const s = partesTexto.filter(Boolean).join(', ');
  return s.charAt(0).toUpperCase() + s.slice(1) + '.';
}

function cronDescHorario(m, h) {
  // Cada minuto...
  if (m === '*' && h === '*') return 'cada minuto';
  if (m.startsWith('*/') && h === '*') return `cada ${m.slice(2)} minutos`;
  if (m === '*' && /^\d+$/.test(h))    return `cada minuto entre las ${cronDescHora(h)}`;
  if (m === '0' && h === '*')          return 'al minuto 0 de cada hora';
  if (m === '0' && h.startsWith('*/')) return `cada ${h.slice(2)} horas en punto`;
  if (/^\d+$/.test(m) && h === '*')    return `al minuto ${parseInt(m, 10)} de cada hora`;
  if (/^\d+$/.test(m) && /^\d+$/.test(h)) {
    return `a las ${String(parseInt(h, 10)).padStart(2, '0')}:${String(parseInt(m, 10)).padStart(2, '0')}`;
  }
  return `en el patrón ${m} ${h}`;
}

function cronDescHora(h) {
  if (/^\d+$/.test(h)) return String(parseInt(h, 10)).padStart(2, '0') + ':00';
  return h;
}

function cronDescDom(dom) {
  if (/^\d+$/.test(dom))            return `el día ${dom} del mes`;
  if (dom.startsWith('*/'))         return `cada ${dom.slice(2)} días`;
  if (/^\d+-\d+$/.test(dom))        return `los días ${dom} del mes`;
  if (dom.includes(','))            return `los días ${dom} del mes`;
  return `en el patrón día=${dom}`;
}

function cronDescMon(mon) {
  if (/^\d+$/.test(mon))          return cronNombreMes(parseInt(mon, 10));
  if (mon.includes(','))          return mon.split(',').map((n) => cronNombreMes(parseInt(n, 10))).join(', ');
  if (/^(\d+)-(\d+)$/.test(mon))  {
    const m = mon.match(/^(\d+)-(\d+)$/);
    return `de ${cronNombreMes(+m[1])} a ${cronNombreMes(+m[2])}`;
  }
  return 'meses ' + mon;
}

function cronDescDow(dow) {
  if (/^\d+$/.test(dow))         return `los ${cronPluralDia(parseInt(dow, 10))}`;
  if (dow.includes(',')) {
    const nombres = dow.split(',').map((n) => cronPluralDia(parseInt(n, 10)));
    if (nombres.length === 1) return `los ${nombres[0]}`;
    return `los ${nombres.slice(0, -1).join(', ')} y ${nombres[nombres.length - 1]}`;
  }
  if (/^(\d+)-(\d+)$/.test(dow)) {
    const m = dow.match(/^(\d+)-(\d+)$/);
    return `de ${cronNombreDia(+m[1])} a ${cronNombreDia(+m[2])}`;
  }
  return 'día-semana=' + dow;
}

// --- Wiring de menús contextuales + Escape ---

document.addEventListener('DOMContentLoaded', () => {
  const mT = document.getElementById('tareasCtxMenu');
  if (mT) {
    mT.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-action]');
      if (!btn) return;
      const action = btn.getAttribute('data-action');
      const id     = tareasCtxRegistroId;
      cerrarMenuContextoTareas();
      if (!id) return;
      const t = tareasCache.find((x) => x.id === id);
      if (action === 'ver-ejecuciones') abrirEjecuciones(id);
      else if (action === 'ejecutar-ahora') ejecutarAhora(id);
      else if (action === 'toggle-activo') {
        if (t) toggleActivoTarea(id, !t.activo).then(() => cargarTareas());
      }
      else if (action === 'editar')    abrirEditarTarea(id);
      else if (action === 'eliminar')  eliminarTarea(id);
    });
  }

  const mE = document.getElementById('ejecucionesCtxMenu');
  if (mE) {
    mE.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-action]');
      if (!btn) return;
      const action = btn.getAttribute('data-action');
      const id     = ejecucionesCtxRegistroId;
      cerrarMenuContextoEjecuciones();
      if (!id) return;
      if (action === 'ver-log') abrirTerminal(id);
      else if (action === 'detener') detenerEjecucion(id);
    });
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const picker  = document.getElementById('cronPickerBackdrop');
  const builder = document.getElementById('cronBuilderBackdrop');
  const ctxT    = document.getElementById('tareasCtxMenu');
  const ctxE    = document.getElementById('ejecucionesCtxMenu');
  const term    = document.getElementById('terminalBackdrop');
  const form    = document.getElementById('formTareaBackdrop');
  const ejec    = document.getElementById('ejecucionesBackdrop');
  const listado = document.getElementById('tareasBackdrop');
  if (picker  && picker.classList.contains('open'))   { cerrarCronPicker(); return; }
  if (builder && builder.classList.contains('open'))  { cerrarCronBuilder(); return; }
  if (ctxT    && ctxT.classList.contains('open'))     { cerrarMenuContextoTareas(); return; }
  if (ctxE    && ctxE.classList.contains('open'))     { cerrarMenuContextoEjecuciones(); return; }
  if (term    && term.classList.contains('open'))     { cerrarTerminal(); return; }
  if (form    && form.classList.contains('open'))     { form.classList.remove('open'); return; }
  if (ejec    && ejec.classList.contains('open'))     { cerrarEjecuciones(); return; }
  if (listado && listado.classList.contains('open'))  { cerrarTareas(); }
});

// ------------------------- Boot -------------------------
window.addEventListener('hashchange', () => {
  if (AUTH.user) render();
});

window.addEventListener('DOMContentLoaded', async () => {
  bindChrome();
  bindLogin();
  $('#logoutBtn')?.addEventListener('click', doLogout);

  const user = await checkSession();
  if (!user) {
    showLoginScreen();
    return;
  }
  showAppShell(user);
  if (!location.hash) location.hash = '#/dashboard';
  render();
});
