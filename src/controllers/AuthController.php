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
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data["email"], $data["name"], $data["role"])) {
            $this->error("Dati mancanti: email, nome o ruolo.", 400);
            return;
        }

        $new_user_role_ids = is_array($data["role"]) ? array_map('intval', $data["role"]) : [intval($data["role"])];
        if (empty($new_user_role_ids)) {
            $this->error("Ruolo/i non valido/i specificato/i per il nuovo utente.", 400);
            return;
        }

        $auth = new AuthMiddleware();
        $registering_user_id = $auth->authenticate()->sub;

        $conn = Connection::get();

        // Verifica permesso generale
        $roleChecker = new RoleChecker();
        if (!$roleChecker->userHasPermission($registering_user_id, 'users.register_new_users')) {
            $this->error("Utente non autorizzato: non ha i permessi per registrare nuovi utenti.", 403);
            return;
        }
        $registeringUserMaxPrivilege = null;

        // Livello massimo dell'utente registratore
        $stmt = $conn->prepare("
        SELECT MAX(r.level) AS max_privilege
        FROM user_role ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ?;
    ");
        $stmt->bind_param("i", $registering_user_id);
        $stmt->execute();
        $stmt->bind_result($registeringUserMaxPrivilege);
        $stmt->fetch();
        $stmt->close();

        if ($registeringUserMaxPrivilege === null) {
            $this->error("Impossibile determinare il livello di privilegio dell'utente registratore.", 403);
            return;
        }

        // Recupera ruoli validi e massimo privilegio del nuovo utente
        $placeholders = implode(',', array_fill(0, count($new_user_role_ids), '?'));
        $types = str_repeat('i', count($new_user_role_ids));
        $stmt = $conn->prepare("SELECT id, level FROM roles WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$new_user_role_ids);
        $stmt->execute();
        $result = $stmt->get_result();

        $validRoleIds = [];
        $newUsersRolesMaxPrivilege = 0;
        while ($row = $result->fetch_assoc()) {
            $validRoleIds[] = (int)$row['id'];
            $newUsersRolesMaxPrivilege = max($newUsersRolesMaxPrivilege, (int)$row['level']);
        }
        $stmt->close();

        if (count($validRoleIds) !== count($new_user_role_ids)) {
            $this->error("Uno o più ruoli specificati non sono validi.", 400);
            return;
        }

        if ($newUsersRolesMaxPrivilege > $registeringUserMaxPrivilege) {
            $this->error("Non puoi assegnare un ruolo con un livello di privilegio superiore al tuo.", 403);
            return;
        }

        $email = $data["email"];
        $name = $data["name"];
        $school = $data["school"] ?? null;

        // Verifica email duplicata
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $this->error("E-mail già registrata.", 409);
            return;
        }
        $stmt->close();

        // Inserisci nuovo utente
        $hashed_password = password_hash($this->password_new_user_default, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (email, password, name, school_id, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $email, $hashed_password, $name, $school, $registering_user_id);
        if (!$stmt->execute()) {
            $this->error("Errore durante l'inserimento dell'utente: " . $conn->error, 500);
            return;
        }
        $new_user_id = $conn->insert_id;
        $stmt->close();

        // Inserisci ruoli senza duplicati
        $stmt = $conn->prepare("INSERT INTO user_role (user_id, role_id) VALUES (?, ?)");
        foreach ($validRoleIds as $role_id) {
            $stmt->bind_param("ii", $new_user_id, $role_id);
            $stmt->execute();
        }
        $stmt->close();

        // Invia email al nuovo utente
        ob_start();
        include __DIR__ . '/../../templates/email/email_register.php';
        $html = ob_get_clean();
        Mail::sendMail($email, "Benvenuto su " . $_ENV["PLATFORM_NAME"], $html, "Il tuo account " . $_ENV["PLATFORM_NAME"] . " è pronto");

        // Notifica all'amministratore
        $stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->bind_param("i", $registering_user_id);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        ob_start();
        include __DIR__ . '/../../templates/email/admin_user_registered.php';
        $html = ob_get_clean();
        Mail::sendMail($admin['email'], "Hai registrato un nuovo utente", $html, "L'account di $name è pronto!");

        Response::json(["success" => true, "message" => "Registrazione completata", "user_id" => $new_user_id]);
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
        $stmt = $conn->prepare("
        SELECT 
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email,
            r.id AS role_id,
            r.name AS role_name,
            r.level AS role_level,
            r.color AS role_color,
            r.school_id AS role_school_id,
            p.id AS permission_id,
            p.name AS permission_name,
            p.display_name AS permission_display_name
        FROM users u
        LEFT JOIN user_role ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        LEFT JOIN role_permissions rp ON r.id = rp.role_id
        LEFT JOIN permissions p ON rp.permission_id = p.id
        WHERE u.id = ?
        ORDER BY r.level DESC;
    ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $user = null;
        $roles = [];

        while ($row = $result->fetch_assoc()) {
            if ($user === null) {
                $user = [
                    "id" => $row["user_id"],
                    "name" => $row["user_name"],
                    "email" => $row["user_email"],
                    "roles" => []
                ];
            }

            if ($row["role_id"]) {
                if (!isset($roles[$row["role_id"]])) {
                    $roles[$row["role_id"]] = [
                        "id" => $row["role_id"],
                        "name" => $row["role_name"],
                        "level" => $row["role_level"],
                        "color" => $row["role_color"],
                        "school_id" => $row["role_school_id"],
                        "permissions" => []
                    ];
                }

                if ($row["permission_id"]) {
                    $roles[$row["role_id"]]["permissions"][] = [
                        "id" => $row["permission_id"],
                        "name" => $row["permission_name"],
                        "display_name" => $row["permission_display_name"]
                    ];
                }
            }
        }

        if ($user !== null) {
            $user["roles"] = array_values($roles);
        }

        $this->json($user);
    }
}
