<?php
/**
 * Vue : /user/{slug} — Profil public
 *
 * Variables:
 *   $userProfile array
 *   $platforms   list<array>
 *   $genres      list<string>
 *   $isPrivate   bool
 *   $stats       array
 *   $lastReviews list<array>
 *   $entries     list<array> (shape CollectionListService::normalizeEntry)
 *   $pager       \App\Core\Pagination
 */

use App\Data\GenreTranslations;
use App\Data\PlatformBadgeColors;
use App\Data\PlatformAbbreviations;

function userCoverSrc(?string $url): string
{
    if (!$url) return '';
    if (str_starts_with($url, '/') || str_starts_with($url, 'http')) return $url;
    return '/storage/images/igdb/' . $url;
}

function userFmtDate(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

$u = $userProfile ?? [];
$platforms = is_array($platforms ?? null) ? $platforms : [];
$genres = is_array($genres ?? null) ? $genres : [];
$isPrivate = !empty($isPrivate);
$stats = is_array($stats ?? null) ? $stats : [];
$lastReviews = is_array($lastReviews ?? null) ? $lastReviews : [];
$entries = is_array($entries ?? null) ? $entries : [];

$viewMode = $_COOKIE['view_mode'] ?? 'grid';
?>

<section class="user-hero">
    <div class="container">
        <div class="user-hero__row">
            <div class="user-hero__avatar">
                <?php if (!empty($u['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars((string) $u['avatar_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="user-hero__placeholder" aria-hidden="true">
                        <?= strtoupper(substr((string) ($u['username'] ?? 'U'), 0, 1)) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="user-hero__main">
                <h1 class="user-hero__name"><?= htmlspecialchars((string) ($u['username'] ?? 'Profil')) ?></h1>
                <?php if (!empty($u['bio'])): ?>
                    <p class="user-hero__bio"><?= nl2br(htmlspecialchars((string) $u['bio'])) ?></p>
                <?php else: ?>
                    <p class="user-hero__bio user-hero__bio--dim">Aucune bio pour le moment.</p>
                <?php endif; ?>

                <div class="user-hero__chips">
                    <?php if (!empty($platforms)): ?>
                        <div class="user-chip-group" aria-label="Plateformes préférées">
                            <?php foreach (array_slice($platforms, 0, 6) as $p):
                                $id = (int) ($p['id'] ?? 0);
                                $name = (string) ($p['name'] ?? '');
                                $abbr = trim((string) ($p['abbreviation'] ?? ''));
                                if ($abbr === '') $abbr = PlatformAbbreviations::get($name) ?: $name;
                            ?>
                                <span class="platform-badge"
                                      style="<?= htmlspecialchars(PlatformBadgeColors::style(
                                          $id,
                                          (string) ($p['slug'] ?? ''),
                                          (string) ($p['abbreviation'] ?? ''),
                                          $name
                                      ), ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($abbr) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($genres)): ?>
                        <div class="user-chip-group" aria-label="Genres préférés">
                            <?php foreach (array_slice($genres, 0, 6) as $g): ?>
                                <span class="user-chip user-chip--dim"><?= htmlspecialchars(GenreTranslations::translate((string) $g)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="user-hero__side">
                <?php require __DIR__ . '/../partials/view-toggle.php'; ?>
            </div>
        </div>
    </div>
</section>

<div class="container user-content">

    <?php if ($isPrivate): ?>
        <div class="user-private">
            <div class="user-private__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/>
                    <path d="M6 11V8a6 6 0 0 1 12 0v3"/>
                    <rect x="5" y="11" width="14" height="10" rx="2"/>
                </svg>
            </div>
            <h2 class="user-private__title">Collection privée</h2>
            <p class="user-private__desc">
                Ce membre a choisi de garder sa collection privée.
            </p>
        </div>
    <?php else: ?>

        <section class="user-stats">
            <div class="user-stat">
                <span class="user-stat__val"><?= number_format((int) ($stats['total_games'] ?? 0)) ?></span>
                <span class="user-stat__lbl">jeux</span>
            </div>
            <div class="user-stat user-stat--sep"></div>
            <div class="user-stat">
                <span class="user-stat__val"><?= number_format((int) ($stats['completed_games'] ?? 0)) ?></span>
                <span class="user-stat__lbl">terminés</span>
            </div>
            <div class="user-stat user-stat--sep"></div>
            <div class="user-stat">
                <span class="user-stat__val"><?= number_format((int) ($stats['hundred_percent_games'] ?? 0)) ?></span>
                <span class="user-stat__lbl">100%</span>
            </div>
            <div class="user-stat user-stat--sep"></div>
            <div class="user-stat">
                <span class="user-stat__val"><?= $stats['average_rating'] !== null ? number_format((float) $stats['average_rating'], 1) : '—' ?></span>
                <span class="user-stat__lbl">note moy.</span>
            </div>
        </section>

        <?php if (!empty($lastReviews)): ?>
            <section class="user-section">
                <div class="user-section__head">
                    <h2 class="user-section__title">Derniers avis</h2>
                </div>
                <div class="user-reviews">
                    <?php foreach ($lastReviews as $r):
                        $g = $r['game'] ?? [];
                        $src = userCoverSrc($g['cover_url'] ?? '');
                        $rating = (int) ($r['rating'] ?? 0);
                        $date = substr((string) ($r['created_at'] ?? ''), 0, 10);
                    ?>
                        <article class="user-review">
                            <a class="user-review__cover" href="/game/<?= htmlspecialchars((string) ($g['slug'] ?? '')) ?>" aria-label="Voir la fiche du jeu">
                                <?php if ($src): ?>
                                    <img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="user-review__cover-ph" aria-hidden="true"></span>
                                <?php endif; ?>
                            </a>
                            <div class="user-review__body">
                                <div class="user-review__top">
                                    <strong class="user-review__game"><?= htmlspecialchars((string) ($g['title'] ?? '')) ?></strong>
                                    <span class="user-review__rating"><?= $rating ?>/10</span>
                                </div>
                                <p class="user-review__text">
                                    <?= htmlspecialchars(mb_substr((string) ($r['body'] ?? ''), 0, 160)) ?><?= mb_strlen((string) ($r['body'] ?? '')) > 160 ? '…' : '' ?>
                                </p>
                                <?php if ($date): ?>
                                    <time class="user-review__date" datetime="<?= htmlspecialchars($date) ?>"><?= htmlspecialchars(userFmtDate($date)) ?></time>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="user-section">
            <div class="user-section__head">
                <h2 class="user-section__title">Collection publique</h2>
                <p class="user-section__sub"><?= number_format((int) ($totalResults ?? 0)) ?> entrée<?= ((int)($totalResults ?? 0)) > 1 ? 's' : '' ?></p>
            </div>

            <?php if (empty($entries)): ?>
                <div class="user-empty">Aucune entrée publique pour le moment.</div>
            <?php else: ?>
                <div class="game-grid game-grid--<?= htmlspecialchars($viewMode) ?>" id="results-grid">
                    <?php foreach ($entries as $entry):
                        $g = $entry['game'] ?? [];
                        $src = userCoverSrc($g['cover_url'] ?? '');
                    ?>
                        <article class="user-col-card">
                            <a class="user-col-card__cover" href="/game/<?= htmlspecialchars((string) ($g['slug'] ?? '')) ?>">
                                <?php if ($src): ?>
                                    <img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="user-col-card__ph" aria-hidden="true"></span>
                                <?php endif; ?>
                            </a>
                            <button class="wish-badge wish-badge--overlay"
                                    type="button"
                                    data-action="wishlist-toggle"
                                    data-game-id="<?= (int)($g['id'] ?? 0) ?>"
                                    aria-pressed="false"
                                    aria-label="Ajouter à ma wishlist"
                                    title="Ajouter à ma wishlist">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M13 3c0 3-2 4-2 6 0 1.2.8 2.2 2 2.6 0-2.2 2-3.4 2-6 2.5 2 4 4.4 4 7.4A7 7 0 1 1 5 13c0-2.3 1.1-4.3 2.6-5.8.2 1.7 1.2 3.1 2.6 4C9.4 7.6 11 6.2 13 3Z"
                                          fill="currentColor"/>
                                </svg>
                            </button>
                            <div class="user-col-card__body">
                                <strong class="user-col-card__title">
                                    <a href="/game/<?= htmlspecialchars((string) ($g['slug'] ?? '')) ?>">
                                        <?= htmlspecialchars((string) ($g['title'] ?? '—')) ?>
                                    </a>
                                </strong>
                                <?php if (!empty($g['release_date'])): ?>
                                    <span class="user-col-card__meta"><?= htmlspecialchars(userFmtDate((string) $g['release_date'])) ?></span>
                                <?php endif; ?>
                                <button class="wish-badge wish-badge--inline"
                                        type="button"
                                        data-action="wishlist-toggle"
                                        data-game-id="<?= (int)($g['id'] ?? 0) ?>"
                                        aria-pressed="false"
                                        aria-label="Ajouter à ma wishlist"
                                        title="Ajouter à ma wishlist">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M13 3c0 3-2 4-2 6 0 1.2.8 2.2 2 2.6 0-2.2 2-3.4 2-6 2.5 2 4 4.4 4 7.4A7 7 0 1 1 5 13c0-2.3 1.1-4.3 2.6-5.8.2 1.7 1.2 3.1 2.6 4C9.4 7.6 11 6.2 13 3Z"
                                              fill="currentColor"/>
                                    </svg>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php require __DIR__ . '/../partials/pagination.php'; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

