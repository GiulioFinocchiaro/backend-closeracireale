<?php
namespace Controllers;

require_once __DIR__ . "/../../vendor/autoload.php";

use Firebase\JWT\JWT;
use Core\Controller;
use Helpers\Response;
use Database\Connection;
use Dotenv\Dotenv;
use Helpers\Mail;
use Core\AuthMiddleware;
use Core\RoleChecker;

class AuthController extends Controller {

    private $secret_key;
    private $password_new_user_default;

    public function __construct() {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $this->secret_key = $_ENV['JWT_SECRET'];
        $this->password_new_user_default = $_ENV['PASSWORD_NEW_USER_DEFAULT'];
    }

    public function register(): void
    {
        // Decodifica i dati JSON inviati nella richiesta
        $data = json_decode(file_get_contents("php://input"), true);

        // 1. Validazione input iniziale: verifica la presenza di dati essenziali
        if (!isset($data["email"]) || !isset($data["name"]) || !isset($data["role"])) {
            $this->error("Dati mancanti: email, nome o ruolo.", 400);
            return;
        }

        // Normalizza i ruoli del nuovo utente in un array di ID interi.
        // Gestisce sia un singolo ID che un array di ID.
        $new_user_role_ids = [];
        if (is_array($data["role"])) {
            foreach ($data["role"] as $role_id) {
                $new_user_role_ids[] = intval($role_id);
            }
        } else {
            $new_user_role_ids[] = intval($data["role"]);
        }

        // Se l'array di ruoli è vuoto dopo la normalizzazione, restituisci un errore
        if (empty($new_user_role_ids)) {
            $this->error("Ruolo/i non valido/i specificato/i per il nuovo utente.", 400);
            return;
        }

        // Autentica l'utente che sta effettuando la richiesta di registrazione
        $auth = new AuthMiddleware();
        $registering_user_id = $auth->authenticate()->sub; // ID dell'utente autenticato

        // Recupera i dettagli dell'utente che sta registrando (l'amministratore)
        $conn = Connection::get();
        $stmt = $conn->prepare("SELECT email, name FROM users WHERE id = ?");
        $stmt->bind_param("i", $registering_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc(); // Dettagli dell'utente che sta registrando
        $stmt->close();

        // Verifica il permesso generale per registrare nuovi utenti
        $roleChecker = new RoleChecker();
        $hasPermissionToRegister = $roleChecker->userHasPermission($registering_user_id, 'users.register_new_users');

        if (!$hasPermissionToRegister) {
            $this->error("Utente non autorizzato: non ha i permessi per registrare nuovi utenti.", 403);
            return;
        }

        // 2. Recupera il livello di privilegio più alto dell'utente che sta registrando
        $registeringUserMaxPrivilege = null;
        try {
            $stmt = $conn->prepare("
                SELECT MAX(r.level) AS max_privilege
                FROM user_role ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ?;
            ");
            $stmt->bind_param("i", $registering_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row && $row['max_privilege'] !== null) {
                $registeringUserMaxPrivilege = (int)$row['max_privilege'];
            }
            $stmt->close();
        } catch (\Exception $e) {
            $this->error("Errore durante il recupero dei privilegi dell'utente registratore: " . $e->getMessage(), 500);
            return;
        }

        // Se non è stato possibile determinare il privilegio dell'utente registratore, nega l'accesso
        if ($registeringUserMaxPrivilege === null) {
            $this->error("Impossibile determinare il livello di privilegio dell'utente registratore. Accesso negato.", 403);
            return;
        }

        // 3. Valida i ruoli del nuovo utente e recupera il loro livello di privilegio più alto
        $newUsersRolesMaxPrivilege = 0; // Inizializza con un valore basso
        try {
            // Prepara i placeholder per la clausola IN
            $placeholders = implode(',', array_fill(0, count($new_user_role_ids), '?'));
            $types = str_repeat('i', count($new_user_role_ids)); // Tutti i parametri sono interi

            $stmt = $conn->prepare("
                SELECT id, level
                FROM roles
                WHERE id IN ($placeholders);
            ");
            // Utilizza l'operatore spread (...) per passare gli elementi dell'array come parametri
            $stmt->bind_param($types, ...$new_user_role_ids);
            $stmt->execute();
            $result = $stmt->get_result();

            $foundRoleIds = [];
            while ($row = $result->fetch_assoc()) {
                $foundRoleIds[] = (int)$row['id'];
                // Trova il massimo livello di privilegio tra i ruoli richiesti
                if ((int)$row['level'] > $newUsersRolesMaxPrivilege) {
                    $newUsersRolesMaxPrivilege = (int)$row['level'];
                }
            }
            $stmt->close();

            // Verifica che tutti i ruoli richiesti siano stati trovati nel database
            if (count(array_diff($new_user_role_ids, $foundRoleIds)) > 0) {
                $this->error("Uno o più ruoli specificati per il nuovo utente non sono validi o non esistono.", 400);
                return;
            }
            // A questo punto, $new_user_role_ids contiene solo ID di ruoli validi
        } catch (\Exception $e) {
            $this->error("Errore durante la validazione dei ruoli del nuovo utente: " . $e->getMessage(), 500);
            return;
        }

        // 4. Confronto dei privilegi: l'utente registratore deve avere privilegi sufficienti
        if ($newUsersRolesMaxPrivilege > $registeringUserMaxPrivilege) {
            $this->error("Non puoi assegnare un ruolo con un livello di privilegio superiore al tuo.", 403);
            return;
        }

        // Estrazione dei dati per la registrazione del nuovo utente
        $email = $data["email"];
        $name = $data["name"];
        $school = isset($data["school"]) ? $data["school"] : null;

        try {
            // Verifica se l'email è già registrata
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $this->error("E-mail già registrata.", 409);
                return;
            }
            $stmt->close();

            // Inserisce il nuovo utente nel database
            $hased_password = password_hash($this->password_new_user_default, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (email, password, name, school_id, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $email, $hased_password, $name, $school, $registering_user_id);
            $stmt->execute();
            $new_user_id = $conn->insert_id; // Ottiene l'ID del nuovo utente inserito
            $stmt->close();

            // Inserisce i ruoli associati al nuovo utente nella tabella user_roles
            $stmt = $conn->prepare("INSERT INTO user_role (user_id, role_id) VALUES (?, ?)");
            foreach ($new_user_role_ids as $role_id) {
                $stmt->bind_param("ii", $new_user_id, $role_id);
                $stmt->execute();
            }
            $stmt->close();

            // --- Invio email al nuovo utente ---
            $password = $this->password_new_user_default; // La password temporanea
            ob_start(); // Inizia il buffering dell'output
            include __DIR__ . '/../../templates/email/email_register.php'; // Includi il template email
            $html = ob_get_clean(); // Ottiene il contenuto del buffer

            Mail::sendMail(
                $email,
                "Benvenuto su " . $_ENV["PLATFORM_NAME"],
                $html,
                "Il tuo account " . $_ENV["PLATFORM_NAME"] . " è pronto"
            );

            // --- Invio email di notifica all'amministratore ---
            $admin_name = $user["name"]; // Nome dell'amministratore che ha registrato
            ob_start(); // Inizia un nuovo buffering dell'output
            include __DIR__ . '/../../templates/email/admin_user_registered.php'; // Includi il template email per l'admin
            $html = ob_get_clean(); // Ottiene il contenuto del buffer

            Mail::sendMail(
                $user["email"], // Email dell'amministratore
                "Hai registrato un nuovo utente",
                $html,
                "L'account di " . $name . " è pronto!" // $name è il nome del nuovo utente
            );

            // Risposta di successo
            Response::json(["message" => "Registrazione completata"]);

        } catch (\Exception $e) {
            // Gestione degli errori generici durante la registrazione
            // In un'applicazione reale, qui si potrebbe anche implementare un rollback della transazione
            $this->error("Si è verificato un errore durante la registrazione: " . $e->getMessage(), 500);
        }
    }

    public function login(): void
    {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['email']) || !isset($data['password'])) {
            $this->error("Email and password are required", 400, 0);
        }

        $conn = Connection::get();

        $email = $data['email'];
        $password = $data['password'];

        try {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $this->error("Email o password non corretta", 401, 1);
            }

            $user = $result->fetch_assoc();

            if (!password_verify($password, $user['password'])) {
                $this->error("Email o password non corretta", 401, 1);
            }

            $stmt = $conn->prepare("SELECT * FROM user_role WHERE user_id = ?");
            $stmt->bind_param("i", $user['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $roles = [];
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row['role_id'];
            }

            $issuedAt = time();
            $expirationTime = $issuedAt + 3600;

            $payload = [
                'iat' => $issuedAt,
                'exp' => $expirationTime,
                'sub' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'school_id' => $user['school_id'],
                'role' => $roles
            ];

            $jwt = JWT::encode($payload, $this->secret_key, 'HS256');


            Response::json([
                'message' => 'Login successful',
                'token' => $jwt
            ]);
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage(), 500);
        }
    }

    public function getMe(): void {
        $auth = new AuthMiddleware();
        $user_id = $auth->authenticate()->sub;

        $conn = Connection::get();
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $this->json($user);
    }
}
