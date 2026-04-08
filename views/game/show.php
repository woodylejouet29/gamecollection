<?php
/**
 * Vue : /game/{slug}
 *
 * Variables injectées par GameController::show() :
 *   $game               array   — colonnes games + raw_igdb (JSONB décodé)
 *   $unique_platforms   array   — plateformes sans doublon
 *   $releases_by_region array   — [region => [{platform, date}, ...]]
 *   $versions           array   — game_versions + jeux liés IGDB (clé optionnelle slug → lien fiche)
 *   $reviews            array   — avis utilisateurs
 *   $avg_review         ?float  — note moyenne des membres
 *   $wishlist_count     int     — nombre de personnes qui attendent ce jeu
 *   $is_wishlisted      bool    — true si l'utilisateur connecté l'a en wishlist
 *   $authUser           ?array
 */

use App\Data\PlatformAbbreviations;

$game             = $game              ?? [];
$uniquePlatforms  = $unique_platforms  ?? [];
$releasesByRegion = $releases_by_region ?? [];
$versions         = $versions          ?? [];
$reviews          = $reviews           ?? [];
$avgReview        = $avg_review        ?? null;
$wishlistCount    = $wishlist_count    ?? 0;
$isWishlisted     = $is_wishlisted     ?? false;
$collectionCount  = (int) ($collection_count ?? 0);
$authUser         = $authUser          ?? null;

$rawIgdb     = $game['raw_igdb']    ?? [];
$genres      = $game['genres']      ?? [];
$screenshots = $game['screenshots'] ?? [];
$videos      = $game['videos']      ?? [];

$title       = $game['title']       ?? '';
$synopsis    = $game['synopsis']    ?? '';
$storyline   = $game['storyline']   ?? '';
$developer   = $game['developer']   ?? '';
$publisher   = $game['publisher']   ?? '';
$releaseDate = $game['release_date'] ?? '';
$releaseYear = $releaseDate ? substr($releaseDate, 0, 4) : '';
$igdbRating  = isset($game['igdb_rating'])      ? (float) $game['igdb_rating']      : null;
$aggrRating  = isset($game['aggregated_rating']) ? (float) $game['aggregated_rating'] : null;
$coverUrl    = $game['cover_url'] ?? '';

// ── Background : artwork > screenshot > cover ────────────────────────────────
$bgImageUrl = '';
$artworks   = $rawIgdb['artworks'] ?? [];
if (!empty($artworks[0]['url'])) {
    $u = $artworks[0]['url'];
    $bgImageUrl = (str_starts_with($u, '//') ? 'https:' : '') . $u;
    $bgImageUrl = preg_replace('/t_[a-z_]+/', 't_1080p', $bgImageUrl);
} elseif (!empty($screenshots)) {
    $firstShot = is_array($screenshots[0]) ? ($screenshots[0]['url'] ?? '') : $screenshots[0];
    if ($firstShot) {
        $bgImageUrl = (str_starts_with($firstShot, '//') ? 'https:' : '') . $firstShot;
        $bgImageUrl = preg_replace('/t_[a-z_]+/', 't_screenshot_huge', $bgImageUrl);
    }
} elseif ($coverUrl) {
    $bgImageUrl = (str_starts_with($coverUrl, '//') ? 'https:' : '') . ltrim($coverUrl, '/');
    $bgImageUrl = preg_replace('/t_[a-z_]+/', 't_1080p', $bgImageUrl) ?: $bgImageUrl;
}

// ── Infos extraites du JSONB raw_igdb ────────────────────────────────────────
$themes      = array_filter(array_map(fn($t) => $t['name'] ?? '', $rawIgdb['themes']               ?? []));
$gameModes   = array_filter(array_map(fn($m) => $m['name'] ?? '', $rawIgdb['game_modes']            ?? []));
$perspectives= array_filter(array_map(fn($p) => $p['name'] ?? '', $rawIgdb['player_perspectives']   ?? []));
$ageRatings  = $rawIgdb['age_ratings'] ?? [];

$websiteCats = [
    1  => ['label' => 'Site officiel', 'icon' => 'globe'],
    13 => ['label' => 'Steam',         'icon' => 'steam'],
    17 => ['label' => 'GOG',           'icon' => 'gog'],
    16 => ['label' => 'Epic Games',    'icon' => 'epic'],
    3  => ['label' => 'Wikipedia',     'icon' => 'wiki'],
    5  => ['label' => 'Twitter / X',   'icon' => 'twitter'],
    14 => ['label' => 'Reddit',        'icon' => 'reddit'],
    9  => ['label' => 'YouTube',       'icon' => 'youtube'],
];
$websites = [];
foreach ($rawIgdb['websites'] ?? [] as $w) {
    $cat = (int) ($w['category'] ?? 0);
    if (isset($websiteCats[$cat]) && !empty($w['url'])) {
        $websites[] = array_merge($websiteCats[$cat], ['url' => $w['url']]);
    }
}

