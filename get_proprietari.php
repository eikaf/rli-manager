<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include 'db.php';
require_once 'config.php';

if (!$auth->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

$idContratto = (int)($_GET['id_contratto'] ?? 0);
if ($idContratto <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID contratto mancante']);
    exit;
}

try {
    // Trova gli immobili del contratto
    $stmt = $pdo->prepare("SELECT id_immobile FROM contratto_immobili WHERE id_contratto = ?");
    $stmt->execute([$idContratto]);
    $immobili = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($immobili)) {
        echo json_encode(['ok' => true, 'ids' => []]);
        exit;
    }

    // Trova i proprietari
    $inQuery = implode(',', array_fill(0, count($immobili), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT id_locatore FROM proprieta_immobili WHERE id_immobile IN ($inQuery)");
    $stmt->execute($immobili);
    $idsProprietari = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['ok' => true, 'ids' => $idsProprietari]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
