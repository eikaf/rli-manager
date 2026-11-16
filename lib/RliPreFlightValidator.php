<?php
/**
 * RliPreFlightValidator — Validazione pre-volo dei dati per la generazione XML RLI12.
 *
 * Centralizza tutti i controlli che prima lanciavano RuntimeException dentro XmlBuilder
 * (anagrafiche PF/società, derivazione da CF, email richiedente, IBAN) e aggiunge i
 * controlli su ufficio territoriale e estremi di registrazione.
 *
 * Restituisce un elenco di errori leggibili in italiano (array vuoto = nessun errore).
 */
class RliPreFlightValidator
{
    /**
     * @param array $payload Payload prodotto da ContrattoRepository::prepareRliPayload().
     * @return string[] Elenco errori in italiano.
     */
    public static function validate(array $payload): array
    {
        $errori = [];

        $pratica     = $payload['pratica'] ?? [];
        $locatori    = $payload['locatori'] ?? [];
        $conduttori  = $payload['conduttori'] ?? [];
        $immobili    = $payload['immobili'] ?? [];
        $richiedente = $payload['richiedente'] ?? null;

        $tipo      = (string)($pratica['tipo_adempimento'] ?? $pratica['tipologia_adempimento'] ?? '1');
        $tipologia = strtoupper((string)($pratica['tipologia_contratto'] ?? ''));

        // --- Richiedente ---
        if (!$richiedente) {
            $errori[] = "Nessun richiedente associato al contratto.";
        } else {
            $email = trim((string)($richiedente['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $nome = trim(($richiedente['cognome'] ?? '') . ' ' . ($richiedente['nome'] ?? ''));
                if ($nome === '') {
                    $nome = 'sconosciuto';
                }
                $errori[] = "Il richiedente '{$nome}' non ha un indirizzo email valido. " .
                            "L'email del richiedente è OBBLIGATORIA per il controllo telematico AdE.";
            }

            $cfRich = strtoupper(trim((string)($richiedente['codice_fiscale'] ?? '')));
            if (!self::isValidCf($cfRich)) {
                $errori[] = "Il codice fiscale del richiedente '{$cfRich}' non è formalmente valido.";
            } elseif (!self::isSocieta($cfRich)) {
                if (trim((string)($richiedente['cognome'] ?? '')) === '') {
                    $errori[] = "Il richiedente persona fisica non ha il cognome.";
                }
                if (trim((string)($richiedente['nome'] ?? '')) === '') {
                    $errori[] = "Il richiedente persona fisica non ha il nome.";
                }
            }
        }

        // --- IBAN / importi da versare ---
        $impTot = (float)($pratica['imposta_registro'] ?? 0)
                + (float)($pratica['imposta_bollo'] ?? 0)
                + (float)($pratica['sanzione_registro'] ?? 0)
                + (float)($pratica['sanzione_bollo'] ?? 0)
                + (float)($pratica['interessi_registro'] ?? 0)
                + (float)($pratica['interessi_bollo'] ?? 0);
        if ($impTot > 0) {
            $iban = strtoupper(preg_replace('/\s+/', '', (string)($pratica['iban'] ?? '')));
            if (strlen($iban) !== 27) {
                $errori[] = "L'IBAN deve essere italiano (27 caratteri, inizia con IT). " .
                            "Trovati: " . strlen($iban) . " caratteri.";
            }
            if (empty($pratica['cf_intestatario_cc'])) {
                $errori[] = "Manca il CF intestatario del conto corrente (obbligatorio per l'addebito).";
            }
        }

        // --- Ufficio territoriale (solo prima registrazione) ---
        if ($tipo === '1') {
            $uff = strtoupper(trim((string)($pratica['ufficio_territoriale'] ?? '')));
            if ($uff === '') {
                $errori[] = "Ufficio territoriale mancante: per la prima registrazione è obbligatorio " .
                            "un codice ufficio AdE di 3 caratteri alfanumerici (es. TMD).";
            } elseif (!preg_match('/^[A-Z0-9]{3}$/', $uff)) {
                $errori[] = "Ufficio territoriale '{$uff}' non valido: deve essere un codice ufficio AdE " .
                            "di 3 caratteri alfanumerici (es. TMD), non una sigla provincia di 2 lettere.";
            }
        }

        // --- Estremi di registrazione (adempimenti successivi) ---
        if ($tipo !== '1') {
            $codId  = trim((string)($pratica['codice_identificativo_contratto'] ?? ''));
            $uffReg = trim((string)($pratica['ufficio_registrazione'] ?? ''));
            $anno   = trim((string)($pratica['anno_registrazione'] ?? ''));
            $serie  = trim((string)($pratica['serie_registrazione'] ?? ''));
            $numero = trim((string)($pratica['numero_registrazione'] ?? ''));
            $hasCartaceo = ($uffReg !== '' && $anno !== '' && $serie !== '' && $numero !== '');
            if ($codId === '' && !$hasCartaceo) {
                $errori[] = "Per gli adempimenti successivi è necessario indicare il codice identificativo " .
                            "del contratto oppure gli estremi di registrazione (ufficio, anno, serie, numero).";
            } elseif ($codId !== '') {
                if (strlen($codId) !== 17) {
                    $errori[] = "Il codice identificativo del contratto (IDTelematico) deve essere di 17 caratteri " .
                                "(trovati " . strlen($codId) . ").";
                } elseif (!self::isValidIdTelematico($codId)) {
                    $errori[] = "Il codice identificativo del contratto (IDTelematico) contiene caratteri non ammessi " .
                                "(es. underscore). Usa solo i caratteri previsti dallo schema AdE " .
                                "(lettere maiuscole, cifre e simboli ammessi).";
                }
            }
        }

        // --- Anagrafiche locatori / conduttori ---
        foreach ($locatori as $s) {
            $errori = array_merge($errori, self::validateSoggetto($s, 'locatore'));
        }
        foreach ($conduttori as $s) {
            $errori = array_merge($errori, self::validateSoggetto($s, 'conduttore'));
        }

        // --- Provincia degli immobili (obbligatoria: sigla di 2 lettere o "EE") ---
        foreach ($immobili as $imm) {
            $prov = strtoupper(trim((string)($imm['provincia'] ?? '')));
            $labelImm = "Immobile #" . ($imm['id'] ?? '?');
            $descr = trim((string)($imm['identificativo_interno'] ?? ''));
            if ($descr === '') {
                $descr = trim((string)($imm['comune'] ?? ''));
            }
            if ($descr !== '') {
                $labelImm .= " '{$descr}'";
            }
            if ($prov === '') {
                $errori[] = "{$labelImm}: la provincia è obbligatoria " .
                            "(sigla di 2 lettere, es. RM, oppure 'EE' per gli immobili all'estero).";
            } elseif (!preg_match('/^[A-Z]{2}$/', $prov)) {
                $errori[] = "{$labelImm}: la provincia '{$prov}' non è valida " .
                            "(servono 2 lettere, es. RM, oppure 'EE' per gli immobili all'estero).";
            }
        }

        // --- Quadri obbligatori ---
        $tipologieLocazione = ['L1', 'L2', 'L3', 'L4', 'S1', 'S2', 'S3', 'T1', 'T2', 'T3', 'T4'];
        if (in_array($tipologia, $tipologieLocazione, true)) {
            if (count($locatori) === 0) {
                $errori[] = "Quadro B: nessun locatore associato al contratto.";
            }
            if ($tipo === '1' && count($conduttori) === 0) {
                $errori[] = "Quadro B: nessun conduttore associato al contratto " .
                            "(obbligatorio per la prima registrazione).";
            }

            $adempimentiSenzaImmobili = ['4', '5', '7'];
            if (!in_array($tipo, $adempimentiSenzaImmobili, true) && count($immobili) === 0) {
                $errori[] = "Quadro C: nessun immobile associato al contratto.";
            }

            if (in_array($tipologia, ['L1', 'L2'], true)) {
                $tuttiIVA = count($locatori) > 0;
                foreach ($locatori as $loc) {
                    if (empty($loc['soggettivita_iva'])) {
                        $tuttiIVA = false;
                        break;
                    }
                }
                if (!$tuttiIVA) {
                    if (count($locatori) === 0) {
                        $errori[] = "Quadro D: nessun locatore presente (obbligatorio per L1/L2).";
                    }
                    if (count($immobili) === 0) {
                        $errori[] = "Quadro D: nessun immobile presente (richiesto per L1/L2).";
                    }
                }
            }
        }

        return $errori;
    }

    /**
     * Valida un singolo soggetto (locatore o conduttore).
     */
    private static function validateSoggetto(array $s, string $ruolo): array
    {
        $errori = [];
        $cf = strtoupper(trim((string)($s['codice_fiscale'] ?? '')));

        $nome = trim(($s['cognome'] ?? '') . ' ' . ($s['nome'] ?? ''));
        $label = ucfirst($ruolo) . ($nome !== '' ? " '{$nome}'" : " (CF " . ($cf !== '' ? $cf : '?') . ")");

        if (!self::isValidCf($cf)) {
            $errori[] = "{$label}: codice fiscale '{$cf}' non formalmente valido.";
            return $errori;
        }

        if (self::isSocieta($cf)) {
            if (trim((string)($s['cognome'] ?? '')) === '') {
                $errori[] = "{$label}: la denominazione è obbligatoria per i soggetti diversi da persona fisica.";
            }
            return $errori;
        }

        // Persona fisica
        if (trim((string)($s['cognome'] ?? '')) === '') {
            $errori[] = "{$label}: il cognome è obbligatorio.";
        }
        if (trim((string)($s['nome'] ?? '')) === '') {
            $errori[] = "{$label}: il nome è obbligatorio per le persone fisiche.";
        }

        $derivati = self::deriveFromCf($cf);

        $sesso = !empty($s['sesso']) ? strtoupper((string)$s['sesso']) : ($derivati['sesso'] ?? null);
        if ($sesso !== 'M' && $sesso !== 'F') {
            $errori[] = "{$label}: sesso non determinabile (impostare M/F).";
        }

        $dataN = !empty($s['data_nascita']) ? $s['data_nascita'] : ($derivati['data_nascita'] ?? null);
        if (empty($dataN)) {
            $errori[] = "{$label}: data di nascita mancante o non valida.";
        }

        if (trim((string)($s['comune_nascita'] ?? '')) === '') {
            $errori[] = "{$label}: il comune di nascita è obbligatorio.";
        }

        // Provincia di nascita: obbligatoria per i soggetti italiani (senza stato estero).
        $statoEstero = trim((string)($s['codice_stato_estero'] ?? $s['stato_estero'] ?? ''));
        if ($statoEstero === '') {
            $prov = strtoupper(trim((string)($s['prov_nascita'] ?? '')));
            if ($prov === '') {
                $errori[] = "{$label}: la provincia di nascita è obbligatoria " .
                            "(sigla di 2 lettere, es. RM).";
            } elseif (!preg_match('/^[A-Z]{2}$/', $prov)) {
                $errori[] = "{$label}: la provincia di nascita '{$prov}' non è valida " .
                            "(servono 2 lettere, es. RM).";
            }
        }

        return $errori;
    }

    private static function isSocieta(string $cf): bool
    {
        return strlen($cf) === 11 && ctype_digit($cf);
    }

    private static function isValidCf(string $cf): bool
    {
        if (strlen($cf) === 11 && ctype_digit($cf)) {
            return true;
        }
        return (bool)preg_match(
            '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/',
            $cf
        );
    }

    /**
     * Verifica il formato IDTelematico: 17 caratteri secondo cm:DatoAN_Type.
     * L'underscore non è ammesso.
     */
    private static function isValidIdTelematico(string $cod): bool
    {
        return (bool)preg_match(
            "/^[0-9A-Z\\-][0-9A-Z&'\\-.,\\s\\/°\\^\\(\\)ÀÈÉÌÒÙÜ\"\\\\|\\+]*$/u",
            $cod
        );
    }

    /**
     * Deriva sesso e data di nascita (ggmmaaaa) da un CF di persona fisica.
     *
     * @return array{sesso: ?string, data_nascita: ?string}
     */
    private static function deriveFromCf(string $cf): array
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
}
