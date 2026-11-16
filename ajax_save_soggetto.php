<?php
/**
 * ajax_save_soggetto.php
 * Endpoint AJAX per creare/modificare un soggetto "al volo" da qualsiasi pagina.
 * Ritorna JSON: { ok: true, id: N, soggetto: {...} } oppure { ok: false, error: "..." }
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['utente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';

// CSRF
if (!Security::validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido']);
    exit;
}

try {
    $isUpdate = !empty($_POST['id_soggetto']);
    $cf = Validators::sanificaCF($_POST['cf'] ?? '');

    if ($cf === '') {
        throw new Exception('Codice fiscale obbligatorio');
    }
    if (!Validators::cf($cf)) {
        throw new Exception('CF non valido');
    }

    $cognome = strtoupper(trim($_POST['cognome'] ?? ''));
    if ($cognome === '') {
        throw new Exception('Cognome/Ragione sociale obbligatorio');
    }

    $tipo_cond = ($_POST['tipologia_conduttore'] === '' || !isset($_POST['tipologia_conduttore'])) ? null : $_POST['tipologia_conduttore'];
    $sesso = (empty($_POST['sesso'])) ? null : strtoupper($_POST['sesso']);
    $data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;
    $tipo_default = $_POST['tipologia_default'] ?? 'ENTRAMBI';

    $pec = trim($_POST['pec'] ?? '');
    if ($pec !== '' && !filter_var($pec, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('PEC non valida');
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
        $cf,
        $cognome,
        strtoupper(trim($_POST['nome'] ?? '')),
        $sesso,
        $data_nascita,
        strtoupper(trim($_POST['comune_nascita'] ?? '')),
        strtoupper(trim($_POST['prov_nascita'] ?? '')),
        trim($_POST['email'] ?? ''),
        $pec,
        trim($_POST['telefono'] ?? ''),
        $tipo_default,
        $tipo_cond,
        !empty($_POST['soggettivita_iva']) ? 1 : 0,
        $cf_rapp,
        $cogn_rapp,
        $nome_rapp,
        $carica,
        $idRappresentante,
        trim($_POST['note'] ?? ''),
    ];

    if ($isUpdate) {
        $id = (int)$_POST['id_soggetto'];
        $params[] = $id;

        // Verifica che non ci sia un ALTRO soggetto con lo stesso CF
        $chk = $pdo->prepare("SELECT id FROM soggetti WHERE codice_fiscale = ? AND id != ?");
        $chk->execute([$cf, $id]);
        if ($chk->fetchColumn()) {
            throw new Exception('Esiste già un altro soggetto con questo CF');
        }

        $pdo->prepare("
            UPDATE soggetti SET
                codice_fiscale=?, cognome=?, nome=?, sesso=?, data_nascita=?,
                comune_nascita=?, prov_nascita=?, email=?, pec=?, telefono=?,
                tipologia_default=?, tipologia_conduttore=?, soggettivita_iva=?,
                cf_rappresentante=?, cognome_rappresentante=?, nome_rappresentante=?, codice_carica=?, id_rappresentante=?,
                note=?
            WHERE id=?
        ")->execute($params);

        Logger::azione('soggetto_update_ajax', 'soggetto', $id);
    } else {
        // Verifica duplicato CF
        $chk = $pdo->prepare("SELECT id FROM soggetti WHERE codice_fiscale = ?");
        $chk->execute([$cf]);
        if ($chk->fetchColumn()) {
            throw new Exception('Esiste già un soggetto con questo CF');
        }

        $pdo->prepare("
            INSERT INTO soggetti (
                codice_fiscale, cognome, nome, sesso, data_nascita,
                comune_nascita, prov_nascita, email, pec, telefono,
                tipologia_default, tipologia_conduttore, soggettivita_iva,
                cf_rappresentante, cognome_rappresentante, nome_rappresentante, codice_carica, id_rappresentante,
                note
            ) VALUES (" . rtrim(str_repeat('?,', 19), ',') . ")
        ")->execute($params);

        $id = (int)$pdo->lastInsertId();
        Logger::azione('soggetto_create_ajax', 'soggetto', $id);
    }

    // Ritorna il soggetto salvato
    $stmt = $pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
    $stmt->execute([$id]);
    $soggetto = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'soggetto' => $soggetto,
        'action' => $isUpdate ? 'update' : 'create',
    ]);

} catch (Throwable $e) {
    Logger::errore('soggetto_save_ajax', $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
