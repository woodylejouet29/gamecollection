<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\ImageConverter;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

/**
 * Script de vérification de la qualité et taille des images WebP générées.
 * 
 * Usage : php scripts/check_webp_quality.php
 * 
 * Vérifie :
 * 1. La qualité configurée (IGDB_WEBP_QUALITY)
 * 2. La taille moyenne des fichiers WebP dans le dossier de stockage
 * 3. Compare avec la taille des originaux si disponibles
 * 4. Fournit des recommandations d'optimisation
 */

echo "=== Audit WebP Pipeline ===\n\n";

// Vérifier la qualité configurée
$quality = (int)($_ENV['IGDB_WEBP_QUALITY'] ?? 80);
echo "1. Qualité WebP configurée : {$quality}/100\n";

if ($quality > 85) {
    echo "   ⚠️  Qualité élevée (>85). Recommandation : 75-85 pour un bon équilibre qualité/taille.\n";
} elseif ($quality < 60) {
    echo "   ⚠️  Qualité basse (<60). Peut affecter la qualité visuelle.\n";
} else {
    echo "   ✅ Qualité bien configurée.\n";
}

// Créer une instance d'ImageConverter pour obtenir le chemin de stockage
$converter = new ImageConverter();
$storageDir = $converter->getStorageDir();
$target = $converter->getTarget();

echo "\n2. Stockage cible : {$target}\n";
echo "   Chemin local : {$storageDir}\n";

// Si le stockage est local, analyser les fichiers
if ($target === 'local' && is_dir($storageDir)) {
    $files = [];
    $totalSize = 0;
    $count = 0;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'webp') {
            $size = $file->getSize();
            $files[] = [
                'path' => $file->getPathname(),
                'size' => $size,
                'name' => $file->getFilename()
            ];
            $totalSize += $size;
            $count++;
        }
    }
    
    if ($count > 0) {
        $avgSize = $totalSize / $count;
        $avgSizeKB = round($avgSize / 1024, 2);
        $totalSizeMB = round($totalSize / (1024 * 1024), 2);
        
        echo "\n3. Analyse des fichiers WebP :\n";
        echo "   Nombre de fichiers : {$count}\n";
        echo "   Taille totale : {$totalSizeMB} MB\n";
        echo "   Taille moyenne : {$avgSizeKB} KB\n";
        
        // Analyser la distribution des tailles
        $sizes = array_column($files, 'size');
        $minSize = min($sizes);
        $maxSize = max($sizes);
        $minSizeKB = round($minSize / 1024, 2);
        $maxSizeKB = round($maxSize / 1024, 2);
        
        echo "   Taille min : {$minSizeKB} KB\n";
        echo "   Taille max : {$maxSizeKB} KB\n";
        
        // Recommandations basées sur les tailles
        if ($avgSizeKB > 150) {
            echo "   ⚠️  Taille moyenne élevée (>150 KB). Considérez :\n";
            echo "      - Réduire la qualité à 75-80\n";
            echo "      - Implémenter le redimensionnement des images grandes\n";
        } elseif ($avgSizeKB < 20) {
            echo "   ℹ️  Taille moyenne très basse (<20 KB). Vérifiez la qualité visuelle.\n";
        } else {
            echo "   ✅ Tailles bien optimisées.\n";
        }
        
        // Vérifier les fichiers anormalement grands
        $largeFiles = array_filter($files, fn($f) => $f['size'] > 500 * 1024); // >500KB
        if (count($largeFiles) > 0) {
            echo "\n4. Fichiers anormalement grands (>500 KB) :\n";
            foreach ($largeFiles as $file) {
                $sizeKB = round($file['size'] / 1024, 2);
                echo "   - {$file['name']} : {$sizeKB} KB\n";
            }
            echo "   Recommandation : Implémenter un redimensionnement pour les images >1MP\n";
        }
    } else {
        echo "\n3. Aucun fichier WebP trouvé dans le dossier de stockage.\n";
    }
} elseif ($target === 'supabase') {
    echo "\n3. Stockage Supabase activé.\n";
    echo "   ⓘ  Les fichiers sont stockés dans le cloud. Pour analyser les tailles,\n";
    echo "      consultez les logs de conversion ou le dashboard Supabase.\n";
}

// Vérifier les extensions disponibles
echo "\n5. Extensions de traitement d'image :\n";
if (extension_loaded('imagick')) {
    $imagick = new \Imagick();
    $formats = $imagick->queryFormats('WEBP');
    echo "   ✅ Imagick disponible" . (in_array('WEBP', $formats) ? " (support WebP)" : " (WebP non supporté)") . "\n";
} else {
    echo "   ❌ Imagick non disponible\n";
}

if (extension_loaded('gd')) {
    $gdInfo = gd_info();
    echo "   ✅ GD disponible" . (!empty($gdInfo['WebP Support']) ? " (support WebP)" : " (WebP non supporté)") . "\n";
} else {
    echo "   ❌ GD non disponible\n";
}

// Recommandations générales
echo "\n6. Recommandations d'optimisation :\n";
echo "   a. Qualité : Maintenir entre 75-85 pour un bon équilibre\n";
echo "   b. Redimensionnement : Ajouter un redimensionnement pour les images >1920px\n";
echo "   c. Formats alternatifs : Considérer AVIF pour une meilleure compression (nécessite Imagick 7+)\n";
echo "   d. Lazy loading : Vérifier que toutes les images ont loading=\"lazy\"\n";
echo "   e. Responsive images : Implémenter srcset pour différentes résolutions\n";

echo "\n=== Audit terminé ===\n";