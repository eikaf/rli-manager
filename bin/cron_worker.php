<?php
/**
 * bin/cron_worker.php — Worker CLI idempotente per scadenze e coda email.
 *
 * Uso:
 *   php bin/cron_worker.php [--task=scadenze|email|all]
 *
 * Esempio crontab:
 *   *\/15 * * * * cd /var/www/html/RLI && php bin/cron_worker.php --task=email >> logs/cron_worker.log 2>&1
 *   0 6 * * *    cd /var/www/html/RLI && php bin/cron_worker.php --task=scadenze >> logs/cron_worker.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Evita il controllo manutenzione (pensato per il web) durante il bootstrap CLI.
define('RLI_SKIP_MAINTENANCE', true);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/ComunicazioneRepository.php';

$task = 'all';
foreach ($argv as $arg) {
    if (strpos($arg, '--task=') === 0) {
        $task = substr($arg, 7);
    }
}
if (!in_array($task, ['scadenze', 'email', 'all'], true)) {
    fwrite(STDERR, "Task non valido. Uso: --task=scadenze|email|all\n");
    exit(2);
}

$inizio = microtime(true);
echo "=== cron_worker ({$task}) — " . date('Y-m-d H:i:s') . " ===\n";

$exitCode = 0;

if ($task === 'scadenze' || $task === 'all') {
    try {
        $create = taskScadenze($pdo);
        echo "Scadenze: notifiche create: {$create}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERRORE scadenze: " . $e->getMessage() . "\n");
        $exitCode = 1;
    }
}

if ($task === 'email' || $task === 'all') {
    try {
        $res = taskEmail($pdo);
        echo "Email: inviate={$res[0]}, errori={$res[1]}\n";
        if ($res[1] > 0) {
            $exitCode = 1;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "ERRORE email: " . $e->getMessage() . "\n");
        $exitCode = 1;
    }
}

try {
    Logger::azione('cron_worker', 'sistema', null, [
        'task'      => $task,
        'durata_ms' => (int)((microtime(true) - $inizio) * 1000),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "WARN log: " . $e->getMessage() . "\n");
}

echo "=== Completato in " . round((microtime(true) - $inizio) * 1000) . " ms ===\n";
exit($exitCode);

// ---------------------------------------------------------------------------
// Task: scadenze / notifiche
// ---------------------------------------------------------------------------

function inserisciNotifica(
    PDO $pdo,
    string $tipo,
    int $idContratto,
    ?string $dataScadenza,
    string $messaggio,
    string $priorita,
    string $chiave
): bool {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notifiche
            (tipo, id_contratto, data_scadenza, messaggio, priorita, origine, chiave_univoca, letta)
        VALUES (?, ?, ?, ?, ?, 'cron', ?, 0)
    ");
    $stmt->execute([$tipo, $idContratto, $dataScadenza, $messaggio, $priorita, $chiave]);
    return $stmt->rowCount() > 0;
}

function taskScadenze(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT id, id_contratto_univoco, durata_al, durata_dal, data_stipula, annualita, stato
        FROM contratti
        WHERE stato IN ('ATTIVO','PROROGATO')
          AND (
                (durata_al IS NOT NULL AND durata_al <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))
             OR (annualita IS NOT NULL AND annualita <> '')
          )
    ");
    $contratti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $create = 0;
    $oggi = strtotime(date('Y-m-d'));

    foreach ($contratti as $c) {
        $idC  = (int)$c['id'];
        $nome = '#' . $idC . (!empty($c['id_contratto_univoco']) ? ' (' . $c['id_contratto_univoco'] . ')' : '');

        // Scadenza contrattuale (durata_al)
        if (!empty($c['durata_al'])) {
            $gg = (int)floor((strtotime($c['durata_al']) - $oggi) / 86400);
            if ($gg >= 0 && $gg <= 60) {
                if ($gg <= 15)      { $soglia = 15; $prio = 'critical'; }
                elseif ($gg <= 30)  { $soglia = 30; $prio = 'warning'; }
                else                { $soglia = 60; $prio = 'info'; }

                $chiave = "scad_contr_{$idC}_{$c['durata_al']}_{$soglia}";
                $msg = "Contratto {$nome} in scadenza tra {$gg} giorni (" .
                       date('d/m/Y', strtotime($c['durata_al'])) . ").";

                if (inserisciNotifica($pdo, 'scadenza', $idC, $c['durata_al'], $msg, $prio, $chiave)) {
                    $create++;
                }
            }
        }

        // Scadenza annualità successiva: anniversario di durata_dal/data_stipula nell'anno 'annualita'
        $annualita = (int)($c['annualita'] ?? 0);
        $base = !empty($c['durata_dal']) ? $c['durata_dal'] : ($c['data_stipula'] ?? null);
        if ($annualita >= 1800 && $annualita <= 2099 && !empty($base)) {
            $tsBase = strtotime($base);
            if ($tsBase) {
                $deadline = sprintf('%04d-%02d-%02d', $annualita, (int)date('m', $tsBase), (int)date('d', $tsBase));
                $ggA = (int)floor((strtotime($deadline) - $oggi) / 86400);
                if ($ggA >= 0 && $ggA <= 60) {
                    if ($ggA <= 15)      { $soglia = 15; $prio = 'critical'; }
                    elseif ($ggA <= 30)  { $soglia = 30; $prio = 'warning'; }
                    else                 { $soglia = 60; $prio = 'info'; }

                    $chiave = "scad_ann_{$idC}_{$annualita}_{$soglia}";
                    $msg = "Annualità {$annualita} del contratto {$nome}: scadenza tra {$ggA} giorni (" .
                           date('d/m/Y', strtotime($deadline)) . ").";

                    if (inserisciNotifica($pdo, 'annualita', $idC, $deadline, $msg, $prio, $chiave)) {
                        $create++;
                    }
                }
            }
        }
    }

    return $create;
}

// ---------------------------------------------------------------------------
// Task: coda email (comunicazioni.inviata = 0)
// ---------------------------------------------------------------------------

function taskEmail(PDO $pdo): array
{
    $ok = 0;
    $ko = 0;

    $stmt = $pdo->query("
        SELECT id, id_contratto, id_utente, oggetto, corpo, destinatari_json, allegati_json
        FROM comunicazioni
        WHERE inviata = 0
        ORDER BY id ASC
        LIMIT 100
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $comRepo = new ComunicazioneRepository($pdo);

    foreach ($rows as $row) {
        $id = (int)$row['id'];

        $destinatari = json_decode($row['destinatari_json'] ?? '[]', true);
        if (!is_array($destinatari)) {
            $destinatari = [];
        }
        $destinatari = array_values(array_filter(array_map('trim', $destinatari), static function ($e) {
            return filter_var($e, FILTER_VALIDATE_EMAIL);
        }));

        $allegati = json_decode($row['allegati_json'] ?? '[]', true);
        if (!is_array($allegati)) {
            $allegati = [];
        }
        $allegatiMail = [];
        foreach ($allegati as $a) {
            if (is_array($a) && !empty($a['path']) && is_file($a['path'])) {
                $allegatiMail[] = [
                    'path' => $a['path'],
                    'name' => $a['name'] ?? basename($a['path']),
                ];
            }
        }

        if (empty($destinatari)) {
            $pdo->prepare("UPDATE comunicazioni SET errore_invio = ? WHERE id = ?")
                ->execute(['Nessun destinatario email valido', $id]);
            $ko++;
            continue;
        }

        try {
            // Risolve i segnaposto {…} di oggetto e corpo usando i dati del contratto.
            $oggetto = (string)($row['oggetto'] ?? '');
            $corpo   = (string)($row['corpo'] ?? '');
            $idContratto = (int)($row['id_contratto'] ?? 0);
            if ($idContratto > 0) {
                $oggetto = $comRepo->renderTemplate($oggetto, $idContratto);
                $corpo   = $comRepo->renderTemplate($corpo, $idContratto);
            }

            $inviata = Mailer::invia(
                $oggetto,
                $corpo,
                $destinatari,
                $allegatiMail,
                ($row['id_utente'] !== null ? (int)$row['id_utente'] : null)
            );

            if ($inviata) {
                $pdo->prepare("UPDATE comunicazioni SET inviata = 1, data_invio = NOW(), errore_invio = NULL WHERE id = ?")
                    ->execute([$id]);
                $ok++;
            } else {
                $pdo->prepare("UPDATE comunicazioni SET errore_invio = ? WHERE id = ?")
                    ->execute(['Invio SMTP fallito (vedi log server)', $id]);
                $ko++;
            }
        } catch (Throwable $e) {
            // Salva l'errore SENZA interrompere i messaggi successivi.
            $pdo->prepare("UPDATE comunicazioni SET errore_invio = ? WHERE id = ?")
                ->execute([substr('Eccezione SMTP: ' . $e->getMessage(), 0, 1000), $id]);
            $ko++;
        }
    }

    return [$ok, $ko];
}