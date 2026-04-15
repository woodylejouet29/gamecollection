<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */

// ──────────────────────────────────────────────
//  Pages publiques
// ──────────────────────────────────────────────
$router->get('/', [App\Controllers\HomeController::class, 'index']);

// ──────────────────────────────────────────────
//  Sitemap & robots.txt (P5.2 — SEO)
// ──────────────────────────────────────────────
$router->get('/sitemap.xml', [App\Controllers\SitemapController::class, 'index']);
$router->get('/robots.txt', [App\Controllers\SitemapController::class, 'robots']);

// ──────────────────────────────────────────────
//  Authentification (P1 — Bloc 1.2)
// ──────────────────────────────────────────────
$router->get('/register',  [App\Controllers\Auth\RegisterController::class, 'show']);
$router->post('/register', [App\Controllers\Auth\RegisterController::class, 'handle']);

$router->get('/login',   [App\Controllers\Auth\LoginController::class, 'show']);
$router->post('/login',  [App\Controllers\Auth\LoginController::class, 'handle']);
$router->get('/logout',  [App\Controllers\Auth\LoginController::class, 'logout']);

// ──────────────────────────────────────────────
//  Profil utilisateur (P1 — Bloc 1.2)
// ──────────────────────────────────────────────
$router->get('/edit-profile',  [App\Controllers\ProfileController::class, 'show']);
$router->post('/edit-profile', [App\Controllers\ProfileController::class, 'update']);

// ──────────────────────────────────────────────
//  Bloc 3.2 — Recherche /search
// ──────────────────────────────────────────────
$router->get('/search', [App\Controllers\SearchController::class, 'index']);

// API jeux : ordre important — /search AVANT /{id}
$router->get('/api/games/search', [App\Controllers\Api\GameApiController::class, 'search']);
$router->get('/api/games/{id}',   [App\Controllers\Api\GameApiController::class, 'show']);

// ──────────────────────────────────────────────
//  Routes P2-P6 — à décommenter au fur et à mesure
// ──────────────────────────────────────────────
$router->get('/game/{slug}', [App\Controllers\GameController::class, 'show']);
// ──────────────────────────────────────────────
//  Bloc 4.1 — Sélection /select (P4)
// ──────────────────────────────────────────────
$router->get('/select',  [App\Controllers\SelectController::class, 'index']);

$router->post('/api/collection/add',         [App\Controllers\Api\CollectionApiController::class, 'add']);
$router->post('/api/select/check-duplicate', [App\Controllers\Api\SelectApiController::class,    'checkDuplicate']);
$router->post('/api/uploads/selection-photo',[App\Controllers\Api\UploadApiController::class,    'uploadSelectionPhoto']);

// ──────────────────────────────────────────────
//  Bloc 4.2 — Collection /collection (P4)
// ──────────────────────────────────────────────
$router->get('/collection',                    [App\Controllers\CollectionController::class,        'index']);
$router->patch('/api/collection/update',       [App\Controllers\Api\CollectionApiController::class, 'update']);
$router->delete('/api/collection/delete',      [App\Controllers\Api\CollectionApiController::class, 'delete']);
$router->get('/api/collection/export',         [App\Controllers\Api\CollectionApiController::class, 'export']);
$router->get('/api/collection/export-xlsx-by-platform', [App\Controllers\Api\CollectionApiController::class, 'exportByPlatformXlsx']);

// $router->get('/wishlist',    [App\Controllers\WishlistController::class, 'index']);
// $router->get('/agenda',      [App\Controllers\AgendaController::class, 'index']);
// $router->get('/user/{slug}', [App\Controllers\UserController::class, 'show']);
// $router->get('/barcode',     [App\Controllers\BarcodeController::class, 'index']);
$router->post('/api/wishlist/toggle',        [App\Controllers\Api\WishlistController::class, 'toggle']);
$router->post('/api/reviews/add',            [App\Controllers\Api\ReviewController::class, 'add']);
$router->get('/api/user/profile',            [App\Controllers\Api\UserApiController::class, 'profile']);
// $router->post('/api/barcode/lookup',         [App\Controllers\Api\BarcodeController::class, 'lookup']);
