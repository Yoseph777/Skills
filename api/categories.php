<?php
/**
 * Categories API Endpoint
 * Handles: CRUD operations for categories
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

// Require authentication for all category operations
requireAuth();

/**
 * Route requests based on method
 */
switch ($method) {
    case 'GET':
        handleGetCategories();
        break;
    
    case 'POST':
        handleCreateCategory($input);
        break;
    
    case 'PUT':
        handleUpdateCategory($input);
        break;
    
    case 'DELETE':
        handleDeleteCategory();
        break;
    
    default:
        jsonResponse(false, null, 'Method not allowed.', 405);
}

/**
 * Get all categories for current user
 */
function handleGetCategories() {
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        $type = $_GET['type'] ?? null;
        
        $sql = "SELECT id, name, type, icon, color, budget, is_active, created_at 
                FROM categories 
                WHERE user_id = ? AND is_active = TRUE";
        
        $params = [$userId];
        
        if ($type && in_array($type, ['income', 'expense'])) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY type, name ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();
        
        // Format data
        foreach ($categories as &$cat) {
            $cat['budget'] = $cat['budget'] ? (float)$cat['budget'] : null;
            $cat['is_active'] = (bool)$cat['is_active'];
        }
        
        // Group by type
        $grouped = [
            'income' => [],
            'expense' => []
        ];
        
        foreach ($categories as $cat) {
            $grouped[$cat['type']][] = $cat;
        }
        
        jsonResponse(true, [
            'categories' => $categories,
            'grouped' => $grouped,
            'total_count' => count($categories)
        ], 'Categories retrieved successfully.');
        
    } catch (PDOException $e) {
        error_log("Get categories error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to retrieve categories.', 500);
    }
}

/**
 * Create new category
 */
function handleCreateCategory($input) {
    $name = sanitize($input['name'] ?? '');
    $type = sanitize($input['type'] ?? 'expense');
    $icon = sanitize($input['icon'] ?? 'tag');
    $color = sanitize($input['color'] ?? '#f48c8c');
    $budget = isset($input['budget']) ? floatval($input['budget']) : null;
    
    // Validation
    if (empty($name)) {
        jsonResponse(false, null, 'Category name is required.', 400);
    }
    
    if (!in_array($type, ['income', 'expense'])) {
        $type = 'expense';
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Check if category with same name and type exists
        $stmt = $db->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ?");
        $stmt->execute([$userId, $name, $type]);
        if ($stmt->fetch()) {
            jsonResponse(false, null, 'A category with this name already exists.', 409);
        }
        
        // Generate UUID
        $categoryId = generateUUID();
        
        // Insert category
        $stmt = $db->prepare("
            INSERT INTO categories (id, user_id, name, type, icon, color, budget, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$categoryId, $userId, $name, $type, $icon, $color, $budget]);
        
        logActivity($userId, 'create_category', "Created category: $name ($type)");
        
        jsonResponse(true, [
            'category' => [
                'id' => $categoryId,
                'name' => $name,
                'type' => $type,
                'icon' => $icon,
                'color' => $color,
                'budget' => $budget,
                'is_active' => true
            ]
        ], 'Category created successfully!', 201);
        
    } catch (PDOException $e) {
        error_log("Create category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to create category.', 500);
    }
}

/**
 * Update existing category
 */
function handleUpdateCategory($input) {
    $categoryId = sanitize($input['id'] ?? '');
    $name = sanitize($input['name'] ?? '');
    $type = sanitize($input['type'] ?? null);
    $icon = sanitize($input['icon'] ?? null);
    $color = sanitize($input['color'] ?? null);
    $budget = $input['budget'] ?? null;
    
    if (empty($categoryId)) {
        jsonResponse(false, null, 'Category ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify category belongs to user
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        $category = $stmt->fetch();
        
        if (!$category) {
            jsonResponse(false, null, 'Category not found.', 404);
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        if (!empty($name)) {
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if ($type !== null && in_array($type, ['income', 'expense'])) {
            $updates[] = 'type = ?';
            $params[] = $type;
        }
        if ($icon !== null) {
            $updates[] = 'icon = ?';
            $params[] = $icon;
        }
        if ($color !== null) {
            $updates[] = 'color = ?';
            $params[] = $color;
        }
        if ($budget !== null) {
            $updates[] = 'budget = ?';
            $params[] = $budget ? floatval($budget) : null;
        }
        
        if (empty($updates)) {
            jsonResponse(false, null, 'No fields to update.', 400);
        }
        
        $params[] = $categoryId;
        $params[] = $userId;
        
        $sql = "UPDATE categories SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        logActivity($userId, 'update_category', "Updated category: $categoryId");
        
        // Fetch updated category
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $updatedCategory = $stmt->fetch();
        $updatedCategory['budget'] = $updatedCategory['budget'] ? (float)$updatedCategory['budget'] : null;
        $updatedCategory['is_active'] = (bool)$updatedCategory['is_active'];
        
        jsonResponse(true, ['category' => $updatedCategory], 'Category updated successfully!');
        
    } catch (PDOException $e) {
        error_log("Update category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to update category.', 500);
    }
}

/**
 * Delete category (soft delete)
 */
function handleDeleteCategory() {
    $categoryId = $_GET['id'] ?? '';
    
    if (empty($categoryId)) {
        jsonResponse(false, null, 'Category ID is required.', 400);
    }
    
    try {
        $db = getDB();
        $userId = getCurrentUserId();
        
        // Verify category belongs to user
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        $category = $stmt->fetch();
        
        if (!$category) {
            jsonResponse(false, null, 'Category not found.', 404);
        }
        
        // Soft delete (set is_active = FALSE)
        $stmt = $db->prepare("UPDATE categories SET is_active = FALSE WHERE id = ? AND user_id = ?");
        $stmt->execute([$categoryId, $userId]);
        
        logActivity($userId, 'delete_category', "Deleted category: {$category['name']}");
        
        jsonResponse(true, null, 'Category deleted successfully!');
        
    } catch (PDOException $e) {
        error_log("Delete category error: " . $e->getMessage());
        jsonResponse(false, null, 'Failed to delete category.', 500);
    }
}
?>