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
const usuariosFiltros = {
  codigo: '', nombre: '', dni: '', correo: '', celular: '', estado: '',
  order_by: 'id', dir: 'desc', limite: 100,
};
let usuariosBuscadorTimer = null;

route('/usuarios', async (mount) => {
  mount.innerHTML = `
    <div class="page-header">
      <div class="page-title">Usuarios</div>
      <div class="page-subtitle">Cuentas con acceso a la plataforma.</div>
    </div>

    <div class="stats-bar" id="usrStats">
      <div class="stat-card"><span class="stat-label">Total</span><span class="stat-value">—</span></div>
      <div class="stat-card"><span class="stat-label">Activos</span><span class="stat-value green">—</span></div>
      <div class="stat-card"><span class="stat-label">Inactivos</span><span class="stat-value red">—</span></div>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <input type="search" class="search-input" id="usrSearch"
                 placeholder="Buscar nombre, correo, DNI…">
          <button class="search-clear" id="usrSearchClear" style="display:none">×</button>
        </div>
        <button class="btn btn-ghost" id="usrFiltrosBtn">
          <i class="fa-solid fa-filter"></i> Filtros
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
            <th style="text-align:right">Acciones</th>
          </tr>
        </thead>
        <tbody id="usrTbody">
          <tr><td colspan="7" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
        </tbody>
      </table>
    </div>
  `;

  $('#usrNuevoBtn').addEventListener('click', () => abrirAltaEdicion(null));
  $('#usrFiltrosBtn').addEventListener('click', () => abrirFiltros());

  const inp = $('#usrSearch');
  const clr = $('#usrSearchClear');
  inp.value = usuariosFiltros.q || '';
  clr.style.display = inp.value ? '' : 'none';
  inp.addEventListener('input', () => {
    clr.style.display = inp.value ? '' : 'none';
    usuariosFiltros.q = inp.value.trim();
    clearTimeout(usuariosBuscadorTimer);
    usuariosBuscadorTimer = setTimeout(cargarUsuarios, 250);
  });
  clr.addEventListener('click', () => {
    inp.value = '';
    clr.style.display = 'none';
    usuariosFiltros.q = '';
    cargarUsuarios();
  });

  $('#usrTbody').addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) return;
    const id = Number(btn.dataset.id);
    if (btn.dataset.act === 'view')   abrirConsultar(id);
    if (btn.dataset.act === 'edit')   abrirAltaEdicion(id);
    if (btn.dataset.act === 'delete') eliminarUsuario(id);
  });

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
    pintarTablaUsuarios(data.items);
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
      <tr>
        <td class="td-id">#${esc(u.id)}</td>
        <td class="td-nombre">${esc(u.nombre || '—')}</td>
        <td>${esc(u.dni || '—')}</td>
        <td>${esc(u.correo || '—')}</td>
        <td>${esc(u.celular || '—')}</td>
        <td><span class="badge ${est.badge}">${esc(est.label)}</span></td>
        <td>
          <div class="actions" style="justify-content:flex-end">
            <button class="action-icon view"   title="Consultar" data-act="view"   data-id="${u.id}"><i class="fa-regular fa-eye"></i></button>
            <button class="action-icon edit"   title="Editar"    data-act="edit"   data-id="${u.id}"><i class="fa-solid fa-pencil"></i></button>
            <button class="action-icon delete" title="Eliminar"  data-act="delete" data-id="${u.id}"><i class="fa-solid fa-trash"></i></button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// ---- Modal de Filtros ----
