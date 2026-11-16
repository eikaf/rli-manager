<?php
/**
 * XmlBuilder — Genera XML RLI12 per AdE tramite DOMDocument.
 * Conforme a Specifiche_RLI12_20251104.pdf
 * Supporta moduli multipli, tutte le tipologie adempimento, cedolare 1/2/3.
 *
 * Firma pubblica invariata: __construct(array $payload), build(): string.
 */
class XmlBuilder
{
    private const NS_LOC = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:loc:v1';
    private const NS_CM  = 'urn:www.agenziaentrate.gov.it:specificheTecniche:common:v2';
    private const NS_REG = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:reg:v1';
    private const NS_SC  = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:common:v2';

    private array $pratica;
    private array $locatori;
    private array $conduttori;
    private array $immobili;
    private ?array $richiedente;
    private string $idContratto;
    private string $cfIntermediario;
    private string $latoSubentro;
    private ?int $cedenteId;
    private array $cessionariIds;
    private DOMDocument $dom;

    /**
     * @param array $payload Payload risolto da ContrattoRepository::prepareRliPayload():
     *   pratica, locatori, conduttori, immobili, richiedente, id_contratto,
     *   cf_intermediario, lato_subentro, cedente_id, cessionari_ids.
     */
    public function __construct(array $payload)
    {
        $this->pratica         = $payload['pratica'] ?? [];
        $this->locatori        = $payload['locatori'] ?? [];
        $this->conduttori      = $payload['conduttori'] ?? [];
        $this->immobili        = $payload['immobili'] ?? [];
        $this->richiedente     = $payload['richiedente'] ?? null;
        $this->idContratto     = (string)($payload['id_contratto'] ?? '');
        $this->cfIntermediario = (string)($payload['cf_intermediario'] ?? (defined('RLI_CF_INTERMEDIARIO') ? RLI_CF_INTERMEDIARIO : ''));
        $this->latoSubentro    = (string)($payload['lato_subentro'] ?? 'entrambi');
        $this->cedenteId       = isset($payload['cedente_id']) ? (int)$payload['cedente_id'] : null;
        $this->cessionariIds   = array_map('intval', (array)($payload['cessionari_ids'] ?? []));
    }

