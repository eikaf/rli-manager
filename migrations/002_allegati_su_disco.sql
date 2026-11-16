-- =============================================================================
-- 002_allegati_su_disco.sql
-- Migrazione graduale degli allegati PDF da LONGBLOB a file system.
--
-- Aggiunge le colonne allegato_path e allegato_sha256 sia a contratti che a
-- eventi_contratto, MANTENENDO temporaneamente allegato_pdf per retrocompatibilità.
-- I nuovi upload vengono salvati su disco (allegato_path) e allegato_pdf = NULL;
-- la lettura resta trasparente (AttachmentStorage::read) con fallback sul BLOB.
--
-- MariaDB 10.11 supporta ADD COLUMN IF NOT EXISTS (idempotente).
-- =============================================================================

ALTER TABLE `contratti`
    ADD COLUMN IF NOT EXISTS `allegato_path`   VARCHAR(255) NULL AFTER `allegato_pdf`,
    ADD COLUMN IF NOT EXISTS `allegato_sha256` CHAR(64)     NULL AFTER `allegato_path`;

ALTER TABLE `eventi_contratto`
    ADD COLUMN IF NOT EXISTS `allegato_path`   VARCHAR(255) NULL AFTER `allegato_pdf`,
    ADD COLUMN IF NOT EXISTS `allegato_sha256` CHAR(64)     NULL AFTER `allegato_path`;
