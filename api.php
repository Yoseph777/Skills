<?php
/**
 * PennyWise Unified API
 * Single file handling all backend operations
 * 
 * Usage: api.php?action=[action]
 * 
 * Actions:
 * - register: Register new user (POST)
 * - login: Login user (POST)
 * - logout: Logout user (GET)
 * - check: Check session status (GET)
 * - getAccounts: Get all accounts (GET)
 * - addAccount: Create account (POST)
 * - updateAccount: Update account (PUT)
 * - deleteAccount: Delete account (DELETE)
 * - getCategories: Get categories (GET)
 * - addCategory: Create category (POST)
 * - deleteCategory: Delete category (DELETE)
 * - getRecords: Get records (GET)
 * - addRecord: Create record (POST)
 * - deleteRecord: Delete record (DELETE)
 * - getSummary: Get dashboard summary (GET)
 */

// ============================================
// CONFIGURATION
// ============================================

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database Configuration - UPDATE THESE FOR YOUR XAMPP SETUP
define('DB_HOST', 'localhost');
define('DB_NAME', 'pennywise_db');
define('DB_USER', 'root');
define('DB_PASS', '');  // Default XAMPP has empty password

// ============================================
// DATABASE CONNECTION
// ============================================

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            sendResponse(false, null, 'Database connection failed: ' . $e->getMessage(), 500);
        }
    }
    return $pdo;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit();
}

function getInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return $input ?? $_POST ?? [];
}

function sanitize($str) {
    return htmlspecialchars(strip_tags(trim($str ?? '')), ENT_QUOTES, 'UTF-8');
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        sendResponse(false, null, 'Authentication required. Please login.', 401);
    }
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

// ============================================
// AUTHENTICATION HANDLERS
// ============================================

