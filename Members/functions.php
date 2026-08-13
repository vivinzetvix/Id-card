<?php
/**
 * Member Statistics Function
 */
if (!function_exists('get_member_status_badge')) {
    function get_member_status_badge($expiry_date): string {
        $today = date('Y-m-d');
        $next30 = date('Y-m-d', strtotime('+30 days'));

        if (!$expiry_date) {
            return '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
        }

        if ($expiry_date >= $today) {
            if ($expiry_date <= $next30) {
                return '<span class="status-badge expiring"><i class="fas fa-clock"></i>Expiring Soon</span>';
            }
            return '<span class="status-badge active"><i class="fas fa-check-circle"></i>Active</span>';
        }

        return '<span class="status-badge expired"><i class="fas fa-exclamation-circle"></i>Expired</span>';
    }
}

function get_member_statistics($pdo, $userOrgId = 0, $isSuperAdmin = false) {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));
    
    $where = '';
    $params = [];
    
    if (!$isSuperAdmin && $userOrgId > 0) {
        $where = 'WHERE organization_id = ? AND deleted_at IS NULL';
        $params[] = $userOrgId;
    } else {
        $where = 'WHERE deleted_at IS NULL';
    }
    
    // Total
    $sql = "SELECT COUNT(*) FROM id_members $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Active (expiry >= today)
    $sql = "SELECT COUNT(*) FROM id_members $where " . ($where ? 'AND' : 'WHERE') . " expiry_date >= ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today]));
    $active = (int)$stmt->fetchColumn();
    
    // Expiring (expiry between today and next 30 days)
    $sql = "SELECT COUNT(*) FROM id_members $where " . ($where ? 'AND' : 'WHERE') . " expiry_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today, $next30]));
    $expiring = (int)$stmt->fetchColumn();
    
    // Expired (expiry < today)
    $sql = "SELECT COUNT(*) FROM id_members $where " . ($where ? 'AND' : 'WHERE') . " expiry_date < ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$today]));
    $expired = (int)$stmt->fetchColumn();
    
    return [
        'total' => $total,
        'active' => $active,
        'expiring' => $expiring,
        'expired' => $expired
    ];
}

/**
 * Get Member by ID
 */
