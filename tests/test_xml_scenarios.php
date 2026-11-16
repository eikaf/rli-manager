<?php
/**
 * Test multi-scenario del generatore XML RLI12 (DOMDocument) + validazione XSD.
 *
 * Uso:  php tests/test_xml_scenarios.php
 *
 * Scenari verificati:
 *   - prima registrazione (persona fisica)
 *   - prima registrazione (conduttore società)
 *   - proroga con cedolare secca
 *   - cessione lato locatore
 *   - subentro lato conduttore
 *   - contratto multi-modulo (> 4 soggetti / immobili)
 */
define('RLI_CF_INTERMEDIARIO', 'RSSMRA80A01H501U'); // CF fittizio (Mario Rossi) per i test
define('RLI_CODICE_FORNITURA', 'RLI12');
define('RLI_TIPO_FORNITORE', '10');
define('RLI_ID_SOFTWARE', '02327910580');
define('RLI_SPAZIO_SERVIZIO', '');

require_once __DIR__ . '/../lib/XmlBuilder.php';
require_once __DIR__ . '/../lib/XsdValidator.php';

function pf(string $cf, string $cognome, string $nome, string $sesso = 'M', string $data = '1980-01-01', string $comune = 'Roma', string $prov = 'RM'): array
{
    return [
        'codice_fiscale' => $cf,
        'cognome' => $cognome,
        'nome' => $nome,
        'sesso' => $sesso,
        'data_nascita' => $data,
        'comune_nascita' => $comune,
        'prov_nascita' => $prov,
        'soggettivita_iva' => 0,
        'percentuale_possesso' => 100,
        'opzione_cedolare' => 0,
        'tipologia_conduttore' => '3',
    ];
}

function immobile(int $n = 1): array
{
    return [
        'tipologia_immobile' => 1,
        'categoria' => 'A2',
        'rendita' => 500.00,
        'codice_comune' => 'H501',
        'tipo_catasto' => 'U',
        'foglio' => (string)$n,
        'particella' => '123',
        'subalterno' => '5',
        'comune' => 'Roma',
        'provincia' => 'RM',
        'toponimo' => 'Via',
        'indirizzo' => 'Roma',
        'civico' => (string)$n,
    ];
}

$richiedente = [
    'codice_fiscale' => 'RSSMRA80A01H501U',
    'cognome' => 'Rossi',
    'nome' => 'Mario',
    'email' => 'mario.rossi@example.com',
    'telefono' => '3331234567',
];

function payload(array $pratica, array $locatori, array $conduttori, array $immobili, ?array $richiedente, string $id, string $lato = 'entrambi'): array
{
    return [
        'pratica' => $pratica,
        'locatori' => $locatori,
        'conduttori' => $conduttori,
        'immobili' => $immobili,
        'richiedente' => $richiedente,
        'id_contratto' => $id,
        'cf_intermediario' => RLI_CF_INTERMEDIARIO,
        'lato_subentro' => $lato,
        'cedente_id' => null,
        'cessionari_ids' => [],
    ];
}

$scenari = [];

// 1. Prima registrazione - persona fisica
$scenari['tipo1_pf'] = payload([
    'id' => 1, 'tipo_adempimento' => '1', 'tipologia_adempimento' => '1', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_TIPO1_PF', 'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'importo_canone' => 12000, 'data_stipula' => '2024-01-01', 'numero_pagine' => 4, 'numero_copie' => 2,
    'ufficio_territoriale' => 'TMD', 'cedolare_secca' => '1', 'contratto_soggetto_iva' => 0, 'data_invio' => '2024-01-10',
], [pf('BNCLGU75C15F205X', 'Bianchi', 'Luigi')], [pf('VRDMRA90D20H501Y', 'Verdi', 'Maria', 'F')], [immobile(), ], $richiedente, 'TEST_TIPO1_PF');

// 2. Prima registrazione - conduttore società
$soc = ['codice_fiscale' => '12345678901', 'cognome' => 'ACME SRL', 'nome' => '', 'tipologia_conduttore' => '3'];
$scenari['tipo1_societa'] = payload([
    'id' => 2, 'tipo_adempimento' => '1', 'tipologia_adempimento' => '1', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_TIPO1_SOC', 'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'importo_canone' => 12000, 'data_stipula' => '2024-01-01', 'numero_pagine' => 4, 'numero_copie' => 2,
    'ufficio_territoriale' => 'TMD', 'cedolare_secca' => '1', 'contratto_soggetto_iva' => 0, 'data_invio' => '2024-01-10',
], [pf('BNCLGU75C15F205X', 'Bianchi', 'Luigi')], [$soc], [immobile()], $richiedente, 'TEST_TIPO1_SOC');

// 3. Proroga con cedolare secca (niente conduttori)
$locProroga = pf('BNCLGU75C15F205X', 'Bianchi', 'Luigi');
$locProroga['opzione_cedolare'] = 1;
$scenari['proroga_cedolare'] = payload([
    'id' => 3, 'tipo_adempimento' => '2', 'tipologia_adempimento' => '2', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_PROROGA', 'codice_identificativo_contratto' => 'TMD26T001234000AA',
    'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'data_fine_proroga' => '2029-01-01', 'importo_canone' => 12000, 'numero_pagine' => 4, 'numero_copie' => 2,
    'ufficio_territoriale' => 'TMD', 'cedolare_secca' => '1', 'data_invio' => '2024-01-10',
], [$locProroga], [], [immobile()], $richiedente, 'TEST_PROROGA');

