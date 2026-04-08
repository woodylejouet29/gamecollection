-- ================================================================
--  003_auth_trigger.sql — Trigger optionnel pour auto-créer le
--  profil dans public.users lors d'une inscription Supabase Auth.
--
--  NOTE : ce trigger est une sécurité de secours. Le RegisterController
--  PHP crée déjà le profil. Ne les utilisez pas en parallèle sans
--  gérer les conflits (ON CONFLICT DO NOTHING est présent).
-- ================================================================

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO public.users (id, username, created_at, updated_at)
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data->>'username', split_part(NEW.email, '@', 1)),
        NOW(),
        NOW()
    )
    ON CONFLICT (id) DO NOTHING;

    RETURN NEW;
END;
$$;

-- Supprimer l'ancien trigger s'il existe
DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;

CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW
    EXECUTE FUNCTION public.handle_new_user();
