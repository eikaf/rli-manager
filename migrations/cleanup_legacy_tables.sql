-- =============================================================================
-- cleanup_legacy_tables.sql
-- Rimozione delle tabelle legacy non più referenziate dal codebase PHP.
--
-- Verifica effettuata sul codebase (grep su *.php):
--   - `locatori`                 : NESSUNA referenza SQL  -> DROP
--   - `comunicazioni_backup_old` : NESSUNA referenza SQL  -> DROP
--   - `pratica_immobili`         : NESSUNA referenza SQL  -> DROP
--   - `pratica_soggetti`         : NESSUNA referenza SQL  -> DROP
--   - `pratiche_rli`             : NESSUNA referenza SQL  -> DROP
--   - `proprieta_immobili`       : ANCORA USATA (proprieta.php, export_csv.php,
--                                  SoggettoRepository, ImmobileRepository,
--                                  RliImportService, get_proprietari.php, backup.php)
--                                  -> NON viene rimossa.
--
-- Ordine di DROP rispettoso dei vincoli FOREIGN KEY:
--   pratica_immobili e pratica_soggetti referenziano pratiche_rli(id),
--   quindi vanno eliminate PRIMA di pratiche_rli.
-- =============================================================================

-- Tabelle figlie di pratiche_rli (FK -> pratiche_rli.id)
DROP TABLE IF EXISTS `pratica_immobili`;
DROP TABLE IF EXISTS `pratica_soggetti`;

-- Tabella padre
DROP TABLE IF EXISTS `pratiche_rli`;

-- Tabelle legacy senza dipendenze FK
DROP TABLE IF EXISTS `locatori`;
DROP TABLE IF EXISTS `comunicazioni_backup_old`;
