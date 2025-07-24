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
    public function updateUser(): void // Rimosso $userIdToModify dal parametro
    {
        // Recupera l'ID dell'utente da modificare dal corpo JSON
        $userIdToModify = $this->requestData['id'] ?? null;
        if ($userIdToModify === null) {
            $this->error('ID utente da modificare mancante nella richiesta.', 400);
            return;
        }

        $conn = Connection::get();

        // Recupera i dettagli dell'utente target (nome, email) e dell'amministratore che effettua la modifica (nome)
        $targetUserName = '';
        $targetUserEmail = '';
        $stmt_get_target_user_details = $conn->prepare("SELECT name, email, school_id FROM users WHERE id = ? LIMIT 1;");
        $stmt_get_target_user_details->bind_param('i', $userIdToModify);
        $stmt_get_target_user_details->execute();
        $stmt_get_target_user_details->bind_result($targetUserName, $targetUserEmail, $targetUserSchoolId);
        $stmt_get_target_user_details->fetch();
        $stmt_get_target_user_details->close();

        if ($targetUserName === null) { // L'utente target non esiste
            $this->error('Utente da modificare non trovato.', 404);
            return;
        }

        $updaterAdminName = '';
        $stmt_get_updater_admin_name = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1;");
        $stmt_get_updater_admin_name->bind_param('i', $this->currentUserId);
        $stmt_get_updater_admin_name->execute();
        $stmt_get_updater_admin_name->bind_result($updaterAdminName);
        $stmt_get_updater_admin_name->fetch();
        $stmt_get_updater_admin_name->close();


        // 2. Verifica Autorizzazione
        // Super Admin può fare tutto
        $canManageAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.manage_all_users');

        // Admin può gestire utenti nella propria sezione
        $canManageOwnSectionUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.manage_own_section_users');
        $isOwnSectionUser = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetUserSchoolId);

        // L'utente corrente può modificare l'utente target se:
        // A) È un Super Admin (ha manage_all_users)
        // B) È un Admin (ha manage_own_section_users) E l'utente target è nella sua stessa sezione
        if (!$canManageAllUsers && (!$canManageOwnSectionUsers || !$isOwnSectionUser)) {
            $this->error('Accesso negato: Permessi insufficienti per modificare questo utente o utente non nella tua sezione.', 403);
            return;
        }

        // 3. Recupera e valida i dati per la modifica (da $this->requestData)
        $updateFields = [];
        $bindParams = [];
        $types = '';
        $roleToAssign = null; // Per la gestione dei ruoli
        $passwordUpdated = false; // Flag per l'invio dell'email

        if (isset($this->requestData['name'])) {
            $updateFields[] = "name = ?";
            $bindParams[] = trim($this->requestData['name']);
            $types .= 's';
        }
        if (isset($this->requestData['email'])) {
            if (!filter_var($this->requestData['email'], FILTER_VALIDATE_EMAIL)) {
                $this->error('Formato email non valido.', 400);
                return;
            }
            // Verifica unicità email se modificata
            $stmt_check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1;");
            $stmt_check_email->bind_param('si', $this->requestData['email'], $userIdToModify);
            $stmt_check_email->execute();
            $stmt_check_email->store_result();
            if ($stmt_check_email->num_rows > 0) {
                $stmt_check_email->close();
                $this->error('La nuova email è già registrata per un altro utente.', 409);
                return;
            }
            $stmt_check_email->close();

            $updateFields[] = "email = ?";
            $bindParams[] = trim($this->requestData['email']);
            $types .= 's';
        }
        if (isset($this->requestData['password'])) {
            if (strlen($this->requestData['password']) < 6) {
                $this->error('La nuova password deve contenere almeno 6 caratteri.', 400);
                return;
            }
            $updateFields[] = "password_hash = ?";
            $bindParams[] = password_hash($this->requestData['password'], PASSWORD_BCRYPT);
            $types .= 's';
            $passwordUpdated = true; // Imposta il flag
        }
        if (isset($this->requestData['school_id'])) { // Permetti anche la modifica della school_id, se necessario
            // Solo Super Admin può modificare la school_id di altri utenti
            if (!$canManageAllUsers) {
                $this->error('Accesso negato: Non hai i permessi per modificare l\'ID della scuola.', 403);
                return;
            }
            $updateFields[] = "school_id = ?";
            $bindParams[] = $this->requestData['school_id'];
            $types .= 'i';
        }

        if (isset($this->requestData['role'])) {
            $roleToAssign = $this->requestData['role'];
            // Verifica permesso specifico per assegnare ruoli
            $canAssignRoles = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.assign_roles');
            // Verifica permesso per elevare privilegi
            $canElevatePrivileges = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.elevate_privileges');

            if (!$canAssignRoles) {
                $this->error('Accesso negato: Permessi insufficienti per assegnare ruoli.', 403);
                return;
            }

            // Recupera l'ID del nuovo ruolo
            $stmt_new_role = $conn->prepare("SELECT id, level FROM roles WHERE name = ? LIMIT 1;");
            $stmt_new_role->bind_param('s', $roleToAssign);
            $stmt_new_role->execute();
            $stmt_new_role->bind_result($newRoleId, $newRoleLevel);
            $stmt_new_role->fetch();
            $stmt_new_role->close();

            if ($newRoleId === null) {
                $this->error('Ruolo specificato non valido.', 400);
                return;
            }

            // Recupera il livello massimo del ruolo dell'utente corrente
            $currentUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($this->currentUserId);
            // Recupera il livello massimo del ruolo attuale dell'utente target
            $targetUserMaxRoleLevel = $this->permissionChecker->getUserMaxRoleLevel($userIdToModify);

            // Regole di gerarchia:
            // Se l'utente corrente NON ha il permesso di elevare privilegi (cioè non è un Super Admin):
            if (!$canElevatePrivileges) {
                // 1. Non può assegnare un ruolo con livello uguale o superiore al suo livello massimo.
                if ($newRoleLevel >= $currentUserMaxRoleLevel) {
                    $this->error('Accesso negato: Non puoi assegnare un ruolo con privilegi pari o superiori ai tuoi.', 403);
                    return;
                }
                // 2. Non può modificare un utente che ha un ruolo con livello uguale o superiore al suo livello massimo.
                if ($targetUserMaxRoleLevel >= $currentUserMaxRoleLevel) {
                    $this->error('Accesso negato: Non puoi modificare il ruolo di un utente con privilegi pari o superiori ai tuoi.', 403);
                    return;
                }
            }

            // Elimina i ruoli esistenti dell'utente e assegna il nuovo ruolo
            $conn->begin_transaction(); // Inizia una transazione per atomicita'
            try {
                $stmt_delete_roles = $conn->prepare("DELETE FROM user_role WHERE user_id = ?;");
                $stmt_delete_roles->bind_param('i', $userIdToModify);
                $stmt_delete_roles->execute();
                $stmt_delete_roles->close();

                $stmt_add_role = $conn->prepare("INSERT INTO user_role (user_id, role_id) VALUES (?, ?);");
                $stmt_add_role->bind_param('ii', $userIdToModify, $newRoleId);
                $stmt_add_role->execute();
                $stmt_add_role->close();

                $conn->commit(); // Conferma la transazione
            } catch (\Exception $e) {
                $conn->rollback(); // Annulla la transazione in caso di errore
                error_log("Errore durante la modifica del ruolo utente: " . $e->getMessage());
                $this->error('Errore durante la modifica del ruolo dell\'utente.', 500);
                return;
            }
        }

        if (empty($updateFields) && $roleToAssign === null) {
            $this->error('Nessun dato fornito per l\'aggiornamento o il ruolo è lo stesso.', 400);
            return;
        }

        // 4. Costruisci ed esegui la query di aggiornamento per i campi dell'utente (se ce ne sono)
        if (!empty($updateFields)) {
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?;";
            $stmt_update = $conn->prepare($sql);

            $bindParams[] = $userIdToModify; // Aggiungi l'ID utente alla fine dei parametri
            $types .= 'i'; // Aggiungi il tipo 'i' per l'ID utente

            $refs = [];
            foreach ($bindParams as $key => $value) {
                $refs[$key] = &$bindParams[$key];
            }
            call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

            if (!$stmt_update->execute()) {
                $this->error('Errore durante l\'aggiornamento dei dati dell\'utente: ' . $conn->error, 500);
                $stmt_update->close();
                return;
            }
            $stmt_update->close(); // Chiudi lo statement dopo l'esecuzione

            // Invia l'email se la password è stata aggiornata
            if ($passwordUpdated) {
                // Prepara i dati per il template dell'email
                $template_vars = [
                    'name' => $targetUserName,
                    'email' => $targetUserEmail,
                    'admin_name' => $updaterAdminName,
                    'update_date' => date('d/m/Y'),
                    'update_time' => date('H:i'),
                ];

                // Avvia l'output buffering per catturare il contenuto HTML del template
                ob_start();
                // Includi il file del template email. Assicurati che il percorso sia corretto.
                // Esempio: se il template è in 'views/emails/admin_password_updated_email_template.php'
                include __DIR__ . '/../../templates/email/reset_password_by_admin.php';
                $email_html_body = ob_get_clean(); // Ottieni il contenuto del buffer

                // Invia l'email
                // Assumi che Mail::sendMail($to, $subject, $html_body, $from_name = null, $from_email = null)
                try {
                    Mail::sendMail(
                        $targetUserEmail,
                        'Aggiornamento Password del tuo account',
                        $email_html_body,
                       "",
                    );
                } catch (\Exception $e) {
                    error_log("Errore nell'invio dell'email di aggiornamento password all'utente: " . $e->getMessage());
                    // Non bloccare la risposta HTTP di successo se l'email fallisce, ma logga l'errore
                }

                ob_start();
                include __DIR__ . '/../../templates/email/admin_reset_password.php';
                $email_html_body = ob_get_clean();

                try{
                    Mail::sendMail(
                        $this->email_user,
                        "Aggiornamento Password di un account",
                        $email_html_body
                    );
                } catch (\Exception $e){
                    error_log("Errore nell'invio dell'email di aggiornamento password all'admin: " . $e->getMessage());
                }
            }
        }

        $this->json(['message' => 'Utente aggiornato con successo.'], 200);
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
        $stmt_target_user->bind_result($targetUserSchoolId);
        $stmt_target_user->fetch();
        $stmt_target_user->close();

        if ($targetUserSchoolId === null) { // L'utente target non esiste
            $this->error('Utente da eliminare non trovato.', 404);
            return;
        }

        // 2. Verifica Autorizzazione
        // Super Admin può fare tutto
        $canManageAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.manage_all_users');

        // Admin può gestire utenti nella propria sezione
        $canManageOwnSectionUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.manage_own_section_users');
        $isOwnSectionUser = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetUserSchoolId);

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
}