<?php
$navPathPrefix = isset($navPathPrefix) ? (string)$navPathPrefix : '';
$navShowHome = isset($navShowHome) ? (bool)$navShowHome : true;
$navShowLogin = isset($navShowLogin) ? (bool)$navShowLogin : true;
$navShowRegister = isset($navShowRegister) ? (bool)$navShowRegister : true;
$navHomeHref = isset($navHomeHref) ? (string)$navHomeHref : 'index.php';
$navLoginHref = isset($navLoginHref) ? (string)$navLoginHref : 'login.php';
$navRegisterHref = isset($navRegisterHref) ? (string)$navRegisterHref : 'register.php';

$navCurrentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$navSiteName = htmlspecialchars(explode(' -', (string)($settings['site_name'] ?? 'FindIt'))[0], ENT_QUOTES, 'UTF-8');

$navLogoSrc = '';
$logoCandidate = (string)($settings['site_logo'] ?? '');
if ($logoCandidate !== '') {
  if (file_exists($logoCandidate)) {
    $navLogoSrc = $logoCandidate;
  } elseif ($navPathPrefix !== '' && file_exists($navPathPrefix . $logoCandidate)) {
    $navLogoSrc = $navPathPrefix . $logoCandidate;
  }
}

$homeHref = preg_match('/^(#|https?:\/\/|\/)/i', $navHomeHref) ? $navHomeHref : $navPathPrefix . $navHomeHref;
$loginHref = preg_match('/^(#|https?:\/\/|\/)/i', $navLoginHref) ? $navLoginHref : $navPathPrefix . $navLoginHref;
$registerHref = preg_match('/^(#|https?:\/\/|\/)/i', $navRegisterHref) ? $navRegisterHref : $navPathPrefix . $navRegisterHref;

$isHomeActive = $navCurrentPage === 'index.php';
$isLoginActive = $navCurrentPage === 'login.php';
$isRegisterActive = $navCurrentPage === 'register.php';
?>

<style>
.un-nav-wrapper {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 60;
  background: rgba(10, 10, 12, 0.84);
  backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding: 0.9rem 1rem;
}

.un-nav-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: 1200px;
  margin: 0 auto;
}

.un-brand-link {
  display: inline-flex;
  align-items: center;
  transition: opacity 0.2s ease;
}

.un-brand-link:hover {
  opacity: 0.86;
}

.un-brand-logo {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 999px;
  object-fit: cover;
  background: #ffffff;
  border: 2px solid rgba(255, 255, 255, 0.98);
  padding: 2px;
  box-shadow: 0 0 0 2px rgba(15, 15, 16, 0.6);
  margin-right: 0.55rem;
  flex-shrink: 0;
}

.un-brand-logo-fallback {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-right: 0.55rem;
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: #111827;
  border: 2px solid rgba(255, 255, 255, 0.98);
  box-shadow: 0 0 0 2px rgba(15, 15, 16, 0.6);
  background: #ffffff;
  flex-shrink: 0;
}

.un-brand-logo-fallback svg {
  width: 1.15rem;
  height: 1.15rem;
}

.un-brand-text {
  color: #ffffff;
  font-family: "Sora", "Segoe UI", sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
  letter-spacing: 0.01em;
}

.un-nav-links {
  display: none;
  align-items: center;
  gap: 0.75rem;
}

.un-nav-link {
  color: rgba(255, 255, 255, 0.88);
  font-size: 0.94rem;
  font-weight: 600;
  border-radius: 0.62rem;
  padding: 0.42rem 0.78rem;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  transition: all 0.2s ease;
}

.un-nav-link:hover,
.un-nav-link.active {
  color: #ffd9be;
  background: rgba(255, 179, 122, 0.14);
}

