**DOCUMENTATION TECHNIQUE**

Site de Collection de Jeux Vidéo

Cahier des charges complet - Version 2.0

**Stack technique**

| **Frontend**      | PHP 8.2+ / CSS externalisé / JS (zéro inline, cache-busting) |
| ----------------- | ------------------------------------------------------------ |
| **Serveur**       | XAMPP / Apache + FastRoute ou AltoRouter                     |
| ---               | ---                                                          |
| **Backend & BDD** | Supabase (PostgreSQL, Auth, Stockage)                        |
| ---               | ---                                                          |
| **API Jeux**      | IGDB (jeux, plateformes, accessoires, régions, DLC)          |
| ---               | ---                                                          |
| **Sécurité**      | Cloudflare Turnstile + RLS Supabase + Rate Limiting          |
| ---               | ---                                                          |
| **Images**        | Conversion automatique en WebP + Lazy Loading                |
| ---               | ---                                                          |
| **Cache**         | Cache serveur agressif + Cron Jobs 24h                       |
| ---               | ---                                                          |
| **SEO**           | Meta, Open Graph, slugs, sitemap.xml dynamique, robots.txt   |
| ---               | ---                                                          |
| **Police**        | Inter (corps) + Police display pour titres                   |
| ---               | ---                                                          |
| **Thème**         | Clair / Sombre, dégradés violets                             |
| ---               | ---                                                          |
| **Mobile**        | Mobile-first, PWA-ready, convertible Android/iOS             |
| ---               | ---                                                          |
| **Logging**       | Système de logging centralisé erreurs/performance            |
| ---               | ---                                                          |

# **1\. Arborescence & Routes**

Le routeur PHP gère toutes les URLs en slugs SEO-friendly. Chaque page dispose d'un fichier CSS dédié.

| **Route**     | **Fichier PHP**  | **Accès**    | **Description**                     |
| ------------- | ---------------- | ------------ | ----------------------------------- |
| /             | index.php        | Public       | Page d'accueil avec widgets         |
| ---           | ---              | ---          | ---                                 |
| /register     | register.php     | Non connecté | Inscription utilisateur             |
| ---           | ---              | ---          | ---                                 |
| /login        | login.php        | Non connecté | Connexion utilisateur               |
| ---           | ---              | ---          | ---                                 |
| /logout       | logout.php       | Connecté     | Déconnexion                         |
| ---           | ---              | ---          | ---                                 |
| /edit-profile | edit-profile.php | Connecté     | Modification du profil              |
| ---           | ---              | ---          | ---                                 |
| /search       | search.php       | Public       | Recherche de jeux                   |
| ---           | ---              | ---          | ---                                 |
| /select       | select.php       | Connecté     | Liste de sélection (panier)         |
| ---           | ---              | ---          | ---                                 |
| /collection   | collection.php   | Connecté     | Collection personnelle complète     |
| ---           | ---              | ---          | ---                                 |
| /wishlist     | wishlist.php     | Connecté     | Liste de jeux attendus              |
| ---           | ---              | ---          | ---                                 |
| /agenda       | agenda.php       | Public       | Agenda des sorties depuis jan. 2026 |
| ---           | ---              | ---          | ---                                 |
| /game/:slug   | game.php         | Public       | Fiche détaillée d'un jeu            |
| ---           | ---              | ---          | ---                                 |
| /user/:slug   | user.php         | Public       | Profil public d'un utilisateur      |
| ---           | ---              | ---          | ---                                 |
| /barcode      | barcode.php      | Connecté     | Scan de code-barres                 |
| ---           | ---              | ---          | ---                                 |
| /sitemap.xml  | sitemap.php      | Public       | Sitemap dynamique SEO               |
| ---           | ---              | ---          | ---                                 |
| /robots.txt   | robots.php       | Public       | Instructions crawlers               |
| ---           | ---              | ---          | ---                                 |

# **2\. Header / Navigation globale**

Le header est présent sur toutes les pages. Il est fixe (sticky) en haut de l'écran.

- Logo + nom du site (lien vers /)
- Barre de navigation horizontale principale
- État non connecté : bouton Inscription + bouton Connexion à droite
- État connecté :
  - Icône de collection avec compteur de jeux en attente de validation
  - Clic sur l'icône → redirige vers /select
  - Bouton Déconnexion
- Toggle thème Clair / Sombre (persisté en cookie)

# **3\. Pages - Description détaillée**

### **3.1 Page d'accueil /**

