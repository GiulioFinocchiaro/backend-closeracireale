<?php

// cron_instagram_publisher.php

// Questo script è destinato ad essere eseguito tramite Command Line Interface (CLI)
// (ad esempio, da un cron job). NON deve essere accessibile via web.

// Assicurati che il percorso al tuo autoloader sia corretto.
// Questo è fondamentale per caricare le classi come InstagramController e Connection.
// Potrebbe essere necessario adattare il percorso in base alla struttura del tuo progetto.
require_once __DIR__ . '/vendor/autoload.php';

// Importa le classi necessarie
use Controllers\InstagramController;
use Database\Connection;

// Inizializza la connessione al database, se necessario.
// La classe Connection dovrebbe gestire la logica di connessione.
// Se la tua classe Connection ha un metodo statico di inizializzazione, chiamalo qui.
// Esempio: Connection::init();
// Altrimenti, l'istanza di Controller (e quindi Connection) nel costruttore gestirà la connessione.

// Inizializza il controller.
// Il costruttore di InstagramController non esegue l'autenticazione quando chiamato da CLI.
$instagramController = new InstagramController();

// Chiama il metodo per elaborare i post in attesa.
// Questo metodo contiene la logica per recuperare i post programmati,
// ottenere i token di accesso specifici per la scuola e pubblicare su Instagram.
$instagramController->processPendingScheduledPosts();

// Messaggio di completamento (visibile solo nell'output del cron job o nel log)
echo "Processo di pubblicazione Instagram completato.\n";

// È buona pratica terminare lo script in modo esplicito per i cron job.
exit(0);
