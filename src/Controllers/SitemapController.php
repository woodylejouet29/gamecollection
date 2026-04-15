<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;

class SitemapController
{
    public function index(): void
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        
        // Définir le type de contenu comme XML
        header('Content-Type: application/xml; charset=utf-8');
        
        // Générer le sitemap dynamiquement
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        // Pages statiques
        $this->addUrl($appUrl . '/', 'daily', 1.0);
        $this->addUrl($appUrl . '/search', 'weekly', 0.8);
        $this->addUrl($appUrl . '/register', 'monthly', 0.3);
        $this->addUrl($appUrl . '/login', 'monthly', 0.3);
        
        // Pages nécessitant une connexion (priorité plus basse)
        if (isset($_SESSION['user_id'])) {
            $this->addUrl($appUrl . '/collection', 'weekly', 0.7);
            $this->addUrl($appUrl . '/select', 'weekly', 0.6);
            $this->addUrl($appUrl . '/edit-profile', 'monthly', 0.4);
        }
        
        // Pages de jeux depuis la base de données
        $this->addGamePages($appUrl);
        
        echo '</urlset>';
        exit;
    }
    
    private function addUrl(string $loc, string $changefreq, float $priority): void
    {
        echo '<url>';
        echo '<loc>' . htmlspecialchars($loc) . '</loc>';
        echo '<changefreq>' . htmlspecialchars($changefreq) . '</changefreq>';
        echo '<priority>' . number_format($priority, 1) . '</priority>';
        echo '</url>';
    }
    
    private function addGamePages(string $appUrl): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Récupérer les jeux actifs avec slug
            $stmt = $db->prepare("
                SELECT slug, updated_at 
                FROM games 
                WHERE slug IS NOT NULL 
                AND slug != '' 
                ORDER BY id DESC 
                LIMIT 10000
            ");
            $stmt->execute();
            $games = $stmt->fetchAll();
            
            foreach ($games as $game) {
                $this->addUrl(
                    $appUrl . '/game/' . htmlspecialchars($game['slug']),
                    'weekly',
                    0.6
                );
            }
            
        } catch (\Exception $e) {
            // En cas d'erreur, on continue sans les pages de jeux
            error_log('Erreur lors de la génération du sitemap pour les jeux: ' . $e->getMessage());
        }
    }
    
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /vendor/\n";
        echo "Disallow: /src/\n";
        echo "Disallow: /views/\n";
        echo "Disallow: /database/\n";
        echo "Disallow: /routes/\n";
        echo "Disallow: /storage/\n";
        echo "\n";
        echo "Sitemap: {$appUrl}/sitemap.xml\n";
        
        exit;
    }
}