- Bandeau conversion (non connecté) : phrase accrocheuse + CTA vers /register
- Bannière collection (connecté) : incitation à démarrer + lien vers /search
- Widgets dynamiques : jeux les plus attendus, dernières sorties, derniers avis, jeux tendance par plateforme, jeux populaires par genre, statistiques globales (membres, jeux catalogués, avis, top 10 jeux les plus collectionnés)

### **3.2 Inscription /register**

- Pseudo (unique, validé serveur), email (unique), mot de passe (min 8 car., chiffre + spécial obligatoires), confirmation
- Upload ou URL d'avatar
- Sélection des plateformes possédées (données IGDB)
- Sélection des accessoires possédés (données IGDB)
- Sélection des genres préférés (données IGDB)
- Cloudflare Turnstile + rate limiting sur soumission

### **3.3 Connexion /login**

- Email + mot de passe
- Case 'Rester connecté' : 30 jours si cochée, 7 jours sinon
- Cloudflare Turnstile + rate limiting
- Lien vers /register et récupération de mot de passe

### **3.4 Modification du profil /edit-profile**

**Onglet Profil**

- Modification pseudo, email, mot de passe (avec confirmation), avatar
- Rate limiting sur chaque action sensible

**Onglet Préférences**

- Mise à jour des plateformes possédées, accessoires possédés, genres préférés

**Onglet Paramètres**

- Visibilité de la collection (Publique / Privée - défaut : Privée)
- Autres paramètres à définir ultérieurement

### **3.5 Recherche de jeux /search**

**Barre de recherche**

- Pleine largeur, prévisualisation live (debounce 300 ms recommandé)

**Filtres (colonne gauche)**

- Date de sortie (plage), note (min/max), plateforme, genre, région (NA, EUR, JAP…), type (Physique / Dématérialisé)

**Résultats (zone droite)**

- Toggle 3 vues : Grille / Liste / Cartes
- Clic sur un jeu → pop-up choix de la version du jeu
- Jeu sélectionné → ajouté à la liste de sélection (/select)

**Doublon** Si la combinaison user_id + game_id + platform_id + version_id + region + type existe déjà dans la liste de sélection ou en collection, l'ajout est refusé avec un message d'erreur explicite.

- Pagination optimisée (infinite scroll ou classique selon performances)

**Barre sticky**

- Compteur de jeux sélectionnés + bouton d'accès rapide à /select

### **3.6 Liste de sélection /select**

_Page de saisie des informations pour chaque jeu avant enregistrement en collection. Accessible depuis le header ou la page de recherche._

**Structure générale**

- Liste de jeux en attente, un formulaire par jeu
- Choix obligatoire : Physique ou Dématérialisé
- Choix de la région du jeu (NA, EUR, JAP…) - fait partie de la clé d'unicité
- Choix du nombre d'exemplaires possédés (obligatoire, entier ≥ 1)
- Si plusieurs exemplaires : le formulaire duplique dynamiquement les sections de saisie pour permettre l'enregistrement séparé de chaque exemplaire
- Chaque exemplaire validé crée une entrée distincte dans collection_entries
- Pagination si plusieurs jeux

**Unicité** Une entrée collection_entries est unique selon la combinaison : user_id + game_id + platform_id + version_id + region + type. version_id est facultatif (null autorisé si version non précisée). Deux régions différentes = deux entrées distinctes. Un utilisateur peut posséder simultanément une version physique et dématérialisée du même jeu.

**Formulaire - Version Dématérialisée**

- Date d'acquisition (facultatif, calendrier pop-up)
- Prix d'achat (facultatif)
- Liste personnelle de classement (obligatoire, voir section 6)
- Statut de progression (obligatoire) : Testé / Non commencé / Commencé mais en pause / Abandonné / Terminé / Terminé à 100%
- Durée pour terminer le jeu (facultatif, si statut = Terminé ou Terminé à 100%)

**Note & avis** La note sur 10 et l'avis textuel (min 100 car.) ne sont accessibles que si le statut est Terminé ou Terminé à 100%. Dans tous les autres cas, ces champs sont masqués et interdits.

**Formulaire - Version Physique**

- État du jeu (obligatoire) : Neuf sous blister / Neuf / Excellent / Bon / Correct / Mauvais / Très mauvais / Ne fonctionne pas
- Commentaire sur l'état (facultatif, max 500 caractères)
- Boîte d'origine : Oui / Non (obligatoire)
- Manuel : Présent / Absent (obligatoire)
- Date d'acquisition (facultatif, calendrier pop-up)
- Prix d'achat (facultatif)
- Liste personnelle (obligatoire)
- Statut de progression (obligatoire, mêmes options que démat)
- Durée pour terminer (facultatif, si Terminé ou Terminé à 100%)

