# Plan d'intégration — Site de Collection de Jeux Vidéo

> **Principe directeur** : construire de bas en haut. Jamais de page avant que son infrastructure (BDD, auth, API) soit validée. Tester chaque phase avant de passer à la suivante.

---

## Vue d'ensemble des phases

| Phase | Nom | Durée estimée | Pré-requis |
|-------|-----|---------------|------------|
| **P1** | Fondations & Authentification | 6–9 jours | — |
| **P2** | IGDB + UI transversale | 6–9 jours | P1 terminé |
| **P3** | Pages publiques core | 8–11 jours | P2 terminé |
| **P4** | Cœur métier collection | 10–13 jours | P3 terminé |
| **P5** | API interne, robustesse & SEO final | 7–9 jours | P4 terminé |
| **P6** | V2 — Communauté & Scan | 9–13 jours | P5 terminé |

---

## P1 — Fondations & Authentification *(6–9 jours)*

### Bloc 1.1 — Infrastructure (3–5 jours)

- [ ] Configurer XAMPP / Apache, activer `mod_rewrite`
- [ ] Installer **FastRoute** ou **AltoRouter** via Composer
- [ ] Créer le fichier `.htaccess` pour router toutes les URL vers `index.php`
- [ ] Mettre en place les variables d'environnement (`.env` + chargeur PHP)
- [ ] Connecter **Supabase** (SDK PHP officiel, clés API en `.env`)
- [ ] Créer toutes les tables BDD avec RLS activé :
  - [ ] `users`
  - [ ] `user_platforms`
  - [ ] `user_genres`
  - [ ] `games`
  - [ ] `game_platforms`
  - [ ] `game_versions`
  - [ ] `collection_entries` (contrainte UNIQUE composite)
  - [ ] `collection_photos`
  - [ ] `reviews`
  - [ ] `wishlist`
  - [ ] `platforms`
  - [ ] `logs`
- [ ] Configurer les politiques RLS sur chaque table
- [ ] Implémenter le **système de logging centralisé** (JSON structuré → table `logs`)
- [ ] Créer les pages d'erreur basiques `404.php` et `500.php`

### Bloc 1.2 — Authentification (3–4 jours)

- [ ] Page `/register` :
  - [ ] Formulaire (pseudo unique, email unique, mot de passe fort, confirmation)
  - [ ] Upload ou URL d'avatar
  - [ ] Sélection plateformes / accessoires / genres préférés (données IGDB statiques en attendant P2)
  - [ ] Intégration **Cloudflare Turnstile**
  - [ ] Rate limiting IP sur soumission
  - [ ] Validation serveur complète
- [ ] Page `/login` :
  - [ ] Formulaire email + mot de passe
  - [ ] Case "Rester connecté" (30j / 7j)
  - [ ] Cloudflare Turnstile + rate limiting
  - [ ] Lien vers `/register`
- [ ] `/logout` : destruction de session + redirection
- [ ] **Middleware d'authentification PHP** (guard sur toutes les pages protégées)
- [ ] Page `/edit-profile` — **onglet Profil uniquement** :
  - [ ] Modification pseudo, email, mot de passe, avatar
  - [ ] Rate limiting sur chaque action sensible (1×/heure)

> ✅ **Jalon P1** : un utilisateur peut s'inscrire, se connecter, modifier son profil et se déconnecter. Le logging fonctionne et écrit en base.

---

## P2 — Intégration IGDB & UI transversale *(6–9 jours)*

### Bloc 2.1 — Intégration IGDB & cache (4–6 jours)

- [ ] Créer le **client PHP IGDB** (Twitch OAuth2 + appels API)
- [ ] Synchronisation initiale des tables catalogue :
  - [ ] `platforms` (nom, logo, génération, fabricant)
  - [ ] `games` (titre, synopsis, genres, dates, notes…)
  - [ ] `game_versions` (GOTY, Deluxe, Standard…)
  - [ ] `game_platforms` (jeu × plateforme × région × date de sortie)
