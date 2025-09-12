<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Dotenv\Dotenv;
use Exception;

class MediaController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null;
    private ?int $currentUserSchoolId = null;
    private array $requestData;
    private string $uploadDir;

    // Nuovi campi centralizzati
    private bool $canViewAll = false;
    private bool $canViewOwn = false;
    private ?int $effectiveSchoolId = null;

    public function __construct()
    {
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        $dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
        $dotenv->load();

        $this->uploadDir = $_ENV["UPLOAD_DIR"];
        if (!is_dir($this->uploadDir)) {
            $this->error("Errore interno del server: directory upload mancante.", 500);
            return;
        }

        $this->authMiddleware = new AuthMiddleware();
        $db = Connection::get();
        if ($db === null) {
            $this->error("Errore interno del server: connessione DB non disponibile.", 500);
            return;
        }

        $this->permissionChecker = new RoleChecker();

        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = $decodedToken->sub;

            $stmt = $db->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
            $stmt->bind_param("i", $this->currentUserId);
            $stmt->execute();
            $stmt->bind_result($this->currentUserSchoolId);
            $stmt->fetch();
            $stmt->close();

            // ===============================
            // LOGICA PER PERMESSI E SCHOOL_ID
            // ===============================
            $this->canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, "media.view_all");
            $this->canViewOwn = $this->permissionChecker->userHasPermission($this->currentUserId, "media.view_own_school");

            if ($this->canViewAll) {
                $schoolId = $_POST["school_id"] ?? $this->requestData["school_id"] ?? null;
                if ($schoolId === null) {
                    echo $schoolId;
                    $this->error("`school_id` obbligatorio per visualizzare asset di un'altra scuola.", 400);
                    return;
                }
                $this->effectiveSchoolId = (int) $schoolId;
            } elseif ($this->canViewOwn) {
                $this->effectiveSchoolId = $this->currentUserSchoolId;
                if (isset($this->requestData["school_id"]) && (int)$this->requestData["school_id"] !== $this->effectiveSchoolId) {
                    $this->error("Non hai i permessi per accedere agli asset di un'altra scuola.", 403);
                    return;
                }
            } else {
                $this->error("Accesso negato: permessi insufficienti.", 403);
                return;
            }

        } catch (Exception $e) {
            $this->error("Autenticazione fallita: " . $e->getMessage(), 401);
            return;
        }
    }

    public function getGraphicAssets(): void
    {
        $conn = Connection::get();
        $assets = [];

        $sql = "SELECT ga.id, ga.file_name, ga.file_path, ga.file_type, ga.asset_type, ga.description, ga.uploaded_by_user_id, ga.school_id, ga.created_at, u.name as uploader_name
                FROM graphic_assets ga
                JOIN users u ON ga.uploaded_by_user_id = u.id
                WHERE ga.school_id = ?
                ORDER BY ga.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $this->error("Errore nella preparazione della query: " . $conn->error, 500);
            return;
        }

        $stmt->bind_param('i', $this->effectiveSchoolId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }
        $stmt->close();

        $this->json($assets, 200);
    }

    public function uploadGraphicAsset(): void
    {
        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $canUpload = $this->permissionChecker->userHasPermission($this->currentUserId, "media.upload");

            if (!$canUpload) {
                $this->error('Permessi insufficienti per caricare asset grafici.', 403);
                $conn->rollback();
                return;
            }

            $uploadSchoolId = $this->effectiveSchoolId;

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('Nessun file caricato o errore nel caricamento.', 400);
                $conn->rollback();
                return;
            }

            $file = $_FILES['file'];
            $fileName = basename($file['name']);
            $fileTmpName = $file['tmp_name'];
            $fileType = $file['type'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'avi'];

            if (!in_array($fileExtension, $allowedExtensions)) {
                $this->error('Tipo di file non supportato.', 415);
                $conn->rollback();
                return;
            }

            $assetType = $_POST['asset_type'] ?? null;
            $description = $_POST['description'] ?? null;

            if (empty($assetType)) {
                $this->error('asset_type mancante.', 400);
                $conn->rollback();
                return;
            }

            $uniqueFileName = uniqid('asset_', true) . '.' . $fileExtension;
            $destinationPath = $this->uploadDir . $uniqueFileName;
            $relativePath = $uniqueFileName;

            if (!move_uploaded_file($fileTmpName, $destinationPath)) {
                $this->error('Errore spostamento file.', 500);
                $conn->rollback();
                return;
            }

            $stmt_insert = $conn->prepare("INSERT INTO graphic_assets (file_name, file_path, file_type, asset_type, description, uploaded_by_user_id, school_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW());");
            $stmt_insert->bind_param('sssssii', $uniqueFileName, $relativePath, $fileType, $assetType, $description, $this->currentUserId, $uploadSchoolId);

            if (!$stmt_insert->execute()) {
                unlink($destinationPath);
                $this->error('Errore salvataggio DB: ' . $conn->error, 500);
                $conn->rollback();
                $stmt_insert->close();
                return;
            }

            $newAssetId = $conn->insert_id;
            $stmt_insert->close();
            $conn->commit();

            $this->json(['message' => 'Asset caricato con successo.', 'asset_id' => $newAssetId, 'file_url' => $relativePath], 201);

        } catch (Exception $e) {
            $conn->rollback();
            $this->error('Errore interno server: ' . $e->getMessage(), 500);
        }
    }

    public function getSingleGraphicAsset(): void
    {
        $conn = Connection::get();
        $assetId = $this->requestData['id'] ?? null;

        if ($assetId === null) {
            $this->error('ID asset mancante.', 400);
            return;
        }
        $assetId = (int)$assetId;

        $sql = "SELECT ga.id, ga.file_name, ga.file_path, ga.file_type, ga.asset_type, ga.description, ga.uploaded_by_user_id, ga.school_id, ga.created_at, u.name as uploader_name
                FROM graphic_assets ga
                JOIN users u ON ga.uploaded_by_user_id = u.id
                WHERE ga.id = ? LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $asset = $result->fetch_assoc();
        $stmt->close();

        if ($asset === null) {
            $this->error('Asset non trovato.', 404);
            return;
        }

        $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $asset['school_id']);
        if (!$this->canViewAll && !($this->canViewOwn && $isOwnSchoolAsset)) {
            $this->error('Accesso negato.', 403);
            return;
        }

        $this->json($asset, 200);
    }

    public function updateGraphicAsset(): void
    {
        $conn = Connection::get();
        $assetId = $this->requestData['id'] ?? null;
        if ($assetId === null) {
            $this->error('ID asset mancante.', 400);
            return;
        }
        $assetId = (int)$assetId;

        $targetSchoolId = null;
        $stmt = $conn->prepare("SELECT school_id FROM graphic_assets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $stmt->bind_result($targetSchoolId);
        $stmt->fetch();
        $stmt->close();

        if ($targetSchoolId === null) {
            $this->error('Asset non trovato.', 404);
            return;
        }

        $canUpdateAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.update_all');
        $canUpdateOwn = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.update_own_school');
        $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        if (!$canUpdateAll && !($canUpdateOwn && $isOwnSchoolAsset)) {
            $this->error('Permessi insufficienti.', 403);
            return;
        }

        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['asset_type'])) {
            $assetType = trim($this->requestData['asset_type']);
            if (empty($assetType)) {
                $this->error('asset_type non può essere vuoto.', 400);
                return;
            }
            $updateFields[] = "asset_type = ?";
            $bindParams[] = $assetType;
            $types .= 's';
        }

        if (isset($this->requestData['description'])) {
            $description = trim($this->requestData['description']);
            $updateFields[] = "description = ?";
            $bindParams[] = $description;
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per aggiornamento.', 400);
            return;
        }

        $sql = "UPDATE graphic_assets SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt_update = $conn->prepare($sql);
        $bindParams[] = $assetId;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore aggiornamento DB: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }

        $stmt_update->close();
        $this->json(['message' => 'Metadati aggiornati con successo.'], 200);
    }

    public function deleteGraphicAsset(): void
    {
        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $assetId = $this->requestData['id'] ?? null;
            if ($assetId === null) {
                $this->error('ID asset mancante.', 400);
                $conn->rollback();
                return;
            }
            $assetId = (int)$assetId;

            $filePath = null;
            $targetSchoolId = null;
            $stmt = $conn->prepare("SELECT file_path, school_id FROM graphic_assets WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $assetId);
            $stmt->execute();
            $stmt->bind_result($filePath, $targetSchoolId);
            $stmt->fetch();
            $stmt->close();

            if ($filePath === null) {
                $this->error('Asset non trovato.', 404);
                $conn->rollback();
                return;
            }

            $canDeleteAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.delete_all');
            $canDeleteOwn = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.delete_own_school');
            $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

            if (!$canDeleteAll && !($canDeleteOwn && $isOwnSchoolAsset)) {
                $this->error('Permessi insufficienti.', 403);
                $conn->rollback();
                return;
            }

            $stmt_delete = $conn->prepare("DELETE FROM graphic_assets WHERE id = ? AND school_id = ?");
            $stmt_delete->bind_param('ii', $assetId, $this->effectiveSchoolId);
            if (!$stmt_delete->execute()) {
                throw new Exception('Errore eliminazione DB: ' . $conn->error);
            }
            $stmt_delete->close();

            $fullPath = __DIR__ . "/../../" . $filePath;
            if (file_exists($fullPath) && is_file($fullPath) && str_starts_with(realpath($fullPath), realpath($this->uploadDir))) {
                unlink($fullPath);
            }

            $conn->commit();
            $this->json(['message' => 'Asset eliminato con successo.'], 200);

        } catch (Exception $e) {
            $conn->rollback();
            $this->error('Errore interno server: ' . $e->getMessage(), 500);
        }
    }
}
