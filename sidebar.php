<?php
/**
 * sidebar.php — Menú lateral retráctil (Bootstrap 5)
 * Enlaces a: analytics.php, control_oficios.php, control_actividades.php, poa.php
 *
 * Uso recomendado en cada página:
 *   <?php $active = 'control_actividades.php'; resalta item 
 *          $sidebar_mode = 'overlay';          
 *          include __DIR__.'/sidebar.php'; ?>
 *
 * Modo "push": en escritorio empuja el contenido (margen izquierdo) y en móvil es flotante.
 * Modo "overlay": SIEMPRE flota y no roba ancho (ideal para pantallas full‑width como control_oficios.php).
 */

// Detecta página activa si no se define manualmente
$active       = $active       ?? basename($_SERVER['PHP_SELF']);
$sidebar_mode = $sidebar_mode ?? 'push'; // 'push' | 'overlay'
$btnDesktopClass = ($sidebar_mode === 'overlay') ? '' : 'd-lg-none';
?>

<!-- Botón hamburguesa (si overlay: visible también en escritorio) -->
<button id="sidebarToggle" class="btn btn-outline-secondary position-fixed m-3 d-print-none <?= $btnDesktopClass ?>" style="z-index:1052;">
  <span class="navbar-toggler-icon"></span> Menú
</button>

<!-- Backdrop para modo overlay -->
<div id="sbBackdrop" class="sigei-backdrop d-print-none"></div>

<!-- Sidebar -->
<aside id="sigeiSidebar" class="sigei-sidebar <?= $sidebar_mode === 'overlay' ? 'overlay' : 'push' ?> d-print-none" aria-label="Navegación SIGEI">
  <div class="sigei-sidebar__brand d-flex align-items-center px-3 py-3">
    <div aria-hidden="true">
        <img src="siti_logo_blanco.png" class="logo-siti-sidebar" alt="SECOED">
    </div>
    <div class="fw-semibold ms-2">SITI</div>
  </div>

  <nav class="sigei-nav px-2">
    <div class="text-uppercase text-white-50 small px-2 mb-2">Panel</div>
    <a class="sigei-link <?= $active==='analytics.php' ? 'active' : '' ?>" href="analytics.php">
      <span class="sigei-icon" aria-hidden="true">📊</span>
      <span>Analíticas</span>
    </a>

    <!-- Grupo desplegable: Módulos -->
    <button class="sigei-collapse-btn" type="button" data-bs-toggle="collapse" data-bs-target="#modulosMenu" aria-expanded="true" aria-controls="modulosMenu">
      <span class="sigei-icon" aria-hidden="true">🗂️</span>
      <span>Módulos</span>
      <span class="sigei-caret" aria-hidden="true">▾</span>
    </button>
    <div id="modulosMenu" class="collapse show">
      <a class="sigei-sublink <?= $active==='control_oficios.php' ? 'active' : '' ?>" href="control_oficios.php">
        <span class="sigei-icon" aria-hidden="true">📝</span>
        <span>Control de oficios</span>
      </a>
      <a class="sigei-sublink <?= $active==='control_actividades.php' ? 'active' : '' ?>" href="control_actividades.php">
        <span class="sigei-icon" aria-hidden="true">🛠️</span>
        <span>Control de actividades</span>
      </a>
      <a class="sigei-sublink <?= $active==='poa.php' ? 'active' : '' ?>" href="poa.php">
        <span class="sigei-icon" aria-hidden="true">📅</span>
        <span>POA</span>
      </a>
    </div>

    <!-- Accesos rápidos (opcional) -->
    <!--
    <div class="mt-3 text-uppercase text-white-50 small px-2">Accesos rápidos</div>
    <a class="sigei-link" href="#consulta-ticket">
      <span class="sigei-icon" aria-hidden="true">#</span>
      <span>Consulta ticket</span>
    </a>
    -->
  </nav>
</aside>

