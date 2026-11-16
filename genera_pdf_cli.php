<?php
// Forza l'output di errori per debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') die("CLI only");

$idContratto = (int)($argv[1] ?? 0);
$outputFile = $argv[2] ?? '';

if ($idContratto <= 0 || empty($outputFile)) {
    die("Uso: php genera_pdf_cli.php <id_contratto> <file_output>");
}

// Simuliamo un ambiente web per i controlli di config
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['utente_id'] = 1;
$_SESSION['utente_ruolo'] = 'admin';

// Includiamo il file principale che genera il PDF
// Sovrascriviamo la variabile per far scrivere su file invece che nel browser
$GLOBALS['cli_output_file'] = $outputFile;

$_POST['id_contratto'] = $idContratto;
$_REQUEST['id_contratto'] = $idContratto;

chdir(__DIR__);
require 'genera_modello_pdf.php';
exit(0);
