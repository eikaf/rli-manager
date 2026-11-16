<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
require_once 'lib/ComunicazioneRepository.php';
require_once 'lib/Mailer.php';

$auth->requireLogin();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
$comRepo = new ComunicazioneRepository($pdo);

/**
 * Rendering di sicurezza dei segnaposto email, indipendente da ComunicazioneRepository.
 * Garantisce che {LOCATORE_NOME} e gli alias vengano sempre sostituiti (case-insensitive),
 * anche se il repository è datato o la sua query principale fallisce.
 */
function rliRenderEmailPlaceholders(PDO $pdo, string $testo, int $idContratto): string
{
    if ($idContratto <= 0 || strpos($testo, '{') === false) {
        return $testo;
    }

    $stmt = $pdo->prepare("SELECT id_contratto_univoco, codice_identificativo_contratto, data_stipula, durata_al, importo_canone FROM contratti WHERE id = ?");
    $stmt->execute([$idContratto]);
    $c = $stmt->fetch();
    if (!$c) {
        return $testo;
    }

    $stmtL = $pdo->prepare("SELECT s.cognome, s.nome FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore WHERE cl.id_contratto = ? ORDER BY cl.richiedente DESC");
    $stmtL->execute([$idContratto]);
    $locatori = [];
    foreach ($stmtL->fetchAll() as $r) {
        $nome = trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
        if ($nome !== '') $locatori[] = $nome;
    }
    $locatoriList = implode(' / ', $locatori);

    $stmtC = $pdo->prepare("SELECT s.cognome, s.nome FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore WHERE cc.id_contratto = ?");
    $stmtC->execute([$idContratto]);
    $conduttori = [];
    foreach ($stmtC->fetchAll() as $r) {
        $nome = trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
        if ($nome !== '') $conduttori[] = $nome;
    }
    $conduttoriList = implode(', ', $conduttori);

    $vars = [
        '{id_contratto}' => $c['id_contratto_univoco'] ?: ('#' . $idContratto),
        '{codice_ade}' => $c['codice_identificativo_contratto'] ?: 'Non ancora assegnato',
        '{locatore_nome}' => $locatoriList,
        '{nome_locatore}' => $locatoriList,
        '{locatori}' => $locatoriList,
        '{locatori_list}' => $locatoriList,
        '{conduttore_nome}' => $conduttori[0] ?? '',
        '{conduttori_list}' => $conduttoriList ?: 'Nessun conduttore',
        '{conduttori_nomi}' => $conduttoriList,
        '{data_stipula}' => $c['data_stipula'] ? date('d/m/Y', strtotime($c['data_stipula'])) : '',
        '{data_scadenza}' => $c['durata_al'] ? date('d/m/Y', strtotime($c['durata_al'])) : '',
        '{canone}' => $c['importo_canone'] ? '€ ' . number_format((float)$c['importo_canone'], 2, ',', '') : '',
        '{data_oggi}' => date('d/m/Y'),
    ];

    $testo = str_ireplace(array_keys($vars), array_values($vars), $testo);
    // Rimuove eventuali segnaposto residui non riconosciuti.
    $testo = preg_replace('/\{[A-Za-z0-9_]+\}/', '', $testo);
    return $testo;
}

// POST: elimina comunicazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    Security::requireCsrf();
    try {
        $idCom = (int)$_POST['delete_id'];
        $cm = $comRepo->find($idCom);
        if (!$cm) throw new Exception('Comunicazione non trovata.');
        $permesso = $cm['id_contratto'] ? $auth->permessoContratto((int)$cm['id_contratto']) : null;
        $isAutore = ((int)($cm['id_utente'] ?? 0) === $auth->id());
        if (!in_array($permesso, ['admin', 'owner', 'edit'], true) && !$isAutore) {
            throw new Exception('Non hai i permessi per eliminare questa comunicazione.');
        }
        $comRepo->delete($idCom);
        Logger::azione('comunicazione_delete', 'comunicazione', $idCom);
        header('Location: comunicazioni.php?msg=Comunicazione+eliminata');
        exit;
    } catch (Exception $e) {
        header('Location: comunicazioni.php?err=' . urlencode($e->getMessage()));
        exit;
    }
}

