<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Pagination;
use App\Core\View;
use App\Services\SearchService;
use App\Services\WishlistListService;

class WishlistController
{
    public function index(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId() ?? '';
        $authUser = AuthMiddleware::user();

        $filters = [
            'q'          => trim((string) ($_GET['q'] ?? '')),
            'platform'   => (int) ($_GET['platform'] ?? 0),
            'genre'      => trim((string) ($_GET['genre'] ?? '')),
            'region'     => trim((string) ($_GET['region'] ?? '')),
            'rating_min' => (int) ($_GET['rating_min'] ?? 0),
        ];

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 24;

        $service = new WishlistListService();
        $result  = $service->getItems($userId, $filters, $page, $perPage);

        $total = (int) ($result['total'] ?? 0);
        $pager = new Pagination(total: $total, perPage: $perPage, currentPage: $page);

        // Options de filtres (réutilise le cache SearchService)
        $opts = (new SearchService())->getFilterOptions();

        View::render('wishlist/index', [
            'title'         => 'Ma wishlist',
            'cssFile'       => 'wishlist',
            'metaDesc'      => 'Vos jeux à venir : triez par date de sortie et gérez votre wishlist.',
            'items'         => $result['items'] ?? [],
            'totalResults'  => $total,
            'filters'       => $filters,
            'filterOptions' => $opts,
            'pager'         => $pager,
            'authUser'      => $authUser,
            'foot'          => '<script src="' . View::asset('js/wishlist.js') . '" defer></script>',
        ]);
    }
}

