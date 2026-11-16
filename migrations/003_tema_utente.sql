-- 003_tema_utente.sql
-- Aggiunge la preferenza tema (chiaro/scuro/automatico) all'utente.
-- Eseguire una sola volta sul database esistente.

ALTER TABLE `utenti`
  ADD COLUMN `tema` ENUM('light','dark','auto') NOT NULL DEFAULT 'light' AFTER `ruolo`;
