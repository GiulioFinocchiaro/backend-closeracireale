<?php

namespace Controllers;

use Core\AuthMiddleware;
use Core\RoleChecker;
use Database\Connection;
use Core\Controller;

class CampaignMaterialsController extends Controller
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
            $this->error('Internal server error: database connection not available.', 500);
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
            $this->error('Authentication failed: ' . $e->getMessage(), 401);
            return;
        }
    }

    /**
     * Adds a new material to a campaign.
     * Permissions: 'campaign_materials.create'
     * Required JSON data: 'campaign_id', 'material_name', 'material_type', 'file_url', 'description' (optional)
     *
     * @return void
     */
    public function addCampaignMaterial(): void
    {
        if (!$this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.create')) {
            $this->error('Access denied: Insufficient permissions to add materials.', 403);
            return;
        }

        $conn = Connection::get();

        if (!isset($this->requestData['campaign_id']) || !isset($this->requestData['material_name']) || !isset($this->requestData['material_type']) || !isset($this->requestData['file_url'])) {
            $this->error('Missing material data: campaign_id, material_name, material_type, and file_url are required.', 400);
            return;
        }

        $campaignId = (int)$this->requestData['campaign_id'];
        $materialName = trim($this->requestData['material_name']);
        $materialType = trim($this->requestData['material_type']);
        $fileUrl = trim($this->requestData['file_url']);
        $description = trim($this->requestData['description'] ?? '');

        // Verify that the campaign exists and that the user has permissions to add materials to it
        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        if ($targetSchoolId === null) {
            $this->error('Specified campaign for the material not found.', 404);
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

        $canCreateAllMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.create_all'); // Assume a more generic permission
        $canCreateOwnSchoolMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.create_own_school');
        $canCreateOwnCampaignMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.create_own_campaign');


        if (!$canCreateAllMaterials && !($canCreateOwnSchoolMaterials && $isOwnSchoolCampaign) && !($canCreateOwnCampaignMaterials && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to add materials to this campaign.', 403);
            return;
        }

        $stmt_insert = $conn->prepare("INSERT INTO campaign_materials (campaign_id, material_name, material_type, file_url, description, created_at) VALUES (?, ?, ?, ?, ?, NOW());");
        $stmt_insert->bind_param('issss', $campaignId, $materialName, $materialType, $fileUrl, $description);

        if (!$stmt_insert->execute()) {
            $this->error('Error adding material: ' . $conn->error, 500);
            $stmt_insert->close();
            return;
        }
        $newMaterialId = $conn->insert_id;
        $stmt_insert->close();

        $this->json(['message' => 'Material added successfully.', 'material_id' => $newMaterialId], 201);
    }

    /**
     * Modifies an existing campaign material.
     * Permissions: 'campaign_materials.update_all', 'campaign_materials.update_own_school', 'campaign_materials.update_own_campaign'
     * Required JSON data: 'id' (material ID), 'material_name', 'material_type', 'file_url', 'description' (optional)
     *
     * @return void
     */
    public function updateCampaignMaterial(): void
    {
        $conn = Connection::get();
        $materialIdToModify = $this->requestData['id'] ?? null;
        if ($materialIdToModify === null) {
            $this->error('Missing material ID to modify.', 400);
            return;
        }
        $materialIdToModify = (int)$materialIdToModify;

        // Retrieve material and associated campaign details
        $campaignId = null;
        $stmt_material = $conn->prepare("SELECT campaign_id FROM campaign_materials WHERE id = ? LIMIT 1;");
        $stmt_material->bind_param('i', $materialIdToModify);
        $stmt_material->execute();
        $stmt_material->bind_result($campaignId);
        $stmt_material->fetch();
        $stmt_material->close();

        if ($campaignId === null) {
            $this->error('Material not found.', 404);
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

        $canUpdateAllMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.update_all');
        $canUpdateOwnSchoolMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.update_own_school');
        $canUpdateOwnCampaignMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.update_own_campaign');

        if (!$canUpdateAllMaterials && !($canUpdateOwnSchoolMaterials && $isOwnSchoolCampaign) && !($canUpdateOwnCampaignMaterials && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to modify this material.', 403);
            return;
        }

        $updateFields = [];
        $bindParams = [];
        $types = '';

        if (isset($this->requestData['material_name'])) {
            $updateFields[] = "material_name = ?";
            $bindParams[] = trim($this->requestData['material_name']);
            $types .= 's';
        }
        if (isset($this->requestData['material_type'])) {
            $updateFields[] = "material_type = ?";
            $bindParams[] = trim($this->requestData['material_type']);
            $types .= 's';
        }
        if (isset($this->requestData['file_url'])) {
            $updateFields[] = "file_url = ?";
            $bindParams[] = trim($this->requestData['file_url']);
            $types .= 's';
        }
        if (isset($this->requestData['description'])) {
            $updateFields[] = "description = ?";
            $bindParams[] = trim($this->requestData['description']);
            $types .= 's';
        }

        if (empty($updateFields)) {
            $this->error('No data provided for material update.', 400);
            return;
        }

        $sql = "UPDATE campaign_materials SET " . implode(', ', $updateFields) . " WHERE id = ?;";
        $stmt_update = $conn->prepare($sql);
        $bindParams[] = $materialIdToModify;
        $types .= 'i';
        $refs = [];
        foreach ($bindParams as $key => $value) {
            $refs[$key] = &$bindParams[$key];
        }
        call_user_func_array([$stmt_update, 'bind_param'], array_merge([$types], $refs));

        if (!$stmt_update->execute()) {
            $this->error('Error updating material: ' . $conn->error, 500);
            $stmt_update->close();
            return;
        }
        $stmt_update->close();
        $this->json(['message' => 'Material updated successfully.'], 200);
    }

    /**
     * Deletes a campaign material.
     * Permissions: 'campaign_materials.delete_all', 'campaign_materials.delete_own_school', 'campaign_materials.delete_own_campaign'
     * Required JSON data: 'id' (material ID)
     *
     * @return void
     */
    public function deleteCampaignMaterial(): void
    {
        $conn = Connection::get();
        $materialIdToDelete = $this->requestData['id'] ?? null;
        if ($materialIdToDelete === null) {
            $this->error('Missing material ID to delete.', 400);
            return;
        }
        $materialIdToDelete = (int)$materialIdToDelete;

        // Retrieve material and associated campaign details
        $campaignId = null;
        $stmt_material = $conn->prepare("SELECT campaign_id FROM campaign_materials WHERE id = ? LIMIT 1;");
        $stmt_material->bind_param('i', $materialIdToDelete);
        $stmt_material->execute();
        $stmt_material->bind_result($campaignId);
        $stmt_material->fetch();
        $stmt_material->close();

        if ($campaignId === null) {
            $this->error('Material not found.', 404);
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

        $canDeleteAllMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.delete_all');
        $canDeleteOwnSchoolMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.delete_own_school');
        $canDeleteOwnCampaignMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.delete_own_campaign');

        if (!$canDeleteAllMaterials && !($canDeleteOwnSchoolMaterials && $isOwnSchoolCampaign) && !($canDeleteOwnCampaignMaterials && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to delete this material.', 403);
            return;
        }

        $stmt_delete = $conn->prepare("DELETE FROM campaign_materials WHERE id = ?;");
        $stmt_delete->bind_param('i', $materialIdToDelete);

        if (!$stmt_delete->execute()) {
            $this->error('Error deleting material: ' . $conn->error, 500);
            $stmt_delete->close();
            return;
        }
        $stmt_delete->close();
        $this->json(['message' => 'Material deleted successfully.'], 200);
    }

    /**
     * Retrieves a list of campaign materials for a specific campaign.
     * Permissions: 'campaign_materials.view_all', 'campaign_materials.view_own_school', 'campaign_materials.view_own_campaign'
     * Required JSON data: 'campaign_id'
     *
     * @return void
     */
    public function getCampaignMaterials(): void
    {
        $conn = Connection::get();
        $materials = [];

        $campaignId = $this->requestData['campaign_id'] ?? null;
        if ($campaignId === null) {
            $this->error('Missing campaign ID to retrieve materials.', 400);
            return;
        }
        $campaignId = (int)$campaignId;

        // Verify that the campaign exists and that the user has permissions to view its materials
        $targetSchoolId = null;
        $stmt_campaign = $conn->prepare("SELECT school_id FROM campaigns WHERE id = ? LIMIT 1;");
        $stmt_campaign->bind_param('i', $campaignId);
        $stmt_campaign->execute();
        $stmt_campaign->bind_result($targetSchoolId);
        $stmt_campaign->fetch();
        $stmt_campaign->close();

        if ($targetSchoolId === null) {
            $this->error('Specified campaign for materials not found.', 404);
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

        $canViewAllMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.view_all');
        $canViewOwnSchoolMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.view_own_school');
        $canViewOwnCampaignMaterials = $this->permissionChecker->userHasPermission($this->currentUserId, 'campaign_materials.view_own_campaign');

        if (!$canViewAllMaterials && !($canViewOwnSchoolMaterials && $isOwnSchoolCampaign) && !($canViewOwnCampaignMaterials && $isOwnCampaign)) {
            $this->error('Access denied: Insufficient permissions to view materials for this campaign.', 403);
            return;
        }

        $stmt = $conn->prepare("SELECT id, material_name, material_type, file_url, description, created_at FROM campaign_materials WHERE campaign_id = ? ORDER BY created_at ASC;");
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $materials[] = $row;
        }
        $stmt->close();

        $this->json($materials, 200);
    }
}