function handleRegister() {
    $input = getInput();
    $username = sanitize($input['username'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    // Validation
    if (strlen($username) < 3) {
        sendResponse(false, null, 'Username must be at least 3 characters.', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, null, 'Please enter a valid email.', 400);
    }
    if (strlen($password) < 6) {
        sendResponse(false, null, 'Password must be at least 6 characters.', 400);
    }
    
    $db = getDB();
    
    // Check existing email
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(false, null, 'Email already registered.', 409);
    }
    
    // Check existing username
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        sendResponse(false, null, 'Username already taken.', 409);
    }
    
    // Create user
    $userId = generateUUID();
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    $stmt = $db->prepare("INSERT INTO users (id, username, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $username, $email, $hashedPassword]);
    
    // Create default account
    $accountId = generateUUID();
    $stmt = $db->prepare("INSERT INTO accounts (id, user_id, name, balance, account_type, is_default) VALUES (?, ?, 'Cash', 0, 'cash', 1)");
    $stmt->execute([$accountId, $userId]);
    
    // Create default categories
    $defaultCategories = [
        ['name' => 'Salary', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#10b981'],
        ['name' => 'Freelance', 'type' => 'income', 'icon' => 'laptop', 'color' => '#06b6d4'],
        ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#3b82f6'],
        ['name' => 'Food', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#f59e0b'],
        ['name' => 'Transport', 'type' => 'expense', 'icon' => 'car', 'color' => '#ef4444'],
        ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#8b5cf6'],
        ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#ec4899'],
        ['name' => 'Bills', 'type' => 'expense', 'icon' => 'file-text', 'color' => '#64748b'],
        ['name' => 'Healthcare', 'type' => 'expense', 'icon' => 'heart', 'color' => '#f97316'],
        ['name' => 'Education', 'type' => 'expense', 'icon' => 'book', 'color' => '#14b8a6'],
    ];
    
    $stmt = $db->prepare("INSERT INTO categories (id, user_id, name, type, icon, color) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($defaultCategories as $cat) {
        $stmt->execute([generateUUID(), $userId, $cat['name'], $cat['type'], $cat['icon'], $cat['color']]);
    }
    
    // Auto login
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
    
    sendResponse(true, [
        'user' => ['id' => $userId, 'username' => $username, 'email' => $email]
    ], 'Registration successful!', 201);
}

function handleLogin() {
    $input = getInput();
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        sendResponse(false, null, 'Email and password are required.', 400);
    }
    
    $db = getDB();
    
    $stmt = $db->prepare("SELECT id, username, email, password, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        sendResponse(false, null, 'Invalid email or password.', 401);
    }
    
    if (!$user['is_active']) {
        sendResponse(false, null, 'Account is deactivated.', 403);
    }
    
    // Update last login
    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    
    sendResponse(true, [
        'user' => ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']]
    ], 'Login successful!');
}

function handleLogout() {
    session_unset();
    session_destroy();
    sendResponse(true, null, 'Logged out successfully.');
}

function handleCheckSession() {
    if (isLoggedIn()) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
        $stmt->execute([getUserId()]);
        $user = $stmt->fetch();
        sendResponse(true, ['authenticated' => true, 'user' => $user], 'Session active.');
    } else {
        sendResponse(true, ['authenticated' => false, 'user' => null], 'No active session.');
    }
}

// ============================================
// ACCOUNT HANDLERS
// ============================================

function handleGetAccounts() {
    requireAuth();
    $db = getDB();
    
    $stmt = $db->prepare("SELECT id, name, balance, account_type, icon, color, is_default, created_at FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name");
    $stmt->execute([getUserId()]);
    $accounts = $stmt->fetchAll();
    
    foreach ($accounts as &$acc) {
        $acc['balance'] = (float)$acc['balance'];
        $acc['is_default'] = (bool)$acc['is_default'];
    }
    
    sendResponse(true, ['accounts' => $accounts], 'Accounts retrieved.');
}

function handleAddAccount() {
    requireAuth();
    $input = getInput();
    
    $name = sanitize($input['name'] ?? '');
    $balance = floatval($input['balance'] ?? 0);
    $type = sanitize($input['account_type'] ?? 'cash');
    
    if (empty($name)) {
        sendResponse(false, null, 'Account name is required.', 400);
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Check if first account
    $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $isFirst = $stmt->fetchColumn() == 0;
    
    $accountId = generateUUID();
    $stmt = $db->prepare("INSERT INTO accounts (id, user_id, name, balance, account_type, is_default) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$accountId, $userId, $name, $balance, $type, $isFirst ? 1 : 0]);
    
    sendResponse(true, [
        'account' => [
            'id' => $accountId,
            'name' => $name,
            'balance' => $balance,
            'account_type' => $type,
            'is_default' => $isFirst
        ]
    ], 'Account created!', 201);
}

function handleDeleteAccount() {
    requireAuth();
    $id = sanitize($_GET['id'] ?? '');
    
    if (empty($id)) {
        sendResponse(false, null, 'Account ID required.', 400);
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Check ownership
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        sendResponse(false, null, 'Account not found.', 404);
    }
    
    // Check if only account
    $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() <= 1) {
        sendResponse(false, null, 'Cannot delete your only account.', 400);
    }
    
    $stmt = $db->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    
    sendResponse(true, null, 'Account deleted.');
}

// ============================================
// CATEGORY HANDLERS
// ============================================

function handleGetCategories() {
    requireAuth();
    $db = getDB();
    
    $type = sanitize($_GET['type'] ?? '');
    
    $sql = "SELECT id, name, type, icon, color, budget, is_active FROM categories WHERE user_id = ? AND is_active = 1";
    $params = [getUserId()];
    
    if ($type && in_array($type, ['income', 'expense'])) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY type, name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll();
    
    foreach ($categories as &$cat) {
        $cat['budget'] = $cat['budget'] ? (float)$cat['budget'] : null;
    }
    
    sendResponse(true, ['categories' => $categories], 'Categories retrieved.');
}

function handleAddCategory() {
    requireAuth();
    $input = getInput();
    
    $name = sanitize($input['name'] ?? '');
    $type = sanitize($input['type'] ?? 'expense');
    
    if (empty($name)) {
        sendResponse(false, null, 'Category name is required.', 400);
    }
    
    if (!in_array($type, ['income', 'expense'])) {
        $type = 'expense';
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Check duplicate
    $stmt = $db->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ?");
    $stmt->execute([$userId, $name, $type]);
    if ($stmt->fetch()) {
        sendResponse(false, null, 'Category already exists.', 409);
    }
    
    $catId = generateUUID();
    $stmt = $db->prepare("INSERT INTO categories (id, user_id, name, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$catId, $userId, $name, $type]);
    
    sendResponse(true, [
        'category' => ['id' => $catId, 'name' => $name, 'type' => $type, 'is_active' => true]
    ], 'Category created!', 201);
}

function handleDeleteCategory() {
    requireAuth();
    $id = sanitize($_GET['id'] ?? '');
    
    if (empty($id)) {
        sendResponse(false, null, 'Category ID required.', 400);
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Soft delete
    $stmt = $db->prepare("UPDATE categories SET is_active = 0 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    
    if ($stmt->rowCount() > 0) {
        sendResponse(true, null, 'Category deleted.');
    } else {
        sendResponse(false, null, 'Category not found.', 404);
    }
}

// ============================================
// RECORD HANDLERS
// ============================================

function handleGetRecords() {
    requireAuth();
    $db = getDB();
    $userId = getUserId();
    
    $type = sanitize($_GET['type'] ?? '');
    $limit = min(intval($_GET['limit'] ?? 100), 500);
    
    $sql = "SELECT r.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
            fa.name as from_account_name, ta.name as to_account_name
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
    
    $sql .= " ORDER BY r.date DESC, r.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    foreach ($records as &$rec) {
        $rec['amount'] = (float)$rec['amount'];
    }
    
    // Get summary
    $sql = "SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
            COUNT(*) as total_count
            FROM records WHERE user_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $summary = $stmt->fetch();
    
    sendResponse(true, [
        'records' => $records,
        'summary' => [
            'total_income' => (float)($summary['total_income'] ?? 0),
            'total_expense' => (float)($summary['total_expense'] ?? 0),
            'net_amount' => (float)($summary['total_income'] ?? 0) - (float)($summary['total_expense'] ?? 0),
            'total_count' => (int)($summary['total_count'] ?? 0)
        ]
    ], 'Records retrieved.');
}

function handleAddRecord() {
    requireAuth();
    $input = getInput();
    
    $type = sanitize($input['type'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $categoryId = sanitize($input['category_id'] ?? null) ?: null;
    $fromAccountId = sanitize($input['from_account_id'] ?? '');
    $toAccountId = sanitize($input['to_account_id'] ?? null) ?: null;
    $description = sanitize($input['description'] ?? '');
    $date = sanitize($input['date'] ?? date('Y-m-d'));
    
    // Validation
    if (!in_array($type, ['income', 'expense', 'transfer'])) {
        sendResponse(false, null, 'Invalid record type.', 400);
    }
    if ($amount <= 0) {
        sendResponse(false, null, 'Amount must be greater than zero.', 400);
    }
    if (empty($fromAccountId)) {
        sendResponse(false, null, 'Account is required.', 400);
    }
    if ($type === 'transfer' && empty($toAccountId)) {
        sendResponse(false, null, 'Destination account required for transfers.', 400);
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Verify source account
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$fromAccountId, $userId]);
    $fromAccount = $stmt->fetch();
    if (!$fromAccount) {
        sendResponse(false, null, 'Source account not found.', 404);
    }
    
    // Check balance for expense/transfer
    if (($type === 'expense' || $type === 'transfer') && $fromAccount['balance'] < $amount) {
        sendResponse(false, null, 'Insufficient funds.', 400);
    }
    
    // Verify destination for transfer
    if ($type === 'transfer') {
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$toAccountId, $userId]);
        if (!$stmt->fetch()) {
            sendResponse(false, null, 'Destination account not found.', 404);
        }
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Insert record
        $recordId = generateUUID();
        $stmt = $db->prepare("INSERT INTO records (id, user_id, type, amount, category_id, from_account_id, to_account_id, description, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$recordId, $userId, $type, $amount, $categoryId, $fromAccountId, $toAccountId, $description, $date]);
        
        // Update balances
        if ($type === 'income') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
        } elseif ($type === 'expense') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
        } else { // transfer
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $fromAccountId]);
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $toAccountId]);
        }
        
        $db->commit();
        
        // Get updated accounts
        $stmt = $db->prepare("SELECT id, name, balance, is_default FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
            $acc['is_default'] = (bool)$acc['is_default'];
        }
        
        sendResponse(true, [
            'record' => [
                'id' => $recordId,
                'type' => $type,
                'amount' => $amount,
                'category_name' => null,
                'description' => $description,
                'date' => $date
            ],
            'accounts' => $accounts
        ], 'Record added!', 201);
        
    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, null, 'Failed to add record: ' . $e->getMessage(), 500);
    }
}

