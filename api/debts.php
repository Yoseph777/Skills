<?php
/**
 * Debts API Endpoint
 * Handles: CRUD operations for debts/loans
 * PennyWise Budget & Expense Tracker
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get JSON input for POST/PUT requests
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Require authentication for all debt operations
requireAuth();

/**
 * Route requests based on method
 */
switch ($method) {
    case 'GET':
        handleGetDebts();
        break;
    
    case 'POST':
        handleCreateDebt($input);
        break;
    
    case 'PUT':
        handleUpdateDebt($input);
        break;
    
    case 'DELETE':
        handleDeleteDebt();
        break;
    
    default:
        jsonResponse(false, null, 'Method not allowed.', 405);
}

/**
 * Get all debts for current user
 */
function handleGetDebts() {
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $isActive = $_GET['is_active'] ?? null;
        
        $sql = "SELECT d.*, 
                    a.name as account_name,
                    (d.amount - d.paid) as remaining
                FROM debts d
                LEFT JOIN accounts a ON d.account_id = a.id
                WHERE d.user_id = ?";
        $params = [$userId];
        
        if ($isActive !== null) {
            $sql .= " AND d.is_active = ?";
            $params[] = $isActive ? 1 : 0;
        }
        
        $sql .= " ORDER BY d.due_date ASC, d.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $debts = $stmt->fetchAll();
        
        // Format data
        foreach ($debts as &$debt) {
            $debt['amount'] = (float)$debt['amount'];
            $debt['paid'] = (float)$debt['paid'];
            $debt['remaining'] = (float)$debt['remaining'];
            $debt['is_active'] = (bool)$debt['is_active'];
        }
        
        // Calculate totals
        $totalDebt = array_sum(array_column($debts, 'amount'));
        $totalPaid = array_sum(array_column($debts, 'paid'));
        $totalRemaining = $totalDebt - $totalPaid;
        
        jsonResponse(true, [
            'debts' => $debts,
            'summary' => [
                'total_debt' => (float)$totalDebt,
                'total_paid' => (float)$totalPaid,
                'total_remaining' => (float)$totalRemaining,
                'debt_count' => count($debts)
            ]
        ], 'Debts retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get debts error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve debts.', 500);
    }
}

/**
 * Create new debt
 * On create, credit the borrowed amount as income to the chosen account
 */
function handleCreateDebt($input) {
    $name = sanitize($input['name'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $accountId = sanitize($input['account_id'] ?? '');
    $dueDate = sanitize($input['due_date'] ?? null);
    $notes = sanitize($input['notes'] ?? '');
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Debt name is required.';
    }
    
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }
    
    if (empty($accountId)) {
        $errors[] = 'Account is required.';
    }
    
    if (!empty($errors)) {
        jsonResponse(false, ['errors' => $errors], 'Validation failed.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify account belongs to user
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            jsonResponse(false, null, 'Account not found.', 404);
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        // Generate UUID
        $debtId = generateUUID();
        
        // Insert debt
        $stmt = $db->prepare("
            INSERT INTO debts (id, user_id, name, amount, paid, account_id, due_date, notes, is_active, created_at) 
            VALUES (?, ?, ?, ?, 0.00, ?, ?, ?, TRUE, NOW())
        ");
        $stmt->execute([$debtId, $userId, $name, $amount, $accountId, $dueDate ?: null, $notes ?: null]);
        
        // Credit the borrowed amount to the account (increases balance)
        $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$amount, $accountId]);
        
        $db->commit();
        
        logActivity($userId, 'create_debt', "Created debt: $name for $amount");
        
        // Fetch the created debt with account name
        $stmt = $db->prepare("
            SELECT d.*, a.name as account_name, (d.amount - d.paid) as remaining
            FROM debts d
            LEFT JOIN accounts a ON d.account_id = a.id
            WHERE d.id = ?
        ");
        $stmt->execute([$debtId]);
        $debt = $stmt->fetch();
        $debt['amount'] = (float)$debt['amount'];
        $debt['paid'] = (float)$debt['paid'];
        $debt['remaining'] = (float)$debt['remaining'];
        $debt['is_active'] = (bool)$debt['is_active'];
        
        // Get updated account balances
        $stmt = $db->prepare("SELECT id, name, balance FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        jsonResponse(true, [
            'debt' => $debt,
            'accounts' => $accounts
        ], 'Debt created successfully! Borrowed amount credited to account.', 201);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Create debt error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to create debt.', 500);
    }
}

/**
 * Update existing debt
 * Update paid amount, recalculate remaining
 */
