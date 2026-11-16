<?php
/**
 * ajax_save_immobile.php
 * Endpoint AJAX per creare un immobile "al volo" dal wizard contratto.
 * Se id_locatore è valorizzato, associa l'immobile al locatore (proprieta_immobili).
 *
 * Ritorna JSON: { ok: true, id: N, immobile: {...} } oppure { ok: false, error: "..." }
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

if (!Security::validateCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido']);
    exit;
}

try {
    $identificativo = trim($_POST['identificativo_interno'] ?? '');
    if ($identificativo === '') {
        throw new Exception('Identificativo interno obbligatorio');
    }

    $comune = strtoupper(trim($_POST['comune'] ?? ''));
    if ($comune === '') {
        throw new Exception('Comune obbligatorio');
    }

    $provincia = strtoupper(trim($_POST['provincia'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $provincia)) {
        throw new Exception("Provincia obbligatoria (sigla di 2 lettere, es. RM, oppure 'EE' per l'estero)");
    }

    $toponimo = strtoupper(trim($_POST['toponimo'] ?? ''));
    if ($toponimo === '') {
        throw new Exception('Toponimo obbligatorio (es. VIA, PIAZZA, CORSO)');
    }

    $categoria = strtoupper(trim($_POST['categoria'] ?? ''));
    $isPertinenza = !empty($_POST['is_pertinenza']) ? 1 : 0;

    $params = [
        $identificativo,
        strtoupper(trim($_POST['codice_comune'] ?? '')),
        $provincia,
        strtoupper(trim($_POST['sezione'] ?? '')),
        ($_POST['tipo_catasto'] ?? 'U') === 'T' ? 'T' : 'U',
        $categoria,
        $isPertinenza,
        $comune,
        trim($_POST['foglio'] ?? ''),
        trim($_POST['particella'] ?? ''),
        trim($_POST['subalterno'] ?? ''),
        !empty($_POST['rendita']) ? str_replace(',', '.', $_POST['rendita']) : null,
        $toponimo,
        strtoupper(trim($_POST['indirizzo'] ?? '')),
        trim($_POST['civico'] ?? ''),
        trim($_POST['note'] ?? ''),
    ];

    $pdo->prepare("
        INSERT INTO immobili (
            identificativo_interno, codice_comune, provincia, sezione, tipo_catasto,
            categoria, is_pertinenza, comune, foglio, particella, subalterno,
            rendita, toponimo, indirizzo, civico, note
        ) VALUES (" . rtrim(str_repeat('?,', 16), ',') . ")
    ")->execute($params);

    $id = (int)$pdo->lastInsertId();

    // Associazione automatica al locatore selezionato
    $idLocatore = (int)($_POST['id_locatore'] ?? 0);
    if ($idLocatore > 0) {
        $chk = $pdo->prepare("SELECT id FROM proprieta_immobili WHERE id_locatore = ? AND id_immobile = ?");
        $chk->execute([$idLocatore, $id]);
        if (!$chk->fetchColumn()) {
            $pdo->prepare("INSERT INTO proprieta_immobili (id_locatore, id_immobile, percentuale) VALUES (?, ?, 100)")
                ->execute([$idLocatore, $id]);
        }
    }

    Logger::azione('immobile_create_ajax', 'immobile', $id);

    $stmt = $pdo->prepare("SELECT * FROM immobili WHERE id = ?");
    $stmt->execute([$id]);
    $immobile = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'immobile' => $immobile,
    ]);
} catch (Throwable $e) {
    Logger::errore('immobile_save_ajax', $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
