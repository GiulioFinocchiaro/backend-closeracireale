<?php
// Script di test per verificare che il sistema funzioni

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config/config.php';

use Database\Connection;

echo "<h1>Test API Graphic Contest</h1>";

// Test connessione database
try {
    
    $conn = Connection::get();
    echo "<p><strong>✅ Connessione database: OK</strong></p>";
    
    // Test query
    $result = $conn->query("SELECT COUNT(*) as count FROM schools");
    $row = $result->fetch_assoc();
    echo "<p>Numero di scuole nel database: " . $row['count'] . "</p>";
    
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    $row = $result->fetch_assoc();
    echo "<p>Numero di utenti nel database: " . $row['count'] . "</p>";
    
    $result = $conn->query("SELECT COUNT(*) as count FROM graphic_contest");
    $row = $result->fetch_assoc();
    echo "<p>Numero di grafiche nel contest: " . $row['count'] . "</p>";
    
} catch (Exception $e) {
    echo "<p><strong>❌ Errore database:</strong> " . $e->getMessage() . "</p>";
}

// Test caricamento autoload
try {
    echo "<p><strong>✅ Autoload Composer: OK</strong></p>";
} catch (Exception $e) {
    echo "<p><strong>❌ Errore Autoload:</strong> " . $e->getMessage() . "</p>";
}

echo "<h2>Endpoint API disponibili:</h2>";
echo "<ul>";
echo "<li><code>POST /api/graphic-contest/add</code> - Aggiungere una grafica (senza auth)</li>";
echo "<li><code>POST /api/graphic-contest/like</code> - Aggiungere un like (senza auth)</li>";
echo "<li><code>PUT /api/graphic-contest/approve</code> - Approvare/disapprovare (con auth)</li>";
echo "<li><code>GET /api/graphic-contest/approved</code> - Vedere grafiche approvate (senza auth)</li>";
echo "<li><code>POST /api/graphic-contest/all</code> - Vedere tutte le grafiche (con auth)</li>";
echo "<li><code>PUT /api/graphic-contest/update</code> - Modificare grafica (con auth)</li>";
echo "<li><code>POST /api/graphic-contest/single</code> - Ottenere singola grafica</li>";
echo "</ul>";

echo "<h2>Credenziali di test:</h2>";
echo "<p><strong>Admin:</strong> admin@system.com / admin123</p>";

echo "<h2>Test Login:</h2>";
echo "<p>Per ottenere un token JWT per testare le API con autenticazione:</p>";
echo "<pre>";
echo "curl -X POST http://localhost/api/auth/login \\
  -H 'Content-Type: application/json' \\
  -d '{\"email\":\"admin@system.com\",\"password\":\"admin123\"}'";
echo "</pre>";
?>