- [ ] **Cron Job 24h** : script de synchronisation IGDB + logging des résultats
- [ ] Conversion automatique des images IGDB en **WebP** (GD ou Imagick) + stockage local
- [ ] Cache serveur agressif sur les appels IGDB (TTL configurable)
- [ ] Rate limiting côté serveur sur les appels IGDB
- [ ] **Circuit breaker** : basculement sur cache local après 3 échecs consécutifs
- [ ] Logging des échecs de synchronisation + retry automatique toutes les heures (24h max)

### Bloc 2.2 — Header & modules transversaux (2–3 jours)

- [ ] **Header sticky** :
  - [ ] Logo + nom du site (lien `/`)
  - [ ] Navigation principale
  - [ ] État non connecté : boutons Inscription / Connexion
  - [ ] État connecté : icône collection + compteur jeux en attente + bouton Déconnexion
  - [ ] Toggle thème Clair / Sombre (persisté en cookie, sans flash)
- [ ] **CSS global** : système de variables (couleurs, dégradés violets, typographie)
- [ ] Intégration police **Inter** (corps) + police display (titres)
- [ ] Un fichier CSS dédié par page (structure de base)
- [ ] **Toggle de vue** (Grille / Liste / Cartes) — composant réutilisable, préférence mémorisée en cookie
- [ ] **Système de filtres universels** — composant réutilisable (date, note, plateforme, genre, région, type)
- [ ] **Barre sticky de sélection** — compteur temps réel + lien `/select`
- [ ] **Système de pagination** — classique + paramètre GET (URLs partageables)

> ✅ **Jalon P2** : les données IGDB sont synchronisées en base. Le cache est opérationnel. Le circuit breaker fonctionne. Le header s'affiche correctement dans les deux thèmes.

---

## P3 — Pages publiques core *(8–11 jours)*

### Bloc 3.1 — Page d'accueil `/` (3–4 jours)

- [ ] Bandeau conversion (non connecté) : phrase accrocheuse + CTA `/register`
- [ ] Bannière collection (connecté) : incitation + lien `/search`
- [ ] Widgets dynamiques :
  - [ ] Jeux les plus attendus
  - [ ] Dernières sorties
  - [ ] Derniers avis communauté
  - [ ] Jeux tendance par plateforme
  - [ ] Jeux populaires par genre
  - [ ] Statistiques globales (membres, jeux catalogués, avis, top 10 jeux collectionnés)
- [ ] Balises meta + Open Graph

### Bloc 3.2 — Recherche `/search` (3–4 jours)

- [ ] Barre de recherche pleine largeur avec **prévisualisation live** (debounce 300 ms)
- [ ] Filtres en colonne gauche (date, note, plateforme, genre, région, type)
- [ ] Zone de résultats — toggle 3 vues (Grille / Liste / Cartes)
- [ ] Clic sur un jeu → **pop-up choix de version**
- [ ] Ajout vers `/select` + vérification doublon immédiate
- [ ] Pagination optimisée + lazy loading images
- [ ] Barre sticky : compteur sélectionnés + accès rapide `/select`

### Bloc 3.3 — Fiche jeu `/game/:slug` (2–3 jours)

- [ ] Bannière full-width blur/parallax + jaquette + titre + badges plateformes
- [ ] Navigation entre versions du jeu (GOTY, Deluxe…) + liens DLC
- [ ] Bouton "Ajouter à ma collection" → pop-up plateforme → envoi vers `/select`
- [ ] Bouton "J'attends" (wishlist) + compteur global
- [ ] Affichage données IGDB : note, dates par région, synopsis, dev, éditeur, genres, galerie, vidéos, accessoires
- [ ] Section avis utilisateurs (Terminé / 100% uniquement) + note moyenne
- [ ] **JSON-LD** schema.org `VideoGame`
- [ ] Balises meta + Open Graph

> ✅ **Jalon P3** : la page d'accueil affiche des widgets réels. La recherche retourne des résultats IGDB filtrés. On peut consulter une fiche jeu complète.

---

## P4 — Cœur métier collection *(10–13 jours)*

### Bloc 4.1 — Liste de sélection `/select` (6–8 jours)

