---
name: Migration progressive Supabase vers VPS
overview: Repartir de zéro sur ton VPS OVH (Coolify) en remplaçant Supabase (DB + RLS + Auth) par une stack self-host simple et durable, sans migration de données existantes.
todos:
  - id: php83-baseline
    content: Passer l’app directement sur PHP 8.3 (runtime Coolify + dépendances composer) et valider les extensions requises.
    status: pending
  - id: decide-auth-approach
    content: "Mettre en place l’auth cible (recommandé\_: Authentik OIDC) et définir les claims/identifiants attendus par l’app."
    status: pending
  - id: deploy-postgres-vps
    content: Déployer PostgreSQL sur le VPS via Coolify avec persistance, réseau privé, et stratégie de sauvegarde/restauration testée.
    status: pending
  - id: rebuild-schema
    content: Recréer le schéma sur le Postgres VPS (adapter `database/001_schema.sql` pour supprimer la dépendance à `auth.users` et revoir RLS selon la nouvelle auth).
    status: pending
  - id: replace-supabase-in-app
    content: Retirer Supabase Auth/PostgREST côté PHP et passer en accès Postgres direct (PDO) + validation JWT/cookies selon l’auth retenue.
    status: pending
  - id: igdb-assets-strategy
    content: "Simplifier les images IGDB (recommandé\_: ne rien stocker et construire les URLs CDN IGDB à la volée). Garder une option MinIO (S3) ou volume local persistant si besoin."
    status: pending
  - id: go-live-checklist
    content: Checklist mise en prod (secrets Coolify, migrations, backups, monitoring, smoke tests).
    status: pending
  - id: rollback-plan
    content: Préparer un plan de rollback/retour à Supabase pendant la transition prod (routage, secrets, critères de bascule).
    status: pending
isProject: false
---

# Objectif
- Repartir sur une stack **self-host** sur ton VPS, sans récupérer les données Supabase existantes.
- Remplacer proprement **DB + Auth + (éventuellement) RLS** avec une solution facile à opérer.

# Constat sur ton projet (à partir du dépôt)
- App **PHP** (`[C:/Users/Woody/Documents/gameproject/composer.json](C:/Users/Woody/Documents/gameproject/composer.json)`) → cible **PHP 8.3** directement.
- Tu utilises Supabase de 2 façons :
  - **Supabase Auth API** (`[C:/Users/Woody/Documents/gameproject/src/Services/SupabaseAuth.php](C:/Users/Woody/Documents/gameproject/src/Services/SupabaseAuth.php)`).
  - **PostgREST** via `rest/v1/...` avec `service_role` (bypass RLS) pour écrire/lire certaines tables (`SupabaseAuth::restGet/restPost`).
- Ton schéma DB dépend de `auth.users` : ta table `public.users` référence `auth.users(id)` (`[C:/Users/Woody/Documents/gameproject/database/001_schema.sql](C:/Users/Woody/Documents/gameproject/database/001_schema.sql)`), et tes policies RLS s’appuient sur `auth.uid()` (`[C:/Users/Woody/Documents/gameproject/database/002_rls_policies.sql](C:/Users/Woody/Documents/gameproject/database/002_rls_policies.sql)`).

# Architecture recommandée (court + long terme)
## Recommandé : Authentik (OIDC) + Postgres + app PHP (PDO)
- **Authentik** gère l’inscription/connexion (OIDC).
- L’app PHP valide le **JWT** (ou consomme l’userinfo) et crée/maintient un profil applicatif.
- La DB Postgres est “classique” (pas de dépendance à `auth.users`).

## Option “encore plus simple” (si tu veux aller vite)
- Auth gérée par l’app PHP (email/password + `password_hash` + sessions/cookies) + Postgres.
- Tu pourras migrer plus tard vers Authentik/Keycloak si besoin (OIDC).

# Plan détaillé (étapes)

## 1) Baseline technique (PHP 8.3)
- Déployer l’app directement avec **PHP 8.3** (runtime/image sur Coolify).
- Mettre à jour/valider `composer.json` et les dépendances pour PHP 8.3.
- Vérifier les extensions nécessaires (ex. `ext-zstd` si tu t’en sers toujours, sinon l’enlever).

## 2) Authentik (OIDC) + migration des secrets (Coolify)
- Déployer Authentik sur Coolify.
- Dans Authentik : créer un **Provider OIDC** puis une **Application** liée au provider.
- Configurer les redirect URIs (ex. `https://<domaine>/auth/callback`) et le logout.
- Récupérer **Client ID** et **Client Secret**.
- Injecter ces valeurs dans les variables d’environnement Coolify (ex. `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_ISSUER_URL`, `OIDC_REDIRECT_URI`).
- Définir les claims utiles (au minimum `sub`, et souvent `email`, `preferred_username`).
- Décider la stratégie “profil applicatif” : table locale `users` liée à `oidc_sub`.

## 3) Préparer PostgreSQL sur ton VPS (Coolify)
- Déployer un service **PostgreSQL** (version récente supportée) avec :
  - **Stockage persistant**
  - **Backups** + test de restauration
  - **Réseau privé** app ↔ DB (éviter d’exposer Postgres)
  - **TLS** uniquement si tu dois exposer Postgres
