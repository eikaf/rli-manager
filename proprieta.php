<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

// CSRF check su tutte le POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

// --- SALVATAGGIO PROPRIETÀ (Singolo e Bulk) ---
if (isset($_POST['save_proprieta']) || isset($_POST['save_bulk'])) {
    try {
        $pdo->beginTransaction();

        // Recuperiamo i dati a seconda che sia bulk o singolo
        if (isset($_POST['save_bulk'])) {
            $idLoc = (int)$_POST['id_locatore_bulk'];
            $immobiliSel = $_POST['immobili'] ?? [];
            $quote = $_POST['quote'] ?? [];
            $dateIni = $_POST['data_inizio'] ?? [];
            $dateFin = $_POST['data_fine'] ?? [];
            
            // Rimuoviamo le vecchie proprietà del locatore
            $pdo->prepare("DELETE FROM proprieta_immobili WHERE id_locatore = ?")->execute([$idLoc]);
            
            $stmt = $pdo->prepare("INSERT INTO proprieta_immobili (id_locatore, id_immobile, percentuale, data_inizio, data_fine) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($immobiliSel as $iid) {
                $iid = (int)$iid;
                $perc = (float)str_replace(',', '.', $quote[$iid] ?? 100);
                $dIni = !empty($dateIni[$iid]) ? $dateIni[$iid] : null;
                $dFin = !empty($dateFin[$iid]) ? $dateFin[$iid] : null;

                // Controllo somma per l'immobile nel periodo specificato (dal/al)
                // Se le date non sono specificate, consideriamo la proprietà attiva oggi.
                $dataCheck = $dIni ?: date('Y-m-d');
                $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(percentuale), 0) FROM proprieta_immobili WHERE id_immobile = ? AND id_locatore != ? AND (data_inizio IS NULL OR data_inizio <= ?) AND (data_fine IS NULL OR data_fine >= ?)");
                $stmtSum->execute([$iid, $idLoc, $dataCheck, $dataCheck]);
                $sommaEsistente = (float)$stmtSum->fetchColumn();

                if (($sommaEsistente + $perc) > 100.01) {
                    throw new Exception("Errore: la somma delle quote per l'immobile supererebbe il 100% nel periodo indicato. Assegnato ad altri in quel periodo: " . number_format($sommaEsistente, 2, ',', '') . "%. Stai provando ad assegnare: " . number_format($perc, 2, ',', '') . "%.");
                }
                
                $stmt->execute([$idLoc, $iid, $perc, $dIni, $dFin]);
            }
            $msg = "Proprietà aggiornate";

        } else {
            $idLoc = (int)$_POST['id_locatore'];
            $idImm = (int)$_POST['id_immobile'];
            $perc = (float)str_replace(',', '.', $_POST['percentuale']);
            $dataIni = !empty($_POST['data_inizio']) ? $_POST['data_inizio'] : null;
            $dataFin = !empty($_POST['data_fine']) ? $_POST['data_fine'] : null;

            // Controllo somma
            $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(percentuale), 0) FROM proprieta_immobili WHERE id_immobile = ? AND id_locatore != ? AND (data_fine IS NULL OR data_fine >= CURDATE())");
            $stmtSum->execute([$idImm, $idLoc]);
            $sommaEsistente = (float)$stmtSum->fetchColumn();

            if (($sommaEsistente + $perc) > 100.01) {
                throw new Exception("Errore: la somma delle quote supererebbe il 100%. Assegnato ad altri: " . number_format($sommaEsistente, 2, ',', '') . "%. Stai provando ad assegnare: " . number_format($perc, 2, ',', '') . "%.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO proprieta_immobili (id_locatore, id_immobile, percentuale, data_inizio, data_fine)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE percentuale = VALUES(percentuale), data_inizio = VALUES(data_inizio), data_fine = VALUES(data_fine)
            ");
            $stmt->execute([$idLoc, $idImm, $perc, $dataIni, $dataFin]);
            $msg = "Proprietà salvata";
        }

        $pdo->commit();
        header("Location: proprieta.php?msg=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        Logger::errore('proprieta_save', $e->getMessage());
    }
}

// --- Eliminazione (POST) ---
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM proprieta_immobili WHERE id = ?")->execute([$id]);
    Logger::azione('proprieta_delete', 'proprieta', $id);
    header("Location: proprieta.php?msg=Eliminata");
    exit;
}

