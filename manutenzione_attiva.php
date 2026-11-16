<?php
session_start();
include 'db.php';
require_once 'config.php';

// Se non è in manutenzione, reindirizza alla home
if (!isManutenzioneAttiva($pdo)) {
    header('Location: index.php');
    exit;
}

// Se è admin, può accedere
if ($auth->isLoggedIn() && $auth->isAdmin()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Manutenzione in corso — RLI Manager</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1a202c; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: #e2e8f0; }
        .box { background: #2d3748; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; max-width: 500px; }
        .box .icon { font-size: 4em; margin-bottom: 20px; }
        .box h1 { margin: 0 0 10px 0; font-size: 1.5em; }
        .box p { color: #a0aec0; line-height: 1.6; margin: 10px 0; }
        .box a { color: #4299e1; text-decoration: none; }
        .box a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">🔧</div>
        <h1>Manutenzione in corso</h1>
        <p>Il portale è temporaneamente in manutenzione per miglioramenti. Torna a trovarci tra poco!</p>
        <p style="margin-top: 30px;">
            <a href="index.php">Torna alla home</a> · 
            <a href="index.php?logout=1">Logout</a>
        </p>
    </div>
</body>
</html>
