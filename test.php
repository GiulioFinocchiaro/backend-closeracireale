<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prova prima la tua riga originale
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (is_null($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

echo "AuthHeader da \$_SERVER: " . ($authHeader ?? 'NULL') . "<br><br>";

echo "Headers da getallheaders():<br>";
$allHeaders = getallheaders();
if ($allHeaders) {
    foreach ($allHeaders as $name => $value) {
        echo htmlspecialchars($name) . ": " . htmlspecialchars($value) . "<br>";
    }
} else {
    echo "getallheaders() non disponibile o non restituisce headers.<br>";
}
echo "<br>";

if (function_exists('apache_request_headers')) {
    echo "Headers da apache_request_headers():<br>";
    $apacheHeaders = apache_request_headers();
    if ($apacheHeaders) {
        foreach ($apacheHeaders as $name => $value) {
            echo htmlspecialchars($name) . ": " . htmlspecialchars($value) . "<br>";
        }
    } else {
        echo "apache_request_headers() non restituisce headers.<br>";
    }
} else {
    echo "apache_request_headers() non è disponibile.<br>";
}
?>