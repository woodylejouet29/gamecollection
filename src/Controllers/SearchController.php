<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Data\PlatformAbbreviations;
use App\Services\SearchService;

class SearchController
{
    public function index(): void
    {
        $service = new SearchService();

        // Lecture des filtres depuis $_GET
        $filters = [
            'q'          => trim($_GET['q']          ?? ''),
            'platform'   => (int) ($_GET['platform'] ?? 0) ?: 0,
            'genre'      => trim($_GET['genre']       ?? ''),
            'year_from'  => trim($_GET['year_from']   ?? ''),
            'year_to'    => trim($_GET['year_to']     ?? ''),
            'rating_min' => (int) ($_GET['rating_min'] ?? 0),
            'sort'       => in_array($_GET['sort'] ?? '', ['recent', 'upcoming', 'oldest', 'rating', 'title_asc', 'title_desc'], true)
                                ? $_GET['sort']
                                : 'recent',
        ];

        $perPage = 24;
        $cursor  = isset($_GET['cursor']) ? trim((string) $_GET['cursor']) : '';

        // Détection requête AJAX (intercept JS via X-Requested-With)
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $baseUrl = $this->buildBaseUrl($filters);

        if ($isAjax) {
            // Requête AJAX : seulement la recherche (options déjà dans le DOM)
            $result = $service->searchCursor($filters, $cursor !== '' ? $cursor : null, $perPage);
            if (!is_array($result)) {
                $result = [];
            }
            $games = is_array($result['games'] ?? null) ? $result['games'] : [];
            $total = (int) ($result['total'] ?? 0);
            $platformMap = $this->platformMap($service);

            header('Content-Type: text/html; charset=utf-8');
            header('X-Search-Total: ' . $total);
            header('Cache-Control: no-store');

            View::renderPartial('search/_results', [
                'games'        => $games,
                'totalResults' => $total,
                'filters'      => $filters,
                'platformMap'  => $platformMap,
                'baseUrl'      => $baseUrl,
                'nextCursor'   => $result['next_cursor'] ?? null,
                'pageSize'     => $perPage,
            ]);
            return;
        }

        // Requête complète : search + options de filtres en parallèle (cold cache)
        // Note: on garde searchWithOptions pour options, mais on remplace la recherche par cursor.
        $combined = $service->searchWithOptions($filters, 1, $perPage);
        $result   = $combined['result'];
        $options  = $combined['options'];
        $platformMap = $this->platformMapFromOptions($options['platforms'] ?? []);

        // Si un cursor est présent au chargement initial (navigation directe),
        // on calcule la recherche cursor (sans recharger les options).
        if ($cursor !== '') {
            $result = $service->searchCursor($filters, $cursor, $perPage);
        } else {
            // Page 1 sans cursor : utiliser aussi cursor pour éviter OFFSET à terme.
            $result = $service->searchCursor($filters, null, $perPage);
        }
        if (!is_array($result)) {
            $result = [];
        }
        $games = is_array($result['games'] ?? null) ? $result['games'] : [];
        $total = (int) ($result['total'] ?? 0);

        $pageTitle = $filters['q'] !== ''
            ? 'Recherche : ' . htmlspecialchars($filters['q'])
            : 'Catalogue des jeux';

        View::render('search/index', [
            'title'         => $pageTitle,
            'cssFile'       => 'search',
            'metaDesc'      => 'Parcourez le catalogue complet de jeux vidéo. Filtrez par plateforme, genre, année et note.',
            'games'         => $games,
            'totalResults'  => $total,
            'filters'       => $filters,
            'filterOptions' => $options,
            'platformMap'   => $platformMap,
            'activeFilters' => array_filter($filters, static fn($v) => $v !== '' && $v !== 0),
            'baseUrl'       => $baseUrl,
            'nextCursor'    => $result['next_cursor'] ?? null,
            'pageSize'      => $perPage,
            // Important: `search.js` dépend de fonctions globales définies dans `app.js` (cookies + view toggle).
            // On l'injecte donc après `app.js` via le slot `$foot` du layout.
            'foot'          => '<script>window.SEARCH_CONFIG={gameUrl:"/api/games/",suggestUrl:"/api/games/search"};</script>'
                            . '<script src="' . View::asset('js/search.js') . '" defer></script>',
        ]);
    }

    private function buildBaseUrl(array $filters): string
    {
        $params = [];
        foreach ($filters as $k => $v) {
            if ($k === 'q') {
                $v = trim((string) $v);
                if ($v !== '') $params['q'] = $v;
                continue;
            }
            if ($v === '' || $v === 0 || $v === '0' || $v === null) {
                continue;
            }
            $params[$k] = $v;
        }
        $qs = http_build_query($params);
        return $qs !== '' ? ('/search?' . $qs) : '/search';
    }

    private function platformMap(SearchService $service): array
    {
        $opts = $service->getFilterOptions();
        return $this->platformMapFromOptions($opts['platforms'] ?? []);
    }

    private function platformMapFromOptions(array $platforms): array
    {
        $map = [];
        foreach ($platforms as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) continue;
            $label = trim((string) ($p['abbreviation'] ?? ''));
            if ($label === '') {
                $fullName = trim((string) ($p['name'] ?? ''));
                $label = PlatformAbbreviations::get($fullName);
            }
            if ($label !== '') {
                $map[$id] = $label;
            }
        }
        return $map;
    }
}

