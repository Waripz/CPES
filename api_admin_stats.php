<?php
/**
 * Admin Real-time Stats API
 * Fetches live counts for pending items and reports
 */
session_start();

// Security check
if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stats = [];
    
    // Pending items count
    $stats['items_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
    
    // Unopened reports count
    $stats['reports_unopened'] = (int)$pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();
    
    // Total reports
    $stats['reports_total'] = (int)$pdo->query("SELECT COUNT(*) FROM report")->fetchColumn();
    
    // Total users
    $stats['users_total'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE matricNo != 'ADMIN'")->fetchColumn();
    
    // Total items
    $stats['items_total'] = (int)$pdo->query("SELECT COUNT(*) FROM item")->fetchColumn();
    
    // Items available
    $stats['items_available'] = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE status = 'available'")->fetchColumn();
    
    // Items sold
    $stats['items_sold'] = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE status = 'sold'")->fetchColumn();
    
    // Items rejected
    $stats['items_rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE status = 'rejected'")->fetchColumn();
    
    // Total sales value
    $stats['total_value'] = (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold'")->fetchColumn();
    
    // Sales this week
    $stats['sales_week'] = (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold' AND sold_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    
    // New items this week
    $stats['items_new_week'] = (int)$pdo->query("SELECT COUNT(*) FROM item WHERE postDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    
    // Fetch latest pending items (5 most recent)
    $pendingItems = $pdo->query("
        SELECT i.ItemID, i.title, i.price, i.postDate, i.image, u.name as seller_name 
        FROM item i 
        JOIN users u ON i.UserID = u.UserID 
        WHERE i.status = 'under_review'
        ORDER BY i.postDate DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch latest unopened reports (5 most recent)
    $newReports = $pdo->query("
        SELECT r.ReportID, COALESCE(r.report_type, 'other') as report_type, r.submitDate, u.name as reporter_name 
        FROM report r 
        LEFT JOIN users u ON r.UserID = u.UserID 
        WHERE r.is_opened = 0 OR r.is_opened IS NULL
        ORDER BY r.submitDate DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Add timestamp
    $stats['timestamp'] = date('Y-m-d H:i:s');
    $stats['pending_items'] = $pendingItems;
    $stats['new_reports'] = $newReports;
    
    echo json_encode(['success' => true, 'stats' => $stats]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
