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
  <title>Databox · cloud</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVer ?>">
</head>
<body>
  <div class="layout">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <svg class="sidebar-logo-mark" viewBox="0 0 160 36" xmlns="http://www.w3.org/2000/svg" aria-label="Databox">
          <g fill="#fff">
            <rect x="2"  y="6"  width="22" height="22" rx="4" fill="rgba(255,255,255,.18)"/>
            <rect x="6"  y="10" width="14" height="14" rx="2"/>
            <text x="32" y="24" font-family="-apple-system,Segoe UI,sans-serif"
                  font-size="18" font-weight="700" letter-spacing="1">DATABOX</text>
          </g>
        </svg>
      </div>

      <nav class="sidebar-nav">
        <a href="#/dashboard" class="nav-item" data-route="/dashboard">
          <span class="nav-icon">📊</span> Dashboard
        </a>
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
            <span>admin</span> <i class="fa-solid fa-chevron-down" style="font-size:.7rem"></i>
          </button>
          <div class="user-dropdown" id="userDropdown">
            <!-- placeholder para futuros items (perfil, cerrar sesion, etc.) -->
            <button class="action-menu-item" disabled><i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
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
