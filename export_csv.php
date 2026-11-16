<?php
/**
 * export_csv.php — Esporta dati in CSV.
 *
 * Tipo di export (parametro GET ?tipo=):
 *   - contratti
 *   - soggetti
 *   - immobili
 *   - proprieta
 *
 * Filtri opzionali (dal contesto):
 *   - per contratti: stato, tipologia
 *   - per soggetti: tipologia_default
 *
 * Sicurezza:
 *   - Solo utenti loggati
 *   - Rispetta visibilità (operator/viewer vedono solo propri + condivisi)
 */
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/ContrattoRepository.php';

$auth->requireLogin();

$tipo = $_GET['tipo'] ?? 'contratti';

// =============================================================
// HELPER: output CSV
// =============================================================
function outputCsv(string $filename, array $headers, array $rows) {
    if (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // BOM UTF-8 per Excel
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Intestazioni
    fputcsv($output, $headers, ';', '"');
    
    // Righe
    foreach ($rows as $row) {
        fputcsv($output, $row, ';', '"');
    }
    
    fclose($output);
    exit;
}

// =============================================================
// EXPORT CONTRATTI
// =============================================================
if ($tipo === 'contratti') {
    $filters = [];
    if (!empty($_GET['stato'])) $filters['stato'] = $_GET['stato'];
    if (!empty($_GET['tipologia'])) $filters['tipologia'] = $_GET['tipologia'];
    
    $repo = new ContrattoRepository($pdo);
    // Carica TUTTI i contratti visibili (senza paginazione)
    $result = $repo->searchAndPaginate($filters, 100000, 0, $auth);
    $contratti = $result['data'];
    
    $headers = [
        'ID', 'ID Contratto', 'Codice AdE', 'Stato', 'Tipologia',
        'Data Stipula', 'Durata Dal', 'Durata Al', 'Canone Annuo',
        'Cedolare', 'Locatori', 'Conduttori', 'Immobili',
        'Data Invio', 'Numero Pagine', 'Numero Copie',
    ];
    
    $rows = [];
    foreach ($contratti as $c) {
        $rows[] = [
            $c['id'],
            $c['id_contratto_univoco'] ?? '',
            $c['codice_identificativo_contratto'] ?? '',
            $c['stato'] ?? '',
            $c['tipologia_contratto'] ?? '',
            $c['data_stipula'] ?? '',
            $c['durata_dal'] ?? '',
            $c['durata_al'] ?? '',
            $c['importo_canone'] ? number_format((float)$c['importo_canone'], 2, ',', '') : '',
            $c['cedolare_secca'] == 1 ? 'Sì' : ($c['cedolare_secca'] == 2 ? 'Mista' : 'No'),
            $c['locatori_list'] ?? '',
            $c['conduttori_list'] ?? '',
            '', // Immobili - non disponibile nel summary
            $c['data_invio'] ?? '',
            $c['numero_pagine'] ?? '',
            $c['numero_copie'] ?? '',
        ];
    }
    
    outputCsv('contratti_' . date('Ymd_His') . '.csv', $headers, $rows);
}

// =============================================================
// EXPORT SOGGETTI
// =============================================================
if ($tipo === 'soggetti') {
    $where = [];
    $params = [];
    
    if (!empty($_GET['tipologia'])) {
        $where[] = 'tipologia_default = ?';
        $params[] = $_GET['tipologia'];
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("
        SELECT * FROM soggetti
        $whereClause
        ORDER BY cognome, nome
    ");
    $stmt->execute($params);
    $soggetti = $stmt->fetchAll();
    
    $headers = [
        'ID', 'Codice Fiscale', 'Cognome', 'Nome', 'Sesso', 'Data Nascita',
        'Comune Nascita', 'Provincia Nascita', 'Tipologia', 'Tipo Conduttore',
        'Email', 'Telefono', 'Soggettività IVA',
        'CF Rappresentante', 'Cognome Rappr.', 'Nome Rappr.', 'Codice Carica',
        'Note',
    ];
    
    $rows = [];
    foreach ($soggetti as $s) {
        $rows[] = [
            $s['id'],
            $s['codice_fiscale'] ?? '',
            $s['cognome'] ?? '',
            $s['nome'] ?? '',
            $s['sesso'] ?? '',
            $s['data_nascita'] ?? '',
            $s['comune_nascita'] ?? '',
            $s['prov_nascita'] ?? '',
            $s['tipologia_default'] ?? '',
            $s['tipologia_conduttore'] ?? '',
            $s['email'] ?? '',
            $s['telefono'] ?? '',
            $s['soggettivita_iva'] ? 'Sì' : 'No',
            $s['cf_rappresentante'] ?? '',
            $s['cognome_rappresentante'] ?? '',
            $s['nome_rappresentante'] ?? '',
            $s['codice_carica'] ?? '',
            $s['note'] ?? '',
        ];
    }
    
    outputCsv('soggetti_' . date('Ymd_His') . '.csv', $headers, $rows);
}

// =============================================================
// EXPORT IMMOBILI
// =============================================================
if ($tipo === 'immobili') {
    $immobili = $pdo->query("SELECT * FROM immobili ORDER BY identificativo_interno")->fetchAll();
    
    $headers = [
        'ID', 'Identificativo Interno', 'Codice Comune', 'Comune', 'Provincia',
        'Sezione', 'Tipo Catasto', 'Categoria', 'Foglio', 'Particella', 'Subalterno',
        'Rendita', 'Toponimo', 'Indirizzo', 'Civico',
        'In Via Accatastamento',
        'Partita Tavolare', 'Corpo Tavolare', 'Porzione Materiale',
        'Superficie Ettari', 'Superficie Are', 'Superficie Centiare',
        'Zona Censuaria', 'Classe', 'Consistenza', 'Natura',
        'Reddito Dominicale', 'Note',
    ];
    
    $rows = [];
    foreach ($immobili as $i) {
        $rows[] = [
            $i['id'],
            $i['identificativo_interno'] ?? '',
            $i['codice_comune'] ?? '',
            $i['comune'] ?? '',
            $i['provincia'] ?? '',
            $i['sezione'] ?? '',
            $i['tipo_catasto'] ?? '',
            $i['categoria'] ?? '',
            $i['foglio'] ?? '',
            $i['particella'] ?? '',
            $i['subalterno'] ?? '',
            $i['rendita'] ?? '',
            $i['toponimo'] ?? '',
            $i['indirizzo'] ?? '',
            $i['civico'] ?? '',
            $i['in_via_di_accatastamento'] ? 'Sì' : 'No',
            $i['partita_tavolare'] ?? '',
            $i['corpo_tavolare'] ?? '',
            $i['porzione_materiale'] ?? '',
            $i['superficie_ettari'] ?? '',
            $i['superficie_are'] ?? '',
            $i['superficie_centiare'] ?? '',
            $i['zona_censuaria'] ?? '',
            $i['classe'] ?? '',
            $i['consistenza'] ?? '',
            $i['natura'] ?? '',
            $i['reddito_dominicale'] ?? '',
            $i['note'] ?? '',
        ];
    }
    
    outputCsv('immobili_' . date('Ymd_His') . '.csv', $headers, $rows);
}

// =============================================================
// EXPORT PROPRIETÀ
// =============================================================
if ($tipo === 'proprieta') {
    $stmt = $pdo->query("
        SELECT p.*, s.cognome, s.nome, s.codice_fiscale, i.identificativo_interno, i.comune
        FROM proprieta_immobili p
        JOIN soggetti s ON s.id = p.id_locatore
        JOIN immobili i ON i.id = p.id_immobile
        ORDER BY s.cognome, s.nome, i.identificativo_interno
    ");
    $proprieta = $stmt->fetchAll();
    
    $headers = [
        'ID', 'ID Locatore', 'Locatore', 'CF Locatore',
        'ID Immobile', 'Immobile', 'Comune',
        'Percentuale', 'Data Inizio', 'Data Fine',
    ];
    
    $rows = [];
    foreach ($proprieta as $p) {
        $rows[] = [
            $p['id'],
            $p['id_locatore'],
            $p['cognome'] . ' ' . $p['nome'],
            $p['codice_fiscale'] ?? '',
            $p['id_immobile'],
            $p['identificativo_interno'] ?? '',
            $p['comune'] ?? '',
            $p['percentuale'] ?? '',
            $p['data_inizio'] ?? '',
            $p['data_fine'] ?? '',
        ];
    }
    
    outputCsv('proprieta_' . date('Ymd_His') . '.csv', $headers, $rows);
}

http_response_code(400);
die('Tipo di export non riconosciuto. Valori validi: contratti, soggetti, immobili, proprieta.');
