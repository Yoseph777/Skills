<?php
/**
 * Summary API Endpoint
 * Provides dashboard summary data and analytics
 * PennyWise Budget & Expense Tracker
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

setCorsHeaders();

// Require authentication
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(false, null, 'Method not allowed.', 405);
}

try {
    $db = getDB();
    $userId = getCurrentUserId();
    
    // Get period from query params
    $period = $_GET['period'] ?? 'month'; // month, year, all
    $year = intval($_GET['year'] ?? date('Y'));
    $month = intval($_GET['month'] ?? date('m'));
    
    // Build date filter
    $dateFilter = "";
    $params = [$userId];
    
    if ($period === 'month') {
        $dateFilter = " AND YEAR(date) = ? AND MONTH(date) = ?";
        $params[] = $year;
        $params[] = $month;
    } elseif ($period === 'year') {
        $dateFilter = " AND YEAR(date) = ?";
        $params[] = $year;
    }
    
    // Get overall summary
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
            COUNT(*) as total_transactions,
            COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
            COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count,
            COUNT(CASE WHEN type = 'transfer' THEN 1 END) as transfer_count
        FROM records 
        WHERE user_id = ? $dateFilter
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch();
    
    // Get total balance across all accounts
    $stmt = $db->prepare("SELECT SUM(balance) as total_balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $balanceResult = $stmt->fetch();
    $totalBalance = (float)($balanceResult['total_balance'] ?? 0);
    
    // Get expense breakdown by category
    $stmt = $db->prepare("
        SELECT 
            c.id, c.name, c.icon, c.color,
            SUM(r.amount) as total_amount,
            COUNT(r.id) as transaction_count
        FROM records r
        JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ? AND r.type = 'expense' $dateFilter
        GROUP BY c.id, c.name, c.icon, c.color
        ORDER BY total_amount DESC
        LIMIT 10
    ");
    $expenseParams = $params;
    $stmt->execute($expenseParams);
    $expenseBreakdown = $stmt->fetchAll();
    
    foreach ($expenseBreakdown as &$item) {
        $item['total_amount'] = (float)$item['total_amount'];
        $item['transaction_count'] = (int)$item['transaction_count'];
    }
    
    // Get income breakdown by category
    $stmt = $db->prepare("
        SELECT 
            c.id, c.name, c.icon, c.color,
            SUM(r.amount) as total_amount,
            COUNT(r.id) as transaction_count
        FROM records r
        JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ? AND r.type = 'income' $dateFilter
        GROUP BY c.id, c.name, c.icon, c.color
        ORDER BY total_amount DESC
        LIMIT 10
    ");
    $incomeParams = $params;
    $stmt->execute($incomeParams);
    $incomeBreakdown = $stmt->fetchAll();
    
    foreach ($incomeBreakdown as &$item) {
        $item['total_amount'] = (float)$item['total_amount'];
        $item['transaction_count'] = (int)$item['transaction_count'];
    }
    
    // Get recent transactions
    $stmt = $db->prepare("
        SELECT r.*, c.name as category_name, c.icon as category_icon, c.color as category_color
        FROM records r
        LEFT JOIN categories c ON r.category_id = c.id
        WHERE r.user_id = ?
        ORDER BY r.date DESC, r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentTransactions = $stmt->fetchAll();
    
    foreach ($recentTransactions as &$trans) {
        $trans['amount'] = (float)$trans['amount'];
    }
    
    // Get monthly trend (last 6 months)
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
        FROM records
        WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$userId]);
    $monthlyTrend = $stmt->fetchAll();
    
    foreach ($monthlyTrend as &$trend) {
        $trend['income'] = (float)$trend['income'];
        $trend['expense'] = (float)$trend['expense'];
        $trend['net'] = $trend['income'] - $trend['expense'];
    }
    
    // Get account breakdown
    $stmt = $db->prepare("
        SELECT id, name, balance, account_type, icon, color, is_default
        FROM accounts
        WHERE user_id = ?
        ORDER BY is_default DESC, name ASC
    ");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll();
    
    foreach ($accounts as &$acc) {
        $acc['balance'] = (float)$acc['balance'];
        $acc['is_default'] = (bool)$acc['is_default'];
    }
    
    jsonResponse(true, [
        'summary' => [
            'total_income' => (float)($summary['total_income'] ?? 0),
            'total_expense' => (float)($summary['total_expense'] ?? 0),
            'net_amount' => (float)($summary['total_income'] ?? 0) - (float)($summary['total_expense'] ?? 0),
            'total_balance' => $totalBalance,
            'total_transactions' => (int)($summary['total_transactions'] ?? 0),
            'income_count' => (int)($summary['income_count'] ?? 0),
            'expense_count' => (int)($summary['expense_count'] ?? 0),
            'transfer_count' => (int)($summary['transfer_count'] ?? 0)
        ],
        'expense_breakdown' => $expenseBreakdown,
        'income_breakdown' => $incomeBreakdown,
        'recent_transactions' => $recentTransactions,
        'monthly_trend' => $monthlyTrend,
        'accounts' => $accounts,
        'period' => $period,
        'year' => $year,
        'month' => $month
    ], 'Summary retrieved successfully.');
    
} catch (PDOException $e) {
    error_log("Summary API error: " . $e->getMessage());
    jsonResponse(false, null, 'Failed to retrieve summary data.', 500);
}
?>