<?php
namespace Core;

require_once __DIR__ . "/../../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use Helpers\Response;

class AuthMiddleware {

    private $secret_key;

    public function __construct() {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $this->secret_key = $_ENV['JWT_SECRET'];
    }

    public function authenticate() {
        // Metodo più robusto per ottenere l'header Authorization.
        // Cerca prima in HTTP_AUTHORIZATION (comune con Nginx/FPM)
        // Poi in REDIRECT_HTTP_AUTHORIZATION (comune con alcune configurazioni Apache)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (is_null($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }


        // Se l'header Authorization non è presente, restituisce un errore 401.
        if (is_null($authHeader)) {
            http_response_code(401);
            exit(json_encode(['error' => 'Token di autorizzazione mancante']));
        }

        // Verifica che il token sia nel formato "Bearer <token>".
        if (!str_starts_with($authHeader, 'Bearer ')) {
            http_response_code(401);
            exit(json_encode(['error' => 'Token di autorizzazione invalido o malformato']));
        }

        // Estrai il token rimuovendo "Bearer ".
        $token = substr($authHeader, 7);

        try {
            // Decodifica e valida il token JWT usando la chiave segreta e l'algoritmo HS256.
            // Se il token è valido e non scaduto, restituisce l'oggetto decodificato.
            return JWT::decode($token, new Key($this->secret_key, 'HS256'));
        } catch (\Exception $e) {
            // Cattura qualsiasi eccezione durante la decodifica (es. token scaduto, firma invalida).
            http_response_code(401);
            // Invia un messaggio di errore che include il motivo specifico dell'eccezione.
            exit(json_encode(['error' => 'Token invalido o scaduto: ' . $e->getMessage()]));
        }
    }

    public function authorize(array $allowedRoles, $decodedToken) {
        // Verifica se la proprietà 'role' esiste nel token decodificato e se il ruolo è permesso.
        if (!isset($decodedToken->role) || !in_array($decodedToken->role, $allowedRoles)) {
            http_response_code(403);
            exit(json_encode(['error' => 'Accesso negato: Permessi insufficienti']));
        }
    }
}