function abrirFiltros() {
  const f = usuariosFiltros;
  openModal(`
    <div class="modal">
      <div class="modal-header">
        <div class="modal-title">Filtros</div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label>Código</label>
            <input type="number" id="fCodigo" value="${esc(f.codigo)}">
          </div>
          <div class="form-group">
            <label>Nombre</label>
            <input type="text" id="fNombre" value="${esc(f.nombre)}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>DNI</label>
            <input type="text" id="fDni" value="${esc(f.dni)}">
          </div>
          <div class="form-group">
            <label>Correo</label>
            <input type="text" id="fCorreo" value="${esc(f.correo)}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Celular</label>
            <input type="text" id="fCelular" value="${esc(f.celular)}">
          </div>
          <div class="form-group">
            <label>Estado</label>
            <select id="fEstado">
              <option value=""  ${f.estado === ''  ? 'selected' : ''}>Todos</option>
              <option value="A" ${f.estado === 'A' ? 'selected' : ''}>Activo</option>
              <option value="I" ${f.estado === 'I' ? 'selected' : ''}>Inactivo</option>
              <option value="B" ${f.estado === 'B' ? 'selected' : ''}>Baja</option>
            </select>
          </div>
        </div>
        <div class="form-row-3">
          <div class="form-group">
            <label>Límite</label>
            <input type="number" id="fLimite" min="1" max="1000" value="${esc(f.limite)}">
          </div>
          <div class="form-group">
            <label>Ordenar por</label>
            <select id="fOrderBy">
              <option value="id"        ${f.order_by === 'id'        ? 'selected' : ''}>Código</option>
              <option value="nombre"    ${f.order_by === 'nombre'    ? 'selected' : ''}>Nombre</option>
              <option value="dni"       ${f.order_by === 'dni'       ? 'selected' : ''}>DNI</option>
              <option value="correo"    ${f.order_by === 'correo'    ? 'selected' : ''}>Correo</option>
              <option value="registrado"${f.order_by === 'registrado'? 'selected' : ''}>Fecha de registro</option>
              <option value="estado"    ${f.order_by === 'estado'    ? 'selected' : ''}>Estado</option>
            </select>
          </div>
          <div class="form-group">
            <label>Dirección</label>
            <select id="fDir">
              <option value="asc"  ${f.dir === 'asc'  ? 'selected' : ''}>Ascendente</option>
              <option value="desc" ${f.dir === 'desc' ? 'selected' : ''}>Descendente</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"     data-act="limpiar"  style="margin-right:auto">Limpiar</button>
        <button class="btn btn-secondary" data-act="close">Cancelar</button>
        <button class="btn btn-primary"   data-act="aplicar">Aplicar</button>
      </div>
    </div>
  `);

  $('#modalRoot').addEventListener('click', async (ev) => {
    const a = ev.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'close')   closeModal();
    if (a.dataset.act === 'limpiar') resetFiltros();
    if (a.dataset.act === 'aplicar') {
      usuariosFiltros.codigo   = $('#fCodigo').value.trim();
      usuariosFiltros.nombre   = $('#fNombre').value.trim();
      usuariosFiltros.dni      = $('#fDni').value.trim();
      usuariosFiltros.correo   = $('#fCorreo').value.trim();
      usuariosFiltros.celular  = $('#fCelular').value.trim();
      usuariosFiltros.estado   = $('#fEstado').value;
      usuariosFiltros.limite   = Number($('#fLimite').value) || 100;
      usuariosFiltros.order_by = $('#fOrderBy').value;
      usuariosFiltros.dir      = $('#fDir').value;
      closeModal();
      cargarUsuarios();
    }
  });
}

function resetFiltros() {
  $('#fCodigo').value  = '';
  $('#fNombre').value  = '';
  $('#fDni').value     = '';
  $('#fCorreo').value  = '';
  $('#fCelular').value = '';
  $('#fEstado').value  = '';
  $('#fLimite').value  = '100';
  $('#fOrderBy').value = 'id';
  $('#fDir').value     = 'desc';
}

// ---- Modal Consultar ----
async function abrirConsultar(id) {
  openModal(`
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title">Consultar usuario <span class="modal-subtitle">#${id}</span></div>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
      <div class="modal-body"><div style="text-align:center;padding:40px"><div class="spin"></div></div></div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-act="close">Cerrar</button>
      </div>
    </div>
  `);
  $('#modalRoot').addEventListener('click', (ev) => {
    if (ev.target.closest('[data-act="close"]')) closeModal();
  });

  try {
    const u = await apiGet(`api/usuarios.php?id=${id}`);
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
        ${fila('Código',    '#' + u.id, false, false)}
        ${fila('Estado',    est.label,  false, false)}
        ${fila('Nombre',    u.nombre,   true,  false)}
        ${fila('DNI',       u.dni)}
        ${fila('Nacimiento',u.nacimiento)}
        ${fila('Correo',    u.correo,   true,  false)}
        ${fila('Celular',   u.celular)}
        ${fila('Sistemas',  u.sistemas)}
        ${fila('Roles',     u.roles,    true,  false)}
        ${fila('Terminal',  u.terminal)}
        ${fila('UUID',      u.uuid,     true,  true)}
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
