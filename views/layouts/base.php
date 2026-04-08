<?php
$theme    = $_COOKIE['theme'] ?? 'dark';
$title    = isset($title) ? htmlspecialchars($title) . ' — GameCollection' : 'GameCollection';
$cssFile  = $cssFile ?? null;
$head     = $head    ?? '';
$foot     = $foot    ?? '';
$appUrl   = rtrim($_ENV['APP_URL'] ?? '', '/');
$metaDesc = $metaDesc ?? 'GameCollection — Cataloguez votre passion jeu vidéo.';
$ogTitle  = $ogTitle  ?? $title;
$ogDesc   = $ogDesc   ?? $metaDesc;
$ogImage  = $ogImage  ?? $appUrl . '/assets/img/og-default.jpg';
$ogUrl    = $ogUrl    ?? $appUrl . ($_SERVER['REQUEST_URI'] ?? '/');
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
    <meta property="og:site_name"   content="GameCollection">
    <meta name="twitter:card"       content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= \App\Core\View::asset('css/global.css') ?>">
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
    <script src="<?= \App\Core\View::asset('js/theme.js') ?>" defer></script>
    <script src="<?= \App\Core\View::asset('js/app.js') ?>" defer></script>
    <?= $foot ?>
</body>
</html>
