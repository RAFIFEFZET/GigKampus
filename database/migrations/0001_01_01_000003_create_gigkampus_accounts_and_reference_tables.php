<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE banks (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,

                CONSTRAINT banks_name_check CHECK (
                    name = btrim(name, E' \t\n\r') AND char_length(name) BETWEEN 2 AND 100
                )
            );

            CREATE UNIQUE INDEX banks_name_unique ON banks (lower(name));

            CREATE TABLE categories (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,

                CONSTRAINT categories_name_check CHECK (
                    name = btrim(name, E' \t\n\r') AND char_length(name) BETWEEN 2 AND 100
                )
            );

            CREATE UNIQUE INDEX categories_name_unique ON categories (lower(name));

            CREATE TABLE admins (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(254) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT admins_name_check CHECK (char_length(btrim(name, E' \t\n\r')) BETWEEN 2 AND 100),
                CONSTRAINT admins_email_check CHECK (email ~ '^[^[:space:]@]+@[^[:space:]@]+$'),
                CONSTRAINT admins_password_hash_check CHECK (password_hash LIKE '$argon2id$%')
            );

            CREATE TABLE users (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(254) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                phone_number VARCHAR(16) NOT NULL,
                nim VARCHAR(20) UNIQUE,
                avatar_path TEXT,
                bio VARCHAR(500),
                is_verified BOOLEAN NOT NULL DEFAULT false,
                is_suspended BOOLEAN NOT NULL DEFAULT false,
                bank_id BIGINT REFERENCES banks (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                bank_account_number VARCHAR(50),
                bank_account_holder_name VARCHAR(100),
                qris_image_path TEXT,
                qris_holder_name VARCHAR(100),
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT users_name_check CHECK (char_length(btrim(name, E' \t\n\r')) BETWEEN 2 AND 100),
                CONSTRAINT users_email_check CHECK (email ~ '^[^[:space:]@]+@apps[.]ipb[.]ac[.]id$'),
                CONSTRAINT users_password_hash_check CHECK (password_hash LIKE '$argon2id$%'),
                CONSTRAINT users_phone_number_check CHECK (phone_number ~ '^[+][0-9]{10,15}$'),
                CONSTRAINT users_nim_check CHECK (nim ~ '^[A-Z0-9]{1,20}$'),
                CONSTRAINT users_verification_check CHECK (is_verified = (nim IS NOT NULL)),
                CONSTRAINT users_avatar_path_check CHECK (btrim(avatar_path, E' \t\n\r') <> ''),
                CONSTRAINT users_bio_check CHECK (btrim(bio, E' \t\n\r') <> ''),
                CONSTRAINT users_bank_fields_check CHECK (
                    num_nonnulls(bank_id, bank_account_number, bank_account_holder_name) IN (0, 3)
                    AND bank_account_number ~ '^[0-9]{1,50}$' IS NOT FALSE
                    AND btrim(bank_account_holder_name, E' \t\n\r') <> '' IS NOT FALSE
                ),
                CONSTRAINT users_qris_fields_check CHECK (
                    num_nonnulls(qris_image_path, qris_holder_name) IN (0, 2)
                    AND btrim(qris_image_path, E' \t\n\r') <> '' IS NOT FALSE
                    AND btrim(qris_holder_name, E' \t\n\r') <> '' IS NOT FALSE
                )
            );

            CREATE INDEX users_bank_id_index ON users (bank_id);

            CREATE OR REPLACE FUNCTION gigkampus_check_global_email() RETURNS trigger
            LANGUAGE plpgsql VOLATILE AS $$
            BEGIN
                NEW.email := lower(btrim(NEW.email, E' \t\n\r'));

                IF TG_OP = 'UPDATE' AND NEW.email IS NOT DISTINCT FROM OLD.email THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'users' AND TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Student email is immutable';
                END IF;

                -- Under READ COMMITTED the query after waiting on the lock sees the other writer's commit.
                PERFORM pg_advisory_xact_lock(hashtextextended(NEW.email, 0));

                IF (TG_TABLE_NAME = 'admins' AND EXISTS (SELECT 1 FROM users WHERE email = NEW.email))
                    OR (TG_TABLE_NAME = 'users' AND EXISTS (SELECT 1 FROM admins WHERE email = NEW.email))
                THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23505',
                        CONSTRAINT = 'gigkampus_global_email_unique',
                        MESSAGE = 'Email is already registered';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER admins_global_email
                BEFORE INSERT OR UPDATE OF email ON admins
                FOR EACH ROW EXECUTE FUNCTION gigkampus_check_global_email();

            CREATE TRIGGER users_global_email
                BEFORE INSERT OR UPDATE OF email ON users
                FOR EACH ROW EXECUTE FUNCTION gigkampus_check_global_email();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE users, admins, categories, banks;
            DROP FUNCTION gigkampus_check_global_email();
            SQL);
    }
};