    public function build(): string
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::NS_LOC, 'loc:Fornitura');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cm', self::NS_CM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:reg', self::NS_REG);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sc', self::NS_SC);
        $this->dom->appendChild($root);

        $tipo = (string)($this->pratica['tipo_adempimento'] ?? $this->pratica['tipologia_adempimento'] ?? '1');

        $this->appendIntestazione($root);
        $this->appendDatiGenerali($root, $tipo);
        $this->appendDocumento($root, $tipo);

        return $this->dom->saveXML();
    }

    // ---------------------------------------------------------------------
    // Helpers DOM
    // ---------------------------------------------------------------------

    private function nsFor(string $qname): string
    {
        if (strncmp($qname, 'cm:', 3) === 0)  return self::NS_CM;
        if (strncmp($qname, 'reg:', 4) === 0) return self::NS_REG;
        if (strncmp($qname, 'sc:', 3) === 0)  return self::NS_SC;
        return self::NS_LOC;
    }

    private function el(string $qname, $text = null, ?DOMNode $parent = null): DOMElement
    {
        $el = $this->dom->createElementNS($this->nsFor($qname), $qname);
        if ($text !== null && $text !== '') {
            $el->appendChild($this->dom->createTextNode((string)$text));
        }
        if ($parent !== null) {
            $parent->appendChild($el);
        }
        return $el;
    }

    private function countModuliEl(?DOMElement $el): int
    {
        if ($el === null) {
            return 0;
        }
        $count = 0;
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement
                && ($child->localName === 'PrimoModulo' || $child->localName === 'Modulo')) {
                $count++;
            }
        }
        return $count;
    }

    // ---------------------------------------------------------------------
    // Intestazione / DatiGenerali
    // ---------------------------------------------------------------------

    private function appendIntestazione(DOMNode $root): void
    {
        $i = $this->el('loc:Intestazione', null, $root);
        $this->el('loc:CodiceFornitura', RLI_CODICE_FORNITURA, $i);
        $this->el('loc:TipoFornitore', RLI_TIPO_FORNITORE, $i);
        $this->el('loc:CodiceFiscaleFornitore', RLI_CF_INTERMEDIARIO, $i);
        if (RLI_SPAZIO_SERVIZIO !== '') {
            $this->el('loc:SpazioServizioTelematico', RLI_SPAZIO_SERVIZIO, $i);
        }
    }

    private function appendDatiGenerali(DOMNode $root, string $tipo): void
    {
        $p = $this->pratica;
        $dg = $this->el('loc:DatiGenerali', null, $root);

        if ($tipo === '1' && !empty($p['ufficio_territoriale'])) {
            $uc = $this->el('loc:UfficioCompetente', null, $dg);
            $this->el('loc:UfficioTerritoriale', (string)$p['ufficio_territoriale'], $uc);
        }

        $impReg = (float)($p['imposta_registro'] ?? 0);
        $impBol = (float)($p['imposta_bollo'] ?? 0);
        $sanzR  = (float)($p['sanzione_registro'] ?? 0);
        $sanzB  = (float)($p['sanzione_bollo'] ?? 0);
        $intR   = (float)($p['interessi_registro'] ?? 0);
        $intB   = (float)($p['interessi_bollo'] ?? 0);
        $impTot = $impReg + $impBol + $sanzR + $sanzB + $intR + $intB;

        $ibanClean = strtoupper(preg_replace('/\s+/', '', $p['iban'] ?? ''));
        if ($impTot > 0 && strlen($ibanClean) === 27 && !empty($p['cf_intestatario_cc'])) {
            $v = $this->el('loc:Versamento', null, $dg);
            $this->el('reg:IBAN', $ibanClean, $v);
            $this->el('reg:CodiceFiscaleTitolareCC', strtoupper((string)$p['cf_intestatario_cc']), $v);
        }

        $this->el('loc:ImportoDaVersare', number_format($impTot, 2, ',', ''), $dg);
    }

    // ---------------------------------------------------------------------
    // Documento
    // ---------------------------------------------------------------------

    private function appendDocumento(DOMNode $root, string $tipo): void
    {
        $p = $this->pratica;

        // === 1. Costruisci PRIMA i quadri che generano moduli ===
        $soggettiEl = $this->buildSoggettiEl($tipo);
        $immobiliEl = $this->buildImmobiliEl($tipo);
        $relazioniEl = $this->buildRelazioniEl($tipo);
        $canoniEl = $this->buildCanoniEl($tipo);

        $numModuliEffettivo = max(
            1,
            $this->countModuliEl($soggettiEl),
            $this->countModuliEl($immobiliEl),
            $this->countModuliEl($relazioniEl)
        );

        // === 2. Documento + Frontespizio ===
        $doc = $this->el('loc:Documento', null, $root);
        $doc->setAttribute('identificativo', '00001');

        $front = $this->el('loc:Frontespizio', null, $doc);
        $this->el('loc:IdentificativoProdSoftware', RLI_ID_SOFTWARE, $front);
        $this->el('loc:NumeroModuliCompilati', (string)$numModuliEffettivo, $front);

        // FIX L.1: sanitizza IDContratto per DatoANU_Type ([0-9A-Z][0-9A-Z_]*, max 20)
        $idContrattoSafe = strtoupper(preg_replace('/[^0-9A-Z_]/', '_', substr($this->idContratto, 0, 20)));
        if ($idContrattoSafe === '' || !preg_match('/^[0-9A-Z]/', $idContrattoSafe)) {
            $idContrattoSafe = substr('ID' . preg_replace('/[^0-9A-Z_]/', '', $idContrattoSafe), 0, 20);
        }
        $this->el('loc:IDContratto', $idContrattoSafe, $front);

        if ($this->richiedente) {
            $this->appendRichiedente($front, $this->richiedente);
        }

        $this->el('loc:TipologiaContratto', (string)($p['tipologia_contratto'] ?? 'L1'), $front);

        $bloccaCampiBase = in_array($tipo, ['3', '4', '5', '6', '7', '8'], true);
        $mostraCampiBase = in_array($tipo, ['1', '2'], true);

        if ($mostraCampiBase) {
            if (!empty($p['durata_dal'])) {
                $this->el('loc:DataInizio', date('dmY', strtotime($p['durata_dal'])), $front);
            }
            if (!empty($p['durata_al'])) {
                $this->el('loc:DataFine', date('dmY', strtotime($p['durata_al'])), $front);
            }
            if (!empty($p['importo_canone']) && (float)$p['importo_canone'] > 0) {
                $this->el('loc:ImportoCanone', number_format((float)$p['importo_canone'], 2, ',', ''), $front);
            }
            if (!empty($p['contratto_esente']) && in_array((int)$p['contratto_esente'], [1, 2, 3], true)) {
                $this->el('loc:ContrattoEsente', (string)(int)$p['contratto_esente'], $front);
            }
        }

        if (!$bloccaCampiBase && !empty($p['contratto_tempo_indeterminato'])) {
            $this->el('loc:ContrattoTempoIndeterminato', '1', $front);
        }
        if ($tipo === '1' && !empty($p['condizione_sospensiva'])) {
            $this->el('loc:CondizioneSospensiva', '1', $front);
        }
        if ($tipo !== '8' && !empty($p['clausola_penale_volontaria'])) {
            $this->el('loc:ClausolaPenaleVolontaria', '1', $front);
        }
        $this->appendGaranzia($front, $tipo);
        if (!$bloccaCampiBase && !empty($p['pagamento_intera_durata'])) {
            $this->el('loc:PagamentoInteraDurataContratto', '1', $front);
        }
        if (!empty($p['eventi_eccezionali'])) {
            $this->el('loc:EventiEccezionali', (string)$p['eventi_eccezionali'], $front);
        }
        if ($tipo === '1' && !empty($p['casi_particolari'])) {
            $this->el('loc:CasiParticolari', (string)$p['casi_particolari'], $front);
        }
        if (!empty($p['agevolazioni'])) {
            $this->el('loc:Agevolazioni', '1', $front);
        }

        $this->appendImporti($front, $tipo);

        if ($tipo === '1') {
            $this->appendRegistrazione($front);
        } else {
            $this->appendAdempSucc($front, $tipo);
        }

        // Impegno a trasmettere
        $cfRichiedente = $this->richiedente['codice_fiscale'] ?? '';
        $cfIntermediario = $this->cfIntermediario;
        $detImp = !empty($p['dettagli_json']) ? json_decode($p['dettagli_json'], true) : [];
        if (!empty($detImp['data_impegno_intermediario'])) {
            $dataImpegno = $detImp['data_impegno_intermediario'];
        } elseif (($p['tipo_adempimento'] ?? '1') !== '1' && !empty($p['data_invio'])) {
            $dataImpegno = $p['data_invio'];
        } else {
            $dataImpegno = $p['data_impegno_intermediario'] ?? date('Y-m-d');
        }

        if (RLI_TIPO_FORNITORE === '10' && $cfIntermediario !== $cfRichiedente) {
            $imp = $this->el('loc:ImpegnoATrasmettere', null, $front);
            $this->el('reg:CFintermediario', $cfIntermediario, $imp);
            $this->el('reg:ImpegnoATrasmettere', '2', $imp);
            $this->el('reg:DataImpegno', date('dmY', strtotime($dataImpegno)), $imp);
            $this->el('reg:FirmaIntermediario', '1', $imp);
        }

        // FIX: usa la data_invio effettiva del contratto/evento
        $dataInvioXml = !empty($p['data_invio']) ? date('dmY', strtotime($p['data_invio'])) : date('dmY');
        $this->el('loc:DataInvio', $dataInvioXml, $front);

        // === 3. Quadri (già costruiti sopra) nell'ordine XSD ===
        if ($soggettiEl)  $doc->appendChild($soggettiEl);
        if ($immobiliEl)  $doc->appendChild($immobiliEl);
        if ($relazioniEl) $doc->appendChild($relazioniEl);
        if ($canoniEl)    $doc->appendChild($canoniEl);
    }

    // ---------------------------------------------------------------------
    // Richiedente / Garanzia / Importi / Registrazione / AdempSucc
    // ---------------------------------------------------------------------

    private function appendRichiedente(DOMNode $front, array $r): void
    {
        $email = trim((string)($r['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                "Il richiedente '" . ($r['cognome'] ?? '?') . " " . ($r['nome'] ?? '') . "' " .
                "non ha un indirizzo email valido. L'email del richiedente e OBBLIGATORIA per l'XSD RLI12. " .
                "Vai in Soggetti, modifica il soggetto, aggiungi un'email valida, poi riprova."
            );
        }

        $el = $this->el('loc:Richiedente', null, $front);
        $cf = (string)($r['codice_fiscale'] ?? '');
        $this->el('loc:CodiceFiscale', $cf, $el);

        $isAzienda = (strlen($cf) === 11 && ctype_digit($cf));
        if ($isAzienda) {
            $sdp = $this->el('loc:SoggettiDiversiDaPF', null, $el);
            $this->el('loc:Denominazione', mb_strtoupper(mb_substr((string)$r['cognome'], 0, 60, 'UTF-8'), 'UTF-8'), $sdp);
        } else {
            $pf = $this->el('loc:PersoneFisiche', null, $el);
            $this->el('loc:Cognome', mb_strtoupper(mb_substr((string)$r['cognome'], 0, 24, 'UTF-8'), 'UTF-8'), $pf);
            if (!empty($r['nome'])) {
                $this->el('loc:Nome', mb_strtoupper(mb_substr((string)$r['nome'], 0, 20, 'UTF-8'), 'UTF-8'), $pf);
            }
        }

        if (!empty($r['cf_rappresentante'])) {
            $rap = $this->el('loc:Rappresentante', null, $el);
            $this->el('loc:CodiceFiscaleRappresentante', (string)$r['cf_rappresentante'], $rap);
            $this->el('loc:CognomeRappresentante', mb_strtoupper(mb_substr((string)($r['cognome_rappresentante'] ?? ''), 0, 24, 'UTF-8'), 'UTF-8'), $rap);
            $this->el('loc:NomeRappresentante', mb_strtoupper(mb_substr((string)($r['nome_rappresentante'] ?? ''), 0, 20, 'UTF-8'), 'UTF-8'), $rap);
            $this->el('loc:CodiceCarica', (string)($r['codice_carica'] ?? '1'), $rap);
        }

        $this->el('loc:Firma', '1', $el);
        $this->el('loc:TipologiaRichiedente', '1', $el);

        if (!empty($r['telefono'])) {
            $tel = preg_replace('/[^0-9]/', '', (string)$r['telefono']);
            if ($tel !== '') {
                $this->el('loc:Telefono', $tel, $el);
            }
        }

        $this->el('loc:Email', $email, $el);
    }

    private function appendGaranzia(DOMNode $front, string $tipo): void
    {
        $p = $this->pratica;
        if ($tipo !== '1' || empty($p['tipo_garanzia'])) {
            return;
        }
        $g = $this->el('loc:Garanzia', null, $front);
        $this->el('loc:TipologiaGaranzia', (string)$p['tipo_garanzia'], $g);

        if (!empty($p['importo_garanzia'])) {
            $this->el('loc:ImportoGaranziaPrestataDaTerzi', number_format((float)$p['importo_garanzia'], 2, ',', ''), $g);
        }
        if (!empty($p['cf_garante']) && (string)$p['tipo_garanzia'] !== '3') {
            $this->el('loc:CodiceFiscaleGarante', strtoupper((string)$p['cf_garante']), $g);
        }
        if (!empty($p['cf_garante2']) && !empty($p['cf_garante'])) {
            $this->el('loc:CodiceFiscaleSecondoGarante', strtoupper((string)$p['cf_garante2']), $g);
        }
        if (!empty($p['garanzia_soggetta_iva'])) {
            $this->el('loc:GaranziaSoggettaIva', '1', $g);
        }
    }

    private function appendImporti(DOMNode $front, string $tipo): void
    {
        $p = $this->pratica;
        $impReg = (float)($p['imposta_registro'] ?? 0);
        $impBol = (float)($p['imposta_bollo'] ?? 0);
        $sanzR = (float)($p['sanzione_registro'] ?? 0);
        $sanzB = (float)($p['sanzione_bollo'] ?? 0);
        $intR = (float)($p['interessi_registro'] ?? 0);
        $intB = (float)($p['interessi_bollo'] ?? 0);

        if ($impReg + $impBol + $sanzR + $sanzB + $intR + $intB <= 0) {
            return;
        }

        $imp = $this->el('loc:Importi', null, $front);

        if ($impReg > 0 || $impBol > 0) {
            $e = $this->el('loc:Imposte', null, $imp);
            if ($impReg > 0) $this->el('loc:ImpostaDiRegistro', number_format($impReg, 2, ',', ''), $e);
            if ($impBol > 0) $this->el('loc:ImpostaDiBollo', number_format($impBol, 2, ',', ''), $e);
        }
        if ($sanzR > 0 || $sanzB > 0) {
            $e = $this->el('loc:Sanzioni', null, $imp);
            if ($sanzR > 0) $this->el('loc:ImpostaDiRegistro', number_format($sanzR, 2, ',', ''), $e);
            if ($sanzB > 0) $this->el('loc:ImpostaDiBollo', number_format($sanzB, 2, ',', ''), $e);
        }
        if ($intR > 0 || $intB > 0) {
            $e = $this->el('loc:Interessi', null, $imp);
            if ($intR > 0) $this->el('loc:ImpostaDiRegistro', number_format($intR, 2, ',', ''), $e);
            if ($intB > 0) $this->el('loc:ImpostaDiBollo', number_format($intB, 2, ',', ''), $e);
        }
    }

    private function appendRegistrazione(DOMNode $front): void
    {
        $p = $this->pratica;
        $reg = $this->el('loc:Registrazione', null, $front);
        $c = $this->el('loc:Contratto', null, $reg);

        if (!empty($p['data_stipula'])) {
            $this->el('loc:DataStipula', date('dmY', strtotime($p['data_stipula'])), $c);
        }
        $this->el('loc:NumeroFogli', (string)max(1, (int)($p['numero_pagine'] ?? 1)), $c);
        $this->el('loc:NumeroCopie', (string)max(1, (int)($p['numero_copie'] ?? 1)), $c);

        if (!empty($p['contratto_soggetto_iva'])) {
            $this->el('loc:ContrattoSoggettoIVA', '1', $c);
        }
        if (!empty($p['numero_allegato_a'])) {
            $this->el('loc:NumeroAllegatoA', (string)(int)$p['numero_allegato_a'], $c);
        }
        if (!empty($p['numero_allegato_b'])) {
            $this->el('loc:NumeroAllegatoB', (string)(int)$p['numero_allegato_b'], $c);
        }
        if (!empty($p['numero_allegato_c'])) {
            $this->el('loc:NumeroAllegatoC', (string)(int)$p['numero_allegato_c'], $c);
        }

        if (!empty($p['allegato_pdf'])) {
            $b64 = rtrim(chunk_split(base64_encode($p['allegato_pdf']), 76, "\n"), "\n");
            $al = $this->el('loc:Allegati', null, $reg);
            $this->el('reg:FileType', 'application/pdf', $al);
            $this->el('reg:FileName', strtoupper((string)($p['nome_allegato'] ?? 'contratto.pdf')), $al);
            $this->el('reg:ImageData', $b64, $al);
        } elseif (!empty($p['contratto_non_allegato'])) {
            $this->el('loc:ContrattoNonAllegato', '1', $reg);
        }
    }

    private function appendAdempSucc(DOMNode $front, string $tipo): void
    {
        $p = $this->pratica;
        $ads = $this->el('loc:AdempSucc', null, $front);
        $this->el('loc:TipologiaAdempimento', $tipo, $ads);

        // CedolareSecca: mai per 3 e 5; non può essere presente in presenza di
        // soggetto subentrato (tipo 6). Per il subentro la si omette.
        if (!in_array($tipo, ['3', '5', '6'], true)) {
            $ced = (string)($p['cedolare_secca'] ?? '3');
            if ($ced === '' || $ced === '0') $ced = '3';
            $this->el('loc:CedolareSecca', $ced, $ads);
        }

        if (!in_array($tipo, ['5', '8'], true) && !empty($p['comunica_dati_catastali'])) {
            $this->el('loc:CDC', '1', $ads);
        } elseif ($tipo === '2' && !empty($p['comunica_dati_catastali'])) {
            $this->el('loc:CDC', '1', $ads);
        }

        if ($tipo === '8' && !empty($p['data_stipula'])) {
            $c = $this->el('loc:Contratto', null, $ads);
            $this->el('loc:DataStipula', date('dmY', strtotime($p['data_stipula'])), $c);
            if (!empty($p['numero_pagine'])) {
                $this->el('loc:NumeroFogli', (string)(int)$p['numero_pagine'], $c);
            }
            if (!empty($p['numero_copie'])) {
                $this->el('loc:NumeroCopie', (string)(int)$p['numero_copie'], $c);
            }
        }

        if (in_array($tipo, ['1', '5'], true) && !empty($p['annualita'])) {
            $this->el('loc:Annualita', (string)$p['annualita'], $ads);
        }

        if ($tipo === '8' && !empty($p['data_inizio_rinegoziazione'])) {
            $this->el('loc:DataInizioRineg', date('dmY', strtotime($p['data_inizio_rinegoziazione'])), $ads);
        }

        if (in_array($tipo, ['2', '3', '4', '6', '7', '8'], true)) {
            if ($tipo === '2') $dataRif = $p['data_fine_proroga'] ?? '';
            elseif ($tipo === '3') $dataRif = $p['data_cessione'] ?? '';
            elseif ($tipo === '4' || $tipo === '7') $dataRif = $p['durata_al'] ?? '';
            elseif ($tipo === '6') $dataRif = $p['data_subentro'] ?? '';
            else $dataRif = $p['durata_al'] ?? '';

            if (!empty($dataRif)) {
                $this->el('loc:DataFine', date('dmY', strtotime($dataRif)), $ads);
            }
        }

        if (in_array($tipo, ['7', '8'], true) && !empty($p['corrispettivo'])) {
            $this->el('loc:Corrispettivo', number_format((float)$p['corrispettivo'], 2, ',', ''), $ads);
        }

        if (!empty($p['ufficio_registrazione']) && !empty($p['serie_registrazione'])) {
            $r = $this->el('loc:Registrazione', null, $ads);
            $this->el('loc:UfficioDiRegistrazione', (string)$p['ufficio_registrazione'], $r);
            $this->el('loc:Anno', (string)($p['anno_registrazione'] ?? ''), $r);
            $this->el('loc:Serie', (string)$p['serie_registrazione'], $r);
            $this->el('loc:NumeroRegistrazione', (string)($p['numero_registrazione'] ?? ''), $r);
            if (!empty($p['sottonumero_registrazione'])) {
                $this->el('loc:SottonumeroRegistrazione', (string)$p['sottonumero_registrazione'], $r);
            }
        } else {
            $idTel = $p['codice_identificativo_contratto'] ?? '';
            if ($idTel === '') {
                $idTel = substr($p['id_contratto_univoco'] ?? $this->idContratto, 0, 17);
            }
            $this->el('loc:IDTelematico', substr((string)$idTel, 0, 17), $ads);
        }

        if ($tipo === '6' && !empty($p['tipologia_subentro'])) {
            $this->el('loc:TipologiaSubentro', (string)$p['tipologia_subentro'], $ads);
        }
        if ($tipo === '2' && !empty($p['tipologia_proroga'])) {
            $this->el('loc:TipologiaProroga', (string)$p['tipologia_proroga'], $ads);
        }
        if ($tipo === '1' && !empty($p['tardivita_annualita'])) {
            $this->el('loc:TardivitaAnnualitaSuccessiva', (string)$p['tardivita_annualita'], $ads);
        }
        if ($tipo === '8' && !empty($p['allegato_pdf'])) {
            $b64 = rtrim(chunk_split(base64_encode($p['allegato_pdf']), 76, "\n"), "\n");
            $al = $this->el('loc:Allegato', null, $ads);
            $this->el('reg:FileType', 'application/pdf', $al);
            $this->el('reg:FileName', strtoupper((string)($p['nome_allegato'] ?? 'contratto.pdf')), $al);
            $this->el('reg:ImageData', $b64, $al);
        }
    }

    // ---------------------------------------------------------------------
    // Quadro B - Soggetti
    // ---------------------------------------------------------------------

    private function buildSoggettiEl(string $tipo): ?DOMElement
    {
        if ($tipo === '4' || (count($this->locatori) + count($this->conduttori)) === 0) {
            return null;
        }

        if ($tipo === '1' || in_array($tipo, ['3', '6'], true)) {
            $locatori = $this->locatori;
            $conduttori = $this->conduttori;
        } else {
            $locatori = $this->locatori;
            $cedEmessa = !in_array($tipo, ['3', '5'], true);
            $conduttori = $cedEmessa ? [] : $this->conduttori;
        }

        $soggetti = $this->el('loc:Soggetti');

        $numLocModuli = max(1, (int)ceil(count($locatori) / 4));
        $numCondModuli = max(1, (int)ceil(count($conduttori) / 4));
        $numModuli = max($numLocModuli, $numCondModuli);

        for ($m = 0; $m < $numModuli; $m++) {
            $tag = ($m === 0) ? 'loc:PrimoModulo' : 'loc:Modulo';
            $modulo = $this->el($tag, null, $soggetti);

            foreach (array_slice($locatori, $m * 4, 4) as $idx => $loc) {
                $this->appendSoggetto($modulo, $loc, 'LOCATORE', ($m * 4) + $idx + 1);
            }
            foreach (array_slice($conduttori, $m * 4, 4) as $idx => $cond) {
                $this->appendSoggetto($modulo, $cond, 'CONDUTTORE', ($m * 4) + $idx + 1);
            }
        }

        return $soggetti;
    }

    private function appendSoggetto(DOMNode $parent, array $s, string $tipo, int $progressivo): void
    {
        $tagName = ($tipo === 'LOCATORE') ? 'loc:Locatore' : 'loc:Conduttore';
        $cf = mb_strtoupper(trim((string)($s['codice_fiscale'] ?? '')), 'UTF-8');
        $isAzienda = (strlen($cf) === 11 && ctype_digit($cf));

        $el = $this->el($tagName, null, $parent);
        $this->el('loc:NumeroProgressivo', str_pad((string)$progressivo, 3, '0', STR_PAD_LEFT), $el);
        $this->el('loc:CodiceFiscale', $cf, $el);

        $tipologiaContratto = strtoupper((string)($this->pratica['tipologia_contratto'] ?? ''));
        if ($tipo === 'LOCATORE' && !empty($s['soggettivita_iva']) && $tipologiaContratto !== 'S1') {
            $this->el('loc:SoggettivitaIVA', '1', $el);
        }

        if ($isAzienda) {
            $denominazione = mb_strtoupper(trim((string)($s['cognome'] ?? '')), 'UTF-8');
            if ($denominazione === '') {
                throw new RuntimeException(
                    "Soggetto {$tipo} con CF {$cf}: la denominazione (campo 'cognome') è obbligatoria " .
                    "per i soggetti diversi da persona fisica."
                );
            }
            $sdp = $this->el('loc:SoggettiDiversiDaPF', null, $el);
            $this->el('loc:Denominazione', mb_substr($denominazione, 0, 60, 'UTF-8'), $sdp);
        } else {
            $cognome = mb_strtoupper(trim((string)($s['cognome'] ?? '')), 'UTF-8');
            $nome    = mb_strtoupper(trim((string)($s['nome'] ?? '')), 'UTF-8');
            if ($cognome === '') {
                throw new RuntimeException("Soggetto {$tipo} con CF {$cf}: il cognome è obbligatorio.");
            }
            if ($nome === '') {
                throw new RuntimeException("Soggetto {$tipo} con CF {$cf}: il nome è obbligatorio per le persone fisiche.");
            }

            $derivati = $this->deriveFromCf($cf);
            $sesso = !empty($s['sesso']) ? mb_strtoupper((string)$s['sesso'], 'UTF-8') : ($derivati['sesso'] ?? null);
            if ($sesso !== 'M' && $sesso !== 'F') {
                throw new RuntimeException("Soggetto {$tipo} con CF {$cf}: sesso non determinabile (impostare M/F).");
            }

            $dataN = !empty($s['data_nascita'])
                ? date('dmY', strtotime($s['data_nascita']))
                : ($derivati['data_nascita'] ?? '');
            if ($dataN === '' || strlen($dataN) !== 8) {
                throw new RuntimeException("Soggetto {$tipo} con CF {$cf}: data di nascita mancante o non valida.");
            }

            $comune = mb_strtoupper(trim((string)($s['comune_nascita'] ?? '')), 'UTF-8');
            if ($comune === '') {
                throw new RuntimeException("Soggetto {$tipo} con CF {$cf}: il comune di nascita è obbligatorio.");
            }
            $prov = mb_strtoupper(trim((string)($s['prov_nascita'] ?? '')), 'UTF-8');

            $pf = $this->el('loc:PersoneFisiche', null, $el);
            $this->el('sc:Cognome', mb_substr($cognome, 0, 80, 'UTF-8'), $pf);
            $this->el('sc:Nome', mb_substr($nome, 0, 80, 'UTF-8'), $pf);
            $this->el('sc:Sesso', $sesso, $pf);
            $this->el('sc:DataNascita', $dataN, $pf);
            $this->el('sc:ComuneNascita', $comune, $pf);
            if ($prov !== '') {
                $this->el('sc:ProvinciaNascita', $prov, $pf);
            }
        }

        $tipoAdemp = (string)($this->pratica['tipo_adempimento'] ?? $this->pratica['tipologia_adempimento'] ?? '1');
        if (in_array($tipoAdemp, ['3', '6'], true) && isset($s['cedente_ruolo'])) {
            if ((int)$s['cedente_ruolo'] === 1) {
                $this->el('loc:Cedente', '1', $el);
            } elseif ((int)$s['cedente_ruolo'] === 2) {
                $this->el('loc:Cedente', '2', $el);
            }
        }

        if ($tipo === 'CONDUTTORE') {
            $tipCond = (string)($s['tipologia_conduttore'] ?? '');
            if (!in_array($tipCond, ['1', '2', '3'], true)) {
                $tipCond = '3';
            }
            $this->el('loc:TipologiaConduttore', $tipCond, $el);
        }

        if ($tipo === 'LOCATORE' && !empty($s['non_presente_in_atto'])) {
            $this->el('loc:ProprietarioNonPresente', '1', $el);
        }
    }

    private function deriveFromCf(string $cf): array
    {
        $cf = strtoupper(trim($cf));
        if (strlen($cf) !== 16) {
            return ['sesso' => null, 'data_nascita' => null];
        }

        $mesi = [
            'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'H' => 6,
            'L' => 7, 'M' => 8, 'P' => 9, 'R' => 10, 'S' => 11, 'T' => 12,
        ];

        $yy      = (int)substr($cf, 6, 2);
        $meseLet = $cf[8] ?? '';
        $gg      = (int)substr($cf, 9, 2);

        if (!isset($mesi[$meseLet]) || $gg < 1) {
            return ['sesso' => null, 'data_nascita' => null];
        }

        $sesso = ($gg > 40) ? 'F' : 'M';
        if ($gg > 40) {
            $gg -= 40;
        }

        $annoCorrente = (int)date('y');
        $anno = ($yy <= $annoCorrente) ? (2000 + $yy) : (1900 + $yy);
        $mese = $mesi[$meseLet];

        if (!checkdate($mese, $gg, $anno)) {
            return ['sesso' => $sesso, 'data_nascita' => null];
        }

        return [
            'sesso'        => $sesso,
            'data_nascita' => sprintf('%02d%02d%04d', $gg, $mese, $anno),
        ];
    }

    // ---------------------------------------------------------------------
    // Quadro C - Immobili
    // ---------------------------------------------------------------------

    private function buildImmobiliEl(string $tipo): ?DOMElement
    {
        if (in_array($tipo, ['4', '5', '7'], true)) {
            return null;
        }
        if (count($this->immobili) === 0) {
            throw new RuntimeException(
                "XmlBuilder: il Quadro C (Immobili) è obbligatorio per la tipologia adempimento {$tipo} " .
                "e tipologia contratto " . ($this->pratica['tipologia_contratto'] ?? '?') . ". " .
                "Nessun immobile è stato associato al contratto."
            );
        }

        $immobili = $this->el('loc:Immobili');
        $numModuli = max(1, (int)ceil(count($this->immobili) / 4));

        for ($m = 0; $m < $numModuli; $m++) {
            $tag = ($m === 0) ? 'loc:PrimoModulo' : 'loc:Modulo';
            $modulo = $this->el($tag, null, $immobili);
            foreach (array_slice($this->immobili, $m * 4, 4) as $idx => $imm) {
                $this->appendImmobile($modulo, $imm, ($m * 4) + $idx + 1);
            }
        }

        return $immobili;
    }

    private function appendImmobile(DOMNode $parent, array $i, int $progressivo): void
    {
        $tipologia = (int)($i['tipologia_immobile'] ?? 1);
        $cat = !empty($i['categoria']) ? mb_strtoupper(trim($i['categoria']), 'UTF-8') : 'A2';
        $cat = str_replace(['/', '\\', ' ', '-'], '', $cat);
        $cat = preg_replace('/^([A-Z])0([0-9])$/', '$1$2', $cat);
        $rendita = !empty($i['rendita']) ? number_format((float)$i['rendita'], 2, ',', '') : '0,00';

        $el = $this->el('loc:Immobile', null, $parent);
        $this->el('loc:NumeroProgressivoImmobile', str_pad((string)$progressivo, 3, '0', STR_PAD_LEFT), $el);
        $this->el('loc:TipologiaImmobile', (string)$tipologia, $el);

        if (!empty($i['codice_comune'])) {
            $this->el('loc:CodiceComuneCatastale', (string)$i['codice_comune'], $el);
        }

        $tipoCatasto = !empty($i['tipo_catasto']) ? mb_strtoupper(trim($i['tipo_catasto']), 'UTF-8') : 'U';
        $tipologiaContratto = strtoupper((string)($this->pratica['tipologia_contratto'] ?? 'L1'));
        if (in_array($tipologiaContratto, ['L1', 'L2', 'L3', 'L4', 'S1', 'S2', 'S3'], true)) {
            $tipoCatasto = 'U';
        }
        $this->el('loc:TipoCatasto', $tipoCatasto, $el);

        if (!empty($i['intero_porzione'])) {
            $this->el('loc:PorzioneImmobile', (string)$i['intero_porzione'], $el);
        }
        if (!empty($i['sezione'])) {
            $this->el('loc:SezioneUrbana', mb_strtoupper((string)$i['sezione'], 'UTF-8'), $el);
        }

        $dc = $this->el('loc:DatiCatastali', null, $el);
        $this->el('reg:Foglio', (string)($i['foglio'] ?? ''), $dc);
        $this->el('reg:Particella', (string)($i['particella'] ?? ''), $dc);
        if (!empty($i['subalterno'])) {
            $this->el('reg:Subalterno', (string)$i['subalterno'], $dc);
        }

        $this->el('loc:Comune', mb_strtoupper(mb_substr((string)($i['comune'] ?? ''), 0, 40, 'UTF-8'), 'UTF-8'), $el);

        // loc:Provincia è OBBLIGATORIO nell'XSD (cm:PE_Type: sigle italiane oppure "EE").
        // Non va MAI emesso vuoto: normalizziamo e, se manca, blocchiamo con un errore chiaro.
        $prov = mb_strtoupper(trim((string)($i['provincia'] ?? '')), 'UTF-8');
        if ($prov === '') {
            $descr = trim((string)($i['identificativo_interno'] ?? ''));
            if ($descr === '') {
                $descr = trim((string)($i['comune'] ?? ''));
            }
            throw new RuntimeException(
                "Immobile #" . ($i['id'] ?? '?') . ($descr !== '' ? " '{$descr}'" : '') . ": " .
                "la provincia è obbligatoria. Valorizzala con una sigla di 2 lettere (es. RM) " .
                "oppure 'EE' per gli immobili situati all'estero."
            );
        }
        $this->el('loc:Provincia', $prov, $el);

        $this->el('loc:CategoriaCatastale', $cat, $el);
        $this->el('loc:RenditaCatastale', $rendita, $el);

        if (!empty($i['toponimo'])) {
            $this->el('loc:TipologiaIndirizzo', mb_strtoupper((string)$i['toponimo'], 'UTF-8'), $el);
        }
        if (!empty($i['indirizzo'])) {
            $this->el('loc:Indirizzo', mb_strtoupper(mb_substr((string)$i['indirizzo'], 0, 35, 'UTF-8'), 'UTF-8'), $el);
        }
        if (!empty($i['civico'])) {
            $this->el('loc:NumCivico', mb_strtoupper((string)$i['civico'], 'UTF-8'), $el);
        }
    }

    // ---------------------------------------------------------------------
    // Quadro D - Relazioni
    // ---------------------------------------------------------------------

    private function buildRelazioniEl(string $tipo): ?DOMElement
    {
        if (!in_array($tipo, ['1', '2', '3', '6', '8'], true)) {
            return null;
        }
        if (in_array($tipo, ['3', '6'], true) && $this->latoSubentro === 'conduttore') {
            return null;
        }

        $tuttiIVA = count($this->locatori) > 0;
        foreach ($this->locatori as $loc) {
            if (empty($loc['soggettivita_iva'])) { $tuttiIVA = false; break; }
        }
        if ($tuttiIVA) {
            return null;
        }
        if (count($this->locatori) === 0) {
            throw new RuntimeException("XmlBuilder: il Quadro D (Relazioni) è obbligatorio ma nessun locatore è presente.");
        }
        if (count($this->immobili) === 0) {
            throw new RuntimeException("XmlBuilder: il Quadro D (Relazioni) richiede almeno un immobile nel Quadro C.");
        }

        $cedolareContratto = (string)($this->pratica['cedolare_secca'] ?? '3');

        $righe = [];
        foreach ($this->immobili as $iIdx => $imm) {
            foreach ($this->locatori as $lIdx => $loc) {
                $quota = (float)($loc['percentuale_possesso'] ?? 100);
                if ($quota <= 0) continue;

                if ($cedolareContratto === '3') {
                    $cedolare = 'N';
                } elseif ($cedolareContratto === '1') {
                    $cedolare = 'S';
                } else {
                    $cedolare = !empty($loc['opzione_cedolare']) ? 'S' : 'N';
                }

                $righe[] = [
                    'immobile_num' => $iIdx + 1,
                    'locatore_num' => $lIdx + 1,
                    'quota' => $quota,
                    'cedolare' => $cedolare,
                    'loc_cf' => $loc['codice_fiscale'],
                ];
            }
        }

        if (empty($righe)) {
            return null;
        }

        $relazioni = $this->el('loc:Relazioni');
        $numModuli = max(1, (int)ceil(count($righe) / 10));

        // PrimoModulo: righe 1..10 + Dichiarazioni (max 4, come da XSD maxOccurs=4).
        $primo = $this->el('loc:PrimoModulo', null, $relazioni);
        foreach (array_slice($righe, 0, 10) as $r) {
            $this->appendLocazione($primo, $r);
        }
        $emesse = [];
        foreach ($righe as $r) {
            if (count($emesse) >= 4) break;
            if ($r['cedolare'] === 'S' && !in_array($r['loc_cf'], $emesse, true)) {
                $d = $this->el('loc:Dichiarazioni', null, $primo);
                $this->el('loc:CodiceFiscale', (string)$r['loc_cf'], $d);
                $this->el('loc:Firma', '1', $d);
                $emesse[] = $r['loc_cf'];
            }
        }

        // Moduli successivi: solo righe, NESSUNA Dichiarazioni (XSD: solo nel PrimoModulo).
        for ($m = 1; $m < $numModuli; $m++) {
            $modulo = $this->el('loc:Modulo', null, $relazioni);
            foreach (array_slice($righe, $m * 10, 10) as $r) {
                $this->appendLocazione($modulo, $r);
            }
        }

        return $relazioni;
    }

    private function appendLocazione(DOMNode $parent, array $r): void
    {
        $loc = $this->el('loc:LocazioneUsoAbitativo', null, $parent);
        $this->el('loc:ImmobileNumero', str_pad((string)$r['immobile_num'], 3, '0', STR_PAD_LEFT), $loc);
        $this->el('loc:LocatoreNumero', str_pad((string)$r['locatore_num'], 3, '0', STR_PAD_LEFT), $loc);
        $this->el('loc:PercentualePossesso', number_format($r['quota'], 2, ',', ''), $loc);
        $this->el('loc:Cedolare', $r['cedolare'], $loc);
    }

    // ---------------------------------------------------------------------
    // Quadro E - Canoni variabili
    // ---------------------------------------------------------------------

    private function buildCanoniEl(string $tipo): ?DOMElement
    {
        $p = $this->pratica;
        if ($tipo !== '1' || empty($p['canoni_variabili'])) {
            return null;
        }

        $canoni = is_array($p['canoni_variabili'])
            ? $p['canoni_variabili']
            : json_decode($p['canoni_variabili'], true);

        if (!is_array($canoni) || count($canoni) === 0) {
            return null;
        }

        $importi = [];
        foreach (array_slice($canoni, 0, 8) as $c) {
            if (is_array($c) && isset($c['importo'])) {
                $importi[] = (float)$c['importo'];
            } elseif (is_numeric($c)) {
                $importi[] = (float)$c;
            }
        }
        if (count($importi) === 0) {
            return null;
        }

        $cv = $this->el('loc:CanoniVariabili');
        $pm = $this->el('loc:PrimoModulo', null, $cv);
        foreach ($importi as $importo) {
            $c = $this->el('loc:Canoni', null, $pm);
            $this->el('loc:ImportoCanone', number_format($importo, 2, ',', ''), $c);
        }

        return $cv;
    }
}
