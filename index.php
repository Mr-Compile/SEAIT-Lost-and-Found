<?php
require_once 'config/db.php';
require_once 'includes/helpers/settings_helper.php';
require_once 'includes/helpers/mfa_helper.php';
require_once 'includes/helpers/auth/remember_me_helper.php';
require_once 'config/env.php';

startSecureSession();
loadEnvFile(__DIR__ . '/.env');
ensureRememberMeTokensTable($pdo);

if (empty($_SESSION['user_id'])) {
  attemptRememberMeLogin($pdo);
}

// Get system settings for dynamic content
$settings = getSystemSettings();

$homepageStats = [
  'items_reported' => 0,
  'successful_matches' => 0,
  'active_users' => 0,
];

try {
  $statsStmt = $pdo->query("SELECT
    (SELECT COUNT(*) FROM items) AS items_reported,
    (SELECT COUNT(*) FROM claim_requests WHERE status IN ('approved', 'ready_for_pickup', 'claimed')) AS successful_matches,
    (SELECT COUNT(*) FROM users) AS active_users
  ");
  $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($statsRow)) {
    $homepageStats['items_reported'] = (int)($statsRow['items_reported'] ?? 0);
    $homepageStats['successful_matches'] = (int)($statsRow['successful_matches'] ?? 0);
    $homepageStats['active_users'] = (int)($statsRow['active_users'] ?? 0);
  }
} catch (PDOException $e) {
  error_log('Index Stats Error: ' . $e->getMessage());
}