function handleDeleteRecord() {
    requireAuth();
    $id = sanitize($_GET['id'] ?? '');
    
    if (empty($id)) {
        sendResponse(false, null, 'Record ID required.', 400);
    }
    
    $db = getDB();
    $userId = getUserId();
    
    // Get record
    $stmt = $db->prepare("SELECT * FROM records WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $record = $stmt->fetch();
    
    if (!$record) {
        sendResponse(false, null, 'Record not found.', 404);
    }
    
    $db->beginTransaction();
    
    try {
        $amount = (float)$record['amount'];
        
        // Reverse balance changes
        if ($record['type'] === 'income') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
        } elseif ($record['type'] === 'expense') {
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
        } else { // transfer
            $stmt = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $record['from_account_id']]);
            $stmt = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $record['to_account_id']]);
        }
        
        // Delete record
        $stmt = $db->prepare("DELETE FROM records WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        $db->commit();
        
        // Get updated accounts
        $stmt = $db->prepare("SELECT id, name, balance, is_default FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$acc) {
            $acc['balance'] = (float)$acc['balance'];
        }
        
        sendResponse(true, ['accounts' => $accounts], 'Record deleted.');
        
    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, null, 'Failed to delete record.', 500);
    }
}

// ============================================
// SUMMARY HANDLER
// ============================================