.un-nav-cta {
  display: inline-flex;
  align-items: center;
  background: linear-gradient(135deg, #ff7a1a, #ff9a4d);
  color: #ffffff;
  border-radius: 0.7rem;
  padding: 0.56rem 1rem;
  font-size: 0.88rem;
  font-weight: 800;
  box-shadow: 0 10px 24px rgba(255, 122, 26, 0.34);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.un-nav-cta:hover {
  transform: translateY(-1px);
  box-shadow: 0 14px 26px rgba(255, 122, 26, 0.4);
}

.un-nav-toggle {
  color: #fde7d3;
  width: 2.15rem;
  height: 2.15rem;
  border-radius: 0.6rem;
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: rgba(255, 255, 255, 0.06);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.un-nav-mobile {
  background: rgba(17, 17, 20, 0.96);
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  margin-top: 0.75rem;
  border-radius: 0.8rem;
  padding: 0.4rem 0.9rem;
}

.un-nav-mobile-link {
  display: block;
  padding: 0.58rem 0;
  color: #f3f4f6;
  font-weight: 600;
}

.un-nav-mobile-link.inline {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.un-nav-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  opacity: 0.95;
}

.un-nav-mobile-link:hover {
  color: #ffcfaa;
}

@media (min-width: 768px) {
  .un-nav-links {
    display: inline-flex;
  }

  .un-nav-toggle {
    display: none;
  }

  .un-nav-mobile {
    display: none !important;
  }
}
</style>

<nav class="un-nav-wrapper">
  <div class="un-nav-content">
    <a href="<?php echo htmlspecialchars($navPathPrefix . 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="un-brand-link">
      <?php if ($navLogoSrc !== ''): ?>
        <img src="<?php echo htmlspecialchars($navLogoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Site Logo" class="un-brand-logo">
      <?php else: ?>
        <span class="un-brand-logo-fallback" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M11 4a7 7 0 015.4 11.5l3.3 3.3a1 1 0 01-1.4 1.4l-3.3-3.3A7 7 0 1111 4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M8.3 11h5.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
        </span>
      <?php endif; ?>
      <span class="un-brand-text"><?php echo $navSiteName; ?></span>
    </a>

    <div class="un-nav-links">
      <?php if ($navShowHome): ?>
        <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-link <?php echo $isHomeActive ? 'active' : ''; ?>">
          <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M3 10.6L12 3l9 7.6V20a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1v-9.4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Home
        </a>
      <?php endif; ?>
      <?php if ($navShowLogin): ?>
        <a href="<?php echo htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-link <?php echo $isLoginActive ? 'active' : ''; ?>">
          <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M14 7l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M19 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            <path d="M5 5h7v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M5 19h7v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Login
        </a>
      <?php endif; ?>
      <?php if ($navShowRegister): ?>
        <a href="<?php echo htmlspecialchars($registerHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-cta <?php echo $isRegisterActive ? 'active' : ''; ?>">
          <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Register
        </a>
      <?php endif; ?>
    </div>

    <button type="button" id="un-nav-mobile-btn" class="un-nav-toggle md:hidden" aria-label="Toggle menu">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <div id="un-nav-mobile-menu" class="un-nav-mobile hidden md:hidden">
    <?php if ($navShowHome): ?>
      <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-mobile-link inline">
        <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M3 10.6L12 3l9 7.6V20a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1v-9.4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Home
      </a>
    <?php endif; ?>
    <?php if ($navShowLogin): ?>
      <a href="<?php echo htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-mobile-link inline">
        <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M14 7l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M19 12H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          <path d="M5 5h7v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M5 19h7v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Login
      </a>
    <?php endif; ?>
    <?php if ($navShowRegister): ?>
      <a href="<?php echo htmlspecialchars($registerHref, ENT_QUOTES, 'UTF-8'); ?>" class="un-nav-mobile-link inline">
        <svg class="un-nav-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Register
      </a>
    <?php endif; ?>
  </div>
</nav>

<script>
(function setupUnifiedNavMenu() {
  const menuBtn = document.getElementById('un-nav-mobile-btn');
  const mobileMenu = document.getElementById('un-nav-mobile-menu');

  if (!menuBtn || !mobileMenu) {
    return;
  }

  menuBtn.addEventListener('click', function () {
    mobileMenu.classList.toggle('hidden');
  });

  mobileMenu.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      mobileMenu.classList.add('hidden');
    });
  });
})();
</script>