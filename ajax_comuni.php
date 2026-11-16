<?php
/**
 * ajax_comuni.php
 * Lookup locale Comune -> Provincia/EE + Codice Catastale (Belfiore).
 *
 * GET: ?q=... (minimo 2 caratteri)
 * Ritorna: { ok: true, results: [{codice_belfiore, nome, provincia}], fallback: bool }
 * Se la tabella comuni è vuota, fallback=true (il client può usare il JSON esterno).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include 'db.php';
require_once 'config.php';

if (empty($_SESSION['utente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => [], 'fallback' => false]);
    exit;
}

try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM comuni")->fetchColumn();
    if ($count === 0) {
        echo json_encode(['ok' => true, 'results' => [], 'fallback' => true]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT codice_belfiore, nome, provincia
        FROM comuni
        WHERE soppresso = 0 AND nome LIKE ?
        ORDER BY nome
        LIMIT 20
    ");
    $stmt->execute([$q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $stmt->execute(['%' . $q . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['ok' => true, 'results' => $rows, 'fallback' => false]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
