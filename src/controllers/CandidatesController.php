<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class CandidatesController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null;
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

        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = $decodedToken->sub;

            // Recupera la school_id dell'utente corrente all'avvio del controller
            $stmt = $dbConnection->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
            $stmt->bind_param('i', $this->currentUserId);
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
     * Crea un nuovo profilo candidato.
     * Permessi: 'candidates.create' (per l'amministratore che crea)
     * 'users.can_be_candidate' (per l'utente che diventa candidato)
     * Dati richiesti da JSON: 'user_id', 'class_year', 'description', 'photo', 'manifesto'
     *
     * @return void
     */
    public function addCandidate(): void
    {
        // Verifica permesso per l'amministratore che esegue l'azione
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare candidati.', 403);
            return;
        }

        $conn = Connection::get();

        // 1. Recupera e valida i dati
        if (!isset($this->requestData['user_id']) || !isset($this->requestData['class_year']) || !isset($this->requestData['description'])) {
            $this->error('Dati mancanti: user_id, class_year e description sono obbligatori.', 400);
            return;
        }

        $userId = (int)$this->requestData['user_id'];
        $classYear = trim($this->requestData['class_year']);
        $description = trim($this->requestData['description']);
        $photo = $this->requestData['photo'] ?? null; // URL o percorso
        $manifesto = $this->requestData['manifesto'] ?? null; // URL o percorso

        if (empty($classYear) || empty($description)) {
            $this->error('class_year e description non possono essere vuoti.', 400);
            return;
        }

        // Verifica che l'utente esista e recupera la sua school_id
        $targetUserSchoolId = null;
        $stmt_check_user = $conn->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
        $stmt_check_user->bind_param('i', $userId);
        $stmt_check_user->execute();
        $stmt_check_user->bind_result($targetUserSchoolId);
        $stmt_check_user->fetch();
        $stmt_check_user->close();

        if ($targetUserSchoolId === null) {
            $this->error('L\'utente specificato per il candidato non esiste.', 404);
            return;
        }

        // NUOVA VERIFICA: L'utente che sta per diventare candidato deve avere il permesso 'users.can_be_candidate'
        if (!$this->permissionChecker->userHasPermission($userId, 'users.can_be_candidate')) {
            $this->error('L\'utente specificato non ha i permessi per essere un candidato (users.can_be_candidate).', 403);
            return;
        }

        // Verifica che non esista già un profilo candidato per questo utente
        $stmt_check_candidate = $conn->prepare("SELECT id FROM candidates WHERE user_id = ? LIMIT 1;");
        $stmt_check_candidate->bind_param('i', $userId);
        $stmt_check_candidate->execute();
        $stmt_check_candidate->store_result();
        if ($stmt_check_candidate->num_rows > 0) {
            $stmt_check_candidate->close();
            $this->error('Esiste già un profilo candidato per questo utente.', 409);
            return;
        }
        $stmt_check_candidate->close();

        // Inserisci il nuovo candidato
        $stmt_insert = $conn->prepare("INSERT INTO candidates (user_id, class_year, description, photo, manifesto, school_id) VALUES (?, ?, ?, ?, ?, ?);");
        $stmt_insert->bind_param('issssi', $userId, $classYear, $description, $photo, $manifesto, $targetUserSchoolId);

        if (!$stmt_insert->execute()) {
            $this->error('Errore durante la creazione del candidato: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }

        $newCandidateId = $conn->insert_id;
        $stmt_insert->close();

        $this->json(['message' => 'Candidato creato con successo.', 'candidate_id' => $newCandidateId], 201);
    }

    /**
     * Visualizza i dettagli di un singolo candidato.
     * L'ID del candidato da visualizzare è preso da $this->requestData['id'].
     * Permessi:
     * - 'candidates.view_all' (visualizza qualsiasi candidato)
     * - 'candidates.view_own_school' (visualizza candidati della propria scuola)
     * - 'candidates.view_own_profile' (visualizza il proprio profilo candidato)
     *
     * @return void
     */
    public function getSingleCandidate(): void
    {
        $conn = Connection::get();

        $candidateId = $this->requestData['id'] ?? null;
        if ($candidateId === null) {
            $this->error('ID candidato da visualizzare mancante nella richiesta.', 400);
            return;
        }

        // Recupera i dettagli del candidato target (user_id, school_id)
        $targetCandidateUserId = null;
        $targetCandidateSchoolId = null;
        $stmt_target_candidate = $conn->prepare("SELECT user_id, school_id FROM candidates WHERE id = ? LIMIT 1;");
        $stmt_target_candidate->bind_param('i', $candidateId);
        $stmt_target_candidate->execute();
        $stmt_target_candidate->bind_result($targetCandidateUserId, $targetCandidateSchoolId);
        $stmt_target_candidate->fetch();
        $stmt_target_candidate->close();

        if ($targetCandidateUserId === null) { // Candidato non trovato
            $this->error('Candidato non trovato.', 404);
            return;
        }

        // Verifica permessi
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_own_school');
        $canViewOwnProfile = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_own_profile');

        $isOwnSchoolCandidate = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetCandidateSchoolId);
        $isOwnProfile = ($this->currentUserId === $targetCandidateUserId);

        if (!$canViewAll && !($canViewOwnSchool && $isOwnSchoolCandidate) && !($canViewOwnProfile && $isOwnProfile)) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare questo candidato.', 403);
            return;
        }

        // Recupera tutti i dettagli del candidato
        $stmt = $conn->prepare("SELECT id, user_id, class_year, description, photo, manifesto, created_at, school_id FROM candidates WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $candidateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $candidate = $result->fetch_assoc();
        $stmt->close();

        $this->json($candidate, 200);
    }

    /**
     * Visualizza un elenco di candidati (tutti, della propria scuola o il proprio profilo).
     * Permessi:
     * - 'candidates.view_all' (visualizza tutti i candidati)
     * - 'candidates.view_own_school' (visualizza candidati della propria scuola)
     * - 'candidates.view_own_profile' (visualizza il proprio profilo candidato)
     *
     * @return void
     */
    public function getAllCandidates(): void
    {
        $conn = Connection::get();
        $candidates = [];

        // Verifica permessi
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_own_school');
        $canViewOwnProfile = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.view_own_profile');

        if (!$canViewAll && !$canViewOwnSchool && !$canViewOwnProfile) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare candidati.', 403);
            return;
        }

        $sql = "SELECT id, user_id, class_year, description, photo, manifesto, created_at, school_id FROM candidates";
        $params = [];
        $types = '';
        $whereClauses = [];

        // Filtra per la propria scuola se non può vedere tutti i candidati ma può vedere quelli della propria scuola
        if (!$canViewAll && $canViewOwnSchool && $this->currentUserSchoolId !== null) {
            $whereClauses[] = "school_id = ?";
            $params[] = $this->currentUserSchoolId;
            $types .= 'i';
        }

        // Filtra per il proprio profilo se non può vedere tutti/propria scuola ma può vedere il proprio profilo
        // E se non è già stato incluso da un filtro più ampio (es. view_own_school)
        if (!$canViewAll && !$canViewOwnSchool && $canViewOwnProfile) {
            // Se l'utente ha solo il permesso di vedere il proprio profilo, e non è già coperto da view_own_school
            // Aggiungiamo esplicitamente il filtro per il proprio user_id
            $whereClauses[] = "user_id = ?";
            $params[] = $this->currentUserId;
            $types .= 'i';
        }
        // Nota: Se un utente ha sia view_own_school che view_own_profile, e la sua scuola ha altri candidati,
        // la logica attuale darà priorità a view_own_school se non view_all. Potrebbe essere necessario affinare.
        // Per ora, se c'è un filtro per school_id, il filtro user_id aggiuntivo non è strettamente necessario
        // a meno che non si voglia escludere altri candidati della stessa scuola.

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

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
            $candidates[] = $row;
        }
        $stmt->close();

        $this->json($candidates, 200);
    }

    /**
     * Modifica i dati di un profilo candidato esistente.
     * L'ID del candidato da modificare è preso da $this->requestData['id'].
     * Permessi:
     * - 'candidates.update_all' (modifica qualsiasi candidato)
     * - 'candidates.update_own_school' (modifica candidati della propria scuola)
     * - 'candidates.update_own_profile' (modifica il proprio profilo candidato)
     *
     * @return void
     */
    public function updateCandidate(): void
    {
        $conn = Connection::get();

        $candidateIdToModify = $this->requestData['id'] ?? null;
        if ($candidateIdToModify === null) {
            $this->error('ID candidato da modificare mancante nella richiesta.', 400);
            return;
        }

        // Recupera i dettagli del candidato target (user_id, school_id)
        $targetCandidateUserId = null;
        $targetCandidateSchoolId = null;
        $stmt_target_candidate = $conn->prepare("SELECT user_id, school_id FROM candidates WHERE id = ? LIMIT 1;");
        $stmt_target_candidate->bind_param('i', $candidateIdToModify);
        $stmt_target_candidate->execute();
        $stmt_target_candidate->bind_result($targetCandidateUserId, $targetCandidateSchoolId);
        $stmt_target_candidate->fetch();
        $stmt_target_candidate->close();

        if ($targetCandidateUserId === null) { // Candidato non trovato
            $this->error('Candidato da modificare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        $canUpdateAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.update_all');
        $canUpdateOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.update_own_school');
        $canUpdateOwnProfile = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.update_own_profile');

        $isOwnSchoolCandidate = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetCandidateSchoolId);
        $isOwnProfile = ($this->currentUserId === $targetCandidateUserId);

        if (!$canUpdateAll && !($canUpdateOwnSchool && $isOwnSchoolCandidate) && !($canUpdateOwnProfile && $isOwnProfile)) {
            $this->error('Accesso negato: Permessi insufficienti per modificare questo candidato.', 403);
            return;
        }

        // 3. Recupera e valida i dati per la modifica
        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['class_year'])) {
            $classYear = trim($this->requestData['class_year']);
            if (empty($classYear)) {
                $this->error('class_year non può essere vuoto.', 400);
                return;
            }
            $updateFields[] = "class_year = ?";
            $bindParams[] = $classYear;
            $types .= 's';
        }
        if (isset($this->requestData['description'])) {
            $description = trim($this->requestData['description']);
            if (empty($description)) {
                $this->error('description non può essere vuoto.', 400);
                return;
            }
            $updateFields[] = "description = ?";
            $bindParams[] = $description;
            $types .= 's';
        }
        if (isset($this->requestData['photo'])) {
            $photo = $this->requestData['photo'];
            $updateFields[] = "photo = ?";
            $bindParams[] = $photo;
            $types .= 's';
        }
        if (isset($this->requestData['manifesto'])) {
            $manifesto = $this->requestData['manifesto'];
            $updateFields[] = "manifesto = ?";
            $bindParams[] = $manifesto;
            $types .= 's';
        }
        // Nota: user_id e school_id non dovrebbero essere modificabili direttamente tramite questo endpoint
        // La user_id è la FK all'utente, la school_id è derivata dall'utente.
        // Se si vuole cambiare l'utente associato, si dovrebbe eliminare e ricreare il candidato.

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
            return;
        }

        // Costruisci ed esegui la query di aggiornamento
        $sql = "UPDATE candidates SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);

        $bindParams[] = $candidateIdToModify;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore durante l\'aggiornamento del candidato: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Candidato aggiornato con successo.'], 200);
    }

    /**
     * Elimina un profilo candidato.
     * L'ID del candidato da eliminare è preso da $this->requestData['id'].
     * Permessi:
     * - 'candidates.delete_all' (elimina qualsiasi candidato)
     * - 'candidates.delete_own_school' (elimina candidati della propria scuola)
     *
     * @return void
     */
    public function deleteCandidate(): void
    {
        $conn = Connection::get();

        $candidateIdToDelete = $this->requestData['id'] ?? null;
        if ($candidateIdToDelete === null) {
            $this->error('ID candidato da eliminare mancante nella richiesta.', 400);
            return;
        }

        // Recupera i dettagli del candidato target (user_id, school_id)
        $targetCandidateUserId = null;
        $targetCandidateSchoolId = null;
        $stmt_target_candidate = $conn->prepare("SELECT user_id, school_id FROM candidates WHERE id = ? LIMIT 1;");
        $stmt_target_candidate->bind_param('i', $candidateIdToDelete);
        $stmt_target_candidate->execute();
        $stmt_target_candidate->bind_result($targetCandidateUserId, $targetCandidateSchoolId);
        $stmt_target_candidate->fetch();
        $stmt_target_candidate->close();

        if ($targetCandidateUserId === null) { // Candidato non trovato
            $this->error('Candidato da eliminare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        $canDeleteAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.delete_all');
        $canDeleteOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'candidates.delete_own_school');

        $isOwnSchoolCandidate = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetCandidateSchoolId);

        if (!$canDeleteAll && !($canDeleteOwnSchool && $isOwnSchoolCandidate)) {
            $this->error('Accesso negato: Permessi insufficienti per eliminare questo candidato o candidato non nella tua scuola.', 403);
            return;
        }

        // 3. Eliminazione del candidato
        $stmt_delete = $conn->prepare("DELETE FROM candidates WHERE id = ?;");
        $stmt_delete->bind_param('i', $candidateIdToDelete);

        if (!$stmt_delete->execute()) {
            $this->error('Errore durante l\'eliminazione del candidato: ' . $conn->error, 500);
            $stmt_delete->close();
            return;
        }
        $stmt_delete->close();

        $this->json(['message' => 'Candidato eliminato con successo.'], 200);
    }
}
