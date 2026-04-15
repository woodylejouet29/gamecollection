<?php
$theme    = $_COOKIE['theme'] ?? 'dark';
$title    = isset($title) ? htmlspecialchars($title) . ' — PlayShelf' : 'PlayShelf';
$cssFile  = $cssFile ?? null;
$head     = $head    ?? '';
$foot     = $foot    ?? '';
$appUrl   = rtrim($_ENV['APP_URL'] ?? '', '/');
$metaDesc = $metaDesc ?? 'PlayShelf — Cataloguez votre passion jeu vidéo.';
$ogTitle  = $ogTitle  ?? $title;
$ogDesc   = $ogDesc   ?? $metaDesc;
$ogImage  = $ogImage  ?? $appUrl . '/assets/img/og-default.jpg';
$ogUrl    = $ogUrl    ?? $appUrl . ($_SERVER['REQUEST_URI'] ?? '/');
$faviconFallback = ($theme === 'dark') ? '/images/logoblanc.ico' : '/images/logonoir.ico';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta property="og:type"        content="website">
    <meta property="og:title"       content="<?= htmlspecialchars($ogTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($ogUrl) ?>">
    <meta property="og:image"       content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:site_name"   content="PlayShelf">
    <meta name="twitter:card"       content="summary_large_image">
    
    <!-- PWA -->
    <meta name="application-name" content="PlayShelf">
    <meta name="theme-color" content="#7c3aed">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PlayShelf">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/x-icon" href="/images/logonoir.ico" sizes="any" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/x-icon" href="/images/logoblanc.ico" sizes="any" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconFallback) ?>" sizes="any">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192x192.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= \App\Core\View::asset('css/global.css') ?>">
    <link rel="stylesheet" href="<?= \App\Core\View::asset('css/platform-badges.css') ?>">
    <link rel="stylesheet" href="<?= \App\Core\View::asset('css/footer.css') ?>">
    <?php if ($cssFile): ?>
    <link rel="stylesheet" href="<?= \App\Core\View::asset('css/' . $cssFile . '.css') ?>">
    <?php endif; ?>
    <?= $head ?>
</head>
<body>
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="main">
        <?php require __DIR__ . '/../partials/flash.php'; ?>
        <?= $content ?>
    </main>
    <?php require __DIR__ . '/../partials/footer.php'; ?>
    
    <!-- Scripts de base -->
    <script src="<?= \App\Core\View::asset('js/theme.js') ?>" defer></script>
    <script src="<?= \App\Core\View::asset('js/app.js') ?>" defer></script>
    
    <!-- Turnstile : init explicite sur /login et /register (même clé de test en dev sans .env) -->
    <?php
    $uriAuth = $_SERVER['REQUEST_URI'] ?? '';
    $isAuthTurnstilePage = str_contains($uriAuth, '/login') || str_contains($uriAuth, '/register');
    ?>
    <?php if ($isAuthTurnstilePage): ?>
    <script src="<?= \App\Core\View::asset('js/turnstile.js') ?>" defer></script>
    <?php endif; ?>
    
    <?= $foot ?>
    
    <!-- Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/service-worker.js')
                .then(function(registration) {
                    console.log('Service Worker enregistré avec succès:', registration.scope);
                    
                    // Vérifier les mises à jour
                    registration.addEventListener('updatefound', () => {
                        const newWorker = registration.installing;
                        console.log('Nouveau Service Worker trouvé:', newWorker);
                        
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                console.log('Nouvelle version disponible!');
                                // On pourrait afficher un message à l'utilisateur ici
                            }
                        });
                    });
                })
                .catch(function(error) {
                    console.log('Échec de l\'enregistrement du Service Worker:', error);
                });
            
            // Écouter les messages du Service Worker
            navigator.serviceWorker.addEventListener('message', event => {
                if (event.data && event.data.type === 'NEW_VERSION_AVAILABLE') {
                    // Afficher un message à l'utilisateur pour recharger
                    console.log('Nouvelle version disponible, rechargement recommandé');
                }
            });
        });
    }
    </script>
</body>
</html>
