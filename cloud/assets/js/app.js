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

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
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
  toast._h = setTimeout(() => t.classList.remove('show'), 2400);
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

function currentPath() {
  const h = location.hash || '#/dashboard';
  return h.startsWith('#') ? h.slice(1) : h;
}

async function render() {
  const path = currentPath();
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

    <div class="table-card" style="margin-top:20px">
      <div class="dash-table-header">
        <span>Plataformas</span>
      </div>
      <div style="padding:20px">
        <div class="tile-grid">
          <a href="https://us-east-1.console.aws.amazon.com/ec2/v2/home?region=us-east-1#Instances:"
             target="_blank" rel="noopener noreferrer" class="tile-card">
            <span class="tile-icon">☁️</span>
            <span class="tile-title">AWS</span>
            <span class="tile-desc">Consola EC2 (us-east-1).</span>
          </a>
          <a href="https://dash.cloudflare.com/"
             target="_blank" rel="noopener noreferrer" class="tile-card">
            <span class="tile-icon">🌐</span>
            <span class="tile-title">Cloudflare</span>
            <span class="tile-desc">Panel principal.</span>
          </a>
          <a href="https://ar151.xvserver.com:2087/cpsess3116822283/scripts4/listaccts"
             target="_blank" rel="noopener noreferrer" class="tile-card">
            <span class="tile-icon">🖥️</span>
            <span class="tile-title">Latincloud</span>
            <span class="tile-desc">WHM cPanel.</span>
          </a>
          <a href="https://evolution.york.databox.net.ar/manager/"
             target="_blank" rel="noopener noreferrer" class="tile-card">
            <span class="tile-icon">🤖</span>
            <span class="tile-title">Evolution API</span>
            <span class="tile-desc">Manager de instancias.</span>
          </a>
        </div>
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
const USR_ESTADOS = {
  A: { label: 'Activo',   badge: 'badge-success' },
  I: { label: 'Inactivo', badge: 'badge-danger'  },
  B: { label: 'Baja',     badge: 'badge-danger'  },
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
              <button type="button" class="filter-chip" data-estado="A">Activo</button>
              <button type="button" class="filter-chip" data-estado="I">Inactivo</button>
              <button type="button" class="filter-chip" data-estado="B">Baja</button>
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
        ${esEdicion
          ? `<div style="text-align:center;padding:40px"><div class="spin"></div></div>`
          : formUsuarioHtml({})}
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"   data-act="close">Cancelar</button>
        <button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear'}</button>
      </div>
    </div>
  `);

  if (esEdicion) {
    try {
      const u = await apiGet(`api/usuarios.php?id=${id}`);
      $('#modalRoot .modal-body').innerHTML = formUsuarioHtml(u);
    } catch (e) {
      $('#modalRoot .modal-body').innerHTML = `<div class="table-empty">Error: ${esc(e.message)}</div>`;
    }
  }

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close') closeModal();
    if (a.dataset.act === 'guardar') await guardarUsuario(id, a);
  });
}

function formUsuarioHtml(u) {
  const v = (k) => esc(u?.[k] ?? '');
  const sel = (k, val) => (u?.[k] ?? '') === val ? 'selected' : '';
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
          <option value="A" ${sel('estado','A') || (!u?.estado ? 'selected' : '')}>Activo</option>
          <option value="I" ${sel('estado','I')}>Inactivo</option>
          <option value="B" ${sel('estado','B')}>Baja</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Sistemas</label>
        <input type="text" id="uSistemas" maxlength="10" value="${v('sistemas')}">
      </div>
      <div class="form-group">
        <label>Roles</label>
        <input type="text" id="uRoles" value="${v('roles')}">
      </div>
    </div>
    <div class="form-group">
      <label>${u?.id ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña'}</label>
      <input type="password" id="uContrasena" autocomplete="new-password">
    </div>
    <div class="field-error" id="uError" style="display:none"></div>
  `;
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

  const payload = {
    nombre,
    dni:        $('#uDni').value.trim(),
    correo:     $('#uCorreo').value.trim(),
    celular:    $('#uCelular').value.trim(),
    nacimiento: $('#uNacimiento').value || null,
    estado:     $('#uEstado').value,
    sistemas:   $('#uSistemas').value.trim(),
    roles:      $('#uRoles').value.trim(),
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
  q: '', codigo: '', nombre: '', descripcion: '',
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
                   placeholder="🔍 Buscar nombre o descripción…">
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
              <th>Descripción</th>
              <th style="text-align:right">Permisos</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="rolTbody">
            <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
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
              <label>Nombre</label>
              <input type="text" id="fRolNombre" oninput="onFiltroRoles('nombre', this.value)">
            </div>
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
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(rolesFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/roles.php?' + qs.toString());
    pintarStatsRoles(data.stats);
    pintarTablaRoles(data.items);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
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
    tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Sin roles.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr data-id="${r.id}" class="row-clickable">
      <td class="td-id">#${esc(r.id)}</td>
      <td class="td-nombre">${esc(r.nombre || '—')}</td>
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
        ${fila('Permisos',    String(ids.length), false, false)}
        ${fila('Nombre',      r.nombre, true, false)}
        ${fila('Descripción', r.descripcion, true, false)}
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
        <label>Nombre *</label>
        <input type="text" id="rNombre" value="${v('nombre')}" required>
      </div>
      <div class="form-group">
        <label>Código</label>
        <input type="text" value="${r?.id ? '#' + r.id : '(se asigna al crear)'}" readonly>
      </div>
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

function marcarPermisos(checked) {
  $$('#rPermList .perm-item').forEach((el) => {
    if (el.style.display === 'none') return; // respetar el filtro activo
    const c = el.querySelector('.perm-check');
    if (c) c.checked = checked;
  });
}

async function guardarRol(id, btn) {
  const nombre = $('#rNombre').value.trim();
  const err    = $('#rError');
  err.style.display = 'none';
  $('#rNombre').classList.remove('input-invalid');

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
  q: '', codigo: '', nombre: '', descripcion: '',
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
                   placeholder="🔍 Buscar nombre o descripción…">
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
              <th>Descripción</th>
              <th style="text-align:center">Acciones</th>
            </tr>
          </thead>
          <tbody id="permTbody">
            <tr><td colspan="4" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
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
              <label>Nombre</label>
              <input type="text" id="fPermNombre" oninput="onFiltroPermisos('nombre', this.value)">
            </div>
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
  tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

  const qs = new URLSearchParams();
  Object.entries(permisosFiltros).forEach(([k, v]) => {
    if (v !== '' && v != null) qs.set(k, v);
  });
  try {
    const data = await apiGet('api/permisos.php?' + qs.toString());
    pintarStatsPermisos(data.stats);
    pintarTablaPermisos(data.items);
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="4" class="table-empty">Error: ${esc(e.message)}</td></tr>`;
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
    tbody.innerHTML = `<tr><td colspan="4" class="table-empty">Sin permisos.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map((p) => `
    <tr data-id="${p.id}" class="row-clickable">
      <td class="td-id">#${esc(p.id)}</td>
      <td class="td-nombre">${esc(p.nombre || '—')}</td>
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
    const fila = (label, value, full = false) => {
      const empty = value == null || value === '';
      const inner = empty ? 'Sin dato' : esc(value);
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
        <label>Nombre *</label>
        <input type="text" id="pNombre" value="${v('nombre')}" required>
      </div>
      <div class="form-group">
        <label>Código</label>
        <input type="text" value="${p?.id ? '#' + p.id : '(se asigna al crear)'}" readonly>
      </div>
    </div>
    <div class="form-group">
      <label>Descripción</label>
      <input type="text" id="pDescripcion" value="${v('descripcion')}">
    </div>
    <div class="field-error" id="pError" style="display:none"></div>
  `;
}

async function guardarPermiso(id, btn) {
  const nombre = $('#pNombre').value.trim();
  const err    = $('#pError');
  err.style.display = 'none';
  $('#pNombre').classList.remove('input-invalid');

  if (!nombre) {
    $('#pNombre').classList.add('input-invalid');
    err.textContent = 'El nombre es obligatorio.';
    err.style.display = '';
    return;
  }

  const payload = {
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

// ------------------------- Vista: Herramientas -------------------------
route('/herramientas', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Herramientas</div>
      <div class="page-subtitle">Utilidades para distintas áreas de la plataforma.</div>
    </div>

    <div class="tile-grid" id="toolsGrid">
      <button type="button" class="tile-card" onclick="abrirExploradorS3()">
        <span class="tile-icon">📁</span>
        <span class="tile-title">Explorador S3</span>
        <span class="tile-desc">Navegá, subí, descargá y eliminá carpetas y archivos del bucket de media del entorno actual.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirExploradorDB()">
        <span class="tile-icon">🗄️</span>
        <span class="tile-title">Explorador DB</span>
        <span class="tile-desc">Recorrá las tablas de la base del entorno actual, ojeá su estructura y los últimos registros.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirParametros()">
        <span class="tile-icon">🧩</span>
        <span class="tile-title">Editor de parámetros</span>
        <span class="tile-desc">Variables runtime (variable / valor) que el resto del sistema lee para configurarse sin redeploy.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirEstados()">
        <span class="tile-icon">🎚️</span>
        <span class="tile-title">Editor de estados</span>
        <span class="tile-desc">Catálogo de valores posibles (<code>campo</code> / <code>valor</code> / <code>texto</code>) para columnas de estado de las distintas tablas.</span>
      </button>
      <button type="button" class="tile-card" onclick="abrirMigraciones()">
        <span class="tile-icon">📜</span>
        <span class="tile-title">Migrador DB</span>
        <span class="tile-desc">Aplicá las migraciones pendientes de <code>cloud/sql/migrations/</code> contra la BD del entorno actual.</span>
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
const AUTH = { user: null };

async function checkSession() {
  try {
    const r = await fetch('api/auth.php?action=me', { credentials: 'same-origin' });
    if (!r.ok) return null;
    const j = await r.json().catch(() => null);
    return (j && j.ok) ? j.data.user : null;
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
  AUTH.user = null;
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
  tbody.innerHTML = rows.map((m) => {
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
    toast(e.message || 'Error al aplicar.', { error: true });
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
      toast(`Falló «${nombre}»: ${e.message}`, { error: true });
    }
    if (!exito) break;
  }
  if (aplicadas === pendientes.length) {
    toast(`Aplicadas ${aplicadas} migración${aplicadas === 1 ? '' : 'es'}.`);
  } else if (aplicadas > 0) {
    toast(`Corrida parcial: ${aplicadas} de ${pendientes.length} aplicadas.`, { error: true });
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

// ------------------------- Herramientas: Visor de sucesos -------------------------
// Visor read-only de la tabla `sucesos`. Los distintos modulos del panel
// escriben ahi su log de actividad (id / fecha / origen / detalle).
let sucesosCache         = [];
let sucesosFiltroQ       = '';
let _sucesosSearchTimer  = null;

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

async function cargarSucesos() {
  const tbody = document.getElementById('sucesosTbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>';

  const desde  = document.getElementById('sucesosDesde')?.value  || '';
  const hasta  = document.getElementById('sucesosHasta')?.value  || '';
  const limite = document.getElementById('sucesosLimite')?.value || '200';

  const params = new URLSearchParams();
  if (sucesosFiltroQ) params.set('q', sucesosFiltroQ);
  if (desde)          params.set('desde', desde);
  if (hasta)          params.set('hasta', hasta);
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
    tbody.innerHTML = '<tr><td colspan="4" class="table-empty">✗ ' + esc(e.message) + '</td></tr>';
  }
}

function renderSucesos(rows) {
  const tbody = document.getElementById('sucesosTbody');
  if (!tbody) return;
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="table-empty">Sin sucesos para mostrar.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((s) => {
    const fecha   = esc(s.fecha   || '');
    const origen  = esc(s.origen  || '');
    const detalle = esc(s.detalle || '');
    return `
      <tr class="row-clickable" data-id="${s.id}" onclick="sucesosVerDetalle(${s.id})">
        <td class="td-id">${s.id}</td>
        <td style="font-family:monospace;white-space:nowrap">${fecha || '<span style="color:var(--muted);font-style:italic">—</span>'}</td>
        <td style="font-family:monospace;font-weight:600">${origen || '<span style="color:var(--muted);font-style:italic">—</span>'}</td>
        <td style="color:var(--muted);max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="${detalle}">${detalle}</td>
      </tr>
    `;
  }).join('');
}

function sucesosVerDetalle(id) {
  const s = sucesosCache.find((x) => x.id === id);
  if (!s) return;
  document.getElementById('sucesoDetalleId').textContent     = s.id;
  document.getElementById('sucesoDetalleFecha').textContent  = s.fecha  || '—';
  document.getElementById('sucesoDetalleOrigen').textContent = s.origen || '—';
  document.getElementById('sucesoDetalleTexto').value        = s.detalle || '';
  document.getElementById('sucesoDetalleBackdrop').classList.add('open');
}

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const detalle = document.getElementById('sucesoDetalleBackdrop');
  const listado = document.getElementById('sucesosBackdrop');
  if (detalle && detalle.classList.contains('open')) { detalle.classList.remove('open'); return; }
  if (listado && listado.classList.contains('open')) { cerrarVisorSucesos(); }
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
