<?php

namespace Controllers; // Assicurati che il namespace sia corretto per i tuoi controller

// Non è più necessario require_once __DIR__ . "/../../vendor/autoload.php"; qui,
// perché il tuo file di routing principale (es. index.php) dovrebbe già includere l'autoloader.

use Core\AuthMiddleware;
use Core\RoleChecker; // Mantenuto il nome RoleChecker come nel tuo codice
use Database\Connection;
use Core\Controller;
use Helpers\Mail;

// Importa la classe Controller base

class UserController extends Controller // Estendi la classe Controller
{

    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null; // ID dell'utente autenticato
    private ?int $currentUserSchoolId = null; // school_id dell'utente autenticato
    private array $requestData; // Proprietà per i dati JSON della richiesta
    private String $email_user;

    public function __construct()
    {
        // Recupera e decodifica i dati JSON della richiesta una sola volta
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        $this->authMiddleware = new AuthMiddleware();
        $dbConnection = Connection::get();

        if ($dbConnection === null) {
            $this->error('Errore interno del server: connessione database non disponibile.', 500);
            return; // Usciamo subito
        }
        $this->permissionChecker = new RoleChecker();

        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = $decodedToken->sub;
            $this->email_user = $decodedToken->email;

            // Recupera la school_id dell'utente corrente all'avvio del controller
            $stmt = $dbConnection->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
            $stmt->bind_param('i', $this->currentUserId);
            $stmt->execute();
            $stmt->bind_result($this->currentUserSchoolId);
            $stmt->fetch();
            $stmt->close();

        } catch (\Exception $e) {
            // AuthMiddleware dovrebbe già gestire l'uscita con 401.
            // Se non lo fa, questa riga cattura l'eccezione e fornisce una risposta.
            $this->error('Autenticazione fallita: ' . $e->getMessage(), 401);
            return;
        }
    }


    /**
     * Gestisce la richiesta di modifica dei dati di un utente.
     * L'ID dell'utente da modificare è preso da $this->requestData['id'].
     * Permessi:
     * - 'users.manage_all_users' (per Super Admin: modifica nome, email, password, ruoli di CHIUNQUE)
     * - 'users.manage_own_section_users' (per Admin: modifica nome, email, password, ruoli di utenti nella STESSA school_id)
     *
     * @return void
     */
    public function updateUser(): void
    {
        error_log("Role update data: " . json_encode($this->requestData['roles']));
        $userIdToModify = $this->requestData['id'] ?? null;
        if ($userIdToModify === null) {
            $this->error('ID utente da modificare mancante nella richiesta.', 400);
            return;
        }

        $conn = Connection::get();

        // Recupera info utente target
        $targetUserName = $targetUserEmail = null;
        $targetUserSchoolId = null;
        $stmt = $conn->prepare("SELECT name, email, school_id FROM users WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $userIdToModify);
        $stmt->execute();
        $stmt->bind_result($targetUserName, $targetUserEmail, $targetUserSchoolId);
        $stmt->fetch();
        $stmt->close();

        if ($targetUserName === null) {
            $this->error('Utente da modificare non trovato.', 404);
            return;
        }

        // Recupera nome admin
        $updaterAdminName = '';
        $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $this->currentUserId);
        $stmt->execute();
        $stmt->bind_result($updaterAdminName);
        $stmt->fetch();
        $stmt->close();

        // Permessi
        $canManageAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.edit.all');
        $canManageOwnSectionUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.edit.own_section');
        $isOwnSectionUser = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetUserSchoolId);

        if (!$canManageAllUsers && (!$canManageOwnSectionUsers || !$isOwnSectionUser)) {
            $this->error('Accesso negato: Permessi insufficienti.', 403);
            return;
        }

        // Campi aggiornabili
        $updateFields = [];
        $bindParams = [];
        $types = '';
        $passwordUpdated = false;
        $rolesUpdated = false;

        if (!empty($this->requestData['name'])) {
            $updateFields[] = "name = ?";
            $bindParams[] = trim($this->requestData['name']);
            $types .= 's';
        }

        if (!empty($this->requestData['email'])) {
            if (!filter_var($this->requestData['email'], FILTER_VALIDATE_EMAIL)) {
                $this->error('Formato email non valido.', 400);
                return;
            }

            // Unicità email
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1;");
            $stmt->bind_param('si', $this->requestData['email'], $userIdToModify);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->close();
                $this->error('Email già in uso.', 409);
                return;
            }
            $stmt->close();

            $updateFields[] = "email = ?";
            $bindParams[] = trim($this->requestData['email']);
            $types .= 's';
        }

        if (!empty($this->requestData['password'])) {
            $updateFields[] = "password_hash = ?";
            $bindParams[] = password_hash($_ENV["PASSWORD_NEW_USER_DEFAULT"], PASSWORD_BCRYPT);
            $types .= 's';
            $passwordUpdated = true;
        }

        if (!empty($this->requestData['school_id'])) {
            if (!$canManageAllUsers) {
                $this->error('Non puoi modificare la scuola.', 403);
                return;
            }
            $updateFields[] = "school_id = ?";
            $bindParams[] = intval($this->requestData['school_id']);
            $types .= 'i';
        }

        // Gestione ruoli
        if (!empty($this->requestData['role']) && is_array($this->requestData['role'])) {
            $roleIdsToAssign = $this->requestData['role'];
            $canAssignRoles = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.assign_roles');
            $canElevatePrivileges = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.elevate_privileges');

            if (!$canAssignRoles) {
                $this->error('Permessi insufficienti per assegnare ruoli.', 403);
                return;
            }

            $placeholders = implode(',', array_fill(0, count($roleIdsToAssign), '?'));
            $roleTypes = str_repeat('i', count($roleIdsToAssign));
            $stmt = $conn->prepare("SELECT id, level FROM roles WHERE id IN ($placeholders)");
            $stmt->bind_param($roleTypes, ...$roleIdsToAssign);
            $stmt->execute();
            $result = $stmt->get_result();

            $validRoles = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($validRoles) !== count($roleIdsToAssign)) {
                $this->error('Ruoli non validi.', 400);
                return;
            }

            $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
            $targetUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($userIdToModify);

            if (!$canElevatePrivileges) {
                foreach ($validRoles as $role) {
                    if ($role['level'] > $currentUserMaxRoleLevel) {
                        $this->error('Non puoi assegnare ruoli superiori ai tuoi.', 403);
                        return;
                    }
                }
                if ($targetUserMaxRoleLevel > $currentUserMaxRoleLevel) {
                    $this->error('Non puoi modificare un utente con privilegi superiori ai tuoi.', 403);
                    return;
                }
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("DELETE FROM user_role WHERE user_id = ?");
                $stmt->bind_param('i', $userIdToModify);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO user_role (user_id, role_id) VALUES (?, ?)");
                foreach ($validRoles as $role) {
                    $stmt->bind_param('ii', $userIdToModify, $role['id']);
                    $stmt->execute();
                }
                $stmt->close();

                $conn->commit();
                $rolesUpdated = true;
            } catch (\Exception $e) {
                $conn->rollback();
                $this->error("Errore aggiornamento ruoli: " . $e->getMessage(), 500);
                return;
            }
        }

        // Se ci sono campi da aggiornare
        if (!empty($updateFields)) {
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);

            $bindParams[] = $userIdToModify;
            $types .= 'i';

            $refs = [];
            foreach ($bindParams as $k => $v) {
                $refs[$k] = &$bindParams[$k];
            }

            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
            if (!$stmt->execute()) {
                $this->error("Errore update utente: " . $conn->error, 500);
                $stmt->close();
                return;
            }
            $stmt->close();
        }

        $this->json([
            'message' => 'Utente aggiornato con successo.',
            'password_updated' => $passwordUpdated,
            'roles_updated' => $rolesUpdated
        ], 200);
    }

    /**
     * Gestisce la richiesta di eliminazione di un utente.
     * L'ID dell'utente da eliminare è preso da $this->requestData['id'].
     * Permessi:
     * - 'users.manage_all_users' (per Super Admin: elimina CHIUNQUE)
     * - 'users.manage_own_section_users' (per Admin: elimina utenti nella STESSA school_id)
     *
     * @return void
     */
    public function deleteUser(): void // Rimosso $userIdToDelete dal parametro
    {
        // Recupera l'ID dell'utente da eliminare dal corpo JSON
        $userIdToDelete = $this->requestData['id'] ?? null;
        if ($userIdToDelete === null) {
            $this->error('ID utente da eliminare mancante nella richiesta.', 400);
            return;
        }

        $conn = Connection::get();

        // Non permettere all'utente di eliminare se stesso
        if ($userIdToDelete === $this->currentUserId) {
            $this->error('Non puoi eliminare il tuo stesso account.', 403);
            return;
        }

        // 1. Carica le informazioni sull'utente che si tenta di eliminare (solo school_id)
        $stmt_target_user = $conn->prepare("SELECT school_id FROM users WHERE id = ? LIMIT 1;");
        $stmt_target_user->bind_param('i', $userIdToDelete);
        $stmt_target_user->execute();
        $stmt_target_user->bind_result($this->currentUserSchoolId);
        $stmt_target_user->fetch();
        $stmt_target_user->close();

        if ($this->currentUserId === null) { // L'utente target non esiste
            $this->error('Utente da eliminare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        // Super Admin può fare tutto
        $canManageAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.delete.all');

        // Admin può gestire utenti nella propria sezione
        $canManageOwnSectionUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.delete.own_section');
        $isOwnSectionUser = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $this->currentUserSchoolId);

        // L'utente corrente può eliminare l'utente target se:
        // A) È un Super Admin (ha manage_all_users)
        // B) È un Admin (ha manage_own_section_users) E l'utente target è nella sua stessa sezione
        if (!$canManageAllUsers && (!$canManageOwnSectionUsers || !$isOwnSectionUser)) {
            $this->error('Accesso negato: Permessi insufficienti per eliminare questo utente o utente non nella tua sezione.', 403);
            return;
        }

        // --- Aggiunta: Verifica gerarchia per l'eliminazione ---
        // Recupera il livello massimo del ruolo dell'utente corrente
        $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
        // Recupera il livello massimo del ruolo dell'utente target
        $targetUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($userIdToDelete);

        // Se l'utente corrente NON ha il permesso di elevare privilegi (cioè non è un Super Admin),
        // non può eliminare un utente con un ruolo di livello superiore al suo.
        $canElevatePrivileges = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.elevate_privileges');
        if (!$canElevatePrivileges && $targetUserMaxRoleLevel >$currentUserMaxRoleLevel) {
            $this->error('Accesso negato: Non puoi eliminare un utente con privilegi pari o superiori ai tuoi.', 403);
            return;
        }
        // --- Fine Aggiunta: Verifica gerarchia per l'eliminazione ---


        // 3. Eliminazione dell'utente
        $stmt_delete_user_roles = $conn->prepare("DELETE FROM user_role WHERE user_id = ?;");
        $stmt_delete_user_roles->bind_param('i', $userIdToDelete);
        $stmt_delete_user_roles->execute();
        $stmt_delete_user_roles->close();

        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE id = ?;");
        $stmt_delete_user->bind_param('i', $userIdToDelete);

        if (!$stmt_delete_user->execute()) {
            $this->error('Errore durante l\'eliminazione dell\'utente: ' . $conn->error, 500);
            $stmt_delete_user->close();
            return;
        }
        $stmt_delete_user->close();

        $this->json(['message' => 'Utente eliminato con successo.'], 200);
    }

    /**
     * Verifica se l'utente autenticato ha un permesso specifico.
     * I dati sono presi da $this->requestData.
     * Richiede 'permission_name' nel corpo JSON.
     *
     * @return void
     */
    public function checkUserPermission(): void
    {
        // 1. Recupera il nome del permesso dalla richiesta
        $permissionName = $this->requestData['permission_name'] ?? null;

        if (empty($permissionName)) {
            $this->error('Nome del permesso mancante nella richiesta.', 400);
            return;
        }

        // 2. Verifica se l'utente autenticato ha il permesso
        $hasPermission = $this->permissionChecker->userHasPermission($this->currentUserId, $permissionName);

        // 3. Restituisci la risposta JSON
        $this->json(['permission_name' => $permissionName, 'has_permission' => $hasPermission], 200);
    }

    /**
     * Restituisce tutti gli utenti di una scuola.
     * Se l'utente ha il permesso 'schools.view_all' può specificare una school_id.
     * Altrimenti, si limita alla propria scuola.
     */
    public function getUsersBySchool(): void
    {
        $conn = Connection::get();

        // Di default, usa la school_id dell'utente autenticato
        $schoolIdToFetch = $this->currentUserSchoolId;

        // Verifica se l'utente ha il permesso di vedere tutte le scuole
        $canViewAllSchools = $this->permissionChecker->userHasPermission($this->currentUserId, 'schools.view_all');

        // Se l'utente può vedere tutte le scuole E ha specificato una school_id nella richiesta,
        // allora usa quella school_id.
        if ($canViewAllSchools && isset($this->requestData['school_id'])) {
            $canViewAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.view_all_users');
            if (!$canViewAllUsers) {
                $this->error("Accesso Negato: Non hai i permessi per vedere tutti gli utenti", 403);
            }else {
                // È buona pratica validare e sanificare l'input
                $requestedSchoolId = intval($this->requestData['school_id']);
                // Assicurati che l'ID richiesto sia valido (es. > 0)
                if ($requestedSchoolId > 0) {
                    $schoolIdToFetch = $requestedSchoolId;
                }
            }
        } else {
            $canViewOwnUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.view.own_section');
            if (!$canViewOwnUsers) {
                $this->error("Accesso Negato: Non hai i permessi per vedere gli utenti", 403);
            }
        }

        // Se, dopo i controlli, non abbiamo un ID scuola valido (es. utente non associato a scuola
        // e non ha permesso di vedere tutte le scuole o non ha specificato un ID valido),
        // restituisci un errore.
        if ($schoolIdToFetch === null || $schoolIdToFetch <= 0) {
            $this->error('ID della scuola non disponibile o non valido per il recupero degli utenti.', 400);
            return;
        }

        // Prepara la query per recuperare gli utenti.
        // Ho aggiunto la colonna 'roles' alla SELECT. Assicurati che esista nella tua tabella 'users'.
        // Se i ruoli sono in una tabella separata, avrai bisogno di un JOIN.
        $stmt = $conn->prepare("
    SELECT 
        u.id,
        u.name,
        u.email,
        u.school_id,
        u.created_at,
        JSON_ARRAYAGG(
            JSON_OBJECT(
                'id', r.id,
                'name', r.name,
                'level', r.level,
                'color', r.color,
                'permissions', IFNULL(
                    (
                        SELECT JSON_ARRAYAGG(
                            JSON_OBJECT('id', p.id, 'name', p.name)
                        )
                        FROM role_permissions rp
                        INNER JOIN permissions p ON rp.permission_id = p.id
                        WHERE rp.role_id = r.id
                    ), JSON_ARRAY()
                )
            )
        ) AS roles
    FROM users u
    LEFT JOIN user_role ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.school_id = ?
    GROUP BY u.id, u.name, u.school_id
");
        $stmt->bind_param('i', $schoolIdToFetch);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $row['roles'] = $row['roles'] ? json_decode($row['roles'], true) : [];
            $users[] = $row;
        }

        $stmt->close();

        $this->json(['users' => $users], 200);
    }
}