// If user is already logged in, redirect to their dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/admin_dashboard.php');
        exit();
    } else {
        header('Location: claimant/claimant_dashboard.php');
        exit();
    }
}
?>
<?php
$pageTitle = $settings['site_tagline'] ?? "Find What's Lost. Return What's Found.";
$additionalStyles = '
<style>
    @import url("https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap");

    :root {
      --auth-black: #0f0f10;
      --auth-ink: #18181b;
      --auth-soft-ink: #26262b;
      --auth-orange: #ff7a1a;
      --auth-orange-deep: #dd6000;
      --auth-orange-soft: #ffb37a;
      --auth-white: #ffffff;
      --auth-gray-500: #6b7280;
      --card-grad-a: #2a2a2e;
      --card-grad-b: #141417;
      --text-strong: #f9fafb;
      --text-soft: #b6bfcb;
    }

    body {
      font-family: "Manrope", "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 10% 8%, rgba(255, 122, 26, 0.18), transparent 32%),
        radial-gradient(circle at 88% 82%, rgba(255, 122, 26, 0.16), transparent 36%),
        linear-gradient(140deg, #0b0b0d 0%, #141417 50%, #1a1a1f 100%);
      color: #ffffff;
    }

    .landing-hero {
      position: relative;
      min-height: 100vh;
      padding-top: 76px;
      padding-bottom: 2rem;
      display: flex;
      align-items: center;
    }

    .hero-bg {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center;
      transform: scale(1.02);
      filter: saturate(0.9) contrast(1.05);
    }

    .hero-overlay {
      position: absolute;
      inset: 0;
      background:
        linear-gradient(120deg, rgba(6, 6, 7, 0.88), rgba(255, 122, 26, 0.35)),
        radial-gradient(circle at 80% 15%, rgba(255, 122, 26, 0.26), transparent 34%);
    }

    .hero-inner {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: 1150px;
      margin: 0 auto;
      padding: 2rem 1.2rem 2.8rem;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 1.5rem;
      align-items: center;
    }

    .hero-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.74rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      padding: 0.42rem 0.7rem;
      border-radius: 999px;
      background: rgba(255, 122, 26, 0.2);
      color: #ffd7b8;
      border: 1px solid rgba(255, 179, 122, 0.5);
      margin-bottom: 1rem;
      font-weight: 700;
    }

    .hero-title {
      font-family: "Sora", "Segoe UI", sans-serif;
      font-size: clamp(2.1rem, 5vw, 4rem);
      font-weight: 800;
      line-height: 1.08;
      letter-spacing: -0.02em;
      margin-bottom: 0.9rem;
      max-width: 820px;
    }

    .hero-highlight {
      color: #ffd8b8;
    }

    .hero-copy {
      max-width: 720px;
      color: rgba(255, 255, 255, 0.84);
      font-size: 1.06rem;
      line-height: 1.7;
      margin-bottom: 1.7rem;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.7rem;
      margin-bottom: 1.5rem;
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      background: linear-gradient(135deg, var(--auth-orange), #ff9f4a);
      color: #ffffff;
      padding: 0.88rem 1.4rem;
      border-radius: 0.8rem;
      font-weight: 800;
      box-shadow: 0 12px 24px rgba(255, 122, 26, 0.4);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 28px rgba(255, 122, 26, 0.42);
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      border: 1px solid rgba(255, 255, 255, 0.35);
      background: rgba(14, 14, 16, 0.48);
      color: #ffffff;
      padding: 0.88rem 1.4rem;
      border-radius: 0.8rem;
      font-weight: 700;
      transition: all 0.2s ease;
    }

    .btn-secondary:hover {
      background: rgba(255, 179, 122, 0.16);
      border-color: rgba(255, 179, 122, 0.56);
      color: #ffe8d5;
      transform: translateY(-1px);
    }

    .metric-grid {
      margin-top: 1.1rem;
      margin-bottom: 1.2rem;
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 0.7rem;
      max-width: 760px;
    }

    .metric-card {
      background: rgba(12, 12, 14, 0.6);
      border: 1px solid rgba(255, 179, 122, 0.26);
      border-radius: 0.8rem;
      padding: 0.9rem 1rem;
      backdrop-filter: blur(4px);
    }

    .metric-value {
      font-family: "Sora", "Segoe UI", sans-serif;
      font-size: 1.45rem;
      font-weight: 700;
      color: #ffffff;
    }

    .metric-label {
      margin-top: 0.2rem;
      font-size: 0.78rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: #ffcfaa;
      font-weight: 600;
    }

    .floating-icon {
      position: absolute;
      color: rgba(255, 179, 122, 0.34);
      animation: drift 7s ease-in-out infinite;
    }

    .floating-icon.one { top: 22%; left: 8%; font-size: 2.3rem; }
    .floating-icon.two { top: 62%; left: 84%; font-size: 2rem; animation-delay: 1s; }
    .floating-icon.three { top: 70%; left: 14%; font-size: 2.2rem; animation-delay: 2s; }
    .floating-icon.four { top: 30%; left: 76%; font-size: 1.8rem; animation-delay: 1.5s; }

    @keyframes drift {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-14px); }
    }

    .feature-zone {
      position: relative;
      margin-top: 0;
      z-index: 1;
      padding: 0.85rem 1rem 2.6rem;
    }

    .feature-grid {
      max-width: 1150px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 0.95rem;
    }

    .feature-card {
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.03) 20%, var(--card-grad-a) 42%, var(--card-grad-b) 100%);
      color: var(--text-strong);
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.16);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.28);
      padding: 1.25rem;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      position: relative;
      overflow: hidden;
      backdrop-filter: blur(6px);
    }

    .feature-card::before {
      content: "";
      position: absolute;
      top: -42%;
      right: -12%;
      width: 180px;
      height: 180px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.17), transparent 68%);
      pointer-events: none;
    }

    .feature-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 24px 48px rgba(0, 0, 0, 0.34);
    }

    .feature-icon {
      width: 42px;
      height: 42px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, rgba(255, 122, 26, 0.34), rgba(255, 179, 122, 0.2));
      color: #ffd5b3;
      margin-bottom: 0.7rem;
      border: 1px solid rgba(255, 179, 122, 0.46);
    }

    .feature-title {
      font-family: "Sora", "Segoe UI", sans-serif;
      font-size: 1rem;
      font-weight: 700;
      color: var(--text-strong);
      margin-bottom: 0.35rem;
    }

    .feature-copy {
      color: var(--text-soft);
      line-height: 1.55;
      font-size: 0.9rem;
    }

    .hero-line {
      width: 210px;
      height: 58px;
      margin-top: 0.8rem;
    }

    @media (min-width: 740px) {
      .metric-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .feature-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 860px) {
      .hero-inner {
        padding-top: 1.6rem;
      }

      .feature-zone {
        margin-top: 0;
        padding-top: 0.7rem;
      }
    }
</style>';
include 'includes/layout/header.php';
?>

<?php
$navHomeHref = '#home';
$navShowHome = true;
$navShowLogin = true;
$navShowRegister = true;
include __DIR__ . '/includes/components/navigation.php';
?>


