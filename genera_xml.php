<?php
/**
 * genera_xml.php
 * Genera il file .RLI (XML RLI12) per l'Agenzia delle Entrate.
 *
 * Flusso:
 *   1. Auth + CSRF + autorizzazione contratto (canAccess)
 *   2. Carica contratto + evento
 *   3. Costruisce il payload risolto (ContrattoRepository::prepareRliPayload)
 *   4. VALIDAZIONE PRE-VOLO (RliPreFlightValidator)
 *   5. Genera XML + validazione XSD + log su file
 *   6. Invia al browser come download
 */
require_once __DIR__ . '/bootstrap.php';

$auth->requireLogin();
Security::requireCsrf();

$idContratto = (int)($_POST['id_contratto'] ?? 0);
$idEvento    = (int)($_POST['id_evento'] ?? 0);

if ($idContratto <= 0) {
    // Retrocompatibilità: se arriva id_pratica, cerca il contratto
    if (isset($_POST['id_pratica'])) {
        $stmt = $pdo->prepare("SELECT id_contratto FROM eventi_contratto WHERE id = ?");
        $stmt->execute([(int)$_POST['id_pratica']]);
        $idContratto = (int)$stmt->fetchColumn();
    }
}

if ($idContratto <= 0) die("ID contratto mancante.");

$repo = new ContrattoRepository($pdo);
$contratto = $repo->find($idContratto);
if (!$contratto) die("Contratto non trovato.");

// =============================================================
// AUTORIZZAZIONE
// =============================================================
if (!$repo->canAccess($idContratto, $auth)) {
    Logger::errore('genera_xml_accesso_negato', 'Accesso negato al contratto', $idContratto);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>403 — Accesso negato</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f7fafc;padding:40px;color:#2d3748;}';
    echo '.box{background:#fff;border-left:4px solid #e53e3e;padding:25px 30px;border-radius:10px;max-width:700px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.08);}';
    echo 'h2{color:#742a2a;margin-top:0;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#4299e1;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;}</style>';
    echo '</head><body><div class="box">';
    echo '<h2>403 — Accesso non autorizzato</h2>';
    echo '<p>Non hai i permessi per generare l\'XML di questo contratto.</p>';
    echo '<a class="btn" href="index.php">← Home</a>';
    echo '</div></body></html>';
    exit;
}

// --- Evento (opzionale) ---
$evento = null;
if ($idEvento > 0) {
    $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
    $stmt->execute([$idEvento, $idContratto]);
    $evento = $stmt->fetch();
} else {
    // Prendi l'evento più recente
    $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id_contratto = ? ORDER BY data_evento DESC, id DESC LIMIT 1");
    $stmt->execute([$idContratto]);
    $evento = $stmt->fetch();
}

// --- Payload risolto (cf_intermediario, locatori/conduttori per cessione/subentro) ---
$payload = $repo->prepareRliPayload($idContratto, $evento ?: null, $auth->id());

// =============================================================
// VALIDAZIONE PRE-VOLO
// =============================================================
$errori = RliPreFlightValidator::validate($payload);

if (!empty($errori)) {
    Logger::errore('genera_xml_validazione', implode(' | ', $errori), $idContratto);
    // PRG: salva gli errori in flash e reindirizza alla scheda contratto.
    $_SESSION['flash_rli_errors'] = $errori;
    header('Location: contract.php?id=' . $idContratto, true, 303);
    exit;
}

// =============================================================
// GENERAZIONE XML
// =============================================================
try {
    $builder = new XmlBuilder($payload);
    $xml = $builder->build();
} catch (Throwable $e) {
    Logger::errore('genera_xml_build', $e->getMessage(), $idContratto);
    $_SESSION['flash_rli_errors'] = ['Errore nella generazione XML: ' . $e->getMessage()];
    header('Location: contract.php?id=' . $idContratto, true, 303);
    exit;
}

// === VALIDAZIONE XSD LOCALE ===
require_once __DIR__ . '/lib/XsdValidator.php';
$xsdResult = XsdValidator::validate($xml);
if (!$xsdResult['ok']) {
    Logger::errore('genera_xml_xsd', implode(' | ', array_slice($xsdResult['errors'], 0, 5)), $idContratto);
    $xsdErrors = array_merge(
        ['Il file XML generato non rispetta lo schema XSD ufficiale:'],
        $xsdResult['errors']
    );
    if (!empty($xsdResult['warnings'])) {
        $xsdErrors = array_merge($xsdErrors, ['Warning:'], $xsdResult['warnings']);
    }
    $_SESSION['flash_rli_errors'] = $xsdErrors;
    header('Location: contract.php?id=' . $idContratto, true, 303);
    exit;
}
if (!empty($xsdResult['warnings'])) {
    Logger::azione('genera_xml_xsd_warnings', 'contratto', $idContratto, ['warnings' => $xsdResult['warnings']]);
}

// === STORICIZZAZIONE XML ===
// Salva l'XML validato nell'evento selezionato (se presente).
if ($evento && !empty($evento['id'])) {
    try {
        $stmtSaveXml = $pdo->prepare("UPDATE eventi_contratto SET xml_generato = ? WHERE id = ? AND id_contratto = ?");
        $stmtSaveXml->execute([$xml, (int)$evento['id'], $idContratto]);
    } catch (Throwable $e) {
        Logger::errore('genera_xml_storicizza', $e->getMessage(), $idContratto);
    }
}

// Log su file — storage esterno alla document root
$logDir = rli_storage_path('logs');
$htaccess = $logDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\n");
}
$logFile = $logDir . '/xml_contratto' . $idContratto . '_' . date('Ymd_His') . '.xml';
@file_put_contents($logFile, $xml);

$pratica    = $payload['pratica'];
$locatori   = $payload['locatori'];
$conduttori = $payload['conduttori'];
$immobili   = $payload['immobili'];

Logger::azione('xml_generato', 'contratto', $idContratto, [
    'file' => basename($logFile),
    'size' => strlen($xml),
    'num_locatori' => count($locatori),
    'num_conduttori' => count($conduttori),
    'num_immobili' => count($immobili),
]);

// Nome file con prefisso
$idUnivoco = trim($pratica['id_contratto_univoco'] ?? '');
$tipoAdemp = (string)($pratica['tipo_adempimento'] ?? $pratica['tipologia_adempimento'] ?? '1');
$prefissi = [
    '1' => '',       // Prima registrazione: nessun prefisso
    '2' => 'PRO_',   // Proroga
    '3' => 'CES_',   // Cessione
    '4' => 'RIS_',   // Risoluzione
    '5' => 'CON_',   // Conguaglio
    '6' => 'SUB_',   // Subentro
    '7' => 'RIS_',   // Risoluzione con corrispettivo
    '8' => 'RIN_',   // Rinegoziazione
];
$prefisso = $prefissi[$tipoAdemp] ?? 'ALL_';

if ($idUnivoco !== '') {
    $idUnivocoSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $idUnivoco);
    $filename = $prefisso . $idUnivocoSafe . ".rli";
} else {
    $locCog  = !empty($locatori) ? substr(strtoupper(str_replace(' ', '', $locatori[0]['cognome'])), 0, 3) : 'XXX';
    $condCog = !empty($conduttori) ? substr(strtoupper(str_replace(' ', '', $conduttori[0]['cognome'])), 0, 8) : 'XXX';
    $filename = $prefisso . "{$locCog}_{$condCog}_{$payload['id_contratto']}.rli";
}

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xml));
echo $xml;
exit;
