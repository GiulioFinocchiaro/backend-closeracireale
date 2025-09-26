<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class CampaignEventsController extends Controller
{
    private AuthMiddleware $authMiddleware;
    private RoleChecker $permissionChecker;
    private ?int $currentUserId = null;
    private ?int $currentUserSchoolId = null;
    private array $requestData;
    private bool $canViewAll;
    private bool $canViewOwn;

    public function __construct()
    {
        $this->requestData = json_decode(file_get_contents("php://input"), true) ?? [];

        $this->authMiddleware = new AuthMiddleware();
        $dbConnection = Connection::get();

        if ($dbConnection === null) {
            $this->error('Internal server error: database connection not available.', 500);
            return;
        }
        $this->permissionChecker = new RoleChecker();

        try {
            $decodedToken = $this->authMiddleware->authenticate();
            $this->currentUserId = $decodedToken->sub;

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
            $this->error('Authentication failed: ' . $e->getMessage(), 401);
            return;
        }
    }

    /**
     * Adds a new event to a campaign.
     * Permissions: 'campaign_events.create'
     * Required JSON data: 'campaign_id', 'event_name', 'event_description', 'event_date', 'location'
     *
     * @return void
     */
    public function addCampaignEvent(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.create_all')) {
            $this->error('Access denied: Insufficient permissions to add events.', 403);
            return;
        }

        $conn = Connection::get();

        if (!isset($this->requestData['campaign_id']) || !isset($this->requestData['event_name']) || !isset($this->requestData['event_date'])) {
            $this->error('Missing event data: campaign_id, event_name, and event_date are required.', 400);
            return;
        }

        $campaignId = (int)$this->requestData['campaign_id'];
        $eventName = trim($this->requestData['event_name']);
        $eventDescription = trim($this->requestData['event_description'] ?? '');
        $eventDate = trim($this->requestData['event_date']);
        $location = trim($this->requestData['location'] ?? '');

        // Date validation (simple, you can add more robust regex or strtotime)
        if (!\DateTime::createFromFormat('Y-m-d H:i:s', $eventDate) && !\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $eventDate)) {
            $this->error('Invalid event date format. Required YYYY-MM-DD HH:MM:SS or ISO 8601.', 400);
            return;
        }

        // Verify that the campaign exists and that the user has permissions to add events to it
        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        if ($targetSchoolId === null) {
            $this->error('Specified campaign for the event not found.', 404);
            return;
        }

        // Retrieve user_ids of all candidates associated with this campaign for permissions
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
        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        $canCreateAllEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.create_all'); // Assume a more generic permission
        $canCreateOwnSchoolEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.create_own_school');
        $canCreateOwnCampaignEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.create_own_campaign');


        if (!$canCreateAllEvents && !($canCreateOwnSchoolEvents && $isOwnSchoolCampaign) && !($canCreateOwnCampaignEvents && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to add events to this campaign.', 403);
            return;
        }

        $stmt_insert = $conn->prepare("INSERT INTO campaign_events (campaign_id, event_name, event_description, event_date, location, created_at) VALUES (?, ?, ?, ?, ?, NOW());");
        $stmt_insert->bind_param('issss', $campaignId, $eventName, $eventDescription, $eventDate, $location);

        if (!$stmt_insert->execute()) {
            $this->error('Error adding event: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }
        $newEventId = $conn->insert_id;
        $stmt_insert->close();

        $this->json(['message' => 'Event added successfully.', 'event_id' => $newEventId], 201);
    }

    /**
     * Modifies an existing campaign event.
     * Permissions: 'campaign_events.update_all', 'campaign_events.update_own_school', 'campaign_events.update_own_campaign'
     * Required JSON data: 'id' (event ID), 'event_name', 'event_description', 'event_date', 'location' (optional)
     *
     * @return void
     */
    public function updateCampaignEvent(): void
    {
        $conn = Connection::get();
        $eventIdToModify = $this->requestData['id'] ?? null;
        if ($eventIdToModify === null) {
            $this->error('Missing event ID to modify.', 400);
            return;
        }
        $eventIdToModify = (int)$eventIdToModify;

        // Retrieve event and associated campaign details
        $campaignId = null;
        $stmt_event = $conn->prepare("SELECT campaign_id FROM campaign_events WHERE id = ? LIMIT 1;");
        $stmt_event->bind_param('i', $eventIdToModify);
        $stmt_event->execute();
        $stmt_event->bind_result($campaignId);
        $stmt_event->fetch();
        $stmt_event->close();

        if ($campaignId === null) {
            $this->error('Event not found.', 404);
            return;
        }

        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        // Retrieve user_ids of all candidates associated with this campaign for permissions
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
        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        $canUpdateAllEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.update_all');
        $canUpdateOwnSchoolEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.update_own_school');
        $canUpdateOwnCampaignEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.update_own_campaign');

        if (!$canUpdateAllEvents && !($canUpdateOwnSchoolEvents && $isOwnSchoolCampaign) && !($canUpdateOwnCampaignEvents && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to modify this event.', 403);
            return;
        }

        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['event_name'])) {
            $updateFields[] = "event_name = ?";
            $bindParams[] = trim($this->requestData['event_name']);
            $types .= 's';
        }
        if (isset($this->requestData['event_description'])) {
            $updateFields[] = "event_description = ?";
            $bindParams[] = trim($this->requestData['event_description']);
            $types .= 's';
        }
        if (isset($this->requestData['event_date'])) {
            $eventDate = trim($this->requestData['event_date']);
            if (!\DateTime::createFromFormat('Y-m-d H:i:s', $eventDate) && !\DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $eventDate)) {
                $this->error('Invalid event date format. Required YYYY-MM-DD HH:MM:SS or ISO 8601.', 400);
                return;
            }
            $updateFields[] = "event_date = ?";
            $bindParams[] = $eventDate;
            $types .= 's';
        }
        if (isset($this->requestData['location'])) {
            $updateFields[] = "location = ?";
            $bindParams[] = trim($this->requestData['location']);
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->error('No data provided for event update.', 400);
            return;
        }

        $sql = "UPDATE campaign_events SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);
        $bindParams[] = $eventIdToModify;
        $types .= 'i';
        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Error updating event: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();
        $this->json(['message' => 'Event updated successfully.'], 200);
    }

    /**
     * Deletes a campaign event.
     * Permissions: 'campaign_events.delete_all', 'campaign_events.delete_own_school', 'campaign_events.delete_own_campaign'
     * Required JSON data: 'id' (event ID)
     *
     * @return void
     */
    public function deleteCampaignEvent(): void
    {
        $conn = Connection::get();
        $eventIdToDelete = $this->requestData['id'] ?? null;
        if ($eventIdToDelete === null) {
            $this->error('Missing event ID to delete.', 400);
            return;
        }
        $eventIdToDelete = (int)$eventIdToDelete;

        // Retrieve event and associated campaign details
        $campaignId = null;
        $stmt_event = $conn->prepare("SELECT campaign_id FROM campaign_events WHERE id = ? LIMIT 1;");
        $stmt_event->bind_param('i', $eventIdToDelete);
        $stmt_event->execute();
        $stmt_event->bind_result($campaignId);
        $stmt_event->fetch();
        $stmt_event->close();

        if ($campaignId === null) {
            $this->error('Event not found.', 404);
            return;
        }

        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        // Retrieve user_ids of all candidates associated with this campaign for permissions
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
        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        $canDeleteAllEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.delete_all');
        $canDeleteOwnSchoolEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.delete_own_school');
        $canDeleteOwnCampaignEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.delete_own_campaign');

        if (!$canDeleteAllEvents && !($canDeleteOwnSchoolEvents && $isOwnSchoolCampaign) && !($canDeleteOwnCampaignEvents && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to delete this event.', 403);
            return;
        }

        $stmt_delete = $conn->prepare("DELETE FROM campaign_events WHERE id = ?;");
        $stmt_delete->bind_param('i', $eventIdToDelete);

        if (!$stmt_delete->execute()) {
            $this->error('Error deleting event: ' . $conn->error, 500);
            $stmt_delete->close();
            return;
        }
        $stmt_delete->close();
        $this->json(['message' => 'Event deleted successfully.'], 200);
    }

    /**
     * Retrieves a list of campaign events for a specific campaign.
     * Permissions: 'campaign_events.view_all', 'campaign_events.view_own_school', 'campaign_events.view_own_campaign'
     * Required JSON data: 'campaign_id'
     *
     * @return void
     */
    public function getCampaignEvents(): void
    {
        $conn = Connection::get();
        $events = [];

        $campaignId = $this->requestData['campaign_id'] ?? null;
        if ($campaignId === null) {
            $this->error('Missing campaign ID to retrieve events.', 400);
            return;
        }
        $campaignId = (int)$campaignId;

        // Verify that the campaign exists and that the user has permissions to view its events
        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        if ($targetSchoolId === null) {
            $this->error('Specified campaign for events not found.', 404);
            return;
        }

        // Retrieve user_ids of all candidates associated with this campaign for permissions
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
        $isOwnSchoolCampaign = ($this->currentUserSchoolId !== null && $this->currentUserSchoolId === $targetSchoolId);

        $canViewAllEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.view_all');
        $canViewOwnSchoolEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.view_own_school');
        $canViewOwnCampaignEvents = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_events.view_own_campaign');

        if (!$canViewAllEvents && !($canViewOwnSchoolEvents && $isOwnSchoolCampaign) && !($canViewOwnCampaignEvents && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to view events for this campaign.', 403);
            return;
        }

        $stmt = $conn->prepare("SELECT id, event_name, event_description, event_date, location, created_at FROM campaign_events WHERE campaign_id = ? ORDER BY event_date ASC;");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();

        $this->json($events, 200);
    }
}
