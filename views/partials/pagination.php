<?php
/**
 * Composant de pagination.
 *
 * Usage dans une vue :
 *   <?php require __DIR__ . '/../partials/pagination.php'; ?>
 *
 * Variable attendue :
 *   $pager  instance de App\Core\Pagination
 */

/** @var \App\Core\Pagination $pager */
if (!isset($pager) || $pager->totalPages <= 1) {
    return;
}
?>
<nav class="pagination" aria-label="Pagination">

    <?php /* Précédent */ ?>
    <?php if ($pager->hasPrev()): ?>
    <a href="<?= htmlspecialchars($pager->pageUrl($pager->currentPage - 1)) ?>"
       class="pagination__btn"
       aria-label="Page précédente"
       rel="prev">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
    </a>
    <?php else: ?>
    <span class="pagination__btn is-disabled" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
    </span>
    <?php endif; ?>

    <?php /* Pages numérotées */ ?>
    <?php foreach ($pager->window() as $p): ?>
        <?php if ($p === null): ?>
            <span class="pagination__ellipsis" aria-hidden="true">…</span>
        <?php elseif ($p === $pager->currentPage): ?>
            <span class="pagination__btn is-active" aria-current="page" aria-label="Page <?= $p ?>, page courante">
                <?= $p ?>
            </span>
        <?php else: ?>
            <a href="<?= htmlspecialchars($pager->pageUrl($p)) ?>"
               class="pagination__btn"
               aria-label="Page <?= $p ?>">
                <?= $p ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php /* Suivant */ ?>
    <?php if ($pager->hasNext()): ?>
    <a href="<?= htmlspecialchars($pager->pageUrl($pager->currentPage + 1)) ?>"
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

    <?php /* Résumé textuel */ ?>
    <p class="pagination__info">
        Résultats <?= $pager->firstItem ?>–<?= $pager->lastItem ?> sur <?= number_format($pager->total) ?>
    </p>

</nav>
