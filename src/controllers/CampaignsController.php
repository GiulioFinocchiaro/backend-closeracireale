<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class CampaignsController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null; // ID dell'utente autenticato (INT)
    private ?int $currentUserSchoolId = null; // school_id dell'utente autenticato
    private array $requestData; // Per i dati JSON in input
    private bool $canViewAll;
    private bool $canViewOwn;

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
            $this->currentUserId = $decodedToken->sub; // Sarà un INT dal JWT

            // ===============================
            // LOGICA PER PERMESSI E SCHOOL_ID
            // ===============================
            $this->canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, "campaign.view_all");
            $this->canViewOwn = $this->permissionChecker->userHasPermission($this->currentUserId, "campaign.view_own_school");

            if ($this->canViewAll) {
                $schoolId = $_POST["school_id"] ?? $this->requestData["school_id"] ?? null;
                if ($schoolId === null) {
                    echo $schoolId;
                    $this->error("`school_id` obbligatorio per visualizzare asset di un'altra scuola.", 400);
                    return;
                }
                $this->currentUserSchoolId = (int) $schoolId;
            } elseif ($this->canViewOwn) {
                if (isset($this->requestData["school_id"]) && (int)$this->requestData["school_id"] !== $this->currentUserSchoolId) {
                    $this->error("Non hai i permessi per accedere agli asset di un'altra scuola.", 403);
                    return;
                }
            } else {
                $this->error("Accesso negato: permessi insufficienti.", 403);
                return;
            }

        } catch (\Exception $e) {
            $this->error('Autenticazione fallita: ' . $e->getMessage(), 401);
            return;
        }
    }

    /**
     * Creates a new election campaign.
     * Permissions: 'campaigns.create'
     * Required JSON data: 'title', 'description', 'status' (optional, default 'draft'), 'candidate_ids' (array of candidate IDs)
     *
     * @return void
     */
    public function addCampaign(): void
    {
        // Check permission
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.add')) {
            $this->error('Access denied: Insufficient permissions to create campaigns.', 403);
            return;
        }

        $conn = Connection::get();
        $conn->begin_transaction(); // Start a transaction for multiple operations

        try {
            // 1. Retrieve and validate campaign data
            if (!isset($this->requestData['title']) || !isset($this->requestData['description'])) {
                $this->error('Missing or invalid data: title, description, and a non-empty array of candidate_ids are required.', 400);
                $conn->rollback();
                return;
            }

            $title = trim($this->requestData['title']);
            $description = trim($this->requestData['description']);
            $status = $this->requestData['status'] ?? 'draft'; // Default 'draft'

            if (empty($title) || empty($description)) {
                $this->error('Title and description cannot be empty.', 400);
                $conn->rollback();
                return;
            }


            // 3. Insert the new campaign
            $stmt_insert_campaign = $conn->prepare("INSERT INTO campaigns (title, description, status, created_at, school_id) VALUES (?, ?, ?, NOW(), ?);");
            $stmt_insert_campaign->bind_param('sssi', $title, $description, $status, $this->currentUserSchoolId);

            if (!$stmt_insert_campaign->execute()) {
                $this->error('Error creating campaign: ' . $conn->error, 500);
                $conn->rollback();
                $stmt_insert_campaign->close();
                return;
            }
            $newCampaignId = $conn->insert_id;
            $stmt_insert_campaign->close();

            $conn->commit(); // Confirm the transaction

            // Retrieve the newly created campaign for the response (with associated candidates)
            $newCampaign = $this->getCampaignDetailsById($newCampaignId); // Helper function
            if ($newCampaign === null) {
                $this->error('Error retrieving the newly created campaign.', 500);
                return;
            }

            $this->json(['message' => 'Campaign created successfully.', 'campaign' => $newCampaign], 201);

        } catch (\Exception $e) {
            $conn->rollback(); // Rollback the transaction in case of error
            error_log("Error in addCampaign: " . $e->getMessage());
            $this->error('Internal server error during campaign creation.', 500);
        }
    }

    /**
     * Displays the details of a single campaign, including associated candidates, events, and materials.
     * The campaign ID to display is taken from $this->requestData['id'].
     * Permissions:
     * - 'campaigns.view_all' (view any campaign)
     * - 'campaigns.view_own_school' (view campaigns from their own school)
     * - 'campaigns.view_own_candidate' (view their own candidate campaigns)
     *
     * @return void
     */
    public function getSingleCampaign(): void
    {
        $conn = Connection::get();

        $campaignId = $this->requestData['id'] ?? null;
        if ($campaignId === null) {
            $this->error('Missing campaign ID to display in the request.', 400);
            return;
        }
        $campaignId = (int)$campaignId; // Cast to INT

        $campaign = $this->getCampaignDetailsById($campaignId);

        if ($campaign === null) {
            $this->error('Campaign not found.', 404);
            return;
        }

        // 2. Authorization check
        // Retrieve the user_ids of all candidates associated with this campaign
        $associatedCandidateUserIds = [];
        $stmt_candidate_users = $conn->prepare("SELECT u.id FROM users u JOIN candidates cand ON u.id = cand.user_id JOIN campaign_candidates cc ON cand.id = cc.candidate_id WHERE cc.campaign_id = ?;");
        $stmt_candidate_users->bind_param('i', $campaignId);
        $stmt_candidate_users->execute();
        $result_candidate_users = $stmt_candidate_users->get_result();
        while ($row = $result_candidate_users->fetch_assoc()) {
            $associatedCandidateUserIds[] = $row['id'];
        }
        $stmt_candidate_users->close();

        $isOwnCampaign = ($this->currentUserId !== null && in_array($this->currentUserId, $associatedCandidateUserIds));
        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $campaign['school_id']);

        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaigns.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaigns.view_own_school');
        $canViewOwnCandidate = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaigns.view_own_candidate');


        if (!$canViewAll && !($canViewOwnSchool && $isOwnSchoolCampaign) && !($canViewOwnCandidate && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to view this campaign.', 403);
            return;
        }

        $this->json($campaign, 200);
    }

    /**
     * Displays a list of campaigns, including associated candidates, events, and materials.
     * This method can filter by candidate_id (if provided in JSON) or by the current user's school_id.
     * Permissions:
     * - 'campaigns.view_all' (view all campaigns)
     * - 'campaigns.view_own_school' (view campaigns from their own school)
     * - 'campaigns.view_own_candidate' (view their own candidate campaigns)
     *
     * @return void
     */
    public function getCampaigns(): void
    {
        $conn = Connection::get();
        $campaigns = [];

        // Check permissions
        $canViewAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.view_all');
        $canViewOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.view_own_school');
        $canViewOwnCandidate = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.view_own_candidate');

        if (!$canViewAll && !$canViewOwnSchool && !$canViewOwnCandidate) {
            $this->error('Access denied: Insufficient permissions to view campaigns.', 403);
            return;
        }

        // Base query with subqueries for counts
        $sql = "SELECT 
                c.id, 
                c.title, 
                c.description, 
                c.status, 
                c.created_at, 
                c.school_id,
                (SELECT COUNT(*) FROM campaign_candidates cc WHERE cc.campaign_id = c.id) AS candidates_count,
                (SELECT COUNT(*) FROM campaign_materials cm WHERE cm.campaign_id = c.id) AS materials_count,
                (SELECT COUNT(*) FROM campaign_events ce WHERE ce.campaign_id = c.id) AS events_count
            FROM campaigns c";

        $params = [];
        $types = '';
        $whereClauses = [];

        // Filter by candidate_id if provided
        $requestedCandidateId = $this->requestData['candidate_id'] ?? null;
        if ($requestedCandidateId !== null) {
            $requestedCandidateId = (int)$requestedCandidateId;

            // Check ownership for candidate
            $targetCandidateUserId = null;
            $stmt_candidate_user_id = $conn->prepare("SELECT user_id FROM candidates WHERE id = ? LIMIT 1;");
            $stmt_candidate_user_id->bind_param('i', $requestedCandidateId);
            $stmt_candidate_user_id->execute();
            $stmt_candidate_user_id->bind_result($targetCandidateUserId);
            $stmt_candidate_user_id->fetch();
            $stmt_candidate_user_id->close();

            if (!$canViewAll && !$canViewOwnSchool && $canViewOwnCandidate && $this->currentUserId !== $targetCandidateUserId) {
                $this->error('Access denied: You cannot view campaigns of other candidates.', 403);
                return;
            }

            if (!$canViewAll && $canViewOwnSchool && $this->currentUserSchoolId !== null) {
                $stmt_check_candidate_school = $conn->prepare("SELECT school_id FROM candidates WHERE id = ? LIMIT 1;");
                $stmt_check_candidate_school->bind_param('i', $requestedCandidateId);
                $stmt_check_candidate_school->execute();
                $stmt_check_candidate_school->bind_result($candSchoolId);
                $stmt_check_candidate_school->fetch();
                $stmt_check_candidate_school->close();

                if ($candSchoolId !== $this->currentUserSchoolId) {
                    $this->error('Access denied: The specified candidate does not belong to your school.', 403);
                    return;
                }
            }

            $sql .= " JOIN campaign_candidates cc ON c.id = cc.campaign_id";
            $whereClauses[] = "cc.candidate_id = ?";
            $params[] = $requestedCandidateId;
            $types .= 'i';
        } else {
            // Permissions filter
            if (!$canViewAll) {
                if ($canViewOwnSchool && $this->currentUserSchoolId !== null) {
                    $whereClauses[] = "c.school_id = ?";
                    $params[] = $this->currentUserSchoolId;
                    $types .= 'i';
                } elseif ($canViewOwnCandidate && $this->currentUserId !== null) {
                    $stmt_get_candidate_id = $conn->prepare("SELECT id FROM candidates WHERE user_id = ? LIMIT 1;");
                    $stmt_get_candidate_id->bind_param('i', $this->currentUserId);
                    $stmt_get_candidate_id->execute();
                    $stmt_get_candidate_id->bind_result($ownCandidateId);
                    $stmt_get_candidate_id->fetch();
                    $stmt_get_candidate_id->close();

                    if ($ownCandidateId !== null) {
                        $sql .= " JOIN campaign_candidates cc ON c.id = cc.campaign_id";
                        $whereClauses[] = "cc.candidate_id = ?";
                        $params[] = $ownCandidateId;
                        $types .= 'i';
                    } else {
                        $this->json([], 200);
                        return;
                    }
                } else {
                    $this->json([], 200);
                    return;
                }
            }
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $sql .= " ORDER BY c.created_at DESC";

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
            $campaigns[] = $row;
        }
        $stmt->close();

        unset($campaign);

        $this->json($campaigns, 200);
    }

    /**
     * Modifies existing campaign data and its associated candidates.
     * The campaign ID to modify is taken from $this->requestData['id'].
     * Permissions:
     * - 'campaigns.update_all' (modify any campaign)
     * - 'campaigns.update_own_school' (modify campaigns from their own school)
     * - 'campaigns.update_own_candidate' (modify their own candidate campaigns)
     * Modifiable JSON data: 'title', 'description', 'status', 'candidate_ids' (array of candidate IDs)
     *
     * @return void
     */
    public function updateCampaign(): void
    {
        $conn = Connection::get();
        $conn->begin_transaction();

        try {
            $campaignIdToModify = $this->requestData['id'] ?? null;
            if ($campaignIdToModify === null) {
                $this->error('Missing campaign ID to modify in the request.', 400);
                $conn->rollback();
                return;
            }
            $campaignIdToModify = (int)$campaignIdToModify; // Cast to INT

            // Retrieve target campaign details (school_id)
            $targetSchoolId = null;
            $stmt_target_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
            $stmt_target_campaign->bind_param('i', $campaignIdToModify);
            $stmt_target_campaign->execute();
            $stmt_target_campaign->bind_result($targetSchoolId);
            $stmt_target_campaign->fetch();
            $stmt_target_campaign->close();

            if ($targetSchoolId === null) { // Campaign not found
                $this->error('Campaign to modify not found.', 404);
                $conn->rollback();
                return;
            }

            // Retrieve user_ids of all candidates associated with this campaign for permissions
            $associatedCandidateUserIds = [];
            $stmt_candidate_users = $conn->prepare("SELECT u.id FROM users u JOIN candidates cand ON u.id = cand.user_id JOIN campaign_candidates cc ON cand.id = cc.candidate_id WHERE cc.campaign_id = ?;");
            $stmt_candidate_users->bind_param('i', $campaignIdToModify);
            $stmt_candidate_users->execute();
            $result_candidate_users = $stmt_candidate_users->get_result();
            while ($row = $result_candidate_users->fetch_assoc()) {
                $associatedCandidateUserIds[] = $row['id'];
            }
            $stmt_candidate_users->close();

            $isOwnCampaign = ($this->currentUserId !== null && in_array($this->currentUserId, $associatedCandidateUserIds));
            $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

            // 2. Authorization check
            $canUpdateAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.update_all_school');
            $canUpdateOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.update_own_school');
            $canUpdateOwnCandidate = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.update_own_candidate');

            if (!$canUpdateAll && !($canUpdateOwnSchool && $isOwnSchoolCampaign) && !($canUpdateOwnCandidate && $isOwnCampaign)) {
                $this->error('Access denied: Insufficient permissions to modify this campaign.', 403);
                $conn->rollback();
                return;
            }

            // 3. Retrieve and validate data for modification
            $updateFields = [];
            $bindParams = [];
            $types = '';

            if (isset($this->requestData['title'])) {
                $title = trim($this->requestData['title']);
                if (empty($title)) {
                    $this->error('The title cannot be empty.', 400);
                    $conn->rollback();
                    return;
                }
                $updateFields[] = "title = ?";
                $bindParams[] = $title;
                $types .= 's';
            }
            if (isset($this->requestData['description'])) {
                $description = trim($this->requestData['description']);
                if (empty($description)) {
                    $this->error('The description cannot be empty.', 400);
                    $conn->rollback();
                    return;
                }
                $updateFields[] = "description = ?";
                $bindParams[] = $description;
                $types .= 's';
            }
            if (isset($this->requestData['status'])) {
                $status = trim($this->requestData['status']);
                if (!in_array($status, ['draft', 'activate'])) {
                    $this->error('Invalid campaign status.', 400);
                    $conn->rollback();
                    return;
                }
                $updateFields[] = "status = ?";
                $bindParams[] = $status;
                $types .= 's';
            }

            // Handle associated candidates (if provided)
            if (isset($this->requestData['candidate_ids'])) {
                if (!is_array($this->requestData['candidate_ids'])) {
                    $this->error('candidate_ids must be an array.', 400);
                    $conn->rollback();
                    return;
                }
                $newCandidateIds = array_map('intval', $this->requestData['candidate_ids']);

                // Verify that the new candidates exist and belong to the same school (if not super admin)
                $canManageAllUsers = $this->permissionChecker->userHasPermission($this->currentUserId, 'users.manage_all_users');
                foreach ($newCandidateIds as $candId) {
                    $stmt_check_candidate = $conn->prepare("SELECT school_id FROM candidates WHERE id = ? LIMIT 1;");
                    $stmt_check_candidate->bind_param('i', $candId);
                    $stmt_check_candidate->execute();
                    $stmt_check_candidate->bind_result($candSchoolId);
                    $stmt_check_candidate->fetch();
                    $stmt_check_candidate->close();

                    if ($candSchoolId === null) {
                        $this->error("Candidate with ID {$candId} not found for update.", 404);
                        $conn->rollback();
                        return;
                    }
                    if (!$canManageAllUsers && $this->currentUserSchoolId !== null && $candSchoolId !== $this->currentUserSchoolId) {
                        $this->error("Access denied: You cannot associate candidates from another school with this campaign.", 403);
                        $conn->rollback();
                        return;
                    }
                }

                // Update the campaign_candidates table
                $stmt_delete_cc = $conn->prepare("DELETE FROM campaign_candidates WHERE campaign_id = ?;");
                $stmt_delete_cc->bind_param('i', $campaignIdToModify);
                $stmt_delete_cc->execute();
                $stmt_delete_cc->close();

                if (!empty($newCandidateIds)) {
                    $stmt_insert_cc = $conn->prepare("INSERT INTO campaign_candidates (campaign_id, candidate_id) VALUES (?, ?);");
                    foreach ($newCandidateIds as $candId) {
                        $stmt_insert_cc->bind_param('ii', $campaignIdToModify, $candId);
                        if (!$stmt_insert_cc->execute()) {
                            $this->error('Error updating candidate association: ' . $conn->error, 500);
                            $conn->rollback();
                            $stmt_insert_cc->close();
                            return;
                        }
                    }
                    $stmt_insert_cc->close();
                }
            }

            if (empty($updateFields) && !isset($this->requestData['candidate_ids'])) {
                $this->error('No data provided for update.', 400);
                $conn->rollback();
                return;
            }

            // Build and execute the update query for campaign fields
            if (!empty($updateFields)) {
                $sql = "UPDATE campaigns SET " . implode(', ', $updateFields) . " WHERE id = ?;";
                $stmt_update = $conn->prepare($sql);

                $bindParams[] = $campaignIdToModify;
                $types .= 'i'; // Bind as INT

                $refs = [];
                foreach ($bindParams as $key => $value) {
                    $refs[$key] = &$bindParams[$key];
                }
                call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

                if (!$stmt_update->execute()) {
                    $this->error('Error updating campaign: ' . $conn->error, 500);
                    $conn->rollback();
                    $stmt_update->close();
                    return;
                }
                $stmt_update->close();
            }

            $conn->commit(); // Confirm the transaction
            $this->json(['message' => 'Campaign updated successfully.'], 200);

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Error in updateCampaign: " . $e->getMessage());
            $this->error('Internal server error during campaign update.', 500);
        }
    }

    /**
     * Deletes a campaign.
     * The campaign ID to delete is taken from $this->requestData['id'].
     * Permissions:
     * - 'campaigns.delete_all' (delete any campaign)
     * - 'campaigns.delete_own_school' (delete campaigns from their own school)
     *
     * @return void
     */
    public function deleteCampaign(): void
    {
        $conn = Connection::get();
        $conn->begin_transaction(); // Start a transaction

        try {
            $campaignIdToDelete = $this->requestData['id'] ?? null;
            if ($campaignIdToDelete === null) {
                $this->error('Missing campaign ID to delete in the request.', 400);
                $conn->rollback();
                return;
            }
            $campaignIdToDelete = (int)$campaignIdToDelete; // Cast to INT

            // Retrieve target campaign details (school_id)
            $targetSchoolId = null;
            $stmt_target_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
            $stmt_target_campaign->bind_param('i', $campaignIdToDelete);
            $stmt_target_campaign->execute();
            $stmt_target_campaign->bind_result($targetSchoolId);
            $stmt_target_campaign->fetch();
            $stmt_target_campaign->close();

            if ($targetSchoolId === null) { // Campaign not found
                $this->error('Campaign to delete not found.', 404);
                $conn->rollback();
                return;
            }

            // Retrieve user_ids of all candidates associated with this campaign for permissions
            $associatedCandidateUserIds = [];
            $stmt_candidate_users = $conn->prepare("SELECT u.id FROM users u JOIN candidates cand ON u.id = cand.user_id JOIN campaign_candidates cc ON cand.id = cc.candidate_id WHERE cc.campaign_id = ?;");
            $stmt_candidate_users->bind_param('i', $campaignIdToDelete);
            $stmt_candidate_users->execute();
            $result_candidate_users = $stmt_candidate_users->get_result();
            while ($row = $result_candidate_users->fetch_assoc()) {
                $associatedCandidateUserIds[] = $row['id'];
            }
            $stmt_candidate_users->close();

            $isOwnCampaign = ($this->currentUserId !== null && in_array($this->currentUserId, $associatedCandidateUserIds));
            $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

            // 2. Authorization check
            $canDeleteAll = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.delete_all_school');
            $canDeleteOwnSchool = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.delete_own_school');
            $canDeleteOwnCandidate = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign.delete_own_candidate'); // Added for consistency, though less common

            if (!$canDeleteAll && !($canDeleteOwnSchool && $isOwnSchoolCampaign) && !($canDeleteOwnCandidate && $isOwnCampaign)) {
                $this->error('Access denied: Insufficient permissions to delete this campaign.', 403);
                $conn->rollback();
                return;
            }

            // 3. Delete the campaign
            // FKs with ON DELETE CASCADE will handle deleting associations in campaign_candidates,
            // events in campaign_events, and materials in campaign_materials.
            $stmt_delete = $conn->prepare("DELETE FROM campaigns WHERE id = ?;");
            $stmt_delete->bind_param('i', $campaignIdToDelete);

            if (!$stmt_delete->execute()) {
                throw new \Exception('Error deleting campaign: ' . $conn->error);
            }
            $stmt_delete->close();

            $conn->commit(); // Confirm the transaction
            $this->json(['message' => 'Campaign deleted successfully.'], 200);

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Error in deleteCampaign: " . $e->getMessage());
            $this->error('Internal server error during campaign deletion.', 500);
        }
    }

    /**
     * Helper function to retrieve all details of a campaign (including candidates, events, materials).
     *
     * @param int $campaignId The ID of the campaign.
     * @return array|null The campaign details or null if not found.
     */
    private function getCampaignDetailsById(int $campaignId): ?array
    {
        $conn = Connection::get();
        $campaign = null;

        // Retrieve basic campaign details
        $stmt = $conn->prepare("SELECT id, title, description, status, created_at, school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaign = $result->fetch_assoc();
        $stmt->close();

        if (!$campaign) {
            return null;
        }

        // Retrieve candidates associated with the campaign
        $campaign['candidates'] = [];
        $stmt_candidates = $conn->prepare("
            SELECT c.id, c.user_id, c.class_year, c.description, c.photo, c.manifesto, u.name as user_name, u.email as user_email
            FROM candidates c
            JOIN campaign_candidates cc ON c.id = cc.candidate_id
            JOIN users u ON c.user_id = u.id
            WHERE cc.campaign_id = ? ORDER BY u.name ASC;
        ");
        $stmt_candidates->bind_param('i', $campaignId);
        $stmt_candidates->execute();
        $candidates_result = $stmt_candidates->get_result();
        while ($candidate_row = $candidates_result->fetch_assoc()) {
            $campaign['candidates'][] = $candidate_row;
        }
        $stmt_candidates->close();

        // Retrieve events associated with the campaign
        $campaign['events'] = [];
        $stmt_events = $conn->prepare("SELECT id, event_name, event_description, event_date, location, created_at FROM campaign_events WHERE campaign_id = ? ORDER BY event_date ASC;");
        $stmt_events->bind_param('i', $campaignId);
        $stmt_events->execute();
        $events_result = $stmt_events->get_result();
        while ($event_row = $events_result->fetch_assoc()) {
            $campaign['events'][] = $event_row;
        }
        $stmt_events->close();

        // Retrieve materials associated with the campaign
        $campaign['materials'] = [];
        $stmt_materials = $conn->prepare("SELECT id, material_name, material_type, file_url, description, created_at FROM campaign_materials WHERE campaign_id = ? ORDER BY created_at ASC;");
        $stmt_materials->bind_param('i', $campaignId);
        $stmt_materials->execute();
        $materials_result = $stmt_materials->get_result();
        while ($material_row = $materials_result->fetch_assoc()) {
            $campaign['materials'][] = $material_row;
        }
        $stmt_materials->close();

        return $campaign;
    }
}