<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Dotenv\Dotenv;

class MediaController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null; // ID dell'utente autenticato (ora INT)
    private ?int $currentUserSchoolId = null; // school_id dell'utente autenticato
    private array $requestData; // Per i dati JSON in input
    private string $uploadDir; // Directory per il caricamento dei file

    public function __construct()
    {
        // Per i dati JSON (metadati)
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        // Configura la directory di upload. Assicurati che esista e sia scrivibile dal server web.
        // Esempio: '/var/www/html/uploads/graphic_assets' o '../uploads/graphic_assets'
        // È fondamentale che questa directory sia configurata correttamente e sicura.
        $dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
        $dotenv->load();

        $this->uploadDir = $_ENV["UPLOAD_DIR"];
        if (!is_dir($this->uploadDir)) {
            $this->error('Errore interno del server: La directory di upload non esiste o non è accessibile: ' . $this->uploadDir, 500);
            return; // Ferma l'esecuzione del costruttore se la directory non esiste
        }

        $this->authMiddleware = new AuthMiddleware();
        $dbConnection = Connection::get();

        if ($dbConnection === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }
        $this->permissionChecker = new RoleChecker();

        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = $decodedToken->sub; // Sarà un INT dal JWT

            // Recupera la school_id dell'utente corrente all'avvio del controller
            $stmt = $dbConnection->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
            $stmt->bind_param('i', $this->currentUserId); // Bind come INT
            $stmt->execute();
            $stmt->bind_result($this->currentUserSchoolId);
            $stmt->fetch();
            $stmt->close();

        } catch (\Exception $e) {
            $this->error('Autenticazione fallita: ' . $e->getMessage(), 401);
            return;
        }
    }

    /**
     * Carica un nuovo asset grafico (immagine/video) e ne memorizza i metadati.
     * Permessi: 'media.upload'
     * Dati richiesti (multipart/form-data per il file, JSON per i metadati):
     * - file: Il file da caricare (tramite $_FILES)
     * - asset_type: Tipo di asset (es. 'post_graphic', 'story_graphic', 'banner', 'general')
     * - description: Descrizione o istruzioni per l'uso (opzionale)
     * - campaign_id: ID della campagna associata (opzionale)
     *
     * @return void
     */
    public function uploadGraphicAsset(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'media.upload')) {
            $this->error('Accesso negato: Permessi insufficienti per caricare asset grafici.', 403);
            return;
        }

        $conn = Connection::get();
        $conn->begin_transaction(); // Inizia una transazione

        try {
            // 1. Gestione del file caricato (da $_FILES)
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('Nessun file caricato o errore nel caricamento.', 400);
                $conn->rollback();
                return;
            }

            $file = $_FILES['file'];
            $fileName = basename($file['name']);
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = $file['type'];
            $fileError = $file['error'];

            // Genera un nome file univoco per evitare sovrascritture
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            $uniqueFileName = uniqid('asset_', true) . '.' . $fileExtension;
            $destinationPath = $this->uploadDir . $uniqueFileName;

            // Sposta il file dalla directory temporanea alla destinazione finale
            if (!move_uploaded_file($fileTmpName, $destinationPath)) {
                $this->error('Errore durante lo spostamento del file caricato.', 500);
                $conn->rollback();
                return;
            }

            // 2. Recupera e valida i metadati (da $_POST o $this->requestData se inviati come JSON nel multipart)
            // Per multipart/form-data, i campi non-file sono in $_POST
            $assetType = $_POST['asset_type'] ?? null;
            $description = $_POST['description'] ?? null;
            $campaignId = $_POST['campaign_id'] ?? null;

            // Se i metadati sono inviati come JSON all'interno del multipart, usa $this->requestData
            if (empty($assetType) && isset($this->requestData['asset_type'])) {
                $assetType = $this->requestData['asset_type'];
                $description = $this->requestData['description'] ?? null;
                $campaignId = $this->requestData['campaign_id'] ?? null;
            }

            if (empty($assetType)) {
                $this->error('Tipo di asset (asset_type) mancante.', 400);
                // Elimina il file caricato se i metadati sono incompleti
                unlink($destinationPath);
                $conn->rollback();
                return;
            }

            // Valida campaign_id se fornito
            $campaignSchoolId = null;
            if ($campaignId !== null) {
                $campaignId = (int)$campaignId;
                $stmt_check_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
                $stmt_check_campaign->bind_param('i', $campaignId);
                $stmt_check_campaign->execute();
                $stmt_check_campaign->bind_result($campaignSchoolId);
                $stmt_check_campaign->fetch();
                $stmt_check_campaign->close();

                if ($campaignSchoolId === null) {
                    $this->error('Campagna specificata non trovata.', 404);
                    unlink($destinationPath); // Elimina il file
                    $conn->rollback();
                    return;
                }
            }

            // Determina la school_id da associare all'asset
            // Se l'asset è associato a una campagna, usa la school_id della campagna.
            // Altrimenti, usa la school_id dell'utente corrente.
            $assetSchoolId = $campaignSchoolId ?? $this->currentUserSchoolId;

            // Verifica permessi basati sulla scuola
            $canManageAllMedia = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.upload_all'); // Assumi permesso più generico
            $canManageOwnSchoolMedia = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.upload_own_school');

            if (!$canManageAllMedia && !($canManageOwnSchoolMedia && $this->currentUserSchoolId !== null && $assetSchoolId === $this->currentUserSchoolId)) {
                $this->error('Accesso negato: Permessi insufficienti per caricare media per questa scuola/campagna.', 403);
                unlink($destinationPath); // Elimina il file
                $conn->rollback();
                return;
            }


            // 3. Inserisci i metadati nel database
            // uploaded_by_user_id è ora INT
            $stmt_insert = $conn->prepare("INSERT INTO graphic_assets (file_name, file_path, file_type, asset_type, description, campaign_id, uploaded_by_user_id, school_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW());");
            $stmt_insert->bind_param('sssssiii', $uniqueFileName, $destinationPath, $fileType, $assetType, $description, $campaignId, $this->currentUserId, $assetSchoolId);

            if (!$stmt_insert->execute()) {
                $this->error('Errore durante il salvataggio dei metadati dell\'asset grafico: ' . $conn->error, 500);
                unlink($destinationPath); // Elimina il file caricato se il DB fallisce
                $conn->rollback();
                $stmt_insert->close();
                return;
            }
            $newAssetId = $conn->insert_id;
            $stmt_insert->close();

            $conn->commit(); // Conferma la transazione

            $this->json(['message' => 'Asset grafico caricato con successo.', 'asset_id' => $newAssetId, 'file_url' => '/uploads/graphic_assets/' . $uniqueFileName], 201);

        } catch (\Exception $e) {
            $conn->rollback(); // Annulla la transazione
            error_log("Errore in uploadGraphicAsset: " . $e->getMessage());
            $this->error('Errore interno del server durante il caricamento dell\'asset grafico.', 500);
        }
    }

    /**
     * Recupera un elenco di asset grafici.
     * Permessi: 'media.view_all', 'media.view_own_school'
     * Dati richiesti da JSON (opzionali): 'campaign_id' (int), 'asset_type' (string)
     *
     * @return void
     */
    public function getGraphicAssets(): void
    {
        $conn = Connection::get();
        $assets = [];

        // Verifica permessi
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_own_school');

        if (!$canViewAll && !$canViewOwnSchool) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare gli asset grafici.', 403);
            return;
        }

        // u.id è INT, ga.uploaded_by_user_id è INT
        $sql = "SELECT ga.id, ga.file_name, ga.file_path, ga.file_type, ga.asset_type, ga.description, ga.campaign_id, ga.uploaded_by_user_id, ga.school_id, ga.created_at, u.name as uploader_name
                FROM graphic_assets ga
                JOIN users u ON ga.uploaded_by_user_id = u.id";
        $params = [];
        $types = '';
        $whereClauses = [];

        $campaignId = $this->requestData['campaign_id'] ?? null;
        $assetType = $this->requestData['asset_type'] ?? null;

        if ($campaignId !== null) {
            $campaignId = (int)$campaignId;
            $sqlWhere[] = "ga.campaign_id = ?";
            $params[] = $campaignId;
            $types .= 'i';
        }
        if ($assetType !== null) {
            $assetType = trim($assetType);
            $sqlWhere[] = "ga.asset_type = ?";
            $params[] = $assetType;
            $types .= 's';
        }

        // Filtro per school_id se l'utente ha solo permessi per la propria scuola
        if (!$canViewAll && $canViewOwnSchool && $this->currentUserSchoolId !== null) {
            $sqlWhere[] = "ga.school_id = ?";
            $params[] = $this->currentUserSchoolId;
            $types .= 'i';
        } elseif (!$canViewAll && !$canViewOwnSchool) {
            // Se non ha permessi specifici e non ha richiesto filtri, non può vedere nulla
            $this->json([], 200);
            return;
        }

        if (!empty($sqlWhere)) {
            $sql .= " WHERE " . implode(' AND ', $sqlWhere);
        }

        $sql .= " ORDER BY ga.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = &$params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }
        $stmt->close();

        $this->json($assets, 200);
    }

    /**
     * Recupera i dettagli di un singolo asset grafico.
     * Permessi: 'media.view_all', 'media.view_own_school'
     * Dati richiesti da JSON: 'id' (int)
     *
     * @return void
     */
    public function getSingleGraphicAsset(): void
    {
        $conn = Connection::get();

        $assetId = $this->requestData['id'] ?? null;
        if ($assetId === null) {
            $this->error('ID asset grafico da visualizzare mancante nella richiesta.', 400);
            return;
        }
        $assetId = (int)$assetId;

        // u.id è INT, ga.uploaded_by_user_id è INT
        $sql = "SELECT ga.id, ga.file_name, ga.file_path, ga.file_type, ga.asset_type, ga.description, ga.campaign_id, ga.uploaded_by_user_id, ga.school_id, ga.created_at, u.name as uploader_name
                FROM graphic_assets ga
                JOIN users u ON ga.uploaded_by_user_id = u.id
                WHERE ga.id = ? LIMIT 1;";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $assetId);
        $stmt->execute();
        $result = $stmt->get_result();
        $asset = $result->fetch_assoc();
        $stmt->close();

        if ($asset === null) {
            $this->error('Asset grafico non trovato.', 404);
            return;
        }

        // Verifica Autorizzazione
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_own_school');

        $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $asset['school_id']);

        if (!$canViewAll && !($canViewOwnSchool && $isOwnSchoolAsset)) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare questo asset grafico.', 403);
            return;
        }

        $this->json($asset, 200);
    }

    /**
     * Modifica i metadati di un asset grafico esistente. Non modifica il file fisico.
     * Permessi: 'media.update_all', 'media.update_own_school'
     * Dati richiesti da JSON: 'id' (int), 'asset_type' (string, opzionale), 'description' (string, opzionale), 'campaign_id' (int, opzionale)
     *
     * @return void
     */
    public function updateGraphicAsset(): void
    {
        $conn = Connection::get();

        $assetIdToModify = $this->requestData['id'] ?? null;
        if ($assetIdToModify === null) {
            $this->error('ID asset grafico da modificare mancante nella richiesta.', 400);
            return;
        }
        $assetIdToModify = (int)$assetIdToModify;

        // Recupera i dettagli dell'asset target (school_id)
        $targetSchoolId = null;
        $stmt_target_asset = $conn->prepare("SELECT school_id FROM graphic_assets WHERE id = ? LIMIT 1;");
        $stmt_target_asset->bind_param('i', $assetIdToModify);
        $stmt_target_asset->execute();
        $stmt_target_asset->bind_result($targetSchoolId);
        $stmt_target_asset->fetch();
        $stmt_target_asset->close();

        if ($targetSchoolId === null) { // Asset non trovato
            $this->error('Asset grafico da modificare non trovato.', 404);
            return;
        }

        // Verifica Autorizzazione
        $canUpdateAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.update_all');
        $canUpdateOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.update_own_school');

        $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        if (!$canUpdateAll && !($canUpdateOwnSchool && $isOwnSchoolAsset)) {
            $this->error('Accesso negato: Permessi insufficienti per modificare questo asset grafico.', 403);
            return;
        }

        // Recupera e valida i dati per la modifica
        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['asset_type'])) {
            $assetType = trim($this->requestData['asset_type']);
            if (empty($assetType)) {
                $this->error('Il tipo di asset non può essere vuoto.', 400);
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
        if (isset($this->requestData['campaign_id'])) {
            $campaignId = $this->requestData['campaign_id'];
            if ($campaignId !== null) { // Può essere null per dissociare
                $campaignId = (int)$campaignId;
                // Verifica che la campagna esista
                $stmt_check_campaign = $conn->prepare("SELECT id FROM campaigns WHERE id = ? LIMIT 1;");
                $stmt_check_campaign->bind_param('i', $campaignId);
                $stmt_check_campaign->execute();
                $stmt_check_campaign->store_result();
                if ($stmt_check_campaign->num_rows === 0) {
                    $this->error('Campagna specificata non trovata.', 404);
                    $stmt_check_campaign->close();
                    return;
                }
                $stmt_check_campaign->close();
            }
            $updateFields[] = "campaign_id = ?";
            $bindParams[] = $campaignId;
            $types .= 'i';
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento dei metadati.', 400);
            return;
        }

        // Costruisci ed esegui la query di aggiornamento
        $sql = "UPDATE graphic_assets SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);

        $bindParams[] = $assetIdToModify;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore durante l\'aggiornamento dei metadati dell\'asset grafico: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Metadati asset grafico aggiornati con successo.'], 200);
    }

    /**
     * Elimina un asset grafico e il suo file fisico.
     * Permessi: 'media.delete_all', 'media.delete_own_school'
     * Dati richiesti da JSON: 'id' (int)
     *
     * @return void
     */
    public function deleteGraphicAsset(): void
    {
        $conn = Connection::get();
        $conn->begin_transaction(); // Inizia una transazione

        try {
            $assetIdToDelete = $this->requestData['id'] ?? null;
            if ($assetIdToDelete === null) {
                $this->error('ID asset grafico da eliminare mancante nella richiesta.', 400);
                $conn->rollback();
                return;
            }
            $assetIdToDelete = (int)$assetIdToDelete;

            // Recupera il percorso del file e la school_id dell'asset
            $filePath = null;
            $targetSchoolId = null;
            $stmt_get_asset = $conn->prepare("SELECT file_path, school_id FROM graphic_assets WHERE id = ? LIMIT 1;");
            $stmt_get_asset->bind_param('i', $assetIdToDelete);
            $stmt_get_asset->execute();
            $stmt_get_asset->bind_result($filePath, $targetSchoolId);
            $stmt_get_asset->fetch();
            $stmt_get_asset->close();

            if ($filePath === null) { // Asset non trovato
                $this->error('Asset grafico da eliminare non trovato.', 404);
                $conn->rollback();
                return;
            }

            // Verifica Autorizzazione
            $canDeleteAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.delete_all');
            $canDeleteOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.delete_own_school');

            $isOwnSchoolAsset = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

            if (!$canDeleteAll && !($canDeleteOwnSchool && $isOwnSchoolAsset)) {
                $this->error('Accesso negato: Permessi insufficienti per eliminare questo asset grafico.', 403);
                $conn->rollback();
                return;
            }

            // 1. Elimina il record dal database
            $stmt_delete_db = $conn->prepare("DELETE FROM graphic_assets WHERE id = ?;");
            $stmt_delete_db->bind_param('i', $assetIdToDelete);

            if (!$stmt_delete_db->execute()) {
                throw new \Exception('Errore durante l\'eliminazione del record dell\'asset grafico dal database: ' . $conn->error);
            }
            $stmt_delete_db->close();

            // 2. Elimina il file fisico dal server
            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    // Se l'eliminazione del file fallisce, logga ma non fare rollback del DB se il record è già stato rimosso
                    error_log("Errore durante l'eliminazione del file fisico: {$filePath}");
                }
            } else {
                error_log("File fisico non trovato, ma il record DB è stato eliminato: {$filePath}");
            }

            $conn->commit(); // Conferma la transazione
            $this->json(['message' => 'Asset grafico eliminato con successo.'], 200);

        } catch (\Exception $e) {
            $conn->rollback(); // Annulla la transazione
            error_log("Errore in deleteGraphicAsset: " . $e->getMessage());
            $this->error('Errore interno del server durante l\'eliminazione dell\'asset grafico.', 500);
        }
    }
}
