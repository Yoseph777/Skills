<?php
/**
 * Records API Endpoint
 * Handles: CRUD operations for financial records (income, expense, transfer)
 * PennyWise Budget & Expense Tracker
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get JSON input for POST/PUT requests
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Require authentication for all record operations
requireAuth();

/**
 * Route requests based on method
 */
switch ($method) {
    case 'GET':
        handleGetRecords();
        break;
    
    case 'POST':
        handleCreateRecord($input);
        break;
    
    case 'PUT':
        handleUpdateRecord($input);
        break;
    
    case 'DELETE':
        handleDeleteRecord();
        break;
    
    default:
        jsonResponse(false, null, 'Method not allowed.', 405);
}

/**
 * Get records with filtering and pagination
 */
function handleGetRecords() {
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Filters
        $type = $_GET['type'] ?? null;
        $categoryId = $_GET['category_id'] ?? null;
        $accountId = $_GET['account_id'] ?? null;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $limit = min(intval($_GET['limit'] ?? 100), 500);
        $offset = max(intval($_GET['offset'] ?? 0), 0);
        
        // Build query
        $sql = "SELECT r.*, 
                    c.name as category_name, c.icon as category_icon, c.color as category_color,
                    fa.name as from_account_name,
                    ta.name as to_account_name
                FROM records r
                LEFT JOIN categories c ON r.category_id = c.id
                LEFT JOIN accounts fa ON r.from_account_id = fa.id
                LEFT JOIN accounts ta ON r.to_account_id = ta.id
                WHERE r.user_id = ?";
        
        $params = [$userId];
        
        if ($type && in_array($type, ['income', 'expense', 'transfer'])) {
            $sql .= " AND r.type = ?";
            $params[] = $type;
        }
        
        if ($categoryId) {
            $sql .= " AND r.category_id = ?";
            $params[] = $categoryId;
        }
        
        if ($accountId) {
            $sql .= " AND (r.from_account_id = ? OR r.to_account_id = ?)";
            $params[] = $accountId;
            $params[] = $accountId;
        }
        
        if ($startDate) {
            $sql .= " AND r.date >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND r.date <= ?";
            $params[] = $endDate;
        }
        
        // Get total count
        $countSql = str_replace("r.*, c.name as category_name, c.icon as category_icon, c.color as category_color, fa.name as from_account_name, ta.name as to_account_name", "COUNT(*) as total", $sql);
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $totalCount = $stmt->fetch()['total'];
        
        // Add ordering and pagination
        $sql .= " ORDER BY r.date DESC, r.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        
        // Format records
        foreach ($records as &$record) {
            $record['amount'] = (float)$record['amount'];
        }
        
        // Get summary
        $summary = getRecordSummary($userId, $type, $startDate, $endDate);
        
        jsonResponse(true, [
            'records' => $records,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ],
            'summary' => $summary
        ], 'Records retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get records error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve records.', 500);
    }
}

/**
 * Get record summary
 */
