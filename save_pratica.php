<?php
/**
 * save_pratica.php
 * Gestione contratti + eventi (save/update/delete) con validazione PDF/A.
 *
 * Azioni supportate:
 *   - delete_contratto
 *   - save_contratto    (creazione)
 *   - update_contratto  (modifica)
 *   - save_evento       (nuovo adempimento)
 *   - update_evento     (modifica adempimento)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');



// === CHECK POST TROPPO GRANDE ===
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && empty($_FILES)
    && !empty($_SERVER['CONTENT_LENGTH'])
    && (int)$_SERVER['CONTENT_LENGTH'] > 0
) {
    $postMax = ini_get('post_max_size');
    $contentLen = round((int)$_SERVER['CONTENT_LENGTH'] / 1024 / 1024, 2);
    echo json_encode([
        'ok' => false,
        'error' => "Il file allegato è troppo grande per la configurazione del server. "
                 . "Dimensione richiesta: {$contentLen} MB. Limite PHP attuale (post_max_size): {$postMax}. "
                 . "Contatta l'amministratore per aumentare post_max_size a 12M."
    ]);
    exit;
}

include 'db.php';
require_once 'config.php';
require_once 'lib/RliTaxCalculator.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/Validators.php';
require_once 'lib/AttachmentStorage.php';
require_once 'lib/PdfAValidator.php';

$tax = new RliTaxCalculator($pdo);
$repo = new ContrattoRepository($pdo);



if (!$auth->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta. Ricarica la pagina e riprova.']);
    exit;
}

// === VERIFICA PERMESSI CONTRATTO ===
// Se l'azione modifica un contratto esistente, verifica permesso di scrittura
$idContrattoCheck = (int)($_POST['id_contratto'] ?? 0);
if ($idContrattoCheck > 0) {
    $permesso = $auth->permessoContratto($idContrattoCheck);
    if ($permesso === null) {
        echo json_encode(['ok' => false, 'error' => 'Non hai accesso a questo contratto.']);
        exit;
    }
    if ($permesso === 'view') {
        echo json_encode(['ok' => false, 'error' => 'Hai solo permesso di lettura su questo contratto: non puoi modificarlo.']);
        exit;
    }
}

// CSRF check
Security::requireCsrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Sicurezza anti-duplicato: se il client invia "save_contratto" ma è presente
// un id_contratto, esiste già una bozza/contratto da aggiornare. Trattiamo
// quindi l'operazione come update per non creare un secondo contratto identico.
if ($action === 'save_contratto' && (int)($_POST['id_contratto'] ?? 0) > 0) {
    $action = 'update_contratto';
}

/**
 * Valida e, se necessario, converte in PDF/A.
 * Ritorna [bool ok, string messaggio, ?string $blob, ?string $nomeFile]
 */
/**
 * Valida un file PDF/A.
 *
 * NON esegue conversione automatica.
 * Se il file non è PDF/A conforme, restituisce errore bloccante con istruzioni.
 *
 * @return array [bool ok, string messaggio, ?string $blob, ?string $nomeFile]
 */
function validaEConvertiAllegato(array $file, bool $autoConvert = false): array {
    switch ($file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [false, 'Il file supera la dimensione massima consentita dal server.', null, null];
        case UPLOAD_ERR_PARTIAL:
            return [false, 'Upload incompleto. Riprova.', null, null];
        case UPLOAD_ERR_NO_FILE:
            return [false, 'Nessun file caricato.', null, null];
        default:
            return [false, 'Errore upload (codice ' . $file['error'] . ').', null, null];
    }

    if ($file['size'] > RLI_MAX_PDF_BYTES) {
        return [false, 'Il PDF è troppo grande (' . round($file['size'] / 1024 / 1024, 2) . ' MB). Massimo: ' . round(RLI_MAX_PDF_BYTES / 1024 / 1024, 2) . ' MB.', null, null];
    }

    $result = PdfAValidator::validate($file['tmp_name'], $file['name']);

    if ($result['ok']) {
        return [true, '', file_get_contents($file['tmp_name']), basename($file['name'])];
    }

    // Validazione fallita: messaggio bloccante con istruzioni
    $msg = "⚠ Il file NON è un PDF/A conforme.\n\n";
    $msg .= "Dettaglio: " . $result['message'] . "\n\n";
    $msg .= "Per convertirlo:\n";
    $msg .= "1. Accedi all'Area Riservata AdE\n";
    $msg .= "2. Vai su CIVIS → Validazione e conversione file\n";
    $msg .= "3. Carica il PDF e richiedi la conversione in PDF/A\n";
    $msg .= "4. Scarica il file convertito e ricaricalo qui\n\n";
    $msg .= "In alternativa usa Adobe Acrobat Pro (Esporta come PDF/A).";

    return [false, $msg, null, null];
}
/**
 * Valida l'allegato in base ai dati POST.
 *
 * @param array $post              $_POST
 * @param array $files             $_FILES
 * @param bool  $allegatoEsistente Se true, un allegato è già presente nel DB (caso update)
 * @return array [bool ok, string messaggio, ?string $blob, ?string $nomeFile]
 */
/**
 * Valida l'allegato in base ai dati POST.
 *
 * NON esegue più conversione automatica: il PDF deve essere già PDF/A conforme.
 * Se non lo è, restituisce errore bloccante.
 *
 * @param array $post              $_POST
 * @param array $files             $_FILES
 * @param bool  $allegatoEsistente Se true, un allegato è già presente nel DB (caso update)
 * @return array [bool ok, string messaggio, ?string $blob, ?string $nomeFile]
 */
function validaAllegatoPdf(array $post, array $files, bool $allegatoEsistente = false): array {
    $nonAllegato = !empty($post['contratto_non_allegato']);

    // Nessun nuovo file caricato?
    $nessunNuovoFile = !isset($files['allegato_pdf'])
                    || $files['allegato_pdf']['error'] === UPLOAD_ERR_NO_FILE;

    // Caso 1: "Contratto non allegato" spuntato
    if ($nonAllegato) {
        if (!$nessunNuovoFile && $files['allegato_pdf']['error'] === UPLOAD_ERR_OK) {
            // L'utente ha spuntato "non allegato" ma ha anche caricato un file: prevale il file
            return validaEConvertiAllegato($files['allegato_pdf'], false);
        }
        // Nessun file nuovo + non allegato: rimuovi eventuale allegato esistente
        return [true, '', null, null];
    }

    // Caso 2: nessun nuovo file caricato
    if ($nessunNuovoFile) {
        if ($allegatoEsistente) {
            // Update: mantieni l'allegato esistente
            return [true, '', null, null];
        }
        // Creazione: allegato obbligatorio
        return [false, "Allegato PDF/A mancante.\n\nCarica un PDF/A conforme oppure spunta \"Contratto non allegato\".", null, null];
    }

    // Caso 3: nuovo file caricato → valida (SENZA conversione automatica)
    return validaEConvertiAllegato($files['allegato_pdf'], false);
}

/**
 * Verifica se esiste già un allegato PDF per un contratto.
 */
function esisteAllegatoContratto(PDO $pdo, int $idContratto): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM eventi_contratto
        WHERE id_contratto = ?
          AND (
                (allegato_pdf IS NOT NULL AND LENGTH(allegato_pdf) > 0)
                OR allegato_path IS NOT NULL
          )
    ");
    $stmt->execute([$idContratto]);
    return ((int)$stmt->fetchColumn()) > 0;
}

/**
 * Salva su disco un allegato PDF (se presente) e restituisce path/sha256.
 * Se $blob è null/vuoto, restituisce path/sha256 null (nessun nuovo file).
 *
 * @return array{path: ?string, sha256: ?string, name: ?string}
 */
function salvaAllegatoSuDisco(?string $blob, ?string $name): array {
    if ($blob === null || $blob === '') {
        return ['path' => null, 'sha256' => null, 'name' => $name];
    }
    $saved = AttachmentStorage::save($blob, $name ?: 'allegato.pdf');
    return ['path' => $saved['path'], 'sha256' => $saved['sha256'], 'name' => $name];
}

