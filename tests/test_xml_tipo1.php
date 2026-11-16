<?php
/**
 * Test standalone: genera un XML RLI12 per una prima registrazione (tipo_adempimento = 1)
 * e lo valida contro lo XSD ufficiale tramite XsdValidator.
 *
 * Uso:  php tests/test_xml_tipo1.php
 */

// Costanti di configurazione (stessi valori di config.php)
define('RLI_CF_INTERMEDIARIO', 'RSSMRA80A01H501U'); // CF fittizio (Mario Rossi) per i test
define('RLI_CODICE_FORNITURA', 'RLI12');
define('RLI_TIPO_FORNITORE', '10');
define('RLI_ID_SOFTWARE', '02327910580');
define('RLI_SPAZIO_SERVIZIO', '');

require_once __DIR__ . '/../lib/XmlBuilder.php';
require_once __DIR__ . '/../lib/XsdValidator.php';

// --- Pratica (prima registrazione) ---
$pratica = [
    'id'                     => 1,
    'tipo_adempimento'       => '1',
    'tipologia_adempimento'  => '1',
    'tipologia_contratto'    => 'L1',
    'id_contratto_univoco'   => 'TEST_TIPO1_2024',
    'durata_dal'             => '2024-01-01',
    'durata_al'              => '2028-01-01',
    'importo_canone'         => 12000.00,
    'data_stipula'           => '2024-01-01',
    'numero_pagine'          => 4,
    'numero_copie'           => 2,
    'ufficio_territoriale'   => 'TMD',
    'imposta_registro'       => 0,
    'imposta_bollo'          => 0,
    'sanzione_registro'      => 0,
    'sanzione_bollo'         => 0,
    'interessi_registro'     => 0,
    'interessi_bollo'        => 0,
    'cedolare_secca'         => '1',
    'contratto_soggetto_iva' => 0,
    'data_invio'             => '2024-01-10',
];

// --- Locatore (persona fisica) ---
$locatori = [[
    'id'                   => 10,
    'codice_fiscale'       => 'BNCLGU75C15F205X',
    'cognome'              => 'Bianchi',
    'nome'                 => 'Luigi',
    'sesso'                => 'M',
    'data_nascita'         => '1975-03-15',
    'comune_nascita'       => 'Milano',
    'prov_nascita'         => 'MI',
    'soggettivita_iva'     => 0,
    'percentuale_possesso' => 100,
    'opzione_cedolare'     => 1,
]];

// --- Conduttori: una persona fisica + un soggetto diverso da PF ---
$conduttori = [
    [
        'id'                   => 20,
        'codice_fiscale'       => 'VRDMRA90D20H501Y',
        'cognome'              => 'Verdi',
        'nome'                 => 'Maria',
        'sesso'                => 'F',
        'data_nascita'         => '1990-04-20',
        'comune_nascita'       => 'Roma',
        'prov_nascita'         => 'RM',
        'tipologia_conduttore' => '3',
    ],
    [
        'id'                   => 21,
        'codice_fiscale'       => '12345678901',
        'cognome'              => 'ACME SRL',
        'nome'                 => '',
        'tipologia_conduttore' => '3',
    ],
];

// --- Immobile ---
$immobili = [[
    'tipologia_immobile' => 1,
    'categoria'          => 'A2',
    'rendita'            => 500.00,
    'codice_comune'      => 'H501',
    'tipo_catasto'       => 'U',
    'foglio'             => '10',
    'particella'         => '123',
    'subalterno'         => '5',
    'comune'             => 'Roma',
    'provincia'          => 'RM',
    'toponimo'           => 'Via',
    'indirizzo'          => 'Roma',
    'civico'             => '1',
]];

// --- Richiedente (persona fisica) ---
$richiedente = [
    'codice_fiscale' => 'RSSMRA80A01H501U',
    'cognome'        => 'Rossi',
    'nome'           => 'Mario',
    'email'          => 'mario.rossi@example.com',
    'telefono'       => '3331234567',
];

$idCont = 'TEST_TIPO1_2024';

$payload = [
    'pratica'          => $pratica,
    'locatori'         => $locatori,
    'conduttori'       => $conduttori,
    'immobili'         => $immobili,
    'richiedente'      => $richiedente,
    'id_contratto'     => $idCont,
    'cf_intermediario' => RLI_CF_INTERMEDIARIO,
    'lato_subentro'    => 'entrambi',
    'cedente_id'       => null,
    'cessionari_ids'   => [],
];

try {
    $builder = new XmlBuilder($payload);
    $xml = $builder->build();
} catch (Throwable $e) {
    fwrite(STDERR, "ERRORE build(): " . $e->getMessage() . "\n");
    exit(2);
}

// Salva l'XML generato per ispezione
$outFile = __DIR__ . '/test_xml_tipo1_output.xml';
file_put_contents($outFile, $xml);

// Conteggio moduli effettivi per sezione
$countMod = function (string $tag, string $xml): int {
    return substr_count($xml, "<loc:{$tag}>");
};
$primoModuli = substr_count($xml, '<loc:PrimoModulo>');
$moduli      = substr_count($xml, '<loc:Modulo>');

echo "XML generato: {$outFile}\n";
echo "Nodi <loc:PrimoModulo>: {$primoModuli}\n";
echo "Nodi <loc:Modulo>: {$moduli}\n";
if (preg_match('#<loc:NumeroModuliCompilati>(\d+)</loc:NumeroModuliCompilati>#', $xml, $m)) {
    echo "NumeroModuliCompilati: {$m[1]}\n";
}

// Validazione XSD
$result = XsdValidator::validate($xml);
echo "XSD: " . ($result['xsd'] ?? 'non trovato') . "\n";
echo "Validazione: " . ($result['ok'] ? 'OK' : 'FALLITA') . "\n";

if (!empty($result['errors'])) {
    echo "--- ERRORI (" . count($result['errors']) . ") ---\n";
    foreach ($result['errors'] as $e) {
        echo "  - {$e}\n";
    }
}
if (!empty($result['warnings'])) {
    echo "--- WARNING (" . count($result['warnings']) . ") ---\n";
    foreach ($result['warnings'] as $w) {
        echo "  - {$w}\n";
    }
}

exit($result['ok'] ? 0 : 1);
