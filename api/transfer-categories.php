<?php
/**
 * Transfer Categories API Endpoint
 * Handles: CRUD operations for transfer categories
 * PennyWise Budget & Expense Tracker
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get JSON input for POST/PUT requests
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Require authentication for all transfer category operations
requireAuth();

/**
 * Route requests based on method
 */
switch ($method) {
    case 'GET':
        handleGetTransferCategories();
        break;
    
    case 'POST':
        handleCreateTransferCategory($input);
        break;
    
    case 'PUT':
        handleUpdateTransferCategory($input);
        break;
    
    case 'DELETE':
        handleDeleteTransferCategory();
        break;
    
    default:
        jsonResponse(false, null, 'Method not allowed.', 405);
}

/**
 * Get all transfer categories for current user
 */
function handleGetTransferCategories() {
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $stmt = $db->prepare("
            SELECT id, name, icon, color, is_active, created_at 
            FROM transfer_categories 
            WHERE user_id = ? AND is_active = TRUE
            ORDER BY name ASC
        ");
        $stmt->execute([$userId]);
        $categories = $stmt->fetchAll();
        
        foreach ($categories as &$cat) {
            $cat['is_active'] = (bool)$cat['is_active'];
        }
        
        // Return default categories if user has none
        if (empty($categories)) {
            $defaults = [
                ['name' => 'Savings Transfer', 'icon' => 'piggy-bank', 'color' => '#10b981'],
                ['name' => 'Investment Transfer', 'icon' => 'trending-up', 'color' => '#3b82f6'],
                ['name' => 'Loan Payment', 'icon' => 'credit-card', 'color' => '#ef4444'],
                ['name' => 'Reimbursement', 'icon' => 'rotate-ccw', 'color' => '#8b5cf6'],
                ['name' => 'Cash Withdrawal', 'icon' => 'banknote', 'color' => '#f59e0b'],
                ['name' => 'Other Transfer', 'icon' => 'repeat', 'color' => '#6366f1']
            ];
            
            $stmt = $db->prepare("INSERT INTO transfer_categories (id, user_id, name, icon, color, is_active, created_at) VALUES (?, ?, ?, ?, ?, TRUE, NOW())");
            foreach ($defaults as $def) {
                $catId = generateUUID();
                $stmt->execute([$catId, $userId, $def['name'], $def['icon'], $def['color']]);
            }
            
            // Re-fetch
            $stmt = $db->prepare("SELECT id, name, icon, color, is_active, created_at FROM transfer_categories WHERE user_id = ? AND is_active = TRUE ORDER BY name ASC");
            $stmt->execute([$userId]);
            $categories = $stmt->fetchAll();
            
            foreach ($categories as &$cat) {
                $cat['is_active'] = (bool)$cat['is_active'];
            }
        }
        
        jsonResponse(true, [
            'categories' => $categories,
            'total_count' => count($categories)
        ], 'Transfer categories retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get transfer categories error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve transfer categories.', 500);
    }
}

/**
 * Create new transfer category
 */
function handleCreateTransferCategory($input) {
    $name = sanitize($input['name'] ?? '');
    $icon = sanitize($input['icon'] ?? 'repeat');
    $color = sanitize($input['color'] ?? '#6366f1');
    
    if (empty($name)) {
        jsonResponse(false, null, 'Category name is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Check for duplicate
        $stmt = $db->prepare("SELECT id FROM transfer_categories WHERE user_id = ? AND name = ?");
        $stmt->execute([$userId, $name]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'A transfer category with this name already exists.', 409);
        }
        
        $categoryId = generateUUID();
        
        $stmt = $db->prepare("
            INSERT INTO transfer_categories (id, user_id, name, icon, color, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, TRUE, NOW())
        ");
        $stmt->execute([$categoryId, $userId, $name, $icon, $color]);
        
        logActivity($userId, 'create_transfer_category', "Created transfer category: $name");
        
        jsonResponse(true, [
            'category' => [
                'id' => $categoryId,
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'is_active' => true
            ]
        ], 'Transfer category created successfully!', 201);
        
    } catch (PDOException $e) {
        error_log("Create transfer category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to create transfer category.', 500);
    }
}

/**
 * Update transfer category
 */
function handleUpdateTransferCategory($input) {
    $categoryId = sanitize($input['id'] ?? '');
    $name = sanitize($input['name'] ?? '');
    $icon = sanitize($input['icon'] ?? null);
    $color = sanitize($input['color'] ?? null);
    
    if (empty($categoryId)) {
        jsonResponse(false, null, 'Category ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $stmt = $db->prepare("SELECT * FROM transfer_categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(false, null, 'Category not found.', 404);
        }
        
        $updates = [];
        $params = [];
        
        if (!empty($name)) {
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if ($icon !== null) {
            $updates[] = 'icon = ?';
            $params[] = $icon;
        }
        if ($color !== null) {
            $updates[] = 'color = ?';
            $params[] = $color;
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update.', 400);
        }
        
        $params[] = $categoryId;
        $params[] = $userId;
        
        $sql = "UPDATE transfer_categories SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        // Fetch updated
        $stmt = $db->prepare("SELECT * FROM transfer_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $updated = $stmt->fetch();
        $updated['is_active'] = (bool)$updated['is_active'];
        
        jsonResponse(true, ['category' => $updated], 'Transfer category updated successfully!');
        
    } catch (PDOException $e) {
        error_log("Update transfer category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to update transfer category.', 500);
    }
}

/**
 * Delete transfer category (soft delete)
 */
function handleDeleteTransferCategory() {
    $categoryId = $_GET['id'] ?? '';
    
    if (empty($categoryId)) {
        jsonResponse(false, null, 'Category ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $stmt = $db->prepare("SELECT * FROM transfer_categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        $category = $stmt->fetch();
        
        if (!$category) {
            jsonResponse(false, null, 'Category not found.', 404);
        }
        
        // Soft delete
        $stmt = $db->prepare("UPDATE transfer_categories SET is_active = FALSE WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        
        logActivity($userId, 'delete_transfer_category', "Deleted transfer category: {$category['name']}");
        
        jsonResponse(true, null, 'Transfer category deleted successfully!');
        
    } catch (PDOException $e) {
        error_log("Delete transfer category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to delete transfer category.', 500);
    }
}
?>