**Note & avis** Identique à la version dématérialisée : note et avis réservés aux statuts Terminé / Terminé à 100%.

- Jusqu'à 3 photos du jeu physique (URL ou upload) par exemplaire

**Validation**

- Chaque exemplaire validé = 1 ligne collection_entries créée en base
- Le retrait de la wishlist n'intervient qu'après validation finale et création réussie de toutes les entrées en collection
- Redirection vers /collection après validation

### **3.7 Fiche du jeu /game/:slug**

**Bannière**

- Image de fond full-width en mode blur/parallax
- Jaquette du jeu superposée à gauche + titre à droite + badges plateformes disponibles

**Navigation entre versions**

- Liens vers les différentes versions du même jeu (GOTY, Deluxe, etc.)
- Liens vers les DLC associés

**Actions utilisateur**

- Bouton 'Ajouter à ma collection' → pop-up choix de plateforme → envoi vers /select
- Bouton 'J'attends' (wishlist) avec compteur de clics global

**Données IGDB affichées**

- Note globale IGDB, date de sortie par région, synopsis, développeur, éditeur, genres, galerie images, vidéos, accessoires compatibles

**Section avis utilisateurs**

- Liste des avis et notes de la communauté (uniquement statuts Terminé / 100%)
- Note moyenne calculée depuis les avis du site

### **3.8 Wishlist /wishlist (V2)**

- Jeux triés par date de sortie (la plus proche en premier)
- Retrait manuel depuis cette page
- Retrait automatique après création réussie d'une entrée collection - pas avant
- Filtres universels + toggle vues + pagination

### **3.9 Agenda des sorties /agenda (V2)**

- Jeux depuis janvier 2026 uniquement
- Bouton 'J'attends' sur chaque jeu + clic → fiche /game/:slug
- Filtres universels + toggle vues + pagination

### **3.10 Collection personnelle /collection**

- Filtres avancés : plateforme, genre, statut, état physique, région, note
- Tri : alphabétique, date d'acquisition, note, valeur estimée
- Toggle vues : Grille / Liste / Cartes
- Statistiques : nombre de jeux, répartition physique/démat, valeur totale estimée, temps de jeu total
- Export de la collection (CSV / JSON)
- Listes personnelles : navigation entre les listes fixes
- Modification rapide d'une entrée (statut, note, état)

### **3.11 Profil public /user/:slug (V2)**

- Avatar, pseudo, bio (si renseignée), plateformes possédées, genres préférés
- Collection si rendue publique par l'utilisateur
- Derniers avis rédigés + statistiques : nombre de jeux, répartition, plateformes

### **3.12 Scan code-barres /barcode (V2)**

- Modes : caméra mobile (navigator.mediaDevices), upload photo, URL de photo
- Support multi-codes sur une seule photo (8+ codes simultanés)
- Détection EAN/UPC → appel API tierce (UPC Item DB ou équivalent) → correspondance IGDB via titre
- Pré-remplissage automatique du formulaire dans /select

IGDB ne dispose pas de base EAN/UPC native. Le couplage avec une API tierce est obligatoire et doit être anticipé dans l'architecture.

# **4\. Modules transversaux**

## **4.1 Système de filtres universels**

Identique sur les pages Recherche, Wishlist et Agenda.

- Date de sortie (plage), note (min/max), plateforme, genre, région (NA, EUR, JAP…), type (Physique / Dématérialisé)

## **4.2 Toggle de vue**

- Grille : jaquettes en mosaïque
- Liste : rangées avec infos résumées
- Cartes : affichage enrichi
- Préférence mémorisée par cookie

## **4.3 Barre sticky de sélection**

- Affichée sur /search, visible en permanence lors de la sélection
- Compteur en temps réel + bouton d'accès rapide vers /select

## **4.4 Système de pagination**

- Pagination classique ou infinite scroll selon les performances mesurées
- Paramètre de page en GET pour URLs partageables et indexables
- Cache serveur activé sur les résultats paginés

## **4.5 Thème clair / sombre**

- Bascule depuis le header, dégradés violets, persisté en cookie (sans flash)

# **5\. Intégration API IGDB**

## **5.1 Données récupérées**

| **Catégorie** | **Champs récupérés**                                                                                       |
| ------------- | ---------------------------------------------------------------------------------------------------------- |
| Jeux          | Titre, synopsis, genres, plateformes, date de sortie par région, note, versions, DLC, développeur, éditeur |
| ---           | ---                                                                                                        |
| Plateformes   | Nom, logo, génération, fabricant                                                                           |
| ---           | ---                                                                                                        |
| Versions      | Edition GOTY, Deluxe, Standard, etc.                                                                       |
| ---           | ---                                                                                                        |
| Régions       | NA, EUR, JAP, WW, etc.                                                                                     |
| ---           | ---                                                                                                        |
| Accessoires   | Périphériques, manettes, adaptateurs                                                                       |
| ---           | ---                                                                                                        |
| Médias        | Jaquettes, screenshots, trailers, vidéos gameplay                                                          |
| ---           | ---                                                                                                        |