// POST: reinvia comunicazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_id'])) {
    Security::requireCsrf();
    try {
        $idCom = (int)$_POST['resend_id'];
        $cm = $comRepo->find($idCom);
        if (!$cm) throw new Exception('Comunicazione non trovata.');
        $destinatari = json_decode($cm['destinatari_json'], true) ?: [];
        
        // Recupera gli allegati salvati
        $allegatiMail = [];
        $allegatiSalvati = json_decode($cm['allegati_json'] ?? '[]', true);
        if (is_array($allegatiSalvati)) {
            foreach ($allegatiSalvati as $a) {
                if (!empty($a['path']) && file_exists($a['path'])) {
                    $allegatiMail[] = [
                        'path' => $a['path'],
                        'name' => $a['name'] ?? basename($a['path'])
                    ];
                }
            }
        }

        // Risolve eventuali segnaposto {…} rimasti (es. comunicazioni accodate prima del fix).
        $oggettoInvio = (string)($cm['oggetto'] ?? '');
        $corpoInvio   = (string)($cm['corpo'] ?? '');
        if (!empty($cm['id_contratto'])) {
            try {
                $oggettoInvio = $comRepo->renderTemplate($oggettoInvio, (int)$cm['id_contratto']);
                $corpoInvio   = $comRepo->renderTemplate($corpoInvio, (int)$cm['id_contratto']);
            } catch (Throwable $e) {
                error_log('ComunicazioneRepository::renderTemplate fallito (resend): ' . $e->getMessage());
            }
            // Fallback indipendente dal repository (case-insensitive).
            $oggettoInvio = rliRenderEmailPlaceholders($pdo, $oggettoInvio, (int)$cm['id_contratto']);
            $corpoInvio   = rliRenderEmailPlaceholders($pdo, $corpoInvio, (int)$cm['id_contratto']);
        }

        $inviata = Mailer::invia($oggettoInvio, $corpoInvio, $destinatari, $allegatiMail, $auth->id());
        if ($inviata) {
            $comRepo->markAsSent($idCom);
            $msg = 'Comunicazione reinviata con successo';
        } else {
            $comRepo->setError($idCom, 'Reinvio SMTP fallito');
            $msg = 'Reinvio fallito';
        }
        header('Location: comunicazioni.php?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        header('Location: comunicazioni.php?err=' . urlencode($e->getMessage()));
        exit;
    }
}

