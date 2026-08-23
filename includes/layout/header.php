<?php
// Common header and navigation
$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/../anti_inspect.php';
require_once __DIR__ . '/../helpers/assets_helper.php';

$loadingLogoPath = '';
if (!empty($settings['site_logo'])) {
    $logoCandidate = (string)$settings['site_logo'];
    if (file_exists($logoCandidate)) {
        $loadingLogoPath = $logoCandidate;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FindIt - <?php echo $pageTitle ?? 'Lost & Found Community'; ?></title>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('vendor/tailwind/tailwind.cdn.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(finditAssetUrl('css/unified-loader.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            box-sizing: border-box;
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .gradient-button {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        
        .gradient-button:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px rgba(16, 185, 129, 0.3);
        }
        
        .input-icon-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        
        .input-with-icon {
            padding-left: 40px !important;
        }
    </style>
    <?php if (isset($additionalStyles)) echo $additionalStyles; ?>
    <?php renderAntiInspectScript(); ?>
</head>
<body class="min-h-screen bg-gray-50">
    <script>
        window.__FINDIT_LOADING__ = Object.assign({}, window.__FINDIT_LOADING__ || {}, {
            logoSrc: <?php echo json_encode($loadingLogoPath !== '' ? $loadingLogoPath : null, JSON_UNESCAPED_SLASHES); ?>,
            autoNavigationDelayMs: 120
        });
    </script>
    <script src="<?php echo htmlspecialchars(finditAssetUrl('js/unified-loader.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>

