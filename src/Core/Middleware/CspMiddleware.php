<?php

declare(strict_types=1);

namespace App\Core\Middleware;

class CspMiddleware
{
    public function handle(): void
    {
        // Ne définir CSP qu'en production ou si explicitement activé
        $env = $_ENV['APP_ENV'] ?? 'development';
        $enableCsp = ($_ENV['ENABLE_CSP'] ?? 'false') === 'true';
        
        // En développement, utiliser une CSP plus permissive ou désactivée
        if ($env !== 'production' && !$enableCsp) {
            // Optionnel : ajouter un en-tête CSP permissif pour le développement
            // header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: http:;");
            return;
        }

        // Politique de sécurité du contenu pour la production
        $csp = [
            // Sources par défaut
            "default-src 'self'",
            
            // Scripts - ajouter 'unsafe-inline' pour Turnstile et autres scripts inline
            "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://www.google-analytics.com",
            
            // Styles
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            
            // Fonts
            "font-src 'self' https://fonts.gstatic.com",
            
            // Images
            "img-src 'self' data: blob: https: http:",
            
            // Connexions
            "connect-src 'self' https://challenges.cloudflare.com https://www.google-analytics.com",
            
            // Cadres (frames)
            "frame-src https://challenges.cloudflare.com",
            
            // Objets
            "object-src 'none'",
            
            // Media
            "media-src 'self'",
            
            // Manifeste
            "manifest-src 'self'",
            
            // Form actions
            "form-action 'self'",
            
            // Base URI
            "base-uri 'self'",
            
            // Sandbox (optionnel)
            // "sandbox allow-forms allow-same-origin allow-scripts",
        ];

        header("Content-Security-Policy: " . implode('; ', $csp));
        
        // En-têtes de sécurité supplémentaires
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        // Permissions Policy
        $permissions = [
            "camera=()",
            "microphone=()", 
            "geolocation=()",
            "payment=()",
            "usb=()",
            "xr-spatial-tracking=()"
        ];
        header("Permissions-Policy: " . implode(', ', $permissions));
    }
}