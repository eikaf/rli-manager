<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/NotificaRepository.php';
require_once 'lib/AttachmentStorage.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit; }

$repo = new ContrattoRepository($pdo);
$contratto = $repo->find($id);
if (!$contratto) { header("Location: index.php?error=Contratto+non+trovato"); exit; }

// === VERIFICA PERMESSI ===
$permessoContratto = $auth->permessoContratto($id);
if ($permessoContratto === null) {
    http_response_code(403);
    die('Non hai i permessi per visualizzare questo contratto.');
}
$puoModificare = in_array($permessoContratto, ['admin', 'owner', 'edit'], true);
$isProprietario = in_array($permessoContratto, ['admin', 'owner'], true);

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$locatori = $repo->locatori($id);
$conduttori = $repo->conduttori($id);
$immobili = $repo->immobili($id);
$eventi = $repo->eventi($id);
$richiedente = $repo->richiedente($id);

// === HANDLER VISUALIZZAZIONE ALLEGATO PDF ===
if (isset($_GET['view_pdf_evento'])) {
    $idEvento = (int)$_GET['view_pdf_evento'];
    if ($idEvento > 0) {
        if (!$repo->canAccess($id, $auth)) {
            http_response_code(403);
            die('Non hai i permessi per scaricare questo allegato.');
        }
        $stmt = $pdo->prepare("SELECT allegato_pdf, allegato_path, nome_allegato FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
        $stmt->execute([$idEvento, $id]);
        $ev = $stmt->fetch();

        $data = $ev ? AttachmentStorage::read($ev['allegato_path'] ?? null, $ev['allegato_pdf'] ?? null) : null;
        if ($data !== null) {
            $nomeFile = $ev['nome_allegato'] ?: ('evento_' . $idEvento . '.pdf');
            $nomeFile = preg_replace('/[^\w.\-]/', '_', $nomeFile);

            if (ob_get_level()) ob_end_clean();

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $nomeFile . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            echo $data;
            exit;
        } else {
            http_response_code(404);
            die('Allegato non trovato per questo evento.');
        }
    }
}

// === HANDLER DOWNLOAD ALLEGATO PDF ===
if (isset($_GET['download_pdf_evento'])) {
    $idEvento = (int)$_GET['download_pdf_evento'];
    if ($idEvento > 0) {
        if (!$repo->canAccess($id, $auth)) {
            http_response_code(403);
            die('Non hai i permessi per scaricare questo allegato.');
        }
        $stmt = $pdo->prepare("SELECT allegato_pdf, allegato_path, nome_allegato FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
        $stmt->execute([$idEvento, $id]);
        $ev = $stmt->fetch();

        $data = $ev ? AttachmentStorage::read($ev['allegato_path'] ?? null, $ev['allegato_pdf'] ?? null) : null;
        if ($data !== null) {
            $nomeFile = $ev['nome_allegato'] ?: ('evento_' . $idEvento . '.pdf');
            $nomeFile = preg_replace('/[^\w.\-]/', '_', $nomeFile);

            if (ob_get_level()) ob_end_clean();

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            echo $data;
            exit;
        } else {
            http_response_code(404);
            die('Allegato non trovato per questo evento.');
        }
    }
}

// === HANDLER DELETE EVENTO ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_evento'])) {
    Security::requireCsrf();
    $idEvento = (int)$_POST['delete_evento'];

    if ($idEvento > 0) {
        $stmtEv = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
        $stmtEv->execute([$idEvento, $id]);
        $eventoDaEliminare = $stmtEv->fetch();

        if ($eventoDaEliminare) {
            if ($eventoDaEliminare['tipo'] === 'registrazione') {
                header("Location: contract.php?id=$id&error=Non+puoi+eliminare+l'evento+di+registrazione.+Usa+'Modifica+contratto'.");
                exit;
            }

            $tipoEvento = $eventoDaEliminare['tipo'];

            try {
                $pdo->beginTransaction();

                $pdo->prepare("DELETE FROM eventi_contratto WHERE id = ? AND id_contratto = ?")
                    ->execute([$idEvento, $id]);

                $stmtTutti = $pdo->prepare("SELECT tipo, data_evento, dettagli_json FROM eventi_contratto WHERE id_contratto = ? ORDER BY data_evento DESC, id DESC");
                $stmtTutti->execute([$id]);
                $eventiRimasti = $stmtTutti->fetchAll();

                $nuovoStato = 'ATTIVO';
                $tipologiaAdempimento = '1';

                foreach ($eventiRimasti as $ev) {
                    if ($ev['tipo'] === 'risoluzione' || $ev['tipo'] === 'risoluzione_corrispettivo') {
                        $nuovoStato = 'RISOLTO';
                        $tipologiaAdempimento = ($ev['tipo'] === 'risoluzione') ? '4' : '7';
                        break;
                    }
                    if ($ev['tipo'] === 'cessione') {
                        $nuovoStato = 'CESSATO';
                        $tipologiaAdempimento = '3';
                        break;
                    }
                }

                $haProroghe = false;
                foreach ($eventiRimasti as $ev) {
                    if ($ev['tipo'] === 'proroga') { $haProroghe = true; break; }
                }
                if ($haProroghe && $nuovoStato === 'ATTIVO') {
                    $nuovoStato = 'PROROGATO';
                    $tipologiaAdempimento = '2';
                }

                $pdo->prepare("UPDATE contratti SET stato = ?, tipologia_adempimento = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$nuovoStato, $tipologiaAdempimento, $id]);

                if ($tipoEvento === 'cessione') {
                    $pdo->prepare("UPDATE contratti SET data_cessione = NULL, corrispettivo = NULL WHERE id = ?")->execute([$id]);
                }
                if ($tipoEvento === 'risoluzione' || $tipoEvento === 'risoluzione_corrispettivo') {
                    $nuovaDurataAl = $contratto['durata_al'];
                    foreach ($eventiRimasti as $ev) {
                        $det = json_decode($ev['dettagli_json'] ?? '{}', true) ?: [];
                        if ($ev['tipo'] === 'proroga' && !empty($det['data_fine_proroga'])) {
                            $nuovaDurataAl = $det['data_fine_proroga'];
                            break;
                        }
                    }
                    $pdo->prepare("UPDATE contratti SET durata_al = ? WHERE id = ?")->execute([$nuovaDurataAl, $id]);
                }
                if ($tipoEvento === 'proroga') {
                    $haAltreProroghe = false;
                    foreach ($eventiRimasti as $ev) {
                        if ($ev['tipo'] === 'proroga') { $haAltreProroghe = true; break; }
                    }
                    if (!$haAltreProroghe) {
                        $pdo->prepare("UPDATE contratti SET data_fine_proroga = NULL, tipologia_proroga = NULL WHERE id = ?")->execute([$id]);
                    }
                }

                $pdo->commit();

                Logger::azione('evento_delete', 'evento', $idEvento, [
                    'tipo_evento' => $tipoEvento,
                    'nuovo_stato' => $nuovoStato,
                ]);

                header("Location: contract.php?id=$id&msg=Evento+eliminato+con+successo");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                Logger::errore('evento_delete', $e->getMessage(), $idEvento);
                header("Location: contract.php?id=$id&error=" . urlencode('Errore eliminazione: ' . $e->getMessage()));
                exit;
            }
        }
    }
    header("Location: contract.php?id=$id&error=Evento+non+trovato");
    exit;
}

/**
 * Determina l'ID dell'ultimo evento (più recente) per il pulsante "PDF ultimo evento".
 */
$ultimoEventoId = 0;
if (!empty($eventi)) {
    $ultimoEventoId = (int)$eventi[0]['id'];
}

// Flash errors provenienti dalla generazione XML (pattern PRG)
$flashRliErrors = $_SESSION['flash_rli_errors'] ?? [];
unset($_SESSION['flash_rli_errors']);

/**
 * Etichetta dinamica per il pulsante PDF di ciascun evento.
 */
function labelEventoPdf(string $tipo): string {
    $map = [
        'registrazione' => '📄 PDF Registrazione',
        'annualita' => '📄 PDF Annualità',
        'proroga' => '📄 PDF Proroga',
        'cessione' => '📄 PDF Cessione',
        'risoluzione' => '📄 PDF Risoluzione',
        'conguaglio' => '📄 PDF Conguaglio',
        'subentro' => '📄 PDF Subentro',
        'risoluzione_corrispettivo' => '📄 PDF Risoluzione',
        'rinegoziazione' => '📄 PDF Rinegoziazione',
    ];
    return $map[$tipo] ?? '📄 PDF Modello RLI';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Contratto #<?= $id ?> — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h4 { color: #4a5568; margin: 15px 0 8px 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8em; font-weight: bold; text-transform: uppercase; }
        .badge-ATTIVO { background: #c6f6d5; color: #22543d; }
        .badge-PROROGATO { background: #bee3f8; color: #2c5282; }
        .badge-CESSATO { background: #feebc8; color: #7b341e; }
        .badge-RISOLTO { background: #e2e8f0; color: #4a5568; }
        .badge-SCADUTO { background: #fed7d7; color: #742a2a; }
        .badge-BOZZA { background: #fefcbf; color: #744210; }
        .badge-L2 { background: #c6f6d5; color: #22543d; border: 1px solid #48bb78; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .field { margin-bottom: 10px; }
        .field-label { color: #718096; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; }
        .field-value { font-size: 1em; color: #2d3748; margin-top: 2px; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-red { background: #f56565; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-sm { padding: 5px 12px; font-size: 0.8em; }
        .actions-bar { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0; align-items: center; }
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #cbd5e0; }
        .event { position: relative; margin-bottom: 20px; padding: 15px 20px; background: #f7fafc; border-radius: 8px; border-left: 4px solid #4299e1; }
        .event::before { content: ''; position: absolute; left: -27px; top: 22px; width: 12px; height: 12px; border-radius: 50%; background: #4299e1; border: 3px solid #fff; box-shadow: 0 0 0 2px #4299e1; }
        .event.proroga { border-color: #48bb78; }
        .event.proroga::before { background: #48bb78; box-shadow: 0 0 0 2px #48bb78; }
        .event.cessione { border-color: #ed8936; }
        .event.cessione::before { background: #ed8936; box-shadow: 0 0 0 2px #ed8936; }
        .event.risoluzione, .event.risoluzione_corrispettivo { border-color: #f56565; }
        .event.risoluzione::before, .event.risoluzione_corrispettivo::before { background: #f56565; box-shadow: 0 0 0 2px #f56565; }
        .event.subentro { border-color: #9f7aea; }
        .event.subentro::before { background: #9f7aea; box-shadow: 0 0 0 2px #9f7aea; }
        .event.rinegoziazione { border-color: #38b2ac; }
        .event.rinegoziazione::before { background: #38b2ac; box-shadow: 0 0 0 2px #38b2ac; }
        .event-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
        .event-title { font-weight: bold; font-size: 1.05em; }
        .event-date { color: #718096; font-size: 0.9em; }
        .event-details { font-size: 0.9em; color: #4a5568; }
        .event-actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.8em; text-transform: uppercase; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .allegato-chip { display: inline-flex; align-items: center; gap: 6px; background: #e6fffa; color: #234e52; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: bold; margin-left: 8px; }

        /* Modal Condividi */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; padding: 25px; max-width: 700px; width: 90%; max-height: 85vh; overflow-y: auto; }
        .modal h3 { margin-top: 0; color: #2b6cb0; }
        .modal-close { float: right; cursor: pointer; font-size: 1.5em; line-height: 1; color: #a0aec0; border: none; background: none; }
        .modal-close:hover { color: #f56565; }
        .cond-row { display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #edf2f7; }
        .cond-row:hover { background: #f7fafc; }
        .cond-row input[type="checkbox"] { width: auto; margin: 0; }
        .cond-row .user-info { flex: 1; }
        .cond-row .user-name { font-weight: 600; color: #2d3748; }
        .cond-row .user-email { font-size: 0.8em; color: #718096; }
        .cond-row select { width: auto; margin: 0; padding: 5px 10px; font-size: 0.85em; }
        .cond-row.disabled { opacity: 0.5; }
        .cond-empty { text-align: center; padding: 30px; color: #a0aec0; }
        .cond-actions { margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>

    <?php if (!empty($flashRliErrors)): ?>
        <div class="alert alert-error" style="background:#fed7d7; color:#742a2a; border-left:4px solid #e53e3e; padding:18px 22px; border-radius:8px; margin-bottom:20px;">
            <h4 style="margin:0 0 10px 0; color:#742a2a;">⚠ Impossibile generare l'XML RLI</h4>
            <ul style="margin:0 0 10px 0; padding-left:20px; line-height:1.6;">
                <?php foreach ($flashRliErrors as $flashErr): ?>
                    <li><?= Security::h($flashErr) ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin:0; font-size:0.9em;">Correggi i dati del contratto e riprova.</p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])) echo "<div class='alert alert-error'>⚠ " . Security::h($_GET['error']) . "</div>"; ?>

    <a href="<?= url('index.php') ?>" style="color:#4299e1; text-decoration:none; font-size:0.9em;">← Torna ai contratti</a>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:start;">
            <div>
                <h2 style="margin-bottom:5px;">
                    <?= Security::h($locatori[0]['cognome'] ?? '?') ?> / <?= Security::h($conduttori[0]['cognome'] ?? '?') ?>
                </h2>
                <p style="color:#718096; font-family:monospace; margin:0;">
                    <?= Security::h($contratto['id_contratto_univoco'] ?: 'ID non assegnato') ?>
                    <?php if (!empty($contratto['codice_identificativo_contratto'])): ?>
                        · <span class="badge-ade" style="color:#22543d; background:#c6f6d5; padding:2px 8px; border-radius:8px; font-weight:bold;">AdE: <?= Security::h($contratto['codice_identificativo_contratto']) ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="text-align:right;">
                <span class="badge badge-<?= Security::h($contratto['stato']) ?>"><?= Security::h($contratto['stato']) ?></span>
                <?php if ($contratto['tipologia_contratto'] === 'L2'): ?>
                    <span class="badge badge-L2" title="Contratto a canone concordato (L2)">📜 CANONE CONCORDATO</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="actions-bar">
            <?php if ($puoModificare && $contratto['stato'] === 'RISOLTO'): ?>
                <div class="alert-error" style="width:100%; padding:10px 15px; border-radius:6px; background:#fed7d7; color:#742a2a; margin-bottom:10px;">
                    ⚠ Questo contratto è stato risolto. Non è possibile modificarlo o aggiungere nuovi eventi.
                </div>
            <?php endif; ?>
            <?php if ($puoModificare && in_array($contratto['stato'], ['ATTIVO','PROROGATO','BOZZA'])): ?>
                <a href="<?= url('contract_event_annualita.php') ?>?id=<?= $id ?>" class="btn btn-green">📅 Annualità</a>
                <a href="<?= url('contract_event_proroga.php') ?>?id=<?= $id ?>" class="btn btn-green">🔄 Proroga</a>
                <a href="<?= url('contract_event_cessione.php') ?>?id=<?= $id ?>" class="btn btn-orange">🔀 Cessione</a>
                <a href="<?= url('contract_event_subentro.php') ?>?id=<?= $id ?>" class="btn btn-orange">👤 Subentro</a>
                <a href="<?= url('contract_event_rinegoziazione.php') ?>?id=<?= $id ?>" class="btn btn-orange">💱 Rinegoziazione</a>
                <a href="<?= url('contract_event_risoluzione.php') ?>?id=<?= $id ?>&tipo=risoluzione" class="btn btn-red">❌ Risoluzione</a>
                <?php if ($puoModificare): ?>
                    <a href="<?= url('contract_new.php') ?>?id=<?= $id ?>" class="btn btn-orange">✏️ Modifica</a>
                <?php endif; ?>
                <?php if ($isProprietario): ?>
                    <button type="button" class="btn btn-primary" onclick="openCondividiModal()">👥 Condividi</button>
                <?php endif; ?>
                <?php if ($puoModificare): ?>
                    <a href="<?= url('comunicazioni.php') ?>?contratto=<?= $id ?>&template=richiesta_firma_rli" class="btn btn-green">📧 Invia PDF via Email</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="actions-bar" style="border-top:1px solid #edf2f7; padding-top:15px;">
            <?php if (!empty($eventi)): ?>
                <?php $ultimo = $eventi[0]; ?>

                <!-- PDF dell'ULTIMO evento (o evento più recente) -->
                <form method="POST" action="genera_modello_pdf.php" target="_blank" style="display:inline">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="id_contratto" value="<?= $id ?>">
                    <input type="hidden" name="id_evento" value="<?= (int)$ultimo['id'] ?>">
                    <button class="btn btn-orange" type="submit"
                            title="Genera il PDF del modello RLI per l'evento più recente (<?= Security::h(rli_evento_label($ultimo['tipo'])) ?>)">
                        📄 PDF Modello RLI (<?= Security::h(rli_evento_label($ultimo['tipo'])) ?>)
                    </button>
                </form>

                <!-- XML dell'ultimo evento -->
                <form method="POST" action="genera_xml.php" style="display:inline">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="id_contratto" value="<?= $id ?>">
                    <input type="hidden" name="id_evento" value="<?= (int)$ultimo['id'] ?>">
                    <button class="btn btn-primary" type="submit">⬇ Scarica XML (.RLI)</button>
                </form>

                <!-- VALIDA XML (AJAX) -->
                <button type="button" class="btn btn-gray"
                        onclick="validaXml(<?= $id ?>, <?= (int)($ultimo['id'] ?? 0) ?>)"
                        title="Valida l'XML contro lo schema XSD senza scaricare il file">
                    🔍 Valida XML
                </button>

                <?php
                $ultimoAllegato = null;
                foreach ($eventi as $ev) {
                    if (!empty($ev['ha_allegato_blob']) || !empty($ev['allegato_path'])) { $ultimoAllegato = $ev; break; }
                }
                if ($ultimoAllegato):
                ?>
                    <a href="?id=<?= $id ?>&view_pdf_evento=<?= (int)$ultimoAllegato['id'] ?>" target="_blank"
                       class="btn btn-green"
                       title="Visualizza l'allegato: <?= Security::h($ultimoAllegato['nome_allegato'] ?: 'PDF') ?>">
                       📎 Visualizza allegato PDF
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h4>Dati contratto</h4>
            <div class="grid-2">
                <div class="field"><div class="field-label">Tipologia</div><div class="field-value"><?= Security::h($contratto['tipologia_contratto']) ?></div></div>
                <div class="field"><div class="field-label">Ufficio</div><div class="field-value"><?= Security::h($contratto['ufficio_territoriale'] ?: '—') ?></div></div>
                <div class="field"><div class="field-label">Stipula</div><div class="field-value"><?= $contratto['data_stipula'] ? date('d/m/Y', strtotime($contratto['data_stipula'])) : '—' ?></div></div>
                <div class="field"><div class="field-label">Data invio (registrazione)</div><div class="field-value"><?= $contratto['data_invio'] ? date('d/m/Y', strtotime($contratto['data_invio'])) : '—' ?></div></div>
                <?php
                // Recupera la data di invio dell'ultimo evento
                $stmtUltimoInvio = $pdo->prepare("SELECT data_invio FROM eventi_contratto WHERE id_contratto = ? AND data_invio IS NOT NULL ORDER BY data_evento DESC, id DESC LIMIT 1");
                $stmtUltimoInvio->execute([$id]);
                $ultimaDataInvio = $stmtUltimoInvio->fetchColumn();
                if ($ultimaDataInvio):
                ?>
                <div class="field"><div class="field-label">Data invio ultimo evento</div><div class="field-value"><?= date('d/m/Y', strtotime($ultimaDataInvio)) ?></div></div>
                <?php endif; ?>
                <div class="field"><div class="field-label">Durata</div><div class="field-value">
                    <?= $contratto['durata_dal'] ? date('d/m/Y', strtotime($contratto['durata_dal'])) : '—' ?>
                    → <?= $contratto['durata_al'] ? date('d/m/Y', strtotime($contratto['durata_al'])) : '—' ?>
                </div></div>
                <div class="field"><div class="field-label">Canone</div><div class="field-value">€ <?= $contratto['importo_canone'] ? number_format((float)$contratto['importo_canone'], 2, ',', '.') : '—' ?></div></div>
                <div class="field"><div class="field-label">Cedolare</div><div class="field-value">
                    <?php $ced = (int)$contratto['cedolare_secca']; ?>
                    <?= $ced === 1 ? '100%' : ($ced === 2 ? 'Mista' : 'No') ?>
                </div></div>
            </div>
        </div>

        <div class="card">
            <h4>Richiedente</h4>
            <?php if ($richiedente): ?>
                <div class="field"><div class="field-label">Nome</div><div class="field-value"><?= Security::h($richiedente['cognome'] . ' ' . $richiedente['nome']) ?></div></div>
                <div class="field"><div class="field-label">CF</div><div class="field-value"><?= Security::h($richiedente['codice_fiscale']) ?></div></div>
                <div class="field"><div class="field-label">Email</div><div class="field-value"><?= Security::h($richiedente['email'] ?: '—') ?></div></div>
            <?php else: ?>
                <p style="color:#a0aec0;">Nessun richiedente configurato.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <h4>Locatori (<?= count($locatori) ?>)</h4>
            <table>
                <thead><tr><th>Nome</th><th>Quota</th><th>Ced.</th></tr></thead>
                <tbody>
                    <?php foreach ($locatori as $l): ?>
                        <tr>
                            <td>
                                <?= Security::h($l['cognome'] . ' ' . $l['nome']) ?>
                                <?php if ($l['richiedente']): ?><span class="badge badge-ATTIVO" style="font-size:0.7em;">Rich.</span><?php endif; ?>
                            </td>
                            <td><?= number_format((float)$l['percentuale_possesso'], 2, ',', '') ?>%</td>
                            <td><?= $l['opzione_cedolare'] ? '✓' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h4>Conduttori (<?= count($conduttori) ?>)</h4>
            <table>
                <thead><tr><th>Nome</th><th>CF</th></tr></thead>
                <tbody>
                    <?php foreach ($conduttori as $c): ?>
                        <tr>
                            <td><?= Security::h($c['cognome'] . ' ' . $c['nome']) ?></td>
                            <td style="font-family:monospace; font-size:0.85em;"><?= Security::h($c['codice_fiscale']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h4>Immobili (<?= count($immobili) ?>)</h4>
        <table>
            <thead><tr><th>ID</th><th>Comune</th><th>Indirizzo</th><th>Cat.</th><th>Tipologia</th><th>I/P</th></tr></thead>
            <tbody>
                <?php foreach ($immobili as $i): ?>
                    <tr>
                        <td><?= Security::h($i['identificativo_interno']) ?></td>
                        <td><?= Security::h($i['comune']) ?> (<?= Security::h($i['provincia']) ?>)</td>
                        <td><?= Security::h(trim(($i['toponimo'] ?? '') . ' ' . ($i['indirizzo'] ?? '') . ' ' . ($i['civico'] ?? ''))) ?></td>
                        <td><?= Security::h($i['categoria'] ?: $i['tipo_catasto']) ?></td>
                        <td><?= rli_tipologie_immobile()[(int)$i['tipologia_immobile']] ?? '—' ?></td>
                        <td><?= Security::h($i['intero_porzione']) ?></td>
                        <td>
                            <a href="<?= url('immobile_new.php') ?>?edit=<?= (int)$i['id'] ?>" class="btn btn-orange btn-sm" title="Modifica immobile">✏️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h4>Timeline eventi (<?= count($eventi) ?>)</h4>
        <?php if (empty($eventi)): ?>
            <p style="color:#a0aec0;">Nessun evento registrato su questo contratto.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($eventi as $e): ?>
                    <div class="event <?= Security::h($e['tipo']) ?>">
                        <div class="event-header">
                            <div class="event-title">
                                <?= rli_evento_label($e['tipo']) ?>
                                <?php if (!empty($e['ha_allegato_blob']) || !empty($e['allegato_path'])): ?>
                                    <span class="allegato-chip" title="<?= Security::h($e['nome_allegato'] ?: 'allegato.pdf') ?>">
                                        📎 <?= Security::h(mb_strimwidth($e['nome_allegato'] ?: 'allegato.pdf', 0, 30, '…')) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <div class="event-date"><?= $e['data_evento'] ? date('d/m/Y', strtotime($e['data_evento'])) : '—' ?></div>

                                <?php if ($e['tipo'] !== 'registrazione' && $puoModificare): ?>
                                    <?php
                                    // Determina la pagina di modifica in base al tipo di evento
                                    $editPage = 'contract_event_edit.php'; // fallback
                                    if ($e['tipo'] === 'cessione') $editPage = 'contract_event_cessione.php';
                                    elseif ($e['tipo'] === 'subentro') $editPage = 'contract_event_subentro.php';
                                    elseif ($e['tipo'] === 'risoluzione' || $e['tipo'] === 'risoluzione_corrispettivo') $editPage = 'contract_event_risoluzione.php';
                                    elseif ($e['tipo'] === 'proroga') $editPage = 'contract_event_proroga.php';
                                    elseif ($e['tipo'] === 'annualita') $editPage = 'contract_event_annualita.php';
                                    ?>
                                    <a href="<?= url($editPage) ?>?id=<?= $id ?>&id_evento=<?= (int)$e['id'] ?>"
                                       class="btn btn-orange btn-sm"
                                       title="Modifica questo adempimento">
                                        ✏️ Modifica
                                    </a>

                                    <form method="POST" style="display:inline; margin:0;"
                                          onsubmit="return confirm('⚠ Eliminare questo evento?\n\n<?= Security::h(rli_evento_label($e['tipo'])) ?> del <?= $e['data_evento'] ? date('d/m/Y', strtotime($e['data_evento'])) : '—' ?>\n\nL\'operazione è irreversibile. Se era l\'ultima proroga/cessione/risoluzione, il contratto tornerà allo stato precedente.');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="delete_evento" value="<?= (int)$e['id'] ?>">
                                        <button type="submit" class="btn btn-red btn-sm"
                                                title="Elimina questo adempimento">
                                            🗑 Elimina
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:0.75em; color:#a0aec0; font-style:italic;"
                                          title="L'evento di registrazione non è modificabile da qui. Usa 'Modifica contratto'.">
                                        🔒 Bloccato
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php $det = json_decode($e['dettagli_json'] ?? '{}', true) ?: []; ?>
                        <div class="event-details">
                            <?php if (!empty($det['descrizione'])): ?>
                                <p style="margin:5px 0;"><?= Security::h($det['descrizione']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($det['note'])): ?>
                                <p style="margin:5px 0; font-style:italic; color:#718096;">Note: <?= Security::h($det['note']) ?></p>
                            <?php endif; ?>
                            <?php if ((float)$e['imposta_registro'] + (float)$e['imposta_bollo'] + (float)$e['sanzione_registro'] + (float)$e['sanzione_bollo'] > 0): ?>
                                <p style="margin:5px 0; font-size:0.9em; color:#4a5568;">
                                    💰 Reg: €<?= number_format((float)$e['imposta_registro'], 2, ',', '') ?> ·
                                    Bollo: €<?= number_format((float)$e['imposta_bollo'], 2, ',', '') ?>
                                    <?php if ((float)$e['sanzione_registro'] > 0): ?>
                                        · Sanz: €<?= number_format((float)$e['sanzione_registro'], 2, ',', '') ?>
                                    <?php endif; ?>
                                    <?php if ((float)$e['interessi_registro'] > 0): ?>
                                        · Int: €<?= number_format((float)$e['interessi_registro'], 2, ',', '') ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- AZIONI EVENTO: PDF, XML, Allegato -->
                        <div class="event-actions">

                            <!-- PDF specifico di questo evento -->
                            <form method="POST" action="genera_modello_pdf.php" target="_blank" style="display:inline">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="id_contratto" value="<?= $id ?>">
                                <input type="hidden" name="id_evento" value="<?= (int)$e['id'] ?>">
                                <button type="submit" class="btn btn-orange btn-sm"
                                        title="Genera il modello PDF RLI per questo evento">
                                    <?= Security::h(labelEventoPdf($e['tipo'])) ?>
                                </button>
                            </form>

                            <!-- XML specifico di questo evento -->
                            <form method="POST" action="genera_xml.php" style="display:inline">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="id_contratto" value="<?= $id ?>">
                                <input type="hidden" name="id_evento" value="<?= $e['id'] ?>">
                                <button type="submit" class="btn btn-gray btn-sm">⬇ XML</button>
                            </form>

                            <!-- XML storico salvato e validato per questo evento -->
                            <?php if (!empty($e['ha_xml_generato'])): ?>
                                <a href="download_xml.php?id_contratto=<?= $id ?>&id_evento=<?= (int)$e['id'] ?>"
                                   class="btn btn-green btn-sm"
                                   title="Scarica l'XML storico generato e validato per questo evento">
                                    ⬇ XML storico
                                </a>
                            <?php endif; ?>

                            <!-- VALIDA XML SPECIFICO EVENTO -->
                            <button type="button" class="btn btn-orange btn-sm"
                                    onclick="validaXml(<?= $id ?>, <?= (int)$e['id'] ?>)"
                                    title="Valida l'XML di questo specifico evento">
                                🔍 Valida XML
                            </button>

                            <!-- Allegato PDF dell'evento -->
                            <?php if (!empty($e['ha_allegato_blob']) || !empty($e['allegato_path'])): ?>
                                <a href="?id=<?= $id ?>&view_pdf_evento=<?= (int)$e['id'] ?>" target="_blank"
                                   class="btn btn-primary btn-sm"
                                   title="Visualizza: <?= Security::h($e['nome_allegato'] ?: 'allegato.pdf') ?>">
                                   👁 Visualizza PDF
                                </a>
                                <a href="?id=<?= $id ?>&download_pdf_evento=<?= (int)$e['id'] ?>"
                                   class="btn btn-gray btn-sm"
                                   title="Scarica l'allegato">
                                   ⬇ Scarica PDF
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- MODALE CONDIVIDI -->
<div class="modal-backdrop" id="modal-condividi" onclick="if(event.target===this) closeCondividiModal()">
    <div class="modal">
        <button class="modal-close" onclick="closeCondividiModal()">×</button>
        <h3>👥 Condividi contratto</h3>
        <p style="color:#718096; font-size:0.9em; margin-top:0;">
            Contratto <strong><?= Security::h($contratto['id_contratto_univoco'] ?: '#' . $id) ?></strong>
        </p>

        <div id="cond-loading" style="text-align:center; padding:30px; color:#a0aec0;">Caricamento...</div>
        <div id="cond-content" style="display:none;"></div>

        <div class="cond-actions" id="cond-actions" style="display:none;">
            <button type="button" class="btn btn-gray" onclick="closeCondividiModal()">Annulla</button>
            <button type="button" class="btn btn-green" onclick="saveCondivisioni()">💾 Salva condivisioni</button>
        </div>
    </div>
</div>

<script>
const COND_CSRF = <?= json_encode(Security::csrfToken()) ?>;
const COND_ID_CONTRATTO = <?= (int)$id ?>;
let condUtenti = [];
let condEsistenti = {};

function openCondividiModal() {
    document.getElementById('modal-condividi').classList.add('open');
    document.getElementById('cond-loading').style.display = 'block';
    document.getElementById('cond-content').style.display = 'none';
    document.getElementById('cond-actions').style.display = 'none';

    fetch('condividi_contratto.php?id=' + COND_ID_CONTRATTO)
        .then(r => r.json())
        .then(data => {
            document.getElementById('cond-loading').style.display = 'none';
            if (!data.ok) {
                alert('Errore: ' + (data.error || 'sconosciuto'));
                closeCondividiModal();
                return;
            }
            condUtenti = data.utenti_disponibili;
            condEsistenti = {};
            data.condivisioni.forEach(c => {
                condEsistenti[c.id_utente] = c.permesso;
            });
            renderCondividi();
            document.getElementById('cond-content').style.display = 'block';
            document.getElementById('cond-actions').style.display = 'flex';
        })
        .catch(err => {
            document.getElementById('cond-loading').style.display = 'none';
            alert('Errore di rete: ' + err);
            closeCondividiModal();
        });
}

function renderCondividi() {
    const content = document.getElementById('cond-content');
    
    let html = '';
    
    // Sezione: condivisioni attive
    const attive = condUtenti.filter(u => condEsistenti.hasOwnProperty(u.id));
    if (attive.length > 0) {
        html += '<h4 style="margin-bottom:10px; color:#553c9a; font-size:0.95em;">🔗 Condivisioni attive</h4>';
        attive.forEach(u => {
            const perm = condEsistenti[u.id];
            const permLabel = perm === 'edit' ? '✏️ Modifica' : '👁 Sola lettura';
            html += '<div class="cond-row" style="background:#f7fafc;">';
            html += '  <div class="user-info">';
            html += '    <div class="user-name">' + escapeHtml(u.cognome + ' ' + u.nome) + ' <span style="font-size:0.8em; color:#718096;">(@' + escapeHtml(u.username) + ')</span></div>';
            html += '    <div class="user-email">' + escapeHtml(u.email) + ' · <strong>' + permLabel + '</strong></div>';
            html += '  </div>';
            html += '  <button type="button" class="btn btn-red" style="padding:5px 12px; font-size:0.8em;" onclick="revocaCondivisione(' + u.id + ')">🗑 Revoca</button>';
            html += '</div>';
        });
        html += '<hr style="margin:15px 0; border:none; border-top:1px solid #e2e8f0;">';
    }
    
    // Sezione: utenti disponibili
    if (condUtenti.length === 0) {
        html += '<div class="cond-empty">Nessun altro utente disponibile.</div>';
    } else {
        html += '<h4 style="margin-bottom:10px; color:#4a5568; font-size:0.95em;">➕ Aggiungi utenti</h4>';
    }
    condUtenti.forEach(u => {
        const isSelected = condEsistenti.hasOwnProperty(u.id);
        const permesso = condEsistenti[u.id] || 'view';
        const checked = isSelected ? 'checked' : '';
        
        html += '<div class="cond-row" data-id="' + u.id + '">';
        html += '  <input type="checkbox" ' + checked + ' onchange="toggleCondivisione(' + u.id + ', this.checked)">';
        html += '  <div class="user-info">';
        html += '    <div class="user-name">' + escapeHtml(u.cognome + ' ' + u.nome) + ' <span style="font-size:0.8em; color:#718096;">(@' + escapeHtml(u.username) + ')</span></div>';
        html += '    <div class="user-email">' + escapeHtml(u.email) + '</div>';
        html += '  </div>';
        html += '  <select onchange="changePermesso(' + u.id + ', this.value)" ' + (isSelected ? '' : 'disabled') + '>';
        html += '    <option value="view" ' + (permesso === 'view' ? 'selected' : '') + '>👁 Sola lettura</option>';
        html += '    <option value="edit" ' + (permesso === 'edit' ? 'selected' : '') + '>✏️ Modifica</option>';
        html += '  </select>';
        html += '</div>';
    });

    content.innerHTML = html;
}

function toggleCondivisione(id, checked) {
    if (checked) {
        condEsistenti[id] = 'view';
    } else {
        delete condEsistenti[id];
    }
    renderCondividi();
}

function changePermesso(id, permesso) {
    if (condEsistenti.hasOwnProperty(id)) {
        condEsistenti[id] = permesso;
    }
}

function revocaCondivisione(idUtente) {
    if (!confirm('Revocare l\'accesso a questo utente?')) return;

    const body = new URLSearchParams();
    body.append('_csrf', COND_CSRF);
    body.append('id_contratto', COND_ID_CONTRATTO);
    body.append('azione', 'revoke');
    body.append('id_utente', idUtente);

    fetch('condividi_contratto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            delete condEsistenti[idUtente];
            renderCondividi();
        } else {
            alert('Errore: ' + (data.error || 'sconosciuto'));
        }
    })
    .catch(err => alert('Errore di rete: ' + err));
}

function saveCondivisioni() {
    const body = new URLSearchParams();
    body.append('_csrf', COND_CSRF);
    body.append('id_contratto', COND_ID_CONTRATTO);
    body.append('azione', 'save');
    Object.keys(condEsistenti).forEach(id => {
        body.append('utenti[' + id + ']', condEsistenti[id]);
    });

    fetch('condividi_contratto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            alert('✅ Condivisioni salvate (' + data.totale_attive + ' utenti)');
            closeCondividiModal();
            location.reload();
        } else {
            alert('Errore: ' + (data.error || 'sconosciuto'));
        }
    })
    .catch(err => alert('Errore di rete: ' + err));
}

function closeCondividiModal() {
    document.getElementById('modal-condividi').classList.remove('open');
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCondividiModal(); closeValidaModal(); } });

// ============================================================
// VALIDA XML (AJAX)
// ============================================================
function validaXml(idContratto, idEvento) {
    const modal = document.getElementById('modal-valida');
    const title = document.getElementById('valida-title');
    const body  = document.getElementById('valida-body');

    title.textContent = 'Validazione XML — Contratto #' + idContratto;
    body.innerHTML = '<div style="text-align:center;padding:30px;color:#718096;">⏳ Validazione in corso...</div>';
    modal.classList.add('open');

    const fd = new FormData();
    fd.append('_csrf', COND_CSRF);
    fd.append('id_contratto', idContratto);
    fd.append('id_evento', idEvento);

    fetch('valida_xml.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                body.innerHTML = '<div style="padding:15px;border-radius:6px;background:#fed7d7;color:#742a2a;">'
                               + '❌ Errore: ' + escapeHtml(data.error || 'sconosciuto') + '</div>';
                return;
            }

            let html = '';
            const valid = data.valid;

            if (valid) {
                html += '<div style="padding:15px;border-radius:6px;background:#c6f6d5;color:#22543d;margin-bottom:15px;">';
                html += '<strong style="font-size:1.1em;">✅ XML valido</strong>';
                html += '<div style="font-size:0.85em;margin-top:8px;">';
                html += 'XSD: <code>' + escapeHtml(data.xsd) + '</code><br>';
                html += 'Dimensione: <strong>' + (data.size / 1024).toFixed(1) + ' KB</strong><br>';
                html += 'Tipo adempimento: <strong>' + data.tipo_adempimento + '</strong>';
                html += '</div></div>';
                html += '<p style="color:#4a5568;font-size:0.9em;">Il file è pronto per il download e per il controllo telematico AdE.</p>';
            } else {
                html += '<div style="padding:15px;border-radius:6px;background:#fed7d7;color:#742a2a;margin-bottom:15px;">';
                html += '<strong style="font-size:1.1em;">❌ XML non valido</strong>';
                html += '<div style="font-size:0.85em;margin-top:5px;">' + data.errors.length + ' error' + (data.errors.length === 1 ? 'e' : 'i') + '</div>';
                html += '</div>';
                html += '<ul style="font-family:Consolas,monospace;font-size:0.8em;line-height:1.6;padding-left:20px;background:#f7fafc;padding:15px 15px 15px 30px;border-radius:6px;">';
                data.errors.forEach(e => {
                    html += '<li style="margin-bottom:8px;">' + escapeHtml(e) + '</li>';
                });
                html += '</ul>';
            }

            if (data.warnings && data.warnings.length > 0) {
                html += '<details style="margin-top:15px;"><summary style="cursor:pointer;color:#7b341e;font-weight:bold;padding:8px 0;">'
                     + '⚠ ' + data.warnings.length + ' warning (non bloccanti)</summary>';
                html += '<ul style="font-family:Consolas,monospace;font-size:0.8em;line-height:1.6;padding-left:20px;background:#fffaf0;padding:15px 15px 15px 30px;border-radius:6px;margin-top:10px;">';
                data.warnings.forEach(w => {
                    html += '<li>' + escapeHtml(w) + '</li>';
                });
                html += '</ul></details>';
            }

            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<div style="padding:15px;border-radius:6px;background:#fed7d7;color:#742a2a;">'
                           + '❌ Errore di rete: ' + escapeHtml(err.message || String(err)) + '</div>';
        });
}

function closeValidaModal() {
    const m = document.getElementById('modal-valida');
    if (m) m.classList.remove('open');
}
</script>

<!-- MODAL VALIDA XML -->
<div class="modal-backdrop" id="modal-valida" onclick="if(event.target===this) closeValidaModal()">
    <div class="modal" style="max-width:800px;">
        <button class="modal-close" onclick="closeValidaModal()">×</button>
        <h3 id="valida-title">Validazione XML</h3>
        <div id="valida-body"></div>
    </div>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
