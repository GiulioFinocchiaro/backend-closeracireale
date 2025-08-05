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
    private ?int $currentUserSchoolId = null;
    private array $requestData;

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
     * Dati richiesti da JSON: 'name', 'level', (optional) 'color', (optional) 'is_global' (se con permesso schools.view_all)
     *
     * @return void
     */
    public function addRole(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        if (!isset($this->requestData['name']) || !isset($this->requestData['level'])) {
            $this->error('Dati mancanti: name e level sono obbligatori.', 400);
            return;
        }

        $name = trim($this->requestData['name']);
        $level = (int)$this->requestData['level'];
        $color = $this->requestData['color'] ?? '';
        $isGlobal = $this->requestData['is_global'] ?? false;

        if (empty($name)) {
            $this->error('Nome non può essere vuoto.', 400);
            return;
        }
        if ($level < 0) {
            $this->error('Il livello del ruolo non può essere negativo.', 400);
            return;
        }

        $schoolIdToAssign = null;

        // Logica per determinare se il ruolo è globale o specifico della scuola
        if ($isGlobal && $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all')) {
            // L'utente ha il permesso e vuole creare un ruolo globale. school_id sarà NULL.
            $schoolIdToAssign = null;
        } elseif (!$isGlobal && $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all')) {
            // L'utente ha il permesso ma vuole specificare la school_id
            if (!isset($this->requestData['school_id'])) {
                $this->error('Dati mancanti: school_id è obbligatorio per questo permesso se non è globale.', 400);
                return;
            }
            $schoolIdToAssign = (int)$this->requestData['school_id'];
        } else {
            // L'utente non ha il permesso, il ruolo è sempre per la scuola corrente
            if ($this->currentUserSchoolId === null) {
                $this->error('Errore interno: school_id dell\'utente corrente non disponibile.', 500);
                return;
            }
            $schoolIdToAssign = $this->currentUserSchoolId;
        }

        // 2. Verifica unicità del nome del ruolo (all'interno della stessa scuola)
        $stmt_check_sql = "SELECT id FROM roles WHERE name = ? AND school_id = ? LIMIT 1;";
        if ($schoolIdToAssign === null) {
            $stmt_check_sql = "SELECT id FROM roles WHERE name = ? AND school_id IS NULL LIMIT 1;";
            $stmt_check = $conn->prepare($stmt_check_sql);
            $stmt_check->bind_param('s', $name);
        } else {
            $stmt_check = $conn->prepare($stmt_check_sql);
            $stmt_check->bind_param('si', $name, $schoolIdToAssign);
        }

        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $stmt_check->close();
            $this->error('Un ruolo con questo nome esiste già per la scuola specificata o come ruolo globale.', 409);
            return;
        }
        $stmt_check->close();

        // 3. Verifica gerarchia: l'utente corrente può creare un ruolo con questo livello?
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
        if ($level >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi creare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        // 4. Inserisci il nuovo ruolo
        $insert_sql = "INSERT INTO roles (name, level, color, school_id) VALUES (?, ?, ?, ?);";
        $stmt_insert = $conn->prepare($insert_sql);

        $stmt_insert->bind_param('sisi', $name, $level, $color, $schoolIdToAssign);

        if (!$stmt_insert->execute()) {
            $this->error('Errore durante la creazione del ruolo: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }
        $newRoleId = $conn->insert_id;
        $stmt_insert->close();

        $stmt_get_new_role = $conn->prepare("SELECT id, name, level, color, school_id FROM roles WHERE id = ? LIMIT 1;");
        $stmt_get_new_role->bind_param('i', $newRoleId);
        $stmt_get_new_role->execute();
        $result = $stmt_get_new_role->get_result();
        $newRole = $result->fetch_assoc();
        $stmt_get_new_role->close();

        $this->json(['message' => 'Ruolo creato con successo.', 'role' => $newRole], 201);
    }

    /**
     * Modifica i dati di un ruolo esistente.
     * L'ID del ruolo da modificare è preso da $this->requestData['id'].
     * Permessi: 'roles.update'
     * Dati modificabili da JSON: 'name', 'level', 'color'
     *
     * @return void
     */
    public function updateRole(): void
    {
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

        $targetRoleLevel = null;
        $stmt_get_target_role_level = $conn->prepare("SELECT level FROM roles WHERE id = ? LIMIT 1;");
        $stmt_get_target_role_level->bind_param('i', $roleIdToModify);
        $stmt_get_target_role_level->execute();
        $stmt_get_target_role_level->bind_result($targetRoleLevel);
        $stmt_get_target_role_level->fetch();
        $stmt_get_target_role_level->close();

        if ($targetRoleLevel === null) {
            $this->error('Ruolo da modificare non trovato.', 404);
            return;
        }

        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
        if ($targetRoleLevel >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi modificare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['name'])) {
            $name = trim($this->requestData['name']);
            if (empty($name)) {
                $this->error('Nome ruolo non può essere vuoto.', 400);
                return;
            }
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
        if (isset($this->requestData['level'])) {
            $newLevel = (int)$this->requestData['level'];
            if ($newLevel < 0) {
                $this->error('Il livello del ruolo non può essere negativo.', 400);
                return;
            }
            if ($newLevel >= $currentUserMaxRoleLevel) {
                $this->error('Accesso negato: Non puoi impostare un livello di privilegio pari o superiore ai tuoi.', 403);
                return;
            }
            $updateFields[] = "level = ?";
            $bindParams[] = $newLevel;
            $types .= 'i';
        }
        if (isset($this->requestData['color'])) {
            $color = trim($this->requestData['color']);
            $updateFields[] = "color = ?";
            $bindParams[] = $color;
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->error('Nessun dato fornito per l\'aggiornamento.', 400);
            return;
        }

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

        $targetRoleLevel = null;
        $stmt_get_target_role_level = $conn->prepare("SELECT level FROM roles WHERE id = ? LIMIT 1;");
        $stmt_get_target_role_level->bind_param('i', $roleIdToDelete);
        $stmt_get_target_role_level->execute();
        $stmt_get_target_role_level->bind_result($targetRoleLevel);
        $stmt_get_target_role_level->fetch();
        $stmt_get_target_role_level->close();

        if ($targetRoleLevel === null) {
            $this->error('Ruolo da eliminare non trovato.', 404);
            return;
        }

        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
        if ($targetRoleLevel >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi eliminare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

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

        $conn->begin_transaction();
        try {
            $stmt_delete_user_roles = $conn->prepare("DELETE FROM user_role WHERE role_id = ?;");
            $stmt_delete_user_roles->bind_param('i', $roleIdToDelete);
            $stmt_delete_user_roles->execute();
            $stmt_delete_user_roles->close();

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

        $stmt = $conn->prepare("SELECT id, name, level, color FROM roles WHERE id = ? LIMIT 1;");
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

    /**
     * Restituisce i permessi dell'utente autenticato.
     * Questo endpoint non richiede permessi aggiuntivi perché si applica all'utente stesso.
     * La query utilizza DISTINCT per garantire che ogni permesso venga visualizzato una sola volta,
     * anche se l'utente ha più ruoli che contengono lo stesso permesso.
     *
     * @return void
     */
    public function getMinePermissions(): void
    {
        $conn = Connection::get();
        if ($conn === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }

        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT p.id, p.name, p.display_name
                FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                INNER JOIN user_role ur ON ur.role_id = rp.role_id
                WHERE ur.user_id = ?
            ");
            $stmt->bind_param('i', $this->currentUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            $permissions = [];
            while ($row = $result->fetch_assoc()) {
                $permissions[] = $row;
            }
            $stmt->close();

            $this->json($permissions, 200);

        } catch (\Exception $e) {
            $this->error('Errore durante il recupero dei permessi: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Restituisce i permessi di un ruolo specifico.
     * Permessi: 'roles.view_all'
     * Dati richiesti da JSON: 'id' (ID del ruolo)
     *
     * @return void
     */
    public function getPermissionsForRole(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all')) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare i permessi dei ruoli.', 403);
            return;
        }

        $conn = Connection::get();
        if ($conn === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }

        $roleId = $this->requestData['id'] ?? null;
        if ($roleId === null) {
            $this->error('ID del ruolo mancante nella richiesta.', 400);
            return;
        }

        try {
            $stmt = $conn->prepare("
                SELECT p.name
                FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ");
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $result = $stmt->get_result();
            $permissions = [];
            while ($row = $result->fetch_assoc()) {
                $permissions[] = $row['name'];
            }
            $stmt->close();

            $this->json($permissions, 200);

        } catch (\Exception $e) {
            $this->error('Errore durante il recupero dei permessi del ruolo: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Restituisce un elenco di tutti i permessi disponibili.
     * L'utente deve avere il permesso 'roles.view_all' per accedere.
     *
     * @return void
     */
    public function getAllPermissions(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all')) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare tutti i permessi.', 403);
            return;
        }

        $conn = Connection::get();
        if ($conn === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return;
        }

        try {
            $stmt = $conn->prepare("SELECT name FROM permissions ORDER BY name ASC;");
            $stmt->execute();
            $result = $stmt->get_result();
            $permissions = [];
            while ($row = $result->fetch_assoc()) {
                $permissions[] = $row['name'];
            }
            $stmt->close();

            $this->json($permissions, 200);
        } catch (\Exception $e) {
            $this->error('Errore durante il recupero di tutti i permessi: ' . $e->getMessage(), 500);
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
     * Visualizza un elenco di ruoli con livello inferiore o uguale a quello massimo dell'utente,
     * filtrati in base alla school_id e ai permessi dell'utente.
     * I permessi di ogni ruolo vengono inclusi nella risposta.
     * Permessi: 'roles.view_all'
     *
     * @return void
     */
    public function getRolesByLevelOrLower(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all')) {
            $this->error('Accesso negato: Permessi insufficienti per visualizzare i ruoli.', 403);
            return;
        }

        $conn = Connection::get();
        $roles = [];
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);

        if ($currentUserMaxRoleLevel === null) {
            $this->error('Nessun ruolo trovato per l\'utente corrente.', 404);
            return;
        }

        $schoolIdToFilter = null;
        if ($this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all')) {
            if (!isset($this->requestData['school_id']) || !is_int($this->requestData['school_id'])) {
                $this->error('School ID non valido o mancante nel corpo della richiesta.', 400);
                return;
            }
            $schoolIdToFilter = $this->requestData['school_id'];
            $sql = "SELECT id, name, level, color, school_id FROM roles WHERE level <= ? AND (school_id = ? OR school_id IS NULL) ORDER BY level DESC, name ASC;";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $currentUserMaxRoleLevel, $schoolIdToFilter);
        } else {
            $schoolIdToFilter = $this->currentUserSchoolId;
            $sql = "SELECT id, name, level, color, school_id FROM roles WHERE level < ? AND school_id = ? ORDER BY level DESC, name ASC;";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $currentUserMaxRoleLevel, $schoolIdToFilter);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        // Recupera i ruoli
        $roleIds = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
            $roleIds[] = $row['id'];
        }
        $stmt->close();

        if (empty($roles)) {
            $this->error('Nessun ruolo trovato con livello pari o inferiore per la scuola specificata.', 404);
            return;
        }

        // Recupera i permessi per tutti i ruoli in un'unica query
        $permissions = [];
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $sql_permissions = "SELECT rp.role_id, p.id, p.display_name FROM role_permissions rp INNER JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id IN ($placeholders);";
        $stmt_permissions = $conn->prepare($sql_permissions);

        $types = str_repeat('i', count($roleIds));
        $refs = [];
        foreach ($roleIds as $key => $value) {
            $refs[$key] = &$roleIds[$key];
        }
        call_user_func_array([$stmt_permissions, 'bind_param'], array_merge([$types], $refs));

        $stmt_permissions->execute();
        $result_permissions = $stmt_permissions->get_result();

        while ($row = $result_permissions->fetch_assoc()) {
            $permissions[$row['role_id']][] = ['id' => $row['id'], 'display_name' => $row['display_name']];
        }
        $stmt_permissions->close();

        // Aggiunge l'array dei permessi a ciascun ruolo
        foreach ($roles as &$role) {
            $role['permissions'] = $permissions[$role['id']] ?? [];
        }

        $this->json($roles, 200);
    }
}
