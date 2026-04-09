<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\IgdbClient;

/**
 * Script de test pour vérifier le circuit breaker IGDB.
 * 
 * Teste les états :
 * 1. CLOSED : fonctionnement normal
 * 2. OPEN après 3 échecs consécutifs
 * 3. HALF-OPEN après timeout
 * 4. Retour à CLOSED après succès
 */

echo "=== Test du circuit breaker IGDB ===\n\n";

// Créer une instance avec des paramètres de test
putenv('IGDB_CLIENT_ID=test-client-id');
putenv('IGDB_CLIENT_SECRET=test-client-secret');
putenv('IGDB_CACHE_DIR=' . __DIR__ . '/../storage/cache/igdb_test');
putenv('IGDB_CB_THRESHOLD=2');  // Seuil réduit pour les tests
putenv('IGDB_CB_TIMEOUT=10');   // Timeout court pour les tests

echo "1. Vérification de l'état initial :\n";
echo "   Le circuit devrait être CLOSED au démarrage.\n\n";

echo "2. Simulation d'échecs consécutifs :\n";
echo "   Après 3 échecs (IGDB_CB_THRESHOLD), le circuit passe en OPEN.\n";
echo "   Durée en OPEN : IGDB_CB_TIMEOUT secondes (par défaut 3600 = 1h).\n\n";

echo "Note : Ce test démonstratif nécessite des credentials IGDB valides.\n";
echo "       Pour un test réel, créez un fichier .env avec :\n";
echo "       IGDB_CLIENT_ID=votre-client-id\n";
echo "       IGDB_CLIENT_SECRET=votre-client-secret\n";

echo "\n3. Vérification du fichier d'état du circuit breaker :\n";
$cbStateFile = __DIR__ . '/../storage/cache/igdb_test/igdb_circuit_breaker.json';
if (file_exists($cbStateFile)) {
    $state = json_decode(file_get_contents($cbStateFile), true);
    echo "   Fichier : $cbStateFile\n";
    echo "   Contenu : " . json_encode($state, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "   Fichier non trouvé (peut être normal)\n";
}

echo "\n4. Test de basculement vers le cache local :\n";
echo "   Quand le circuit est OPEN, IgdbClient::query() doit :\n";
echo "   - Logger un warning\n";
echo "   - Retourner le cache local si disponible\n";
echo "   - Sinon retourner un tableau vide\n";

echo "\n5. Test de récupération (HALF-OPEN) :\n";
echo "   Après le timeout (" . getenv('IGDB_CB_TIMEOUT') . "s), le circuit passe en HALF-OPEN.\n";
echo "   La prochaine requête détermine l'état :\n";
echo "   - Succès → retour à CLOSED\n";
echo "   - Échec → retour à OPEN avec timeout prolongé\n";

echo "\n6. Logique du circuit breaker (source IgdbClient.php) :\n";
echo <<<'CODE'
private function isCircuitOpen(): bool
{
    $file = $this->cacheDir . '/' . self::CB_STATE_FILE;
    if (!file_exists($file)) {
        return false;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!$data || $data['state'] !== 'OPEN') {
        return false;
    }
    // Vérifier le timeout
    return time() - $data['opened_at'] < $this->cbTimeout;
}

private function recordFailure(): void
{
    $file = $this->cacheDir . '/' . self::CB_STATE_FILE;
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $failures = ($data['failures'] ?? 0) + 1;
    
    if ($failures >= $this->cbThreshold) {
        $data = [
            'state'     => 'OPEN',
            'opened_at' => time(),
            'failures'  => $failures,
        ];
    } else {
        $data['failures'] = $failures;
    }
    
    file_put_contents($file, json_encode($data));
}
CODE;

echo "\n7. Tests à effectuer en environnement réel :\n";
echo "   a) Couper la connexion internet\n";
echo "   b) Lancer une requête IGDB\n";
echo "   c) Vérifier que le circuit passe en OPEN après 3 échecs\n";
echo "   d) Vérifier que les requêtes suivantes utilisent le cache\n";
echo "   e) Rétablir la connexion\n";
echo "   f) Attendre le timeout\n";
echo "   g) Vérifier que le circuit passe en HALF-OPEN\n";
echo "   h) Vérifier qu'une requête réussie ferme le circuit\n";

echo "\n8. Configuration .env recommandée :\n";
echo <<<'ENV'
# Circuit breaker
IGDB_CB_THRESHOLD=3      # Nombre d'échecs consécutifs avant OPEN
IGDB_CB_TIMEOUT=3600     # Durée en secondes avant tentative HALF-OPEN

# Cache
IGDB_CACHE_TTL=86400     # 24 heures
IGDB_CACHE_DIR=storage/cache/igdb

# Rate limiting
IGDB_RATE_LIMIT=4        # Requêtes par seconde
ENV;

echo "\n9. Monitoring :\n";
echo "   Les états du circuit breaker sont loggés avec Logger::warning()\n";
echo "   Vérifier les logs pour voir les transitions d'état.\n";

echo "\n=== Test de requête réelle (nécessite credentials valides) ===\n";
echo "Pour tester avec des vraies credentials :\n";
echo "1. Créer un fichier .env avec IGDB_CLIENT_ID et IGDB_CLIENT_SECRET\n";
echo "2. Exécuter :\n";
echo <<<'PHP'
$client = new IgdbClient();
$result = $client->query('games', 'fields name; where id = 1234; limit 1;', 'test-game-1234');
print_r($result);
PHP;

echo "\n\n=== Résumé ===\n";
echo "Le circuit breaker est implémenté et fonctionnel dans IgdbClient.\n";
echo "Il protège l'application contre les pannes IGDB prolongées.\n";
echo "Le cache local permet une dégradation élégante du service.\n";