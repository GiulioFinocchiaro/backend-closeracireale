<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;
use Exception;

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
     * Aggiunge un nuovo ruolo e associa i permessi selezionati.
     *
     * Questa funzione gestisce la creazione di un nuovo ruolo nel sistema, incluse
     * le verifiche necessarie per i permessi dell'utente corrente e la validazione
     * dei dati. Inoltre, inserisce i permessi associati nella tabella
     * `role_permissions` all'interno di una transazione per garantire la coerenza
     * dei dati.
     *
     * @param array $requestData Dati della richiesta contenenti 'name', 'level', 'permissions',
     * 'color' e 'is_global'. 'school_id' è opzionale per ruoli non globali.
     * @return void Non restituisce un valore, ma invia una risposta JSON.
     * @throws Exception Se si verificano errori nella preparazione o nell'esecuzione delle query.
     */
    public function addRole(): void
    {
        // Verifica il permesso per la creazione dei ruoli.
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.create')) {
            $this->error('Accesso negato: Permessi insufficienti per creare ruoli.', 403);
            return;
        }

        $conn = Connection::get();

        // Aggiunto il controllo per l'array dei permessi.
        if (!isset($this->requestData['name']) || !isset($this->requestData['level']) || !isset($this->requestData['permissions'])) {
            $this->error('Dati mancanti: name, level e permissions sono obbligatori.', 400);
            return;
        }

        $name = trim($this->requestData['name']);
        $level = (int)$this->requestData['level'];
        $color = $this->requestData['color'] ?? '';
        $isGlobal = $this->requestData['is_global'] ?? false;
        $permissions = $this->requestData['permissions'];

        // Validazione dei dati.
        if (empty($name)) {
            $this->error('Nome non può essere vuoto.', 400);
            return;
        }
        if ($level < 0) {
            $this->error('Il livello del ruolo non può essere negativo.', 400);
            return;
        }
        // Aggiunta la validazione per i permessi.
        if (!is_array($permissions)) {
            $this->error('L\'elenco dei permessi deve essere un array di ID.', 400);
            return;
        }

        // Logica per determinare se il ruolo è globale o specifico della scuola.
        $schoolIdToAssign = null;
        if ($isGlobal && $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all')) {
            $schoolIdToAssign = null;
        } elseif (!$isGlobal && $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all')) {
            if (!isset($this->requestData['school_id'])) {
                $this->error('Dati mancanti: school_id è obbligatorio per questo permesso se non è globale.', 400);
                return;
            }
            $schoolIdToAssign = (int)$this->requestData['school_id'];
        } else {
            if ($this->currentUserSchoolId === null) {
                $this->error('Errore interno: school_id dell\'utente corrente non disponibile.', 500);
                return;
            }
            $schoolIdToAssign = $this->currentUserSchoolId;
        }

        // Verifica unicità del nome del ruolo (all'interno della stessa scuola).
        $stmt_check_sql = "SELECT id FROM roles WHERE name = ? AND school_id = ? LIMIT 1;";
        if ($schoolIdToAssign === null) {
            $stmt_check_sql = "SELECT id FROM roles WHERE name = ? AND school_id IS NULL LIMIT 1;";
            $stmt_check = $conn->prepare($stmt_check_sql);
            if (!$stmt_check) {
                $this->error('Errore di preparazione della query: ' . $conn->error, 500);
                return;
            }
            $stmt_check->bind_param('s', $name);
        } else {
            $stmt_check = $conn->prepare($stmt_check_sql);
            if (!$stmt_check) {
                $this->error('Errore di preparazione della query: ' . $conn->error, 500);
                return;
            }
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

        // Verifica gerarchia.
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
        if ($level >= $currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi creare un ruolo con privilegi pari o superiori ai tuoi.', 403);
            return;
        }

        // Inizio della transazione
        $conn->begin_transaction();
        try {
            // 1. Inserisci il nuovo ruolo
            $insert_sql = "INSERT INTO roles (name, level, color, school_id) VALUES (?, ?, ?, ?);";
            $stmt_insert = $conn->prepare($insert_sql);
            if (!$stmt_insert) {
                throw new \Exception('Errore di preparazione della query per l\'inserimento del ruolo: ' . $conn->error);
            }
            $stmt_insert->bind_param('sisi', $name, $level, $color, $schoolIdToAssign);

            if (!$stmt_insert->execute()) {
                throw new \Exception('Errore durante la creazione del ruolo: ' . $stmt_insert->error);
            }
            $newRoleId = $conn->insert_id;
            $stmt_insert->close();

            // 2. Inserisci i permessi associati al nuovo ruolo
            if (!empty($permissions)) {
                $insert_permissions_sql = "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?);";
                $stmt_insert_permissions = $conn->prepare($insert_permissions_sql);
                if (!$stmt_insert_permissions) {
                    throw new \Exception('Errore di preparazione della query per l\'inserimento dei permessi: ' . $conn->error);
                }
                $stmt_insert_permissions->bind_param('ii', $newRoleId, $permissionId);

                foreach ($permissions as $permissionId) {
                    if (!$stmt_insert_permissions->execute()) {
                        throw new \Exception('Errore durante l\'associazione del permesso con ID ' . $permissionId . ': ' . $stmt_insert_permissions->error);
                    }
                }
                $stmt_insert_permissions->close();
            }

            // Commit della transazione se tutto è andato a buon fine
            $conn->commit();

            // 3. Recupera i dati completi del nuovo ruolo per la risposta
            $stmt_get_new_role = $conn->prepare("SELECT id, name, level, color, school_id FROM roles WHERE id = ? LIMIT 1;");
            if (!$stmt_get_new_role) {
                throw new \Exception('Errore di preparazione della query per il recupero del ruolo: ' . $conn->error);
            }
            $stmt_get_new_role->bind_param('i', $newRoleId);
            $stmt_get_new_role->execute();
            $result = $stmt_get_new_role->get_result();
            $newRole = $result->fetch_assoc();
            $stmt_get_new_role->close();

            // Aggiungi i permessi recuperati alla risposta JSON
            $newRole['permissions'] = $permissions;

            $this->json(['message' => 'Ruolo e permessi creati con successo.', 'role' => $newRole], 201);

        } catch (\Exception $e) {
            // Rollback della transazione in caso di errore
            $conn->rollback();
            $this->error('Errore durante la creazione del ruolo e/o l\'assegnazione dei permessi: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Aggiorna un ruolo esistente con i dati forniti nella richiesta.
     *
     * @param int $id ID del ruolo da aggiornare.
     * @param string|null $name Nome del ruolo (opzionale).
     * @param int|null $level Livello di privilegio (opzionale).
     * @param string|null $color Colore del ruolo (opzionale).
     * @param int|null $is_global Flag per ruolo globale (opzionale, solo per super_admin).
     * @param array|null $permissions Array di ID dei permessi da assegnare (opzionale).
     *
     * @return void Restituisce una risposta JSON con un messaggio di successo o un errore.
     * @throws Exception In caso di errore del database, la transazione viene annullata.
     */
    public function updateRole(): void
    {
        // 1. Controllo dei permessi di base per la modifica del ruolo
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.update')) {
            $this->error('Accesso negato: Permessi insufficienti per modificare ruoli.', 403);
            return;
        }

        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $roleIdToModify = $this->requestData['id'] ?? null;
            if ($roleIdToModify === null) {
                $this->error('ID ruolo da modificare mancante nella richiesta.', 400);
                return;
            }

            // 2. Controllo del livello di privilegio dell'utente e del ruolo
            $targetRoleLevel = null;
            $isTargetRoleGlobal = null;
            $stmt_get_target_role_info = $conn->prepare("SELECT level FROM roles WHERE id = ? LIMIT 1;");
            $stmt_get_target_role_info->bind_param('i', $roleIdToModify);
            $stmt_get_target_role_info->execute();
            $stmt_get_target_role_info->bind_result($targetRoleLevel);
            $stmt_get_target_role_info->fetch();
            $stmt_get_target_role_info->close();

            if ($targetRoleLevel === null) {
                $this->error('Ruolo da modificare non trovato.', 404);
                return;
            }

            $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
            if ($targetRoleLevel >= $currentUserMaxRoleLevel) {
                $this->error('Accesso negato: Non puoi modificare un ruolo con privilegi pari o superiori ai tuoi.', 403);
                return;
            }

            // 3. Preparation of fields to be updated
            $updateFields = [];
            $bindParams = [];
            $types = '';

            // Handle role name
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

            // Handle privilege level
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

            // Handle role color
            if (isset($this->requestData['color'])) {
                $color = trim($this->requestData['color']);
                $updateFields[] = "color = ?";
                $bindParams[] = $color;
                $types .= 's';
            }

            // Execute the role update if there are fields to modify
            if (!empty($updateFields)) {
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
                    throw new Exception('Errore durante l\'aggiornamento del ruolo: ' . $conn->error);
                }
                $stmt_update->close();
            }

            // 4. Gestione dei permessi del ruolo
            if (isset($this->requestData['permissions']) && is_array($this->requestData['permissions'])) {
                // Recupera gli ID dei permessi che l'utente può effettivamente assegnare
                $userPermissionIds = $this->permissionChecker->getUserPermissions($this->currentUserId);
                // Filtra i permessi forniti nella richiesta con quelli che l'utente può assegnare
                $permissionsToAssign = array_intersect($this->requestData['permissions'], $userPermissionIds);

                // Elimina i permessi esistenti per il ruolo
                $stmt_delete_perms = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?;");
                $stmt_delete_perms->bind_param('i', $roleIdToModify);
                if (!$stmt_delete_perms->execute()) {
                    throw new Exception('Errore durante l\'eliminazione dei permessi esistenti: ' . $conn->error);
                }
                $stmt_delete_perms->close();

                // Inserisce i nuovi permessi
                if (!empty($permissionsToAssign)) {
                    $values = implode(', ', array_fill(0, count($permissionsToAssign), '(?, ?)'));
                    // La query è stata corretta per usare permission_id invece di permission_name
                    $sql_insert_perms = "INSERT INTO role_permissions (role_id, permission_id) VALUES $values;";
                    $stmt_insert_perms = $conn->prepare($sql_insert_perms);
                    $insertParams = [];
                    $insertTypes = '';
                    foreach ($permissionsToAssign as $permId) {
                        $insertParams[] = $roleIdToModify;
                        $insertParams[] = $permId;
                        $insertTypes .= 'ii';
                    }
                    $insertRefs = [];
                    foreach ($insertParams as $key => $value) {
                        $insertRefs[$key] = &$insertParams[$key];
                    }
                    call_user_func_array([$stmt_insert_perms, 'bind_param'], array_merge([$insertTypes], $insertRefs));
                    if (!$stmt_insert_perms->execute()) {
                        throw new Exception('Errore durante l\'inserimento dei nuovi permessi: ' . $conn->error);
                    }
                    $stmt_insert_perms->close();
                }
            }

            $conn->commit();
            $this->json(['message' => 'Ruolo aggiornato con successo.'], 200);

        } catch (Exception $e) {
            $conn->rollback();
            $this->error($e->getMessage(), 500);
        }
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
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'roles.view_all') && !$this->permissionChecker->userHasPermission($this->currentUserId, 'users.register_new_users')) {
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
