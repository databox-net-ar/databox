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

  <script src="assets/js/app.js?v=<?= $jsVer ?>"></script>
</body>
</html>
