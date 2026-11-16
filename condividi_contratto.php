<?php
/**
 * condividi_contratto.php — Endpoint AJAX per gestire condivisioni contratto.
 *
 * Azioni:
 *   - GET ?id=X : ritorna lista condivisioni esistenti + lista utenti disponibili
 *   - POST con azione 'save' : salva/aggiorna condivisioni
 *   - POST con azione 'revoke' : revoca una condivisione specifica
 *
 * Permessi:
 *   - Solo il proprietario (id_utente_creatore) o l'admin possono condividere
 *
 * Risposta: JSON
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include 'db.php';
require_once 'config.php';

if (!$auth->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato.']);
    exit;
}

// CSRF per POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf()) {
        echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
        exit;
    }
}

$idContratto = (int)($_GET['id'] ?? $_POST['id_contratto'] ?? 0);
if ($idContratto <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID contratto mancante.']);
    exit;
}

// Verifica esistenza contratto
$stmt = $pdo->prepare("SELECT id, id_contratto_univoco, id_utente_creatore FROM contratti WHERE id = ?");
$stmt->execute([$idContratto]);
$contratto = $stmt->fetch();
if (!$contratto) {
    echo json_encode(['ok' => false, 'error' => 'Contratto non trovato.']);
    exit;
}

// Verifica permessi: solo il proprietario o admin
$isProprietario = ((int)$contratto['id_utente_creatore'] === $auth->id());
if (!$isProprietario && !$auth->isAdmin()) {
    echo json_encode(['ok' => false, 'error' => 'Solo il proprietario del contratto o un admin può gestire le condivisioni.']);
    exit;
}

// === AZIONE: GET (carica lista) ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Utenti attivi (escluso il proprietario e l'admin corrente se non è proprietario)
    $stmt = $pdo->prepare("
        SELECT id, username, nome, cognome, email, ruolo
        FROM utenti
        WHERE attivo = 1 AND id != ?
        ORDER BY cognome, nome
    ");
    $stmt->execute([$isProprietario ? $auth->id() : ($contratto['id_utente_creatore'] ?: 0)]);
    $utentiDisponibili = $stmt->fetchAll();

    // Condivisioni esistenti
    $stmt = $pdo->prepare("
        SELECT cc.*, u.username, u.nome, u.cognome, u.email
        FROM contratti_condivisioni cc
        JOIN utenti u ON u.id = cc.id_utente
        WHERE cc.id_contratto = ?
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([$idContratto]);
    $condivisioni = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'contratto' => [
            'id' => (int)$contratto['id'],
            'id_contratto_univoco' => $contratto['id_contratto_univoco'],
            'id_utente_creatore' => (int)$contratto['id_utente_creatore'],
        ],
        'utenti_disponibili' => $utentiDisponibili,
        'condivisioni' => $condivisioni,
        'is_proprietario' => $isProprietario,
        'is_admin' => $auth->isAdmin(),
    ]);
    exit;
}

// === AZIONE: POST SAVE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'save') {
    try {
        $utentiSelezionati = $_POST['utenti'] ?? []; // formato: { id_utente: 'view'|'edit' }

        $pdo->beginTransaction();

        // Carica condivisioni esistenti
        $stmt = $pdo->prepare("SELECT id_utente FROM contratti_condivisioni WHERE id_contratto = ?");
        $stmt->execute([$idContratto]);
        $esistenti = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $aggiunte = 0;
        $aggiornate = 0;
        $rimosse = 0;

        // Aggiungi/aggiorna
        foreach ($utentiSelezionati as $idUtente => $permesso) {
            $idUtente = (int)$idUtente;
            $permesso = in_array($permesso, ['view', 'edit'], true) ? $permesso : 'view';
            if ($idUtente <= 0) continue;

            if (in_array($idUtente, $esistenti, true)) {
                // Aggiorna
                $pdo->prepare("UPDATE contratti_condivisioni SET permesso = ? WHERE id_contratto = ? AND id_utente = ?")
                    ->execute([$permesso, $idContratto, $idUtente]);
                $aggiornate++;
            } else {
                // Inserisci
                $pdo->prepare("
                    INSERT INTO contratti_condivisioni (id_contratto, id_utente, permesso, condiviso_da)
                    VALUES (?, ?, ?, ?)
                ")->execute([$idContratto, $idUtente, $permesso, $auth->id()]);
                $aggiunte++;
            }
        }

        // Rimuovi quelle non più selezionate
        foreach ($esistenti as $idUtenteEsistente) {
            if (!isset($utentiSelezionati[$idUtenteEsistente]) && !isset($utentiSelezionati[(string)$idUtenteEsistente])) {
                $pdo->prepare("DELETE FROM contratti_condivisioni WHERE id_contratto = ? AND id_utente = ?")
                    ->execute([$idContratto, $idUtenteEsistente]);
                $rimosse++;
            }
        }

        $pdo->commit();

        Logger::azione('condivisione_save', 'contratto', $idContratto, [
            'aggiunte' => $aggiunte,
            'aggiornate' => $aggiornate,
            'rimosse' => $rimosse,
        ]);

        echo json_encode([
            'ok' => true,
            'aggiunte' => $aggiunte,
            'aggiornate' => $aggiornate,
            'rimosse' => $rimosse,
            'totale_attive' => count($utentiSelezionati),
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        Logger::errore('condivisione_save', $e->getMessage(), $idContratto);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// === AZIONE: POST REVOKE (rimuovi una singola condivisione) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'revoke') {
    try {
        $idUtente = (int)($_POST['id_utente'] ?? 0);
        if ($idUtente <= 0) throw new Exception('ID utente mancante.');

        $pdo->prepare("DELETE FROM contratti_condivisioni WHERE id_contratto = ? AND id_utente = ?")
            ->execute([$idContratto, $idUtente]);

        Logger::azione('condivisione_revoke', 'contratto', $idContratto, ['id_utente' => $idUtente]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Azione non riconosciuta
echo json_encode(['ok' => false, 'error' => 'Azione non riconosciuta.']);