function handleUpdateDebt($input) {
    $debtId = sanitize($input['id'] ?? '');
    $name = sanitize($input['name'] ?? '');
    $paid = isset($input['paid']) ? floatval($input['paid']) : null;
    $accountId = sanitize($input['account_id'] ?? '');
    $dueDate = sanitize($input['due_date'] ?? null);
    $notes = sanitize($input['notes'] ?? null);
    $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : null;
    
    if (empty($debtId)) {
        jsonResponse(false, null, 'Debt ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify debt belongs to user
        $stmt = $db->prepare("SELECT d.*, a.name as account_name FROM debts d LEFT JOIN accounts a ON d.account_id = a.id WHERE d.id = ? AND d.user_id = ?");
        $stmt->execute([$debtId, $userId]);
        $debt = $stmt->fetch();
        
        if (!$debt) {
            jsonResponse(false, null, 'Debt not found.', 404);
        }
        
        // Build update query
        $updates = [];
        $params = [];
        $balanceAdjustment = 0;
        
        if (!empty($name)) {
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        
        if ($paid !== null) {
            $oldPaid = (float)$debt['paid'];
            $newPaid = $paid;
            
            // Validate: paid cannot exceed total amount
            if ($newPaid > (float)$debt['amount']) {
                jsonResponse(false, null, 'Paid amount cannot exceed total debt amount.', 400);
            }
            
            if ($newPaid < 0) {
                jsonResponse(false, null, 'Paid amount cannot be negative.', 400);
            }
            
            $updates[] = 'paid = ?';
            $params[] = $newPaid;
            
            // Calculate balance adjustment
            // Each payment reduces the account balance (paying off debt uses cash)
            $paidDiff = $newPaid - $oldPaid;
            $balanceAdjustment = -$paidDiff; // paying more = reducing balance
        }
        
        if (!empty($accountId)) {
            // Verify new account belongs to user
            $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
            $stmt->execute([$accountId, $userId]);
            if (!$stmt->fetch()) {
                jsonResponse(false, null, 'Account not found.', 404);
            }
            $updates[] = 'account_id = ?';
            $params[] = $accountId;
        }
        
        if ($dueDate !== null) {
            $updates[] = 'due_date = ?';
            $params[] = $dueDate ?: null;
        }
        
        if ($notes !== null) {
            $updates[] = 'notes = ?';
            $params[] = $notes;
        }
        
        if ($isActive !== null) {
            $updates[] = 'is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update.', 400);
        }
        
        $params[] = $debtId;
        $params[] = $userId;
        
        $db->beginTransaction();
        
        $sql = "UPDATE debts SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Apply balance adjustment if paid amount changed
        if ($balanceAdjustment !== 0) {
            $targetAccountId = !empty($accountId) ? $accountId : $debt['account_id'];
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$balanceAdjustment, $targetAccountId]);
        }
        
        $db->commit();
        
        logActivity($userId, 'update_debt', "Updated debt: $debtId");
        
        // Fetch updated debt
        $stmt = $db->prepare("
            SELECT d.*, a.name as account_name, (d.amount - d.paid) as remaining
            FROM debts d
            LEFT JOIN accounts a ON d.account_id = a.id
            WHERE d.id = ?
        ");
        $stmt->execute([$debtId]);
        $updatedDebt = $stmt->fetch();
        $updatedDebt['amount'] = (float)$updatedDebt['amount'];
        $updatedDebt['paid'] = (float)$updatedDebt['paid'];
        $updatedDebt['remaining'] = (float)$updatedDebt['remaining'];
        $updatedDebt['is_active'] = (bool)$updatedDebt['is_active'];
        
        // Get updated account balances
        $stmt = $db->prepare("SELECT id, name, balance FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        jsonResponse(true, [
            'debt' => $updatedDebt,
            'accounts' => $accounts
        ], 'Debt updated successfully!');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Update debt error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to update debt.', 500);
    }
}

/**
 * Delete debt
 * Reverse the balance credit if debt is deleted
 */
function handleDeleteDebt() {
    $debtId = $_GET['id'] ?? '';
    
    if (empty($debtId)) {
        jsonResponse(false, null, 'Debt ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify debt belongs to user
        $stmt = $db->prepare("SELECT * FROM debts WHERE id = ? AND user_id = ?");
        $stmt->execute([$debtId, $userId]);
        $debt = $stmt->fetch();
        
        if (!$debt) {
            jsonResponse(false, null, 'Debt not found.', 404);
        }
        
        $db->beginTransaction();
        
        // Reverse the credit: subtract the borrowed amount minus what was paid back
        // When debt was created, we credited the full amount to the account
        // When deleting, we need to reverse: subtract the remaining (unpaid) amount from account
        $remainingAmount = (float)$debt['amount'] - (float)$debt['paid'];
        
        if ($remainingAmount > 0) {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$remainingAmount, $debt['account_id']]);
        }
        
        // Delete debt
        $stmt = $db->prepare("DELETE FROM debts WHERE id = ? AND user_id = ?");
        $stmt->execute([$debtId, $userId]);
        
        $db->commit();
        
        logActivity($userId, 'delete_debt', "Deleted debt: {$debt['name']}");
        
        // Get updated account balances
        $stmt = $db->prepare("SELECT id, name, balance FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name ASC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        jsonResponse(true, ['accounts' => $accounts], 'Debt deleted successfully! Remaining balance reversed from account.');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Delete debt error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to delete debt.', 500);
    }
}
?>
