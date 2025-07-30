<?php
// Queste variabili $name, $username, $password
// verranno dal contesto del file che include questo template.
// Le assegnazioni qui servono solo come fallback se non sono già definite.ù
require_once __DIR__ . "/../../vendor/autoload.php";

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
$dotenv->load();

$support_email = $_ENV["MAIL_SUPPORT"] ?? "supporto@email.it";
$platform_name = $_ENV["PLATFORM_NAME"] ?? "piattaforma";
$admin_name = $admin_name ?? 'utente';
$email = $email ?? 'account';
$password = $password ?? '********';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Benvenuto su Closer Acireale</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>

        body {

            margin: 0;

            padding: 0;

            background-color: #f4f4f4;

            font-family: 'Segoe UI', sans-serif;

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

        }

        .header h1 {

            margin: 0;

            font-size: 28px;

        }

        .content {

            padding: 30px;

            color: #333333;

        }

        .content h2 {

            color: #003366;

        }

        .credentials {

            background-color: #f0f8ff;

            padding: 15px;

            border-radius: 8px;

            margin: 20px 0;

            font-family: monospace;

        }

        .footer {

            font-size: 13px;

            padding: 20px;

            background-color: #fafafa;

            text-align: center;

            color: #888888;

        }

        a {

            color: #003366;

        }

    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Hai registranto un utente su <?= htmlspecialchars($platform_name)?>!</h1>
    </div>
    <div class="content">
        <p>Ciao <strong><?= htmlspecialchars($admin_name) ?></strong>,</p>
        <p>Hai registrato un account con i poteri di amministratore su questa piattaforma.</p>

        <h2>Le sue credenziali d'accesso:</h2>
        <div class="credentials">
            Username: <strong><?= htmlspecialchars($email) ?></strong><br>
            Password: <strong><?= htmlspecialchars($password) ?></strong>
        </div>
    </div>
    <div class="footer">
        <p><strong>❗ NON rispondere a questa email</strong>: è inviata automaticamente e non viene monitorata.</p>
        <p>Per problemi o assistenza, contatta il Super Admin: <br><a href="mailto:<?= htmlspecialchars($support_email)?>"><?=htmlspecialchars($support_email)?></a></p>
        <p><?=htmlspecialchars($_ENV["EMOTICON_SIMLE"].$platform_name)?></p>
    </div>
</div>
</body>
</html>