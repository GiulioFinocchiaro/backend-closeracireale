<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class RoleController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null;
    private ?int $currentUserSchoolId = null; // Mantenuto per coerenza, ma non direttamente usato per la gestione ruoli
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
            // Non strettamente necessaria per la gestione dei ruoli, ma mantenuta per coerenza
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
     * Crea un nuovo ruolo.
     * Permessi: 'roles.create'
     * Dati richiesti da JSON: 'name', 'description', 'level'
     *
     * @return void
     */
    public function addRole(): void
    {
        // Verifica permesso per l'amministratore che esegue l'azione
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        // 1. Recupera e valida i dati
        if (!isset($this->requestData['name']) || !isset($this->requestData['description']) || !isset($this->requestData['level'])) {
            $this->error('Dati mancanti: name, description e level sono obbligatori.', 400);
            return;
        }

        $name = trim($this->requestData['name']);
        $description = trim($this->requestData['description']);
        $level = (int)$this->requestData['level'];

        if (empty($name) || empty($description)) {
            $this->error('Nome e descrizione non possono essere vuoti.', 400);
            return;
        }
        if ($level < 0) { // Il livello non può essere negativo
            $this->error('Il livello del ruolo non può essere negativo.', 400);
            return;
        }

        // 2. Verifica unicità del nome del ruolo
        $stmt_check = $conn->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1;");
        $stmt_check->bind_param('s', $name);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $stmt_check->close();
            $this->error('Un ruolo con questo nome esiste già.', 409);
            return;
        }
        $stmt_check->close();

        // 3. Verifica gerarchia: l'utente corrente può creare un ruolo con questo livello?
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);

        // L'utente corrente non può creare ruoli con livello pari o superiore al suo
        if ($level >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi creare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        // 4. Inserisci il nuovo ruolo
        $stmt_insert = $conn->prepare("INSERT INTO roles (name, description, level) VALUES (?, ?, ?);");
        $stmt_insert->bind_param('ssi', $name, $description, $level);

        if (!$stmt_insert->execute()) {
            $this->error('Errore durante la creazione del ruolo: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }

        $newRoleId = $conn->insert_id;
        $stmt_insert->close();

        $this->json(['message' => 'Ruolo creato con successo.', 'role_id' => $newRoleId], 201);
    }

    /**
     * Modifica i dati di un ruolo esistente.
     * L'ID del ruolo da modificare è preso da $this->requestData['id'].
     * Permessi: 'roles.update'
     * Dati modificabili da JSON: 'name', 'description', 'level'
     *
     * @return void
     */
    public function updateRole(): void
    {
        // Verifica permesso per l'amministratore che esegue l'azione
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.update')) {
            $this->error('Accesso negato: Permessi insufficienti per modificare ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        $roleIdToModify = $this->requestData['id'] ?? null;
        if ($roleIdToModify === null) {
            $this->error('ID ruolo da modificare mancante nella richiesta.', 400);
            return;
        }

        // Recupera il livello attuale del ruolo da modificare
        $targetRoleLevel = null;
        // Prima prova a recuperare il livello tramite ID se presente nel body
        // Altrimenti, recupera dal DB tramite ID del ruolo da modificare
        $stmt_get_target_role_level = $conn->prepare("SELECT level FROM roles WHERE id = ? LIMIT 1;");
        $stmt_get_target_role_level->bind_param('i', $roleIdToModify);
        $stmt_get_target_role_level->execute();
        $stmt_get_target_role_level->bind_result($targetRoleLevel);
        $stmt_get_target_role_level->fetch();
        $stmt_get_target_role_level->close();


        if ($targetRoleLevel === null) { // Ruolo non trovato
            $this->error('Ruolo da modificare non trovato.', 404);
            return;
        }

        // Verifica gerarchia: l'utente corrente può modificare questo ruolo?
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);

        // L'utente corrente non può modificare ruoli con livello pari o superiore al suo
        if ($targetRoleLevel >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi modificare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        $updateFields = [];
        $bindParams = [];
        $types = '';
        $newLevel = null; // Per tenere traccia del nuovo livello se viene modificato

        if (isset($this->requestData['name'])) {
            $name = trim($this->requestData['name']);
            if (empty($name)) {
                $this->error('Nome ruolo non può essere vuoto.', 400);
                return;
            }
            // Verifica unicità del nome se modificato
            $stmt_check_name = $conn->prepare("SELECT id FROM roles WHERE name = ? AND id != ? LIMIT 1;");
            $stmt_check_name->bind_param('si', $name, $roleIdToModify);
            $stmt_check_name->execute();
            $stmt_check_name->store_result();
            if ($stmt_check_name->num_rows > 0) {
                $stmt_check_name->close();
                $this->error('Il nuovo nome ruolo è già esistente.', 409);
                return;
            }
            $stmt_check_name->close();

            $updateFields[] = "name = ?";
            $bindParams[] = $name;
            $types .= 's';
        }
        if (isset($this->requestData['description'])) {
            $description = trim($this->requestData['description']);
            if (empty($description)) {
                $this->error('Descrizione non può essere vuota.', 400);
                return;
            }
            $updateFields[] = "description = ?";
            $bindParams[] = $description;
            $types .= 's';
        }
        if (isset($this->requestData['level'])) {
            $newLevel = (int)$this->requestData['level'];
            if ($newLevel < 0) {
                $this->error('Il livello del ruolo non può essere negativo.', 400);
                return;
            }
            // Verifica gerarchia per il NUOVO livello: non può essere pari o superiore al livello dell'utente corrente
            if ($newLevel >= $currentUserMaxRoleLevel) {
                $this->error('Accesso negato: Non puoi impostare un livello di privilegio pari o superiore ai tuoi.', 403);
                return;
            }
            $updateFields[] = "level = ?";
            $bindParams[] = $newLevel;
            $types .= 'i';
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
            return;
        }

        // Costruisci ed esegui la query di aggiornamento
        $sql = "UPDATE roles SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);

        $bindParams[] = $roleIdToModify;
        $types .= 'i';

        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Errore durante l\'aggiornamento del ruolo: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();

        $this->json(['message' => 'Ruolo aggiornato con successo.'], 200);
    }

    /**
     * Elimina un ruolo.
     * L'ID del ruolo da eliminare è preso da $this->requestData['id'].
     * Permessi: 'roles.delete'
     *
     * @return void
     */
    public function deleteRole(): void
    {
        // Verifica permesso per l'amministratore che esegue l'azione
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.delete')) {
            $this->error('Accesso negato: Permessi insufficienti per eliminare ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        $roleIdToDelete = $this->requestData['id'] ?? null;
        if ($roleIdToDelete === null) {
            $this->error('ID ruolo da eliminare mancante nella richiesta.', 400);
            return;
        }

        // Recupera il livello del ruolo da eliminare
        $targetRoleLevel = null;
        $stmt_get_target_role_level = $conn->prepare("SELECT level FROM roles WHERE id = ? LIMIT 1;");
        $stmt_get_target_role_level->bind_param('i', $roleIdToDelete);
        $stmt_get_target_role_level->execute();
        $stmt_get_target_role_level->bind_result($targetRoleLevel);
        $stmt_get_target_role_level->fetch();
        $stmt_get_target_role_level->close();

        if ($targetRoleLevel === null) { // Ruolo non trovato
            $this->error('Ruolo da eliminare non trovato.', 404);
            return;
        }

        // Verifica gerarchia: l'utente corrente può eliminare questo ruolo?
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);

        // L'utente corrente non può eliminare ruoli con livello pari o superiore al suo
        if ($targetRoleLevel >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi eliminare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        // Impedisci l'eliminazione del ruolo 'super_admin' o del proprio ruolo se si è l'unico super admin
        // Questa è una logica di sicurezza aggiuntiva, potresti volerla affinare.
        // Ad esempio, impedire l'eliminazione di ruoli critici.
        $stmt_check_critical_role = $conn->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1;");
        $stmt_check_critical_role->bind_param('i', $roleIdToDelete);
        $stmt_check_critical_role->execute();
        $stmt_check_critical_role->bind_result($roleName);
        $stmt_check_critical_role->fetch();
        $stmt_check_critical_role->close();

        if ($roleName === 'super_admin') {
            $this->error('Non è consentito eliminare il ruolo "super_admin" direttamente.', 403);
            return;
        }

        // Gestione delle dipendenze: cosa succede agli utenti con questo ruolo?
        // È fondamentale che il database abbia una Foreign Key con ON DELETE SET NULL o CASCADE
        // sulla tabella user_role. Altrimenti, dovrai gestire la riassegnazione o eliminazione qui.
        // Esempio: Rimuovi tutte le associazioni utente-ruolo per il ruolo che sta per essere eliminato
        $conn->begin_transaction();
        try {
            $stmt_delete_user_roles = $conn->prepare("DELETE FROM user_role WHERE role_id = ?;");
            $stmt_delete_user_roles->bind_param('i', $roleIdToDelete);
            $stmt_delete_user_roles->execute();
            $stmt_delete_user_roles->close();

            // Ora elimina il ruolo
            $stmt_delete = $conn->prepare("DELETE FROM roles WHERE id = ?;");
            $stmt_delete->bind_param('i', $roleIdToDelete);

            if (!$stmt_delete->execute()) {
                throw new \Exception('Errore durante l\'eliminazione del ruolo.');
            }
            $stmt_delete->close();
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Errore eliminazione ruolo: " . $e->getMessage() . " - " . $conn->error);
            $this->error('Errore durante l\'eliminazione del ruolo: ' . $e->getMessage(), 500);
            return;
        }

        $this->json(['message' => 'Ruolo eliminato con successo.'], 200);
    }

    /**
     * Visualizza i dettagli di un singolo ruolo.
     * L'ID del ruolo da visualizzare è preso da $this->requestData['id'].
     * Permessi: 'roles.view_all'
     *
     * @return void
     */
    public function getSingleRole(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all')) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare i ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        $roleId = $this->requestData['id'] ?? null;
        if ($roleId === null) {
            $this->error('ID ruolo da visualizzare mancante nella richiesta.', 400);
            return;
        }

        $stmt = $conn->prepare("SELECT id, name, description, level, color FROM roles WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        $role = $result->fetch_assoc();
        $stmt->close();

        if ($role) {
            $this->json($role, 200);
        } else {
            $this->error('Ruolo non trovato.', 404);
        }
    }

    public function getMineRoles(): void
    {
        $conn = Connection::get();

        $stmt = $conn->prepare("
        SELECT r.id, r.name, r.level, r.color
        FROM roles r
        INNER JOIN user_role ur ON ur.role_id = r.id
        WHERE ur.user_id = ?
        ORDER BY r.level DESC;
    ");

        $stmt->bind_param('i', $this->currentUserId);
        $stmt->execute();
        $result = $stmt->get_result();

        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }

        $stmt->close();

        if (!empty($roles)) {
            $this->json($roles, 200);
        } else {
            $this->error('Nessun ruolo trovato per l\'utente.', 404);
        }
    }


    /**
     * Visualizza un elenco di tutti i ruoli.
     * Permessi: 'roles.view_all'
     *
     * @return void
     */
    public function getAllRoles(): void
    {
        // Verifica permesso
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all')) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare i ruoli.', 403);
            return;
        }

        $conn = Connection::get();
        $roles = [];

        $sql = "SELECT id, name, description, level FROM roles ORDER BY level DESC, name ASC;";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
        $stmt->close();

        $this->json($roles, 200);
    }
}
