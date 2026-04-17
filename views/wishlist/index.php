<?php
/**
 * Vue : /wishlist — Wishlist utilisateur
 *
 * Variables :
 *   $items        list<array{game:array,created_at:string}>
 *   $totalResults int
 *   $filters      array
 *   $filterOptions array{platforms?:array,genres?:array}
 *   $pager        \App\Core\Pagination
 */

use App\Data\PlatformAbbreviations;
use App\Data\PlatformBadgeColors;

function wlCoverSrc(?string $url): string
{
    if (!$url) return '';
    if (str_starts_with($url, '//')) return 'https:' . $url;
    if (str_starts_with($url, '/') || str_starts_with($url, 'http')) return $url;
    // En mode "IGDB direct", on ne sert plus de fichiers locaux.
    return '';
}

function wlFmtDateShort(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    if (!$ts) return $date;
    $months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    $m = (int) date('n', $ts);
    $mon = $months[$m - 1] ?? date('M', $ts);
    return (int) date('j', $ts) . ' ' . $mon . ' ' . date('Y', $ts);
}

$items        = is_array($items ?? null) ? $items : [];
$totalResults = (int) ($totalResults ?? 0);
$filters      = is_array($filters ?? null) ? $filters : [];

$viewMode = $_COOKIE['view_mode'] ?? 'grid';

// platformMap (id -> label/slug/name/abbreviation) pour badges, basé sur options cache SearchService
$platformMap = [];
foreach (($filterOptions['platforms'] ?? []) as $p) {
    $id = (int) ($p['id'] ?? 0);
    if ($id <= 0) continue;
    $label = trim((string) ($p['abbreviation'] ?? ''));
    if ($label === '') {
        $label = PlatformAbbreviations::get(trim((string) ($p['name'] ?? ''))) ?: (string) ($p['name'] ?? '');
    }
    $platformMap[$id] = [
        'label' => $label,
        'slug'  => (string) ($p['slug'] ?? ''),
        'name'  => (string) ($p['name'] ?? ''),
        'abbreviation' => (string) ($p['abbreviation'] ?? ''),
    ];
}

$platformBadgeStyle = static function (int $id) use ($platformMap): string {
    $row = $platformMap[$id] ?? null;
    if (!is_array($row)) return '';
    return PlatformBadgeColors::style(
        $id,
        (string) ($row['slug'] ?? ''),
        (string) ($row['abbreviation'] ?? ''),
        (string) ($row['name'] ?? '')
    );
};
?>

<div class="wl-hero">
    <div class="container">
        <div class="wl-hero__row">
            <div class="wl-hero__left">
                <h1 class="wl-hero__title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                    </svg>
                    Ma wishlist
                </h1>
                <div class="wl-hero__meta">
                    <?php if ($totalResults > 0): ?>
                        <span class="wl-hero__pill">
                            <strong><?= number_format($totalResults) ?></strong>
                            jeu<?= $totalResults > 1 ? 'x' : '' ?>
                        </span>
                        <span class="wl-hero__hint">triés par date de sortie · retrait en un clic</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wl-hero__right">
                <?php require __DIR__ . '/../partials/view-toggle.php'; ?>
            </div>
        </div>
    </div>
</div>