## **5.2 Stratégie de cache et synchronisation**

- Cron Job toutes les 24h pour synchroniser les données IGDB avec la base Supabase
- Cache serveur agressif sur tous les appels IGDB (TTL configurable)
- Les données sont stockées localement en base pour limiter les appels API
- Rate limiting côté serveur sur les appels IGDB pour respecter les quotas
- Toutes les images IGDB converties en WebP et stockées localement

**Séparation stricte** Les données synchronisées depuis IGDB alimentent uniquement les tables catalogue (games, platforms, game_versions, game_platforms). Elles ne doivent jamais écraser les données personnelles stockées dans collection_entries.

## **5.3 Plan de continuité IGDB**

En cas d'indisponibilité de l'API IGDB, le site doit rester fonctionnel grâce aux mesures suivantes :

- Toutes les données critiques (titres, jaquettes, plateformes) sont synchronisées et stockées localement en base Supabase
- Les jaquettes et images sont converties en WebP et servies localement - aucune dépendance aux CDN IGDB en production
- En cas d'échec du Cron Job de synchronisation : alerte de logging centralisé, dernier état connu conservé, retry automatique toutes les heures pendant 24h
- Les formulaires de /select et /collection restent accessibles et fonctionnels sans appel IGDB en temps réel
- Circuit breaker sur les appels IGDB en temps réel : si l'API échoue 3 fois consécutives, basculement automatique sur le cache local sans dégradation visible
- Tableau de bord d'état IGDB visible côté admin pour monitorer la disponibilité

## **5.4 Nettoyage des données obsolètes**

- Cron Job hebdomadaire : détection des jeux en cache sans aucune entrée collection_entries ni wishlist associée depuis plus de 90 jours
- Suppression automatique des images WebP locales orphelines (non référencées en base)
- Purge des logs de plus de 30 jours (configurable)
- Purge des sessions expirées en base Supabase
- Rapport de nettoyage loggé pour audit

# **6\. Système de collection & listes personnelles**

## **6.1 Règle d'unicité des entrées**

Une entrée collection_entries est unique selon la combinaison : user_id + game_id + platform_id + version_id + region + type. version_id est nullable (null si version non précisée). Deux régions différentes, deux types différents ou deux versions différentes constituent des entrées distinctes.

Exemples de combinaisons valides pour le même jeu et le même utilisateur :

