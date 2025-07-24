<?php
// Queste variabili verranno dal contesto del file che include questo template.
// Le assegnazioni qui servono solo come fallback se non sono già definite.
require_once __DIR__ . "/../../vendor/autoload.php";

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
$dotenv->load();

$support_email = $_ENV["MAIL_SUPPORT"];
$admin_name = $admin_name ?? 'Amministratore';
$update_date = $update_date ?? date('d/m/Y');
$update_time = $update_time ?? date('H:i');
$email = $email ?? 'email';
$name = $name ?? "utente";
$password = $password ?? 'password';


?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Aggiornamento Password del Tuo Account Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            font-family: 'Segoe UI', sans-serif; /* Mantenuto il font del template "Benvenuto" */
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
            width: 100% !important;
        }
        table {
            border-collapse: collapse;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        td {
            padding: 0;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }
        a {
            text-decoration: none;
            color: #003366; /* Colore link del template "Benvenuto" */
        }
        /* Stili specifici per l'email */
        .container {
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
        }
        .header {
            background-color: #003366; /* Blu scuro del template "Benvenuto" */
            color: #ffffff;
            padding: 30px;
            text-align: center;
            border-top-left-radius: 12px; /* Mantenuto il bordo arrotondato */
            border-top-right-radius: 12px;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600; /* Aggiunto per coerenza */
        }
        .content {
            padding: 30px;
            color: #333333;
            line-height: 1.6; /* Aggiunto per leggibilità */
            font-size: 15px; /* Aggiunto per leggibilità */
        }
        .content h2 {
            color: #003366;
            margin-top: 25px; /* Spazio sopra il titolo */
            margin-bottom: 15px; /* Spazio sotto il titolo */
        }
        .info-box {
            background-color: #f0f8ff; /* Colore credenziali del template "Benvenuto" */
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: monospace; /* Mantenuto il font monospace per le info tecniche */
            border-left: 4px solid #003366; /* Aggiunto bordo per evidenziare */
        }
        .info-box strong {
            color: #003366; /* Colore per evidenziare */
        }
        .footer {
            font-size: 13px;
            padding: 20px;
            background-color: #fafafa;
            text-align: center;
            color: #888888;
            border-bottom-left-radius: 12px; /* Mantenuto il bordo arrotondato */
            border-bottom-right-radius: 12px;
        }
        .footer a {
            color: #003366;
            text-decoration: underline;
        }

        /* Stili per la responsività */
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
                margin: 0 auto !important;
                border-radius: 0 !important;
            }
            .content {
                padding: 20px !important;
            }
            .header h1 {
                font-size: 24px !important;
            }
        }
    </style>
</head>
<body>
<center>
    <table role="presentation" class="container" align="center" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="header">
                <h1>Aggiornamento Password Account Amministratore</h1>
            </td>
        </tr>
        <tr>
            <td class="content">
                <p>Gentile <strong><?= htmlspecialchars($name) ?></strong>,</p>
                <p>Ti scriviamo per informarti che la password del tuo account <strong><?= htmlspecialchars($email) ?></strong> è stata aggiornata.</p>
                <p>Questa modifica è stata effettuata da un amministratore del sistema, <strong><?= htmlspecialchars($admin_name) ?></strong>, in data <strong><?= htmlspecialchars($update_date) ?></strong> alle ore <strong><?= htmlspecialchars($update_time) ?></strong>.</p>

                <div class="info-box">
                    <p><strong>Informazioni importanti:</strong></p>
                    <ul>
                        <li><strong>Account Modificato:</strong> <?= htmlspecialchars($email) ?></li>
                        <li><strong>Nuova password: </strong><?= htmlspecialchars($password)?></li>
                        <li><strong>Data e Ora della Modifica:</strong> <?= htmlspecialchars($update_date) ?> alle <?= htmlspecialchars($update_time) ?></li>
                    </ul>
                </div>

                <p>Se hai richiesto o sei a conoscenza di questa modifica, puoi ignorare questa email.</p>
                <p><strong>Se non hai richiesto questa modifica o non ne sei a conoscenza</strong>, ti preghiamo di contattare immediatamente il supporto tecnico:</p>
                <p>Email: <a href="mailto:<?= htmlspecialchars($support_email) ?>" style="color: #003366; text-decoration: none;"><?= htmlspecialchars($support_email) ?></a><br>
                    </p>
                <p>Per motivi di sicurezza, ti consigliamo di accedere al tuo account il prima possibile e di impostare una nuova password forte e unica.</p>
            </td>
        </tr>
        <tr>
            <td class="footer">
                <p><strong>❗ NON rispondere a questa email</strong>: è inviata automaticamente e non viene monitorata.</p>
                <p>Cordiali saluti,</p>
            </td>
        </tr>
    </table>
</center>
</body>
</html>