try {
    // =====================================================
    // AZIONE: DELETE CONTRATTO
    // =====================================================
    if ($action === 'delete_contratto') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID mancante']); exit; }
        $pdo->prepare("DELETE FROM contratti WHERE id = ?")->execute([$id]);
        Logger::azione('contratto_delete', 'contratto', $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    // =====================================================
    // AZIONE: SALVA CONTRATTO (nuovo)
    // =====================================================
    if ($action === 'save_contratto') {
        // In creazione: allegato obbligatorio (se non spuntato "non allegato")
        list($allegatoOk, $allegatoErr, $pdfBlob, $pdfName) = validaAllegatoPdf($_POST, $_FILES, false);
        if (!$allegatoOk) {
            throw new Exception($allegatoErr);
        }

        $pdo->beginTransaction();

        $idU = trim($_POST['id_contratto_univoco'] ?? '');
        $tipologia = $_POST['tipologia_contratto'] ?? 'L1';
        $ufficio = strtoupper(substr($_POST['ufficio_territoriale'] ?? '', 0, 10));
        $dataStip = $_POST['data_stipula'] ?? null;
        $dal = $_POST['durata_dal'] ?? null;
        $al = $_POST['durata_al'] ?? null;
        $canone = (float)str_replace(',', '.', $_POST['importo_canone'] ?? 0);
        $pagine = (int)($_POST['numero_pagine'] ?? 1);
        $copie = (int)($_POST['numero_copie'] ?? 2);
        $note = trim($_POST['note'] ?? '');

        $iban = !empty($_POST['iban']) ? strtoupper(preg_replace('/\s+/', '', $_POST['iban'])) : null;
        $cfInt = !empty($_POST['cf_intestatario_cc']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intestatario_cc'])) : null;
        if ($iban !== null && !Validators::iban($iban)) {
            $check = Validators::ibanValidate($iban);
            throw new Exception("IBAN non valido: " . ($check['error'] ?: 'formato errato'));
        }

        $cfIntermediario = !empty($_POST['cf_intermediario']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intermediario'])) : null;
        $dataImpegno = !empty($_POST['data_impegno_intermediario']) ? $_POST['data_impegno_intermediario'] : null;
        if ($cfIntermediario && !$dataImpegno) throw new Exception("Intermediario: CF compilato ma manca Data Impegno.");

        $ravvAccettato = !empty($_POST['ravvedimento_accettato']) ? 1 : 0;
        $ravvDettagli = $_POST['ravvedimento_dettagli_json'] ?? null;
        $dataInvio = !empty($_POST['data_invio']) ? $_POST['data_invio'] : date('Y-m-d');
        $comunicaDatiCatastali = !empty($_POST['comunica_dati_catastali']) ? 1 : 0;
        $contrattoNonAllegato = !empty($_POST['contratto_non_allegato']) ? 1 : 0;
        $eventiEccezionali = !empty($_POST['eventi_eccezionali']) ? $_POST['eventi_eccezionali'] : null;

        $casiParticolari = !empty($_POST['casi_particolari']) ? (int)$_POST['casi_particolari'] : null;
        $contrattoEsente = !empty($_POST['contratto_esente']) ? (int)$_POST['contratto_esente'] : null;
        $agevolazioni = !empty($_POST['agevolazioni']) ? 1 : 0;
        $pagamentoInteraDurata = !empty($_POST['pagamento_intera_durata']) ? 1 : 0;
        $condSospensiva = !empty($_POST['condizione_sospensiva']) ? 1 : 0;
        $tempoIndet = !empty($_POST['contratto_tempo_indeterminato']) ? 1 : 0;
        $clausolaPenale = !empty($_POST['clausola_penale_volontaria']) ? 1 : 0;
        $contrattoIVA = !empty($_POST['contratto_soggetto_iva']) ? 1 : 0;
        $tipoGaranzia = !empty($_POST['tipo_garanzia']) ? (int)$_POST['tipo_garanzia'] : null;
        $importoGaranzia = !empty($_POST['importo_garanzia']) ? (float)str_replace(',', '.', $_POST['importo_garanzia']) : null;
        $cfGarante = !empty($_POST['cf_garante']) ? strtoupper(trim($_POST['cf_garante'])) : null;
        $cfGarante2 = !empty($_POST['cf_garante2']) ? strtoupper(trim($_POST['cf_garante2'])) : null;
        $garanziaIVA = !empty($_POST['garanzia_soggetta_iva']) ? 1 : 0;
        $numAllegatoA = (int)($_POST['numero_allegato_a'] ?? 0);
        $numAllegatoB = (int)($_POST['numero_allegato_b'] ?? 0);
        $numAllegatoC = (int)($_POST['numero_allegato_c'] ?? 0);
        $canoniVariabili = !empty($_POST['canoni_variabili']) ? $_POST['canoni_variabili'] : null;

        $locatoriIds = $_POST['locatori'] ?? [];
        $locatoriData = [];
        foreach ($locatoriIds as $lid) {
            $locatoriData[] = [
                'id' => (int)$lid,
                'quota' => (float)($_POST['quota_' . $lid] ?? 100),
                'richiedente' => ($_POST['richiedente_id'] ?? null) == $lid ? 1 : 0,
                'cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
                'opzione_cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
            ];
        }
        file_put_contents('/tmp/debug_richiedente.log', 'POST=' . ($_POST['richiedente_id'] ?? 'NULL') . ' | locatoriData=' . json_encode($locatoriData) . "\n", FILE_APPEND);
        $conduttoriIds = $_POST['conduttori'] ?? [];
        $immobiliIds = $_POST['immobili'] ?? [];
        $immobiliData = [];
        foreach ($immobiliIds as $iid) {
            $immobiliData[] = [
                'id' => (int)$iid,
                'ip' => !empty($_POST['ip_i_' . $iid]) ? 'I' : 'P',
                'tipologia' => (int)($_POST['tipologia_imm_' . $iid] ?? 1),
            ];
        }

        $cedCount = count(array_filter($locatoriData, function($l) { return $l['cedolare']; }));
        $ced = '3';
        if (count($locatoriData) > 0 && $cedCount === count($locatoriData)) $ced = '1';
        elseif ($cedCount > 0) $ced = '2';

        $praticaCalc = [
            'tipologia_adempimento' => '1',
            'tipologia_contratto' => $tipologia,
            'importo_canone' => $canone,
            'durata_dal' => $dal,
            'durata_al' => $al,
            'data_stipula' => $dataStip,
            'data_invio' => $dataInvio,
            'cedolare_secca' => $ced,
            'numero_pagine' => $pagine,
            'numero_copie' => $copie,
            'casi_particolari' => $casiParticolari,
            'contratto_esente' => $contrattoEsente,
            'agevolazioni' => $agevolazioni,
            'pagamento_intera_durata' => $pagamentoInteraDurata,
            'tipo_garanzia' => $tipoGaranzia,
            'importo_garanzia' => $importoGaranzia,
            'garanzia_soggetta_iva' => $garanziaIVA,
            'numero_allegato_a' => $numAllegatoA,
            'numero_allegato_b' => $numAllegatoB,
            'numero_allegato_c' => $numAllegatoC,
        ];
        $impReg = $tax->calcolaImpostaRegistro($praticaCalc, $locatoriData);
        $impBol = $tax->calcolaImpostaBollo($praticaCalc);

        $sanzReg = 0; $intReg = 0; $sanzBol = 0; $intBol = 0;
        if ($ravvAccettato && $ravvDettagli) {
            $rd = json_decode($ravvDettagli, true);
            if (is_array($rd)) {
                $sanzReg = (float)($rd['sanzione_registro'] ?? 0);
                $sanzBol = (float)($rd['sanzione_bollo'] ?? 0);
                $intReg = (float)($rd['interessi_registro'] ?? 0);
                $intBol = (float)($rd['interessi_bollo'] ?? 0);
            }
        } else {
            $ravv = $tax->calcolaRavvedimento($praticaCalc, $impReg, $impBol);
            $sanzReg = $ravv['sanzione_registro'];
            $sanzBol = $ravv['sanzione_bollo'];
            $intReg = $ravv['interessi_registro'];
            $intBol = $ravv['interessi_bollo'];
        }

        if ($action === 'save_bozza') { $impReg = 0; $impBol = 0; $sanzReg = 0; $sanzBol = 0; $intReg = 0; $intBol = 0; }
        $totaleDaVersare = $impReg + $impBol + $sanzReg + $sanzBol + $intReg + $intBol;
        if ($totaleDaVersare > 0) {
            $mancanti = [];
            if (empty($iban) && $action !== 'save_bozza') $mancanti[] = 'IBAN';
            if (empty($cfInt) && $action !== 'save_bozza') $mancanti[] = 'CF intestatario conto';
            if (!empty($mancanti)) throw new Exception("Totale: € " . number_format($totaleDaVersare, 2, ',', '') . ". Mancanti: " . implode(', ', $mancanti));
        }

        $stmt = $pdo->prepare("
            INSERT INTO contratti (
                id_utente_creatore, id_contratto_univoco, codice_identificativo_contratto, stato, tipologia_contratto, ufficio_territoriale,
                data_stipula, durata_dal, durata_al, importo_canone, numero_pagine, numero_copie,
                tipologia_adempimento, cedolare_secca,
                imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo,
                interessi_registro, interessi_bollo,
                iban, cf_intestatario_cc,
                cf_intermediario, data_impegno_intermediario,
                ravvedimento_accettato, ravvedimento_dettagli_json,
                comunica_dati_catastali, contratto_non_allegato, eventi_eccezionali,
                casi_particolari, contratto_esente, agevolazioni, pagamento_intera_durata,
                condizione_sospensiva, contratto_tempo_indeterminato, clausola_penale_volontaria,
                contratto_soggetto_iva,
                tipo_garanzia, importo_garanzia, cf_garante, cf_garante2, garanzia_soggetta_iva,
                numero_allegato_a, numero_allegato_b, numero_allegato_c,
                canoni_variabili,
                data_impegno, data_invio
            ) VALUES (
                ?, ?, NULL, 'BOZZA', ?, ?,
                ?, ?, ?, ?, ?, ?,
                '1', ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?,
                CURDATE(), ?
            )
        ");

        $stmt->execute([
            $_SESSION['utente_id'] ?? null,
            $idU ?: null, $tipologia, $ufficio,
            $dataStip, $dal, $al, $canone, $pagine, $copie,
            $ced,
            $impReg, $impBol, $sanzReg, $sanzBol,
            $intReg, $intBol,
            $iban, $cfInt,
            $cfIntermediario, $dataImpegno,
            $ravvAccettato, $ravvDettagli,
            $comunicaDatiCatastali, $contrattoNonAllegato, $eventiEccezionali,
            $casiParticolari, $contrattoEsente, $agevolazioni, $pagamentoInteraDurata,
            $condSospensiva, $tempoIndet, $clausolaPenale,
            $contrattoIVA,
            $tipoGaranzia, $importoGaranzia, $cfGarante, $cfGarante2, $garanziaIVA,
            $numAllegatoA, $numAllegatoB, $numAllegatoC,
            $canoniVariabili,
            $dataInvio,
        ]);

        $idContratto = (int)$pdo->lastInsertId();
        // Forza stato ATTIVO alla creazione
        $pdo->prepare("UPDATE contratti SET stato = 'ATTIVO' WHERE id = ?")->execute([$idContratto]);

        $stmtL = $pdo->prepare("INSERT INTO contratto_locatori (id_contratto, id_locatore, richiedente, opzione_cedolare, percentuale_possesso) VALUES (?, ?, ?, ?, ?)");
        foreach ($locatoriData as $l) $stmtL->execute([$idContratto, $l['id'], $l['richiedente'], $l['cedolare'], $l['quota']]);
        $stmtC = $pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)");
        foreach ($conduttoriIds as $cid) $stmtC->execute([$idContratto, (int)$cid]);
        $stmtI = $pdo->prepare("INSERT INTO contratto_immobili (id_contratto, id_immobile, tipologia_immobile, intero_porzione) VALUES (?, ?, ?, ?)");
        foreach ($immobiliData as $i) $stmtI->execute([$idContratto, $i['id'], $i['tipologia'], $i['ip']]);

        $att = salvaAllegatoSuDisco($pdfBlob, $pdfName);
        $stmtE = $pdo->prepare("
            INSERT INTO eventi_contratto (id_contratto, tipo, data_evento, data_invio, dettagli_json, allegato_pdf, allegato_path, allegato_sha256, nome_allegato, imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo, interessi_registro, interessi_bollo)
            VALUES (?, 'registrazione', ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtE->execute([$idContratto, $dataStip, $dataInvio, json_encode(['note' => $note]), $att['path'], $att['sha256'], $pdfName, $impReg, $impBol, $sanzReg, $sanzBol, $intReg, $intBol]);

        $pdo->prepare("UPDATE contratti SET stato = 'ATTIVO' WHERE id = ?")->execute([$idContratto]);

        $pdo->commit();

        Logger::azione('contratto_create', 'contratto', $idContratto, [
            'tipologia' => $tipologia,
            'canone' => $canone,
            'data_invio' => $dataInvio,
            'allegato' => $pdfBlob !== null ? 'sì' : 'no',
        ]);

        header("Location: contract.php?id=$idContratto&msg=Contratto+creato+con+successo");
        exit;
    }

    // =====================================================
    // AZIONE: UPDATE CONTRATTO
    // =====================================================
    if ($action === 'update_contratto') {
        error_log('DEBUG: inizio update_contratto per id=' . ($_POST['id_contratto'] ?? 'NULL'));
        $idContratto = (int)($_POST['id_contratto'] ?? 0);
        if ($idContratto <= 0) throw new Exception("ID contratto mancante");

        $stmtCheck = $pdo->prepare("SELECT * FROM contratti WHERE id = ?");
        $stmtCheck->execute([$idContratto]);
        $contrattoOld = $stmtCheck->fetch();
        if (!$contrattoOld) throw new Exception("Contratto non trovato");

        // >>> VERIFICA ALLEGATO ESISTENTE <<<
        $allegatoEsistente = esisteAllegatoContratto($pdo, $idContratto);

        list($allegatoOk, $allegatoErr, $pdfBlob, $pdfName) = validaAllegatoPdf($_POST, $_FILES, $allegatoEsistente);
        if (!$allegatoOk) {
            throw new Exception($allegatoErr);
        }

        $pdo->beginTransaction();

        $idU = trim($_POST['id_contratto_univoco'] ?? '');
        $tipologia = $_POST['tipologia_contratto'] ?? 'L1';
        $ufficio = strtoupper(substr($_POST['ufficio_territoriale'] ?? '', 0, 10));
        $dataStip = $_POST['data_stipula'] ?? null;
        $dal = $_POST['durata_dal'] ?? null;
        $al = $_POST['durata_al'] ?? null;
        $canone = (float)str_replace(',', '.', $_POST['importo_canone'] ?? 0);
        $pagine = (int)($_POST['numero_pagine'] ?? 1);
        $copie = (int)($_POST['numero_copie'] ?? 2);

        $codiceAdE = !empty($_POST['codice_identificativo_contratto'])
            ? strtoupper(preg_replace('/\s+/', '', $_POST['codice_identificativo_contratto']))
            : null;
        if ($codiceAdE !== null && !preg_match('/^[0-9A-Z]{17}$/', $codiceAdE)) {
            throw new Exception("Il codice identificativo AdE deve essere di 17 caratteri alfanumerici (es. TER20T016318000AA).");
        }

        $iban = !empty($_POST['iban']) ? strtoupper(preg_replace('/\s+/', '', $_POST['iban'])) : null;
        $cfInt = !empty($_POST['cf_intestatario_cc']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intestatario_cc'])) : null;
        if ($iban !== null && !Validators::iban($iban)) {
            $check = Validators::ibanValidate($iban);
            throw new Exception("IBAN non valido: " . ($check['error'] ?: 'formato errato'));
        }

        $cfIntermediario = !empty($_POST['cf_intermediario']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intermediario'])) : null;
        $dataImpegno = !empty($_POST['data_impegno_intermediario']) ? $_POST['data_impegno_intermediario'] : null;
        if ($cfIntermediario && !$dataImpegno) throw new Exception("Intermediario: CF compilato ma manca Data Impegno.");

        $ravvAccettato = !empty($_POST['ravvedimento_accettato']) ? 1 : 0;
        $ravvDettagli = $_POST['ravvedimento_dettagli_json'] ?? null;
        $dataInvio = !empty($_POST['data_invio']) ? $_POST['data_invio'] : date('Y-m-d');
        $comunicaDatiCatastali = !empty($_POST['comunica_dati_catastali']) ? 1 : 0;
        $contrattoNonAllegato = !empty($_POST['contratto_non_allegato']) ? 1 : 0;
        $eventiEccezionali = !empty($_POST['eventi_eccezionali']) ? $_POST['eventi_eccezionali'] : null;

        $casiParticolari = !empty($_POST['casi_particolari']) ? (int)$_POST['casi_particolari'] : null;
        $contrattoEsente = !empty($_POST['contratto_esente']) ? (int)$_POST['contratto_esente'] : null;
        $agevolazioni = !empty($_POST['agevolazioni']) ? 1 : 0;
        $pagamentoInteraDurata = !empty($_POST['pagamento_intera_durata']) ? 1 : 0;
        $condSospensiva = !empty($_POST['condizione_sospensiva']) ? 1 : 0;
        $tempoIndet = !empty($_POST['contratto_tempo_indeterminato']) ? 1 : 0;
        $clausolaPenale = !empty($_POST['clausola_penale_volontaria']) ? 1 : 0;
        $contrattoIVA = !empty($_POST['contratto_soggetto_iva']) ? 1 : 0;
        $tipoGaranzia = !empty($_POST['tipo_garanzia']) ? (int)$_POST['tipo_garanzia'] : null;
        $importoGaranzia = !empty($_POST['importo_garanzia']) ? (float)str_replace(',', '.', $_POST['importo_garanzia']) : null;
        $cfGarante = !empty($_POST['cf_garante']) ? strtoupper(trim($_POST['cf_garante'])) : null;
        $cfGarante2 = !empty($_POST['cf_garante2']) ? strtoupper(trim($_POST['cf_garante2'])) : null;
        $garanziaIVA = !empty($_POST['garanzia_soggetta_iva']) ? 1 : 0;
        $numAllegatoA = (int)($_POST['numero_allegato_a'] ?? 0);
        $numAllegatoB = (int)($_POST['numero_allegato_b'] ?? 0);
        $numAllegatoC = (int)($_POST['numero_allegato_c'] ?? 0);
        $canoniVariabili = !empty($_POST['canoni_variabili']) ? $_POST['canoni_variabili'] : null;

        $locatoriIds = $_POST['locatori'] ?? [];
        $locatoriData = [];
        foreach ($locatoriIds as $lid) {
            $locatoriData[] = [
                'id' => (int)$lid,
                'quota' => (float)($_POST['quota_' . $lid] ?? 100),
                'richiedente' => ($_POST['richiedente_id'] ?? null) == $lid ? 1 : 0,
                'cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
                'opzione_cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
            ];
        }
        file_put_contents('/tmp/debug_richiedente.log', 'UPDATE_CONTRATTO | POST=' . ($_POST['richiedente_id'] ?? 'NULL') . ' | locatoriData=' . json_encode($locatoriData) . "\n", FILE_APPEND);
        $conduttoriIds = $_POST['conduttori'] ?? [];
        $immobiliIds = $_POST['immobili'] ?? [];
        $immobiliData = [];
        foreach ($immobiliIds as $iid) {
            $immobiliData[] = [
                'id' => (int)$iid,
                'ip' => !empty($_POST['ip_i_' . $iid]) ? 'I' : 'P',
                'tipologia' => (int)($_POST['tipologia_imm_' . $iid] ?? 1),
            ];
        }

        $cedCount = count(array_filter($locatoriData, function($l) { return $l['cedolare']; }));
        $ced = '3';
        if (count($locatoriData) > 0 && $cedCount === count($locatoriData)) $ced = '1';
        elseif ($cedCount > 0) $ced = '2';

        $praticaCalc = [
            'tipologia_adempimento' => $contrattoOld['tipologia_adempimento'] ?? '1',
            'tipologia_contratto' => $tipologia,
            'importo_canone' => $canone,
            'durata_dal' => $dal,
            'durata_al' => $al,
            'data_stipula' => $dataStip,
            'data_invio' => $dataInvio,
            'cedolare_secca' => $ced,
            'numero_pagine' => $pagine,
            'numero_copie' => $copie,
            'casi_particolari' => $casiParticolari,
            'contratto_esente' => $contrattoEsente,
            'agevolazioni' => $agevolazioni,
            'pagamento_intera_durata' => $pagamentoInteraDurata,
            'tipo_garanzia' => $tipoGaranzia,
            'importo_garanzia' => $importoGaranzia,
            'garanzia_soggetta_iva' => $garanziaIVA,
            'numero_allegato_a' => $numAllegatoA,
            'numero_allegato_b' => $numAllegatoB,
            'numero_allegato_c' => $numAllegatoC,
        ];
        $impReg = $tax->calcolaImpostaRegistro($praticaCalc, $locatoriData);
        $impBol = $tax->calcolaImpostaBollo($praticaCalc);

        $sanzReg = 0; $intReg = 0; $sanzBol = 0; $intBol = 0;
        if ($ravvAccettato && $ravvDettagli) {
            $rd = json_decode($ravvDettagli, true);
            if (is_array($rd)) {
                $sanzReg = (float)($rd['sanzione_registro'] ?? 0);
                $sanzBol = (float)($rd['sanzione_bollo'] ?? 0);
                $intReg = (float)($rd['interessi_registro'] ?? 0);
                $intBol = (float)($rd['interessi_bollo'] ?? 0);
            }
        } else {
            $ravv = $tax->calcolaRavvedimento($praticaCalc, $impReg, $impBol);
            $sanzReg = $ravv['sanzione_registro'];
            $sanzBol = $ravv['sanzione_bollo'];
            $intReg = $ravv['interessi_registro'];
            $intBol = $ravv['interessi_bollo'];
        }

        if ($action === 'save_bozza') { $impReg = 0; $impBol = 0; $sanzReg = 0; $sanzBol = 0; $intReg = 0; $intBol = 0; }
        $totaleDaVersare = $impReg + $impBol + $sanzReg + $sanzBol + $intReg + $intBol;
        if ($totaleDaVersare > 0) {
            $mancanti = [];
            if (empty($iban) && $action !== 'save_bozza') $mancanti[] = 'IBAN';
            if (empty($cfInt) && $action !== 'save_bozza') $mancanti[] = 'CF intestatario conto';
            if (!empty($mancanti)) throw new Exception("Totale: € " . number_format($totaleDaVersare, 2, ',', '') . ". Mancanti: " . implode(', ', $mancanti));
        }

        $stmt = $pdo->prepare("
            UPDATE contratti SET
                id_contratto_univoco = ?, codice_identificativo_contratto = ?,
                tipologia_contratto = ?, ufficio_territoriale = ?,
                data_stipula = ?, durata_dal = ?, durata_al = ?,
                importo_canone = ?, numero_pagine = ?, numero_copie = ?,
                cedolare_secca = ?,
                imposta_registro = ?, imposta_bollo = ?, sanzione_registro = ?, sanzione_bollo = ?,
                interessi_registro = ?, interessi_bollo = ?,
                iban = ?, cf_intestatario_cc = ?,
                cf_intermediario = ?, data_impegno_intermediario = ?,
                ravvedimento_accettato = ?, ravvedimento_dettagli_json = ?,
                comunica_dati_catastali = ?, contratto_non_allegato = ?, eventi_eccezionali = ?,
                casi_particolari = ?, contratto_esente = ?, agevolazioni = ?, pagamento_intera_durata = ?,
                condizione_sospensiva = ?, contratto_tempo_indeterminato = ?, clausola_penale_volontaria = ?,
                contratto_soggetto_iva = ?,
                tipo_garanzia = ?, importo_garanzia = ?, cf_garante = ?, cf_garante2 = ?, garanzia_soggetta_iva = ?,
                numero_allegato_a = ?, numero_allegato_b = ?, numero_allegato_c = ?,
                canoni_variabili = ?,
                data_invio = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $idU ?: null, $codiceAdE,
            $tipologia, $ufficio,
            $dataStip, $dal, $al,
            $canone, $pagine, $copie,
            $ced,
            $impReg, $impBol, $sanzReg, $sanzBol,
            $intReg, $intBol,
            $iban, $cfInt,
            $cfIntermediario, $dataImpegno,
            $ravvAccettato, $ravvDettagli,
            $comunicaDatiCatastali, $contrattoNonAllegato, $eventiEccezionali,
            $casiParticolari, $contrattoEsente, $agevolazioni, $pagamentoInteraDurata,
            $condSospensiva, $tempoIndet, $clausolaPenale,
            $contrattoIVA,
            $tipoGaranzia, $importoGaranzia, $cfGarante, $cfGarante2, $garanziaIVA,
            $numAllegatoA, $numAllegatoB, $numAllegatoC,
            $canoniVariabili,
            $dataInvio,
            $idContratto
        ]);

        // Forza lo stato ad ATTIVO se era in BOZZA (non tocca CESSATO/RISOLTO)
        $stmtStato = $pdo->prepare("UPDATE contratti SET stato = 'ATTIVO' WHERE id = ? AND stato = 'BOZZA'");
        $stmtStato->execute([$idContratto]);
        error_log('DEBUG: stato aggiornato per id=' . $idContratto . ', righe modificate=' . $stmtStato->rowCount());
        
        $pdo->prepare("DELETE FROM contratto_locatori WHERE id_contratto = ?")->execute([$idContratto]);
        $pdo->prepare("DELETE FROM contratto_conduttori WHERE id_contratto = ?")->execute([$idContratto]);
        $pdo->prepare("DELETE FROM contratto_immobili WHERE id_contratto = ?")->execute([$idContratto]);

        $stmtL = $pdo->prepare("INSERT INTO contratto_locatori (id_contratto, id_locatore, richiedente, opzione_cedolare, percentuale_possesso) VALUES (?, ?, ?, ?, ?)");
        foreach ($locatoriData as $l) $stmtL->execute([$idContratto, $l['id'], $l['richiedente'], $l['cedolare'], $l['quota']]);
        $stmtC = $pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)");
        foreach ($conduttoriIds as $cid) $stmtC->execute([$idContratto, (int)$cid]);
        $stmtI = $pdo->prepare("INSERT INTO contratto_immobili (id_contratto, id_immobile, tipologia_immobile, intero_porzione) VALUES (?, ?, ?, ?)");
        foreach ($immobiliData as $i) $stmtI->execute([$idContratto, $i['id'], $i['tipologia'], $i['ip']]);

        // >>> AGGIORNA O CREA EVENTO REGISTRAZIONE <<<
        $stmtCheckEv = $pdo->prepare("SELECT id FROM eventi_contratto WHERE id_contratto = ? AND tipo = 'registrazione' LIMIT 1");
        $stmtCheckEv->execute([$idContratto]);
        $esisteEvento = $stmtCheckEv->fetchColumn();

        if ($esisteEvento) {
            // Evento esiste: UPDATE
            if ($pdfBlob !== null) {
                $att = salvaAllegatoSuDisco($pdfBlob, $pdfName);
                $stmtEvUpd = $pdo->prepare("
                    UPDATE eventi_contratto
                    SET data_evento = ?, data_invio = ?, imposta_registro = ?, imposta_bollo = ?,
                        sanzione_registro = ?, sanzione_bollo = ?, interessi_registro = ?, interessi_bollo = ?,
                        allegato_pdf = NULL, allegato_path = ?, allegato_sha256 = ?, nome_allegato = ?
                    WHERE id = ?
                ");
                $stmtEvUpd->execute([$dataStip, $dataInvio, $impReg, $impBol, $sanzReg, $sanzBol, $intReg, $intBol, $att['path'], $att['sha256'], $pdfName, $esisteEvento]);
            } else {
                $stmtEvUpd = $pdo->prepare("
                    UPDATE eventi_contratto
                    SET data_evento = ?, data_invio = ?, imposta_registro = ?, imposta_bollo = ?,
                        sanzione_registro = ?, sanzione_bollo = ?, interessi_registro = ?, interessi_bollo = ?
                    WHERE id = ?
                ");
                $stmtEvUpd->execute([$dataStip, $dataInvio, $impReg, $impBol, $sanzReg, $sanzBol, $intReg, $intBol, $esisteEvento]);
            }
        } else {
            // Evento NON esiste: INSERT
            $att = salvaAllegatoSuDisco($pdfBlob, $pdfName);
            $stmtEvIns = $pdo->prepare("
                INSERT INTO eventi_contratto
                    (id_contratto, tipo, data_evento, data_invio, imposta_registro, imposta_bollo,
                     sanzione_registro, sanzione_bollo, interessi_registro, interessi_bollo,
                     allegato_pdf, allegato_path, allegato_sha256, nome_allegato)
                VALUES (?, 'registrazione', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
            ");
            $stmtEvIns->execute([
                $idContratto, $dataStip, $dataInvio, $impReg, $impBol, $sanzReg, $sanzBol, $intReg, $intBol,
                $att['path'], $att['sha256'], $pdfName
            ]);
        }

        $pdo->commit();

        Logger::azione('contratto_update', 'contratto', $idContratto, [
            'data_invio' => $dataInvio,
            'allegato' => $pdfBlob !== null ? 'sostituito' : 'mantenuto',
        ]);

        header("Location: contract.php?id=$idContratto&msg=Contratto+aggiornato+con+successo");
        exit;
    }

    // =====================================================
    // AZIONE: SALVA EVENTO (nuovo)
    // =====================================================
    if ($action === 'save_evento') {
        $pdo->beginTransaction();

        $idContratto = (int)($_POST['id_contratto'] ?? 0);
        $tipo = $_POST['tipo_evento'] ?? 'proroga';
        if ($idContratto <= 0) throw new Exception("ID contratto mancante");

        $stmtC = $pdo->prepare("SELECT * FROM contratti WHERE id = ?");
        $stmtC->execute([$idContratto]);
        $c = $stmtC->fetch();
        if (!$c) throw new Exception("Contratto non trovato");

        $dataEvento = $_POST['data_evento'] ?? date('Y-m-d');
        $dataInvio = !empty($_POST['data_invio']) ? $_POST['data_invio'] : date('Y-m-d');
        $dettagli = ['note' => trim($_POST['note_evento'] ?? '')];
        if (!empty($_POST['data_impegno_intermediario'])) {
            $dettagli['data_impegno_intermediario'] = $_POST['data_impegno_intermediario'];
        }
        if ($tipo === 'subentro' || $tipo === 'cessione') {
            $latoKey = ($tipo === 'subentro') ? 'lato_subentro' : 'lato_cessione';
            if (!empty($_POST[$latoKey])) {
                $dettagli[$latoKey] = $_POST[$latoKey];
            }
            if (!empty($_POST['soggetto_cedente'])) {
                $dettagli['soggetto_cedente_id'] = (int)$_POST['soggetto_cedente'];
            }
            if (!empty($_POST['cessionari'])) {
                $dettagli['cessionari_ids'] = array_map('intval', $_POST['cessionari']);
            }
        }
        if ($tipo === 'subentro' && !empty($_POST['lato_subentro'])) {
            $dettagli['lato_subentro'] = $_POST['lato_subentro'];
        }
        if (!empty($_POST['data_impegno_intermediario'])) {
            $dettagli['data_impegno_intermediario'] = $_POST['data_impegno_intermediario'];
        }

        // I dati specifici dell'adempimento restano NELL'EVENTO (dettagli_json).
        // La tabella contratti viene riallineata dopo, leggendo l'ultimo evento.
        if ($tipo === 'proroga') {
            $dettagli['data_fine_proroga'] = $_POST['data_fine_proroga'] ?? null;
            $dettagli['tipologia_proroga'] = $_POST['tipologia_proroga'] ?? null;
        } elseif ($tipo === 'annualita') {
            $dettagli['annualita'] = $_POST['annualita'] ?? null;
            $dettagli['tardivita_annualita'] = $_POST['tardivita_annualita'] ?? null;
        } elseif ($tipo === 'cessione') {
            $dettagli['data_cessione'] = $_POST['data_cessione'] ?? null;
            $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
        } elseif ($tipo === 'subentro') {
            $dettagli['data_subentro'] = $_POST['data_subentro'] ?? null;
            $dettagli['tipologia_subentro'] = $_POST['tipologia_subentro'] ?? null;
        } elseif ($tipo === 'risoluzione' || $tipo === 'risoluzione_corrispettivo') {
            $dettagli['durata_al'] = $_POST['durata_al'] ?? null;
            if ($tipo === 'risoluzione_corrispettivo') $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
        } elseif ($tipo === 'rinegoziazione') {
            $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
            $dettagli['rinegoziazione_tipo'] = $_POST['rinegoziazione_tipo'] ?? 'aumento';
            $dettagli['data_inizio_rinegoziazione'] = $_POST['data_inizio_rinegoziazione'] ?? null;
            $dettagli['data_stipula'] = $_POST['data_stipula'] ?? null;
        }

        // Allegato opzionale per gli eventi
        $pdfBlob = null; $pdfName = null;
        if (isset($_FILES['allegato_pdf']) && $_FILES['allegato_pdf']['error'] === UPLOAD_ERR_OK) {
            list($ok, $err, $blob, $name) = validaEConvertiAllegato($_FILES["allegato_pdf"], false);
            if (!$ok) throw new Exception($err);
            $pdfBlob = $blob;
            $pdfName = $name;
        }

        $praticaCalc = $c;
        $mapTipo = ['annualita'=>'1','proroga'=>'2','cessione'=>'3','risoluzione'=>'4','conguaglio'=>'5','subentro'=>'6','risoluzione_corrispettivo'=>'7','rinegoziazione'=>'8'];
        $praticaCalc['tipologia_adempimento'] = $mapTipo[$tipo] ?? '1';
        $praticaCalc['tipo_adempimento'] = $praticaCalc['tipologia_adempimento'];
        $praticaCalc['data_invio'] = $dataInvio;
        $praticaCalc['data_fine_proroga'] = $dettagli['data_fine_proroga'] ?? null;
        $praticaCalc['data_cessione'] = $dettagli['data_cessione'] ?? null;
        $praticaCalc['data_subentro'] = $dettagli['data_subentro'] ?? null;
        $praticaCalc['corrispettivo'] = $dettagli['corrispettivo'] ?? 0;
        $praticaCalc['durata_al'] = $dettagli['durata_al'] ?? ($c['durata_al'] ?? null);
        $praticaCalc['rinegoziazione_tipo'] = $dettagli['rinegoziazione_tipo'] ?? 'aumento';
        $praticaCalc['data_inizio_rinegoziazione'] = $dettagli['data_inizio_rinegoziazione'] ?? null;
        $praticaCalc['annualita'] = $dettagli['annualita'] ?? null;

        $stmtL = $pdo->prepare("SELECT s.*, cl.opzione_cedolare, cl.percentuale_possesso FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore WHERE cl.id_contratto = ?");
        $stmtL->execute([$idContratto]);
        $lista_loc = $stmtL->fetchAll();

        $impReg = $tax->calcolaImpostaRegistro($praticaCalc, $lista_loc);
        $impBol = $tax->calcolaImpostaBollo($praticaCalc);

        $ravv = $tax->calcolaRavvedimento($praticaCalc, $impReg, $impBol);
        $sanzReg = $ravv['sanzione_registro'];
        $sanzBol = $ravv['sanzione_bollo'];
        $intReg = $ravv['interessi_registro'];
        $intBol = $ravv['interessi_bollo'];

        // --- GESTIONE SPUNTE SOGGETTI (Subentro/Cessione) ---
        // 1. Rimuovi tutti i locatori attuali e reinserisci solo quelli spuntati con le loro quote e cedolari
        $locatoriDaMantenere = $_POST['locatori'] ?? [];
        $quoteLocatori = $_POST['quote_locatori'] ?? [];
        $cedolariLocatori = $_POST['cedolare_locatori'] ?? [];
        $richiedenteSelezionato = (int)($_POST['nuovo_richiedente'] ?? 0);
        
        // ============================================================
        // GESTIONE SOGGETTI
        // - Subentro: logica specifica (cedente + cessionari, lato)
        // - Cessione/Rinegoziazione: logica a spunte
        // - Altri tipi (proroga, annualità, risoluzione): NON toccare
        // ============================================================
        
        if (in_array($tipo, ['subentro', 'cessione'], true)) {
            // Logica specifica per subentro e cessione
            $latoKey = ($tipo === 'subentro') ? 'lato_subentro' : 'lato_cessione';
            $lato = $_POST[$latoKey] ?? 'entrambi';
            $idCedente = (int)($_POST['soggetto_cedente'] ?? 0);
            $cessionari = $_POST['cessionari'] ?? [];

            // Rimuovi il cedente dal lato corretto
            if ($idCedente > 0) {
                if (in_array($lato, ['locatore', 'entrambi'], true)) {
                    $pdo->prepare("DELETE FROM contratto_locatori WHERE id_contratto = ? AND id_locatore = ?")->execute([$idContratto, $idCedente]);
                }
                if (in_array($lato, ['conduttore', 'entrambi'], true)) {
                    $pdo->prepare("DELETE FROM contratto_conduttori WHERE id_contratto = ? AND id_conduttore = ?")->execute([$idContratto, $idCedente]);
                }
            }

            // Aggiungi i cessionari al lato corretto
            foreach ($cessionari as $idCess) {
                $idCess = (int)$idCess;
                if ($idCess <= 0) continue;
                
                if (in_array($lato, ['locatore', 'entrambi'], true)) {
                    $chk = $pdo->prepare("SELECT id FROM contratto_locatori WHERE id_contratto = ? AND id_locatore = ?");
                    $chk->execute([$idContratto, $idCess]);
                    if (!$chk->fetch()) {
                        $pdo->prepare("INSERT INTO contratto_locatori (id_contratto, id_locatore, percentuale_possesso) VALUES (?, ?, 100)")->execute([$idContratto, $idCess]);
                    }
                }
                if (in_array($lato, ['conduttore', 'entrambi'], true)) {
                    $chk = $pdo->prepare("SELECT id FROM contratto_conduttori WHERE id_contratto = ? AND id_conduttore = ?");
                    $chk->execute([$idContratto, $idCess]);
                    if (!$chk->fetch()) {
                        $pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)")->execute([$idContratto, $idCess]);
                    }
                }
            }
        } 
        elseif (in_array($tipo, ['cessione', 'rinegoziazione'], true)) {
            // Logica a spunte (per cessione e rinegoziazione)
            if (isset($_POST['locatori']) || isset($_POST['conduttori'])) {
                $locatoriDaMantenere = $_POST['locatori'] ?? [];
                $quoteLocatori = $_POST['quote_locatori'] ?? [];
                $cedolariLocatori = $_POST['cedolare_locatori'] ?? [];
                $richiedenteSelezionato = (int)($_POST['nuovo_richiedente'] ?? 0);

                $pdo->prepare("DELETE FROM contratto_locatori WHERE id_contratto = ?")->execute([$idContratto]);
                $stmtL = $pdo->prepare("INSERT INTO contratto_locatori (id_contratto, id_locatore, percentuale_possesso, opzione_cedolare, richiedente) VALUES (?, ?, ?, ?, ?)");
                foreach ($locatoriDaMantenere as $idLoc) {
                    $quota = isset($quoteLocatori[$idLoc]) ? (float)$quoteLocatori[$idLoc] : 100;
                    $cedolare = isset($cedolariLocatori[$idLoc]) ? 1 : 0;
                    $isRichiedente = ($idLoc == $richiedenteSelezionato) ? 1 : 0;
                    $stmtL->execute([$idContratto, (int)$idLoc, $quota, $cedolare, $isRichiedente]);
                }

                $conduttoriDaMantenere = $_POST['conduttori'] ?? [];
                $pdo->prepare("DELETE FROM contratto_conduttori WHERE id_contratto = ?")->execute([$idContratto]);
                $stmtC = $pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)");
                foreach ($conduttoriDaMantenere as $idCond) {
                    $stmtC->execute([$idContratto, (int)$idCond]);
                }
            }
        }
        // Per proroga, annualità, risoluzione: NON toccare i soggetti




        // Salviamo il cedente (chi esce) nei dettagli dell'evento
        if (($tipo === 'subentro' || $tipo === 'cessione') && !empty($_POST['soggetto_cedente'])) {
            $dettagli['soggetto_cedente_id'] = (int)$_POST['soggetto_cedente'];
        }

        $att = salvaAllegatoSuDisco($pdfBlob, $pdfName);
        $stmtE = $pdo->prepare("
            INSERT INTO eventi_contratto (id_contratto, tipo, data_evento, data_invio, dettagli_json, allegato_pdf, allegato_path, allegato_sha256, nome_allegato, imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo, interessi_registro, interessi_bollo)
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtE->execute([$idContratto, $tipo, $dataEvento, $dataInvio, json_encode($dettagli), $att['path'], $att['sha256'], $pdfName, $impReg, $impBol, $sanzReg, $sanzBol, $intReg, $intBol]);

        // Riallinea i campi denormalizzati del contratto all'ultimo evento.
        $repo->sincronizzaStatoDaEventi($idContratto);

        $pdo->commit();

        Logger::azione('evento_create', 'evento', $idContratto, ['tipo' => $tipo, 'data_invio' => $dataInvio]);

        header("Location: contract.php?id=$idContratto&msg=Evento+registrato");
        exit;
    }

    // =====================================================
    // AZIONE: UPDATE EVENTO
    // =====================================================
    if ($action === 'update_evento') {
        $idEvento = (int)($_POST['id_evento'] ?? 0);
        $idContratto = (int)($_POST['id_contratto'] ?? 0);
        $tipo = $_POST['tipo_evento'] ?? 'proroga';

        if ($idEvento <= 0 || $idContratto <= 0) throw new Exception("ID evento o contratto mancante");

        $stmtEv = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
        $stmtEv->execute([$idEvento, $idContratto]);
        $eventoOld = $stmtEv->fetch();
        if (!$eventoOld) throw new Exception("Evento non trovato");

        if ($eventoOld['tipo'] === 'registrazione') {
            throw new Exception("L'evento di registrazione non si modifica da qui. Usa 'Modifica contratto'.");
        }

        $stmtC = $pdo->prepare("SELECT * FROM contratti WHERE id = ?");
        $stmtC->execute([$idContratto]);
        $c = $stmtC->fetch();
        if (!$c) throw new Exception("Contratto non trovato");

        $pdo->beginTransaction();

        $dataEvento = $_POST['data_evento'] ?? date('Y-m-d');
        $dataInvio = !empty($_POST['data_invio']) ? $_POST['data_invio'] : date('Y-m-d');
        $dettagli = ['note' => trim($_POST['note_evento'] ?? '')];

        if ($tipo === 'annualita') {
            $dettagli['annualita'] = $_POST['annualita'] ?? null;
            $dettagli['tardivita_annualita'] = $_POST['tardivita_annualita'] ?? null;
        } elseif ($tipo === 'proroga') {
            $dettagli['data_fine_proroga'] = $_POST['data_fine_proroga'] ?? null;
            $dettagli['tipologia_proroga'] = $_POST['tipologia_proroga'] ?? null;
        } elseif ($tipo === 'cessione') {
            $dettagli['data_cessione'] = $_POST['data_cessione'] ?? null;
            $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
        } elseif ($tipo === 'subentro') {
            $dettagli['data_subentro'] = $_POST['data_subentro'] ?? null;
            $dettagli['tipologia_subentro'] = $_POST['tipologia_subentro'] ?? null;
        } elseif ($tipo === 'risoluzione' || $tipo === 'risoluzione_corrispettivo') {
            $dettagli['durata_al'] = $_POST['durata_al'] ?? null;
            if ($tipo === 'risoluzione_corrispettivo') $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
        } elseif ($tipo === 'rinegoziazione') {
            $dettagli['corrispettivo'] = $_POST['corrispettivo'] ?? null;
            $dettagli['rinegoziazione_tipo'] = $_POST['rinegoziazione_tipo'] ?? 'aumento';
            $dettagli['data_inizio_rinegoziazione'] = $_POST['data_inizio_rinegoziazione'] ?? null;
            $dettagli['data_stipula'] = $_POST['data_stipula'] ?? null;
        }

        // >>> ALLEGATO: mantieni quello esistente se non ne viene caricato uno nuovo <<<
        $pdfBlob = $eventoOld['allegato_pdf'];
        $pdfName = $eventoOld['nome_allegato'];
        $nuovoFile = false;
        if (isset($_FILES['allegato_pdf']) && $_FILES['allegato_pdf']['error'] === UPLOAD_ERR_OK) {
            list($ok, $err, $blob, $name) = validaEConvertiAllegato($_FILES["allegato_pdf"], false);
            if (!$ok) throw new Exception($err);
            $pdfBlob = $blob;
            $pdfName = $name;
            $nuovoFile = true;
        }

        $praticaCalc = $c;
        $mapTipo = ['annualita'=>'1','proroga'=>'2','cessione'=>'3','risoluzione'=>'4','conguaglio'=>'5','subentro'=>'6','risoluzione_corrispettivo'=>'7','rinegoziazione'=>'8'];
        $praticaCalc['tipologia_adempimento'] = $mapTipo[$tipo] ?? '1';
        $praticaCalc['tipo_adempimento'] = $praticaCalc['tipologia_adempimento'];
        $praticaCalc['data_invio'] = $dataInvio;
        $praticaCalc['data_fine_proroga'] = $dettagli['data_fine_proroga'] ?? null;
        $praticaCalc['data_cessione'] = $dettagli['data_cessione'] ?? null;
        $praticaCalc['data_subentro'] = $dettagli['data_subentro'] ?? null;
        $praticaCalc['corrispettivo'] = $dettagli['corrispettivo'] ?? 0;
        $praticaCalc['durata_al'] = $dettagli['durata_al'] ?? ($c['durata_al'] ?? null);
        $praticaCalc['rinegoziazione_tipo'] = $dettagli['rinegoziazione_tipo'] ?? 'aumento';
        $praticaCalc['data_inizio_rinegoziazione'] = $dettagli['data_inizio_rinegoziazione'] ?? null;
        $praticaCalc['annualita'] = $dettagli['annualita'] ?? null;

        $stmtL = $pdo->prepare("SELECT s.*, cl.opzione_cedolare, cl.percentuale_possesso FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore WHERE cl.id_contratto = ?");
        $stmtL->execute([$idContratto]);
        $lista_loc = $stmtL->fetchAll();

        $impReg = $tax->calcolaImpostaRegistro($praticaCalc, $lista_loc);
        $impBol = $tax->calcolaImpostaBollo($praticaCalc);
        $ravv = $tax->calcolaRavvedimento($praticaCalc, $impReg, $impBol);

        if ($nuovoFile) {
            $att = salvaAllegatoSuDisco($pdfBlob, $pdfName);
            $stmtU = $pdo->prepare("
                UPDATE eventi_contratto SET
                    data_evento = ?, data_invio = ?, dettagli_json = ?,
                    allegato_pdf = NULL, allegato_path = ?, allegato_sha256 = ?, nome_allegato = ?,
                    imposta_registro = ?, imposta_bollo = ?,
                    sanzione_registro = ?, sanzione_bollo = ?,
                    interessi_registro = ?, interessi_bollo = ?
                WHERE id = ? AND id_contratto = ?
            ");
            $stmtU->execute([
                $dataEvento, $dataInvio, json_encode($dettagli),
                $att['path'], $att['sha256'], $pdfName,
                $impReg, $impBol,
                $ravv['sanzione_registro'], $ravv['sanzione_bollo'],
                $ravv['interessi_registro'], $ravv['interessi_bollo'],
                $idEvento, $idContratto
            ]);
        } else {
            $stmtU = $pdo->prepare("
                UPDATE eventi_contratto SET
                    data_evento = ?, data_invio = ?, dettagli_json = ?,
                    imposta_registro = ?, imposta_bollo = ?,
                    sanzione_registro = ?, sanzione_bollo = ?,
                    interessi_registro = ?, interessi_bollo = ?
                WHERE id = ? AND id_contratto = ?
            ");
            $stmtU->execute([
                $dataEvento, $dataInvio, json_encode($dettagli),
                $impReg, $impBol,
                $ravv['sanzione_registro'], $ravv['sanzione_bollo'],
                $ravv['interessi_registro'], $ravv['interessi_bollo'],
                $idEvento, $idContratto
            ]);
        }

        // Riallinea i campi denormalizzati del contratto all'ULTIMO evento:
        // modificare un evento storico non sovrascrive più lo stato corrente.
        $repo->sincronizzaStatoDaEventi($idContratto);

        $pdo->commit();

        Logger::azione('evento_update', 'evento', $idEvento, ['tipo' => $tipo]);

        header("Location: contract.php?id=$idContratto&msg=Evento+aggiornato+con+successo");
        exit;
    }

    // =====================================================
    // AZIONE: SALVA BOZZA (autosalvataggio / manuale)
    // =====================================================
    if ($action === 'save_bozza') {
        $totale = 0; // FIX: per le bozze non ci sono importi da versare
        $richiede_iban = false;
        $pdo->beginTransaction();

        $idContratto = (int)($_POST['id_contratto'] ?? 0);
        $isUpdate = ($idContratto > 0);

        // === RACCOLTA DATI (senza validazioni bloccanti) ===
        $idU = trim($_POST['id_contratto_univoco'] ?? '');
        $tipologia = $_POST['tipologia_contratto'] ?? 'L1';
        $ufficio = strtoupper(substr($_POST['ufficio_territoriale'] ?? '', 0, 10));
        $dataStip = !empty($_POST['data_stipula']) ? $_POST['data_stipula'] : null;
        $dal = !empty($_POST['durata_dal']) ? $_POST['durata_dal'] : null;
        $al = !empty($_POST['durata_al']) ? $_POST['durata_al'] : null;
        $canone = (float)str_replace(',', '.', $_POST['importo_canone'] ?? 0);
        $pagine = (int)($_POST['numero_pagine'] ?? 1) ?: 1;
        $copie = (int)($_POST['numero_copie'] ?? 1) ?: 1;

        $iban = !empty($_POST['iban']) ? strtoupper(preg_replace('/\s+/', '', $_POST['iban'])) : null;
        $cfInt = !empty($_POST['cf_intestatario_cc']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intestatario_cc'])) : null;
        $cfIntermediario = !empty($_POST['cf_intermediario']) ? strtoupper(preg_replace('/\s+/', '', $_POST['cf_intermediario'])) : null;
        $dataImpegno = !empty($_POST['data_impegno_intermediario']) ? $_POST['data_impegno_intermediario'] : null;
        $dataInvio = !empty($_POST['data_invio']) ? $_POST['data_invio'] : date('Y-m-d');

        $casiParticolari = !empty($_POST['casi_particolari']) ? (int)$_POST['casi_particolari'] : null;
        $contrattoEsente = !empty($_POST['contratto_esente']) ? (int)$_POST['contratto_esente'] : null;
        $agevolazioni = !empty($_POST['agevolazioni']) ? 1 : 0;
        $pagamentoInteraDurata = !empty($_POST['pagamento_intera_durata']) ? 1 : 0;
        $condSospensiva = !empty($_POST['condizione_sospensiva']) ? 1 : 0;
        $tempoIndet = !empty($_POST['contratto_tempo_indeterminato']) ? 1 : 0;
        $clausolaPenale = !empty($_POST['clausola_penale_volontaria']) ? 1 : 0;
        $contrattoIVA = !empty($_POST['contratto_soggetto_iva']) ? 1 : 0;
        $tipoGaranzia = !empty($_POST['tipo_garanzia']) ? (int)$_POST['tipo_garanzia'] : null;
        $importoGaranzia = !empty($_POST['importo_garanzia']) ? (float)str_replace(',', '.', $_POST['importo_garanzia']) : null;
        $cfGarante = !empty($_POST['cf_garante']) ? strtoupper(trim($_POST['cf_garante'])) : null;
        $cfGarante2 = !empty($_POST['cf_garante2']) ? strtoupper(trim($_POST['cf_garante2'])) : null;
        $garanziaIVA = !empty($_POST['garanzia_soggetta_iva']) ? 1 : 0;
        $numAllegatoA = (int)($_POST['numero_allegato_a'] ?? 0);
        $numAllegatoB = (int)($_POST['numero_allegato_b'] ?? 0);
        $numAllegatoC = (int)($_POST['numero_allegato_c'] ?? 0);
        $canoniVariabili = !empty($_POST['canoni_variabili']) ? $_POST['canoni_variabili'] : null;
        $comunicaDatiCatastali = !empty($_POST['comunica_dati_catastali']) ? 1 : 0;
        $contrattoNonAllegato = !empty($_POST['contratto_non_allegato']) ? 1 : 0;
        $eventiEccezionali = !empty($_POST['eventi_eccezionali']) ? $_POST['eventi_eccezionali'] : null;
        $ravvDettagli = $_POST['ravvedimento_dettagli_json'] ?? null;
        $ravvAccettato = !empty($_POST['ravvedimento_accettato']) ? 1 : 0;
        $codiceAdE = !empty($_POST['codice_identificativo_contratto'])
            ? strtoupper(preg_replace('/\s+/', '', $_POST['codice_identificativo_contratto']))
            : null;

        $locatoriData = [];
        foreach ($_POST['locatori'] ?? [] as $lid) {
            $locatoriData[] = [
                'id' => (int)$lid,
                'quota' => (float)($_POST['quota_' . $lid] ?? 100),
                'richiedente' => ($_POST['richiedente_id'] ?? null) == $lid ? 1 : 0,
                'cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
                'opzione_cedolare' => !empty($_POST['cedolare_' . $lid]) ? 1 : 0,
            ];
        }
        $conduttoriIds = array_map('intval', $_POST['conduttori'] ?? []);
        $immobiliData = [];
        foreach ($_POST['immobili'] ?? [] as $iid) {
            $immobiliData[] = [
                'id' => (int)$iid,
                'ip' => !empty($_POST['ip_i_' . $iid]) ? 'I' : 'P',
                'tipologia' => (int)($_POST['tipologia_imm_' . $iid] ?? 1),
            ];
        }

        $cedCount = count(array_filter($locatoriData, function($l) { return $l['cedolare']; }));
        $ced = '3';
        if (count($locatoriData) > 0 && $cedCount === count($locatoriData)) $ced = '1';
        elseif ($cedCount > 0) $ced = '2';

        if ($isUpdate) {
            $stmt = $pdo->prepare("SELECT stato FROM contratti WHERE id = ?");
            $stmt->execute([$idContratto]);
            $statoAttuale = $stmt->fetchColumn();
            if ($statoAttuale === false) throw new Exception("Contratto #$idContratto non trovato");

            $pdo->prepare("
                UPDATE contratti SET
                    id_contratto_univoco = ?, codice_identificativo_contratto = ?,
                    tipologia_contratto = ?, ufficio_territoriale = ?,
                    data_stipula = ?, durata_dal = ?, durata_al = ?,
                    importo_canone = ?, numero_pagine = ?, numero_copie = ?,
                    cedolare_secca = ?,
                    iban = ?, cf_intestatario_cc = ?,
                    cf_intermediario = ?, data_impegno_intermediario = ?,
                    ravvedimento_accettato = ?, ravvedimento_dettagli_json = ?,
                    comunica_dati_catastali = ?, contratto_non_allegato = ?, eventi_eccezionali = ?,
                    casi_particolari = ?, contratto_esente = ?, agevolazioni = ?, pagamento_intera_durata = ?,
                    condizione_sospensiva = ?, contratto_tempo_indeterminato = ?, clausola_penale_volontaria = ?,
                    contratto_soggetto_iva = ?,
                    tipo_garanzia = ?, importo_garanzia = ?, cf_garante = ?, cf_garante2 = ?, garanzia_soggetta_iva = ?,
                    numero_allegato_a = ?, numero_allegato_b = ?, numero_allegato_c = ?,
                    canoni_variabili = ?,
                    data_invio = ?, updated_at = NOW()
                WHERE id = ? AND stato = 'BOZZA'
            ")->execute([
                $idU ?: null, $codiceAdE,
                $tipologia, $ufficio,
                $dataStip, $dal, $al,
                $canone, $pagine, $copie,
                $ced,
                $iban, $cfInt,
                $cfIntermediario, $dataImpegno,
                $ravvAccettato, $ravvDettagli,
                $comunicaDatiCatastali, $contrattoNonAllegato, $eventiEccezionali,
                $casiParticolari, $contrattoEsente, $agevolazioni, $pagamentoInteraDurata,
                $condSospensiva, $tempoIndet, $clausolaPenale,
                $contrattoIVA,
                $tipoGaranzia, $importoGaranzia, $cfGarante, $cfGarante2, $garanziaIVA,
                $numAllegatoA, $numAllegatoB, $numAllegatoC,
                $canoniVariabili,
                $dataInvio,
                $idContratto
            ]);

            $pdo->prepare("DELETE FROM contratto_locatori WHERE id_contratto = ?")->execute([$idContratto]);
            $pdo->prepare("DELETE FROM contratto_conduttori WHERE id_contratto = ?")->execute([$idContratto]);
            $pdo->prepare("DELETE FROM contratto_immobili WHERE id_contratto = ?")->execute([$idContratto]);
        } else {
            $pdo->prepare("
                INSERT INTO contratti (
                    id_utente_creatore, id_contratto_univoco, codice_identificativo_contratto, stato, tipologia_contratto, ufficio_territoriale,
                    data_stipula, durata_dal, durata_al, importo_canone, numero_pagine, numero_copie,
                    tipologia_adempimento, cedolare_secca,
                    iban, cf_intestatario_cc,
                    cf_intermediario, data_impegno_intermediario,
                    ravvedimento_accettato, ravvedimento_dettagli_json,
                    comunica_dati_catastali, contratto_non_allegato, eventi_eccezionali,
                    casi_particolari, contratto_esente, agevolazioni, pagamento_intera_durata,
                    condizione_sospensiva, contratto_tempo_indeterminato, clausola_penale_volontaria,
                    contratto_soggetto_iva,
                    tipo_garanzia, importo_garanzia, cf_garante, cf_garante2, garanzia_soggetta_iva,
                    numero_allegato_a, numero_allegato_b, numero_allegato_c,
                    canoni_variabili,
                    data_impegno, data_invio, updated_at
                ) VALUES (
                    ?, ?, ?, 'BOZZA', ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    '1', ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?,
                    CURDATE(), ?, NOW()
                )
            ")->execute([
                $_SESSION['utente_id'] ?? null,
                $idU ?: null, $codiceAdE, $tipologia, $ufficio,
                $dataStip, $dal, $al, $canone, $pagine, $copie,
                $ced,
                $iban, $cfInt,
                $cfIntermediario, $dataImpegno,
                $ravvAccettato, $ravvDettagli,
                $comunicaDatiCatastali, $contrattoNonAllegato, $eventiEccezionali,
                $casiParticolari, $contrattoEsente, $agevolazioni, $pagamentoInteraDurata,
                $condSospensiva, $tempoIndet, $clausolaPenale,
                $contrattoIVA,
                $tipoGaranzia, $importoGaranzia, $cfGarante, $cfGarante2, $garanziaIVA,
                $numAllegatoA, $numAllegatoB, $numAllegatoC,
                $canoniVariabili,
                $dataInvio
            ]);
            $idContratto = (int)$pdo->lastInsertId();
        }

        $stmtL = $pdo->prepare("INSERT INTO contratto_locatori (id_contratto, id_locatore, richiedente, opzione_cedolare, percentuale_possesso) VALUES (?, ?, ?, ?, ?)");
        foreach ($locatoriData as $l) $stmtL->execute([$idContratto, $l['id'], $l['richiedente'], $l['cedolare'], $l['quota']]);

        $stmtC = $pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)");
        foreach ($conduttoriIds as $cid) $stmtC->execute([$idContratto, $cid]);

        $stmtI = $pdo->prepare("INSERT INTO contratto_immobili (id_contratto, id_immobile, tipologia_immobile, intero_porzione) VALUES (?, ?, ?, ?)");
        foreach ($immobiliData as $i) $stmtI->execute([$idContratto, $i['id'], $i['tipologia'], $i['ip']]);

        $pdo->commit();

        Logger::azione($isUpdate ? 'bozza_update' : 'bozza_create', 'contratto', $idContratto);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'id_contratto' => $idContratto,
            'azione' => $isUpdate ? 'update' : 'create',
        ]);
        exit;
    }

    Logger::errore('save_pratica', "Azione non riconosciuta: '" . $action . "'. POST keys: " . implode(', ', array_keys($_POST)));
    echo json_encode([
        'ok' => false,
        'error' => 'Azione non riconosciuta (action="' . $action . '"). Contatta l\'assistenza se il problema persiste.'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Logger::errore('save_pratica', $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
