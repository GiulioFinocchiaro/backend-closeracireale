<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Dotenv\Dotenv;
use Exception;

class GraphicContestController extends Controller
{
    private string $uploadDir;
    private ?AuthMiddleware $authMiddleware = null;
    private ?RoleChecker $permissionChecker = null;
    private ?int $currentUserId = null;
    private ?int $currentUserSchoolId = null;

    public function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
        $dotenv->load();

        $this->uploadDir = $_ENV["UPLOAD_DIR"];
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    private function initAuth(): void
    {
        if ($this->authMiddleware === null) {
            $this->authMiddleware = new AuthMiddleware();
            $this->permissionChecker = new RoleChecker();
            
            try {
                $decodedToken = $this->authMiddleware->authenticate();
                $this->currentUserId = $decodedToken->sub;

                $conn = Connection::get();
                $stmt = $conn->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $this->currentUserId);
                $stmt->execute();
                $stmt->bind_result($this->currentUserSchoolId);
                $stmt->fetch();
                $stmt->close();
            } catch (Exception $e) {
                $this->error("Autenticazione fallita: " . $e->getMessage(), 401);
                return;
            }
        }
    }

    /**
     * Aggiungere una grafica (senza autenticazione)
     */
    public function addGraphic(): void
    {
        $conn = Connection::get();
        
        try {
            // Verifica che sia stato caricato un file
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('Nessun file caricato o errore nel caricamento.', 400);
                return;
            }

            // Recupera i dati dal form
            $school_id = $_POST['school_id'] ?? null;
            $name = $_POST['name'] ?? null;
            $description = $_POST['description'] ?? null;
            $uploader_name = $_POST['uploader_name'] ?? null;
            $phone_number = $_POST['phone_number'] ?? null;
            $class = $_POST['class'] ?? null;

            // Validazione campi obbligatori
            if (empty($school_id) || empty($name) || empty($uploader_name)) {
                $this->error('Campi obbligatori mancanti: school_id, name, uploader_name.', 400);
                return;
            }

            $file = $_FILES['file'];
            $fileName = basename($file['name']);
            $fileTmpName = $file['tmp_name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'ai', 'psd'];

            if (!in_array($fileExtension, $allowedExtensions)) {
                $this->error('Tipo di file non supportato. Formati consentiti: jpg, jpeg, png, gif, pdf, ai, psd.', 415);
                return;
            }

            // Crea nome file unico
            $uniqueFileName = uniqid('contest_', true) . '.' . $fileExtension;
            $destinationPath = $this->uploadDir . $uniqueFileName;

            // Sposta il file
            if (!move_uploaded_file($fileTmpName, $destinationPath)) {
                $this->error('Errore nel salvataggio del file.', 500);
                return;
            }

            // Inserisce nel database
            $stmt = $conn->prepare("
                INSERT INTO graphic_contest (school_id, name, description, uploader_name, phone_number, class, file_path, status, likes, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
            ");
            
            $stmt->bind_param('issssss', $school_id, $name, $description, $uploader_name, $phone_number, $class, $uniqueFileName);

            if (!$stmt->execute()) {
                // Rimuovi il file se il DB fallisce
                unlink($destinationPath);
                $this->error('Errore nel salvataggio nel database: ' . $conn->error, 500);
                $stmt->close();
                return;
            }

            $graphic_id = $conn->insert_id;
            $stmt->close();

            $this->json([
                'success' => true,
                'message' => 'Grafica caricata con successo.',
                'graphic_id' => $graphic_id,
                'file_path' => $uniqueFileName
            ], 201);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Aggiungere un like (senza autenticazione)
     */
    public function addLike(): void
    {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['graphic_id'])) {
            $this->error('graphic_id mancante.', 400);
            return;
        }

        $graphic_id = (int)$data['graphic_id'];
        $conn = Connection::get();

        try {
            // Verifica che la grafica esista
            $stmt = $conn->prepare("SELECT id FROM graphic_contest WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $graphic_id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows === 0) {
                $this->error('Grafica non trovata.', 404);
                $stmt->close();
                return;
            }
            $stmt->close();

            // Incrementa i like
            $stmt = $conn->prepare("UPDATE graphic_contest SET likes = likes + 1 WHERE id = ?");
            $stmt->bind_param('i', $graphic_id);
            
            if (!$stmt->execute()) {
                $this->error('Errore nell\'aggiunta del like: ' . $conn->error, 500);
                $stmt->close();
                return;
            }
            $stmt->close();

            // Recupera il numero aggiornato di like
            $stmt = $conn->prepare("SELECT likes FROM graphic_contest WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $graphic_id);
            $stmt->execute();
            $stmt->bind_result($likes);
            $stmt->fetch();
            $stmt->close();

            $this->json([
                'success' => true,
                'message' => 'Like aggiunto con successo.',
                'total_likes' => $likes
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approvare o disapprovare una grafica (con autenticazione e controllo permessi)
     */
    public function approveGraphic(): void
    {
        $this->initAuth();
        if ($this->currentUserId === null) return;

        // Verifica permessi
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'graphics.approve')) {
            $this->error('Permessi insufficienti per approvare/disapprovare grafiche.', 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['graphic_id']) || !isset($data['status'])) {
            $this->error('graphic_id e status sono obbligatori.', 400);
            return;
        }

        $graphic_id = (int)$data['graphic_id'];
        $status = (int)$data['status'];

        // Valida status (0 = disapprovata/attesa, 1 = approvata)
        if (!in_array($status, [0, 1])) {
            $this->error('Status non valido. Usa 0 per disapprovare/mettere in attesa, 1 per approvare.', 400);
            return;
        }

        $conn = Connection::get();

        try {
            // Verifica che la grafica esista
            $stmt = $conn->prepare("SELECT school_id FROM graphic_contest WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $graphic_id);
            $stmt->execute();
            $stmt->bind_result($graphic_school_id);
            $stmt->fetch();
            $stmt->close();

            if ($graphic_school_id === null) {
                $this->error('Grafica non trovata.', 404);
                return;
            }

            // Verifica permessi scuola (opzionale - dipende dalla logica di business)
            // Se vuoi che si possano approvare solo grafiche della propria scuola, decommenta:
            /*
            if ($this->currentUserSchoolId !== $graphic_school_id && 
                !$this->permissionChecker->userHasPermission($this->currentUserId, 'graphics.approve_all_schools')) {
                $this->error('Non puoi approvare grafiche di altre scuole.', 403);
                return;
            }
            */

            // Aggiorna lo status
            $stmt = $conn->prepare("UPDATE graphic_contest SET status = ? WHERE id = ?");
            $stmt->bind_param('ii', $status, $graphic_id);
            
            if (!$stmt->execute()) {
                $this->error('Errore nell\'aggiornamento dello status: ' . $conn->error, 500);
                $stmt->close();
                return;
            }
            $stmt->close();

            $status_text = $status === 1 ? 'approvata' : 'disapprovata/messa in attesa';
            
            $this->json([
                'success' => true,
                'message' => "Grafica $status_text con successo.",
                'graphic_id' => $graphic_id,
                'new_status' => $status
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Visualizzare tutte le grafiche approvate (senza autenticazione)
     */
    public function getApprovedGraphics(): void
    {
        $conn = Connection::get();
        
        try {
            $sql = "
                SELECT gc.id, gc.school_id, gc.name, gc.description, gc.uploader_name, 
                       gc.phone_number, gc.class, gc.file_path, gc.status, gc.likes, gc.created_at,
                       s.name as school_name
                FROM graphic_contest gc
                LEFT JOIN schools s ON gc.school_id = s.id
                WHERE gc.status = 1 
                ORDER BY gc.likes DESC, gc.created_at DESC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $graphics = [];
            while ($row = $result->fetch_assoc()) {
                $graphics[] = $row;
            }
            $stmt->close();

            $this->json([
                'success' => true,
                'graphics' => $graphics,
                'count' => count($graphics)
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Visualizzare tutte le grafiche (con autenticazione)
     */
    public function getAllGraphics(): void
    {
        $this->initAuth();
        if ($this->currentUserId === null) return;

        // Verifica permessi
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'graphics.view_all')) {
            $this->error('Permessi insufficienti per visualizzare tutte le grafiche.', 403);
            return;
        }

        $conn = Connection::get();
        
        try {
            $sql = "
                SELECT gc.id, gc.school_id, gc.name, gc.description, gc.uploader_name, 
                       gc.phone_number, gc.class, gc.file_path, gc.status, gc.likes, gc.created_at,
                       s.name as school_name
                FROM graphic_contest gc
                LEFT JOIN schools s ON gc.school_id = s.id
                ORDER BY gc.created_at DESC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $graphics = [];
            while ($row = $result->fetch_assoc()) {
                $graphics[] = $row;
            }
            $stmt->close();

            $this->json([
                'success' => true,
                'graphics' => $graphics,
                'count' => count($graphics)
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Modificare una grafica (con autenticazione)
     */
    public function updateGraphic(): void
    {
        $this->initAuth();
        if ($this->currentUserId === null) return;

        // Verifica permessi
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'graphics.update')) {
            $this->error('Permessi insufficienti per modificare grafiche.', 403);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['graphic_id'])) {
            $this->error('graphic_id mancante.', 400);
            return;
        }

        $graphic_id = (int)$data['graphic_id'];
        $conn = Connection::get();

        try {
            // Verifica che la grafica esista
            $stmt = $conn->prepare("SELECT school_id, name, description, uploader_name, phone_number, class FROM graphic_contest WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $graphic_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $graphic = $result->fetch_assoc();
            $stmt->close();

            if (!$graphic) {
                $this->error('Grafica non trovata.', 404);
                return;
            }

            // Prepara i campi da aggiornare
            $updateFields = [];
            $bindParams = [];
            $types = '';

            if (isset($data['name']) && !empty(trim($data['name']))) {
                $updateFields[] = "name = ?";
                $bindParams[] = trim($data['name']);
                $types .= 's';
            }

            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $bindParams[] = trim($data['description']);
                $types .= 's';
            }

            if (isset($data['uploader_name']) && !empty(trim($data['uploader_name']))) {
                $updateFields[] = "uploader_name = ?";
                $bindParams[] = trim($data['uploader_name']);
                $types .= 's';
            }

            if (isset($data['phone_number'])) {
                $updateFields[] = "phone_number = ?";
                $bindParams[] = trim($data['phone_number']);
                $types .= 's';
            }

            if (isset($data['class'])) {
                $updateFields[] = "class = ?";
                $bindParams[] = trim($data['class']);
                $types .= 's';
            }

            if (empty($updateFields)) {
                $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
                return;
            }

            // Esegue l'aggiornamento
            $sql = "UPDATE graphic_contest SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            $bindParams[] = $graphic_id;
            $types .= 'i';

            $stmt->bind_param($types, ...$bindParams);
            
            if (!$stmt->execute()) {
                $this->error('Errore nell\'aggiornamento: ' . $conn->error, 500);
                $stmt->close();
                return;
            }
            $stmt->close();

            $this->json([
                'success' => true,
                'message' => 'Grafica aggiornata con successo.',
                'graphic_id' => $graphic_id
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Ottenere una singola grafica
     */
    public function getSingleGraphic(): void
    {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['graphic_id'])) {
            $this->error('graphic_id mancante.', 400);
            return;
        }

        $graphic_id = (int)$data['graphic_id'];
        $conn = Connection::get();

        try {
            $sql = "
                SELECT gc.id, gc.school_id, gc.name, gc.description, gc.uploader_name, 
                       gc.phone_number, gc.class, gc.file_path, gc.status, gc.likes, gc.created_at,
                       s.name as school_name
                FROM graphic_contest gc
                LEFT JOIN schools s ON gc.school_id = s.id
                WHERE gc.id = ? 
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $graphic_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $graphic = $result->fetch_assoc();
            $stmt->close();

            if (!$graphic) {
                $this->error('Grafica non trovata.', 404);
                return;
            }

            $this->json([
                'success' => true,
                'graphic' => $graphic
            ]);

        } catch (Exception $e) {
            $this->error('Errore interno del server: ' . $e->getMessage(), 500);
        }
    }
}