- Physique / EUR / version Standard = 1 entrée
- Dématérialisé / EUR / version Standard = 1 entrée distincte (type différent)
- Physique / JAP / version Standard = 1 entrée distincte (région différente)
- Physique / EUR / version GOTY = 1 entrée distincte (version différente)
- 2 exemplaires physiques / EUR / Standard = 2 entrées distinctes (créées via le champ 'nombre d'exemplaires')

## **6.2 Listes fixes prédéfinies**

| **Liste**      | **Description**                                      |
| -------------- | ---------------------------------------------------- |
| Testé          | L'utilisateur a essayé le jeu brièvement             |
| ---            | ---                                                  |
| Non commencé   | Jeu acquis mais pas encore lancé                     |
| ---            | ---                                                  |
| En cours       | Jeu actuellement joué                                |
| ---            | ---                                                  |
| En pause       | Commencé mais mis de côté temporairement             |
| ---            | ---                                                  |
| Abandonné      | Jeu arrêté sans intention de reprendre               |
| ---            | ---                                                  |
| Terminé        | Histoire principale complétée                        |
| ---            | ---                                                  |
| Terminé à 100% | Jeu complété intégralement (succès, trophées, etc.)  |
| ---            | ---                                                  |
| À acheter      | Jeu non possédé, dans une liste d'envies personnelle |
| ---            | ---                                                  |

## **6.3 Règles de notation**

**Restriction** La note sur 10 et l'avis textuel (minimum 100 caractères) ne sont disponibles que si le statut est Terminé ou Terminé à 100%. Ces champs sont masqués et leur saisie est interdite pour tout autre statut, y côté validation serveur.

## **6.4 Données associées à chaque entrée**

- Type : Physique ou Dématérialisé
- Région (NA, EUR, JAP…) - fait partie de la clé d'unicité
- version_id (FK nullable - null si version non précisée)
- Plateforme (platform_id)
- Statut de progression (liste ci-dessus)
- Liste personnelle de classement
- Date d'acquisition (facultatif), prix d'achat (facultatif)
- Note sur 10 + avis (facultatif, uniquement si Terminé ou Terminé à 100%)
- Durée de complétion (facultatif, si Terminé ou Terminé à 100%)
- Pour versions physiques : état, commentaire état, boîte, manuel, jusqu'à 3 photos

# **7\. Spécifications des API internes**

Le frontend PHP communique avec Supabase via le SDK PHP officiel et via des endpoints PHP intermédiaires pour les opérations complexes (validation, rate limiting, logique métier).

## **7.1 Endpoints PHP internes principaux**

| **Endpoint**                | **Méthode** | **Auth** | **Description**                                                       |
| --------------------------- | ----------- | -------- | --------------------------------------------------------------------- |
| /api/collection/add         | POST        | Oui      | Ajoute une ou plusieurs entrées collection après validation d'unicité |
| ---                         | ---         | ---      | ---                                                                   |
| /api/collection/update      | PATCH       | Oui      | Modifie une entrée collection existante                               |
| ---                         | ---         | ---      | ---                                                                   |
| /api/collection/delete      | DELETE      | Oui      | Supprime une entrée collection                                        |
| ---                         | ---         | ---      | ---                                                                   |
| /api/wishlist/toggle        | POST        | Oui      | Ajoute ou retire un jeu de la wishlist                                |
| ---                         | ---         | ---      | ---                                                                   |
| /api/select/check-duplicate | POST        | Oui      | Vérifie si une combinaison existe déjà                                |
| ---                         | ---         | ---      | ---                                                                   |
| /api/reviews/add            | POST        | Oui      | Soumet un avis (validation statut Terminé obligatoire)                |
| ---                         | ---         | ---      | ---                                                                   |
| /api/games/search           | GET         | Non      | Recherche de jeux avec filtres et pagination                          |
| ---                         | ---         | ---      | ---                                                                   |
| /api/games/:id              | GET         | Non      | Données complètes d'un jeu depuis le cache local                      |
| ---                         | ---         | ---      | ---                                                                   |
| /api/user/profile           | GET         | Non      | Profil public d'un utilisateur                                        |
| ---                         | ---         | ---      | ---                                                                   |
| /api/barcode/lookup         | POST        | Oui      | Résolution d'un code-barres vers un jeu IGDB                          |
| ---                         | ---         | ---      | ---                                                                   |

## **7.2 Format de réponse standard**

Toutes les réponses API suivent un format JSON uniforme :

{ "success": true, "data": { ... } } - en cas de succès

{ "success": false, "error": { "code": "DUPLICATE_ENTRY", "message": "..." } } - en cas d'erreur

## **7.3 Codes d'erreur métier**

| **Code**                  | **Situation**                                                      |
| ------------------------- | ------------------------------------------------------------------ |
| DUPLICATE_ENTRY           | Combinaison user/jeu/plateforme/version/région/type déjà existante |
| ---                       | ---                                                                |
| INVALID_STATUS_FOR_REVIEW | Tentative de noter un jeu dont le statut n'est pas Terminé         |
| ---                       | ---                                                                |
| RATE_LIMITED              | Trop de requêtes en un temps donné                                 |
| ---                       | ---                                                                |
| IGDB_UNAVAILABLE          | API IGDB inaccessible, basculement sur cache local                 |
| ---                       | ---                                                                |
| UNAUTHORIZED              | Action nécessitant une connexion                                   |
| ---                       | ---                                                                |
| VALIDATION_ERROR          | Données soumises invalides (champs manquants, format incorrect)    |
| ---                       | ---                                                                |

# **8\. Sécurité**

## **8.1 Authentification & sessions**

- Auth gérée par Supabase (JWT)
- Sessions : 7 jours par défaut, 30 jours si 'Rester connecté'
- Row Level Security (RLS) Supabase sur toutes les tables
- Données sensibles jamais exposées côté client

## **8.2 Anti-bot & rate limiting**

| **Action protégée**        | **Mécanisme**                           |
| -------------------------- | --------------------------------------- |
| Inscription                | Cloudflare Turnstile + Rate Limiting IP |
| ---                        | ---                                     |
| Connexion                  | Cloudflare Turnstile + Rate Limiting IP |
| ---                        | ---                                     |
| Changement de pseudo       | Rate Limiting (1 fois / heure)          |
| ---                        | ---                                     |
| Changement d'email         | Rate Limiting (1 fois / heure)          |
| ---                        | ---                                     |
| Changement de mot de passe | Rate Limiting (1 fois / heure)          |
| ---                        | ---                                     |
| Appels API IGDB            | Rate Limiting côté serveur + cache      |
| ---                        | ---                                     |
| Upload de fichiers         | Vérification type MIME + taille max     |
| ---                        | ---                                     |

## **8.3 Validation des données**

- Validation côté serveur systématique (PHP) - le front n'est jamais la seule barrière
- Échappement de toutes les sorties HTML (XSS)
- Requêtes Supabase via paramètres préparés (injections SQL impossibles)
- Uploads : vérification du type MIME réel (pas seulement l'extension)
- Conversion automatique des images en WebP (élimination des formats dangereux)
- Validation de la règle d'unicité collection_entries côté serveur avant insertion
- Validation du statut avant autorisation de notation (Terminé / 100% obligatoire)

# **9\. SEO & Performance**

## **9.1 SEO**

- Balises meta (title, description) sur chaque page
- Balises Open Graph (og:title, og:description, og:image) pour le partage social
- URLs en slugs SEO-friendly
- sitemap.xml dynamique généré depuis la base de données
- robots.txt configuré
- Pages de fiche jeu : données structurées JSON-LD (schema.org VideoGame)
- Pagination avec balises rel='next' / rel='prev'

## **9.2 Performance & images**

- CSS externalisé, un fichier par page pour éviter le surpoids
- JS zéro inline, cache-busting via hash de fichier
- Toutes les images converties en WebP automatiquement
- Lazy Loading natif (loading='lazy') sur toutes les images hors viewport
- Lazy Loading JavaScript pour les composants non critiques
- Cache serveur agressif sur les données IGDB
- Mobile-first : les styles mobiles sont la base, desktop est une surcouche
- PWA-ready : manifest.json et service worker

## **9.3 Logging centralisé**

Un système de logging centralisé est mis en place dès le démarrage du projet pour le suivi des erreurs et des performances.

- Logging de toutes les erreurs PHP (niveau ERROR et CRITICAL) avec stack trace et contexte utilisateur
- Logging des temps de réponse des appels IGDB et Supabase (seuil d'alerte configurable)
- Logging des échecs de synchronisation IGDB (Cron Job)
- Logging des tentatives de rate limiting déclenchées
- Logging des erreurs de validation métier (DUPLICATE_ENTRY, INVALID_STATUS_FOR_REVIEW, etc.)
- Logs structurés en JSON pour faciliter l'analyse
- Purge automatique des logs de plus de 30 jours (configurable via Cron Job)
- Intégration possible avec un outil externe (Sentry, Logtail, etc.) en V2

# **10\. Structure de base de données**

Schéma simplifié des principales tables. RLS activé sur chaque table. Les données IGDB n'écrasent jamais les données personnelles de collection_entries.

| **Table**          | **Champs principaux**                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| users              | id (UUID, PK) · slug (unique) · pseudo · email · avatar_url · collection_public (bool, défaut false) · created_at                                                                                                                                                                                                                                                                                                       |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| user_platforms     | user_id (FK → users) · platform_id (IGDB)                                                                                                                                                                                                                                                                                                                                                                               |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| user_genres        | user_id (FK → users) · genre_id (IGDB)                                                                                                                                                                                                                                                                                                                                                                                  |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| games              | id (IGDB ID) · slug · title · synopsis · cover_url (WebP local) · igdb_rating · release_date · developer · publisher · updated_at                                                                                                                                                                                                                                                                                       |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| game_platforms     | game_id · platform_id · region · release_date_region                                                                                                                                                                                                                                                                                                                                                                    |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| game_versions      | id · game_id · name (GOTY, Deluxe…) · igdb_version_id                                                                                                                                                                                                                                                                                                                                                                   |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| collection_entries | id · user_id · game_id · version_id (FK nullable) · platform_id · region · type (physical/digital) · exemplaire_index · condition · box_present · manual_present · acquisition_date · price · rating (null si statut non Terminé) · review (null si statut non Terminé) · status · list_name · completion_time · created_at \[UNIQUE: user_id + game_id + platform_id + version_id + region + type + exemplaire_index\] |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| collection_photos  | id · entry_id (FK → collection_entries) · url (WebP) · created_at                                                                                                                                                                                                                                                                                                                                                       |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| reviews            | id · user_id · game_id · rating (1-10) · body (min 100 chars) · created_at \[Seuls les statuts Terminé / 100% autorisés\]                                                                                                                                                                                                                                                                                               |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| wishlist           | id · user_id · game_id · created_at                                                                                                                                                                                                                                                                                                                                                                                     |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| platforms          | id (IGDB) · name · slug · logo_url (WebP) · generation · manufacturer                                                                                                                                                                                                                                                                                                                                                   |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |
| logs               | id · level · message · context (JSON) · created_at \[Purge auto > 30j\]                                                                                                                                                                                                                                                                                                                                                 |
| ---                | ---                                                                                                                                                                                                                                                                                                                                                                                                                     |

# **11\. Plan d'implémentation - Ordre optimal**

Ce chapitre définit l'ordre de développement recommandé pour minimiser les blocages, valider les fondations avant de construire dessus, et livrer rapidement une version utilisable.

Principe directeur : construire de bas en haut. Jamais de page avant que son infrastructure (BDD, auth, API) soit validée. Tester chaque phase avant de passer à la suivante.

| **Phase** | **Bloc**                      | **Contenu**                                                                                                                                                                                                                                        | **Durée estimée** |
| --------- | ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- |
| **P1**    | Fondations infrastructure     | Configuration XAMPP/Apache, installation FastRoute/AltoRouter, connexion Supabase, création de toutes les tables BDD avec RLS, système de logging centralisé, variables d'environnement et configuration globale, page 404/500 basiques            | 3-5 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P1**    | Authentification              | Pages /register et /login avec Cloudflare Turnstile, système de sessions (7j/30j), middleware d'authentification PHP, rate limiting sur connexion/inscription, /logout, /edit-profile (onglet Profil uniquement)                                   | 3-4 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P2**    | Intégration IGDB & cache      | Connexion API IGDB (client PHP), synchronisation initiale des tables catalogue (games, platforms, game_versions, game_platforms), Cron Job 24h, cache serveur, conversion WebP, circuit breaker IGDB, plan de continuité                           | 4-6 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P2**    | Header & modules transversaux | Header sticky (logo, nav, état auth, compteur /select), toggle thème clair/sombre, CSS global et système de variables, police Inter, un fichier CSS par page                                                                                       | 2-3 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P3**    | Page d'accueil                | Widgets dynamiques (sorties récentes, jeux attendus, derniers avis), bannière non connecté / connecté, statistiques globales du site, SEO meta + Open Graph                                                                                        | 3-4 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P3**    | Recherche & fiche jeu         | Page /search (barre, filtres, toggle vues, pagination, lazy loading images, prévisualisation live), page /game/:slug (bannière, données IGDB, boutons collection/wishlist, JSON-LD), validation doublon à l'ajout                                  | 5-7 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P4**    | Collection - cœur métier      | Page /select avec formulaire physique/démat, gestion des exemplaires multiples, règle d'unicité (validation serveur), restriction notation Terminé/100%, création collection_entries, API interne /api/collection/add, /api/select/check-duplicate | 6-8 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P4**    | Collection personnelle        | Page /collection (filtres avancés, tri, toggle vues, statistiques, export CSV/JSON, modification rapide), onglet Préférences de /edit-profile, wishlist avec logique de retrait post-validation                                                    | 4-5 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P5**    | API internes & robustesse     | Tous les endpoints /api/\* documentés en section 7, codes d'erreur métier standardisés, tests des règles d'unicité, tests de la restriction notation, tests du circuit breaker IGDB, Cron Job nettoyage données obsolètes                          | 4-5 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P5**    | SEO & performance finale      | sitemap.xml dynamique, robots.txt, rel=next/prev, lazy loading complet, cache-busting JS/CSS, optimisation WebP pipeline, audit Lighthouse mobile, PWA manifest + service worker basique                                                           | 3-4 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P6**    | V2 - Pages communauté         | Page /wishlist (filtres, toggle, tri), page /agenda, page /user/:slug (profil public, collection publique, avis), onglet Paramètres /edit-profile (visibilité collection)                                                                          | 5-7 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |
| **P6**    | V2 - Scan code-barres         | Page /barcode, intégration API tierce EAN/UPC, correspondance IGDB via titre, scan caméra mobile, upload photo, multi-codes, pré-remplissage /select                                                                                               | 4-6 jours         |
| ---       | ---                           | ---                                                                                                                                                                                                                                                | ---               |

## **11.1 Matrice de dépendances**

Règles de dépendance strictes à respecter :

- L'auth doit être validée avant toute page connectée (/select, /collection, /edit-profile)
- L'intégration IGDB doit être opérationnelle avant /search, /game/:slug, /agenda
- /select dépend de /search (flux de sélection) et de la BDD collection_entries
- /collection dépend de /select (données créées par ce flux)
- /wishlist dépend de /game/:slug (bouton 'J'attends') et de /collection (retrait post-validation)
- /user/:slug dépend de /collection (visibilité collection publique) et de /reviews
- /barcode dépend de /select (pré-remplissage du formulaire)
- Le Cron Job de nettoyage dépend des tables catalogue et logs existantes (Phase P5)

## **11.2 Jalons de validation par phase**

| **Phase** | **Critère de validation avant de passer à la suite**                                                             |
| --------- | ---------------------------------------------------------------------------------------------------------------- |
| P1        | Un utilisateur peut s'inscrire, se connecter, modifier son profil et se déconnecter. Le logging fonctionne.      |
| ---       | ---                                                                                                              |
| P2        | Les données IGDB sont synchronisées en base. Le cache est opérationnel. Le circuit breaker fonctionne.           |
| ---       | ---                                                                                                              |
| P3        | La page d'accueil affiche des widgets réels. La recherche retourne des résultats IGDB filtrés.                   |
| ---       | ---                                                                                                              |
| P4        | Un utilisateur peut ajouter un jeu en collection avec les règles d'unicité et de notation validées côté serveur. |
| ---       | ---                                                                                                              |
| P5        | Tous les endpoints API répondent selon le format standard. Le Cron de nettoyage s'exécute sans erreur.           |
| ---       | ---                                                                                                              |
| P6        | La wishlist, l'agenda, le profil public et le scan code-barres sont fonctionnels.                                |
| ---       | ---                                                                                                              |

# **12\. Suggestions & améliorations**

## **12.1 Pages manquantes à prévoir**

- Page 404 personnalisée (dans le thème du site)
- Page 500 personnalisée
- Page de politique de confidentialité (obligatoire RGPD)
- Page CGU (conditions générales d'utilisation)
- Page de récupération de mot de passe

## **12.2 Fonctionnalités V3**

- Système de notifications en base de données : 'Votre jeu wishlisté sort dans 7 jours', 'Nouveau avis sur un jeu de votre collection'
- Système de signalement de contenu inapproprié (avis, profils)
- Système de modération des avis et des profils utilisateurs

## **12.3 Fonctionnalités futures (post-launch)**

- Système social : amis, followers, comparaison de collections
- Notifications push (PWA)
- Application mobile native (Android / iOS) depuis la base PWA
- Listes personnalisées (custom) en plus des listes fixes
- Intégration d'une API de prix du marché (jeux d'occasion)
- Intégration outil de logging externe (Sentry, Logtail) en remplacement du logging local

# **13\. Récapitulatif technique**

| **Composant**     | **Technologie**                    | **Rôle**                                |
| ----------------- | ---------------------------------- | --------------------------------------- |
| Frontend          | PHP 8.2+ / CSS / JS                | Rendu serveur, templating, interactions |
| ---               | ---                                | ---                                     |
| Routeur           | FastRoute ou AltoRouter            | Gestion des slugs SEO                   |
| ---               | ---                                | ---                                     |
| Base de données   | Supabase (PostgreSQL)              | Stockage principal, RLS, auth JWT       |
| ---               | ---                                | ---                                     |
| Stockage fichiers | Supabase Storage                   | Avatars, photos de jeux                 |
| ---               | ---                                | ---                                     |
| API jeux          | IGDB (Twitch API)                  | Catalogue complet jeux/plateformes      |
| ---               | ---                                | ---                                     |
| Anti-bot          | Cloudflare Turnstile               | Protection formulaires sensibles        |
| ---               | ---                                | ---                                     |
| Cache             | Cache fichier/PHP serveur          | Réduction appels IGDB                   |
| ---               | ---                                | ---                                     |
| Cron              | Cron Jobs (serveur)                | Sync IGDB 24h, nettoyage hebdo          |
| ---               | ---                                | ---                                     |
| Images            | Conversion WebP (GD/Imagick)       | Optimisation + lazy loading             |
| ---               | ---                                | ---                                     |
| Logging           | JSON structuré + purge auto        | Erreurs, perfs, audit                   |
| ---               | ---                                | ---                                     |
| Police            | Inter (Google Fonts)               | Corps de texte du site                  |
| ---               | ---                                | ---                                     |
| Déploiement       | XAMPP / Apache (local)             | Serveur de développement                |
| ---               | ---                                | ---                                     |
| SEO               | sitemap.xml + robots.txt + JSON-LD | Indexation + rich snippets              |
| ---               | ---                                | ---                                     |
| Sécurité          | RLS + Rate Limiting + Turnstile    | Protection multicouche                  |
| ---               | ---                                | ---                                     |
| Résilience IGDB   | Cache local + circuit breaker      | Continuité en cas de panne API          |
| ---               | ---                                | ---                                     |

- Fin du document v2.0 -