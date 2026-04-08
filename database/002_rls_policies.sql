-- ================================================================
--  002_rls_policies.sql — Politiques Row Level Security (RLS)
--  Exécuter APRÈS 001_schema.sql dans le SQL Editor Supabase
-- ================================================================

-- ----------------------------------------------------------------
--  Activer RLS sur toutes les tables
-- ----------------------------------------------------------------
ALTER TABLE platforms          ENABLE ROW LEVEL SECURITY;
ALTER TABLE users              ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_platforms     ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_genres        ENABLE ROW LEVEL SECURITY;
ALTER TABLE games              ENABLE ROW LEVEL SECURITY;
ALTER TABLE game_platforms     ENABLE ROW LEVEL SECURITY;
ALTER TABLE game_versions      ENABLE ROW LEVEL SECURITY;
ALTER TABLE collection_entries ENABLE ROW LEVEL SECURITY;
ALTER TABLE collection_photos  ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews            ENABLE ROW LEVEL SECURITY;
ALTER TABLE wishlist           ENABLE ROW LEVEL SECURITY;
ALTER TABLE logs               ENABLE ROW LEVEL SECURITY;

-- ================================================================
--  PLATFORMS — lecture publique, écriture service_role seulement
-- ================================================================
CREATE POLICY "platforms_read_all"
    ON platforms FOR SELECT
    USING (true);

-- ================================================================
--  USERS — lecture propre profil + profils publics, écriture propre profil
-- ================================================================
CREATE POLICY "users_read_own"
    ON users FOR SELECT
    USING (
        auth.uid() = id
        OR collection_public = true
    );

CREATE POLICY "users_insert_own"
    ON users FOR INSERT
    WITH CHECK (auth.uid() = id);

CREATE POLICY "users_update_own"
    ON users FOR UPDATE
    USING (auth.uid() = id)
    WITH CHECK (auth.uid() = id);

CREATE POLICY "users_delete_own"
    ON users FOR DELETE
    USING (auth.uid() = id);

-- ================================================================
--  USER_PLATFORMS — propre utilisateur uniquement
-- ================================================================
CREATE POLICY "user_platforms_own"
    ON user_platforms FOR ALL
    USING (auth.uid() = user_id)
    WITH CHECK (auth.uid() = user_id);

-- ================================================================
--  USER_GENRES — propre utilisateur uniquement
-- ================================================================
CREATE POLICY "user_genres_own"
    ON user_genres FOR ALL
    USING (auth.uid() = user_id)
    WITH CHECK (auth.uid() = user_id);

-- ================================================================
--  GAMES — lecture publique, écriture service_role (sync IGDB)
-- ================================================================
CREATE POLICY "games_read_all"
    ON games FOR SELECT
    USING (true);

-- ================================================================
--  GAME_PLATFORMS — lecture publique
-- ================================================================
CREATE POLICY "game_platforms_read_all"
    ON game_platforms FOR SELECT
    USING (true);

-- ================================================================
--  GAME_VERSIONS — lecture publique
-- ================================================================
CREATE POLICY "game_versions_read_all"
    ON game_versions FOR SELECT
    USING (true);

-- ================================================================
--  COLLECTION_ENTRIES
--   - Lecture : propre collection + collections publiques
--   - Écriture : propre collection uniquement
-- ================================================================
CREATE POLICY "collection_read_own_or_public"
    ON collection_entries FOR SELECT
    USING (
        auth.uid() = user_id
        OR EXISTS (
            SELECT 1 FROM users u
            WHERE u.id = collection_entries.user_id
              AND u.collection_public = true
        )
    );

CREATE POLICY "collection_insert_own"
    ON collection_entries FOR INSERT
    WITH CHECK (auth.uid() = user_id);

CREATE POLICY "collection_update_own"
    ON collection_entries FOR UPDATE
    USING (auth.uid() = user_id)
    WITH CHECK (auth.uid() = user_id);

CREATE POLICY "collection_delete_own"
    ON collection_entries FOR DELETE
    USING (auth.uid() = user_id);

-- ================================================================
--  COLLECTION_PHOTOS — même logique que collection_entries
-- ================================================================
CREATE POLICY "photos_read_own_or_public"
    ON collection_photos FOR SELECT
    USING (
        EXISTS (
            SELECT 1 FROM collection_entries ce
            LEFT JOIN users u ON u.id = ce.user_id
            WHERE ce.id = collection_photos.entry_id
              AND (ce.user_id = auth.uid() OR u.collection_public = true)
        )
    );

CREATE POLICY "photos_write_own"
    ON collection_photos FOR INSERT
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM collection_entries ce
            WHERE ce.id = collection_photos.entry_id
              AND ce.user_id = auth.uid()
        )
    );

CREATE POLICY "photos_delete_own"
    ON collection_photos FOR DELETE
    USING (
        EXISTS (
            SELECT 1 FROM collection_entries ce
            WHERE ce.id = collection_photos.entry_id
              AND ce.user_id = auth.uid()
        )
    );

-- ================================================================
--  REVIEWS — lecture publique, écriture propre avis uniquement
-- ================================================================
CREATE POLICY "reviews_read_all"
    ON reviews FOR SELECT
    USING (true);

CREATE POLICY "reviews_insert_own"
    ON reviews FOR INSERT
    WITH CHECK (auth.uid() = user_id);

CREATE POLICY "reviews_update_own"
    ON reviews FOR UPDATE
    USING (auth.uid() = user_id)
    WITH CHECK (auth.uid() = user_id);

CREATE POLICY "reviews_delete_own"
    ON reviews FOR DELETE
    USING (auth.uid() = user_id);

-- ================================================================
--  WISHLIST — propre utilisateur uniquement
-- ================================================================
CREATE POLICY "wishlist_own"
    ON wishlist FOR ALL
    USING (auth.uid() = user_id)
    WITH CHECK (auth.uid() = user_id);

-- ================================================================
--  LOGS — aucun accès direct (service_role uniquement via backend)
-- ================================================================
-- Pas de politique SELECT/INSERT pour les rôles anon / authenticated.
-- Le backend utilise la clé service_role (bypass RLS) pour écrire.
-- Pour autoriser le monitoring admin Supabase Dashboard :
CREATE POLICY "logs_admin_only"
    ON logs FOR SELECT
    USING (auth.role() = 'service_role');
