<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';
require_once 'lib/NotificaRepository.php';

$auth->requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') Security::requireCsrf();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
$error = '';

// --- Salvataggio soggetto ---
if (isset($_POST['save_soggetto'])) {
    try {
        $cf = Validators::sanificaCF($_POST['cf'] ?? '');
        if ($cf === '') {
            throw new Exception('Il codice fiscale è obbligatorio.');
        }
        if (!Validators::cf($cf)) {
            throw new Exception('Codice fiscale non valido (struttura o carattere di controllo).');
        }

        // Controllo esplicito di unicità prima dell'INSERT
        $chk = $pdo->prepare("SELECT id FROM soggetti WHERE codice_fiscale = ? LIMIT 1");
        $chk->execute([$cf]);
        if ($chk->fetchColumn()) {
            throw new Exception('Esiste già un soggetto con questo codice fiscale.');
        }

        $tipo_cond = ($_POST['tipologia_conduttore'] === '') ? null : $_POST['tipologia_conduttore'];
        $sesso = ($_POST['sesso'] === '') ? null : strtoupper($_POST['sesso']);
        $data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;
        $tipo_default = $_POST['tipologia_default'] ?? 'ENTRAMBI';

        $pec = trim($_POST['pec'] ?? '');
        if ($pec !== '' && !filter_var($pec, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('PEC non valida.');
        }

        $idRappresentante = !empty($_POST['id_rappresentante']) ? (int)$_POST['id_rappresentante'] : null;

        $cf_rapp = null; $cogn_rapp = null; $nome_rapp = null; $carica = null;
        if (strlen($cf) === 11 && ctype_digit($cf)) {
            $cf_rapp = Validators::sanificaCF($_POST['cf_rappresentante'] ?? '');
            $cogn_rapp = strtoupper(trim($_POST['cognome_rappresentante'] ?? ''));
            $nome_rapp = strtoupper(trim($_POST['nome_rappresentante'] ?? ''));
            $carica = $_POST['codice_carica'] ?? null;
        }

        $params = [
            $cf, strtoupper($_POST['cognome']), strtoupper($_POST['nome'] ?? ''),
            $sesso, $data_nascita,
            strtoupper(trim($_POST['comune_nascita'] ?? '')),
            strtoupper(trim($_POST['prov_nascita'] ?? '')),
            trim($_POST['email'] ?? ''), $pec, trim($_POST['telefono'] ?? ''),
            $tipo_default, $tipo_cond,
            !empty($_POST['soggettivita_iva']) ? 1 : 0,
            $cf_rapp, $cogn_rapp, $nome_rapp, $carica, $idRappresentante,
            trim($_POST['note'] ?? ''),
        ];

        $pdo->prepare("
            INSERT INTO soggetti (
                codice_fiscale, cognome, nome, sesso, data_nascita,
                comune_nascita, prov_nascita, email, pec, telefono,
                tipologia_default, tipologia_conduttore, soggettivita_iva,
                cf_rappresentante, cognome_rappresentante, nome_rappresentante, codice_carica, id_rappresentante,
                note
            ) VALUES (" . rtrim(str_repeat('?,', 19), ',') . ")
        ")->execute($params);

        $newId = (int)$pdo->lastInsertId();
        Logger::azione('soggetto_create', 'soggetto', $newId);
        header("Location: soggetti.php?msg=Soggetto+salvato");
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
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuovo Soggetto — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .auto-filled { background-color: #f0fff4 !important; border-color: #68d391 !important; }
        .hint { color: #718096; font-size: 0.8em; margin-top: -8px; margin-bottom: 10px; display: block; }
        .cf-feedback { padding: 8px 12px; border-radius: 6px; font-size: 0.85em; margin-top: -4px; margin-bottom: 12px; display: none; }
        .cf-feedback.ok { background: #c6f6d5; color: #22543d; display: block; }
        .cf-feedback.warn { background: #feebc8; color: #7b341e; display: block; }
        .cf-feedback.err { background: #fed7d7; color: #742a2a; display: block; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <div style="margin-bottom: 15px;">
        <a href="<?= url('soggetti.php') ?>" class="btn btn-gray">← Torna ai soggetti</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error">⚠ <?= Security::h($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>Nuovo soggetto</h2>
        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>

            <div class="row">
                <div>
                    <label>Tipologia *</label>
                    <select name="tipologia_default" required>
                        <option value="ENTRAMBI">Entrambi</option>
                        <option value="LOCATORE">Solo Locatore</option>
                        <option value="CONDUTTORE">Solo Conduttore</option>
                    </select>
                </div>
                <div>
                    <label>Ruolo conduttore (se applicabile)</label>
                    <select name="tipologia_conduttore">
                        <option value="">— Non conduttore —</option>
                        <option value="3">3 - Generico</option>
                        <option value="1">1 - Coop/Studenti</option>
                        <option value="2">2 - CF Provvisorio</option>
                    </select>
                </div>
            </div>

            <label>Codice Fiscale / P.IVA *</label>
            <input type="text" name="cf" id="cf_field" required maxlength="16"
                   placeholder="16 caratteri per persona fisica, 11 cifre per azienda">
            <span class="hint">Persona fisica: 16 caratteri. Azienda/P.IVA: 11 cifre. Il sistema compila automaticamente sesso, data e comune di nascita.</span>
            <div id="cf_feedback" class="cf-feedback"></div>

            <div class="row">
                <div style="flex:2;"><label>Cognome / Ragione Sociale *</label><input type="text" name="cognome" required></div>
                <div style="flex:1.5;"><label>Nome</label><input type="text" name="nome"></div>
                <div style="flex:0.7;">
                    <label>Sesso</label>
                    <select name="sesso" id="sesso_field">
                        <option value="">—</option>
                        <option value="M">M</option>
                        <option value="F">F</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Data nascita</label>
                    <input type="date" name="data_nascita" id="birth_field" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label>Comune nascita</label>
                    <input type="text" name="comune_nascita" id="comune_nascita_field" list="lista_comuni_nascita" placeholder="Es. ROMA">
                    <datalist id="lista_comuni_nascita"></datalist>
                </div>
                <div style="flex:0.6;">
                    <label>Prov.</label>
                    <input type="text" name="prov_nascita" id="prov_nascita_field" maxlength="2" placeholder="RM">
                </div>
            </div>

            <div class="row">
                <div><label>Email</label><input type="email" name="email"></div>
                <div><label>PEC</label><input type="email" name="pec" placeholder="es. studio@pec.it"></div>
                <div><label>Telefono</label><input type="text" name="telefono"></div>
            </div>

            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="soggettivita_iva" value="1" style="width:auto; margin:0;">
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
                                    data-nome="<?= Security::h($pf['nome']) ?>">
                                <?= Security::h($pf['cognome'] . ' ' . $pf['nome'] . ' — ' . $pf['codice_fiscale']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>CF Rappresentante</label><input type="text" name="cf_rappresentante" maxlength="16">
                    <div class="row">
                        <div><label>Cognome</label><input type="text" name="cognome_rappresentante"></div>
                        <div><label>Nome</label><input type="text" name="nome_rappresentante"></div>
                    </div>
                    <label>Codice Carica</label>
                    <select name="codice_carica">
                        <option value="">—</option>
                        <option value="1">1 - Rappresentante legale</option>
                        <option value="2">2 - Minore/inabilitato</option>
                        <option value="3">3 - Curatore fallimentare</option>
                        <option value="4">4 - Commissario liquidatore</option>
                        <option value="7">7 - Erede</option>
                        <option value="8">8 - Liquidatore</option>
                        <option value="13">13 - Amministratore condominio</option>
                        <option value="14">14 - Pubblica amministrazione</option>
                    </select>
                </div>
            </details>

            <label style="margin-top:10px;">Note interne</label>
            <textarea name="note" rows="2"></textarea>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" name="save_soggetto" class="btn btn-green">💾 Salva Soggetto</button>
                <a href="<?= url('soggetti.php') ?>" class="btn btn-gray">Annulla</a>
            </div>
        </form>
    </div>
</div>

<script src="assets/cf_utils.js"></script>
<script>
CFUtils.attach(document.getElementById('cf_field'), {
    feedbackEl: document.getElementById('cf_feedback'),
    fields: {
        sesso: document.getElementById('sesso_field'),
        data_nascita: document.getElementById('birth_field'),
        comune_nascita: document.getElementById('comune_nascita_field'),
        prov_nascita: document.getElementById('prov_nascita_field'),
    },
    autoFill: true
});
CFUtils.attachComuneAutocomplete(
    document.getElementById('comune_nascita_field'),
    document.getElementById('prov_nascita_field'),
    'lista_comuni_nascita'
);

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
