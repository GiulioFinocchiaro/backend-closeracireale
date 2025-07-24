<?php
// Percorsi consigliati: questo file NON deve trovarsi nella directory pubblica (ad es. outside di public/)

use Dotenv\Dotenv;

require_once __DIR__ . '/../../vendor/autoload.php';

// Carica le variabili da .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Configurazione MySQL
return [
    'db' => [
        'host'     => $_ENV['DB_HOST']     ?? 'localhost',
        'port'     => $_ENV['DB_PORT']     ?? 3306,
        'username' => $_ENV['DB_USER']     ?? '',
        'password' => $_ENV['DB_PASS']     ?? '',
        'database' => $_ENV['DB_NAME']     ?? '',
        'charset'  => $_ENV['DB_CHARSET']  ?? 'utf8mb4',
    ],
];