<!-- Hero -->
<section id="home" class="landing-hero">
  <?php if (!empty($settings['hero_image']) && file_exists($settings['hero_image'])): ?>
    <div class="hero-bg" style="background-image: url('<?php echo htmlspecialchars($settings['hero_image']); ?>');"></div>
  <?php else: ?>
    <div class="hero-bg" style="background-image: url('https://source.unsplash.com/1600x900/?lost,found');"></div>
  <?php endif; ?>

  <div class="hero-overlay"></div>

  <div class="floating-icon one"><i class="fas fa-key"></i></div>
  <div class="floating-icon two"><i class="fas fa-wallet"></i></div>
  <div class="floating-icon three"><i class="fas fa-mobile-alt"></i></div>
  <div class="floating-icon four"><i class="fas fa-headphones"></i></div>

  <div class="hero-inner">
    <div class="hero-grid">
      <div>
        <span class="hero-kicker"><i class="fas fa-bolt"></i> Smart Community Recovery</span>

        <h1 class="hero-title">
          <?php
          $tagline = $settings['site_tagline'] ?? "Find What Is Lost. Return What Is Found.";
          $taglineParts = array_filter(array_map('trim', explode('.', $tagline)));

          if (count($taglineParts) >= 2) {
              $firstLine = array_shift($taglineParts);
              $secondLine = implode('. ', $taglineParts);

              echo htmlspecialchars($firstLine) . '.<br>';
              echo '<span class="hero-highlight">' . htmlspecialchars($secondLine) . '.</span>';
          } else {
              echo htmlspecialchars($tagline);
          }
          ?>
        </h1>

        <p class="hero-copy">
          <?php echo htmlspecialchars($settings['site_description'] ?? 'Easily report lost or found items, get instant matches, and connect with others in your community.'); ?>
        </p>

        <div class="hero-actions">
          <a href="register.php" class="btn-primary">
            <i class="fas fa-user-plus"></i> Get Started
          </a>
          <a href="login.php" class="btn-secondary">
            <i class="fas fa-sign-in-alt"></i> Login
          </a>
        </div>

        <div class="hero-line" aria-hidden="true">
          <svg width="100%" height="100%" viewBox="0 0 210 58" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M2 44C34 44 34 14 66 14C98 14 98 44 130 44C162 44 162 14 194 14" stroke="#FFB37A" stroke-width="3" stroke-linecap="round"/>
            <circle cx="34" cy="44" r="5" fill="#FF7A1A"/>
            <circle cx="98" cy="14" r="5" fill="#FFFFFF"/>
            <circle cx="162" cy="44" r="5" fill="#FF7A1A"/>
          </svg>
        </div>

        <div class="metric-grid">
          <div class="metric-card">
            <div class="metric-value"><?php echo number_format($homepageStats['items_reported']); ?></div>
            <div class="metric-label">Items Reported</div>
          </div>
          <div class="metric-card">
            <div class="metric-value"><?php echo number_format($homepageStats['successful_matches']); ?></div>
            <div class="metric-label">Successful Matches</div>
          </div>
          <div class="metric-card">
            <div class="metric-value"><?php echo number_format($homepageStats['active_users']); ?></div>
            <div class="metric-label">Active Users</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- White Feature Cards -->
<section class="feature-zone">
  <div class="feature-grid">
    <article class="feature-card">
      <div class="feature-icon"><i class="fas fa-flag"></i></div>
      <h3 class="feature-title">Post In Seconds</h3>
      <p class="feature-copy">Submit lost or found reports with details, photos, and location context in a clean guided flow.</p>
    </article>
    <article class="feature-card">
      <div class="feature-icon"><i class="fas fa-comments"></i></div>
      <h3 class="feature-title">Connect Safely</h3>
      <p class="feature-copy">Use in-platform messaging and verification steps to protect both claimants and finders.</p>
    </article>
    <article class="feature-card">
      <div class="feature-icon"><i class="fas fa-bell"></i></div>
      <h3 class="feature-title">Get Smart Alerts</h3>
      <p class="feature-copy">Receive fast notifications when matching items appear so returns happen while details are fresh.</p>
    </article>
  </div>
</section>

<?php include 'includes/layout/footer.php'; ?>