function get_member_by_id($pdo, $id) {
    $stmt = $pdo->prepare("SELECT m.*, o.organization_name, t.name as template_name 
                           FROM id_members m 
                           LEFT JOIN organizations o ON m.organization_id = o.id 
                           LEFT JOIN card_templates t ON m.template_id = t.id 
                           WHERE m.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Generate Unique ID
 */
function generate_unique_id($pdo, $prefix = 'MEM') {
    $year = date('Y');
    $month = date('m');
    
    // Get last sequence
    $stmt = $pdo->prepare("SELECT MAX(unique_id) as last_id FROM id_members WHERE unique_id LIKE ?");
    $stmt->execute([$prefix . $year . $month . '%']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['last_id']) {
        $last_num = (int)substr($result['last_id'], -4);
        $next_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $next_num = '0001';
    }
    
    return $prefix . $year . $month . $next_num;
}

/**
 * Validate Member Data
 */
function validate_member_data($data) {
    $errors = [];
    
    if (empty($data['name'])) {
        $errors[] = 'Name is required';
    }
    
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (!empty($data['expiry_date']) && strtotime($data['expiry_date']) < strtotime(date('Y-m-d'))) {
        $errors[] = 'Expiry date cannot be in the past';
    }
    
    return $errors;
}

/**
 * Save Member
 */
function save_member($pdo, $data) {
    $errors = validate_member_data($data);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Generate unique ID if not provided
        if (empty($data['unique_id'])) {
            $data['unique_id'] = generate_unique_id($pdo);
        }
        
        $sql = "INSERT INTO id_members (
                    organization_id, member_type, unique_id, name, guardian_name,
                    class, department, designation, company, purpose,
                    dob, address, emergency_contact, email, joined_date,
                    expiry_date, photo, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['organization_id'] ?? null,
            $data['member_type'] ?? 'student',
            $data['unique_id'],
            $data['name'],
            $data['guardian_name'] ?? null,
            $data['class'] ?? null,
            $data['department'] ?? null,
            $data['designation'] ?? null,
            $data['company'] ?? null,
            $data['purpose'] ?? null,
            $data['dob'] ?? null,
            $data['address'] ?? null,
            $data['emergency_contact'] ?? null,
            $data['email'] ?? null,
            $data['joined_date'] ?? date('Y-m-d'),
            $data['expiry_date'] ?? null,
            $data['photo'] ?? null
        ]);
        
        $memberId = $pdo->lastInsertId();
        $pdo->commit();
        
        return ['success' => true, 'id' => $memberId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update Member
 */
function update_member($pdo, $id, $data) {
    $errors = validate_member_data($data);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    try {
        $pdo->beginTransaction();
        
        $sql = "UPDATE id_members SET
                    organization_id = ?,
                    member_type = ?,
                    name = ?,
                    guardian_name = ?,
                    class = ?,
                    department = ?,
                    designation = ?,
                    company = ?,
                    purpose = ?,
                    dob = ?,
                    address = ?,
                    emergency_contact = ?,
                    email = ?,
                    joined_date = ?,
                    expiry_date = ?,
                    photo = ?,
                    updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['organization_id'] ?? null,
            $data['member_type'] ?? 'student',
            $data['name'],
            $data['guardian_name'] ?? null,
            $data['class'] ?? null,
            $data['department'] ?? null,
            $data['designation'] ?? null,
            $data['company'] ?? null,
            $data['purpose'] ?? null,
            $data['dob'] ?? null,
            $data['address'] ?? null,
            $data['emergency_contact'] ?? null,
            $data['email'] ?? null,
            $data['joined_date'] ?? date('Y-m-d'),
            $data['expiry_date'] ?? null,
            $data['photo'] ?? null,
            $id
        ]);
        
        $pdo->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete Member (Soft Delete / Archive)
 */
function delete_member($pdo, $id) {
    try {
        $stmt = $pdo->prepare("UPDATE id_members SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'error' => 'Member not found or already archived'];
        }
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Restore archived member
 */
function restore_member(PDO $pdo, int $id): array
{
    try {
        $stmt = $pdo->prepare('UPDATE id_members SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'error' => 'Member not found or not archived'];
        }
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Renew member expiry date (same record)
 */
function renew_member(PDO $pdo, int $id, string $newExpiryDate): array
{
    if ($newExpiryDate === '' || strtotime($newExpiryDate) < strtotime(date('Y-m-d'))) {
        return ['success' => false, 'error' => 'New expiry date must be today or in the future.'];
    }
    try {
        $stmt = $pdo->prepare('UPDATE id_members SET expiry_date = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$newExpiryDate, $id]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'error' => 'Member not found or archived'];
        }
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get Members by Organization
 */
function get_members_by_organization($pdo, $orgId) {
    $stmt = $pdo->prepare("SELECT * FROM id_members WHERE organization_id = ? ORDER BY name");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get Members by Type
 */
function get_members_by_type($pdo, $type) {
    $stmt = $pdo->prepare("SELECT * FROM id_members WHERE member_type = ? ORDER BY name");
    $stmt->execute([$type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get Members Expiring Soon
 */
function get_expiring_members($pdo, $days = 30, $limit = 10) {
    $today = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+$days days"));
    
    $stmt = $pdo->prepare("SELECT * FROM id_members 
                           WHERE expiry_date BETWEEN ? AND ? 
                           ORDER BY expiry_date ASC LIMIT ?");
    $stmt->execute([$today, $end_date, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Search Members
 */
function search_members($pdo, $query, $limit = 20) {
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare("SELECT * FROM id_members 
                           WHERE deleted_at IS NULL AND (name LIKE ? OR unique_id LIKE ? OR email LIKE ? OR emergency_contact LIKE ?)
                           ORDER BY name LIMIT ?");
    $stmt->execute([$like, $like, $like, $like, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get Member Card Count
 */
function get_member_card_count($pdo, $memberId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM generated_cards WHERE member_id = ?");
    $stmt->execute([$memberId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get Member Templates
 */
function get_member_templates($pdo, $orgId = null) {
    $sql = "SELECT id, name, orientation, primary_color FROM card_templates WHERE status = 1 AND deleted_at IS NULL";
    $params = [];
    
    if ($orgId) {
        $sql .= " AND (organization_id = ? OR organization_id IS NULL OR organization_id = 0)";
        $params[] = $orgId;
    }
    
    $sql .= " ORDER BY is_default DESC, name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
