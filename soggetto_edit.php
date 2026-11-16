<?php
/**
 * soggetto_edit.php — Modifica soggetto esistente.
 */
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';

$auth->requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

$idSog = (int)($_GET['id'] ?? $_POST['id_soggetto'] ?? 0);
if ($idSog <= 0) {
    header('Location: soggetti.php?error=ID+soggetto+mancante');
    exit;
}

$error = '';

// Carica soggetto
$stmt = $pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
$stmt->execute([$idSog]);
$soggetto = $stmt->fetch();
if (!$soggetto) {
    header('Location: soggetti.php?error=Soggetto+non+trovato');
    exit;
}

// === HANDLER SALVATAGGIO ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_soggetto'])) {
    try {
        $cf = Validators::sanificaCF($_POST['cf'] ?? '');
        if (!Validators::cf($cf)) {
            throw new Exception('Codice fiscale non valido (16 caratteri per PF, 11 per P.IVA)');
        }

        $cognome = strtoupper(trim($_POST['cognome'] ?? ''));
        if ($cognome === '') throw new Exception('Cognome/Ragione sociale obbligatorio');

        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email non valida');
        }

        $pec = trim($_POST['pec'] ?? '');
        if ($pec !== '' && !filter_var($pec, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('PEC non valida');
        }

        $idRappresentante = !empty($_POST['id_rappresentante']) ? (int)$_POST['id_rappresentante'] : null;

        // Verifica unicità CF (escludendo il soggetto corrente)
        $chk = $pdo->prepare("SELECT id FROM soggetti WHERE codice_fiscale = ? AND id != ? LIMIT 1");
        $chk->execute([$cf, $idSog]);
        if ($chk->fetchColumn()) {
            throw new Exception('Esiste già un altro soggetto con questo codice fiscale');
        }

        $tipo_cond = ($_POST['tipologia_conduttore'] === '') ? null : $_POST['tipologia_conduttore'];
        $sesso = ($_POST['sesso'] === '') ? null : strtoupper($_POST['sesso']);
        $data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;
        $tipo_default = $_POST['tipologia_default'] ?? 'ENTRAMBI';

        $cf_rapp = null; $cogn_rapp = null; $nome_rapp = null; $carica = null;
        if (strlen($cf) === 11 && ctype_digit($cf)) {
            $cf_rapp = Validators::sanificaCF($_POST['cf_rappresentante'] ?? '');
            $cogn_rapp = strtoupper(trim($_POST['cognome_rappresentante'] ?? ''));
            $nome_rapp = strtoupper(trim($_POST['nome_rappresentante'] ?? ''));
            $carica = $_POST['codice_carica'] ?? null;
        }

        $pdo->prepare("
            UPDATE soggetti SET
                codice_fiscale = ?, cognome = ?, nome = ?, sesso = ?, data_nascita = ?,
                comune_nascita = ?, prov_nascita = ?, email = ?, pec = ?, telefono = ?,
                tipologia_default = ?, tipologia_conduttore = ?, soggettivita_iva = ?,
                cf_rappresentante = ?, cognome_rappresentante = ?, nome_rappresentante = ?, codice_carica = ?, id_rappresentante = ?,
                note = ?
            WHERE id = ?
        ")->execute([
            $cf, $cognome, strtoupper(trim($_POST['nome'] ?? '')),
            $sesso, $data_nascita,
            strtoupper(trim($_POST['comune_nascita'] ?? '')),
            strtoupper(trim($_POST['prov_nascita'] ?? '')),
            $email, $pec, trim($_POST['telefono'] ?? ''),
            $tipo_default, $tipo_cond,
            !empty($_POST['soggettivita_iva']) ? 1 : 0,
            $cf_rapp, $cogn_rapp, $nome_rapp, $carica, $idRappresentante,
            trim($_POST['note'] ?? ''),
            $idSog
        ]);

        Logger::azione('soggetto_update', 'soggetto', $idSog, ['email' => $email]);
        header('Location: soggetti.php?msg=Soggetto+aggiornato+con+successo');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Persone fisiche già censite (per il selettore del legale rappresentante)