function getRecordSummary($userId, $type = null, $startDate = null, $endDate = null) {
    $db = getDB();
    
    $sql = "SELECT 
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
                COUNT(*) as total_count,
                COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
                COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count,
                COUNT(CASE WHEN type = 'transfer' THEN 1 END) as transfer_count
            FROM records WHERE user_id = ?";
    
    $params = [$userId];
    
    if ($type && in_array($type, ['income', 'expense', 'transfer'])) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    if ($startDate) {
        $sql .= " AND date >= ?";
        $params[] = $startDate;
    }
    
    if ($endDate) {
        $sql .= " AND date <= ?";
        $params[] = $endDate;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    
    return [
        'total_income' => (float)($result['total_income'] ?? 0),
        'total_expense' => (float)($result['total_expense'] ?? 0),
        'net_amount' => (float)($result['total_income'] ?? 0) - (float)($result['total_expense'] ?? 0),
        'total_count' => (int)($result['total_count'] ?? 0),
        'income_count' => (int)($result['income_count'] ?? 0),
        'expense_count' => (int)($result['expense_count'] ?? 0),
        'transfer_count' => (int)($result['transfer_count'] ?? 0)
    ];
}

/**
 * Create new record
 */
function handleCreateRecord($input) {
    $type = sanitize($input['type'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $categoryId = sanitize($input['category_id'] ?? null);
    $fromAccountId = sanitize($input['from_account_id'] ?? null);
    $toAccountId = sanitize($input['to_account_id'] ?? null);
    $description = sanitize($input['description'] ?? '');
    $date = sanitize($input['date'] ?? date('Y-m-d'));
    
    // Validation
    $errors = [];
    
    if (!in_array($type, ['income', 'expense', 'transfer'])) {
        $errors[] = 'Invalid record type.';
    }
    
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    
    if (empty($fromAccountId)) {
        $errors[] = 'Account is required.';
    }
    
    if ($type === 'transfer' && empty($toAccountId)) {
        $errors[] = 'Destination account is required for transfers.';
    }
    
    if ($type === 'transfer' && $fromAccountId === $toAccountId) {
        $errors[] = 'Source and destination accounts cannot be the same.';
    }
    
    if (!empty($errors)) {
        jsonResponse(false, ['errors' => $errors], 'Validation failed.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify source account belongs to user and has sufficient funds
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$fromAccountId, $userId]);
        $fromAccount = $stmt->fetch();
        
        if (!$fromAccount) {
            jsonResponse(false, null, 'Source account not found.', 404);
        }
        
        // Check sufficient funds for expense or transfer
        if (($type === 'expense' || $type === 'transfer') && $fromAccount['balance'] < $amount) {
            jsonResponse(false, null, 'Insufficient funds in source account.', 400);
        }
        
        // Verify destination account for transfer
        if ($type === 'transfer') {
            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$toAccountId, $userId]);
            $toAccount = $stmt->fetch();
            
            if (!$toAccount) {
                jsonResponse(false, null, 'Destination account not found.', 404);
            }
        }
        
        // Verify category if provided
        if ($categoryId) {
            $stmt = $db->prepare("SELECT * FROM categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$categoryId, $userId]);
            if (!$stmt->fetch()) {
                $categoryId = null; // Invalid category, set to null
            }
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        // Generate UUID
        $recordId = generateUUID();
        
        // Insert record
        $stmt = $db->prepare("
            INSERT INTO records (id, user_id, type, amount, category_id, from_account_id, to_account_id, description, date, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$recordId, $userId, $type, $amount, $categoryId, $fromAccountId, $toAccountId, $description, $date]);
        
        // Update account balances
        if ($type === 'income') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
        } elseif ($type === 'expense') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
        } elseif ($type === 'transfer') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $toAccountId]);
        }
        
        $db->commit();
        
        logActivity($userId, 'create_record', "Created $type record: $amount");
        
        // Fetch the created record with related data
        $stmt = $db->prepare("
            SELECT r.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
                   fa.name as from_account_name, ta.name as to_account_name
            FROM records r
            LEFT JOIN categories c ON r.category_id = c.id
            LEFT JOIN accounts fa ON r.from_account_id = fa.id
            LEFT JOIN accounts ta ON r.to_account_id = ta.id
            WHERE r.id = ?
        ");
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        $record['amount'] = (float)$record['amount'];
        
        // Get updated account balances
        $stmt = $db->prepare("SELECT id, name, balance FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        jsonResponse(true, [
            'record' => $record,
            'accounts' => $accounts
        ], 'Record created successfully!', 201);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Create record error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to create record.', 500);
    }
}

/**
 * Update existing record
 */
