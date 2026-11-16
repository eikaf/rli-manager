<?php
/**
 * RliParser — Parsing di un file RLI12 (.rli / .xml) in struttura PHP.
 *
 * Versione con query XPath ASSOLUTE sul documento.
 * Funziona su PHP 7.4 + libxml 2.9.x anche con file di grandi dimensioni
 * (base64 <reg:ImageData> di 2+ MB).
 *
 * Supporta namespace:
 *   loc = urn:www.agenziaentrate.gov.it:specificheTecniche:sco:loc:v1
 *   reg = urn:www.agenziaentrate.gov.it:specificheTecniche:sco:reg:v1
 *   sc  = urn:www.agenziaentrate.gov.it:specificheTecniche:sco:common:v2
 *   cm  = urn:www.agenziaentrate.gov.it:specificheTecniche:common:v2
 */
class RliParser
{
    private const NS = [
        'loc' => 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:loc:v1',
        'reg' => 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:reg:v1',
        'sc'  => 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:common:v2',
        'cm'  => 'urn:www.agenziaentrate.gov.it:specificheTecniche:common:v2',
    ];

    /** @var DOMDocument|null */
    private $dom = null;

    /** @var DOMXPath|null */
    private $xpath = null;

    public function __construct() {}

    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File non trovato: {$filePath}");
        }
        if (!is_readable($filePath)) {
            throw new RuntimeException("File non leggibile: {$filePath}");
        }
        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException("File vuoto o non leggibile: {$filePath}");
        }
        return $this->parseString($content, $filePath);
    }

    public function parseString(string $xml, string $origName = '(string)'): array
    {
        // 1. Rimuovi BOM UTF-8
        if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
            $xml = substr($xml, 3);
        }
        $xml = ltrim($xml);

        // 2. Salta caratteri prima di <
        $firstTagPos = strpos($xml, '<');
        if ($firstTagPos !== false && $firstTagPos > 0) {
            $xml = substr($xml, $firstTagPos);
        }

        // 3. Normalizza dichiarazione XML
        if (preg_match('/^<\?xml[^?]*\?>/i', $xml, $m)) {
            $encoding = 'UTF-8';
            if (preg_match('/encoding="([^"]*)"/i', $m[0], $encMatch)) {
                $encoding = $encMatch[1];
            }
            $xml = preg_replace('/^<\?xml[^?]*\?>/i', '<?xml version="1.0" encoding="' . $encoding . '"?>', $xml, 1);
        }

        // 4. Tentativi di parsing
        $this->dom = null;
        $loaded = false;
        $errors = [];

        $optsHuge = defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0;
        $tentativi = [
            ['opzioni' => LIBXML_NONET | LIBXML_NOBLANKS | $optsHuge, 'label' => 'NONET|NOBLANKS|PARSEHUGE'],
            ['opzioni' => LIBXML_NONET | $optsHuge,                   'label' => 'NONET|PARSEHUGE'],
            ['opzioni' => $optsHuge,                                  'label' => 'PARSEHUGE'],
            ['opzioni' => LIBXML_NONET | LIBXML_NOBLANKS,             'label' => 'NONET|NOBLANKS'],
            ['opzioni' => LIBXML_NONET,                               'label' => 'NONET'],
            ['opzioni' => 0,                                          'label' => 'nessuna'],
        ];

        foreach ($tentativi as $t) {
            libxml_clear_errors();
            $prev = libxml_use_internal_errors(true);

            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $ok = $dom->loadXML($xml, $t['opzioni']);
            $errs = libxml_get_errors();

            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            $root = $this->getDocumentElement($dom);
            if ($ok && $root !== null) {
                $this->dom = $dom;
                $loaded = true;
                break;
            }
            if (empty($errors)) {
                $errors = $errs;
            }
        }

        if (!$loaded || $this->dom === null) {
            $msg = "Errore parsing XML di '{$origName}':\n";
            $msg .= "- Dimensione: " . strlen($xml) . " bytes\n";
            if (!empty($errors)) {
                $msg .= "- Errori libxml (" . count($errors) . "):\n";
                foreach (array_slice($errors, 0, 5) as $e) {
                    $msg .= "    Riga {$e->line}, col {$e->column}: " . trim($e->message) . "\n";
                }
            } else {
                $msg .= "- Nessun errore libxml riportato.\n";
            }
            throw new RuntimeException($msg);
        }

        // 5. Crea XPath e registra namespace ufficiali
        $this->xpath = new DOMXPath($this->dom);
        foreach (self::NS as $prefix => $uri) {
            $this->xpath->registerNamespace($prefix, $uri);
        }

        // 6. Verifica root
        $root = $this->getDocumentElement($this->dom);
        if ($root === null || $root->localName !== 'Fornitura') {
            $trovato = $root ? $root->localName : '(nessun elemento radice)';
            throw new RuntimeException(
                "Elemento radice non riconosciuto. Atteso <loc:Fornitura>, trovato <{$trovato}>."
            );
        }

        // 7. Conta i documenti presenti
        $numDocumenti = $this->xpath->query('//loc:Documento')->length;

        return [
            'intestazione'      => $this->parseIntestazione(),
            'dati_generali'     => $this->parseDatiGenerali(),
            'documenti'         => $this->parseDocumenti(),
            'num_documenti'     => $numDocumenti,
            'raw_xml'           => $xml,
        ];
    }

    /**
     * Fix per PHP 7.4 + libxml 2.9.x: $dom->documentElement risulta "undefined property".
     */
    private function getDocumentElement(DOMDocument $dom): ?DOMElement
    {
        try {
            $el = @$dom->documentElement;
            if ($el instanceof DOMElement) return $el;
        } catch (Throwable $e) {
            // ignora
        }
        foreach ($dom->childNodes as $node) {
            if ($node instanceof DOMElement) return $node;
        }
        return null;
    }

    // ============================================================
    // INTESTAZIONE
    // ============================================================
    private function parseIntestazione(): array
    {
        return [
            'codice_fornitura' => $this->qtext('//loc:Intestazione/loc:CodiceFornitura'),
            'tipo_fornitore'   => $this->qtext('//loc:Intestazione/loc:TipoFornitore'),
            'cf_fornitore'     => $this->qtext('//loc:Intestazione/loc:CodiceFiscaleFornitore'),
            'spazio_servizio'  => $this->qtext('//loc:Intestazione/loc:SpazioServizioTelematico'),
        ];
    }

    private function parseDatiGenerali(): array
    {
        return [
            'ufficio_territoriale' => $this->qtext('//loc:DatiGenerali/loc:UfficioCompetente/loc:UfficioTerritoriale'),
            'importo_da_versare'   => $this->parseImporto($this->qtext('//loc:DatiGenerali/loc:ImportoDaVersare')),
            'iban'                 => $this->qtext('//loc:DatiGenerali/loc:Versamento/reg:IBAN'),
            'cf_intestatario_cc'   => $this->qtext('//loc:DatiGenerali/loc:Versamento/reg:CodiceFiscaleTitolareCC'),
        ];
    }

    // ============================================================
    // DOCUMENTI
    // ============================================================
    private function parseDocumenti(): array
    {
        $documenti = [];
        $nodes = $this->xpath->query('//loc:Documento');
        if ($nodes === false) return $documenti;

        $idx = 0;
        foreach ($nodes as $docNode) {
            $idx++;
            $documenti[] = $this->parseDocumento($docNode, $idx);
        }
        return $documenti;
    }

    /**
     * @param DOMNode $docNode  Nodo <loc:Documento>
     * @param int     $index    1-based: primo documento, secondo documento...
     */
    private function parseDocumento(DOMNode $docNode, int $index = 1): array
    {
        $identificativo = $docNode->attributes && $docNode->attributes->getNamedItem('identificativo')
            ? $docNode->attributes->getNamedItem('identificativo')->nodeValue
            : null;

        // Prefisso XPath per accedere a QUESTO specifico documento
        $docPath = "(//loc:Documento)[{$index}]";

        return [
            'identificativo'   => $identificativo,
            'index'            => $index,
            'doc_path'         => $docPath,
            'frontespizio'     => $this->parseFrontespizio($docPath),
            'soggetti'         => $this->parseSoggetti($docPath),
            'immobili'         => $this->parseImmobili($docPath),
            'relazioni'        => $this->parseRelazioni($docPath),
            'canoni_variabili' => $this->parseCanoniVariabili($docPath),
        ];
    }

    // ============================================================
    // FRONTESPIZIO
    // ============================================================
    private function parseFrontespizio(string $docPath): array
    {
        $f = $docPath . '/loc:Frontespizio';

        return [
            'identificativo_software'       => $this->qtext($f . '/loc:IdentificativoProdSoftware'),
            'numero_moduli_compilati'       => (int)$this->qtext($f . '/loc:NumeroModuliCompilati'),
            'id_contratto'                  => $this->qtext($f . '/loc:IDContratto'),
            'richiedente'                   => $this->parseRichiedente($f),
            'tipologia_contratto'           => $this->qtext($f . '/loc:TipologiaContratto'),
            'data_inizio'                   => $this->parseDate($this->qtext($f . '/loc:DataInizio')),
            'data_fine'                     => $this->parseDate($this->qtext($f . '/loc:DataFine')),
            'importo_canone'                => $this->parseImporto($this->qtext($f . '/loc:ImportoCanone')),
            'contratto_esente'              => $this->qtext($f . '/loc:ContrattoEsente'),
            'contratto_tempo_indeterminato' => $this->qtext($f . '/loc:ContrattoTempoIndeterminato'),
            'condizione_sospensiva'         => $this->qtext($f . '/loc:CondizioneSospensiva'),
            'clausola_penale'               => $this->qtext($f . '/loc:ClausolaPenaleVolontaria'),
            'pagamento_intera_durata'       => $this->qtext($f . '/loc:PagamentoInteraDurataContratto'),
            'casi_particolari'              => $this->qtext($f . '/loc:CasiParticolari'),
            'eventi_eccezionali'            => $this->qtext($f . '/loc:EventiEccezionali'),
            'agevolazioni'                  => $this->qtext($f . '/loc:Agevolazioni'),
            'registrazione'                 => $this->parseRegistrazione($f),
            'ademp_succ'                    => $this->parseAdempSucc($f),
            'impegno_trasmettere'           => $this->parseImpegno($f),
            'data_invio'                    => $this->parseDate($this->qtext($f . '/loc:DataInvio')),
            'importi'                       => $this->parseImporti($f),
        ];
    }

    private function parseRichiedente(string $frontPath): ?array
    {
        $r = $frontPath . '/loc:Richiedente';
        if ($this->xpath->query($r)->length === 0) return null;

        return [
            'codice_fiscale'    => $this->qtext($r . '/loc:CodiceFiscale'),
            'cognome'           => $this->qtext($r . '/loc:PersoneFisiche/loc:Cognome')
                                ?: $this->qtext($r . '/loc:SoggettiDiversiDaPF/loc:Denominazione'),
            'nome'              => $this->qtext($r . '/loc:PersoneFisiche/loc:Nome'),
            'firma'             => $this->qtext($r . '/loc:Firma'),
            'tipologia'         => $this->qtext($r . '/loc:TipologiaRichiedente'),
            'email'             => $this->qtext($r . '/loc:Email'),
            'cf_rappresentante' => $this->qtext($r . '/loc:Rappresentante/loc:CodiceFiscaleRappresentante'),
            'cognome_rapp'      => $this->qtext($r . '/loc:Rappresentante/loc:CognomeRappresentante'),
            'nome_rapp'         => $this->qtext($r . '/loc:Rappresentante/loc:NomeRappresentante'),
            'codice_carica'     => $this->qtext($r . '/loc:Rappresentante/loc:CodiceCarica'),
        ];
    }

    private function parseRegistrazione(string $frontPath): ?array
    {
        $reg = $frontPath . '/loc:Registrazione';
        if ($this->xpath->query($reg)->length === 0) return null;

        return [
            'data_stipula'           => $this->parseDate($this->qtext($reg . '/loc:Contratto/loc:DataStipula')),
            'numero_fogli'           => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroPagine'),
            'numero_pagine'          => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroPagine'),
            'numero_copie'           => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroCopie'),
            'contratto_iva'          => $this->qtext($reg . '/loc:Contratto/loc:ContrattoSoggettoIVA'),
            'numero_allegato_a'      => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroAllegatoA'),
            'numero_allegato_b'      => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroAllegatoB'),
            'numero_allegato_c'      => (int)$this->qtext($reg . '/loc:Contratto/loc:NumeroAllegatoC'),
            'contratto_non_allegato' => $this->qtext($reg . '/loc:ContrattoNonAllegato') !== '',
            'allegato_file_type'     => $this->qtext($reg . '/loc:Allegati/reg:FileType'),
            'allegato_file_name'     => $this->qtext($reg . '/loc:Allegati/reg:FileName'),
        ];
    }

    private function parseAdempSucc(string $frontPath): ?array
    {
        $a = $frontPath . '/loc:AdempSucc';
        if ($this->xpath->query($a)->length === 0) return null;

        return [
            'tipologia_adempimento' => $this->qtext($a . '/loc:TipologiaAdempimento'),
            'cedolare_secca'        => $this->qtext($a . '/loc:CedolareSecca'),
            'cdc'                   => $this->qtext($a . '/loc:CDC'),
            'annualita'             => $this->qtext($a . '/loc:Annualita'),
            'data_fine'             => $this->parseDate($this->qtext($a . '/loc:DataFine')),
            'data_inizio_rineg'     => $this->parseDate($this->qtext($a . '/loc:DataInizioRineg')),
            'corrispettivo'         => $this->parseImporto($this->qtext($a . '/loc:Corrispettivo')),
            'id_telematico'         => $this->qtext($a . '/loc:IDTelematico'),
            'tipologia_subentro'    => $this->qtext($a . '/loc:TipologiaSubentro'),
            'tipologia_proroga'     => $this->qtext($a . '/loc:TipologiaProroga'),
            'tardivita_annualita'   => $this->qtext($a . '/loc:TardivitaAnnualitaSuccessiva'),
            'data_stipula'          => $this->parseDate($this->qtext($a . '/loc:Contratto/loc:DataStipula')),
            'numero_fogli'          => (int)$this->qtext($a . '/loc:Contratto/loc:NumeroPagine'),
            'numero_copie'          => (int)$this->qtext($a . '/loc:Contratto/loc:NumeroCopie'),
        ];
    }

    private function parseImpegno(string $frontPath): ?array
    {
        $i = $frontPath . '/loc:ImpegnoATrasmettere';
        if ($this->xpath->query($i)->length === 0) return null;

        return [
            'cf_intermediario'    => $this->qtext($i . '/reg:CFintermediario'),
            'impegno'             => $this->qtext($i . '/reg:ImpegnoATrasmettere'),
            'data_impegno'        => $this->parseDate($this->qtext($i . '/reg:DataImpegno')),
            'firma_intermediario' => $this->qtext($i . '/reg:FirmaIntermediario'),
        ];
    }

    private function parseImporti(string $frontPath): array
    {
        $base = $frontPath . '/loc:Importi/';
        return [
            'imposta_registro'   => $this->parseImporto($this->qtext($base . 'loc:Imposte/loc:ImpostaDiRegistro')),
            'imposta_bollo'      => $this->parseImporto($this->qtext($base . 'loc:Imposte/loc:ImpostaDiBollo')),
            'sanzione_registro'  => $this->parseImporto($this->qtext($base . 'loc:Sanzioni/loc:ImpostaDiRegistro')),
            'sanzione_bollo'     => $this->parseImporto($this->qtext($base . 'loc:Sanzioni/loc:ImpostaDiBollo')),
            'interessi_registro' => $this->parseImporto($this->qtext($base . 'loc:Interessi/loc:ImpostaDiRegistro')),
            'interessi_bollo'    => $this->parseImporto($this->qtext($base . 'loc:Interessi/loc:ImpostaDiBollo')),
        ];
    }

    // ============================================================
    // SOGGETTI
    // ============================================================
    private function parseSoggetti(string $docPath): array
    {
        $soggetti = ['locatori' => [], 'conduttori' => []];
        $base = $docPath . '/loc:Soggetti';

        $locatori = $this->xpath->query($base . '//loc:Locatore');
        if ($locatori !== false) {
            foreach ($locatori as $loc) {
                $soggetti['locatori'][] = $this->parseSoggetto($loc, 'locatore');
            }
        }

        $conduttori = $this->xpath->query($base . '//loc:Conduttore');
        if ($conduttori !== false) {
            foreach ($conduttori as $cond) {
                $soggetti['conduttori'][] = $this->parseSoggetto($cond, 'conduttore');
            }
        }

        return $soggetti;
    }

    private function parseSoggetto(DOMNode $node, string $ruolo): array
    {
        $isPF = $node->getElementsByTagNameNS(self::NS['loc'], 'PersoneFisiche')->length > 0;

        $cf       = $this->nodeText($node, 'loc:CodiceFiscale');
        $cognome  = '';
        $nome     = '';
        $sesso    = '';
        $dataNasc = null;
        $comNasc  = '';
        $provNasc = '';

        if ($isPF) {
            $cognome  = $this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="Cognome"]');
            $nome     = $this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="Nome"]');
            $sesso    = $this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="Sesso"]');
            $dataNasc = $this->parseDate($this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="DataNascita"]'));
            $comNasc  = $this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="ComuneNascita"]');
            $provNasc = $this->nodeText($node, 'loc:PersoneFisiche/*[local-name()="ProvinciaNascita"]');
        } else {
            $cognome = $this->nodeText($node, 'loc:SoggettiDiversiDaPF/loc:Denominazione');
        }

        return [
            'ruolo'                => $ruolo,
            'progressivo'          => (int)$this->nodeText($node, 'loc:NumeroProgressivo'),
            'codice_fiscale'       => strtoupper($cf),
            'cognome'              => $cognome,
            'nome'                 => $nome,
            'sesso'                => $sesso,
            'data_nascita'         => $dataNasc,
            'comune_nascita'       => $comNasc,
            'prov_nascita'         => $provNasc,
            'tipologia_conduttore' => $this->nodeText($node, 'loc:TipologiaConduttore'),
            'soggettivita_iva'     => $this->nodeText($node, 'loc:SoggettivitaIVA') !== '',
            'non_presente_in_atto' => $this->nodeText($node, 'loc:Locatore') !== '',
            'tipo_cessione'        => $this->nodeText($node, 'loc:Cedente'),
        ];
    }

    // ============================================================
    // IMMOBILI
    // ============================================================
    private function parseImmobili(string $docPath): array
    {
        $immobili = [];
        $base = $docPath . '/loc:Immobili';

        $nodes = $this->xpath->query($base . '//loc:Immobile');
        if ($nodes !== false) {
            foreach ($nodes as $imm) {
                $immobili[] = $this->parseImmobile($imm);
            }
        }
        return $immobili;
    }

    private function parseImmobile(DOMNode $node): array
    {
        return [
            'progressivo'         => (int)$this->nodeText($node, 'loc:NumeroProgressivoImmobile'),
            'tipologia_immobile'  => (int)($this->nodeText($node, 'loc:TipologiaImmobile') ?: 1),
            'codice_comune'       => strtoupper($this->nodeText($node, 'loc:CodiceComuneCatastale')),
            'tipo_catasto'        => strtoupper($this->nodeText($node, 'loc:TipoCatasto')),
            'intero_porzione'     => strtoupper($this->nodeText($node, 'loc:PorzioneImmobile')),
            'sezione'             => strtoupper($this->nodeText($node, 'loc:SezioneUrbana')),
            'foglio'              => $this->nodeText($node, 'loc:DatiCatastali/*[local-name()="Foglio"]'),
            'particella'          => $this->nodeText($node, 'loc:DatiCatastali/*[local-name()="Particella"]'),
            'subalterno'          => $this->nodeText($node, 'loc:DatiCatastali/*[local-name()="Subalterno"]'),
            'comune'              => strtoupper($this->nodeText($node, 'loc:Comune')),
            'provincia'           => strtoupper($this->nodeText($node, 'loc:Provincia')),
            'categoria'           => strtoupper($this->nodeText($node, 'loc:CategoriaCatastale')),
            'rendita'             => $this->parseImporto($this->nodeText($node, 'loc:RenditaCatastale')),
            'toponimo'            => strtoupper($this->nodeText($node, 'loc:TipologiaIndirizzo')),
            'indirizzo'           => strtoupper($this->nodeText($node, 'loc:Indirizzo')),
            'civico'              => strtoupper($this->nodeText($node, 'loc:NumCivico')),
        ];
    }

    // ============================================================
    // RELAZIONI
    // ============================================================
    private function parseRelazioni(string $docPath): array
    {
        $relazioni = ['locazioni' => [], 'dichiarazioni' => []];
        $base = $docPath . '/loc:Relazioni';

        $locazioni = $this->xpath->query($base . '//loc:LocazioneUsoAbitativo');
        if ($locazioni !== false) {
            foreach ($locazioni as $loc) {
                $relazioni['locazioni'][] = [
                    'immobile_numero'      => (int)$this->nodeText($loc, 'loc:ImmobileNumero'),
                    'locatore_numero'      => (int)$this->nodeText($loc, 'loc:LocatoreNumero'),
                    'percentuale_possesso' => $this->parseImporto($this->nodeText($loc, 'loc:PercentualePossesso')),
                    'cedolare'             => strtoupper($this->nodeText($loc, 'loc:Cedolare')),
                ];
            }
        }

        $dich = $this->xpath->query($base . '//loc:Dichiarazioni');
        if ($dich !== false) {
            foreach ($dich as $d) {
                $relazioni['dichiarazioni'][] = [
                    'codice_fiscale' => strtoupper($this->nodeText($d, 'loc:CodiceFiscale')),
                    'firma'          => $this->nodeText($d, 'loc:Firma'),
                ];
            }
        }

        return $relazioni;
    }

    // ============================================================
    // CANONI VARIABILI
    // ============================================================
    private function parseCanoniVariabili(string $docPath): array
    {
        $canoni = [];
        $base = $docPath . '/loc:CanoniVariabili';

        $nodes = $this->xpath->query($base . '//loc:Canoni/loc:ImportoCanone');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $canoni[] = $this->parseImporto($node->textContent);
            }
        }
        return $canoni;
    }

    // ============================================================
    // HELPER
    // ============================================================
    /**
     * Query XPath assoluta + testo (usata per tutto il parsing).
     */
    private function qtext(string $query): string
    {
        if ($this->xpath === null) return '';
        $nodes = $this->xpath->query($query);
        if ($nodes === false || $nodes->length === 0) return '';
        return trim($nodes->item(0)->textContent);
    }

    /**
     * Query XPath relativa a un DOMNode: ritorna il testo del primo match.
     */
    private function nodeText(DOMNode $context, string $relativeQuery): string
    {
        if ($this->xpath === null) return '';
        if (!preg_match('#^[./]#', $relativeQuery)) {
            $relativeQuery = './' . $relativeQuery;
        }
        $nodes = $this->xpath->query($relativeQuery, $context);
        if ($nodes === false || $nodes->length === 0) return '';
        return trim($nodes->item(0)->textContent);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return $value;
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function parseImporto(string $value): float
    {
        $value = trim($value);
        if ($value === '') return 0.0;
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}
