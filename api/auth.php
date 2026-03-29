<?php
/**
 * Authentication API Endpoint
 * Handles: Register, Login, Logout, Session Check
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

/**
 * Handle different authentication actions
 */
switch ($action) {
    case 'register':
        handleRegister($input);
        break;
    
    case 'login':
        handleLogin($input);
        break;
    
    case 'logout':
        handleLogout();
        break;
    
    case 'check':
        handleSessionCheck();
        break;
    
    case 'user':
        handleGetUser();
        break;
    
    default:
        jsonResponse(false, null, 'Invalid action. Available actions: register, login, logout, check, user', 400);
}

/**
 * Register new user
 */
function handleRegister($input) {
    // Validate input
    $username = sanitize($input['username'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters.';
    }
    
    if (!validateEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    
    if (!empty($errors)) {
        jsonResponse(false, ['errors' => $errors], 'Validation failed.', 400);
    }
    
    try {
        $db = getDB();
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'Email is already registered. Please login instead.', 409);
        }
        
        // Check if username already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'Username is already taken. Please choose another.', 409);
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS);
        
        // Generate UUID
        $userId = generateUUID();
        
        // Insert user
        $stmt = $db->prepare("INSERT INTO users (id, username, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $username, $email, $hashedPassword]);
        
        // Create default account for user
        $accountId = generateUUID();
        $stmt = $db->prepare("INSERT INTO accounts (id, user_id, name, balance, account_type, is_default) VALUES (?, ?, 'Cash', 0.00, 'cash', TRUE)");
        $stmt->execute([$accountId, $userId]);
        
        // Create default categories for user
        $defaultCategories = [
            ['name' => 'Salary', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#10b981'],
            ['name' => 'Freelance', 'type' => 'income', 'icon' => 'laptop', 'color' => '#06b6d4'],
            ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#3b82f6'],
            ['name' => 'Food & Dining', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#f59e0b'],
            ['name' => 'Transportation', 'type' => 'expense', 'icon' => 'car', 'color' => '#ef4444'],
            ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#8b5cf6'],
            ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#ec4899'],
            ['name' => 'Bills & Utilities', 'type' => 'expense', 'icon' => 'file-text', 'color' => '#64748b'],
            ['name' => 'Healthcare', 'type' => 'expense', 'icon' => 'heart', 'color' => '#f97316'],
            ['name' => 'Education', 'type' => 'expense', 'icon' => 'book', 'color' => '#14b8a6'],
        ];
        
        $stmt = $db->prepare("INSERT INTO categories (id, user_id, name, type, icon, color) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($defaultCategories as $cat) {
            $catId = generateUUID();
            $stmt->execute([$catId, $userId, $cat['name'], $cat['type'], $cat['icon'], $cat['color']]);
        }
        
        // Log activity
        logActivity($userId, 'register', "New user registered: $username");
        
        // Auto-login after registration
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        
        jsonResponse(true, [
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $email
            ],
            'message' => 'Registration successful! Welcome to PennyWise.'
        ], 'Registration successful!', 201);
        
    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        jsonResponse(false, null, 'Registration failed. Please try again.', 500);
    }
}

/**
 * Login user
 */
function handleLogin($input) {
    $email = sanitize($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    // Validation
    if (empty($email) || empty($password)) {
        jsonResponse(false, null, 'Email and password are required.', 400);
    }
    
    try {
        $db = getDB();
        
        // Find user by email
        $stmt = $db->prepare("SELECT id, username, email, password, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, null, 'Invalid email or password.', 401);
        }
        
        // Check if account is active
        if (!$user['is_active']) {
            jsonResponse(false, null, 'Your account has been deactivated. Please contact support.', 403);
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            logActivity($user['id'], 'failed_login', "Failed login attempt for email: $email");
            jsonResponse(false, null, 'Invalid email or password.', 401);
        }
        
        // Update last login
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        
        // Log activity
        logActivity($user['id'], 'login', "User logged in: {$user['username']}");
        
        jsonResponse(true, [
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]
        ], 'Login successful! Welcome back.');
        
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        jsonResponse(false, null, 'Login failed. Please try again.', 500);
    }
}

/**
 * Logout user
 */
function handleLogout() {
    $userId = getCurrentUserId();
    
    if ($userId) {
        logActivity($userId, 'logout', "User logged out");
    }
    
    // Destroy session
    session_unset();
    session_destroy();
    
    // Start new session for flash message
    session_start();
    
    jsonResponse(true, null, 'You have been logged out successfully.');
}

/**
 * Check session status
 */
function handleSessionCheck() {
    if (isAuthenticated()) {
        $user = getCurrentUser();
        jsonResponse(true, [
            'authenticated' => true,
            'user' => $user
        ], 'Session is active.');
    } else {
        jsonResponse(true, [
            'authenticated' => false,
            'user' => null
        ], 'No active session.');
    }
}

/**
 * Get current user data
 */
function handleGetUser() {
    requireAuth();
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Get user info
        $stmt = $db->prepare("SELECT id, username, email, created_at, last_login FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, null, 'User not found.', 404);
        }
        
        // Get summary data
        $stmt = $db->prepare("SELECT SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income, SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense FROM records WHERE user_id = ?");
        $stmt->execute([$userId]);
        $summary = $stmt->fetch();
        
        // Get account count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $accountCount = $stmt->fetch()['count'];
        
        // Get category count
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE user_id = ?");
        $stmt->execute([$userId]);
        $categoryCount = $stmt->fetch()['count'];
        
        jsonResponse(true, [
            'user' => $user,
            'summary' => [
                'total_income' => (float)($summary['total_income'] ?? 0),
                'total_expense' => (float)($summary['total_expense'] ?? 0),
                'balance' => (float)($summary['total_income'] ?? 0) - (float)($summary['total_expense'] ?? 0),
                'account_count' => (int)$accountCount,
                'category_count' => (int)$categoryCount
            ]
        ], 'User data retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get user error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve user data.', 500);
    }
}
?>