<!-- Estilos -->
<style>
  :root {
    --sbw: 260px;                /* ancho sidebar */
    --sb-bg: #24282d;            /* gris carbón */
    --sb-fg: #eceff2;            /* gris claro */
    --sb-fg-dim: #b4bcc6;        /* gris medio */
    --sb-accent: #d3d7dc;        /* gris suave */
  }

  /* Base */
  .sigei-sidebar {
    position: fixed; inset: 0 auto 0 0; width: var(--sbw); height: 100vh;
    background: var(--sb-bg); color: var(--sb-fg); z-index: 1051;
    border-right: 1px solid rgba(255,255,255,.06);
    overflow-y: auto; overscroll-behavior: contain;
    transform: translateX(-100%); /* oculto por defecto */
    transition: transform .25s ease;
  }
  .sigei-sidebar.show { transform: translateX(0); }

  /* Modo push: empuja en escritorio, flota en móvil */
  .sigei-sidebar.push { transform: translateX(-100%); }
  @media (min-width: 992px) {
    body:not(.overlay-mode) { margin-left: var(--sbw); }
    .sigei-sidebar.push { transform: translateX(0); }
  }

  /* Modo overlay: nunca empuja el cuerpo */
  body.overlay-mode { margin-left: 0 !important; }
  .sigei-sidebar.overlay { box-shadow: 0 10px 30px rgba(0,0,0,.35); }

  /* Backdrop para overlay */
  .sigei-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,.35); backdrop-filter: blur(1px);
    opacity: 0; pointer-events: none; transition: opacity .2s ease; z-index: 1050;
  }
  .sigei-backdrop.show { opacity: 1; pointer-events: auto; }

  .sigei-sidebar__brand { border-bottom: 1px solid rgba(255,255,255,.06); }
  .logo-siti-sidebar { height: 28px; width: auto; display: block; }

  .sigei-nav { display: block; }
  .sigei-link, .sigei-sublink, .sigei-collapse-btn {
    display: grid; grid-template-columns: 24px 1fr auto; align-items: center;
    gap: .5rem; width: 100%; padding: .6rem .75rem; border-radius: .5rem;
    color: var(--sb-fg); text-decoration: none; border: 0; background: transparent;
  }
  .sigei-link:hover, .sigei-sublink:hover, .sigei-collapse-btn:hover {
    background: rgba(255,255,255,.06);
  }
  .sigei-link.active, .sigei-sublink.active {
    background: rgba(56,189,248,.15); color: #fff; box-shadow: inset 0 0 0 1px rgba(56,189,248,.35);
  }
  .sigei-icon { display: inline-flex; width: 24px; justify-content: center; opacity: .9; }
  .sigei-sublink { padding-left: 2.25rem; color: var(--sb-fg-dim); }
  .sigei-caret { opacity: .7; }

  /* Impresión: ocultar navegación y márgenes */
  @media print {
    body { margin: 0 !important; }
  }
</style>

<!-- Script: modo, toggle, backdrop y autocierre -->
<script>
  (function(){
    const sidebar  = document.getElementById('sigeiSidebar');
    const btn      = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sbBackdrop');
    const isOverlay = sidebar?.classList.contains('overlay');

    // Marcar body si es overlay para evitar margen izquierdo
    if (isOverlay) document.body.classList.add('overlay-mode');

    const toggle = (force) => {
      const willShow = force !== undefined ? !!force : !sidebar.classList.contains('show');
      sidebar.classList.toggle('show', willShow);
      if (isOverlay) backdrop.classList.toggle('show', willShow);
    };

    btn?.addEventListener('click', () => toggle());
    backdrop?.addEventListener('click', () => toggle(false));

    // Cerrar al hacer clic en un enlace (overlay o móvil)
    sidebar?.addEventListener('click', (e) => {
      const t = e.target.closest('a');
      if (!t) return;
      if (isOverlay || window.matchMedia('(max-width: 991.98px)').matches) toggle(false);
    });

    // En modo push, mantener abierto por defecto en escritorio
    if (!isOverlay && window.matchMedia('(min-width: 992px)').matches) {
      sidebar.classList.add('show');
    }
  })();
</script>

<!-- Helper opcional: resaltar activo por URL si no se definió $active -->
<script>
  (function(){
    try {
      const path = (window.location.pathname||'').split('/').pop();
      const links = document.querySelectorAll('#sigeiSidebar a[href]');
      links.forEach(a => {
        const href = a.getAttribute('href');
        if (!href) return;
        if (href === path && !a.classList.contains('active')) {
          a.classList.add('active');
        }
      });
    } catch(_){}
  })();
</script>
