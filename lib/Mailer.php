<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Crypto.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

class Mailer
{
    /**
     * Invia un'email tramite SMTP.
     * Se $idUtente è passato, usa le credenziali SMTP di quell'utente (se configurate).
     */
    public static function invia(string $oggetto, string $corpo, array $destinatari, array $allegati = [], ?int $idUtente = null): bool
    {
        global $pdo; // Usa la connessione PDO globale

        // 1. Carica i default da config.php
        $smtpHost = defined('RLI_SMTP_HOST') ? RLI_SMTP_HOST : '';
        $smtpPort = defined('RLI_SMTP_PORT') ? (int)RLI_SMTP_PORT : 587;
        $smtpUser = defined('RLI_SMTP_USER') ? RLI_SMTP_USER : '';
        $smtpPass = defined('RLI_SMTP_PASS') ? RLI_SMTP_PASS : '';
        $smtpFrom = defined('RLI_SMTP_FROM') ? RLI_SMTP_FROM : 'noreply@rli.local';
        $smtpFromName = defined('RLI_SMTP_FROM_NAME') ? RLI_SMTP_FROM_NAME : 'RLI Manager';

        // 2. Se c'è un utente, sovrascrivi con le sue credenziali (se presenti)
        if ($idUtente && isset($pdo)) {
            $stmt = $pdo->prepare("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, smtp_from_name FROM utenti WHERE id = ?");
            $stmt->execute([$idUtente]);
            $userSmtp = $stmt->fetch();

            if ($userSmtp && !empty($userSmtp['smtp_host'])) {
                $smtpHost = $userSmtp['smtp_host'];
                $smtpPort = (int)($userSmtp['smtp_port'] ?: 587);
                $smtpUser = $userSmtp['smtp_user'];
                $smtpPass = $userSmtp['smtp_pass'];
                
                // Decripta la password SMTP (nuovo formato AES-256-GCM, fallback legacy/plaintext)
                if ($smtpPass !== '') {
                    $smtpPass = Crypto::decryptLegacy($smtpPass);
                }
                
                $smtpFrom = $userSmtp['smtp_from'] ?: $userSmtp['smtp_user'];
                $smtpFromName = $userSmtp['smtp_from_name'] ?: 'RLI Manager';
            }
        }

        // 3. Se l'host è ancora vuoto, interrompi
        if (empty($smtpHost)) {
            return false;
        }

        // 4. Filtra destinatari validi
        $validi = array_values(array_filter($destinatari, function($e) {
            return filter_var($e, FILTER_VALIDATE_EMAIL);
        }));

        if (empty($validi)) {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = !empty($smtpUser);
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            
            if ($smtpPort == 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpPort == 25 || $smtpPort == 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
            }
            
            $mail->Port       = $smtpPort;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            $mail->setFrom($smtpFrom, $smtpFromName);

            foreach ($validi as $email) {
                $mail->addAddress($email);
            }

            foreach ($allegati as $a) {
                if (!empty($a['path']) && is_file($a['path'])) {
                    $mail->addAttachment($a['path'], $a['name'] ?? basename($a['path']));
                }
            }

            $mail->isHTML(false);
            $mail->Subject = $oggetto;
            $mail->Body    = $corpo;

            $mail->send();
            return true;

        } catch (MailException $e) {
            error_log("Mailer SMTP Error: " . $mail->ErrorInfo);
            return false;
        } catch (Throwable $e) {
            error_log("Mailer Error: " . $e->getMessage());
            return false;
        }
    }
}
