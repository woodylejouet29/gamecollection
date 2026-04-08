<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\IgdbClient;

(Dotenv::createImmutable(BASE_PATH))->safeLoad();

$igdb = new IgdbClient();

$where = $argv[1] ?? '';
$lines = [
    'fields id, game.id, games.id, features.title, features.description;',
    $where !== '' ? "where {$where};" : null,
    'limit 5;',
    'sort id asc;',
];
$body = implode("\n", array_values(array_filter($lines, fn($l) => $l !== null && $l !== '')));

$r = $igdb->query('game_versions', $body, 'debug/game_versions_min');

echo "count=" . count($r) . PHP_EOL;
if ($r) {
    echo json_encode($r[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

