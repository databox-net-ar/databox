<?php
// SPA shell. Todo el render del contenido lo hace assets/js/app.js
// segun el hash de la URL (#/dashboard, #/...).
$cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();
$jsVer  = @filemtime(__DIR__ . '/assets/js/app.js')   ?: time();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Databox Cloud</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVer ?>">
</head>
<body class="auth-checking">
  <div class="auth-splash" id="authSplash">
    <div class="spin"></div>
  </div>

  <div class="login-screen" id="loginScreen" hidden>
    <form class="login-card" id="loginForm" autocomplete="on" novalidate>
      <img src="assets/img/logo_light.png" class="login-logo" alt="Databox">
      <div class="login-title">Ingresar al panel</div>

      <div class="form-group">
        <label for="loginCorreo">Correo</label>
        <input type="email" id="loginCorreo" name="correo" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label for="loginContrasena">Contraseña</label>
        <input type="password" id="loginContrasena" name="contrasena" autocomplete="current-password" required>
      </div>

      <div class="field-error" id="loginError" hidden></div>

      <button type="submit" class="btn btn-primary login-submit" id="loginSubmit">
        Ingresar
      </button>
    </form>
  </div>

  <div class="layout">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <img src="assets/img/logo_light.png" class="sidebar-logo-mark" alt="Databox">
      </div>

      <nav class="sidebar-nav">
        <div class="nav-group-wrap" data-group="inicio">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">🏠</span>
            <span class="nav-group-label">Inicio</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/dashboard" class="nav-item nav-sub-item" data-route="/dashboard">
              <span class="nav-icon">📊</span> Dashboard
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="datacount">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">📒</span>
            <span class="nav-group-label">Datacount</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/datacountcomprobantes" class="nav-item nav-sub-item" data-route="/datacountcomprobantes">
              <span class="nav-icon">🧾</span> Comprobantes
            </a>
            <a href="#/datacountfacturacion" class="nav-item nav-sub-item" data-route="/datacountfacturacion">
              <span class="nav-icon">🤖</span> Facturación
            </a>
            <a href="#/datacountasientos" class="nav-item nav-sub-item" data-route="/datacountasientos">
              <span class="nav-icon">📖</span> Asientos
            </a>
            <a href="#/datacountrecurrentes" class="nav-item nav-sub-item" data-route="/datacountrecurrentes">
              <span class="nav-icon">🔁</span> Movimientos recurrentes
            </a>
            <a href="#/datacountcuentas" class="nav-item nav-sub-item" data-route="/datacountcuentas">
              <span class="nav-icon">📒</span> Plan de cuentas
            </a>
            <a href="#/datacountempresas" class="nav-item nav-sub-item" data-route="/datacountempresas">
              <span class="nav-icon">🏢</span> Empresas
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="datarocket">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">🚀</span>
            <span class="nav-group-label">Datarocket</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/datarocketcontactos" class="nav-item nav-sub-item" data-route="/datarocketcontactos">
              <span class="nav-icon">👥</span> Contactos
            </a>
            <a href="#/datarocketmensajes" class="nav-item nav-sub-item" data-route="/datarocketmensajes">
              <span class="nav-icon">✉️</span> Mensajes
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="datasale">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">💰</span>
            <span class="nav-group-label">Datasale</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/prospectos" class="nav-item nav-sub-item" data-route="/prospectos">
              <span class="nav-icon">🎯</span> Prospectos
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="plataformas">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">🌐</span>
            <span class="nav-group-label">Plataformas</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/aws" class="nav-item nav-sub-item" data-route="/aws">
              <span class="nav-icon">☁️</span> AWS
            </a>
            <a href="#/awsses" class="nav-item nav-sub-item" data-route="/awsses">
              <span class="nav-icon">📧</span> AWS SES
            </a>
            <a href="#/evolution" class="nav-item nav-sub-item" data-route="/evolution">
              <span class="nav-icon">💬</span> Evolution API
            </a>
            <a href="#/mercadopago" class="nav-item nav-sub-item" data-route="/mercadopago">
              <span class="nav-icon">💳</span> Mercadopago
            </a>
            <a href="#/dolarhoy" class="nav-item nav-sub-item" data-route="/dolarhoy">
              <span class="nav-icon">💵</span> Dolarhoy
            </a>
            <a href="#/movistar" class="nav-item nav-sub-item" data-route="/movistar">
              <span class="nav-icon">📡</span> Movistar
            </a>
            <a href="#/claro" class="nav-item nav-sub-item" data-route="/claro">
              <span class="nav-icon">📡</span> Claro
            </a>
            <a href="#/openai" class="nav-item nav-sub-item" data-route="/openai">
              <span class="nav-icon">🤖</span> OpenAI
            </a>
            <a href="#/anthropic" class="nav-item nav-sub-item" data-route="/anthropic">
              <span class="nav-icon">✨</span> Anthropic
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="seguridad">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">🔐</span>
            <span class="nav-group-label">Seguridad</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/usuarios" class="nav-item nav-sub-item" data-route="/usuarios">
              <span class="nav-icon">👥</span> Usuarios
            </a>
            <a href="#/roles" class="nav-item nav-sub-item" data-route="/roles">
              <span class="nav-icon">🛡️</span> Roles
            </a>
            <a href="#/permisos" class="nav-item nav-sub-item" data-route="/permisos">
              <span class="nav-icon">🔑</span> Permisos
            </a>
          </div>
        </div>

        <div class="nav-group-wrap" data-group="administracion">
          <button type="button" class="nav-item nav-group-toggle">
            <span class="nav-icon">⚙️</span>
            <span class="nav-group-label">Administración</span>
            <span class="nav-group-arrow">+</span>
          </button>
          <div class="nav-sub">
            <a href="#/herramientas" class="nav-item nav-sub-item" data-route="/herramientas">
              <span class="nav-icon">🛠️</span> Herramientas
            </a>
          </div>
        </div>
      </nav>

      <div class="sidebar-footer">v<span id="appVersion">dev</span></div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main">
      <div class="topbar">
        <button class="hamburger" id="hamburger" aria-label="Menú">☰</button>
        <div class="topbar-title" id="topbarTitle">Dashboard</div>
        <div class="topbar-enlaces">
          <button type="button" class="topbar-enlaces-btn" id="enlacesBtn"
                  title="Enlaces externos (Alt+E)">
            <i class="fa-solid fa-link"></i>
            <span class="topbar-enlaces-label">Enlaces</span>
            <i class="fa-solid fa-chevron-down" style="font-size:.65rem;opacity:.7"></i>
          </button>
          <div class="enlaces-menu" id="enlacesMenu" role="menu" aria-label="Enlaces externos">
            <div class="enlaces-menu-cats" id="enlacesMenuCats" role="none"></div>
            <div class="enlaces-menu-items" id="enlacesMenuItems" role="none">
              <div class="enlaces-menu-empty">Pasá el mouse sobre una categoría.</div>
            </div>
          </div>
        </div>

        <div class="topbar-user">
          <button class="topbar-username" id="userBtn">
            <span id="userBtnName">—</span> <i class="fa-solid fa-chevron-down" style="font-size:.7rem"></i>
          </button>
          <div class="user-dropdown" id="userDropdown">
            <button class="action-menu-item" id="logoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
          </div>
        </div>
      </div>

      <div class="content" id="view">
        <div style="text-align:center;padding:60px 0"><div class="spin"></div></div>
      </div>
    </div>
  </div>

  <!-- ===== Modal Explorador S3 ===== -->
  <div class="modal-backdrop" id="s3ExpModalBackdrop"
       onclick="if(event.target===this)cerrarExploradorS3()">
    <div class="modal s3-exp-modal">
      <div class="modal-header">
        <div class="modal-title">
          <span style="font-size:1.2rem">☁️</span>
          <span>Explorador S3</span>
          <span class="badge badge-info" id="s3ExpBucket" style="font-family:monospace">—</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarExploradorS3()" title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <div class="s3-exp-toolbar">
          <div class="s3-exp-breadcrumbs" id="s3ExpBreadcrumbs"></div>
          <div class="s3-exp-toolbar-right">
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="s3ExpRecargar()">
              <i class="fa-solid fa-rotate"></i>
            </button>
            <input type="file" id="s3ExpUploadInput" style="display:none"
                   onchange="s3ExpSubirArchivo(this.files)">
            <button class="btn btn-secondary btn-sm"
                    onclick="document.getElementById('s3ExpUploadInput').click()">
              <i class="fa-solid fa-upload"></i> Subir
            </button>
            <button class="btn btn-secondary btn-sm" onclick="s3ExpCrearCarpeta()">
              <i class="fa-solid fa-folder-plus"></i> Nueva carpeta
            </button>
          </div>
        </div>
        <div class="table-card s3-exp-table-card">
          <table>
            <thead>
              <tr>
                <th style="width:36px"></th>
                <th>Nombre</th>
                <th style="width:120px">Tamaño</th>
                <th style="width:160px">Modificado</th>
                <th style="width:60px; text-align:center">Acciones</th>
              </tr>
            </thead>
            <tbody id="s3ExpTbody">
              <tr><td colspan="5" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>
        <div class="s3-exp-footer-info" id="s3ExpFooterInfo"></div>
        <div style="text-align:center">
          <button class="btn btn-ghost btn-sm" id="s3ExpBtnMas" style="display:none" onclick="s3ExpCargarMas()">Cargar más</button>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarExploradorS3()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Menú contextual del Explorador S3 -->
  <div id="s3ExpCtxMenu" class="ctx-menu" role="menu">
    <button type="button" data-action="abrir" role="menuitem">
      <i class="fa-solid fa-up-right-from-square"></i><span>Abrir / Descargar</span>
    </button>
    <button type="button" data-action="copiar-url" role="menuitem">
      <i class="fa-solid fa-link"></i><span>Copiar URL pública</span>
    </button>
    <div class="ctx-menu-sep"></div>
    <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
      <i class="fa-solid fa-trash"></i><span>Eliminar</span>
    </button>
  </div>

  <!-- ===== Modal Explorador DB ===== -->
  <div class="modal-backdrop" id="dbExpModalBackdrop"
       onclick="if(event.target===this)cerrarExploradorDB()">
    <div class="modal db-exp-modal">
      <div class="modal-header">
        <div class="modal-title">
          <span style="font-size:1.2rem">🗄️</span>
          <span>Explorador DB</span>
          <span class="badge badge-info" id="dbExpDbName" style="font-family:monospace">—</span>
          <span class="badge" id="dbExpEnvBadge" style="font-family:monospace">—</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarExploradorDB()" title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <div class="db-exp-toolbar">
          <div class="db-exp-breadcrumbs" id="dbExpBreadcrumbs"></div>
          <div class="db-exp-toolbar-right">
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="dbExpRecargar()">
              <i class="fa-solid fa-rotate"></i>
            </button>
            <div class="search-wrap" id="dbExpSearchWrap" style="display:none">
              <input type="search" id="dbExpSearch" class="search-input"
                     placeholder="Buscar tabla…" oninput="dbExpFiltrarTablas()">
              <button class="search-clear" onclick="dbExpLimpiarBuscador()">×</button>
            </div>
          </div>
        </div>

        <!-- Vista 1: listado de tablas -->
        <div class="db-exp-view" id="dbExpViewTables">
          <div class="table-card db-exp-table-card">
            <table>
              <thead>
                <tr>
                  <th style="width:36px"></th>
                  <th>Tabla</th>
                  <th style="width:140px">Filas (aprox.)</th>
                  <th style="width:120px">Engine</th>
                </tr>
              </thead>
              <tbody id="dbExpTablesTbody">
                <tr><td colspan="4" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
              </tbody>
            </table>
          </div>
          <div class="db-exp-footer-info" id="dbExpTablesInfo"></div>
        </div>

        <!-- Vista 2: detalle de tabla (tabs: campos / registros) -->
        <div class="db-exp-view db-exp-view-detail" id="dbExpViewDetail" style="display:none">
          <div class="db-exp-tabs" role="tablist">
            <button type="button" class="db-exp-tab active" role="tab"
                    data-tab="recs" onclick="dbExpCambiarTab('recs')">
              <i class="fa-solid fa-table"></i> Registros
              <span class="db-exp-tab-count" id="dbExpRecsMeta"></span>
            </button>
            <button type="button" class="db-exp-tab" role="tab"
                    data-tab="cols" onclick="dbExpCambiarTab('cols')">
              <i class="fa-solid fa-list-ul"></i> Campos
              <span class="db-exp-tab-count" id="dbExpColsMeta"></span>
            </button>
          </div>

          <div class="db-exp-tabpanel" id="dbExpTabRecs" role="tabpanel">
            <div class="db-exp-recs-toolbar">
              <div class="db-exp-recs-toolbar-left">
                <label class="db-exp-limite-label">Límite
                  <select id="dbExpLimite" onchange="dbExpCambiarLimite()">
                    <option value="10">10</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                  </select>
                </label>
              </div>
              <div class="db-exp-recs-toolbar-right">
                <div class="search-wrap">
                  <input type="search" id="dbExpRecsSearch" class="search-input"
                         placeholder="Buscar en los registros…" oninput="dbExpFiltrarRegistros()">
                  <button class="search-clear" onclick="dbExpLimpiarBuscadorRegs()">×</button>
                </div>
              </div>
            </div>
            <div class="table-card db-exp-table-card db-exp-recs-card db-exp-fill">
              <table id="dbExpRecsTable">
                <thead><tr><th></th></tr></thead>
                <tbody id="dbExpRecsTbody">
                  <tr><td style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="db-exp-tabpanel" id="dbExpTabCols" role="tabpanel" hidden>
            <div class="table-card db-exp-table-card db-exp-fill">
              <table>
                <thead>
                  <tr>
                    <th style="width:36px">#</th>
                    <th>Campo</th>
                    <th>Tipo</th>
                    <th style="width:70px">Null</th>
                    <th style="width:70px">Clave</th>
                    <th>Default</th>
                    <th>Extra</th>
                  </tr>
                </thead>
                <tbody id="dbExpColsTbody">
                  <tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarExploradorDB()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Editor de parámetros ===== -->
  <div class="modal-backdrop" id="parametrosBackdrop"
       onclick="if(event.target===this)cerrarParametros()">
    <div class="modal" style="max-width:880px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">🧩</span>
          <span>Editor de parámetros</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarParametros()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
            <div class="search-wrap">
              <input class="search-input" type="search" id="parametrosSearch"
                     placeholder="🔍 Buscar variable, valor, comentario…"
                     oninput="parametrosOnSearch(this.value)">
              <button class="search-clear" id="parametrosSearchClear" style="display:none"
                      onclick="parametrosLimpiarBusqueda()">×</button>
            </div>
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarParametros()">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
          <div class="toolbar-right">
            <button class="btn btn-primary" onclick="abrirNuevoParametro()">+ Nuevo parámetro</button>
          </div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th style="width:80px">Código</th>
                <th style="width:220px">Variable</th>
                <th>Valor</th>
                <th>Comentario</th>
                <th style="width:60px; text-align:center">Acciones</th>
              </tr>
            </thead>
            <tbody id="parametrosTbody">
              <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarParametros()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Modal Alta / Edición de parámetro -->
  <div class="modal-backdrop" id="formParametroBackdrop"
       onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-title" id="formParametroTitulo" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">🧩</span>
          <span>Nuevo parámetro</span>
        </div>
        <button class="btn-icon-sm" type="button"
                onclick="document.getElementById('formParametroBackdrop').classList.remove('open')"
                title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="formParametroId" value="">
        <div class="form-group">
          <label for="formParametroVariable">Variable</label>
          <input type="text" id="formParametroVariable"
                 placeholder="ej. smtp_host, moneda_default"
                 autocomplete="off" autocapitalize="none" spellcheck="false"
                 maxlength="255" style="font-family:monospace">
          <div class="field-error" id="formParametroVariableError" style="display:none"></div>
        </div>
        <div class="form-group">
          <label for="formParametroValor">Valor</label>
          <textarea id="formParametroValor" placeholder="Valor del parámetro…"
                    rows="3" maxlength="255" style="font-family:monospace"></textarea>
          <div class="field-error" id="formParametroValorError" style="display:none"></div>
        </div>
        <div class="form-group">
          <label for="formParametroComentario">
            Comentario <span style="font-weight:400;color:var(--muted)">— opcional</span>
          </label>
          <textarea id="formParametroComentario"
                    placeholder="Para qué se usa este parámetro"
                    rows="2" maxlength="1024"></textarea>
          <div class="field-error" id="formParametroComentarioError" style="display:none"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="document.getElementById('formParametroBackdrop').classList.remove('open')">Cancelar</button>
        <button class="btn btn-primary" id="btnGuardarParametro" onclick="guardarParametro()">Guardar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Editor de estados ===== -->
  <div class="modal-backdrop" id="estadosBackdrop"
       onclick="if(event.target===this)cerrarEstados()">
    <div class="modal" style="max-width:960px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">🎚️</span>
          <span>Editor de estados</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarEstados()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
            <div class="search-wrap">
              <input class="search-input" type="search" id="estadosSearch"
                     placeholder="🔍 Buscar campo, texto, valor…"
                     oninput="estadosOnSearch(this.value)">
              <button class="search-clear" id="estadosSearchClear" style="display:none"
                      onclick="estadosLimpiarBusqueda()">×</button>
            </div>
            <select id="estadosCampoFiltro" onchange="cargarEstados()" style="min-width:200px">
              <option value="">— Todos los campos —</option>
            </select>
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarEstados()">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
          <div class="toolbar-right">
            <button class="btn btn-primary" onclick="abrirNuevoEstado()">+ Nuevo estado</button>
          </div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th style="width:80px">Código</th>
                <th style="width:240px">Campo</th>
                <th style="width:120px">Valor</th>
                <th>Texto</th>
                <th style="width:80px;text-align:center">Orden</th>
                <th style="width:60px;text-align:center">Acciones</th>
              </tr>
            </thead>
            <tbody id="estadosTbody">
              <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarEstados()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Modal Alta / Edición de estado -->
  <div class="modal-backdrop" id="formEstadoBackdrop"
       onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal" style="max-width:560px">
      <div class="modal-header">
        <div class="modal-title" id="formEstadoTitulo" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">🎚️</span>
          <span>Nuevo estado</span>
        </div>
        <button class="btn-icon-sm" type="button"
                onclick="document.getElementById('formEstadoBackdrop').classList.remove('open')"
                title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="formEstadoId" value="">
        <div class="form-group">
          <label for="formEstadoCampo">Campo</label>
          <input type="text" id="formEstadoCampo"
                 placeholder="ej. datacountcomprobantes.estado"
                 autocomplete="off" autocapitalize="none" spellcheck="false"
                 maxlength="255" style="font-family:monospace" list="formEstadoCampoLista">
          <datalist id="formEstadoCampoLista"></datalist>
          <div class="field-error" id="formEstadoCampoError" style="display:none"></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="formEstadoValor">Valor</label>
            <input type="text" id="formEstadoValor"
                   placeholder="ej. A, 1, B…"
                   autocomplete="off" autocapitalize="none" spellcheck="false"
                   maxlength="255" style="font-family:monospace">
            <div class="field-error" id="formEstadoValorError" style="display:none"></div>
          </div>
          <div class="form-group">
            <label for="formEstadoOrden">
              Orden <span style="font-weight:400;color:var(--muted)">— opcional</span>
            </label>
            <input type="number" id="formEstadoOrden" placeholder="0" step="1">
            <div class="field-error" id="formEstadoOrdenError" style="display:none"></div>
          </div>
        </div>
        <div class="form-group">
          <label for="formEstadoTexto">Texto</label>
          <input type="text" id="formEstadoTexto"
                 placeholder="ej. Activo, Pendiente, Anulado…"
                 maxlength="255">
          <div class="field-error" id="formEstadoTextoError" style="display:none"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="document.getElementById('formEstadoBackdrop').classList.remove('open')">Cancelar</button>
        <button class="btn btn-primary" id="btnGuardarEstado" onclick="guardarEstado()">Guardar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Migrador DB ===== -->
  <div class="modal-backdrop" id="migracionesBackdrop"
       onclick="if(event.target===this)cerrarMigraciones()">
    <div class="modal" style="max-width:960px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">📜</span>
          <span>Migrador DB</span>
          <span class="badge badge-info" id="migrDbName" style="font-family:monospace">—</span>
          <span class="badge" id="migrEnvBadge" style="font-family:monospace">—</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarMigraciones()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarMigraciones()">
              <i class="fa-solid fa-rotate"></i>
            </button>
            <span id="migrResumen" style="font-size:.82rem;color:var(--muted)"></span>
          </div>
          <div class="toolbar-right">
            <button class="btn btn-primary" id="migrBtnAplicarPendientes"
                    onclick="aplicarPendientesMigraciones()" disabled>
              Aplicar todas las pendientes
            </button>
          </div>
        </div>

        <div class="table-card" style="max-height:52vh;overflow-y:auto">
          <table>
            <thead>
              <tr>
                <th style="width:110px;position:sticky;top:0;background:var(--bg);z-index:1">Estado</th>
                <th style="position:sticky;top:0;background:var(--bg);z-index:1">Archivo</th>
                <th style="width:90px;position:sticky;top:0;background:var(--bg);z-index:1">Tamaño</th>
                <th style="width:110px;position:sticky;top:0;background:var(--bg);z-index:1">Hash</th>
                <th style="width:160px;position:sticky;top:0;background:var(--bg);z-index:1">Aplicada</th>
                <th style="width:160px;text-align:center;position:sticky;top:0;background:var(--bg);z-index:1">Acciones</th>
              </tr>
            </thead>
            <tbody id="migrTbody">
              <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>

        <div style="font-size:.78rem;color:var(--muted);line-height:1.5">
          Los archivos viven en <code style="font-family:monospace">cloud/sql/migrations/</code>
          y se aplican en orden alfabético. Cada migración se registra en la tabla
          <code style="font-family:monospace">migraciones</code> de la BD del entorno actual
          para no re-ejecutarse. <strong>El target es siempre la BD del propio panel</strong>
          — el panel de dev aplica contra <code style="font-family:monospace">databox_dev</code>;
          el panel de prod aplica contra RDS.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarMigraciones()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Modal preview SQL de migración -->
  <div class="modal-backdrop" id="migrPreviewBackdrop"
       onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">📜</span>
          <span>Migración</span>
          <span class="modal-subtitle"><code id="migrPreviewNombre" style="font-family:monospace">—</code></span>
        </div>
        <button class="btn-icon-sm" type="button"
                onclick="document.getElementById('migrPreviewBackdrop').classList.remove('open')"
                title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Contenido SQL (solo lectura)</label>
          <textarea class="json-editor" id="migrPreviewSql" readonly
                    spellcheck="false" autocomplete="off"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="document.getElementById('migrPreviewBackdrop').classList.remove('open')">Cerrar</button>
        <button class="btn btn-primary" id="migrPreviewBtnAplicar" onclick="migrPreviewAplicar()">
          Aplicar
        </button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Sincronizador de tablas ===== -->
  <div class="modal-backdrop" id="sincronizadorBackdrop"
       onclick="if(event.target===this)cerrarSincronizador()">
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">🔄</span>
          <span>Sincronizador de tablas</span>
          <span id="sincResumen" class="modal-subtitle"></span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarSincronizador()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:16px">
        <div class="form-row">
          <div class="form-group">
            <label for="sincOrigen">Origen</label>
            <select id="sincOrigen" onchange="sincOnCambioOrigen()">
              <option value="">— Elegí origen —</option>
              <option value="dev">Desarrollo (databox_dev)</option>
              <option value="prod">Producción (databox)</option>
            </select>
          </div>
          <div class="form-group">
            <label for="sincDestino">Destino</label>
            <input type="text" id="sincDestino" readonly placeholder="Se completa automáticamente">
          </div>
        </div>
        <div class="form-group">
          <label for="sincTabla">Tabla</label>
          <select id="sincTabla" disabled>
            <option value="">— Elegí primero el origen —</option>
          </select>
          <div class="field-error" id="sincTablaError" style="display:none"></div>
        </div>
        <div>
          <label style="font-size:.8rem;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">
            Log de ejecución
          </label>
          <pre class="terminal-log" id="sincLog"><span class="term-info">Elegí origen y tabla, y hacé click en «Ejecutar sincronización» para empezar.</span></pre>
        </div>
        <div style="font-size:.78rem;color:var(--muted);line-height:1.5">
          Copia la tabla completa del origen al destino <strong>preservando los IDs de origen</strong>.
          Si la tabla no existe en destino, se crea con el DDL del origen.
          Si existe, se <code style="font-family:monospace">TRUNCATE</code>a antes de insertar.
          Esta herramienta <strong>solo funciona en el panel de desarrollo</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarSincronizador()">Cerrar</button>
        <button class="btn btn-primary" id="sincBtnEjecutar" onclick="sincEjecutar()" disabled>
          Ejecutar sincronización
        </button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Visor de sucesos ===== -->
  <div class="modal-backdrop" id="sucesosBackdrop"
       onclick="if(event.target===this)cerrarVisorSucesos()">
    <div class="modal" style="max-width:1100px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">📰</span>
          <span>Visor de sucesos</span>
          <span id="sucesosResumen" class="modal-subtitle"></span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarVisorSucesos()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
            <div class="search-wrap">
              <input class="search-input" type="search" id="sucesosSearch"
                     placeholder="🔍 Buscar origen, detalle…"
                     oninput="sucesosOnSearch(this.value)">
              <button class="search-clear" id="sucesosSearchClear" style="display:none"
                      onclick="sucesosLimpiarBusqueda()">×</button>
            </div>
            <div id="sucesosTipoChips" style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip active" data-val=""       onclick="setFiltroTipoSucesos(this, '')">Todos</button>
              <button type="button" class="filter-chip"        data-val="info"   onclick="setFiltroTipoSucesos(this, 'info')"><i class="fa-solid fa-circle-info" style="color:var(--info)"></i> Info</button>
              <button type="button" class="filter-chip"        data-val="alerta" onclick="setFiltroTipoSucesos(this, 'alerta')"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warn)"></i> Alerta</button>
              <button type="button" class="filter-chip"        data-val="error"  onclick="setFiltroTipoSucesos(this, 'error')"><i class="fa-solid fa-circle-exclamation" style="color:var(--danger)"></i> Error</button>
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
              Desde
              <input type="date" id="sucesosDesde" onchange="cargarSucesos()">
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
              Hasta
              <input type="date" id="sucesosHasta" onchange="cargarSucesos()">
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
              Límite
              <select id="sucesosLimite" onchange="cargarSucesos()">
                <option value="100">100</option>
                <option value="200" selected>200</option>
                <option value="500">500</option>
                <option value="1000">1.000</option>
                <option value="2000">2.000</option>
              </select>
            </label>
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarSucesos()">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th style="width:170px">Fecha</th>
                <th style="width:180px">Origen</th>
                <th style="width:120px">Tipo</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody id="sucesosTbody">
              <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>

        <div style="font-size:.78rem;color:var(--muted);line-height:1.5">
          Vista de solo lectura sobre la tabla <code style="font-family:monospace">sucesos</code>.
          Los registros se ordenan por <strong>id descendente</strong> (más recientes primero).
          Tocá una fila para ver el detalle completo.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="cerrarVisorSucesos()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Modal detalle de suceso -->
  <div class="modal-backdrop" id="sucesoDetalleBackdrop"
       onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal" style="max-width:780px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">📰</span>
          <span>Suceso</span>
          <span class="modal-subtitle">#<span id="sucesoDetalleId">—</span></span>
        </div>
        <button class="btn-icon-sm" type="button"
                onclick="document.getElementById('sucesoDetalleBackdrop').classList.remove('open')"
                title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label>Fecha</label>
            <div id="sucesoDetalleFecha" style="font-family:monospace">—</div>
          </div>
          <div class="form-group">
            <label>Tipo</label>
            <div id="sucesoDetalleTipo" style="display:flex;align-items:center;gap:6px">—</div>
          </div>
        </div>
        <div class="form-group">
          <label>Origen</label>
          <div id="sucesoDetalleOrigen" style="font-family:monospace">—</div>
        </div>
        <div class="form-group">
          <label>Detalle</label>
          <textarea class="json-editor" id="sucesoDetalleTexto" readonly
                    spellcheck="false" autocomplete="off"
                    style="min-height:260px;font-family:monospace"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="document.getElementById('sucesoDetalleBackdrop').classList.remove('open')">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Menú contextual del Editor de parámetros -->
  <div id="parametrosCtxMenu" class="ctx-menu" role="menu">
    <button type="button" data-action="editar" role="menuitem">
      <i class="fa-solid fa-pen"></i><span>Editar</span>
    </button>
    <button type="button" data-action="copiar-variable" role="menuitem">
      <i class="fa-solid fa-copy"></i><span>Copiar variable</span>
    </button>
    <div class="ctx-menu-sep"></div>
    <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
      <i class="fa-solid fa-trash"></i><span>Eliminar</span>
    </button>
  </div>

  <!-- Menú contextual del Editor de estados -->
  <div id="estadosCtxMenu" class="ctx-menu" role="menu">
    <button type="button" data-action="editar" role="menuitem">
      <i class="fa-solid fa-pen"></i><span>Editar</span>
    </button>
    <button type="button" data-action="copiar-campo" role="menuitem">
      <i class="fa-solid fa-copy"></i><span>Copiar campo</span>
    </button>
    <button type="button" data-action="copiar-valor" role="menuitem">
      <i class="fa-solid fa-copy"></i><span>Copiar valor</span>
    </button>
    <div class="ctx-menu-sep"></div>
    <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
      <i class="fa-solid fa-trash"></i><span>Eliminar</span>
    </button>
  </div>

  <!-- ===== Modal Programador de tareas: Listado ===== -->
  <div class="modal-backdrop" id="tareasBackdrop"
       onclick="if(event.target===this)cerrarTareas()">
    <div class="modal" style="max-width:1080px;display:flex;flex-direction:column;max-height:90vh;overflow:hidden">
      <div class="modal-header" style="flex-shrink:0">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">⏰</span>
          <span>Programador de tareas</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarTareas()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px;flex:1;overflow:hidden;min-height:0;display:flex;flex-direction:column">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
            <div class="search-wrap">
              <input class="search-input" type="search" id="tareasSearch"
                     placeholder="🔍 Buscar nombre, script, cron…"
                     oninput="tareasOnSearch(this.value)">
              <button class="search-clear" id="tareasSearchClear" style="display:none"
                      onclick="tareasLimpiarBusqueda()">×</button>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button type="button" class="filter-chip tareas-chip-estado"        data-activo=""  onclick="tareasSetActivo('', this)">Todas</button>
              <button type="button" class="filter-chip tareas-chip-estado active"  data-activo="1" onclick="tareasSetActivo('1', this)">Activas</button>
              <button type="button" class="filter-chip tareas-chip-estado"        data-activo="0" onclick="tareasSetActivo('0', this)">Inactivas</button>
            </div>
          </div>
          <div class="toolbar-right" style="gap:6px">
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarTareas()">
              <i class="fa-solid fa-rotate"></i>
            </button>
            <button class="btn btn-primary btn-sm" onclick="abrirNuevaTarea()">
              <i class="fa-solid fa-plus"></i> Nueva tarea
            </button>
          </div>
        </div>

        <div class="table-card" style="flex:1;overflow-y:auto;min-height:0">
          <table>
            <thead style="position:sticky;top:0;background:var(--bg);z-index:1">
              <tr>
                <th style="width:70px">Código</th>
                <th>Nombre</th>
                <th style="width:150px">Cron</th>
                <th style="width:110px">Estado</th>
                <th style="width:170px">Última corrida</th>
                <th style="width:80px;text-align:center">Activa</th>
                <th style="width:70px;text-align:center">Acciones</th>
              </tr>
            </thead>
            <tbody id="tareasTbody">
              <tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="flex-shrink:0">
        <button class="btn btn-ghost" onclick="cerrarTareas()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Alta / Edición de tarea ===== -->
  <div class="modal-backdrop" id="formTareaBackdrop"
       onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal" style="max-width:640px">
      <div class="modal-header">
        <div class="modal-title" id="formTareaTitulo" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">⏰</span><span>Nueva tarea</span>
        </div>
        <button class="btn-icon-sm" type="button"
                onclick="document.getElementById('formTareaBackdrop').classList.remove('open')"
                title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <input type="hidden" id="formTareaId">
        <div class="form-group">
          <label for="formTareaNombre">Nombre</label>
          <input type="text" id="formTareaNombre" maxlength="120">
          <div class="field-error" id="formTareaNombreError" style="display:none"></div>
        </div>
        <div class="form-group">
          <label for="formTareaDescripcion">Descripción (opcional)</label>
          <input type="text" id="formTareaDescripcion" maxlength="255">
          <div class="field-error" id="formTareaDescripcionError" style="display:none"></div>
        </div>
        <div class="form-group">
          <label for="formTareaScript">Script</label>
          <div style="display:flex;gap:6px;align-items:stretch">
            <select id="formTareaScript" style="flex:1">
              <option value="">— Cargando… —</option>
            </select>
            <button class="btn btn-ghost btn-icon" type="button" title="Re-escanear scripts"
                    onclick="cargarScriptsDisponibles(document.getElementById('formTareaScript').value)">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
          <div class="field-error" id="formTareaScriptError" style="display:none"></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="formTareaCron">Expresión cron</label>
            <div style="display:flex;gap:6px;align-items:stretch">
              <input type="text" id="formTareaCron" style="font-family:monospace;flex:1"
                     maxlength="80" placeholder="*/5 * * * *">
              <button class="btn btn-ghost btn-icon" type="button" title="Abrir constructor"
                      onclick="abrirCronBuilder()">
                <i class="fa-solid fa-sliders"></i>
              </button>
            </div>
            <div class="field-error" id="formTareaCronError" style="display:none"></div>
          </div>
          <div class="form-group">
            <label for="formTareaTimeout">Timeout (segundos)</label>
            <input type="number" id="formTareaTimeout" min="5" max="86400" value="300">
            <div class="field-error" id="formTareaTimeoutError" style="display:none"></div>
          </div>
        </div>
        <div class="form-row form-row-3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
          <div class="form-group">
            <label for="formTareaOverlap">Si ya está corriendo</label>
            <select id="formTareaOverlap">
              <option value="skip">Saltar</option>
              <option value="allow">Ejecutar</option>
            </select>
          </div>
          <div class="form-group">
            <label for="formTareaRetencion">Retención (días)</label>
            <input type="number" id="formTareaRetencion" min="1" max="3650" value="7">
          </div>
          <div class="form-group">
            <label for="formTareaActivo">Estado</label>
            <select id="formTareaActivo">
              <option value="1">Activa</option>
              <option value="0">Inactiva</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" type="button"
                onclick="document.getElementById('formTareaBackdrop').classList.remove('open')">Cancelar</button>
        <button class="btn btn-primary" id="btnGuardarTarea" type="button" onclick="guardarTarea()">Guardar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Ejecuciones ===== -->
  <div class="modal-backdrop" id="ejecucionesBackdrop"
       onclick="if(event.target===this)cerrarEjecuciones()">
    <div class="modal" style="max-width:1000px;display:flex;flex-direction:column;max-height:90vh;overflow:hidden">
      <div class="modal-header" style="flex-shrink:0">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">📜</span>
          <span>Ejecuciones de </span>
          <span id="ejecucionesTareaNombre" style="font-family:monospace">—</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarEjecuciones()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px;flex:1;overflow:hidden;min-height:0;display:flex;flex-direction:column">
        <div class="toolbar" style="margin-bottom:0">
          <div class="toolbar-left" style="gap:6px;flex-wrap:wrap">
            <button type="button" class="filter-chip tareas-chip-est active" data-est=""          onclick="ejecucionesSetEstado('', this)">Todas</button>
            <button type="button" class="filter-chip tareas-chip-est"        data-est="corriendo" onclick="ejecucionesSetEstado('corriendo', this)">Corriendo</button>
            <button type="button" class="filter-chip tareas-chip-est"        data-est="ok"        onclick="ejecucionesSetEstado('ok', this)">OK</button>
            <button type="button" class="filter-chip tareas-chip-est"        data-est="error"     onclick="ejecucionesSetEstado('error', this)">Error</button>
            <button type="button" class="filter-chip tareas-chip-est"        data-est="timeout"   onclick="ejecucionesSetEstado('timeout', this)">Timeout</button>
            <button type="button" class="filter-chip tareas-chip-est"        data-est="killed"    onclick="ejecucionesSetEstado('killed', this)">Killed</button>
          </div>
          <div class="toolbar-right">
            <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargarEjecuciones()">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>
        </div>

        <div class="table-card" style="flex:1;overflow-y:auto;min-height:0">
          <table>
            <thead style="position:sticky;top:0;background:var(--bg);z-index:1">
              <tr>
                <th style="width:70px">Código</th>
                <th style="width:160px">Inicio</th>
                <th style="width:110px">Duración</th>
                <th style="width:100px">Estado</th>
                <th style="width:100px">Disparo</th>
                <th>Mensaje</th>
                <th style="width:70px;text-align:center">Acciones</th>
              </tr>
            </thead>
            <tbody id="ejecucionesTbody">
              <tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="flex-shrink:0">
        <button class="btn btn-ghost" onclick="cerrarEjecuciones()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Terminal (streaming SSE) ===== -->
  <div class="modal-backdrop" id="terminalBackdrop"
       onclick="if(event.target===this)cerrarTerminal()">
    <div class="modal" style="max-width:960px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-size:1.2rem">🖥️</span>
          <span>Log ejecución</span>
          <span id="terminalEjecucionNum" style="font-family:monospace;color:var(--muted)">#—</span>
          <span class="badge badge-info" id="terminalEstadoBadge">corriendo</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarTerminal()" title="Cerrar">×</button>
      </div>
      <div class="modal-body">
        <pre id="terminalOutput" class="terminal-live"></pre>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost btn-icon active" type="button" id="btnTerminalAutoscroll"
                title="Auto-scroll" onclick="terminalToggleAutoscroll()">
          <i class="fa-solid fa-angles-down"></i>
        </button>
        <div style="flex:1"></div>
        <button class="btn btn-danger" type="button" id="btnTerminalDetener"
                onclick="detenerEjecucionActual()" style="display:none">
          <i class="fa-solid fa-stop"></i> Detener
        </button>
        <button class="btn btn-ghost" onclick="cerrarTerminal()">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Constructor de cron ===== -->
  <div class="modal-backdrop" id="cronBuilderBackdrop" style="z-index:160"
       onclick="if(event.target===this)cerrarCronBuilder()">
    <div class="modal" style="max-width:640px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem">🧰</span><span>Constructor de expresión cron</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarCronBuilder()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:12px">
        <div id="cronBuilderCampos" style="display:flex;flex-direction:column;gap:8px"></div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px">
          <div style="font-family:monospace;font-weight:600;font-size:1rem" id="cronBuilderPreview">* * * * *</div>
          <div style="font-size:.82rem;color:var(--muted);margin-top:4px" id="cronBuilderDesc">Cada minuto, todos los días.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="cerrarCronBuilder()">Cancelar</button>
        <button class="btn btn-primary" type="button" onclick="cronBuilderAplicar()">Aplicar</button>
      </div>
    </div>
  </div>

  <!-- ===== Modal Picker de valores del cron ===== -->
  <div class="modal-backdrop" id="cronPickerBackdrop" style="z-index:180"
       onclick="if(event.target===this)cerrarCronPicker()">
    <div class="modal" style="max-width:640px">
      <div class="modal-header">
        <div class="modal-title" style="display:flex;align-items:center;gap:8px">
          <span id="cronPickerEmoji" style="font-size:1.2rem">⏱️</span>
          <span id="cronPickerTitulo">Elegir valores</span>
        </div>
        <button class="btn-icon-sm" type="button" onclick="cerrarCronPicker()" title="Cerrar">×</button>
      </div>
      <div class="modal-body" style="gap:10px">
        <div id="cronPickerHint" style="font-size:.82rem;color:var(--muted)">—</div>
        <div>
          <label id="cronPickerLabel1" style="font-size:.78rem;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">Valores</label>
          <div id="cronPickerGrupo1" style="display:flex;flex-wrap:wrap;gap:6px"></div>
        </div>
        <div id="cronPickerGrupo2Wrap" style="display:none">
          <label style="font-size:.78rem;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">Hasta</label>
          <div id="cronPickerGrupo2" style="display:flex;flex-wrap:wrap;gap:6px"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="cronPickerLimpiar()">Limpiar</button>
        <div style="flex:1"></div>
        <button class="btn btn-ghost" type="button" onclick="cerrarCronPicker()">Cancelar</button>
        <button class="btn btn-primary" type="button" onclick="cronPickerAplicar()">Aplicar</button>
      </div>
    </div>
  </div>

  <!-- Menú contextual del Programador de tareas -->
  <div id="tareasCtxMenu" class="ctx-menu" role="menu">
    <button type="button" data-action="ver-ejecuciones" role="menuitem">
      <i class="fa-solid fa-list"></i><span>Ver ejecuciones</span>
    </button>
    <button type="button" data-action="ejecutar-ahora" role="menuitem">
      <i class="fa-solid fa-play"></i><span>Ejecutar ahora</span>
    </button>
    <button type="button" data-action="toggle-activo" role="menuitem">
      <i class="fa-solid fa-power-off"></i><span data-label>Desactivar</span>
    </button>
    <div class="ctx-menu-sep"></div>
    <button type="button" data-action="editar" role="menuitem">
      <i class="fa-solid fa-pen"></i><span>Editar</span>
    </button>
    <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
      <i class="fa-solid fa-trash"></i><span>Eliminar</span>
    </button>
  </div>

  <!-- Menú contextual de Ejecuciones -->
  <div id="ejecucionesCtxMenu" class="ctx-menu" role="menu">
    <button type="button" data-action="ver-log" role="menuitem">
      <i class="fa-solid fa-terminal"></i><span>Ver log</span>
    </button>
    <button type="button" data-action="detener" class="ctx-menu-danger" role="menuitem">
      <i class="fa-solid fa-stop"></i><span>Detener</span>
    </button>
  </div>

  <script src="assets/js/app.js?v=<?= $jsVer ?>"></script>
</body>
</html>