// 4. Cessione lato locatore
$ced = pf('BNCLGU75C15F205X', 'Bianchi', 'Luigi'); $ced['cedente_ruolo'] = 1;
$cess = pf('RSSMRA80A01H501U', 'Rossi', 'Mario'); $cess['cedente_ruolo'] = 2;
$scenari['cessione_locatore'] = payload([
    'id' => 4, 'tipo_adempimento' => '3', 'tipologia_adempimento' => '3', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_CESSIONE', 'codice_identificativo_contratto' => 'TMD26T001234000AA',
    'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'data_cessione' => '2025-01-01', 'importo_canone' => 12000, 'numero_pagine' => 4, 'numero_copie' => 2,
    'ufficio_territoriale' => 'TMD', 'comunica_dati_catastali' => 1, 'data_invio' => '2024-01-10',
], [$ced, $cess], [], [immobile()], $richiedente, 'TEST_CESSIONE', 'locatore');

// 5. Subentro lato conduttore
$cedC = pf('VRDMRA90D20H501Y', 'Verdi', 'Maria', 'F'); $cedC['cedente_ruolo'] = 1;
$subC = pf('RSSMRA80A01H501U', 'Rossi', 'Mario'); $subC['cedente_ruolo'] = 2;
$scenari['subentro_conduttore'] = payload([
    'id' => 5, 'tipo_adempimento' => '6', 'tipologia_adempimento' => '6', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_SUBENTRO', 'codice_identificativo_contratto' => 'TMD26T001234000AA',
    'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'data_subentro' => '2025-01-01', 'tipologia_subentro' => 1, 'importo_canone' => 12000,
    'numero_pagine' => 4, 'numero_copie' => 2, 'ufficio_territoriale' => 'TMD',
    'comunica_dati_catastali' => 1, 'data_invio' => '2024-01-10',
], [pf('BNCLGU75C15F205X', 'Bianchi', 'Luigi')], [$cedC, $subC], [immobile()], $richiedente, 'TEST_SUBENTRO', 'conduttore');

// 6. Multi-modulo: 5 locatori, 5 conduttori, 5 immobili
$locMulti = [];
$cfsLoc = ['BNCLGU75C15F205X', 'RSSMRA80A01H501U', 'VRDMRA90D20H501Y', 'FRNGNN70A01F205X', 'PLTMRC66B02H501Z'];
foreach ($cfsLoc as $i => $cf) $locMulti[] = pf($cf, 'Loc' . ($i + 1), 'Nome' . ($i + 1));
$condMulti = [];
$cfsCond = ['MRNLCU75A01F205X', 'GRGPPA80A01H501U', 'LNZMRA90D20H501Y', 'BNCDVD70A01F205X', 'RSSLCA66B02H501Z'];
foreach ($cfsCond as $i => $cf) $condMulti[] = pf($cf, 'Cond' . ($i + 1), 'Nome' . ($i + 1));
$immMulti = [immobile(1), immobile(2), immobile(3), immobile(4), immobile(5)];
$scenari['multi_modulo'] = payload([
    'id' => 6, 'tipo_adempimento' => '1', 'tipologia_adempimento' => '1', 'tipologia_contratto' => 'L1',
    'id_contratto_univoco' => 'TEST_MULTI', 'durata_dal' => '2024-01-01', 'durata_al' => '2028-01-01',
    'importo_canone' => 24000, 'data_stipula' => '2024-01-01', 'numero_pagine' => 8, 'numero_copie' => 2,
    'ufficio_territoriale' => 'TMD', 'cedolare_secca' => '1', 'contratto_soggetto_iva' => 0, 'data_invio' => '2024-01-10',
], $locMulti, $condMulti, $immMulti, $richiedente, 'TEST_MULTI');

$exitCode = 0;
foreach ($scenari as $nome => $pl) {
    try {
        $xml = (new XmlBuilder($pl))->build();
        // Salva l'XML generato per ispezione manuale (utile in caso di errore).
        file_put_contents(__DIR__ . '/out_' . $nome . '.xml', $xml);
        $res = XsdValidator::validate($xml);
        $status = $res['ok'] ? 'OK' : 'FALLITA';
        echo str_pad($nome, 24) . " {$status}";
        if (!$res['ok']) {
            echo "  -> XML: tests/out_{$nome}.xml";
        }
        echo "\n";
        if (!$res['ok']) {
            $exitCode = 1;
            foreach (array_slice($res['errors'], 0, 8) as $e) {
                echo "   - {$e}\n";
            }
        }
        if (!empty($res['warnings'])) {
            echo "   warning: " . count($res['warnings']) . "\n";
        }
    } catch (Throwable $e) {
        $exitCode = 1;
        echo str_pad($nome, 24) . " ERRORE: " . $e->getMessage() . "\n";
    }
}

exit($exitCode);