// POST: invia comunicazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_comunicazione'])) {
    Security::requireCsrf();
    try {
        $idContratto = (int)$_POST['id_contratto'];
        $tipo = $_POST['tipo'] ?? 'email';
        $oggetto = trim($_POST['oggetto'] ?? '');
        $corpo = trim($_POST['corpo'] ?? '');
        $destinatari = trim($_POST['destinatari'] ?? '');
        $idTemplate = (int)($_POST['id_template'] ?? 0);

        if ($idContratto <= 0) throw new Exception('Seleziona un contratto.');
        if ($oggetto === '' || $corpo === '') throw new Exception('Oggetto e corpo obbligatori.');
        if ($auth->permessoContratto($idContratto) === null) throw new Exception('Non hai accesso a questo contratto.');

        try {
            $oggetto = $comRepo->renderTemplate($oggetto, $idContratto);
            $corpo   = $comRepo->renderTemplate($corpo, $idContratto);
        } catch (Throwable $e) {
            error_log('ComunicazioneRepository::renderTemplate fallito: ' . $e->getMessage());
        }
        // Fallback indipendente dal repository: garantisce la sostituzione di
        // {LOCATORE_NOME} e alias anche se il repository è datato o fallisce.
        $oggetto = rliRenderEmailPlaceholders($pdo, $oggetto, $idContratto);
        $corpo   = rliRenderEmailPlaceholders($pdo, $corpo, $idContratto);

        $allegatiMail = [];

        // AUTO-ALLEGA PDF SE È IL TEMPLATE RICHIESTA FIRMA
        if ($idTemplate > 0) {
            $stmtTpl = $pdo->prepare("SELECT codice FROM template_email WHERE id = ?");
            $stmtTpl->execute([$idTemplate]);
            $codiceTemplate = $stmtTpl->fetchColumn();

            if ($codiceTemplate === 'richiesta_firma_rli') {
                $tempFile = sys_get_temp_dir() . '/rli_auto_' . uniqid() . '.pdf';
                $cmd = 'php ' . escapeshellarg(__DIR__ . '/genera_pdf_cli.php') . ' ' . (int)$idContratto . ' ' . escapeshellarg($tempFile) . ' 2>&1';
                exec($cmd, $output, $returnVar);

                if ($returnVar === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                    $allegatiMail[] = [
                        'path' => $tempFile,
                        'name' => 'Modello_RLI_' . $idContratto . '.pdf'
                    ];
                } else {
                }
            }
        }

        // Gestione allegato manuale (se presente)
        if (isset($_FILES['allegato']) && $_FILES['allegato']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/comunicazioni/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
            $ext = strtolower(pathinfo($_FILES['allegato']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                $safeName = uniqid('com_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['allegato']['name']);
                $destPath = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['allegato']['tmp_name'], $destPath)) {
                    $allegatiMail[] = ['path' => $destPath, 'name' => $_FILES['allegato']['name']];
                }
            }
        }

        $idCom = $comRepo->create([
            'id_contratto' => $idContratto,
            'id_utente' => $auth->id(),
            'tipo' => $tipo,
            'oggetto' => $oggetto,
            'corpo' => $corpo,
            'destinatari' => $destinatari ? array_map('trim', explode(',', $destinatari)) : [],
            'allegati' => $allegatiMail,
            'inviata' => 0,
        ]);

        if ($tipo === 'email' && !empty($destinatari)) {
            $listaDest = array_map('trim', explode(',', $destinatari));
            $inviata = Mailer::invia($oggetto, $corpo, $listaDest, $allegatiMail, $auth->id());
            if ($inviata) $comRepo->markAsSent($idCom);
            else $comRepo->setError($idCom, 'Invio SMTP fallito');
        }

        header('Location: comunicazioni.php?msg=' . urlencode('Comunicazione registrata'));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$idContrattoFilter = isset($_GET['contratto']) ? (int)$_GET['contratto'] : null;
$tipoFilter = $_GET['tipo'] ?? null;
$templatePreselezionato = $_GET['template'] ?? '';

$comunicazioni = $comRepo->all($idContrattoFilter, $tipoFilter, 200);
$template = $comRepo->listaTemplate();
// Lista contratti (per il select) - Filtro visibilità
$visWhere = "";
$visParams = [];
if (!$auth->isAdmin()) {
    $visWhere = "WHERE (id_utente_creatore = :id_utente OR EXISTS (
        SELECT 1 FROM contratti_condivisioni cc
        WHERE cc.id_contratto = contratti.id AND cc.id_utente = :id_utente_cond
    ))";
    $visParams[':id_utente'] = $auth->id();
    $visParams[':id_utente_cond'] = $auth->id();
}