$personeFisiche = $pdo->query("
    SELECT id, codice_fiscale, cognome, nome
    FROM soggetti
    WHERE CHAR_LENGTH(codice_fiscale) = 16
    ORDER BY cognome, nome
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Modifica Soggetto #<?= $idSog ?> — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .alert-info { background: #bee3f8; color: #2c5282; }
        .hint { color: #718096; font-size: 0.8em; margin-top: -8px; margin-bottom: 10px; display: block; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <div style="margin-bottom: 15px;">
        <a href="<?= url('soggetti.php') ?>" class="btn btn-gray">← Torna ai soggetti</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">⚠ <?= Security::h($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>✏️ Modifica soggetto #<?= $idSog ?></h2>
        <p style="color:#718096; font-size:0.9em; margin-top:-10px;">
            <?= Security::h($soggetto['cognome'] . ' ' . $soggetto['nome']) ?>
            — CF <code><?= Security::h($soggetto['codice_fiscale']) ?></code>
        </p>

        <?php if (empty($soggetto['email'])): ?>
            <div class="alert alert-info">
                <strong>ℹ Nota:</strong> l'email è <strong>obbligatoria</strong> per i soggetti che sono
                richiedenti di un contratto (serve all'Agenzia delle Entrate per l'XML RLI).
                Compilala per evitare errori in fase di esportazione.
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>
            <input type="hidden" name="update_soggetto" value="1">
            <input type="hidden" name="id_soggetto" value="<?= $idSog ?>">

            <div class="row">
                <div>
                    <label>Tipologia *</label>
                    <select name="tipologia_default" required>
                        <?php foreach (['ENTRAMBI','LOCATORE','CONDUTTORE'] as $t): ?>
                            <option value="<?= $t ?>" <?= $soggetto['tipologia_default'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Ruolo conduttore (se applicabile)</label>
                    <select name="tipologia_conduttore">
                        <option value="">— Non conduttore —</option>
                        <?php foreach ([1=>'1 - Coop/Studenti',2=>'2 - CF Provvisorio',3=>'3 - Generico'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (string)$soggetto['tipologia_conduttore'] === (string)$v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label>Codice Fiscale / P.IVA *</label>
            <input type="text" name="cf" required maxlength="16" value="<?= Security::h($soggetto['codice_fiscale']) ?>">
            <span class="hint">16 caratteri per persona fisica, 11 cifre per azienda/P.IVA.</span>

            <div class="row">
                <div style="flex:2;"><label>Cognome / Ragione Sociale *</label><input type="text" name="cognome" required value="<?= Security::h($soggetto['cognome']) ?>"></div>
                <div style="flex:1.5;"><label>Nome</label><input type="text" name="nome" value="<?= Security::h($soggetto['nome']) ?>"></div>
                <div style="flex:0.7;">
                    <label>Sesso</label>
                    <select name="sesso">
                        <option value="">—</option>
                        <option value="M" <?= $soggetto['sesso'] === 'M' ? 'selected' : '' ?>>M</option>
                        <option value="F" <?= $soggetto['sesso'] === 'F' ? 'selected' : '' ?>>F</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div><label>Data nascita</label><input type="date" name="data_nascita" value="<?= Security::h($soggetto['data_nascita']) ?>" max="<?= date('Y-m-d') ?>"></div>
                <div><label>Comune nascita</label><input type="text" name="comune_nascita" value="<?= Security::h($soggetto['comune_nascita']) ?>"></div>
                <div style="flex:0.6;"><label>Prov.</label><input type="text" name="prov_nascita" maxlength="2" value="<?= Security::h($soggetto['prov_nascita']) ?>"></div>
            </div>

            <div class="row">
                <div><label>Email</label><input type="email" name="email" value="<?= Security::h($soggetto['email']) ?>"></div>
                <div><label>PEC</label><input type="email" name="pec" value="<?= Security::h($soggetto['pec'] ?? '') ?>" placeholder="es. studio@pec.it"></div>
                <div><label>Telefono</label><input type="text" name="telefono" value="<?= Security::h($soggetto['telefono']) ?>"></div>
            </div>

            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" name="soggettivita_iva" value="1" style="width:auto; margin:0;" <?= !empty($soggetto['soggettivita_iva']) ? 'checked' : '' ?>>
                Soggettività IVA
            </label>

            <details style="margin-top:10px;">
                <summary style="cursor:pointer; color:#4299e1; font-weight:600;">Rappresentante legale (per persone giuridiche)</summary>
                <div style="margin-top:10px; padding:12px; background:#f7fafc; border-radius:8px;">
                    <label>Legale rappresentante (persona fisica già censita)</label>
                    <select name="id_rappresentante" id="id_rappresentante">
                        <option value="">— Nessuno / compila manualmente —</option>
                        <?php foreach ($personeFisiche as $pf): ?>
                            <option value="<?= (int)$pf['id'] ?>"
                                    data-cf="<?= Security::h($pf['codice_fiscale']) ?>"
                                    data-cognome="<?= Security::h($pf['cognome']) ?>"
                                    data-nome="<?= Security::h($pf['nome']) ?>"
                                    <?= (int)($soggetto['id_rappresentante'] ?? 0) === (int)$pf['id'] ? 'selected' : '' ?>>
                                <?= Security::h($pf['cognome'] . ' ' . $pf['nome'] . ' — ' . $pf['codice_fiscale']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>CF Rappresentante</label><input type="text" name="cf_rappresentante" maxlength="16" value="<?= Security::h($soggetto['cf_rappresentante'] ?? '') ?>">
                    <div class="row">
                        <div><label>Cognome</label><input type="text" name="cognome_rappresentante" value="<?= Security::h($soggetto['cognome_rappresentante'] ?? '') ?>"></div>
                        <div><label>Nome</label><input type="text" name="nome_rappresentante" value="<?= Security::h($soggetto['nome_rappresentante'] ?? '') ?>"></div>
                    </div>
                    <label>Codice Carica</label>
                    <select name="codice_carica">
                        <option value="">—</option>
                        <?php foreach (['1'=>'1 - Rappresentante legale','2'=>'2 - Minore/inabilitato','3'=>'3 - Curatore fallimentare','4'=>'4 - Commissario liquidatore','7'=>'7 - Erede','8'=>'8 - Liquidatore','13'=>'13 - Amministratore condominio','14'=>'14 - Pubblica amministrazione'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (string)($soggetto['codice_carica'] ?? '') === (string)$v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </details>

            <label style="margin-top:10px;">Note interne</label>
            <textarea name="note" rows="2"><?= Security::h($soggetto['note']) ?></textarea>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" class="btn btn-green">💾 Salva modifiche</button>
                <a href="<?= url('soggetti.php') ?>" class="btn btn-gray">Annulla</a>
            </div>
        </form>
    </div>
</div>

<script>
// Autofill del legale rappresentante dalla persona fisica selezionata
const selRapp = document.getElementById('id_rappresentante');
if (selRapp) {
    selRapp.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!this.value) return;
        const cfR = document.querySelector('[name="cf_rappresentante"]');
        const cogR = document.querySelector('[name="cognome_rappresentante"]');
        const nomR = document.querySelector('[name="nome_rappresentante"]');
        const carica = document.querySelector('[name="codice_carica"]');
        if (cfR) cfR.value = opt.dataset.cf || '';
        if (cogR) cogR.value = opt.dataset.cognome || '';
        if (nomR) nomR.value = opt.dataset.nome || '';
        if (carica) carica.value = '1';
    });
}
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