$editions = array_values(array_filter($versions, fn($v) => !($v['is_dlc'] ?? false)));
$dlcs     = array_values(array_filter($versions, fn($v) =>  ($v['is_dlc'] ?? false)));

$regionLabels = [
    'PAL'    => 'Europe / PAL',
    'NTSC-U' => 'Amérique du Nord',
    'NTSC-J' => 'Japon',
    'NTSC-K' => 'Corée',
    'other'  => 'Mondial',
];

// ── Helpers locaux ───────────────────────────────────────────────────────────
function gameSrc(?string $url): string
{
    if (!$url) return '';
    if (str_starts_with($url, '//'))   return 'https:' . $url;
    if (str_starts_with($url, 'http')) return $url;
    return '/' . ltrim($url, '/');
}

function gameCoverBig(?string $url): string
{
    $u = gameSrc($url);
    if (str_contains($u, 'images.igdb.com')) {
        return (string) preg_replace('/t_[a-z_]+/', 't_cover_big_2x', $u);
    }
    return $u;
}

function gameThumb(?string $url, string $size = 't_screenshot_med'): string
{
    $u = gameSrc($url);
    if (str_contains($u, 'images.igdb.com')) {
        return (string) preg_replace('/t_[a-z_]+/', $size, $u);
    }
    return $u;
}