$stmt = $pdo->prepare("SELECT id, id_contratto_univoco FROM contratti $visWhere ORDER BY updated_at DESC LIMIT 100");
$stmt->execute($visParams);
$contratti = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Comunicazioni — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1100px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h3 { color: #4a5568; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; font-family: inherit; }
        textarea { resize: vertical; min-height: 120px; }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .comunicazione-item { padding: 15px; border-bottom: 1px solid #edf2f7; }
        .comunicazione-item:last-child { border-bottom: none; }
        .com-item-header { display: flex; justify-content: space-between; margin-bottom: 8px; align-items: flex-start; }
        .com-tipo { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; text-transform: uppercase; }
        .com-tipo.email { background: #bee3f8; color: #2c5282; }
        .com-tipo.pec { background: #feebc8; color: #7b341e; }
        .com-tipo.raccomandata { background: #c6f6d5; color: #22543d; }
        .com-data { color: #718096; font-size: 0.85em; }
        .com-oggetto { font-weight: 600; color: #2d3748; margin-bottom: 4px; }
        .com-corpo { color: #4a5568; font-size: 0.9em; white-space: pre-wrap; }
        .com-meta { color: #a0aec0; font-size: 0.8em; margin-top: 8px; }
        .badge-contratto { display: inline-block; padding: 2px 8px; border-radius: 8px; background: #c6f6d5; color: #22543d; font-size: 0.75em; font-weight: bold; text-decoration: none; }
    </style>
</head>
<body>
<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>📧 Comunicazioni</h2>
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($_GET['err'])) echo "<div class='alert alert-error'>⚠ " . Security::h($_GET['err']) . "</div>"; ?>
    <?php if (isset($error)) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <div class="card">
        <h3>➕ Nuova comunicazione</h3>
        <form method="POST" enctype="multipart/form-data">
            <?= Security::csrfField() ?>
            <input type="hidden" name="id_template" id="id_template_hidden" value="0">

            <div class="row">
                <div style="flex: 2;">
                    <label>Contratto *</label>
                    <select name="id_contratto" id="id_contratto_select" required>
                        <option value="">— Seleziona contratto —</option>
                        <?php foreach ($contratti as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $idContrattoFilter == $c['id'] ? 'selected' : '' ?>><?= Security::h($c['id_contratto_univoco'] ?: '#' . $c['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tipo *</label>
                    <select name="tipo" required>
                        <option value="email">📧 Email</option>
                        <option value="pec">📨 PEC</option>
                        <option value="nota_interna">📝 Nota interna</option>
                    </select>
                </div>
            </div>

            <label>Destinatari (email separate da virgola)</label>
            <input type="text" name="destinatari" class="no-uppercase" placeholder="es. mario@example.com">

            <label>Template predefinito (opzionale)</label>
            <select id="template_selector" onchange="caricaTemplate(this.value)">
                <option value="">— Nessun template —</option>
                <?php foreach ($template as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" data-oggetto="<?= Security::h($t['oggetto']) ?>" data-corpo="<?= Security::h($t['corpo']) ?>" <?= $templatePreselezionato === $t['codice'] ? 'selected' : '' ?>><?= Security::h($t['nome']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Oggetto *</label>
            <input type="text" name="oggetto" id="oggetto" class="no-uppercase" required>

            <label>Corpo *</label>
            <textarea name="corpo" id="corpo" class="no-uppercase" required></textarea>

            <label>Allegato manuale (opzionale)</label>
            <input type="file" name="allegato" id="allegato">

            <button type="submit" name="invia_comunicazione" value="1" class="btn btn-green">📧 Registra comunicazione</button>
        </form>
        
        <div style="margin-top: 15px; border-top: 1px solid #edf2f7; padding-top: 15px;">
            <p style="font-size: 0.9em; color: #4a5568; margin-bottom: 8px;">Scarica il PDF per allegarlo manualmente:</p>
            <form method="POST" action="genera_modello_pdf.php" target="_blank" id="form_pdf">
                <?= Security::csrfField() ?>
                <input type="hidden" name="id_contratto" id="pdf_id_contratto" value="">
                <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 0.9em;" onclick="return checkPdfSelection();">📄 Scarica PDF Modello RLI</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>📋 Registro comunicazioni (<?= count($comunicazioni) ?>)</h3>
        <?php if (empty($comunicazioni)): ?>
            <p style="color:#a0aec0;">Nessuna comunicazione registrata.</p>
        <?php else: ?>
            <?php foreach ($comunicazioni as $cm): ?>
                <div class="comunicazione-item">
                    <div class="com-item-header">
                        <div>
                            <span class="com-tipo <?= Security::h($cm['tipo']) ?>"><?= Security::h($cm['tipo']) ?></span>
                            <?php if ($cm['id_contratto_univoco']): ?>
                                <a href="<?= url('contract.php') ?>?id=<?= (int)$cm['id_contratto'] ?>" class="badge-contratto" style="margin-left: 8px;"><?= Security::h($cm['id_contratto_univoco']) ?></a>
                            <?php endif; ?>
                            <span class="com-data" style="margin-left: 10px;"><?= date('d/m/Y H:i', strtotime($cm['data_comunicazione'])) ?></span>
                        </div>
                        <div style="display:flex; gap:5px;">
                            <?php if ($cm['tipo'] === 'email' && !empty($cm['destinatari_json'])): ?>
                                <form method="POST" onsubmit="return confirm('Reinviare questa email?')" style="margin:0;">
                                    <?= Security::csrfField() ?>
                                    <button type="submit" name="resend_id" value="<?= (int)$cm['id'] ?>" class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75em;" title="Reinvia Email">🔄 Reinvia</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Eliminare questa comunicazione?')">
                                <?= Security::csrfField() ?>
                                <button type="submit" name="delete_id" value="<?= (int)$cm['id'] ?>" class="btn btn-red" style="padding: 4px 10px; font-size: 0.75em;">🗑</button>
                            </form>
                        </div>
                    </div>
                    <?php
                    // Re-render in visualizzazione: corregge anche i record storici
                    // salvati con segnaposto non risolti.
                    $oggettoDisplay = rliRenderEmailPlaceholders($pdo, (string)($cm['oggetto'] ?? ''), (int)($cm['id_contratto'] ?? 0));
                    $corpoDisplay   = rliRenderEmailPlaceholders($pdo, (string)($cm['corpo'] ?? ''), (int)($cm['id_contratto'] ?? 0));
                    ?>
                    <div class="com-oggetto"><?= Security::h($oggettoDisplay ?: '(senza oggetto)') ?></div>
                    <div class="com-corpo"><?= nl2br(Security::h($corpoDisplay)) ?></div>
                    <div class="com-meta">
                        Da <?= Security::h(trim(($cm['utente_cognome'] ?? '') . ' ' . ($cm['utente_nome'] ?? ''))) ?>
                        <?php if ($cm['inviata']): ?> · ✅ Inviata il <?= date('d/m/Y H:i', strtotime($cm['data_invio'])) ?>
                        <?php else: ?> · ⏳ Registrata
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function caricaTemplate() {
    const sel = document.getElementById('template_selector');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('id_template_hidden').value = opt.value || '0';
    if (!opt.value) return;
    document.getElementById('oggetto').value = opt.dataset.oggetto || '';
    document.getElementById('corpo').value = opt.dataset.corpo || '';
}

document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('template_selector');
    if (sel && sel.value) caricaTemplate();
});

function checkPdfSelection() {
    const id = document.getElementById('id_contratto_select').value;
    if (!id) { 
        alert('Seleziona prima un contratto.'); 
        return false; 
    }
    document.getElementById('pdf_id_contratto').value = id;
    return true;
}

document.getElementById('id_contratto_select').addEventListener('change', function() {
    const id = this.value;
    const destField = document.querySelector('input[name="destinatari"]');
    const pdfInput = document.getElementById('pdf_id_contratto');
    
    if (pdfInput) pdfInput.value = id;
    
    if (!id) { 
        destField.value = ''; 
        return; 
    }
    
    fetch('api/contratto_emails.php?id_contratto=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.emails.length > 0) {
                destField.value = data.emails.join(', ');
            }
        })
        .catch(err => {}); 
});
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
