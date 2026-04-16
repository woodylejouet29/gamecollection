<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Pagination;
use App\Core\View;
use App\Services\UserProfileService;

class UserController
{
    public function show(string $slug): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            View::redirect('/');
        }

        $viewerId = AuthMiddleware::userId();
        $authUser = AuthMiddleware::user();

        $service = new UserProfileService();
        $profile = $service->getByUsername($slug, $viewerId);

        if ($profile === null) {
            http_response_code(404);
            $errorPage = __DIR__ . '/../../errors/404.php';
            if (file_exists($errorPage)) {
                require $errorPage;
            } else {
                echo '<h1>404 — Profil introuvable</h1>';
            }
            exit;
        }

        $user = $profile['user'] ?? [];
        $isPrivate = !empty($user['_private']);

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 24;

        $collection = ['entries' => [], 'total' => 0];
        $pager = new Pagination(total: 0, perPage: $perPage, currentPage: $page);
        $stats = ['stats' => [], 'last_reviews' => []];

        if (!$isPrivate) {
            $stats = $service->getStatsAndReviews((string) ($user['id'] ?? ''));
            $collection = $service->getCollectionEntries((string) ($user['id'] ?? ''), $page, $perPage);
            $pager = new Pagination(total: (int) ($collection['total'] ?? 0), perPage: $perPage, currentPage: $page);
        }

        View::render('user/show', [
            'title'      => ($user['username'] ?? 'Profil') . ' — Profil',
            'cssFile'    => 'user',
            'userProfile'=> $user,
            'platforms'  => $profile['platforms'] ?? [],
            'genres'     => $profile['genres'] ?? [],
            'isPrivate'  => $isPrivate,
            'stats'      => $stats['stats'] ?? [],
            'lastReviews'=> $stats['last_reviews'] ?? [],
            'entries'    => $collection['entries'] ?? [],
            'totalResults' => (int) ($collection['total'] ?? 0),
            'pager'      => $pager,
            'authUser'   => $authUser,
        ]);
    }
}

