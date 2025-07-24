<?php

namespace Core; // Assicurati che il namespace sia appropriato per il tuo progetto

use Database\Connection;
use Helpers\Response;

// La tua classe personalizzata per la connessione al database

class RoleChecker {
    // La classe non ha più bisogno di una proprietà $mysqli
    // perché la ottiene al momento dalla Connection::get()

    /**
     * Verifica se un utente ha un permesso specifico.
     *
     * @param int $userId L'ID dell'utente da controllare.
     * @param string $permissionName Il nome interno del permesso da verificare (es. 'users.register_new_users', 'content.manage_articles').
     * @return bool True se l'utente ha il permesso, false altrimenti.
     */
    public function userHasPermission(int $userId, string $permissionName): bool {
        // Controllo base: se il nome del permesso è vuoto, ritorna falso
        if (empty($permissionName)) {
            return false;
        }

        // Ottieni l'istanza di connessione MySQLi dalla tua classe Connection
        $conn = Connection::get();

        // Verifica se la connessione è stata ottenuta correttamente
        if ($conn === null) {
            error_log("Errore: Connessione al database non disponibile per PermissionChecker::userHasPermission.");
            // Potresti voler lanciare un'eccezione qui invece di un semplice return false
            return false;
        }

        $sql = "
            SELECT
                COUNT(rp.permission_id)
            FROM
                users u
            JOIN
                user_role ur ON u.id = ur.user_id
            JOIN
                roles r ON ur.role_id = r.id
            JOIN
                role_permissions rp ON r.id = rp.role_id
            JOIN
                permissions p ON rp.permission_id = p.id
            WHERE
                u.id = ?
                AND p.name = ?
            LIMIT 1; -- Ottimizzazione: ci basta sapere se esiste almeno una corrispondenza
        ";

        try {
            // Prepara lo statement SQL
            $stmt = $conn->prepare($sql);

            // Gestisci eventuali errori nella preparazione dello statement
            if ($stmt === false) {
                error_log("Errore di preparazione dello statement MySQLi in userHasPermission: " . $conn->error);
                return false;
            }

            // Binda i parametri: 'i' per l'ID utente (integer), 's' per il nome del permesso (string)
            $stmt->bind_param('is', $userId, $permissionName);

            // Esegui lo statement
            $stmt->execute();

            // Lega il risultato della COUNT() a una variabile
            $stmt->bind_result($count);

            // Recupera la riga del risultato
            $stmt->fetch();

            // Chiudi lo statement per liberare le risorse del server
            $stmt->close();

            // Restituisci TRUE se il conteggio è maggiore di 0 (l'utente ha il permesso), altrimenti FALSE
            return $count > 0;

        } catch (\Exception $e) {
            // Gestione di qualsiasi altra eccezione che possa verificarsi (es. problemi di connessione, query malformata)
            error_log("Errore in PermissionChecker::userHasPermission (MySQLi): " . $e->getMessage());
            return false; // Ritorno sicuro in caso di errore critico
        }
    }

    /**
     * Verifica se un utente ha almeno uno dei ruoli specificati.
     * Questo metodo è mantenuto se vuoi ancora controllare i ruoli direttamente.
     *
     * @param int $userId L'ID dell'utente da controllare.
     * @param string|array $roles Un singolo nome di ruolo (stringa) o un array di nomi di ruoli.
     * @return bool True se l'utente ha almeno uno dei ruoli, false altrimenti.
     */
    public function userHasRole(int $userId, string|array $roles): bool {
        // Se $roles è una stringa, la convertiamo in un array per uniformità
        if (is_string($roles)) {
            $roles = [$roles];
        }

        if (empty($roles)) {
            return false; // Nessun ruolo specificato per la verifica
        }

        // Creiamo un placeholder per la clausola IN per i ruoli (es: ?, ?, ?)
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));

        $conn = Connection::get();
        if ($conn === null) {
            error_log("Errore: Connessione al database non disponibile per PermissionChecker::userHasRole.");
            return false;
        }

        $sql = "
            SELECT
                COUNT(ur.user_id)
            FROM
                users u
            JOIN
                user_role ur ON u.id = ur.user_id
            JOIN
                roles r ON ur.role_id = r.id
            WHERE
                u.id = ?
                AND r.name IN ({$placeholders})
            LIMIT 1;
        ";

        try {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("Errore di preparazione dello statement MySQLi in userHasRole: " . $conn->error);
                return false;
            }

            $types = 'i' . str_repeat('s', count($roles));
            $params = array_merge([$userId], $roles);

            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = &$params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));

            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            return $count > 0;
        } catch (\Exception $e) {
            error_log("Errore in PermissionChecker::userHasRole (MySQLi): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera il livello massimo di privilegio per un dato utente.
     * Se l'utente ha più ruoli, restituisce il livello più alto.
     *
     * @param int $userId L'ID dell'utente.
     * @return int Il livello massimo del ruolo dell'utente, o 0 se l'utente non ha ruoli o non esiste.
     */
    public function getUserMaxRoleLevel(int $userId): int {
        $conn = Connection::get();
        if ($conn === null) {
            error_log("Errore: Connessione al database non disponibile per getUserMaxRoleLevel.");
            return 0;
        }

        $sql = "
            SELECT
                MAX(r.level)
            FROM
                users u
            JOIN
                user_role ur ON u.id = ur.user_id
            JOIN
                roles r ON ur.role_id = r.id
            WHERE
                u.id = ?;
        ";

        try {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("Errore di preparazione dello statement MySQLi in getUserMaxRoleLevel: " . $conn->error);
                return 0;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->bind_result($maxLevel);
            $stmt->fetch();
            $stmt->close();
            return $maxLevel ?? 0; // Restituisce 0 se non ci sono ruoli o il livello è NULL
        } catch (\Exception $e) {
            error_log("Errore in getUserMaxRoleLevel (MySQLi): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Recupera il livello di un ruolo dato il suo nome.
     *
     * @param string $roleName Il nome del ruolo (es. 'super_admin').
     * @return int|null Il livello del ruolo, o null se il ruolo non esiste.
     */
    public function getRoleLevelByName(string $roleName): ?int {
        $conn = Connection::get();
        if ($conn === null) {
            error_log("Errore: Connessione al database non disponibile per getRoleLevelByName.");
            return null;
        }

        $sql = "SELECT level FROM roles WHERE name = ? LIMIT 1;";
        try {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                error_log("Errore di preparazione dello statement MySQLi in getRoleLevelByName: " . $conn->error);
                return null;
            }
            $stmt->bind_param('s', $roleName);
            $stmt->execute();
            $stmt->bind_result($level);
            $stmt->fetch();
            $stmt->close();
            return $level;
        } catch (\Exception $e) {
            error_log("Errore in getRoleLevelByName (MySQLi): " . $e->getMessage());
            return null;
        }
    }
}