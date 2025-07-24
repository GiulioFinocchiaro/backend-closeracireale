<?php
namespace Helpers;

// Carica TUTTE le dipendenze di Composer, inclusa PHPMailer.
// Questo è il modo corretto e unico per includere le classi quando usi Composer.
require_once __DIR__ . "/../../vendor/autoload.php";

use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

// IMPORTANTE: Rimuovi la riga successiva che include PHPMailer manualmente,
// altrimenti potresti avere problemi di "Class not found" o altri conflitti.
// require_once __DIR__ . "/../../vendor/PHPMailer/PHPMailer/src/PHPMailer.php"; // <-- RIMUOVI QUESTA RIGA!

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

class Mail {
    public static function sendMail($to, $subject, $body, $altBody = '') {
        if (!isset($_ENV['MAIL_HOST'])) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->load();
        }

        $mail = new PHPMailer(true);
        $cssToInlineStyles = new CssToInlineStyles();
        $body = $cssToInlineStyles->convert($body);

        try {
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['MAIL_USERNAME'];
            $mail->Password   = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = $_ENV['MAIL_PORT'];

            $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = $altBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Errore invio email: {$mail->ErrorInfo}");
            return false;
        }
    }
}