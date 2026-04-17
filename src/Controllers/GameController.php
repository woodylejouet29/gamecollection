<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\View;
use App\Services\CollectionReleasePolicy;
use App\Services\GameService;

class GameController
{
    public function show(string $slug): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            View::redirect('/search');
        }

        $authUser = AuthMiddleware::user();
        $userId   = AuthMiddleware::userId();

        $service = new GameService();
        $data    = $service->getBySlug($slug, $userId);

        if ($data === null) {
            http_response_code(404);
            $errorPage = __DIR__ . '/../../errors/404.php';
            if (file_exists($errorPage)) {
                require $errorPage;
            } else {
                echo '<h1>404 — Jeu introuvable</h1>';
            }
            exit;
        }

        $game    = $data['game'];
        $rawIgdb = $game['raw_igdb'] ?? [];
        $appUrl  = rtrim($_ENV['APP_URL'] ?? '', '/');

        $collectionGate              = CollectionReleasePolicy::checkAddAllowed($game['release_date'] ?? null);
        $collectionAddBlocked        = !$collectionGate['allowed'];
        $collectionAddBlockedMessage = $collectionAddBlocked ? (string) ($collectionGate['message'] ?? '') : '';

        $gameTitle = $game['title'] ?? 'Jeu';
        $year      = $game['release_date'] ? substr($game['release_date'], 0, 4) : '';

        $metaDesc = !empty($game['developer'])
            ? ("Découvrez " . $gameTitle . " par " . $game['developer'] . " sur PlayShelf — notes, plateformes, galerie et avis des membres.")
            : "Découvrez {$gameTitle} sur PlayShelf — notes, plateformes, galerie et avis des membres.";

        $coverUrl = $this->absoluteUrl($game['cover_url'] ?? '', $appUrl);
        $ogImage  = $coverUrl ?: ($appUrl . '/assets/img/og-default.jpg');
        if (str_contains($ogImage, 'images.igdb.com')) {
            $ogImage = preg_replace('/t_[a-z_]+/', 't_cover_big', $ogImage);
        }

        $jsonLd = $this->buildJsonLd($game, $data, $appUrl);

        View::render('game/show', array_merge($data, [
            'title'    => $gameTitle . ($year ? " ({$year})" : ''),
            'cssFile'  => 'game',
            'metaDesc' => $metaDesc,
            'ogTitle'  => $gameTitle . ' — PlayShelf',
            'ogDesc'   => $metaDesc,
            'ogUrl'    => $appUrl . '/game/' . $slug,
            'ogImage'  => $ogImage,
            'authUser' => $authUser,
            'collection_add_blocked'         => $collectionAddBlocked,
            'collection_add_blocked_message' => $collectionAddBlockedMessage,
            'head'     => '<script type="application/ld+json">' . $jsonLd . '</script>',
            'foot'     => '<script src="' . View::asset('js/collection-release.js') . '" defer></script>'
                        . '<script>window.GAME_CONFIG=' . json_encode([
                'gameId'       => (int) ($game['id'] ?? 0),
                'gameSlug'     => $game['slug'] ?? '',
                'gameTitle'    => $game['title'] ?? '',
                'gameCover'    => $game['cover_url'] ?? '',
                'releaseDate'  => (string) ($game['release_date'] ?? ''),
                'isLoggedIn'   => (bool) $authUser,
                'isWishlisted' => (bool) $data['is_wishlisted'],
                'collectionCount' => (int) ($data['collection_count'] ?? 0),
                'collectionAddBlocked' => $collectionAddBlocked,
            ], JSON_UNESCAPED_SLASHES) . ';</script>'
                        . '<script src="' . View::asset('js/game.js') . '" defer></script>',
        ]));
    }

    // ─────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────

    private function absoluteUrl(?string $url, string $appUrl): string
    {
        if (!$url) return '';
        if (str_starts_with($url, '//'))   return 'https:' . $url;
        if (str_starts_with($url, 'http')) return $url;
        return $appUrl . '/' . ltrim($url, '/');
    }

    private function buildJsonLd(array $game, array $data, string $appUrl): string
    {
        $genres    = array_filter(
            array_map(fn($g) => is_array($g) ? ($g['name'] ?? '') : '', $game['genres'] ?? [])
        );
        $platforms = array_filter(
            array_map(fn($p) => $p['name'] ?? '', $data['unique_platforms'] ?? [])
        );

        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'VideoGame',
            'name'        => $game['title'] ?? '',
            'url'         => $appUrl . '/game/' . ($game['slug'] ?? ''),
            'description' => strip_tags(trim($game['synopsis'] ?? $game['storyline'] ?? '')),
        ];

        $coverUrl = $this->absoluteUrl($game['cover_url'] ?? '', $appUrl);
        if ($coverUrl) {
            $ld['image'] = str_contains($coverUrl, 'images.igdb.com')
                ? preg_replace('/t_[a-z_]+/', 't_cover_big', $coverUrl)
                : $coverUrl;
        }

        if (!empty($game['developer'])) {
            $ld['author']    = ['@type' => 'Organization', 'name' => $game['developer']];
        }
        if (!empty($game['publisher'])) {
            $ld['publisher'] = ['@type' => 'Organization', 'name' => $game['publisher']];
        }
        if (!empty($game['release_date'])) {
            $ld['datePublished'] = $game['release_date'];
        }
        if (!empty($genres))    $ld['genre']       = array_values($genres);
        if (!empty($platforms)) $ld['gamePlatform'] = array_values($platforms);

        if (!empty($game['igdb_rating'])) {
            $ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => round((float) $game['igdb_rating'] / 10, 1),
                'bestRating'  => 10,
                'worstRating' => 0,
                'ratingCount' => max(1, (int) ($game['igdb_rating_count'] ?? 1)),
            ];
        }

        return json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
