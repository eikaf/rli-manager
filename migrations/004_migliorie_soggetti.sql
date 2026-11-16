-- 004_migliorie_soggetti.sql
-- Blocco A — Migliorie Soggetti:
--   * campo PEC dedicato
--   * collegamento FK al legale rappresentante (persona fisica già censita)
-- Le colonne denormalizzate cf_rappresentante/cognome_rappresentante/
-- nome_rappresentante/codice_carica restano invariate per compatibilità con XmlBuilder.

ALTER TABLE `soggetti`
  ADD COLUMN `pec` VARCHAR(150) NULL AFTER `email`,
  ADD COLUMN `id_rappresentante` INT(11) NULL AFTER `codice_carica`,
  ADD KEY `idx_rappresentante` (`id_rappresentante`),
  ADD CONSTRAINT `fk_soggetti_rappresentante`
    FOREIGN KEY (`id_rappresentante`) REFERENCES `soggetti` (`id`) ON DELETE SET NULL;