$soggetti = $pdo->query("SELECT * FROM soggetti WHERE tipologia_default IN ('LOCATORE','ENTRAMBI') ORDER BY cognome, nome")->fetchAll();
$immobili = $pdo->query("SELECT * FROM immobili ORDER BY identificativo_interno")->fetchAll();

$editLoc = null;
if (isset($_GET['edit_locatore'])) {
    $stmt = $pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
    $stmt->execute([$_GET['edit_locatore']]);
    $editLoc = $stmt->fetch();
}

$propEdit = [];
if ($editLoc) {
    // Recuperiamo TUTTE le proprietà, anche quelle storiche (scadute)
    $stmt = $pdo->prepare("SELECT * FROM proprieta_immobili WHERE id_locatore = ?");
    $stmt->execute([$editLoc['id']]);
    foreach ($stmt->fetchAll() as $p) {
        // Salviamo un array con percentuale, dal e al
        $propEdit[$p['id_immobile']] = [
            'percentuale' => $p['percentuale'],
            'data_inizio' => $p['data_inizio'],
            'data_fine' => $p['data_fine'],
        ];
    }
}

$proprieta = $pdo->query("
    SELECT p.*, s.cognome, s.nome, i.identificativo_interno
    FROM proprieta_immobili p
    JOIN soggetti s ON s.id = p.id_locatore
    JOIN immobili i ON i.id = p.id_immobile
    ORDER BY s.cognome, i.identificativo_interno
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Proprietà — RLI Manager</title>
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
        .card { max-width: 100%; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; }
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
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.8em; text-transform: uppercase; }
        .check-list { max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        /* Stile per la riga degli immobili */
        .check-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #edf2f7;
            flex-wrap: nowrap; /* Non andare a capo */
        }
        .check-item:last-child {
            border-bottom: none;
        }
        .check-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            margin: 0;
        }
        .check-item label {
            flex: 1;
            min-width: 150px;
            margin: 0;
            font-weight: normal;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .check-item .campi-date {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .check-item .campi-date input[type="date"] {
            width: 130px;
            padding: 4px 6px;
            margin: 0;
            font-size: 0.85em;
            flex-shrink: 0;
        }
        .check-item .campo-quota {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .check-item .campo-quota input[type="number"] {
            width: 70px;
            padding: 4px 6px;
            margin: 0;
            font-size: 0.85em;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>


    

    <div class="container">
    <?php if ($editLoc): ?>
        <div class="card">
            <h2>🏘 Proprietà di <?= Security::h($editLoc['cognome'] . ' ' . $editLoc['nome']) ?></h2>
            <form method="POST" id="form-proprieta">
                <?= Security::csrfField() ?>
                <input type="hidden" name="save_bulk" value="1">
                <input type="hidden" name="id_locatore_bulk" value="<?= $editLoc['id'] ?>">

                <label>Immobili posseduti</label>
                <div class="check-list">
                    <?php foreach ($immobili as $i): ?>
                        <div class="check-item">
                            <input type="checkbox" name="immobili[]" value="<?= $i['id'] ?>"
                                <?= isset($propEdit[$i['id']]) ? 'checked' : '' ?>>
                            <label>
                                <strong><?= Security::h($i['identificativo_interno']) ?></strong>
                                <span style="color:#718096; font-size:0.85em;"> — <?= Security::h($i['comune']) ?></span>
                            </label>
                            <div class="campi-date">
                                <span style="font-size: 0.85em; color: #4a5568;">Dal</span>
                                <input type="date" name="data_inizio[<?= $i['id'] ?>]" value="<?= Security::h($propEdit[$i['id']]['data_inizio'] ?? '') ?>">
                                <span style="font-size: 0.85em; color: #4a5568;">Al</span>
                                <input type="date" name="data_fine[<?= $i['id'] ?>]" value="<?= Security::h($propEdit[$i['id']]['data_fine'] ?? '') ?>">
                            </div>
                            <div class="campo-quota">
                                <input type="number" step="0.01" min="0" max="100"
                                    name="quote[<?= $i['id'] ?>]"
                                    value="<?= Security::h($propEdit[$i['id']]['percentuale'] ?? '100') ?>"
                                    placeholder="%">
                                <span style="font-size: 0.85em; color: #4a5568;">%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                
                    <button type="submit" class="btn btn-green">💾 Salva</button>
                    <a href="<?= url('proprieta.php') ?>" class="btn btn-gray">Annulla</a>
            </form>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error">
            ⚠ <?= Security::h(str_replace(['SQLSTATE[45000]:', '&lt;&lt;Unknown error&gt;&gt;: 1644'], '', $error)) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <strong>Cosa sono le proprietà?</strong><br>
        Associa ogni immobile ai suoi proprietari (locatori). Quando crei un contratto, verranno mostrati <strong>solo</strong> gli immobili dei locatori selezionati.
    </div>

    

    

    <div class="card">
        <h2>Locatori</h2>
        <table>
            <thead><tr><th>Nome</th><th>CF</th><th>Immobili</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($soggetti as $s):
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM proprieta_immobili WHERE id_locatore = ? AND (data_fine IS NULL OR data_fine >= CURDATE())");
                    $countStmt->execute([$s['id']]);
                    $nImm = (int)$countStmt->fetchColumn();
                ?>
                    <tr>
                        <td><strong><?= Security::h($s['cognome'] . ' ' . $s['nome']) ?></strong></td>
                        <td style="font-family:monospace; font-size:0.85em;"><?= Security::h($s['codice_fiscale']) ?></td>
                        <td>
                            <?php if ($nImm > 0): ?>
                                <span style="color:#48bb78; font-weight:bold;"><?= $nImm ?> immobile/i</span>
                            <?php else: ?>
                                <span style="color:#a0aec0;">Nessuno</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?edit_locatore=<?= $s['id'] ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.85em;">Gestisci proprietà</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Tutte le proprietà registrate (<?= count($proprieta) ?>)</h2>
        <?php if (empty($proprieta)): ?>
            <p style="color:#a0aec0;">Nessuna proprietà registrata.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Locatore</th><th>Immobile</th><th>Quota</th><th>Dal</th><th>Al</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($proprieta as $p): ?>
                        <tr>
                            <td><?= Security::h($p['cognome'] . ' ' . $p['nome']) ?></td>
                            <td><?= Security::h($p['identificativo_interno']) ?></td>
                            <td><?= number_format((float)$p['percentuale'], 2, ',', '') ?>%</td>
                            <td><?= $p['data_inizio'] ? date('d/m/Y', strtotime($p['data_inizio'])) : '—' ?></td>
                            <td><?= $p['data_fine'] ? date('d/m/Y', strtotime($p['data_fine'])) : '—' ?></td>
                            <td>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere questa proprietà?')">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                    <button class="btn btn-red" style="padding:5px 10px; font-size:0.8em;">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<script src="assets/uppercase.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-proprieta');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        // Raccogliamo tutte le quote per ciascun immobile
        const quotes = {};
        let hasError = false;
        
        form.querySelectorAll('input[name^="quote["]').forEach(input => {
            if (!input.offsetParent) return; // se è nascosto, lo ignoriamo (es. immobile non spuntato)
            const match = input.name.match(/quote\[(\d+)\]/);
            if (!match) return;
            const immobileId = match[1];
            const checkbox = form.querySelector('input[name="immobili[]"][value="' + immobileId + '"]');
            if (!checkbox || !checkbox.checked) return;
            
            const val = parseFloat(input.value) || 0;
            if (val < 0 || val > 100) {
                alert('Errore: la quota per un immobile non può essere inferiore a 0 o superiore a 100.');
                input.focus();
                hasError = true;
                return;
            }
            
            if (!quotes[immobileId]) quotes[immobileId] = 0;
            quotes[immobileId] += val;
        });
        
        if (hasError) return;
        
        // Controlliamo che la somma per ogni immobile non superi 100
        for (const [immobileId, sum] of Object.entries(quotes)) {
            if (sum > 100) {
                alert('Errore: la somma delle quote per un immobile supera il 100%. Correggi prima di salvare.');
                e.preventDefault();
                return;
            }
        }
    });
});
</script>
</body>
</html>
