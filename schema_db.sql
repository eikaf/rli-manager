/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: db_rli
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `comunicazioni`
--

DROP TABLE IF EXISTS `comunicazioni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comunicazioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) DEFAULT NULL,
  `id_utente` int(11) DEFAULT NULL,
  `tipo` enum('email','pec','raccomandata','telefonata','nota_interna','altro') NOT NULL DEFAULT 'email',
  `data_comunicazione` datetime NOT NULL DEFAULT current_timestamp(),
  `oggetto` varchar(255) DEFAULT NULL,
  `corpo` text DEFAULT NULL,
  `destinatari_json` longtext DEFAULT NULL,
  `allegati_json` longtext DEFAULT NULL,
  `inviata` tinyint(1) NOT NULL DEFAULT 0,
  `data_invio` datetime DEFAULT NULL,
  `errore_invio` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `idx_utente` (`id_utente`),
  KEY `idx_data` (`data_comunicazione`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `comunicazioni_backup_old`
--

DROP TABLE IF EXISTS `comunicazioni_backup_old`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comunicazioni_backup_old` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) DEFAULT NULL,
  `id_soggetto` int(11) DEFAULT NULL,
  `tipo` enum('email_inviata','email_ricevuta','pec_inviata','pec_ricevuta','nota_interna','telefonata') NOT NULL DEFAULT 'nota_interna',
  `direzione` enum('uscita','entrata','interna') NOT NULL DEFAULT 'interna',
  `oggetto` varchar(255) DEFAULT NULL,
  `corpo` mediumtext DEFAULT NULL,
  `destinatario` varchar(255) DEFAULT NULL,
  `mittente` varchar(255) DEFAULT NULL,
  `allegato_nome` varchar(255) DEFAULT NULL,
  `allegato_size` int(11) DEFAULT NULL,
  `esito` varchar(30) DEFAULT NULL,
  `data_comunicazione` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `idx_data` (`data_comunicazione`),
  KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contratti`
--

DROP TABLE IF EXISTS `contratti`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_utente_creatore` int(11) DEFAULT NULL,
  `id_contratto_univoco` varchar(20) DEFAULT NULL COMMENT 'ID Contratto AdE (17-20 char)',
  `codice_identificativo_contratto` varchar(20) DEFAULT NULL,
  `stato` enum('BOZZA','ATTIVO','PROROGATO','CESSATO','RISOLTO','SCADUTO') NOT NULL DEFAULT 'BOZZA',
  `tipologia_contratto` varchar(2) NOT NULL DEFAULT 'L1',
  `ufficio_territoriale` varchar(10) DEFAULT NULL,
  `data_stipula` date DEFAULT NULL,
  `durata_dal` date DEFAULT NULL,
  `durata_al` date DEFAULT NULL,
  `data_fine_proroga` date DEFAULT NULL,
  `data_cessione` date DEFAULT NULL,
  `data_subentro` date DEFAULT NULL,
  `data_inizio_rinegoziazione` date DEFAULT NULL,
  `rinegoziazione_tipo` enum('aumento','diminuzione') DEFAULT NULL,
  `importo_canone` decimal(10,2) DEFAULT NULL,
  `numero_pagine` int(11) DEFAULT NULL,
  `numero_copie` int(11) DEFAULT NULL,
  `tipologia_adempimento` varchar(2) DEFAULT NULL COMMENT 'Ultimo tipo registrato',
  `cedolare_secca` tinyint(4) DEFAULT NULL COMMENT '1=100%, 2=mista, 3=nessuna',
  `casi_particolari` tinyint(4) DEFAULT NULL,
  `agevolazioni` tinyint(1) NOT NULL DEFAULT 0,
  `contratto_esente` tinyint(4) DEFAULT NULL,
  `pagamento_intera_durata` tinyint(1) NOT NULL DEFAULT 0,
  `corrispettivo` decimal(10,2) DEFAULT NULL,
  `tipologia_subentro` tinyint(4) DEFAULT NULL,
  `tipologia_proroga` tinyint(4) DEFAULT NULL,
  `annualita` varchar(4) DEFAULT NULL,
  `tardivita_annualita` tinyint(4) DEFAULT NULL,
  `condizione_sospensiva` tinyint(1) NOT NULL DEFAULT 0,
  `contratto_tempo_indeterminato` tinyint(1) NOT NULL DEFAULT 0,
  `clausola_penale_volontaria` tinyint(1) NOT NULL DEFAULT 0,
  `contratto_soggetto_iva` tinyint(1) NOT NULL DEFAULT 0,
  `contratto_non_allegato` tinyint(1) NOT NULL DEFAULT 0,
  `tipo_garanzia` tinyint(4) DEFAULT NULL,
  `importo_garanzia` decimal(10,2) DEFAULT NULL,
  `cf_garante` varchar(16) DEFAULT NULL,
  `cf_garante2` varchar(16) DEFAULT NULL,
  `garanzia_soggetta_iva` tinyint(1) NOT NULL DEFAULT 0,
  `numero_allegato_a` int(11) NOT NULL DEFAULT 0,
  `numero_allegato_b` int(11) NOT NULL DEFAULT 0,
  `numero_allegato_c` int(11) NOT NULL DEFAULT 0,
  `imposta_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `imposta_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sanzione_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sanzione_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interessi_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interessi_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ufficio_registrazione` varchar(10) DEFAULT NULL,
  `anno_registrazione` varchar(4) DEFAULT NULL,
  `serie_registrazione` varchar(2) DEFAULT NULL,
  `numero_registrazione` varchar(6) DEFAULT NULL,
  `sottonumero_registrazione` varchar(3) DEFAULT NULL,
  `eventi_eccezionali` tinyint(4) DEFAULT NULL,
  `canoni_variabili` text DEFAULT NULL COMMENT 'JSON array',
  `comunica_dati_catastali` tinyint(1) NOT NULL DEFAULT 0,
  `allegato_pdf` longblob DEFAULT NULL,
  `nome_allegato` varchar(255) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `cf_intestatario_cc` varchar(16) DEFAULT NULL,
  `cf_intermediario` varchar(16) DEFAULT NULL,
  `data_impegno_intermediario` date DEFAULT NULL,
  `data_impegno` date DEFAULT NULL,
  `data_invio` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ravvedimento_accettato` tinyint(1) DEFAULT 0,
  `ravvedimento_dettagli_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uuid` (`id_contratto_univoco`),
  KEY `idx_stato` (`stato`),
  KEY `idx_durata` (`durata_dal`,`durata_al`),
  KEY `idx_codice_identificativo` (`codice_identificativo_contratto`),
  KEY `idx_data_stipula` (`data_stipula`),
  KEY `idx_durata_al` (`durata_al`),
  KEY `idx_creatore` (`id_utente_creatore`),
  FULLTEXT KEY `ft_contratti_search` (`id_contratto_univoco`,`codice_identificativo_contratto`),
  CONSTRAINT `fk_contratti_utente` FOREIGN KEY (`id_utente_creatore`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contratti_condivisioni`
--

DROP TABLE IF EXISTS `contratti_condivisioni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratti_condivisioni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) NOT NULL,
  `id_utente` int(11) NOT NULL,
  `permesso` enum('view','edit') NOT NULL DEFAULT 'view',
  `condiviso_da` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contratto_utente` (`id_contratto`,`id_utente`),
  KEY `idx_utente` (`id_utente`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `condiviso_da` (`condiviso_da`),
  CONSTRAINT `contratti_condivisioni_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratti_condivisioni_ibfk_2` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratti_condivisioni_ibfk_3` FOREIGN KEY (`condiviso_da`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contratto_conduttori`
--

DROP TABLE IF EXISTS `contratto_conduttori`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratto_conduttori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) NOT NULL,
  `id_conduttore` int(11) NOT NULL,
  `tipologia_conduttore` tinyint(4) DEFAULT 3,
  `tipo_cessione` tinyint(4) NOT NULL DEFAULT 3,
  `data_inizio` date DEFAULT NULL,
  `data_fine` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_contr_cond` (`id_contratto`,`id_conduttore`),
  KEY `id_conduttore` (`id_conduttore`),
  CONSTRAINT `contratto_conduttori_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratto_conduttori_ibfk_2` FOREIGN KEY (`id_conduttore`) REFERENCES `soggetti` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=453 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contratto_immobili`
--

DROP TABLE IF EXISTS `contratto_immobili`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratto_immobili` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) NOT NULL,
  `id_immobile` int(11) NOT NULL,
  `tipologia_immobile` tinyint(4) NOT NULL DEFAULT 1,
  `intero_porzione` enum('I','P') NOT NULL DEFAULT 'I',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_contr_imm` (`id_contratto`,`id_immobile`),
  KEY `id_immobile` (`id_immobile`),
  CONSTRAINT `contratto_immobili_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratto_immobili_ibfk_2` FOREIGN KEY (`id_immobile`) REFERENCES `immobili` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=204 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contratto_locatori`