function fmtGameDate(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    if (!$ts) return $date;
    static $months = [
        1=>'jan.',2=>'fév.',3=>'mars',4=>'avr.',5=>'mai',6=>'juin',
        7=>'juil.',8=>'août',9=>'sept.',10=>'oct.',11=>'nov.',12=>'déc.',
    ];
    return date('j', $ts) . ' ' . ($months[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

function scoreColor(float $score): string
{
    if ($score >= 75) return '#22c55e';
    if ($score >= 50) return '#f59e0b';
    return '#ef4444';
}

function platformLabel(array $p): string
{
    $abbr = trim($p['abbreviation'] ?? '');
    if ($abbr !== '') return htmlspecialchars($abbr);
    $full = $p['name'] ?? '';
    return htmlspecialchars(PlatformAbbreviations::get($full) ?: $full ?: '?');
}
?>

<?php /* ════════════════════════════════════════════════════ HERO ═══════════ */ ?>
<section class="game-hero"
    <?= $bgImageUrl ? 'style="--hero-bg: url(\'' . htmlspecialchars($bgImageUrl, ENT_QUOTES) . '\')"' : '' ?>>
    <div class="game-hero__bg" aria-hidden="true"></div>
    <div class="game-hero__gradient" aria-hidden="true"></div>

    <div class="container">
        <div class="game-hero__inner">

            <?php /* Cover */ ?>
            <div class="game-hero__cover-wrap">
                <?php if ($coverUrl): ?>
                    <img class="game-hero__cover"
                         src="<?= htmlspecialchars(gameCoverBig($coverUrl)) ?>"
                         alt="Jaquette — <?= htmlspecialchars($title) ?>"
                         width="220" height="293">
                <?php else: ?>
                    <div class="game-hero__cover game-hero__cover--empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="3"/>
                            <path d="M12 8v8M8 12h8"/>
                        </svg>
                    </div>
                <?php endif; ?>

                <?php if ($igdbRating !== null): ?>
                    <div class="game-hero__score"
                         title="Note IGDB : <?= round($igdbRating) ?>/100"
                         style="--sc: <?= scoreColor($igdbRating) ?>">
                        <?= round($igdbRating) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* Infos + actions */ ?>
            <div class="game-hero__info">
                <?php if (!empty($uniquePlatforms)): ?>
                    <div class="game-hero__platforms">
                        <?php foreach ($uniquePlatforms as $p): ?>
                            <span class="platform-badge"><?= platformLabel($p) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h1 class="game-hero__title"><?= htmlspecialchars($title) ?></h1>

                <div class="game-hero__meta">
                    <?php if ($releaseYear): ?>
                        <span class="game-hero__meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                            <?= htmlspecialchars($releaseYear) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($developer): ?>
                        <span class="game-hero__meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8L6 7h12z"/>
                            </svg>
                            <?= htmlspecialchars($developer) ?>
                        </span>
                    <?php endif; ?>
                    <?php
                    $genreNames = array_filter(array_map(
                        fn($g) => is_array($g) ? ($g['name'] ?? '') : '',
                        array_slice($genres, 0, 3)
                    ));
                    if (!empty($genreNames)):
                    ?>
                        <span class="game-hero__meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M4 6h16M4 10h12M4 14h8"/>
                            </svg>
                            <?= htmlspecialchars(implode(', ', $genreNames)) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php /* Badges notes */ ?>
                <?php if ($igdbRating !== null || $aggrRating !== null || $avgReview !== null): ?>
                    <div class="game-hero__ratings">
                        <?php if ($igdbRating !== null): ?>
                            <div class="rating-badge" title="Note IGDB (utilisateurs)">
                                <span class="rating-badge__val" style="color:<?= scoreColor($igdbRating) ?>">
                                    <?= round($igdbRating) ?>
                                </span>
                                <span class="rating-badge__lbl">IGDB</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($aggrRating !== null): ?>
                            <div class="rating-badge" title="Note agrégée presse">
                                <span class="rating-badge__val" style="color:<?= scoreColor($aggrRating) ?>">
                                    <?= round($aggrRating) ?>
                                </span>
                                <span class="rating-badge__lbl">Presse</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($avgReview !== null): ?>
                            <div class="rating-badge" title="Note des membres GameCollection">
                                <span class="rating-badge__val" style="color:<?= scoreColor($avgReview * 10) ?>">
                                    <?= number_format($avgReview, 1) ?>
                                </span>
                                <span class="rating-badge__lbl">Membres</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php /* Actions */ ?>
                <div class="game-hero__actions">
                    <?php if ($authUser): ?>
                        <button class="btn btn--primary btn--collection"
                                id="btn-add-collection"
                                data-game-id="<?= (int) ($game['id'] ?? 0) ?>"
                                aria-label="<?= $collectionCount > 0 ? ('Déjà dans ma collection (' . $collectionCount . ') — ajouter une autre entrée') : 'Ajouter à ma collection' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>
                            </svg>
                            Ajouter à ma collection
                            <?php if ($collectionCount > 0): ?>
                                <span class="badge badge--danger" title="Déjà dans ma collection"><?= (int) $collectionCount ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="btn btn--ghost btn--wishlist <?= $isWishlisted ? 'is-active' : '' ?>"
                                id="btn-wishlist"
                                data-game-id="<?= (int) ($game['id'] ?? 0) ?>">
                            <svg class="wishlist-icon" viewBox="0 0 24 24"
                                 fill="<?= $isWishlisted ? 'currentColor' : 'none' ?>"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                            </svg>
                            <span class="wishlist-label"><?= $isWishlisted ? 'Dans ma wishlist' : "Je veux" ?></span>
                            <?php if ($wishlistCount > 0): ?>
                                <span class="wishlist-count" id="wishlist-count"><?= $wishlistCount ?></span>
                            <?php endif; ?>
                        </button>
                    <?php else: ?>
                        <a href="/login?redirect=<?= urlencode('/game/' . ($game['slug'] ?? '')) ?>"
                           class="btn btn--primary btn--collection">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>
                            </svg>
                            Ajouter à ma collection
                        </a>
                        <a href="/login?redirect=<?= urlencode('/game/' . ($game['slug'] ?? '')) ?>"
                           class="btn btn--ghost btn--wishlist">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                            </svg>
                            J'attends
                            <?php if ($wishlistCount > 0): ?>
                                <span class="wishlist-count"><?= $wishlistCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php /* ═══════════════════════════════════════════ CONTENU PRINCIPAL ════════ */ ?>
<div class="container">
    <div class="game-body">

        <?php /* ───────────── COLONNE PRINCIPALE ───────────── */ ?>
        <div class="game-main">

            <?php /* Onglets (mobile) — fallback sans JS : tout reste visible */ ?>
            <?php
            $validVideos = array_filter($videos, fn($v) => is_array($v) && !empty($v['video_id']));
            $hasEditionsTab = !empty($editions) || !empty($dlcs);
            ?>
            <section class="game-tabs" data-game-tabs aria-label="Navigation fiche jeu">
                <div class="game-tabs__bar" role="tablist" aria-label="Sections">
                    <button class="game-tab is-active" type="button"
                            role="tab"
                            id="game-tab-overview"
                            data-tab="overview"
                            aria-selected="true"
                            aria-controls="game-tabpanel-overview">
                        Vue d’ensemble
                    </button>
                    <button class="game-tab" type="button"
                            role="tab"
                            id="game-tab-info"
                            data-tab="info"
                            aria-selected="false"
                            aria-controls="game-tabpanel-info"
                            tabindex="-1">
                        Infos
                    </button>
                    <?php if ($hasEditionsTab): ?>
                        <button class="game-tab" type="button"
                                role="tab"
                                id="game-tab-editions"
                                data-tab="editions"
                                aria-selected="false"
                                aria-controls="game-tabpanel-editions"
                                tabindex="-1">
                            Éditions
                        </button>
                    <?php endif; ?>
                    <button class="game-tab" type="button"
                            role="tab"
                            id="game-tab-videos"
                            data-tab="videos"
                            aria-selected="false"
                            aria-controls="game-tabpanel-videos"
                            tabindex="-1">
                        Vidéos
                    </button>
                    <button class="game-tab" type="button"
                            role="tab"
                            id="game-tab-reviews"
                            data-tab="reviews"
                            aria-selected="false"
                            aria-controls="game-tabpanel-reviews"
                            tabindex="-1">
                        Avis
                    </button>
                </div>

                <div class="game-tabs__panels">
                    <div class="game-tabpanel is-active"
                         role="tabpanel"
                         id="game-tabpanel-overview"
                         data-panel="overview"
                         aria-labelledby="game-tab-overview">

                        <?php /* Synopsis */ ?>
                        <?php if ($synopsis || $storyline): ?>
                        <section class="game-section">
                            <h2 class="game-section__title">Synopsis</h2>
                            <?php if ($synopsis): ?>
                                <div class="game-synopsis"><?= nl2br(htmlspecialchars($synopsis)) ?></div>
                            <?php endif; ?>
                            <?php if ($storyline && $storyline !== $synopsis): ?>
                                <div class="game-synopsis game-synopsis--story"><?= nl2br(htmlspecialchars($storyline)) ?></div>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <?php /* Galerie de screenshots */ ?>
                        <?php if (!empty($screenshots)): ?>
                        <section class="game-section">
                            <h2 class="game-section__title">Galerie</h2>
                            <div class="game-gallery" id="game-gallery">
                                <?php foreach ($screenshots as $i => $shot):
                                    $shotUrl = is_array($shot) ? ($shot['url'] ?? '') : $shot;
                                    $thumb   = gameThumb($shotUrl, 't_screenshot_med');
                                    $full    = gameThumb($shotUrl, 't_screenshot_huge');
                                    if (!$thumb) continue;
                                ?>
                                    <button class="game-gallery__thumb" type="button"
                                            data-index="<?= $i ?>"
                                            data-full="<?= htmlspecialchars($full) ?>"
                                            aria-label="Voir screenshot <?= $i + 1 ?>">
                                        <img src="<?= htmlspecialchars($thumb) ?>"
                                             alt="Screenshot <?= $i + 1 ?>"
                                             loading="lazy">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>
                    </div>

                    <div class="game-tabpanel"
                         role="tabpanel"
                         id="game-tabpanel-info"
                         data-panel="info"
                         aria-labelledby="game-tab-info"
                         tabindex="-1">
                        <?php /* Informations (version mobile : la sidebar desktop reste en place au-delà de 768px) */ ?>
                        <div class="game-tabs__mobile-info">
                            <div class="game-info-box">
                                <h2 class="game-info-box__title">Informations</h2>
                                <dl class="game-info-list">

                                    <?php if ($releaseDate): ?>
                                        <dt>Sortie</dt>
                                        <dd><?= fmtGameDate($releaseDate) ?></dd>
                                    <?php endif; ?>

                                    <?php if ($developer): ?>
                                        <dt>Développeur</dt>
                                        <dd><?= htmlspecialchars($developer) ?></dd>
                                    <?php endif; ?>

                                    <?php if ($publisher && $publisher !== $developer): ?>
                                        <dt>Éditeur</dt>
                                        <dd><?= htmlspecialchars($publisher) ?></dd>
                                    <?php endif; ?>

                                    <?php if (!empty($genres)): ?>
                                        <dt>Genres</dt>
                                        <dd>
                                            <div class="info-tags">
                                                <?php foreach ($genres as $g):
                                                    $gn = is_array($g) ? ($g['name'] ?? '') : '';
                                                    if (!$gn) continue;
                                                ?>
                                                    <span class="info-tag"><?= htmlspecialchars($gn) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if (!empty($themes)): ?>
                                        <dt>Thèmes</dt>
                                        <dd>
                                            <div class="info-tags">
                                                <?php foreach ($themes as $t): ?>
                                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($t) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if (!empty($gameModes)): ?>
                                        <dt>Modes</dt>
                                        <dd>
                                            <div class="info-tags">
                                                <?php foreach ($gameModes as $m): ?>
                                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($m) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if (!empty($perspectives)): ?>
                                        <dt>Perspective</dt>
                                        <dd>
                                            <div class="info-tags">
                                                <?php foreach ($perspectives as $p): ?>
                                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($p) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if (!empty($game['igdb_rating_count'])): ?>
                                        <dt>Votes IGDB</dt>
                                        <dd><?= number_format((int) $game['igdb_rating_count']) ?></dd>
                                    <?php endif; ?>

                                </dl>
                            </div>

                            <?php /* Dates de sortie par région */ ?>
                            <?php if (!empty($releasesByRegion)): ?>
                            <div class="game-info-box">
                                <h2 class="game-info-box__title">Dates de sortie</h2>
                                <?php foreach ($releasesByRegion as $region => $entries): ?>
                                    <div class="release-block">
                                        <h3 class="release-block__region">
                                            <?= htmlspecialchars($regionLabels[$region] ?? ucfirst($region)) ?>
                                        </h3>
                                        <div class="release-entries">
                                            <?php foreach ($entries as $e):
                                                $pf     = $e['platform'];
                                                $pLabel = trim($pf['abbreviation'] ?? '');
                                                if (!$pLabel) $pLabel = PlatformAbbreviations::get($pf['name'] ?? '') ?: ($pf['name'] ?? '?');
                                            ?>
                                                <div class="release-entry">
                                                    <span class="release-entry__platform"><?= htmlspecialchars($pLabel) ?></span>
                                                    <span class="release-entry__date"><?= fmtGameDate($e['date']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php /* Liens web */ ?>
                            <?php if (!empty($websites)): ?>
                            <div class="game-info-box">
                                <h2 class="game-info-box__title">Liens</h2>
                                <div class="game-links">
                                    <?php foreach ($websites as $w): ?>
                                        <a class="game-link"
                                           href="<?= htmlspecialchars($w['url']) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer">
                                            <?php /* Icônes SVG inline selon type */ ?>
                                            <?php if ($w['icon'] === 'globe'): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                            <?php elseif ($w['icon'] === 'steam'): ?>
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.606 0 11.979 0z"/></svg>
                                            <?php elseif ($w['icon'] === 'wiki'): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                            <?php elseif ($w['icon'] === 'youtube'): ?>
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/></svg>
                                            <?php elseif ($w['icon'] === 'reddit'): ?>
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/></svg>
                                            <?php else: ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($w['label']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($hasEditionsTab): ?>
                        <div class="game-tabpanel"
                             role="tabpanel"
                             id="game-tabpanel-editions"
                             data-panel="editions"
                             aria-labelledby="game-tab-editions"
                             tabindex="-1">
                            <?php /* Éditions */ ?>
                            <?php if (!empty($editions)): ?>
                            <section class="game-section">
                                <h2 class="game-section__title">Éditions</h2>
                                <div class="game-versions">
                                    <?php foreach ($editions as $ed):
                                        $edSlug = trim((string) ($ed['slug'] ?? ''));
                                    ?>
                                        <?php if ($edSlug !== ''): ?>
                                        <a class="game-version-card game-version-card--link" href="/game/<?= htmlspecialchars($edSlug) ?>">
                                        <?php else: ?>
                                        <div class="game-version-card">
                                        <?php endif; ?>
                                            <?php if (!empty($ed['cover_url'])): ?>
                                                <img class="game-version-card__cover"
                                                     src="<?= htmlspecialchars(gameSrc($ed['cover_url'])) ?>"
                                                     alt="<?= htmlspecialchars($ed['name'] ?? '') ?>"
                                                     loading="lazy">
                                            <?php endif; ?>
                                            <div class="game-version-card__info">
                                                <strong class="game-version-card__name"><?= htmlspecialchars($ed['name'] ?? '') ?></strong>
                                                <?php if (!empty($ed['description'])): ?>
                                                    <p class="game-version-card__desc">
                                                        <?= htmlspecialchars(mb_substr($ed['description'], 0, 200)) ?>
                                                        <?= mb_strlen($ed['description']) > 200 ? '…' : '' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php if ($edSlug !== ''): ?></a><?php else: ?></div><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php /* DLC & Extensions */ ?>
                            <?php if (!empty($dlcs)): ?>
                            <section class="game-section">
                                <h2 class="game-section__title">DLC &amp; Extensions</h2>
                                <div class="game-versions">
                                    <?php foreach ($dlcs as $dlc):
                                        $dlcSlug = trim((string) ($dlc['slug'] ?? ''));
                                    ?>
                                        <?php if ($dlcSlug !== ''): ?>
                                        <a class="game-version-card game-version-card--dlc game-version-card--link" href="/game/<?= htmlspecialchars($dlcSlug) ?>">
                                        <?php else: ?>
                                        <div class="game-version-card game-version-card--dlc">
                                        <?php endif; ?>
                                            <?php if (!empty($dlc['cover_url'])): ?>
                                                <img class="game-version-card__cover"
                                                     src="<?= htmlspecialchars(gameSrc($dlc['cover_url'])) ?>"
                                                     alt="<?= htmlspecialchars($dlc['name'] ?? '') ?>"
                                                     loading="lazy">
                                            <?php endif; ?>
                                            <div class="game-version-card__info">
                                                <strong class="game-version-card__name"><?= htmlspecialchars($dlc['name'] ?? '') ?></strong>
                                                <span class="dlc-tag">DLC</span>
                                                <?php if (!empty($dlc['description'])): ?>
                                                    <p class="game-version-card__desc">
                                                        <?= htmlspecialchars(mb_substr($dlc['description'], 0, 200)) ?>
                                                        <?= mb_strlen($dlc['description']) > 200 ? '…' : '' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php if ($dlcSlug !== ''): ?></a><?php else: ?></div><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="game-tabpanel"
                         role="tabpanel"
                         id="game-tabpanel-videos"
                         data-panel="videos"
                         aria-labelledby="game-tab-videos"
                         tabindex="-1">
                        <?php /* Vidéos */ ?>
                        <section class="game-section">
                            <div class="game-section__header">
                                <h2 class="game-section__title">Vidéos</h2>
                            </div>

                            <?php if (!empty($validVideos)): ?>
                                <div class="game-videos">
                                    <?php foreach (array_slice($validVideos, 0, 6) as $v):
                                        $ytId   = htmlspecialchars($v['video_id']);
                                        $ytName = htmlspecialchars($v['name'] ?? 'Vidéo');
                                    ?>
                                        <div class="game-video-card">
                                            <button class="game-video-card__thumb" type="button"
                                                    data-yt-id="<?= $ytId ?>"
                                                    aria-label="Lire : <?= $ytName ?>">
                                                <img src="https://img.youtube.com/vi/<?= $ytId ?>/hqdefault.jpg"
                                                     alt="<?= $ytName ?>"
                                                     loading="lazy">
                                                <div class="game-video-card__play" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                        <circle cx="12" cy="12" r="12" fill="rgba(0,0,0,.6)"/>
                                                        <path d="M10 8l6 4-6 4V8z" fill="#fff"/>
                                                    </svg>
                                                </div>
                                            </button>
                                            <p class="game-video-card__name"><?= $ytName ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="game-empty">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                    </svg>
                                    <p>Aucune vidéo pour le moment.</p>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <div class="game-tabpanel"
                         role="tabpanel"
                         id="game-tabpanel-reviews"
                         data-panel="reviews"
                         aria-labelledby="game-tab-reviews"
                         tabindex="-1">
                        <?php /* Avis des membres */ ?>
                        <section class="game-section" id="reviews-section">
                            <div class="game-section__header">
                                <h2 class="game-section__title">Avis des membres</h2>
                                <?php if ($avgReview !== null): ?>
                                    <div class="reviews-summary">
                                        <span class="reviews-summary__score"
                                              style="color:<?= scoreColor($avgReview * 10) ?>">
                                            <?= number_format($avgReview, 1) ?>
                                        </span>
                                        <span class="reviews-summary__sub">
                                            /10 &nbsp;·&nbsp; <?= count($reviews) ?> avis
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($reviews)): ?>
                                <div class="reviews-list">
                                    <?php foreach ($reviews as $rev):
                                        $reviewer = $rev['users'] ?? [];
                                        $rating   = (int) ($rev['rating'] ?? 0);
                                    ?>
                                        <article class="review-card">
                                            <div class="review-card__header">
                                                <?php if (!empty($reviewer['avatar_url'])): ?>
                                                    <img class="review-card__avatar"
                                                         src="<?= htmlspecialchars($reviewer['avatar_url']) ?>"
                                                         alt="" loading="lazy">
                                                <?php else: ?>
                                                    <span class="review-card__avatar review-card__avatar--placeholder">
                                                        <?= strtoupper(substr($reviewer['username'] ?? 'M', 0, 1)) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <div class="review-card__user">
                                                    <strong><?= htmlspecialchars($reviewer['username'] ?? 'Membre') ?></strong>
                                                    <time class="review-card__date"
                                                          datetime="<?= htmlspecialchars(substr($rev['created_at'] ?? '', 0, 10)) ?>">
                                                        <?= fmtGameDate(substr($rev['created_at'] ?? '', 0, 10)) ?>
                                                    </time>
                                                </div>
                                                <div class="review-card__rating"
                                                     style="--r: <?= scoreColor($rating * 10) ?>">
                                                    <span><?= $rating ?></span><em>/10</em>
                                                </div>
                                            </div>
                                            <div class="review-card__body">
                                                <?= nl2br(htmlspecialchars($rev['body'] ?? '')) ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="game-empty">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                    </svg>
                                    <p>Aucun avis pour le moment.</p>
                                    <?php if ($authUser): ?>
                                        <p class="game-empty__sub">Les avis sont réservés aux membres ayant terminé ce jeu.</p>
                                    <?php else: ?>
                                        <p class="game-empty__sub">
                                            <a href="/register" class="text-link">Créez un compte</a> pour laisser un avis après avoir terminé ce jeu.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </section>

        </div><!-- /.game-main -->

        <?php /* ───────────── SIDEBAR ───────────── */ ?>
        <aside class="game-sidebar">

            <?php /* Informations */ ?>
            <div class="game-info-box">
                <h2 class="game-info-box__title">Informations</h2>
                <dl class="game-info-list">

                    <?php if ($releaseDate): ?>
                        <dt>Sortie</dt>
                        <dd><?= fmtGameDate($releaseDate) ?></dd>
                    <?php endif; ?>

                    <?php if ($developer): ?>
                        <dt>Développeur</dt>
                        <dd><?= htmlspecialchars($developer) ?></dd>
                    <?php endif; ?>

                    <?php if ($publisher && $publisher !== $developer): ?>
                        <dt>Éditeur</dt>
                        <dd><?= htmlspecialchars($publisher) ?></dd>
                    <?php endif; ?>

                    <?php if (!empty($genres)): ?>
                        <dt>Genres</dt>
                        <dd>
                            <div class="info-tags">
                                <?php foreach ($genres as $g):
                                    $gn = is_array($g) ? ($g['name'] ?? '') : '';
                                    if (!$gn) continue;
                                ?>
                                    <span class="info-tag"><?= htmlspecialchars($gn) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($themes)): ?>
                        <dt>Thèmes</dt>
                        <dd>
                            <div class="info-tags">
                                <?php foreach ($themes as $t): ?>
                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($t) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($gameModes)): ?>
                        <dt>Modes</dt>
                        <dd>
                            <div class="info-tags">
                                <?php foreach ($gameModes as $m): ?>
                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($m) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($perspectives)): ?>
                        <dt>Perspective</dt>
                        <dd>
                            <div class="info-tags">
                                <?php foreach ($perspectives as $p): ?>
                                    <span class="info-tag info-tag--dim"><?= htmlspecialchars($p) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($game['igdb_rating_count'])): ?>
                        <dt>Votes IGDB</dt>
                        <dd><?= number_format((int) $game['igdb_rating_count']) ?></dd>
                    <?php endif; ?>

                </dl>
            </div>

            <?php /* Dates de sortie par région */ ?>
            <?php if (!empty($releasesByRegion)): ?>
            <div class="game-info-box">
                <h2 class="game-info-box__title">Dates de sortie</h2>
                <?php foreach ($releasesByRegion as $region => $entries): ?>
                    <div class="release-block">
                        <h3 class="release-block__region">
                            <?= htmlspecialchars($regionLabels[$region] ?? ucfirst($region)) ?>
                        </h3>
                        <div class="release-entries">
                            <?php foreach ($entries as $e):
                                $pf     = $e['platform'];
                                $pLabel = trim($pf['abbreviation'] ?? '');
                                if (!$pLabel) $pLabel = PlatformAbbreviations::get($pf['name'] ?? '') ?: ($pf['name'] ?? '?');
                            ?>
                                <div class="release-entry">
                                    <span class="release-entry__platform"><?= htmlspecialchars($pLabel) ?></span>
                                    <span class="release-entry__date"><?= fmtGameDate($e['date']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* Liens web */ ?>
            <?php if (!empty($websites)): ?>
            <div class="game-info-box">
                <h2 class="game-info-box__title">Liens</h2>
                <div class="game-links">
                    <?php foreach ($websites as $w): ?>
                        <a class="game-link"
                           href="<?= htmlspecialchars($w['url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <?php /* Icônes SVG inline selon type */ ?>
                            <?php if ($w['icon'] === 'globe'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            <?php elseif ($w['icon'] === 'steam'): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.606 0 11.979 0z"/></svg>
                            <?php elseif ($w['icon'] === 'wiki'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <?php elseif ($w['icon'] === 'youtube'): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/></svg>
                            <?php elseif ($w['icon'] === 'reddit'): ?>
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            <?php endif; ?>
                            <?= htmlspecialchars($w['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </aside><!-- /.game-sidebar -->

    </div><!-- /.game-body -->
</div>

<?php /* ══════════════════════════════════ MODAL : AJOUTER À LA COLLECTION ══ */ ?>
<?php if ($authUser): ?>
<div id="modal-collection"
     class="modal"
     aria-hidden="true"
     role="dialog"
     aria-label="Ajouter à ma collection"
     aria-modal="true">
    <div class="modal__backdrop" id="modal-collection-backdrop"></div>
    <div class="modal__box">
        <div class="modal__header">
            <h3 class="modal__title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                Ajouter à ma collection
            </h3>
            <button class="modal__close" id="modal-collection-close" aria-label="Fermer">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="modal__body">
            <div class="modal__game-preview">
                <?php if ($coverUrl): ?>
                    <img src="<?= htmlspecialchars(gameCoverBig($coverUrl)) ?>"
                         alt="" class="modal__game-cover">
                <?php endif; ?>
                <div>
                    <p class="modal__game-title"><?= htmlspecialchars($title) ?></p>
                    <?php if ($releaseYear): ?>
                        <p class="modal__game-year"><?= htmlspecialchars($releaseYear) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($uniquePlatforms)): ?>
                <p class="modal__hint">Sur quelle plateforme avez-vous ce jeu ?</p>
                <div class="modal__platforms" id="modal-platform-list">
                    <?php foreach ($uniquePlatforms as $p): ?>
                        <button type="button"
                                class="modal__platform-btn"
                                data-platform-id="<?= (int) ($p['id'] ?? 0) ?>"
                                data-platform-name="<?= htmlspecialchars($p['name'] ?? '') ?>">
                            <span class="modal__platform-abbr"><?= platformLabel($p) ?></span>
                            <span class="modal__platform-name"><?= htmlspecialchars($p['name'] ?? '') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="modal__note">
                    Vous pourrez préciser la région, l'édition et l'état sur la page suivante.
                </p>
            <?php else: ?>
                <p class="modal__hint modal__hint--empty">
                    Aucune plateforme connue pour ce jeu.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ══════════════════════════════════ LIGHTBOX GALERIE ═══════════════════ */ ?>
<div id="lightbox"
     class="lightbox"
     aria-hidden="true"
     role="dialog"
     aria-label="Galerie"
     aria-modal="true">
    <div class="lightbox__backdrop" id="lightbox-backdrop"></div>
    <button class="lightbox__close" id="lightbox-close" aria-label="Fermer la galerie">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
    </button>
    <button class="lightbox__nav lightbox__nav--prev" id="lightbox-prev" aria-label="Image précédente">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
    </button>
    <button class="lightbox__nav lightbox__nav--next" id="lightbox-next" aria-label="Image suivante">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M9 18l6-6-6-6"/>
        </svg>
    </button>
    <div class="lightbox__inner">
        <img class="lightbox__img" id="lightbox-img" src="" alt="">
        <p class="lightbox__counter" id="lightbox-counter" aria-live="polite"></p>
    </div>
</div>

<?php /* ══════════════════════════════════ MODALE VIDÉO ════════════════════════ */ ?>
<div id="modal-video"
     class="modal modal--video"
     aria-hidden="true"
     role="dialog"
     aria-label="Vidéo"
     aria-modal="true">
    <div class="modal__backdrop" id="modal-video-backdrop"></div>
    <div class="modal__box modal__box--video">
        <button class="modal__close" id="modal-video-close" aria-label="Fermer la vidéo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
        <div class="video-embed-wrap" id="video-embed-container"></div>
    </div>
</div>
