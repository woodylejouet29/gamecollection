<?php
/**
 * Pagination "cursor" (keyset pagination).
 *
 * Variables attendues :
 *   $baseUrl     string   URL /search?... SANS cursor (inclut filtres + sort)
 *   $nextCursor  ?string  token cursor (base64url JSON) pour la page suivante
 *   $pageSize    int
 *   $gamesCount  int
 *   $totalResults int
 */

$baseUrl      = $baseUrl      ?? '/search';
$nextCursor   = $nextCursor   ?? null;
$pageSize     = (int) ($pageSize ?? 24);
$gamesCount   = (int) ($gamesCount ?? 0);
$totalResults = (int) ($totalResults ?? 0);

if ($gamesCount <= 0) {
    return;
}

$hasNext = $nextCursor !== null && $gamesCount >= $pageSize;
?>

<nav class="pagination" aria-label="Pagination">
    <a href="<?= htmlspecialchars($baseUrl) ?>"
       class="pagination__btn"
       aria-label="Retour au début">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M11 19l-7-7 7-7"/><path d="M21 19V5"/>
        </svg>
    </a>

    <?php if ($hasNext): ?>
        <a href="<?= htmlspecialchars($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'cursor=' . rawurlencode($nextCursor)) ?>"
           class="pagination__btn"
           aria-label="Page suivante"
           rel="next">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M9 18l6-6-6-6"/>
            </svg>
        </a>
    <?php else: ?>
        <span class="pagination__btn is-disabled" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M9 18l6-6-6-6"/>
            </svg>
        </span>
    <?php endif; ?>

    <p class="pagination__info">
        <?= $totalResults > 0 ? ('Résultats (estimé) : ' . number_format($totalResults)) : 'Résultats' ?>
    </p>
</nav>