- [ ] Liste de jeux en attente avec un formulaire par jeu
- [ ] **Formulaire version Dématérialisée** :
  - [ ] Choix Physique / Dématérialisé (obligatoire)
  - [ ] Choix région (obligatoire)
  - [ ] Nombre d'exemplaires (≥ 1, duplication dynamique des sections)
  - [ ] Date d'acquisition + prix (facultatifs)
  - [ ] Liste personnelle de classement (obligatoire)
  - [ ] Statut de progression (obligatoire)
  - [ ] Durée complétion (si Terminé / 100%)
  - [ ] Note sur 10 + avis (≥ 100 car.) — **masqués si statut ≠ Terminé / 100%**
- [ ] **Formulaire version Physique** (tout ce qui précède, plus) :
  - [ ] État du jeu (obligatoire, 8 niveaux)
  - [ ] Commentaire état (max 500 car.)
  - [ ] Boîte d'origine Oui/Non (obligatoire)
  - [ ] Manuel Présent/Absent (obligatoire)
  - [ ] Jusqu'à 3 photos par exemplaire (URL ou upload → WebP)
- [ ] **Endpoint** `POST /api/collection/add` :
  - [ ] Validation d'unicité serveur (user + game + platform + version + region + type)
  - [ ] Création d'une ligne `collection_entries` par exemplaire validé
  - [ ] Retrait wishlist **uniquement après** validation réussie
  - [ ] Redirection vers `/collection`
- [ ] **Endpoint** `POST /api/select/check-duplicate`
- [ ] Pagination si plusieurs jeux
- [ ] Codes d'erreur : `DUPLICATE_ENTRY`, `INVALID_STATUS_FOR_REVIEW`, `VALIDATION_ERROR`

### Bloc 4.2 — Collection personnelle `/collection` (4–5 jours)

- [ ] Filtres avancés : plateforme, genre, statut, état physique, région, note
- [ ] Tri : alphabétique, date d'acquisition, note, valeur estimée
- [ ] Toggle vues (Grille / Liste / Cartes)
- [ ] Statistiques : nombre de jeux, répartition physique/démat, valeur totale estimée, temps de jeu total
- [ ] Export **CSV / JSON**
- [ ] Navigation entre les listes personnelles fixes
- [ ] Modification rapide d'une entrée (statut, note, état physique)
- [ ] **Endpoints** `PATCH /api/collection/update` et `DELETE /api/collection/delete`
- [ ] **Onglet Préférences** de `/edit-profile` : mise à jour plateformes, accessoires, genres

> ✅ **Jalon P4** : un utilisateur peut ajouter un jeu en collection avec les règles d'unicité et de notation validées côté serveur. La collection est consultable et modifiable.

---

## P5 — API interne, robustesse & SEO final *(7–9 jours)*

### Bloc 5.1 — API internes & robustesse (4–5 jours)

- [ ] Finaliser et tester tous les endpoints `/api/*` :
  - [ ] `POST /api/collection/add`
  - [ ] `PATCH /api/collection/update`
  - [ ] `DELETE /api/collection/delete`
  - [ ] `POST /api/wishlist/toggle`
  - [ ] `POST /api/select/check-duplicate`
  - [ ] `POST /api/reviews/add` (validation statut Terminé obligatoire)
  - [ ] `GET /api/games/search`
  - [ ] `GET /api/games/:id`
  - [ ] `GET /api/user/profile`
- [ ] Format de réponse JSON uniforme (`success`, `data` / `error.code`, `error.message`)
- [ ] Tests des règles d'unicité `collection_entries`
- [ ] Tests de la restriction de notation (statut Terminé / 100% obligatoire)
- [ ] Tests du circuit breaker IGDB
- [ ] **Cron Job hebdomadaire de nettoyage** :
  - [ ] Détection jeux en cache sans `collection_entries` ni `wishlist` depuis +90 jours
  - [ ] Suppression images WebP orphelines
  - [ ] Purge logs > 30 jours
  - [ ] Purge sessions expirées
  - [ ] Rapport de nettoyage loggé

### Bloc 5.2 — SEO & performance finale (3–4 jours)

- [ ] `sitemap.xml` dynamique généré depuis la BDD → `/sitemap.xml`
- [ ] `robots.txt` configuré → `/robots.txt`
- [ ] Balises `rel="next"` / `rel="prev"` sur toutes les pages paginées
- [ ] Lazy loading complet (natif `loading="lazy"` + JS pour composants non critiques)
- [ ] **Cache-busting** JS/CSS via hash de fichier
- [ ] Optimisation pipeline WebP (vérification qualité / taille)
- [ ] Audit **Lighthouse mobile** (Performance, Accessibilité, SEO, Best Practices)
- [ ] **PWA** : `manifest.json` + service worker basique