function handleUpdateRecord($input) {
    $recordId = sanitize($input['id'] ?? '');
    $amount = isset($input['amount']) ? floatval($input['amount']) : null;
    $categoryId = sanitize($input['category_id'] ?? null);
    $description = isset($input['description']) ? sanitize($input['description']) : null;
    $date = isset($input['date']) ? sanitize($input['date']) : null;
    
    if (empty($recordId)) {
        jsonResponse(false, null, 'Record ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify record belongs to user
        $stmt = $db->prepare("SELECT * FROM records WHERE id = ? AND user_id = ?");
        $stmt->execute([$recordId, $userId]);
        $record = $stmt->fetch();
        
        if (!$record) {
            jsonResponse(false, null, 'Record not found.', 404);
        }
        
        // For now, only allow updating amount, category, description, and date
        // Changing type or accounts would require complex balance adjustments
        $updates = [];
        $params = [];
        
        if ($amount !== null && $amount > 0) {
            // Need to adjust account balance
            $oldAmount = (float)$record['amount'];
            $diff = $amount - $oldAmount;
            
            $db->beginTransaction();
            
            if ($record['type'] === 'income') {
                $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$diff, $record['from_account_id']]);
            } elseif ($record['type'] === 'expense') {
                $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$diff, $record['from_account_id']]);
            } elseif ($record['type'] === 'transfer') {
                $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$diff, $record['from_account_id']]);
                $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$diff, $record['to_account_id']]);
            }
            
            $updates[] = 'amount = ?';
            $params[] = $amount;
        }
        
        if ($categoryId !== null) {
            $updates[] = 'category_id = ?';
            $params[] = $categoryId ?: null;
        }
        
        if ($description !== null) {
            $updates[] = 'description = ?';
            $params[] = $description;
        }
        
        if ($date !== null) {
            $updates[] = 'date = ?';
            $params[] = $date;
        }
        
        if (empty($updates)) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            jsonResponse(false, null, 'No fields to update.', 400);
        }
        
        $params[] = $recordId;
        $params[] = $userId;
        
        $sql = "UPDATE records SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        if ($db->inTransaction()) {
            $db->commit();
        }
        
        logActivity($userId, 'update_record', "Updated record: $recordId");
        
        // Fetch updated record
        $stmt = $db->prepare("
            SELECT r.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
                   fa.name as from_account_name, ta.name as to_account_name
            FROM records r
            LEFT JOIN categories c ON r.category_id = c.id
            LEFT JOIN accounts fa ON r.from_account_id = fa.id
            LEFT JOIN accounts ta ON r.to_account_id = ta.id
            WHERE r.id = ?
        ");
        $stmt->execute([$recordId]);
        $updatedRecord = $stmt->fetch();
        $updatedRecord['amount'] = (float)$updatedRecord['amount'];
        
        jsonResponse(true, ['record' => $updatedRecord], 'Record updated successfully!');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Update record error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to update record.', 500);
    }
}

/**
 * Delete record
 */
function handleDeleteRecord() {
    $recordId = $_GET['id'] ?? '';
    
    if (empty($recordId)) {
        jsonResponse(false, null, 'Record ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify record belongs to user
        $stmt = $db->prepare("SELECT * FROM records WHERE id = ? AND user_id = ?");
        $stmt->execute([$recordId, $userId]);
        $record = $stmt->fetch();
        
        if (!$record) {
            jsonResponse(false, null, 'Record not found.', 404);
        }
        
        $db->beginTransaction();
        
        // Reverse the account balance changes
        $amount = (float)$record['amount'];
        
        if ($record['type'] === 'income') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
        } elseif ($record['type'] === 'expense') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
        } elseif ($record['type'] === 'transfer') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $record['to_account_id']]);
        }
        
        // Delete record
        $stmt = $db->prepare("DELETE FROM records WHERE id = ? AND user_id = ?");
        $stmt->execute([$recordId, $userId]);
        
        $db->commit();
        
        logActivity($userId, 'delete_record', "Deleted record: $recordId");
        
        // Get updated account balances
        $stmt = $db->prepare("SELECT id, name, balance FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        jsonResponse(true, ['accounts' => $accounts], 'Record deleted successfully!');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Delete record error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to delete record.', 500);
    }
}
?>