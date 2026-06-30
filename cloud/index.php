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

  <script src="assets/js/app.js?v=<?= $jsVer ?>"></script>
</body>
</html>
