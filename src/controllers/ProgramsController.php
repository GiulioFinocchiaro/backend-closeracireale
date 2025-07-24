<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Dotenv\Dotenv;

class ProgramsController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null; // ID dell'utente autenticato (INT)
    private ?int $currentUserSchoolId = null; // school_id dell'utente autenticato
    private array $requestData; // Per i dati JSON in input

    public function __construct()
    {
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        $this->authMiddleware = new AuthMiddleware();
        $dbConnection = Connection::get();

        if ($dbConnection === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }
        $this->permissionChecker = new RoleChecker();

        $dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
        $dotenv->load();

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
     * Crea un nuovo programma.
     * Permessi: 'programs.create'
     * Dati richiesti da JSON: 'campaign_id', 'name', 'description', 'generated_by_ai' (opzionale, default false)
     *
     * @return void
     */
    public function addProgram(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'programs.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare programmi.', 403);
            return;
        }

        $conn = Connection::get();

        // 1. Recupera e valida i dati
        if (!isset($this->requestData['campaign_id']) || !isset($this->requestData['name']) || !isset($this->requestData['description'])) {
            $this->error('Dati mancanti: campaign_id, name e description sono obbligatori.', 400);
            return;
        }

        $campaignId = (int)$this->requestData['campaign_id'];
        $name = trim($this->requestData['name']);
        $description = trim($this->requestData['description']);
        // Recupera il flag generated_by_ai, default a false se non fornito
        $generatedByAi = isset($this->requestData['generated_by_ai']) ? (bool)$this->requestData['generated_by_ai'] : false;


        if (empty($name) || empty($description)) {
            $this->error('Nome e descrizione non possono essere vuoti.', 400);
            return;
        }

        // 2. Verifica che la campagna esista e recupera la sua school_id
        $campaignSchoolId = null;
        $stmt_check_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_check_campaign->bind_param('i', $campaignId);
        $stmt_check_campaign->execute();
        $stmt_check_campaign->bind_result($campaignSchoolId);
        $stmt_check_campaign->fetch();
        $stmt_check_campaign->close();

        if ($campaignSchoolId === null) {
            $this->error('Campagna specificata non trovata.', 404);
            return;
        }

        // 3. Verifica permessi basati sulla scuola della campagna
        $canCreateAllPrograms = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.create_all'); // Assumi un permesso più generico
        $canCreateOwnSchoolPrograms = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.create_own_school');

        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $campaignSchoolId);

        if (!$canCreateAllPrograms && !($canCreateOwnSchoolPrograms && $isOwnSchoolCampaign)) {
            $this->error('Accesso negato: Permessi insufficienti per creare programmi per questa campagna (non nella tua scuola).', 403);
            return;
        }

        // 4. Inserisci il nuovo programma
        $stmt_insert = $conn->prepare("INSERT INTO programs (campaign_id, name, description, generated_by_ai, created_at, school_id) VALUES (?, ?, ?, ?, NOW(), ?);");
        // Nota l'aggiunta di 'b' per il boolean (MySQL tratta BOOLEAN come TINYINT(1))
        $stmt_insert->bind_param('isbsi', $campaignId, $name, $description, $generatedByAi, $campaignSchoolId);

        if (!$stmt_insert->execute()) {
            $this->error('Errore durante la creazione del programma: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }

        $newProgramId = $conn->insert_id;
        $stmt_insert->close();

        // Recupera il programma appena creato per la risposta
        $stmt_fetch = $conn->prepare("SELECT id, campaign_id, name, description, generated_by_ai, created_at, school_id FROM programs WHERE id = ? LIMIT 1;");
        $stmt_fetch->bind_param('i', $newProgramId);
        $stmt_fetch->execute();
        $result = $stmt_fetch->get_result();
        $newProgram = $result->fetch_assoc();
        $stmt_fetch->close();

        // Assicurati che il valore booleano venga restituito correttamente come boolean
        if ($newProgram) {
            $newProgram['generated_by_ai'] = (bool)$newProgram['generated_by_ai'];
        }

        $this->json(['message' => 'Programma creato con successo.', 'program' => $newProgram], 201);
    }

    /**
     * Visualizza i dettagli di un singolo programma.
     * L'ID del programma da visualizzare è preso da $this->requestData['id'].
     * Permessi: 'programs.view_all', 'programs.view_own_school'
     *
     * @return void
     */
    public function getSingleProgram(): void
    {
        $conn = Connection::get();

        $programId = $this->requestData['id'] ?? null;
        if ($programId === null) {
            $this->error('ID programma da visualizzare mancante nella richiesta.', 400);
            return;
        }
        $programId = (int)$programId;

        // 1. Recupera i dettagli del programma target (campaign_id, school_id)
        $targetCampaignId = null;
        $targetSchoolId = null;
        $stmt_target_program = $conn->prepare("SELECT campaign_id, school_id FROM programs WHERE id = ? LIMIT 1;");
        $stmt_target_program->bind_param('i', $programId);
        $stmt_target_program->execute();
        $stmt_target_program->bind_result($targetCampaignId, $targetSchoolId);
        $stmt_target_program->fetch();
        $stmt_target_program->close();

        if ($targetCampaignId === null) { // Programma non trovato
            $this->error('Programma non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.view_own_school');

        $isOwnSchoolProgram = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        if (!$canViewAll && !($canViewOwnSchool && $isOwnSchoolProgram)) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare questo programma.', 403);
            return;
        }

        // 3. Recupera tutti i dettagli del programma, inclusa la nuova colonna
        $stmt = $conn->prepare("SELECT id, campaign_id, name, description, generated_by_ai, created_at, school_id FROM programs WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $programId);
        $stmt->execute();
        $result = $stmt->get_result();
        $program = $result->fetch_assoc();
        $stmt->close();

        // Assicurati che il valore booleano venga restituito correttamente come boolean
        if ($program) {
            $program['generated_by_ai'] = (bool)$program['generated_by_ai'];
        }

        $this->json($program, 200);
    }

    /**
     * Visualizza un elenco di programmi.
     * Questo metodo può filtrare per campaign_id (se fornito nel JSON).
     * Permessi: 'programs.view_all', 'programs.view_own_school'
     *
     * @return void
     */
    public function getPrograms(): void
    {
        $conn = Connection::get();
        $programs = [];

        // Verifica permessi
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.view_own_school');

        if (!$canViewAll && !$canViewOwnSchool) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare programmi.', 403);
            return;
        }

        // Includi la nuova colonna nella SELECT
        $sql = "SELECT id, campaign_id, name, description, generated_by_ai, created_at, school_id FROM programs";
        $params = [];
        $types = '';
        $whereClauses = [];

        // Filtro per campaign_id se fornito nel JSON
        $requestedCampaignId = $this->requestData['campaign_id'] ?? null;
        if ($requestedCampaignId !== null) {
            $requestedCampaignId = (int)$requestedCampaignId;

            // Verifica che la campagna esista e che l'utente abbia i permessi per visualizzare i programmi di quella campagna
            $targetCampaignSchoolId = null;
            $stmt_check_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
            $stmt_check_campaign->bind_param('i', $requestedCampaignId);
            $stmt_check_campaign->execute();
            $stmt_check_campaign->bind_result($targetCampaignSchoolId);
            $stmt_check_campaign->fetch();
            $stmt_check_campaign->close();

            if ($targetCampaignSchoolId === null) {
                $this->error('Campagna specificata per il filtro non trovata.', 404);
                return;
            }

            $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetCampaignSchoolId);

            if (!$canViewAll && !($canViewOwnSchool && $isOwnSchoolCampaign)) {
                $this->error('Accesso negato: Permessi insufficienti per visualizzare programmi di questa campagna.', 403);
                return;
            }

            $whereClauses[] = "campaign_id = ?";
            $params[] = $requestedCampaignId;
            $types .= 'i';
        }

        // Filtro per school_id se l'utente ha solo permessi di visualizzazione per la propria scuola
        if (!$canViewAll && $canViewOwnSchool && $this->currentUserSchoolId !== null) {
            $whereClauses[] = "school_id = ?";
            $params[] = $this->currentUserSchoolId;
            $types .= 'i';
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $sql .= " ORDER BY created_at DESC";

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
            // Assicurati che il valore booleano venga restituito correttamente come boolean
            $row['generated_by_ai'] = (bool)$row['generated_by_ai'];
            $programs[] = $row;
        }
        $stmt->close();

        $this->json($programs, 200);
    }

    /**
     * Modifica i dati di un programma esistente.
     * L'ID del programma da modificare è preso da $this->requestData['id'].
     * Permessi: 'programs.update_all', 'programs.update_own_school'
     * Dati modificabili da JSON: 'name', 'description', 'generated_by_ai' (opzionale)
     *
     * @return void
     */
    public function updateProgram(): void
    {
        $conn = Connection::get();

        $programIdToModify = $this->requestData['id'] ?? null;
        if ($programIdToModify === null) {
            $this->error('ID programma da modificare mancante nella richiesta.', 400);
            return;
        }
        $programIdToModify = (int)$programIdToModify;

        // Recupera i dettagli del programma target (school_id)
        $targetSchoolId = null;
        $stmt_target_program = $conn->prepare("SELECT school_id FROM programs WHERE id = ? LIMIT 1;");
        $stmt_target_program->bind_param('i', $programIdToModify);
        $stmt_target_program->execute();
        $stmt_target_program->bind_result($targetSchoolId);
        $stmt_target_program->fetch();
        $stmt_target_program->close();

        if ($targetSchoolId === null) { // Programma non trovato
            $this->error('Programma da modificare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        $canUpdateAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.update_all');
        $canUpdateOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.update_own_school');

        $isOwnSchoolProgram = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        if (!$canUpdateAll && !($canUpdateOwnSchool && $isOwnSchoolProgram)) {
            $this->error('Accesso negato: Permessi insufficienti per modificare questo programma.', 403);
            return;
        }

        // 3. Recupera e valida i dati per la modifica
        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['name'])) {
            $name = trim($this->requestData['name']);
            if (empty($name)) {
                $this->error('Il nome non può essere vuoto.', 400);
                return;
            }
            $updateFields[] = "name = ?";
            $bindParams[] = $name;
            $types .= 's';
        }
        if (isset($this->requestData['description'])) {
            $description = trim($this->requestData['description']);
            if (empty($description)) {
                $this->error('La descrizione non può essere vuota.', 400);
                return;
            }
            $updateFields[] = "description = ?";
            $bindParams[] = $description;
            $types .= 's';
        }
        // Aggiungi la gestione per generated_by_ai
        if (isset($this->requestData['generated_by_ai'])) {
            $generatedByAi = (bool)$this->requestData['generated_by_ai'];
            $updateFields[] = "generated_by_ai = ?";
            $bindParams[] = $generatedByAi;
            $types .= 'b'; // 'b' per boolean (o 'i' se MySQL lo tratta come TINYINT(1))
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
            return;
        }

        // Costruisci ed esegui la query di aggiornamento
        $sql = "UPDATE programs SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);

        $bindParams[] = $programIdToModify;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore durante l\'aggiornamento del programma: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Programma aggiornato con successo.'], 200);
    }

    /**
     * Elimina un programma.
     * L'ID del programma da eliminare è preso da $this->requestData['id'].
     * Permessi: 'programs.delete_all', 'programs.delete_own_school'
     *
     * @return void
     */
    public function deleteProgram(): void
    {
        $conn = Connection::get();

        $programIdToDelete = $this->requestData['id'] ?? null;
        if ($programIdToDelete === null) {
            $this->error('ID programma da eliminare mancante nella richiesta.', 400);
            return;
        }
        $programIdToDelete = (int)$programIdToDelete;

        // Recupera i dettagli del programma target (school_id)
        $targetSchoolId = null;
        $stmt_target_program = $conn->prepare("SELECT school_id FROM programs WHERE id = ? LIMIT 1;");
        $stmt_target_program->bind_param('i', $programIdToDelete);
        $stmt_target_program->execute();
        $stmt_target_program->bind_result($targetSchoolId);
        $stmt_target_program->fetch();
        $stmt_target_program->close();

        if ($targetSchoolId === null) { // Programma non trovato
            $this->error('Programma da eliminare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        $canDeleteAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.delete_all');
        $canDeleteOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'programs.delete_own_school');

        $isOwnSchoolProgram = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        if (!$canDeleteAll && !($canDeleteOwnSchool && $isOwnSchoolProgram)) {
            $this->error('Accesso negato: Permessi insufficienti per eliminare questo programma.', 403);
            return;
        }

        // 3. Eliminazione del programma
        $stmt_delete = $conn->prepare("DELETE FROM programs WHERE id = ?;");
        $stmt_delete->bind_param('i', $programIdToDelete);

        if (!$stmt_delete->execute()) {
            $this->error('Errore durante l\'eliminazione del programma: ' . $conn->error, 500);
            $stmt_delete->close();
            return;
        }
        $stmt_delete->close();

        $this->json(['message' => 'Programma eliminato con successo.'], 200);
    }

    /**
     * Genera una descrizione per un programma utilizzando l'API di Gemini.
     * Permessi: 'ai_generation.program_description'
     * Dati richiesti da JSON: 'prompt'
     *
     * @return void
     */
    public function generateProgramDescriptionAI(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'ai_generation.program_description')) {
            $this->error('Accesso negato: Permessi insufficienti per generare descrizioni AI.', 403);
            return;
        }

        // Recupera il prompt dalla richiesta
        $prompt = $this->requestData['prompt'] ?? null;
        if (empty($prompt)) {
            $this->error('Prompt mancante per la generazione AI.', 400);
            return;
        }

        // Costruisci il payload per l'API di Gemini
        $chatHistory = [
            ['role' => 'user', 'parts' => [['text' => $prompt]]]
        ];

        $payload = [
            'contents' => $chatHistory
        ];

        $apiKey = $_ENV["GEMINI_API_KEY"];

        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

        // Inizializza cURL
        $ch = curl_init($apiUrl);

        // Imposta le opzioni di cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Restituisce il trasferimento come stringa del risultato
        curl_setopt($ch, CURLOPT_POST, true); // Imposta la richiesta come POST
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); // Imposta i dati POST
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        // Esegui la richiesta cURL
        $response = curl_exec($ch);

        // Gestione degli errori cURL
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            error_log("Errore cURL durante la chiamata all'API Gemini: " . $error_msg);
            $this->error('Errore di connessione con il servizio di generazione AI.', 500);
            return;
        }

        // Chiudi la sessione cURL
        curl_close($ch);

        // Decodifica la risposta JSON
        $result = json_decode($response, true);

        // Verifica la struttura della risposta e estrai il testo generato
        if (isset($result['candidates']) && count($result['candidates']) > 0 &&
            isset($result['candidates'][0]['content']) && isset($result['candidates'][0]['content']['parts']) &&
            count($result['candidates'][0]['content']['parts']) > 0 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {

            $generatedText = $result['candidates'][0]['content']['parts'][0]['text'];
            $this->json(['message' => 'Descrizione generata con successo.', 'generated_text' => $generatedText], 200);

        } else {
            // Gestisci casi in cui la struttura della risposta è inaspettata o il contenuto manca
            error_log("Risposta inaspettata dall'API Gemini: " . $response);
            $this->error('Impossibile generare la descrizione. Risposta non valida dal servizio AI.', 500);
        }
    }
}
