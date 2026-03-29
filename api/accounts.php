<?php
/**
 * Accounts API Endpoint
 * Handles: CRUD operations for user accounts
 * PennyWise Budget & Expense Tracker
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Get JSON input for POST/PUT requests
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Require authentication for all account operations
requireAuth();

/**
 * Route requests based on method
 */
switch ($method) {
    case 'GET':
        handleGetAccounts();
        break;
    
    case 'POST':
        handleCreateAccount($input);
        break;
    
    case 'PUT':
        handleUpdateAccount($input);
        break;
    
    case 'DELETE':
        handleDeleteAccount();
        break;
    
    default:
        jsonResponse(false, null, 'Method not allowed.', 405);
}

/**
 * Get all accounts for current user
 */
function handleGetAccounts() {
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $stmt = $db->prepare("
            SELECT id, name, balance, account_type, icon, color, is_default, created_at 
            FROM accounts 
            WHERE user_id = ? 
            ORDER BY is_default DESC, name ASC
        ");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        
        // Calculate total balance
        $totalBalance = 0;
        foreach ($accounts as &$account) {
            $account['balance'] = (float)$account['balance'];
            $account['is_default'] = (bool)$account['is_default'];
            $totalBalance += $account['balance'];
        }
        
        jsonResponse(true, [
            'accounts' => $accounts,
            'total_balance' => $totalBalance,
            'account_count' => count($accounts)
        ], 'Accounts retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get accounts error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve accounts.', 500);
    }
}

/**
 * Create new account
 */
function handleCreateAccount($input) {
    $name = sanitize($input['name'] ?? '');
    $balance = floatval($input['balance'] ?? 0);
    $accountType = sanitize($input['account_type'] ?? 'cash');
    $icon = sanitize($input['icon'] ?? 'wallet');
    $color = sanitize($input['color'] ?? '#f48c8c');
    
    // Validation
    if (empty($name)) {
        jsonResponse(false, null, 'Account name is required.', 400);
    }
    
    if (strlen($name) > 100) {
        jsonResponse(false, null, 'Account name is too long.', 400);
    }
    
    $validTypes = ['cash', 'bank', 'credit', 'savings', 'other'];
    if (!in_array($accountType, $validTypes)) {
        $accountType = 'cash';
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Check if account with same name exists
        $stmt = $db->prepare("SELECT id FROM accounts WHERE user_id = ? AND name = ?");
        $stmt->execute([$userId, $name]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'An account with this name already exists.', 409);
        }
        
        // Check if this is the first account (make it default)
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $isFirst = $stmt->fetch()['count'] == 0;
        
        // Generate UUID
        $accountId = generateUUID();
        
        // Insert account
        $stmt = $db->prepare("
            INSERT INTO accounts (id, user_id, name, balance, account_type, icon, color, is_default, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$accountId, $userId, $name, $balance, $accountType, $icon, $color, $isFirst]);
        
        logActivity($userId, 'create_account', "Created account: $name with balance: $balance");
        
        jsonResponse(true, [
            'account' => [
                'id' => $accountId,
                'name' => $name,
                'balance' => $balance,
                'account_type' => $accountType,
                'icon' => $icon,
                'color' => $color,
                'is_default' => $isFirst
            ]
        ], 'Account created successfully!', 201);
        
    } catch (PDOException $e) {
        error_log("Create account error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to create account.', 500);
    }
}

/**
 * Update existing account
 */
function handleUpdateAccount($input) {
    $accountId = sanitize($input['id'] ?? '');
    $name = sanitize($input['name'] ?? '');
    $balance = $input['balance'] ?? null;
    $accountType = sanitize($input['account_type'] ?? null);
    $icon = sanitize($input['icon'] ?? null);
    $color = sanitize($input['color'] ?? null);
    $isDefault = $input['is_default'] ?? null;
    
    if (empty($accountId)) {
        jsonResponse(false, null, 'Account ID is required.', 400);
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
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        if (!empty($name)) {
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if ($balance !== null) {
            $updates[] = 'balance = ?';
            $params[] = floatval($balance);
        }
        if ($accountType !== null) {
            $updates[] = 'account_type = ?';
            $params[] = $accountType;
        }
        if ($icon !== null) {
            $updates[] = 'icon = ?';
            $params[] = $icon;
        }
        if ($color !== null) {
            $updates[] = 'color = ?';
            $params[] = $color;
        }
        if ($isDefault !== null) {
            // If setting as default, remove default from other accounts
            if ($isDefault) {
                $stmt = $db->prepare("UPDATE accounts SET is_default = FALSE WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
            $updates[] = 'is_default = ?';
            $params[] = $isDefault ? 1 : 0;
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update.', 400);
        }
        
        $params[] = $accountId;
        $params[] = $userId;
        
        $sql = "UPDATE accounts SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        logActivity($userId, 'update_account', "Updated account: $accountId");
        
        // Fetch updated account
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $updatedAccount = $stmt->fetch();
        $updatedAccount['balance'] = (float)$updatedAccount['balance'];
        $updatedAccount['is_default'] = (bool)$updatedAccount['is_default'];
        
        jsonResponse(true, ['account' => $updatedAccount], 'Account updated successfully!');
        
    } catch (PDOException $e) {
        error_log("Update account error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to update account.', 500);
    }
}

/**
 * Delete account
 */
function handleDeleteAccount() {
    $accountId = $_GET['id'] ?? '';
    
    if (empty($accountId)) {
        jsonResponse(false, null, 'Account ID is required.', 400);
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
        
        // Check if this is the default/only account
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $accountCount = $stmt->fetch()['count'];
        
        if ($accountCount <= 1) {
            jsonResponse(false, null, 'Cannot delete your only account. Please create another account first.', 400);
        }
        
        // Delete account
        $stmt = $db->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$accountId, $userId]);
        
        // If deleted account was default, make another account default
        if ($account['is_default']) {
            $stmt = $db->prepare("SELECT id FROM accounts WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $newDefault = $stmt->fetch();
            if ($newDefault) {
                $stmt = $db->prepare("UPDATE accounts SET is_default = TRUE WHERE id = ?");
                $stmt->execute([$newDefault['id']]);
            }
        }
        
        logActivity($userId, 'delete_account', "Deleted account: {$account['name']}");
        
        jsonResponse(true, null, 'Account deleted successfully!');
        
    } catch (PDOException $e) {
        error_log("Delete account error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to delete account.', 500);
    }
}
?>