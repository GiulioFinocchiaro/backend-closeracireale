<?php
// Queste variabili verranno dal contesto del file che include questo template.
// Le assegnazioni qui servono solo come fallback se non sono già definite.
require_once __DIR__ . "/../../vendor/autoload.php";

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
$dotenv->load();

$current_admin_name = $current_admin_name ?? 'Amministratore'; // Nome dell'amministratore che ha creato la scuola
$school_name = $school_name ?? 'Nome Scuola'; // Nome della nuova scuola
$school_list_name = $school_list_name ?? 'Nome Lista Scuola'; // Nome lista della nuova scuola
$creation_date = $creation_date ?? date('d/m/Y');
$creation_time = $creation_time ?? date('H:i');
$platform_name = $_ENV["PLATFORM_NAME"] ?? 'Piattaforma'; // Nome della piattaforma
$support_email = $_ENV["MAIL_SUPPORT"] ?? 'supporto@sito.it'; // Email di supporto
$platform_website = $_ENV["PLATFORM_WEBSITE"] ?? 'https://www.sito.it'; // Sito web della piattaforma
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Conferma Creazione Nuova Scuola su <?= htmlspecialchars($platform_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            font-family: 'Segoe UI', sans-serif;
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
            color: #003366;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
        }
        .header {
            background-color: #003366;
            color: #ffffff;
            padding: 30px;
            text-align: center;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .content {
            padding: 30px;
            color: #333333;
            line-height: 1.6;
            font-size: 15px;
        }
        .content h2 {
            color: #003366;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        .info-box {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: monospace;
            border-left: 4px solid #003366;
        }
        .info-box strong {
            color: #003366;
        }
        .footer {
            font-size: 13px;
            padding: 20px;
            background-color: #fafafa;
            text-align: center;
            color: #888888;
            border-bottom-left-radius: 12px;
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
                <h1>Nuova Scuola Creata</h1>
            </td>
        </tr>
        <tr>
            <td class="content">
                <p>Ciao <strong><?= htmlspecialchars($current_admin_name) ?></strong>,</p>
                <p>Ti confermiamo che la nuova scuola <strong><?= htmlspecialchars($school_name) ?></strong> è stata creata con successo nel sistema.</p>
                <p>Questa operazione è stata effettuata da te in data <strong><?= htmlspecialchars($creation_date) ?></strong> alle ore <strong><?= htmlspecialchars($creation_time) ?></strong>.</p>

                <div class="info-box">
                    <p><strong>Dettagli della nuova scuola:</strong></p>
                    <ul>
                        <li><strong>Nome Scuola:</strong> <?= htmlspecialchars($school_name) ?></li>
                        <li><strong>Nome Lista:</strong> <?= htmlspecialchars($school_list_name) ?></li>
                        <li><strong>Data e Ora Creazione:</strong> <?= htmlspecialchars($creation_date) ?> alle <?= htmlspecialchars($creation_time) ?></li>
                    </ul>
                </div>

                <p>Se hai domande o necessiti di ulteriore assistenza, non esitare a contattare il supporto tecnico:</p>
                <p>Email: <a href="mailto:<?= htmlspecialchars($support_email) ?>" style="color: #003366; text-decoration: none;"><?= htmlspecialchars($support_email) ?></a></p>
            </td>
        </tr>
        <tr>
            <td class="footer">
                <p><strong>❗ NON rispondere a questa email</strong>: è inviata automaticamente e non viene monitorata.</p>
                <p>Cordiali saluti,</p>
                <p>L'Amministrazione di <?= htmlspecialchars($platform_name) ?><br>
                    <a href="<?= htmlspecialchars($platform_website) ?>" style="color: #003366; text-decoration: underline;"><?= htmlspecialchars($platform_website) ?></a></p>
            </td>
        </tr>
    </table>
</center>
</body>
</html>
