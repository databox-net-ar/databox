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

// ------------------------- Boot -------------------------
window.addEventListener('hashchange', render);
window.addEventListener('DOMContentLoaded', () => {
  bindChrome();
  if (!location.hash) location.hash = '#/dashboard';
  render();
});
