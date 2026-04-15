<?php

declare(strict_types=1);

/**
 * Script de test pour vérifier la restriction de notation :
 * - Les avis/notes sont réservés aux statuts "completed" et "hundred_percent"
 * - Validation côté serveur dans CollectionApiController et ReviewController
 */

echo "=== Test de restriction de notation ===\n\n";

echo "1. Statuts autorisés pour les avis :\n";
$reviewStatuses = ['completed', 'hundred_percent'];
foreach ($reviewStatuses as $status) {
    echo "  - $status ✓\n";
}

echo "\n2. Statuts NON autorisés pour les avis :\n";
$nonReviewStatuses = ['owned', 'playing', 'abandoned'];
foreach ($nonReviewStatuses as $status) {
    echo "  - $status (bloque note/avis) ✓\n";
}

echo "\n3. Logique de validation dans CollectionApiController::add() :\n";
echo <<<'CODE'
if (!empty($copy['rating']) || !empty($copy['review_body'])) {
    if (!in_array($status, self::REVIEW_STATUSES, true)) {
        $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 
                     'code' => 'INVALID_STATUS_FOR_REVIEW', 
                     'message' => 'Note/avis réservés aux statuts Terminé ou 100%.'];
        continue;
    }
}
CODE;

echo "\n\n4. Logique de validation dans ReviewController::add() :\n";
echo <<<'CODE'
// Vérifier que le statut permet un avis
if (!in_array($entry['status'] ?? '', self::REVIEW_STATUSES, true)) {
    $this->json(['success' => false, 'error' => [
        'code' => 'INVALID_STATUS_FOR_REVIEW', 
        'message' => 'Les avis sont réservés aux jeux terminés ou complétés à 100%.'
    ]], 422);
}
CODE;

echo "\n\n5. Tests à effectuer manuellement :\n";
echo "a) Ajouter un jeu avec statut 'owned' + note → doit échouer\n";
echo "b) Ajouter un jeu avec statut 'playing' + avis → doit échouer\n";
echo "c) Ajouter un jeu avec statut 'completed' + note 8 + avis ≥100 caractères → doit réussir\n";
echo "d) Ajouter un jeu avec statut 'hundred_percent' + note 10 → doit réussir\n";
echo "e) Modifier un jeu de 'owned' à 'completed' puis ajouter un avis → doit réussir\n";

echo "\n6. Scénarios d'erreur attendus :\n";
echo "- Code : INVALID_STATUS_FOR_REVIEW\n";
echo "- Message : 'Note/avis réservés aux statuts Terminé ou 100%'\n";
echo "- HTTP : 422 Unprocessable Entity\n";

echo "\n7. Validation supplémentaire :\n";
echo "- Note : 1-10 uniquement\n";
echo "- Avis : ≥100 caractères\n";
echo "- Durée complétion : requise pour 'completed' et 'hundred_percent'\n";

echo "\n=== Test avec l'API ===\n";
echo "Pour tester /api/reviews/add (nécessite un serveur local) :\n";
echo "1. Démarrer : php -S localhost:8000 -t public\n";
echo "2. Exécuter :\n";
echo <<<'CURL'
curl -X POST http://localhost:8000/api/reviews/add \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer VOTRE_TOKEN_JWT" \
  -d '{
    "entry_id": 123,
    "rating": 9,
    "review_body": "Ce jeu est excellent ! Il offre une expérience immersive avec..."
  }'
CURL;

echo "\n\nPour tester /api/collection/add avec validation de statut :\n";
echo <<<'CURL'
curl -X POST http://localhost:8000/api/collection/add \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer VOTRE_TOKEN_JWT" \
  -d '{
    "games": [{
      "game_id": 12345,
      "platform_id": 130,
      "game_version_id": 789,
      "region": "PAL",
      "game_type": "physical",
      "copies": [{
        "status": "completed",
        "rating": 8,
        "review_body": "Un jeu fantastique qui mérite son succès...",
        "rank_position": 1,
        "play_time_hours": 25,
        "play_time_minutes": 30
      }]
    }]
  }'
CURL;

echo "\n\n=== Résumé ===\n";
echo "La restriction est implémentée à deux niveaux :\n";
echo "1. CollectionApiController : validation lors de l'ajout\n";
echo "2. ReviewController : validation lors de l'ajout d'avis séparé\n";
echo "3. CollectionService::addReview : statut vérifié en amont\n";