--

DROP TABLE IF EXISTS `contratto_locatori`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contratto_locatori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) NOT NULL,
  `id_locatore` int(11) NOT NULL,
  `richiedente` tinyint(1) NOT NULL DEFAULT 0,
  `opzione_cedolare` tinyint(1) NOT NULL DEFAULT 0,
  `percentuale_possesso` decimal(5,2) NOT NULL DEFAULT 100.00,
  `tipo_cessione` tinyint(4) NOT NULL DEFAULT 3 COMMENT '1=Esce, 2=Entra, 3=Rimane',
  `data_inizio` date DEFAULT NULL,
  `data_fine` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_contr_loc` (`id_contratto`,`id_locatore`),
  KEY `id_locatore` (`id_locatore`),
  CONSTRAINT `contratto_locatori_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contratto_locatori_ibfk_2` FOREIGN KEY (`id_locatore`) REFERENCES `soggetti` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=589 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `eventi_contratto`
--

DROP TABLE IF EXISTS `eventi_contratto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `eventi_contratto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) NOT NULL,
  `tipo` varchar(30) NOT NULL COMMENT 'registrazione, proroga, cessione, risoluzione, subentro, annualita, conguaglio, rinegoziazione',
  `data_evento` date DEFAULT NULL,
  `data_invio` date DEFAULT NULL,
  `dettagli_json` text DEFAULT NULL,
  `allegato_pdf` longblob DEFAULT NULL,
  `nome_allegato` varchar(255) DEFAULT NULL,
  `imposta_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `imposta_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sanzione_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sanzione_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interessi_registro` decimal(10,2) NOT NULL DEFAULT 0.00,
  `interessi_bollo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `xml_generato` mediumtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_data_evento` (`data_evento`),
  CONSTRAINT `eventi_contratto_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `immobili`
--

DROP TABLE IF EXISTS `immobili`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comuni` (
  `codice_belfiore` char(4) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `provincia` char(2) NOT NULL,
  `soppresso` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`codice_belfiore`),
  KEY `idx_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `immobili`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `immobili` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identificativo_interno` varchar(100) NOT NULL,
  `codice_comune` varchar(5) DEFAULT NULL,
  `provincia` varchar(2) DEFAULT NULL,
  `sezione` varchar(3) DEFAULT NULL,
  `tipo_catasto` enum('U','T') NOT NULL DEFAULT 'U',
  `categoria` varchar(4) DEFAULT NULL,
  `is_pertinenza` tinyint(1) NOT NULL DEFAULT 0,
  `comune` varchar(40) NOT NULL,
  `foglio` varchar(4) NOT NULL,
  `particella` varchar(5) NOT NULL,
  `subalterno` varchar(4) DEFAULT NULL,
  `rendita` decimal(10,2) DEFAULT NULL,
  `reddito_dominicale` decimal(10,2) DEFAULT NULL,
  `toponimo` varchar(20) DEFAULT NULL,
  `indirizzo` varchar(100) DEFAULT NULL,
  `civico` varchar(10) DEFAULT NULL,
  `in_via_di_accatastamento` tinyint(1) NOT NULL DEFAULT 0,
  `zona_censuaria` varchar(3) DEFAULT NULL,
  `classe` varchar(2) DEFAULT NULL,
  `consistenza` decimal(10,2) DEFAULT NULL,
  `partita_tavolare` varchar(7) DEFAULT NULL,
  `corpo_tavolare` varchar(3) DEFAULT NULL,
  `porzione_materiale` varchar(4) DEFAULT NULL,
  `superficie_ettari` int(11) DEFAULT NULL,
  `superficie_are` int(11) DEFAULT NULL,
  `superficie_centiare` int(11) DEFAULT NULL,
  `natura` varchar(3) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comune` (`comune`),
  KEY `idx_identificativo` (`identificativo_interno`),
  KEY `idx_codice_comune` (`codice_comune`),
  FULLTEXT KEY `ft_immobili_search` (`comune`,`indirizzo`,`note`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `impostazioni`
--

DROP TABLE IF EXISTS `impostazioni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `impostazioni` (
  `chiave` varchar(50) NOT NULL,
  `valore` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chiave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locatori`
--

DROP TABLE IF EXISTS `locatori`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locatori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codice_fiscale` varchar(16) NOT NULL,
  `cognome` varchar(100) DEFAULT NULL,
  `nome` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `log_attivita`
--

DROP TABLE IF EXISTS `log_attivita`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_attivita` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_utente` int(11) DEFAULT NULL,
  `data_ora` datetime DEFAULT current_timestamp(),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `azione` varchar(50) NOT NULL,
  `entita` varchar(50) DEFAULT NULL,
  `id_entita` int(11) DEFAULT NULL,
  `dettagli` text DEFAULT NULL,
  `esito` enum('ok','errore') DEFAULT 'ok',
  PRIMARY KEY (`id`),
  KEY `idx_data` (`data_ora`),
  KEY `idx_azione` (`azione`),
  KEY `idx_entita` (`entita`,`id_entita`),
  KEY `idx_utente_log` (`id_utente`),
  CONSTRAINT `fk_log_utente` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=726 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(64) NOT NULL,
  `eseguita_il` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifiche`
--

DROP TABLE IF EXISTS `notifiche`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifiche` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL,
  `id_contratto` int(11) DEFAULT NULL,
  `data_scadenza` date DEFAULT NULL,
  `messaggio` text NOT NULL,
  `priorita` enum('info','warning','critical') DEFAULT 'info',
  `origine` varchar(30) NOT NULL DEFAULT 'manuale',
  `chiave_univoca` varchar(128) DEFAULT NULL,
  `letta` tinyint(1) DEFAULT 0,
  `data_lettura` datetime DEFAULT NULL,
  `data_creazione` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chiave` (`chiave_univoca`),
  KEY `idx_letta` (`letta`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `idx_scadenza` (`data_scadenza`),
  KEY `idx_origine` (`origine`),
  CONSTRAINT `notifiche_ibfk_1` FOREIGN KEY (`id_contratto`) REFERENCES `contratti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pratica_immobili`
--

DROP TABLE IF EXISTS `pratica_immobili`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pratica_immobili` (
  `id_pratica` int(11) NOT NULL,
  `id_immobile` int(11) NOT NULL,
  `tipo_immobile` enum('PRINCIPALE','PERTINENZA') DEFAULT NULL,
  `intero_porzione` varchar(1) DEFAULT 'I',
  `tipologia_immobile` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id_pratica`,`id_immobile`),
  KEY `id_immobile` (`id_immobile`),
  CONSTRAINT `pratica_immobili_ibfk_1` FOREIGN KEY (`id_pratica`) REFERENCES `pratiche_rli` (`id`),
  CONSTRAINT `pratica_immobili_ibfk_2` FOREIGN KEY (`id_immobile`) REFERENCES `immobili` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pratica_soggetti`
--

DROP TABLE IF EXISTS `pratica_soggetti`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pratica_soggetti` (
  `id_pratica` int(11) NOT NULL,
  `id_soggetto` int(11) NOT NULL,
  `tipo_soggetto` enum('LOCATORE','CONDUTTORE') NOT NULL,
  `percentuale_possesso` decimal(5,2) DEFAULT NULL,
  `richiedente` tinyint(1) DEFAULT 0,
  `opzione_cedolare` tinyint(1) DEFAULT 0,
  `tipo_cessione` tinyint(4) DEFAULT 3,
  `cedente_cessionario` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id_pratica`,`id_soggetto`,`tipo_soggetto`),
  UNIQUE KEY `idx_pratica_sogg` (`id_pratica`,`id_soggetto`),
  KEY `id_soggetto` (`id_soggetto`),
  CONSTRAINT `pratica_soggetti_ibfk_1` FOREIGN KEY (`id_pratica`) REFERENCES `pratiche_rli` (`id`),
  CONSTRAINT `pratica_soggetti_ibfk_2` FOREIGN KEY (`id_soggetto`) REFERENCES `soggetti` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pratiche_rli`
--

DROP TABLE IF EXISTS `pratiche_rli`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pratiche_rli` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto_univoco` varchar(50) DEFAULT NULL,
  `ufficio_territoriale` varchar(4) DEFAULT NULL,
  `tipologia_contratto` varchar(2) DEFAULT NULL,
  `data_stipula` date DEFAULT NULL,
  `durata_dal` date DEFAULT NULL,
  `durata_al` date DEFAULT NULL,
  `data_fine_proroga` date DEFAULT NULL,
  `data_cessione` date DEFAULT NULL,
  `data_subentro` date DEFAULT NULL,
  `importo_canone` decimal(12,2) DEFAULT NULL,
  `numero_pagine` int(11) DEFAULT 0,
  `numero_copie` int(11) DEFAULT 0,
  `pagamento_intero_periodo` tinyint(1) DEFAULT 0,
  `casi_particolari` tinyint(1) DEFAULT 0,
  `agevolazioni` tinyint(1) DEFAULT 0,
  `contratto_esente` tinyint(4) DEFAULT NULL,
  `pagamento_intera_durata` tinyint(1) DEFAULT 0,
  `corrispettivo` decimal(10,2) DEFAULT NULL,
  `tipologia_subentro` tinyint(4) DEFAULT NULL,
  `tipologia_proroga` tinyint(4) DEFAULT NULL,
  `annualita` varchar(4) DEFAULT NULL,
  `tardivita_annualita` tinyint(4) DEFAULT NULL,
  `tipo_adempimento` tinyint(1) DEFAULT NULL,
  `cedolare_secca` tinyint(1) DEFAULT 0,
  `data_creazione` timestamp NULL DEFAULT current_timestamp(),
  `imposta_registro` decimal(12,2) DEFAULT 0.00,
  `imposta_bollo` decimal(12,2) DEFAULT 0.00,
  `sanzione_registro` decimal(10,2) DEFAULT 0.00,
  `sanzione_bollo` decimal(10,2) DEFAULT 0.00,
  `interessi_registro` decimal(10,2) DEFAULT 0.00,
  `interessi_bollo` decimal(10,2) DEFAULT 0.00,
  `sanzioni` decimal(12,2) DEFAULT 0.00,
  `data_impegno` date DEFAULT NULL,
  `data_invio` date DEFAULT NULL,
  `interessi` decimal(12,2) DEFAULT 0.00,
  `ufficio_competente` varchar(4) DEFAULT NULL,
  `allegato_pdf` longblob DEFAULT NULL,
  `nome_allegato` varchar(255) DEFAULT NULL,
  `condizione_sospensiva` tinyint(1) DEFAULT 0,
  `contratto_tempo_indeterminato` tinyint(1) DEFAULT 0,
  `clausola_penale_volontaria` tinyint(1) DEFAULT 0,
  `contratto_soggetto_iva` tinyint(1) DEFAULT 0,
  `contratto_non_allegato` tinyint(1) DEFAULT 0,
  `tipo_garanzia` tinyint(4) DEFAULT NULL,
  `importo_garanzia` decimal(10,2) DEFAULT NULL,
  `cf_garante` varchar(16) DEFAULT NULL,
  `cf_garante2` varchar(16) DEFAULT NULL,
  `garanzia_soggetta_iva` tinyint(1) DEFAULT 0,
  `numero_allegato_a` int(11) DEFAULT 0,
  `numero_allegato_b` int(11) DEFAULT 0,
  `numero_allegato_c` int(11) DEFAULT 0,
  `eventi_eccezionali` tinyint(4) DEFAULT NULL,
  `ufficio_registrazione` varchar(10) DEFAULT NULL,
  `anno_registrazione` varchar(4) DEFAULT NULL,
  `serie_registrazione` varchar(2) DEFAULT NULL,
  `numero_registrazione` varchar(6) DEFAULT NULL,
  `sottonumero_registrazione` varchar(3) DEFAULT NULL,
  `canoni_variabili` text DEFAULT NULL,
  `comunica_dati_catastali` tinyint(1) DEFAULT 0,
  `rinegoziazione_tipo` varchar(10) DEFAULT 'aumento',
  `data_inizio_rinegoziazione` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `proprieta_immobili`
--

DROP TABLE IF EXISTS `proprieta_immobili`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proprieta_immobili` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_locatore` int(11) NOT NULL,
  `id_immobile` int(11) NOT NULL,
  `percentuale` decimal(5,2) NOT NULL DEFAULT 100.00,
  `data_inizio` date DEFAULT NULL,
  `data_fine` date DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_loc_imm` (`id_locatore`,`id_immobile`),
  KEY `idx_immobile` (`id_immobile`),
  CONSTRAINT `proprieta_immobili_ibfk_1` FOREIGN KEY (`id_locatore`) REFERENCES `soggetti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `proprieta_immobili_ibfk_2` FOREIGN KEY (`id_immobile`) REFERENCES `immobili` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rli_import_log`
--

DROP TABLE IF EXISTS `rli_import_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rli_import_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contratto` int(11) DEFAULT NULL,
  `nome_file` varchar(255) NOT NULL,
  `id_contratto_univoco` varchar(100) DEFAULT NULL,
  `codice_ade` varchar(50) DEFAULT NULL,
  `esito` enum('ok','skip','errore') NOT NULL,
  `messaggio` text DEFAULT NULL,
  `creati_json` longtext DEFAULT NULL,
  `collegati_json` longtext DEFAULT NULL,
  `user_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contratto` (`id_contratto`),
  KEY `idx_esito` (`esito`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `soggetti`
--

DROP TABLE IF EXISTS `soggetti`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `soggetti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codice_fiscale` varchar(16) NOT NULL,
  `cognome` varchar(80) NOT NULL,
  `nome` varchar(80) DEFAULT NULL,
  `sesso` char(1) DEFAULT NULL,
  `data_nascita` date DEFAULT NULL,
  `comune_nascita` varchar(255) DEFAULT NULL,
  `prov_nascita` varchar(2) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pec` varchar(150) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `tipologia_default` enum('LOCATORE','CONDUTTORE','ENTRAMBI') NOT NULL DEFAULT 'ENTRAMBI',
  `tipologia_conduttore` tinyint(4) DEFAULT NULL COMMENT '1=Coop/Studenti, 2=CF provvisorio, 3=Generico',
  `soggettivita_iva` tinyint(1) NOT NULL DEFAULT 0,
  `cf_rappresentante` varchar(16) DEFAULT NULL,
  `cognome_rappresentante` varchar(80) DEFAULT NULL,
  `nome_rappresentante` varchar(80) DEFAULT NULL,
  `codice_carica` varchar(2) DEFAULT NULL,
  `id_rappresentante` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_cf` (`codice_fiscale`),
  KEY `idx_cognome` (`cognome`),
  KEY `idx_cognome_nome` (`cognome`,`nome`),
  KEY `idx_rappresentante` (`id_rappresentante`),
  FULLTEXT KEY `ft_soggetti_search` (`cognome`,`nome`,`email`,`pec`,`note`),
  CONSTRAINT `fk_soggetti_rappresentante` FOREIGN KEY (`id_rappresentante`) REFERENCES `soggetti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tassi_interesse_legale`
--

DROP TABLE IF EXISTS `tassi_interesse_legale`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tassi_interesse_legale` (
  `anno` int(11) NOT NULL,
  `tasso` decimal(6,4) NOT NULL,
  PRIMARY KEY (`anno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `template_email`
--

DROP TABLE IF EXISTS `template_email`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_email` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codice` varchar(50) NOT NULL,
  `nome` varchar(255) NOT NULL,
  `oggetto` varchar(255) NOT NULL,
  `corpo` text NOT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codice` (`codice`),
  KEY `idx_codice` (`codice`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `utenti`
--

DROP TABLE IF EXISTS `utenti`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `utenti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cognome` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `cf_intermediario` varchar(16) DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_user` varchar(255) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_from` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(255) DEFAULT NULL,
  `ruolo` enum('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  `tema` enum('light','dark','auto') NOT NULL DEFAULT 'light',
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_accesso` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_ruolo` (`ruolo`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-12 16:56:36