function handleGetSummary() {
    requireAuth();
    $db = getDB();
    $userId = getUserId();
    
    // Total balance
    $stmt = $db->prepare("SELECT SUM(balance) as total FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totalBalance = (float)($stmt->fetch()['total'] ?? 0);
    
    // Income/Expense totals
    $stmt = $db->prepare("SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense,
        COUNT(*) as total_count
        FROM records WHERE user_id = ?");
    $stmt->execute([$userId]);
    $totals = $stmt->fetch();
    
    // Expense breakdown
    $stmt = $db->prepare("SELECT c.name, c.icon, c.color, SUM(r.amount) as total_amount
        FROM records r
        JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ? AND r.type = 'expense'
        GROUP BY c.id ORDER BY total_amount DESC LIMIT 10");
    $stmt->execute([$userId]);
    $expenseBreakdown = $stmt->fetchAll();
    foreach ($expenseBreakdown as &$item) {
        $item['total_amount'] = (float)$item['total_amount'];
    }
    
    // Income breakdown
    $stmt = $db->prepare("SELECT c.name, c.icon, c.color, SUM(r.amount) as total_amount
        FROM records r
        JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ? AND r.type = 'income'
        GROUP BY c.id ORDER BY total_amount DESC LIMIT 10");
    $stmt->execute([$userId]);
    $incomeBreakdown = $stmt->fetchAll();
    foreach ($incomeBreakdown as &$item) {
        $item['total_amount'] = (float)$item['total_amount'];
    }
    
    // Recent transactions
    $stmt = $db->prepare("SELECT r.*, c.name as category_name, c.color as category_color
        FROM records r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ?
        ORDER BY r.date DESC, r.created_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $recent = $stmt->fetchAll();
    foreach ($recent as &$rec) {
        $rec['amount'] = (float)$rec['amount'];
    }
    
    // Accounts
    $stmt = $db->prepare("SELECT id, name, balance, account_type, is_default FROM accounts WHERE user_id = ? ORDER BY is_default DESC, name");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll();
    foreach ($accounts as &$acc) {
        $acc['balance'] = (float)$acc['balance'];
        $acc['is_default'] = (bool)$acc['is_default'];
    }
    
    sendResponse(true, [
        'summary' => [
            'total_income' => (float)($totals['income'] ?? 0),
            'total_expense' => (float)($totals['expense'] ?? 0),
            'net_amount' => (float)($totals['income'] ?? 0) - (float)($totals['expense'] ?? 0),
            'total_balance' => $totalBalance,
            'total_transactions' => (int)($totals['total_count'] ?? 0)
        ],
        'expense_breakdown' => $expenseBreakdown,
        'income_breakdown' => $incomeBreakdown,
        'recent_transactions' => $recent,
        'accounts' => $accounts
    ], 'Summary retrieved.');
}

// ============================================
// ROUTER
// ============================================

$action = sanitize($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        // Auth
        case 'register':
            if ($method === 'POST') handleRegister();
            else sendResponse(false, null, 'Method not allowed. Use POST.', 405);
            break;
            
        case 'login':
            if ($method === 'POST') handleLogin();
            else sendResponse(false, null, 'Method not allowed. Use POST.', 405);
            break;
            
        case 'logout':
            if ($method === 'GET' || $method === 'POST') handleLogout();
            else sendResponse(false, null, 'Method not allowed.', 405);
            break;
            
        case 'check':
            if ($method === 'GET') handleCheckSession();
            else sendResponse(false, null, 'Method not allowed. Use GET.', 405);
            break;
            
        // Accounts
        case 'getAccounts':
            if ($method === 'GET') handleGetAccounts();
            else sendResponse(false, null, 'Method not allowed. Use GET.', 405);
            break;
            
        case 'addAccount':
            if ($method === 'POST') handleAddAccount();
            else sendResponse(false, null, 'Method not allowed. Use POST.', 405);
            break;
            
        case 'deleteAccount':
            if ($method === 'DELETE' || $method === 'GET') handleDeleteAccount();
            else sendResponse(false, null, 'Method not allowed. Use DELETE.', 405);
            break;
            
        // Categories
        case 'getCategories':
            if ($method === 'GET') handleGetCategories();
            else sendResponse(false, null, 'Method not allowed. Use GET.', 405);
            break;
            
        case 'addCategory':
            if ($method === 'POST') handleAddCategory();
            else sendResponse(false, null, 'Method not allowed. Use POST.', 405);
            break;
            
        case 'deleteCategory':
            if ($method === 'DELETE' || $method === 'GET') handleDeleteCategory();
            else sendResponse(false, null, 'Method not allowed. Use DELETE.', 405);
            break;
            
        // Records
        case 'getRecords':
            if ($method === 'GET') handleGetRecords();
            else sendResponse(false, null, 'Method not allowed. Use GET.', 405);
            break;
            
        case 'addRecord':
            if ($method === 'POST') handleAddRecord();
            else sendResponse(false, null, 'Method not allowed. Use POST.', 405);
            break;
            
        case 'deleteRecord':
            if ($method === 'DELETE' || $method === 'GET') handleDeleteRecord();
            else sendResponse(false, null, 'Method not allowed. Use DELETE.', 405);
            break;
            
        // Summary
        case 'getSummary':
            if ($method === 'GET') handleGetSummary();
            else sendResponse(false, null, 'Method not allowed. Use GET.', 405);
            break;
            
        default:
            sendResponse(false, null, 'Invalid action. Available: register, login, logout, check, getAccounts, addAccount, deleteAccount, getCategories, addCategory, deleteCategory, getRecords, addRecord, deleteRecord, getSummary', 400);
    }
} catch (PDOException $e) {
    error_log("API Error: " . $e->getMessage());
    sendResponse(false, null, 'Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    sendResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}
?>