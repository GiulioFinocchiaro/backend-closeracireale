<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class InstagramController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null; // ID dell'utente autenticato (INT)
    private ?int $currentUserSchoolId = null; // school_id dell'utente autenticato
    private array $requestData; // Per i dati JSON in input

    public function __construct()
    {
        // Per le richieste HTTP (API)
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        $this->authMiddleware = new AuthMiddleware();
        $dbConnection = Connection::get();

        if ($dbConnection === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }
        $this->permissionChecker = new RoleChecker();

        // L'autenticazione è necessaria per tutti i metodi chiamati via HTTP (API)
        // ma non per il metodo processPendingScheduledPosts che sarà chiamato via CLI (cron job).
        // Quindi, la logica di autenticazione è spostata nei singoli metodi che ne hanno bisogno.
    }

    /**
     * Metodo di inizializzazione per i metodi che richiedono autenticazione.
     * Chiamato all'inizio di ogni metodo pubblico che è un endpoint HTTP.
     *
     * @return bool True se l'autenticazione ha successo, false altrimenti (e gestisce l'errore).
     */
    private function authenticateRequest(): bool
    {
        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = (int)$decodedToken->sub; // Sarà un INT dal JWT

            $conn = Connection::get();
            $stmt = $conn->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
            $stmt->bind_param('i', $this->currentUserId);
            $stmt->execute();
            $stmt->bind_result($this->currentUserSchoolId);
            $stmt->fetch();
            $stmt->close();

            return true;
        } catch (\Exception $e) {
            $this->error('Autenticazione fallita: ' . $e->getMessage(), 401);
            return false;
        }
    }

    /**
     * Programma un nuovo post o storia su Instagram.
     * Permessi: 'instagram.schedule_post'
     * Dati richiesti da JSON:
     * - asset_id: ID dell'asset grafico da pubblicare (dalla tabella graphic_assets)
     * - caption: Testo della didascalia per il post/storia di Instagram
     * - instagram_account_id: ID dell'account Instagram Business/Creator a cui pubblicare
     * - post_type: 'POST' o 'STORY'
     * - scheduled_at: Data e ora di pubblicazione desiderata (formato YYYY-MM-DD HH:MM:SS)
     *
     * @return void
     */
    public function scheduleInstagramPost(): void
    {
        if (!$this->authenticateRequest()) return;

        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.schedule_post')) {
            $this->error('Accesso negato: Permessi insufficienti per programmare post su Instagram.', 403);
            return;
        }

        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            // 1. Recupera e valida i dati
            $assetId = $this->requestData['asset_id'] ?? null;
            $caption = $this->requestData['caption'] ?? '';
            $instagramAccountId = $this->requestData['instagram_account_id'] ?? null;
            $postType = strtoupper($this->requestData['post_type'] ?? '');
            $scheduledAt = $this->requestData['scheduled_at'] ?? null;

            if ($assetId === null || empty($instagramAccountId) || empty($postType) || empty($scheduledAt)) {
                $this->error('Dati mancanti: asset_id, instagram_account_id, post_type, scheduled_at sono obbligatori.', 400);
                $conn->rollback();
                return;
            }

            $assetId = (int)$assetId;
            if (!in_array($postType, ['POST', 'STORY'])) {
                $this->error('Tipo di post non valido. Deve essere "POST" o "STORY".', 400);
                $conn->rollback();
                return;
            }

            // Validazione data/ora programmata
            $dateTimeScheduled = \DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt);
            if (!$dateTimeScheduled || $dateTimeScheduled->getTimestamp() < time()) {
                $this->error('Data e ora di programmazione non valide o nel passato. Formato richiesto: YYYY-MM-DD HH:MM:SS.', 400);
                $conn->rollback();
                return;
            }

            // 2. Verifica che l'asset esista e appartenga alla scuola dell'utente (o che l'utente abbia permessi per tutti i media)
            $assetSchoolId = null;
            $stmt_get_asset = $conn->prepare("SELECT school_id FROM graphic_assets WHERE id = ? LIMIT 1;");
            $stmt_get_asset->bind_param('i', $assetId);
            $stmt_get_asset->execute();
            $stmt_get_asset->bind_result($assetSchoolId);
            $stmt_get_asset->fetch();
            $stmt_get_asset->close();

            if ($assetSchoolId === null) {
                $this->error('Asset grafico specificato non trovato.', 404);
                $conn->rollback();
                return;
            }

            $canViewAllMedia = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_all');
            $canViewOwnSchoolMedia = $this->permissionChecker->userHasPermission($this->currentUserId, 'media.view_own_school');

            if (!$canViewAllMedia && !($canViewOwnSchoolMedia && $this->currentUserSchoolId !== null && $this->currentUserSchoolId === $assetSchoolId)) {
                $this->error('Accesso negato: Non puoi programmare post con asset non appartenenti alla tua scuola.', 403);
                $conn->rollback();
                return;
            }

            // 3. Verifica che l'instagram_account_id sia configurato per la scuola dell'asset
            $stmt_check_ig_account = $conn->prepare("SELECT school_id FROM school_instagram_accounts WHERE instagram_account_id = ? LIMIT 1;");
            $stmt_check_ig_account->bind_param('s', $instagramAccountId);
            $stmt_check_ig_account->execute();
            $stmt_check_ig_account->bind_result($configuredSchoolId);
            $stmt_check_ig_account->fetch();
            $stmt_check_ig_account->close();

            if ($configuredSchoolId === null) {
                $this->error('Account Instagram specificato non configurato per nessuna scuola.', 404);
                $conn->rollback();
                return;
            }
            if ($configuredSchoolId !== $assetSchoolId) {
                $this->error('L\'account Instagram specificato non appartiene alla scuola dell\'asset.', 403);
                $conn->rollback();
                return;
            }


            // 4. Inserisci il post programmato nel database
            $stmt_insert = $conn->prepare("INSERT INTO scheduled_instagram_posts (asset_id, instagram_account_id, caption, post_type, scheduled_at, created_by_user_id, school_id) VALUES (?, ?, ?, ?, ?, ?, ?);");
            $stmt_insert->bind_param('isssisi', $assetId, $instagramAccountId, $caption, $postType, $scheduledAt, $this->currentUserId, $assetSchoolId);

            if (!$stmt_insert->execute()) {
                $this->error('Errore durante la programmazione del post Instagram: ' . $conn->error, 500);
                $conn->rollback();
                $stmt_insert->close();
                return;
            }
            $newScheduledPostId = $conn->insert_id;
            $stmt_insert->close();

            $conn->commit();
            $this->json(['message' => 'Post Instagram programmato con successo.', 'scheduled_post_id' => $newScheduledPostId], 201);

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Errore in scheduleInstagramPost: " . $e->getMessage());
            $this->error('Errore interno del server durante la programmazione del post.', 500);
        }
    }

    /**
     * Recupera un elenco di post Instagram programmati.
     * Permessi: 'instagram.view_scheduled_posts_all', 'instagram.view_scheduled_posts_own_school'
     * Dati richiesti da JSON (opzionali): 'status' (PENDING, PUBLISHED, FAILED, CANCELLED), 'campaign_id' (int)
     *
     * @return void
     */
    public function getScheduledInstagramPosts(): void
    {
        if (!$this->authenticateRequest()) return;

        $conn = Connection::get();
        $scheduledPosts = [];

        // Verifica permessi
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.view_scheduled_posts_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.view_scheduled_posts_own_school');

        if (!$canViewAll && !$canViewOwnSchool) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare i post programmati di Instagram.', 403);
            return;
        }

        $sql = "SELECT sip.id, sip.asset_id, sip.instagram_account_id, sip.caption, sip.post_type, sip.scheduled_at, sip.status, sip.published_at, sip.failure_reason, sip.created_by_user_id, sip.school_id, sip.created_at, ga.file_name, ga.file_path, ga.file_type, u.name as created_by_user_name
                FROM scheduled_instagram_posts sip
                JOIN graphic_assets ga ON sip.asset_id = ga.id
                JOIN users u ON sip.created_by_user_id = u.id";
        $params = [];
        $types = '';
        $whereClauses = [];

        $statusFilter = $this->requestData['status'] ?? null;
        $campaignIdFilter = $this->requestData['campaign_id'] ?? null;

        if ($statusFilter !== null && in_array(strtoupper($statusFilter), ['PENDING', 'PUBLISHED', 'FAILED', 'CANCELLED'])) {
            $whereClauses[] = "sip.status = ?";
            $params[] = strtoupper($statusFilter);
            $types .= 's';
        }
        if ($campaignIdFilter !== null) {
            $campaignIdFilter = (int)$campaignIdFilter;
            $whereClauses[] = "ga.campaign_id = ?"; // Filtra per la campagna associata all'asset
            $params[] = $campaignIdFilter;
            $types .= 'i';
        }

        // Filtro per school_id se l'utente ha solo permessi per la propria scuola
        if (!$canViewAll && $canViewOwnSchool && $this->currentUserSchoolId !== null) {
            $whereClauses[] = "sip.school_id = ?";
            $params[] = $this->currentUserSchoolId;
            $types .= 'i';
        } elseif (!$canViewAll && !$canViewOwnSchool) {
            $this->json([], 200); // Restituisce un array vuoto se non ci sono permessi
            return;
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $sql .= " ORDER BY sip.scheduled_at ASC";

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
            // Aggiungi l'URL pubblico per il frontend
            $row['file_url'] = '/uploads/graphic_assets/' . basename($row['file_path']);
            $scheduledPosts[] = $row;
        }
        $stmt->close();

        $this->json($scheduledPosts, 200);
    }

    /**
     * Cancella un post Instagram programmato (solo se in stato PENDING).
     * Permessi: 'instagram.cancel_scheduled_post_all', 'instagram.cancel_scheduled_post_own_school'
     * Dati richiesti da JSON: 'id' (int)
     *
     * @return void
     */
    public function cancelScheduledInstagramPost(): void
    {
        if (!$this->authenticateRequest()) return;

        $conn = Connection::get();

        $scheduledPostId = $this->requestData['id'] ?? null;
        if ($scheduledPostId === null) {
            $this->error('ID del post programmato da cancellare mancante.', 400);
            return;
        }
        $scheduledPostId = (int)$scheduledPostId;

        // Recupera i dettagli del post programmato
        $postDetails = null;
        $stmt_get_post = $conn->prepare("SELECT status, school_id FROM scheduled_instagram_posts WHERE id = ? LIMIT 1;");
        $stmt_get_post->bind_param('i', $scheduledPostId);
        $stmt_get_post->execute();
        $result = $stmt_get_post->get_result();
        $postDetails = $result->fetch_assoc();
        $stmt_get_post->close();

        if ($postDetails === null) {
            $this->error('Post programmato non trovato.', 404);
            return;
        }

        // Verifica che il post sia in stato PENDING
        if ($postDetails['status'] !== 'PENDING') {
            $this->error('Impossibile cancellare un post che non è in stato PENDING (stato attuale: ' . $postDetails['status'] . ').', 400);
            return;
        }

        // Verifica permessi
        $canCancelAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.cancel_scheduled_post_all');
        $canCancelOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.cancel_scheduled_post_own_school');

        $isOwnSchoolPost = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $postDetails['school_id']);

        if (!$canCancelAll && !($canCancelOwnSchool && $isOwnSchoolPost)) {
            $this->error('Accesso negato: Permessi insufficienti per cancellare questo post programmato.', 403);
            return;
        }

        // Aggiorna lo stato a CANCELLED
        $stmt_update = $conn->prepare("UPDATE scheduled_instagram_posts SET status = 'CANCELLED' WHERE id = ?;");
        $stmt_update->bind_param('i', $scheduledPostId);

        if (!$stmt_update->execute()) {
            $this->error('Errore durante la cancellazione del post programmato: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Post programmato cancellato con successo.'], 200);
    }

    /**
     * Metodo interno chiamato da un cron job (o simile) per elaborare i post in attesa.
     * NON è un endpoint HTTP. Deve essere chiamato da CLI (Command Line Interface).
     *
     * @return void
     */
    public function processPendingScheduledPosts(): void
    {
        // Questo metodo NON deve essere chiamato via HTTP.
        // Deve essere eseguito tramite CLI (es. un cron job).
        if (php_sapi_name() !== 'cli') {
            error_log("Tentativo di chiamare processPendingScheduledPosts via HTTP. Accesso negato.");
            // Potresti voler restituire un errore 403 o 404 se chiamato via HTTP per sicurezza.
            // Per scopi di debug, potresti usare $this->error('Accesso negato: Questo metodo può essere chiamato solo da CLI.', 403);
            // ma in produzione, è meglio che non risponda affatto via HTTP.
            exit('Access Denied');
        }

        $conn = Connection::get();
        error_log("Inizio elaborazione post Instagram programmati...");

        // Recupera i post in stato PENDING la cui scheduled_at è passata o è ora,
        // e recupera anche il token di accesso dall'account Instagram della scuola.
        $stmt = $conn->prepare("SELECT sip.id, sip.asset_id, sip.caption, sip.instagram_account_id, sip.post_type, ga.file_path, ga.file_type, sia.access_token
                                FROM scheduled_instagram_posts sip
                                JOIN graphic_assets ga ON sip.asset_id = ga.id
                                JOIN school_instagram_accounts sia ON sip.instagram_account_id = sia.instagram_account_id
                                WHERE sip.status = 'PENDING' AND sip.scheduled_at <= NOW()
                                ORDER BY sip.scheduled_at ASC;");
        $stmt->execute();
        $result = $stmt->get_result();
        $postsToProcess = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($postsToProcess)) {
            error_log("Nessun post Instagram programmato da elaborare al momento.");
            return;
        }

        error_log("Trovati " . count($postsToProcess) . " post da elaborare.");

        foreach ($postsToProcess as $post) {
            $scheduledPostId = $post['id'];
            $assetId = $post['asset_id'];
            $caption = $post['caption'];
            $instagramAccountId = $post['instagram_account_id'];
            $postType = $post['post_type'];
            $filePath = $post['file_path'];
            $fileType = $post['file_type'];
            $userAccessToken = $post['access_token']; // Recuperato dal DB

            // Costruisci l'URL pubblico del file.
            $appBaseUrl = getenv('cdn_url') ?: ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $publicFileUrl = $appBaseUrl . '/uploads/graphic_assets/' . basename($filePath);

            try {
                // Esegui la logica di pubblicazione su Instagram
                $instagramApiBaseUrl = 'https://graph.facebook.com/v19.0/';

                if (empty($userAccessToken)) {
                    throw new \Exception('Token di accesso Instagram mancante per l\'account ' . $instagramAccountId . '.');
                }

                $mediaContainerParams = [
                    'caption' => $caption,
                    'access_token' => $userAccessToken
                ];

                if (str_starts_with($fileType, 'image/')) {
                    $mediaContainerParams['image_url'] = $publicFileUrl;
                    $mediaContainerParams['media_type'] = 'IMAGE';
                } elseif (str_starts_with($fileType, 'video/')) {
                    $mediaContainerParams['video_url'] = $publicFileUrl;
                    $mediaContainerParams['media_type'] = 'VIDEO';
                    $mediaContainerParams['share_to_feed'] = true;
                } else {
                    throw new \Exception('Tipo di file non supportato per la pubblicazione su Instagram: ' . $fileType);
                }

                // Step 1: Creare un Media Container
                $ch = curl_init($instagramApiBaseUrl . "{$instagramAccountId}/media");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($mediaContainerParams));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $responseData = json_decode($response, true);

                if ($httpCode !== 200 || !isset($responseData['id'])) {
                    throw new \Exception('Errore creazione media container Instagram (HTTP ' . $httpCode . '): ' . ($responseData['error']['message'] ?? 'Nessuna risposta valida'));
                }
                $mediaContainerId = $responseData['id'];

                // Step 2: Pubblicare il Media Container
                $ch = curl_init($instagramApiBaseUrl . "{$instagramAccountId}/media_publish");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['creation_id' => $mediaContainerId, 'access_token' => $userAccessToken]));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $responseData = json_decode($response, true);

                if ($httpCode !== 200 || !isset($responseData['id'])) {
                    throw new \Exception('Errore pubblicazione media Instagram (HTTP ' . $httpCode . '): ' . ($responseData['error']['message'] ?? 'Nessuna risposta valida'));
                }

                // Aggiorna lo stato del post programmato a PUBLISHED
                $stmt_update_status = $conn->prepare("UPDATE scheduled_instagram_posts SET status = 'PUBLISHED', published_at = NOW(), failure_reason = NULL WHERE id = ?;");
                $stmt_update_status->bind_param('i', $scheduledPostId);
                $stmt_update_status->execute();
                $stmt_update_status->close();
                error_log("Post Instagram ID {$scheduledPostId} pubblicato con successo.");

            } catch (\Exception $e) {
                // In caso di errore, aggiorna lo stato a FAILED e registra il motivo
                $failureReason = substr($e->getMessage(), 0, 500); // Trunca il messaggio se troppo lungo
                $stmt_update_status = $conn->prepare("UPDATE scheduled_instagram_posts SET status = 'FAILED', failure_reason = ?, published_at = NOW() WHERE id = ?;");
                $stmt_update_status->bind_param('si', $failureReason, $scheduledPostId);
                $stmt_update_status->execute();
                $stmt_update_status->close();
                error_log("Errore durante la pubblicazione del post Instagram ID {$scheduledPostId}: " . $e->getMessage());
            }
        }
        error_log("Fine elaborazione post Instagram programmati.");
    }

    /**
     * Imposta (aggiunge o aggiorna) la configurazione di un account Instagram per una scuola.
     * Permessi: 'instagram.configure_own_school_account' (per la propria scuola)
     * 'instagram.configure_all_school_accounts' (per qualsiasi scuola)
     * Dati richiesti da JSON:
     * - school_id (int, opzionale, se l'utente ha configure_all_school_accounts)
     * - instagram_account_id (string)
     * - access_token (string)
     *
     * @return void
     */
    public function setSchoolInstagramAccount(): void
    {
        if (!$this->authenticateRequest()) return;

        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $targetSchoolId = $this->requestData['school_id'] ?? $this->currentUserSchoolId;
            $instagramAccountId = $this->requestData['instagram_account_id'] ?? null;
            $accessToken = $this->requestData['access_token'] ?? null;

            if ($targetSchoolId === null || empty($instagramAccountId) || empty($accessToken)) {
                $this->error('Dati mancanti: school_id (se non admin), instagram_account_id e access_token sono obbligatori.', 400);
                $conn->rollback();
                return;
            }
            $targetSchoolId = (int)$targetSchoolId;

            // Verifica permessi
            $canConfigureAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_all_school_accounts');
            $canConfigureOwn = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_own_school_account');

            if (!$canConfigureAll && !($canConfigureOwn && $this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId)) {
                $this->error('Accesso negato: Permessi insufficienti per configurare l\'account Instagram per questa scuola.', 403);
                $conn->rollback();
                return;
            }

            // Verifica se la scuola esiste
            $stmt_check_school = $conn->prepare("SELECT id FROM schools WHERE id = ? LIMIT 1;");
            $stmt_check_school->bind_param('i', $targetSchoolId);
            $stmt_check_school->execute();
            $stmt_check_school->store_result();
            if ($stmt_check_school->num_rows === 0) {
                $this->error('Scuola specificata non trovata.', 404);
                $conn->rollback();
                return;
            }
            $stmt_check_school->close();

            // Prova a inserire o aggiornare (UPSERT)
            $stmt_upsert = $conn->prepare("INSERT INTO school_instagram_accounts (instagram_account_id, school_id, access_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE school_id = VALUES(school_id), access_token = VALUES(access_token), updated_at = NOW();");
            $stmt_upsert->bind_param('sis', $instagramAccountId, $targetSchoolId, $accessToken);

            if (!$stmt_upsert->execute()) {
                $this->error('Errore durante il salvataggio della configurazione dell\'account Instagram: ' . $conn->error, 500);
                $conn->rollback();
                $stmt_upsert->close();
                return;
            }
            $stmt_upsert->close();

            $conn->commit();
            $this->json(['message' => 'Configurazione account Instagram salvata con successo.', 'instagram_account_id' => $instagramAccountId, 'school_id' => $targetSchoolId], 200);

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Errore in setSchoolInstagramAccount: " . $e->getMessage());
            $this->error('Errore interno del server durante la configurazione dell\'account Instagram.', 500);
        }
    }

    /**
     * Recupera la configurazione degli account Instagram per le scuole.
     * Permessi: 'instagram.configure_own_school_account' (per la propria scuola)
     * 'instagram.configure_all_school_accounts' (per tutte le scuole)
     * Dati richiesti da JSON (opzionale): 'school_id' (int)
     *
     * @return void
     */
    public function getSchoolInstagramAccounts(): void
    {
        if (!$this->authenticateRequest()) return;

        $conn = Connection::get();
        $accounts = [];

        // Verifica permessi
        $canConfigureAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_all_school_accounts');
        $canConfigureOwn = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_own_school_account');

        if (!$canConfigureAll && !$canConfigureOwn) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare le configurazioni degli account Instagram.', 403);
            return;
        }

        $sql = "SELECT sia.instagram_account_id, sia.school_id, s.name as school_name, sia.created_at, sia.updated_at
                FROM school_instagram_accounts sia
                JOIN schools s ON sia.school_id = s.id";
        $params = [];
        $types = '';
        $whereClauses = [];

        $requestedSchoolId = $this->requestData['school_id'] ?? null;
        if ($requestedSchoolId !== null) {
            $requestedSchoolId = (int)$requestedSchoolId;
            $whereClauses[] = "sia.school_id = ?";
            $params[] = $requestedSchoolId;
            $types .= 'i';
        }

        // Filtro per school_id se l'utente ha solo permessi per la propria scuola
        if (!$canConfigureAll && $canConfigureOwn && $this->currentUserSchoolId !== null) {
            // Se l'utente ha richiesto una scuola diversa dalla sua, e non ha permessi per tutte, nega l'accesso.
            if ($requestedSchoolId !== null && $requestedSchoolId !== $this->currentUserSchoolId) {
                $this->error('Accesso negato: Non puoi visualizzare le configurazioni Instagram di altre scuole.', 403);
                return;
            }
            $whereClauses[] = "sia.school_id = ?";
            $params[] = $this->currentUserSchoolId;
            $types .= 'i';
        } elseif (!$canConfigureAll && !$canConfigureOwn) {
            $this->json([], 200); // Nessun permesso, restituisci vuoto
            return;
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $sql .= " ORDER BY sia.school_id, sia.instagram_account_id ASC";

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
            $accounts[] = $row;
        }
        $stmt->close();

        $this->json($accounts, 200);
    }

    /**
     * Elimina la configurazione di un account Instagram per una scuola.
     * Permessi: 'instagram.configure_own_school_account' (per la propria scuola)
     * 'instagram.configure_all_school_accounts' (per qualsiasi scuola)
     * Dati richiesti da JSON:
     * - instagram_account_id (string)
     *
     * @return void
     */
    public function deleteSchoolInstagramAccount(): void
    {
        if (!$this->authenticateRequest()) return;

        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $instagramAccountIdToDelete = $this->requestData['instagram_account_id'] ?? null;

            if (empty($instagramAccountIdToDelete)) {
                $this->error('ID dell\'account Instagram da eliminare mancante.', 400);
                $conn->rollback();
                return;
            }

            // Recupera la school_id associata all'account Instagram
            $targetSchoolId = null;
            $stmt_get_school_id = $conn->prepare("SELECT school_id FROM school_instagram_accounts WHERE instagram_account_id = ? LIMIT 1;");
            $stmt_get_school_id->bind_param('s', $instagramAccountIdToDelete);
            $stmt_get_school_id->execute();
            $stmt_get_school_id->bind_result($targetSchoolId);
            $stmt_get_school_id->fetch();
            $stmt_get_school_id->close();

            if ($targetSchoolId === null) {
                $this->error('Configurazione account Instagram non trovata.', 404);
                $conn->rollback();
                return;
            }

            // Verifica permessi
            $canConfigureAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_all_school_accounts');
            $canConfigureOwn = $this->permissionChecker->userHasPermission($this->currentUserId, 'instagram.configure_own_school_account');

            if (!$canConfigureAll && !($canConfigureOwn && $this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId)) {
                $this->error('Accesso negato: Permessi insufficienti per eliminare questa configurazione dell\'account Instagram.', 403);
                $conn->rollback();
                return;
            }

            // Elimina la configurazione
            $stmt_delete = $conn->prepare("DELETE FROM school_instagram_accounts WHERE instagram_account_id = ?;");
            $stmt_delete->bind_param('s', $instagramAccountIdToDelete);

            if (!$stmt_delete->execute()) {
                throw new \Exception('Errore durante l\'eliminazione della configurazione dell\'account Instagram: ' . $conn->error);
            }
            $stmt_delete->close();

            $conn->commit();
            $this->json(['message' => 'Configurazione account Instagram eliminata con successo.'], 200);

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Errore in deleteSchoolInstagramAccount: " . $e->getMessage());
            $this->error('Errore interno del server durante l\'eliminazione della configurazione dell\'account Instagram.', 500);
        }
    }
}