> ✅ **Jalon P5** : tous les endpoints API répondent selon le format standard. Le Cron de nettoyage s'exécute sans erreur. Le score Lighthouse est satisfaisant.

---

## P6 — V2 : Communauté & Scan *(9–13 jours)*

### Bloc 6.1 — Pages communauté (5–7 jours)

- [ ] Page `/wishlist` :
  - [ ] Tri par date de sortie (la plus proche en premier)
  - [ ] Retrait manuel
  - [ ] Filtres universels + toggle vues + pagination
- [ ] Page `/user/:slug` :
  - [ ] Avatar, pseudo, bio, plateformes, genres préférés
  - [ ] Collection publique (si `collection_public = true`)
  - [ ] Derniers avis + statistiques utilisateur
- [ ] **Onglet Paramètres** de `/edit-profile` :
  - [ ] Visibilité collection (Publique / Privée, défaut Privée)
- [ ] `GET /api/user/profile` — finalisation endpoint profil public

### Bloc 6.2 — Scan code-barres `/barcode` (4–6 jours)

- [ ] Interface `/barcode` :
  - [ ] Mode caméra mobile (`navigator.mediaDevices`)
  - [ ] Upload photo
  - [ ] URL de photo
- [ ] Détection multi-codes (8+ codes EAN/UPC simultanés)
- [ ] Intégration **API tierce EAN/UPC** (UPC Item DB ou équivalent)
- [ ] Correspondance IGDB via titre (retour du résultat de l'API tierce)
- [ ] `POST /api/barcode/lookup` — endpoint dédié
- [ ] Pré-remplissage automatique du formulaire dans `/select`

> ✅ **Jalon P6** : la wishlist, l'agenda, le profil public et le scan code-barres sont fonctionnels.

---

## Matrice de dépendances

```
P1 (Infra + Auth)
│
├─► P2 (IGDB + UI transversale)
│     │
│     ├─► P3 (Accueil + Search + Fiche jeu)
│     │     │
│     │     └─► P4 (Select + Collection)
│     │           │
│     │           └─► P5 (API + SEO final)
│     │                 │
│     │                 └─► P6 (Wishlist, Agenda, User, Barcode)
│     │
│     └─► [Header / CSS global disponible dès P2]
```

**Règles strictes :**

- Auth (P1) → prérequis pour toute page connectée (`/select`, `/collection`, `/edit-profile`)
- IGDB (P2) → prérequis pour `/search`, `/game/:slug`, `/agenda`
- `/select` → dépend de `/search` (flux) + table `collection_entries`
- `/collection` → dépend de `/select` (données créées par ce flux)
- `/wishlist` → dépend de `/game/:slug` (bouton "J'attends") + `/collection` (retrait post-validation)
- `/user/:slug` → dépend de `/collection` (visibilité) + `reviews`
- `/barcode` → dépend de `/select` (pré-remplissage formulaire)
- Cron nettoyage → dépend des tables catalogue et `logs` (P5)

---

## Tableau de bord des jalons

| Phase | Critère de validation |
|-------|-----------------------|
| ✅ P1 | Inscription, connexion, modification profil, déconnexion. Logging opérationnel. |
| ✅ P2 | Données IGDB synchronisées. Cache actif. Circuit breaker testé. Header thème OK. |
| ✅ P3 | Widgets accueil avec données réelles. Recherche filtrée fonctionnelle. Fiche jeu complète. |
| ✅ P4 | Ajout en collection avec unicité + restriction notation validées côté serveur. Collection consultable/modifiable. |
| ✅ P5 | Tous les endpoints `/api/*` en format standard. Cron nettoyage sans erreur. Lighthouse mobile satisfaisant. |
| ✅ P6 | Wishlist, agenda, profil public et scan code-barres fonctionnels. |

---

*Plan généré depuis `documentation.md` v2.0 — Projet Site de Collection de Jeux Vidéo*
