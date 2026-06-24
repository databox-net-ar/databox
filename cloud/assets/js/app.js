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
      <div class="table-empty" style="grid-column:1/-1">
        Aún no hay herramientas disponibles.
      </div>
    </div>
  `;
}, 'Herramientas');

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
