<?php
/**
 * Application Configuration
 * PennyWise Budget & Expense Tracker
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Application settings
define('APP_NAME', 'PennyWise');
define('APP_VERSION', '2.0.0');

// Session configuration
define('SESSION_LIFETIME', 86400); // 24 hours in seconds

// Security settings
define('PASSWORD_MIN_LENGTH', 6);
define('PASSWORD_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_OPTIONS', ['cost' => 12]);

/**
 * CORS Headers for API
 */
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Send JSON response
 */
function jsonResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require authentication (returns error if not authenticated)
 */
function requireAuth() {
    if (!isAuthenticated()) {
        jsonResponse(false, null, 'Authentication required. Please login.', 401);
    }
}

/**
 * Sanitize input data
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate unique ID
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Format currency
 */
function formatCurrency($amount, $symbol = '$') {
    return $symbol . number_format((float)$amount, 2, '.', ',');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isAuthenticated()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Log activity
 */
function logActivity($userId, $action, $details = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $action, $details]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}
?>