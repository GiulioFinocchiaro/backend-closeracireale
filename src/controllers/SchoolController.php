<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Helpers\Mail;

class SchoolController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null;
    private ?int $currentUserSchoolId = null;
    private array $requestData;
    private String $email;
    private String $name;

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
            $this->email = $decodedToken->email;
            $this->name = $decodedToken->name;

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
     * Aggiunge una nuova scuola.
     * Permessi: 'schools.create'
     * Dati richiesti da JSON: 'school_name', 'list_name'
     *
     * @return void
     */
    public function addSchool(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'schools.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare scuole.', 403);
            return;
        }

        $conn = Connection::get();

        // 1. Recupera e valida i dati (aggiunto display_name)
        if (!isset($this->requestData['school_name']) || !isset($this->requestData['list_name'])) {
            $this->error('Dati mancanti: school_name e list_name sono obbligatori.', 400);
            return;
        }

        $schoolName = trim($this->requestData['school_name']);
        $listName = trim($this->requestData['list_name']);

        if (empty($schoolName) || empty($listName)) {
            $this->error('school_name e list_name non possono essere vuoti.', 400);
            return;
        }

        // 2. Verifica unicità school_name
        $stmt_check = $conn->prepare("SELECT id FROM schools WHERE school_name = ? AND list_name = ? LIMIT 1;");
        $stmt_check->bind_param('ss', $schoolName, $listName);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $stmt_check->close();
            $this->error('Nome scuola (identificativo) già esistente.', 409);
            return;
        }
        $stmt_check->close();

        // 3. Inserisci la nuova scuola
        $stmt_insert = $conn->prepare("INSERT INTO schools (school_name, list_name) VALUES (?, ?);");
        $stmt_insert->bind_param('ss', $schoolName, $listName);

        if (!$stmt_insert->execute()) {
            $this->error('Errore durante l\'aggiunta della scuola: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }

        $newSchoolId = $conn->insert_id;
        $stmt_insert->close();

        try {
            $current_admin_name = $this->name;
            $school_name = $schoolName;
            $school_list_name = $listName;

            ob_start();
            require_once __DIR__ . "/../../templates/email/create_new_school.php";
            $email_html_body = ob_get_clean();

            Mail::sendMail(
                $this->email,
                "Hai creato una nuovo scuola",
                $email_html_body
            );
        } catch (\Exception $e){
            error_log("Errore nell'invio dell'email della creazione di una nuova scuola ". $e->getMessage());
        }

        $this->json(['message' => 'Scuola aggiunta con successo.', 'school_id' => $newSchoolId], 201);
    }

    /**
     * Modifica i dati di una scuola esistente.
     * L'ID della scuola da modificare è preso da $this->requestData['id'].
     * Permessi: 'schools.update'
     * Dati modificabili da JSON: 'school_name', 'display_name', 'list_name'
     *
     * @return void
     */
    public function updateSchool(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'schools.update')) {
            $this->error('Accesso negato: Permessi insufficienti per modificare le scuole.', 403);
            return;
        }

        $conn = Connection::get();

        $schoolIdToModify = $this->requestData['id'] ?? null;
        if ($schoolIdToModify === null) {
            $this->error('ID scuola da modificare mancante nella richiesta.', 400);
            return;
        }

        // Verifica che la scuola esista
        $stmt_check_school = $conn->prepare("SELECT 1 FROM schools WHERE id = ? LIMIT 1;");
        $stmt_check_school->bind_param('i', $schoolIdToModify);
        $stmt_check_school->execute();
        $stmt_check_school->store_result();
        if ($stmt_check_school->num_rows === 0) {
            $stmt_check_school->close();
            $this->error('Scuola non trovata.', 404);
            return;
        }
        $stmt_check_school->close();

        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['school_name'])) {
            $schoolName = trim($this->requestData['school_name']);
            if (empty($schoolName)) {
                $this->error('school_name non può essere vuoto.', 400);
                return;
            }
            // Verifica unicità del nome se modificato
            $stmt_check_name = $conn->prepare("SELECT id FROM schools WHERE school_name = ? AND id != ? LIMIT 1;");
            $stmt_check_name->bind_param('si', $schoolName, $schoolIdToModify);
            $stmt_check_name->execute();
            $stmt_check_name->store_result();
            if ($stmt_check_name->num_rows > 0) {
                $stmt_check_name->close();
                $this->error('Il nuovo nome scuola (identificativo) è già registrato per un\'altra scuola.', 409);
                return;
            }
            $stmt_check_name->close();

            $updateFields[] = "school_name = ?";
            $bindParams[] = $schoolName;
            $types .= 's';
        }
        if (isset($this->requestData['list_name'])) {
            $listName = trim($this->requestData['list_name']);
            if (empty($listName)) {
                $this->error('list_name non può essere vuoto.', 400);
                return;
            }
            $updateFields[] = "list_name = ?";
            $bindParams[] = $listName;
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
            return;
        }

        // Costruisci ed esegui la query di aggiornamento
        $sql = "UPDATE schools SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);

        $bindParams[] = $schoolIdToModify;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore durante l\'aggiornamento della scuola: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Scuola aggiornata con successo.'], 200);
    }

    /**
     * Elimina una scuola.
     * L'ID della scuola da eliminare è preso da $this->requestData['id'].
     * Permessi: 'schools.delete'
     *
     * @return void
     */
    public function deleteSchool(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'schools.delete')) {
            $this->error('Accesso negato: Permessi insufficienti per eliminare scuole.', 403);
            return;
        }

        $conn = Connection::get();

        $schoolIdToDelete = $this->requestData['id'] ?? null;
        if ($schoolIdToDelete === null) {
            $this->error('ID scuola da eliminare mancante nella richiesta.', 400);
            return;
        }

        // Verifica che la scuola esista
        $stmt_check_school = $conn->prepare("SELECT 1 FROM schools WHERE id = ? LIMIT 1;");
        $stmt_check_school->bind_param('i', $schoolIdToDelete);
        $stmt_check_school->execute();
        $stmt_check_school->store_result();
        if ($stmt_check_school->num_rows === 0) {
            $stmt_check_school->close();
            $this->error('Scuola non trovata.', 404);
            return;
        }
        $stmt_check_school->close();

        // Implementa la logica per gestire le chiavi esterne (es. utenti associati)
        // Ho mantenuto la logica di SET NULL per school_id degli utenti.
        $conn->begin_transaction();
        try {
            $stmt_update_users = $conn->prepare("UPDATE users SET school_id = NULL WHERE school_id = ?;");
            $stmt_update_users->bind_param('i', $schoolIdToDelete);
            $stmt_update_users->execute();
            $stmt_update_users->close();

            // Ora elimina la scuola
            $stmt_delete = $conn->prepare("DELETE FROM schools WHERE id = ?;");
            $stmt_delete->bind_param('i', $schoolIdToDelete);

            if (!$stmt_delete->execute()) {
                throw new \Exception('Errore durante l\'eliminazione della scuola.');
            }
            $stmt_delete->close();
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Errore eliminazione scuola: " . $e->getMessage() . " - " . $conn->error);
            $this->error('Errore durante l\'eliminazione della scuola: ' . $e->getMessage(), 500);
            return;
        }

        $this->json(['message' => 'Scuola eliminata con successo.'], 200);
    }

    /**
     * Recupera i dettagli di una singola scuola associata all'utente corrente.
     * L'ID della scuola viene preso dal parametro 'school_id' dell'utente autenticato,
     * non dai parametri della richiesta REST.
     * Richiede il permesso 'schools.view_own'.
     */
    public function getSingleSchoolMine(): void
    {
        $conn = Connection::get();

        // L'ID della scuola viene preso direttamente dalla school_id dell'utente corrente.
        // Assumiamo che $this->currentUserSchoolId sia una proprietà già popolata
        // dall'oggetto utente autenticato o dalla sessione.
        $schoolIdToView = $this->currentUserSchoolId;

        // Verifica se l'utente è effettivamente associato a una scuola.
        // Se currentUserSchoolId è null, l'utente non ha una scuola assegnata.
        if ($schoolIdToView === null) {
            $this->error('L\'utente non è associato a nessuna scuola.', 400); // Bad Request o Forbidden, a seconda della logica di business.
            return;
        }

        // Verifica il permesso specifico per visualizzare la propria scuola.
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_own');

        // Se l'utente non ha il permesso di visualizzare la propria scuola, nega l'accesso.
        // Non è più necessario confrontare l'ID della richiesta, dato che l'ID della scuola
        // da visualizzare è intrinsecamente quello dell'utente corrente.
        if (!$canViewOwnSchool) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare la propria scuola.', 403);
            return;
        }

        // Prepara ed esegui la query per recuperare i dettagli della scuola.
        // Viene usato l'ID della scuola dell'utente corrente.
        $stmt = $conn->prepare("SELECT id, school_name, list_name, created_at FROM schools WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $schoolIdToView); // Usa l'ID della scuola dell'utente
        $stmt->execute();
        $result = $stmt->get_result();
        $school = $result->fetch_assoc();
        $stmt->close();

        // Restituisce la scuola se trovata, altrimenti un errore.
        if ($school) {
            $this->json($school, 200);
        } else {
            // Questo caso dovrebbe essere raro se $schoolIdToView è valido,
            // ma potrebbe accadere se la scuola è stata eliminata dal database
            // dopo che l'utente è stato autenticato.
            $this->error('Scuola associata all\'utente non trovata nel database.', 404);
        }
    }

    /**
     * Visualizza un elenco di scuole (tutte o quelle a cui l'utente ha accesso).
     * Permessi:
     * - 'schools.view_all' (visualizza tutte le scuole)
     * - 'schools.view_own' (visualizza solo la propria scuola, se associato)
     *
     * @return void
     */
    public function getAllSchools(): void
    {
        $conn = Connection::get();
        $schools = [];

        // Verifica il permesso per visualizzare tutte le scuole
        $canViewAllSchools = $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all');

        if (!$canViewAllSchools) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare le scuole.', 403);
            return;
        }

        // Aggiunto display_name alla SELECT
        $sql = "SELECT id, school_name, list_name, created_at FROM schools";
        $params = [];
        $types = '';

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
            $schools[] = $row;
        }
        $stmt->close();

        $this->json($schools, 200);
    }
}