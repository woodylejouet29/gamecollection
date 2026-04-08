<?php
$flash = \App\Core\Flash::message();
if ($flash):
?>
<div class="flash flash--<?= htmlspecialchars($flash['type']) ?>" role="alert">
    <span class="flash__text"><?= htmlspecialchars($flash['text']) ?></span>
    <button class="flash__close" onclick="this.parentElement.remove()" aria-label="Fermer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
    </button>
</div>
<?php endif; ?>