- Créer un rôle DB d’application (ex. `app_rw`) avec droits minimum nécessaires.

## 4) Recréer le schéma DB sans Supabase
- Prendre `[database/001_schema.sql](C:/Users/Woody/Documents/gameproject/database/001_schema.sql)` comme base.
- Remplacer le modèle Supabase :
  - `public.users(id UUID PRIMARY KEY REFERENCES auth.users(id))`
  - par un modèle local (recommandé si Authentik) :
    - `users(id UUID PK)` *ou* `users(id BIGSERIAL PK)`
    - `users.oidc_sub TEXT UNIQUE NOT NULL`
- Mettre à jour toutes les FKs `user_id` pour référencer le nouveau `users(id)`.
- Conserver/adapter les index additionnels (`[database/013_games_synopsis_zstd_backfill_index.sql](C:/Users/Woody/Documents/gameproject/database/013_games_synopsis_zstd_backfill_index.sql)` etc.).

## 5) Autorisation (choisie) — Approche 3.A : logique applicative PHP
On fait l’autorisation dans le backend PHP (pas de RLS comme barrière primaire) :
- Les requêtes sur données privées incluent systématiquement `WHERE user_id = :currentUserId` (ou des jointures équivalentes).
- La DB n’est accessible qu’au backend (réseau privé), jamais depuis un client.
- On peut ajouter RLS plus tard en durcissement, mais ce n’est pas requis pour démarrer.

## 6) Initialisation (pas de migration de données)
- Charger uniquement le schéma.
- (Optionnel) seed minimal (admin user, plateformes de base, etc.).

## 7) Remplacer Supabase dans l’app PHP (PostgREST → PDO)
- Remplacer `SupabaseAuth` (`[src/Services/SupabaseAuth.php](C:/Users/Woody/Documents/gameproject/src/Services/SupabaseAuth.php)`) :
  - Implémenter le login OIDC (authorization code flow), stocker la session côté serveur / cookie sécurisé.
  - Associer `oidc_sub` → `users.id` (provisioning à la première connexion).
- Remplacer les appels PostgREST (`/rest/v1/...`) par accès Postgres direct (PDO).
  - Préparer une couche **Repository** ou **DataMapper** pour éviter de disperser du SQL partout.
- Supprimer les variables d’env Supabase et basculer sur `DB_HOST/DB_*` du VPS.

## 8) Images IGDB — supprimer l’étape de stockage (recommandé)
Comme IGDB sert déjà les images via CDN, tu peux éviter complètement le stockage :
- Stocker seulement les identifiants IGDB nécessaires (ex. `igdb_id` et/ou `image_id`).
- Construire l’URL IGDB à la volée au rendu (et s’appuyer sur le cache navigateur/CDN).
- Résultat : tu peux supprimer `SupabaseObjectStorage` et toute la complexité S3/Local.

### Fallback si tu dois stocker malgré tout
- **Local** : déclarer un **volume persistant** dans Coolify (sinon perte à chaque déploiement).
- **MinIO (S3 self-host)** : un petit conteneur MinIO s’intègre bien et garde ton code “cloud-ready”.

## 9) Observabilité, sécurité, exploitation
- Backups + test de restore.
- Monitoring Postgres (connexions, slow queries, disque).
- Gestion secrets Coolify (cookies secure, secrets OIDC, mots de passe DB).
- Réseaux (Postgres non exposé, firewall OVH + fail2ban si besoin).

## 10) Rollback / retour à Supabase (pendant la transition)
Même si tu repars de zéro, avoir un “retour arrière” réduit le stress :
- **Déploiement parallèle** : garder l’ancienne version (Supabase) déployée et fonctionnelle pendant que tu mets la nouvelle en prod.
- **Routage** : bascule via DNS / reverse proxy (ou deux sous-domaines `old.` / `new.`) pour revenir en arrière rapidement.
- **Secrets** : conserver les variables Supabase (URL/keys/DB) prêtes à être réactivées sur Coolify.
- **Critères** : définir à l’avance ce qui déclenche le rollback (taux d’erreur, login cassé, timeouts DB, etc.).
- **Données** : comme tu ne migres pas de data, le rollback est essentiellement un basculement de trafic (pas de resync complexe).

# Points de vigilance (pièges potentiels)
- **Remplacement de PostgREST** : ton app utilise actuellement HTTP (PostgREST) pour parler à la base. Passer à PDO va être plus rapide, mais demande une réécriture significative.
  - Prépare une couche **Repository** ou **DataMapper** pour remplacer proprement les services.
  - Priorise les parcours critiques (auth, profil, collection, reviews).
- **Gestion des Assets (S3 vs Local)** : si tu restes en local sur le VPS, pense au **volume persistant** côté Coolify, sinon tout disparaît à chaque déploiement.
  - Vu ta config, un petit conteneur **MinIO** s’intègre bien et garde ton code “cloud-ready”.

# Livrables concrets attendus à la fin
- PostgreSQL sur VPS opérationnel + backups.
- Schéma “clean” sans dépendances Supabase.
- Auth fonctionnelle (Authentik recommandé) + profils applicatifs.
- App **PHP 8.3** connectée au Postgres VPS (et images IGDB via CDN, sans stockage).
