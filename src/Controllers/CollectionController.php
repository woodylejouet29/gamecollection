<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\View;
use App\Services\CollectionListService;

class CollectionController
{
    private const VALID_SORTS = [
        'recent', 'title_asc', 'title_desc',
        'acquired_desc', 'acquired_asc',
        'rank_asc', 'price_desc', 'rating_desc',
    ];

    private const VALID_LISTS = [
        'all', 'playing', 'finished', 'hundred_percent',
        'abandoned', 'physical', 'digital', 'ranked',
    ];

    public function index(): void
    {
        AuthMiddleware::requireAuth();
        $authUser = AuthMiddleware::user();
        $userId   = AuthMiddleware::userId() ?? '';

        $filters = [
            'list'      => in_array($_GET['list']      ?? '', self::VALID_LISTS, true) ? $_GET['list']  : 'all',
            'platform'  => (int) ($_GET['platform']    ?? 0),
            'status'    => trim($_GET['status']        ?? ''),
            'game_type' => trim($_GET['game_type']     ?? ''),
            'region'    => trim($_GET['region']        ?? ''),
            'condition' => trim($_GET['condition']     ?? ''),
            'q'         => trim($_GET['q']             ?? ''),
            'sort'      => in_array($_GET['sort']      ?? '', self::VALID_SORTS, true) ? $_GET['sort'] : 'recent',
        ];

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 24;

        $service = new CollectionListService();

        $result  = $service->getEntries($userId, $filters, $page, $perPage);
        $stats   = $service->getStats($userId);
        $options = $service->getFilterOptions($userId);

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        // Lien de base (sans page) pour la pagination
        $baseParams = array_filter($filters, fn($v) => $v !== '' && $v !== 0);
        $baseUrl    = '/collection' . ($baseParams ? '?' . http_build_query($baseParams) : '');

        View::render('collection/index', [
            'title'         => 'Ma collection',
            'cssFile'       => 'collection',
            'metaDesc'      => 'Gérez votre collection personnelle de jeux vidéo.',
            'entries'       => $result['entries'],
            'totalResults'  => $result['total'],
            'filters'       => $filters,
            'filterOptions' => $options,
            'stats'         => $stats,
            'page'          => $page,
            'perPage'       => $perPage,
            'totalPages'    => $totalPages,
            'baseUrl'       => $baseUrl,
            'authUser'      => $authUser,
            'foot'          => '<script>window.COLLECTION_CONFIG=' . json_encode([
                'updateUrl' => '/api/collection/update',
                'deleteUrl' => '/api/collection/delete',
                'exportUrl' => '/api/collection/export',
            ], JSON_UNESCAPED_SLASHES) . ';</script>'
                            . '<script src="' . View::asset('js/collection.js') . '" defer></script>',
        ]);
    }
}
