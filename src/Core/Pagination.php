<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Calcule les paramètres de pagination à partir du total et de la page courante.
 *
 * Utilisation dans un contrôleur :
 *   $page  = (int) ($_GET['page'] ?? 1);
 *   $pager = new Pagination(total: $total, perPage: 24, currentPage: $page);
 *   // Passer $pager à la vue → utiliser views/partials/pagination.php
 */
class Pagination
{
    public readonly int $total;
    public readonly int $perPage;
    public readonly int $currentPage;
    public readonly int $totalPages;
    public readonly int $offset;
    public readonly int $firstItem;
    public readonly int $lastItem;

    public function __construct(int $total, int $perPage = 24, int $currentPage = 1)
    {
        $this->total       = max(0, $total);
        $this->perPage     = max(1, $perPage);
        $this->totalPages  = max(1, (int) ceil($this->total / $this->perPage));
        $this->currentPage = max(1, min($currentPage, $this->totalPages));
        $this->offset      = ($this->currentPage - 1) * $this->perPage;
        $this->firstItem   = $this->total === 0 ? 0 : $this->offset + 1;
        $this->lastItem    = min($this->offset + $this->perPage, $this->total);
    }

    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * Génère l'URL d'une page en préservant tous les paramètres GET existants.
     */
    public function pageUrl(int $page, ?array $extraParams = null): string
    {
        $params         = array_merge($_GET, $extraParams ?? []);
        $params['page'] = $page;

        // Ne pas afficher page=1 dans l'URL (URL canonique)
        if ($params['page'] === 1) {
            unset($params['page']);
        }

        $qs = http_build_query($params);
        return $qs !== '' ? '?' . $qs : parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    /**
     * Retourne la liste des numéros de pages à afficher dans le composant,
     * avec null pour les ellipses.
     *
     * Ex. pour page=7/20, range=2 : [1, null, 5, 6, 7, 8, 9, null, 20]
     *
     * @return array<int|null>
     */
    public function window(int $range = 2): array
    {
        $pages = [];
        $start = max(1, $this->currentPage - $range);
        $end   = min($this->totalPages, $this->currentPage + $range);

        if ($start > 1) {
            $pages[] = 1;
            if ($start > 2) {
                $pages[] = null; // ellipse gauche
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $this->totalPages) {
            if ($end < $this->totalPages - 1) {
                $pages[] = null; // ellipse droite
            }
            $pages[] = $this->totalPages;
        }

        return $pages;
    }
}