<div class="container wl-content">
    <?php
        $activeFilters = $filters;
        $filterOptions = $filterOptions ?? [];
        $totalResults  = $totalResults;
        require __DIR__ . '/../partials/filters.php';
    ?>

    <div class="wl-toolbar">
        <p class="wl-count" aria-live="polite">
            <?php if ($totalResults > 0): ?>
                <strong><?= number_format($totalResults) ?></strong>
                jeu<?= $totalResults > 1 ? 'x' : '' ?> dans ma wishlist
            <?php else: ?>
                Aucun jeu dans votre wishlist
            <?php endif; ?>
        </p>
    </div>

    <?php if (empty($items)): ?>
        <div class="wl-empty">
            <div class="wl-empty__icon" aria-hidden="true">
                <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M32 6c14.36 0 26 11.64 26 26S46.36 58 32 58 6 46.36 6 32 17.64 6 32 6z"/>
                    <path d="M32 18v16" stroke-linecap="round"/>
                    <path d="M32 42h.01" stroke-linecap="round"/>
                </svg>
            </div>
            <h2 class="wl-empty__title">Votre wishlist est vide</h2>
            <p class="wl-empty__desc">Parcourez le catalogue et cliquez sur “Je veux” sur une fiche jeu.</p>
            <a href="/search" class="btn btn--primary">Découvrir des jeux</a>
        </div>
    <?php else: ?>
        <div class="game-grid game-grid--<?= htmlspecialchars($viewMode) ?>" id="results-grid">
            <?php foreach ($items as $it):
                $g = $it['game'] ?? [];
                $src = wlCoverSrc($g['cover_url'] ?? '');
                $rating = isset($g['igdb_rating']) ? round((float) $g['igdb_rating']) : null;
                $platformIds = $g['platform_ids'] ?? [];
                if (is_string($platformIds)) $platformIds = json_decode($platformIds, true);
                $platformIds = is_array($platformIds) ? array_values(array_filter($platformIds, 'is_numeric')) : [];
                $badges = [];
                $more = 0;
                foreach ($platformIds as $pid) {
                    $pid = (int) $pid;
                    if ($pid <= 0) continue;
                    if (!isset($platformMap[$pid])) continue;
                    if (count($badges) < 2) {
                        $badges[] = ['id' => $pid, 'label' => (string) ($platformMap[$pid]['label'] ?? '')];
                    } else {
                        $more++;
                    }
                }
            ?>
            <article class="wl-card" data-game-id="<?= (int) ($g['id'] ?? 0) ?>">
                <div class="wl-card__cover">
                    <div class="wl-card__placeholder" aria-hidden="true">
                        <?= htmlspecialchars(mb_substr($g['title'] ?? '?', 0, 28)) ?>
                    </div>
                    <?php if ($src): ?>
                        <img src="<?= htmlspecialchars($src) ?>"
                             alt="<?= htmlspecialchars($g['title'] ?? '') ?>"
                             loading="lazy"
                             onerror="this.style.display='none'">
                    <?php endif; ?>
                    <?php if ($rating !== null): ?>
                        <span class="wl-card__rating
                            <?= $rating >= 80 ? 'wl-card__rating--high' : ($rating >= 60 ? 'wl-card__rating--mid' : 'wl-card__rating--low') ?>">
                            <?= $rating ?>
                        </span>
                    <?php endif; ?>
                    <button class="wl-card__remove"
                            type="button"
                            data-action="remove"
                            aria-label="Retirer <?= htmlspecialchars($g['title'] ?? '') ?> de ma wishlist"
                            title="Retirer de ma wishlist">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="wl-card__body">
                    <h2 class="wl-card__title">
                        <a href="/game/<?= htmlspecialchars($g['slug'] ?? (string)($g['id'] ?? 0)) ?>">
                            <?= htmlspecialchars($g['title'] ?? '—') ?>
                        </a>
                    </h2>
                    <?php if (!empty($badges)): ?>
                        <div class="wl-card__platforms" aria-label="Plateformes">
                            <?php foreach ($badges as $b): ?>
                                <span class="platform-badge platform-badge--xs"
                                      style="<?= htmlspecialchars($platformBadgeStyle((int) ($b['id'] ?? 0)), ENT_QUOTES) ?>">
                                    <?= htmlspecialchars((string) ($b['label'] ?? '')) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($more > 0): ?>
                                <span class="platform-badge platform-badge--xs platform-badge--more">
                                    <?= $more ?> autre<?= $more > 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="wl-card__meta">
                        <span class="wl-card__date">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                            <?= htmlspecialchars(wlFmtDateShort($g['release_date'] ?? null)) ?>
                        </span>
                        <?php if (!empty($g['developer'])): ?>
                            <span class="wl-card__meta-sep" aria-hidden="true">·</span>
                            <span class="wl-card__dev"><?= htmlspecialchars((string) $g['developer']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($it['created_at'])): ?>
                            <span class="wl-card__meta-sep" aria-hidden="true">·</span>
                            <span class="wl-card__added">Ajouté le <?= htmlspecialchars(wlFmtDateShort($it['created_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>

