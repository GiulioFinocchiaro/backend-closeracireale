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

    public function register(): void {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data["email"]) || !isset($data["name"]) || !isset($data["role"])) {
            $this->error("Dati mancanti", 400, 0);
        }

        $auth = new AuthMiddleware();
        $user_id = $auth->authenticate()->sub;

        $roleChecker = new RoleChecker();
        $hasPermission = $roleChecker->userHasPermission($user_id, 'users.register_new_users');

        if (!$hasPermission) {
            $this->error("Utente non autorizzato, non ha il ruolo adatto", 403);
            return;
        }

        $conn = Connection::get();


        $email = $data["email"];
        $name = $data["name"];
        $role = array($data["role"]);
        if (!isset($data["school"])) {
            $school = null;
        } else $school = $data["school"];

        try {
             $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
             $stmt->bind_param("s", $email);
             $stmt->execute();
             $stmt->store_result();
             if ($stmt->num_rows > 0) {
                 $this->error("E-mail già registrata", 409, 1);
                 return;
             }

             $hased_password = password_hash($this->password_new_user_default, PASSWORD_DEFAULT);
             $stmt = $conn->prepare("INSERT INTO users (email, password, name, school_id, created_by) VALUES (?, ?, ?, ?, ?)");
             $stmt->bind_param("ssssi", $email, $hased_password, $name, $school, $user_id);
             $stmt->execute();
             $user_id = $conn->insert_id;

             foreach ($role as $item) {
                 $stmt = $conn->prepare("INSERT INTO user_role (user_id, role_id) VALUES (?, ?)");
                 $stmt->bind_param("ii", $user_id, $item);
                 $stmt->execute();
             }
            $password = $this->password_new_user_default;
            ob_start();
            include __DIR__ . '/../../templates/email/email_register.php';
            $html = ob_get_clean();

            Mail::sendMail($email, "Benvenuto su Closer Acireale!", $html, "Il tuo account Closer Acireale è pronto");
            $stmt->close();
            Response::json(["message" => "Registrazione completata"]);
        } catch (\Exception $e){
            $this->error("An error occurred: " . $e->getMessage(), 500);
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
}
