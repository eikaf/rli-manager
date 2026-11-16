<?php
ini_set('display_errors', 0);
error_reporting(0);
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!$auth->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta']);
    exit;
}

$idContratto = (int)($_GET['id_contratto'] ?? 0);
if ($idContratto <= 0 || $auth->permessoContratto($idContratto) === null) {
    echo json_encode(['ok' => false, 'error' => 'Accesso negato o ID non valido']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.email
    FROM contratto_locatori cl
    JOIN soggetti s ON s.id = cl.id_locatore
    WHERE cl.id_contratto = ? AND s.email IS NOT NULL AND s.email <> ''
");
$stmt->execute([$idContratto]);
$emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['ok' => true, 'emails' => array_values(array_unique($emails))]);
