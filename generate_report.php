<?php
session_start();

// Security check
if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// Database connection
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database Error");
}

// Get parameters
$reportType = $_GET['type'] ?? 'complete';
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'html';
$includeCharts = isset($_GET['charts']) && $_GET['charts'] == 1;
$includeSummary = isset($_GET['summary']) && $_GET['summary'] == 1;
$includeDetails = isset($_GET['details']) && $_GET['details'] == 1;

// Validate dates
$startDate = date('Y-m-d', strtotime($startDate));
$endDate = date('Y-m-d', strtotime($endDate));

if ($startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// Helper functions
if (!function_exists('h')) { function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
function formatMoney($v) { return 'RM ' . number_format((float)$v, 2); }
function formatDate($d) { return date('d M Y', strtotime($d)); }

// ===== GATHER REPORT DATA =====

$reportData = [];

// User Statistics (within date range for new registrations)
if ($reportType === 'complete' || $reportType === 'users') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE matricNo != 'ADMIN'");
    $stmt->execute();
    $reportData['users']['total'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE matricNo != 'ADMIN' AND (blacklist_until IS NULL OR blacklist_until < NOW())");
    $stmt->execute();
    $reportData['users']['active'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE blacklist_until IS NOT NULL AND blacklist_until > NOW()");
    $stmt->execute();
    $reportData['users']['blacklisted'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT UserID) FROM item");
    $stmt->execute();
    $reportData['users']['with_items'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT UserID) FROM item WHERE status = 'sold'");
    $stmt->execute();
    $reportData['users']['with_sales'] = $stmt->fetchColumn();
    
    // Users registered in period (if created_at column exists)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) BETWEEN ? AND ? AND matricNo != 'ADMIN'");
        $stmt->execute([$startDate, $endDate]);
        $reportData['users']['new_in_period'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $reportData['users']['new_in_period'] = 'N/A';
    }
    
    // User list with details
    if ($includeDetails) {
        $stmt = $pdo->prepare("
            SELECT u.UserID, u.name, u.matricNo, u.email, u.blacklist_until,
                   COUNT(DISTINCT i.ItemID) as total_items,
                   COUNT(DISTINCT CASE WHEN i.status = 'sold' THEN i.ItemID END) as sold_items,
                   COALESCE(SUM(CASE WHEN i.status = 'sold' THEN COALESCE(i.sold_price, i.price) END), 0) as total_sales
            FROM users u
            LEFT JOIN item i ON u.UserID = i.UserID
            WHERE u.matricNo != 'ADMIN'
            GROUP BY u.UserID
            ORDER BY total_sales DESC
            LIMIT 50
        ");
        $stmt->execute();
        $reportData['users']['list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Item Statistics (within date range)
if ($reportType === 'complete' || $reportType === 'items') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE DATE(postDate) BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    $reportData['items']['new_in_period'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item");
    $stmt->execute();
    $reportData['items']['total'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'available'");
    $stmt->execute();
    $reportData['items']['available'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'sold'");
    $stmt->execute();
    $reportData['items']['sold'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'under_review'");
    $stmt->execute();
    $reportData['items']['pending'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'rejected'");
    $stmt->execute();
    $reportData['items']['rejected'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'blacklisted'");
    $stmt->execute();
    $reportData['items']['blacklisted'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE category = 'Events'");
    $stmt->execute();
    $reportData['items']['events'] = $stmt->fetchColumn();
    
    // Category distribution
    $stmt = $pdo->prepare("SELECT category, COUNT(*) as count FROM item WHERE category IS NOT NULL GROUP BY category ORDER BY count DESC");
    $stmt->execute();
    $reportData['items']['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Items posted in period
    if ($includeDetails) {
        $stmt = $pdo->prepare("
            SELECT i.ItemID, i.title, i.price, i.status, i.category, i.postDate, u.name as seller_name
            FROM item i
            JOIN users u ON i.UserID = u.UserID
            WHERE DATE(i.postDate) BETWEEN ? AND ?
            ORDER BY i.postDate DESC
            LIMIT 100
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData['items']['list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Sales Statistics (within date range)
if ($reportType === 'complete' || $reportType === 'sales') {
    // Sales in period
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(COALESCE(sold_price, price)), 0) as total_value,
               COALESCE(AVG(COALESCE(sold_price, price)), 0) as avg_value,
               COALESCE(MAX(COALESCE(sold_price, price)), 0) as max_value,
               COALESCE(MIN(COALESCE(sold_price, price)), 0) as min_value
        FROM item 
        WHERE status = 'sold' 
        AND (DATE(sold_date) BETWEEN ? AND ? OR (sold_date IS NULL AND DATE(last_updated) BETWEEN ? AND ?))
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $salesInPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportData['sales']['in_period'] = $salesInPeriod;
    
    // Total sales all time
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold'");
    $stmt->execute();
    $reportData['sales']['total_all_time'] = $stmt->fetchColumn();
    
    // Daily sales breakdown in period
    $stmt = $pdo->prepare("
        SELECT DATE(COALESCE(sold_date, last_updated)) as sale_date,
               COUNT(*) as count,
               SUM(COALESCE(sold_price, price)) as total
        FROM item 
        WHERE status = 'sold' 
        AND (DATE(sold_date) BETWEEN ? AND ? OR (sold_date IS NULL AND DATE(last_updated) BETWEEN ? AND ?))
        GROUP BY DATE(COALESCE(sold_date, last_updated))
        ORDER BY sale_date ASC
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $reportData['sales']['daily'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top sellers in period
    $stmt = $pdo->prepare("
        SELECT u.name, u.matricNo,
               COUNT(*) as sold_count,
               SUM(COALESCE(i.sold_price, i.price)) as total_sales
        FROM item i
        JOIN users u ON i.UserID = u.UserID
        WHERE i.status = 'sold'
        AND (DATE(i.sold_date) BETWEEN ? AND ? OR (i.sold_date IS NULL AND DATE(i.last_updated) BETWEEN ? AND ?))
        GROUP BY u.UserID
        ORDER BY total_sales DESC
        LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $reportData['sales']['top_sellers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sales by category
    $stmt = $pdo->prepare("
        SELECT category,
               COUNT(*) as count,
               SUM(COALESCE(sold_price, price)) as total
        FROM item
        WHERE status = 'sold'
        AND (DATE(sold_date) BETWEEN ? AND ? OR (sold_date IS NULL AND DATE(last_updated) BETWEEN ? AND ?))
        GROUP BY category
        ORDER BY total DESC
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $reportData['sales']['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sold items list
    if ($includeDetails) {
        $stmt = $pdo->prepare("
            SELECT i.ItemID, i.title, i.price, i.sold_price, i.category, 
                   COALESCE(i.sold_date, i.last_updated) as sale_date,
                   u.name as seller_name
            FROM item i
            JOIN users u ON i.UserID = u.UserID
            WHERE i.status = 'sold'
            AND (DATE(i.sold_date) BETWEEN ? AND ? OR (i.sold_date IS NULL AND DATE(i.last_updated) BETWEEN ? AND ?))
            ORDER BY sale_date DESC
            LIMIT 100
        ");
        $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $reportData['sales']['list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Report Statistics (within date range)
if ($reportType === 'complete' || $reportType === 'reports') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM report WHERE DATE(submitDate) BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    $reportData['reports']['in_period'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM report");
    $stmt->execute();
    $reportData['reports']['total'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL");
    $stmt->execute();
    $reportData['reports']['unopened'] = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM report WHERE is_checked = 1");
    $stmt->execute();
    $reportData['reports']['checked'] = $stmt->fetchColumn();
    
    // Reports list in period
    if ($includeDetails) {
        $stmt = $pdo->prepare("
            SELECT r.ReportID, r.reason, r.submitDate, r.is_opened, r.is_checked,
                   u1.name as reporter_name,
                   u2.name as reported_user_name,
                   i.title as reported_item_title
            FROM report r
            LEFT JOIN users u1 ON r.UserID = u1.UserID
            LEFT JOIN users u2 ON r.reportedUserID = u2.UserID
            LEFT JOIN item i ON r.reportedItemID = i.ItemID
            WHERE DATE(r.submitDate) BETWEEN ? AND ?
            ORDER BY r.submitDate DESC
            LIMIT 50
        ");
        $stmt->execute([$startDate, $endDate]);
        $reportData['reports']['list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Activity Statistics (within date range)
if ($reportType === 'complete' || $reportType === 'activity') {
    // Messages in period
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE DATE(timestamp) BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    $reportData['activity']['messages'] = $stmt->fetchColumn();
    
    // Reviews in period (if has date column)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reviews");
        $stmt->execute();
        $reportData['activity']['reviews_total'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COALESCE(AVG(rating), 0) FROM reviews");
        $stmt->execute();
        $reportData['activity']['avg_rating'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $reportData['activity']['reviews_total'] = 'N/A';
        $reportData['activity']['avg_rating'] = 'N/A';
    }
    
    // Memos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM memo");
    $stmt->execute();
    $reportData['activity']['memos'] = $stmt->fetchColumn();
}

// ===== HANDLE CSV FORMAT =====
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_report_' . $startDate . '_' . $endDate . '.csv"');
    
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Report Header
    fputcsv($output, ['Campus Preloved E-Shop SYSTEM REPORT']);
    fputcsv($output, ['Period:', formatDate($startDate) . ' - ' . formatDate($endDate)]);
    fputcsv($output, ['Generated:', date('d M Y H:i:s')]);
    fputcsv($output, []);
    
    // Users Section
    if (isset($reportData['users'])) {
        fputcsv($output, ['=== USER STATISTICS ===']);
        fputcsv($output, ['Total Users', $reportData['users']['total']]);
        fputcsv($output, ['Active Users', $reportData['users']['active']]);
        fputcsv($output, ['Blacklisted Users', $reportData['users']['blacklisted']]);
        fputcsv($output, ['Users with Items', $reportData['users']['with_items']]);
        fputcsv($output, ['Users with Sales', $reportData['users']['with_sales']]);
        fputcsv($output, []);
    }
    
    // Items Section
    if (isset($reportData['items'])) {
        fputcsv($output, ['=== ITEM STATISTICS ===']);
        fputcsv($output, ['Total Items', $reportData['items']['total']]);
        fputcsv($output, ['New Items (This Period)', $reportData['items']['new_in_period']]);
        fputcsv($output, ['Available Items', $reportData['items']['available']]);
        fputcsv($output, ['Sold Items', $reportData['items']['sold']]);
        fputcsv($output, ['Pending Review', $reportData['items']['pending']]);
        fputcsv($output, ['Rejected Items', $reportData['items']['rejected']]);
        fputcsv($output, []);
        
        if (!empty($reportData['items']['categories'])) {
            fputcsv($output, ['Category', 'Item Count']);
            foreach ($reportData['items']['categories'] as $cat) {
                fputcsv($output, [$cat['category'], $cat['count']]);
            }
            fputcsv($output, []);
        }
    }
    
    // Sales Section
    if (isset($reportData['sales'])) {
        fputcsv($output, ['=== SALES STATISTICS ===']);
        fputcsv($output, ['Sales (This Period)', $reportData['sales']['in_period']['count']]);
        fputcsv($output, ['Sales Value (This Period)', formatMoney($reportData['sales']['in_period']['total_value'])]);
        fputcsv($output, ['Average Sale Price', formatMoney($reportData['sales']['in_period']['avg_value'])]);
        fputcsv($output, ['Highest Sale', formatMoney($reportData['sales']['in_period']['max_value'])]);
        fputcsv($output, ['Lowest Sale', formatMoney($reportData['sales']['in_period']['min_value'])]);
        fputcsv($output, ['Total Sales Value (All Time)', formatMoney($reportData['sales']['total_all_time'])]);
        fputcsv($output, []);
        
        if (!empty($reportData['sales']['top_sellers'])) {
            fputcsv($output, ['Top Sellers']);
            fputcsv($output, ['Name', 'Matric No.', 'Items Sold', 'Total Value']);
            foreach ($reportData['sales']['top_sellers'] as $seller) {
                fputcsv($output, [$seller['name'], $seller['matricNo'], $seller['sold_count'], formatMoney($seller['total_sales'])]);
            }
            fputcsv($output, []);
        }
    }
    
    // Reports Section
    if (isset($reportData['reports'])) {
        fputcsv($output, ['=== REPORT STATISTICS ===']);
        fputcsv($output, ['Reports (This Period)', $reportData['reports']['in_period']]);
        fputcsv($output, ['Total Reports', $reportData['reports']['total']]);
        fputcsv($output, ['Unopened Reports', $reportData['reports']['unopened']]);
        fputcsv($output, ['Checked Reports', $reportData['reports']['checked']]);
        fputcsv($output, []);
    }
    
    // Activity Section
    if (isset($reportData['activity'])) {
        fputcsv($output, ['=== ACTIVITY STATISTICS ===']);
        fputcsv($output, ['Messages (This Period)', $reportData['activity']['messages']]);
        fputcsv($output, ['Total Reviews', $reportData['activity']['reviews_total']]);
        fputcsv($output, ['Average Rating', number_format($reportData['activity']['avg_rating'], 1)]);
        fputcsv($output, ['Total Memos', $reportData['activity']['memos']]);
    }
    
    fclose($output);
    exit;
}

// ===== HTML FORMAT =====
$reportTitle = [
    'complete' => 'Complete System Report',
    'users' => 'Users Report',
    'items' => 'Items/Products Report',
    'sales' => 'Sales Report',
    'reports' => 'Complaints Report',
    'activity' => 'Activity Report'
][$reportType] ?? 'System Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($reportTitle) ?> - E-Kedai</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($includeCharts): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #18181b;
            background: #f4f4f5;
            padding: 32px;
        }
        
        .report-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07);
            overflow: hidden;
        }
        
        .report-header {
            background: linear-gradient(135deg, #18181b 0%, #27272a 100%);
            color: white;
            padding: 32px 40px;
        }
        
        .report-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .logo-icon {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            color: #18181b;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
        }
        
        .report-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .report-period {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .report-meta {
            display: flex;
            gap: 24px;
            margin-top: 16px;
            font-size: 13px;
            opacity: 0.8;
        }
        
        .report-body {
            padding: 32px 40px;
        }
        
        .section {
            margin-bottom: 32px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #18181b;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e4e4e7;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title svg {
            width: 20px;
            height: 20px;
            color: #52525b;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            background: #f9fafb;
            border: 1px solid #e4e4e7;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #18181b;
        }
        
        .stat-value.success { color: #059669; }
        .stat-value.warning { color: #d97706; }
        .stat-value.danger { color: #dc2626; }
        .stat-value.info { color: #2563eb; }
        
        .stat-label {
            font-size: 12px;
            color: #71717a;
            margin-top: 4px;
        }
        
        .chart-container {
            height: 280px;
            margin-bottom: 24px;
            background: #f9fafb;
            border-radius: 10px;
            padding: 16px;
        }
        
        .chart-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e4e4e7;
        }
        
        .data-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #52525b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table tr:hover {
            background: #fafafa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success { background: #ecfdf5; color: #059669; }
        .badge-warning { background: #fffbeb; color: #d97706; }
        .badge-danger { background: #fef2f2; color: #dc2626; }
        .badge-info { background: #eff6ff; color: #2563eb; }
        .badge-neutral { background: #f4f4f5; color: #71717a; }
        
        .report-footer {
            background: #f9fafb;
            padding: 20px 40px;
            text-align: center;
            font-size: 12px;
            color: #71717a;
            border-top: 1px solid #e4e4e7;
        }
        
        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 12px;
        }
        
        .action-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }
        
        .action-btn-primary {
            background: #18181b;
            color: white;
        }
        
        .action-btn-primary:hover {
            background: #27272a;
        }
        
        .action-btn-secondary {
            background: white;
            color: #18181b;
            border: 1px solid #e4e4e7;
        }
        
        .action-btn-secondary:hover {
            background: #f4f4f5;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            .report-container {
                box-shadow: none;
                border-radius: 0;
            }
            .no-print {
                display: none;
            }
            .chart-container {
                page-break-inside: avoid;
            }
            .section {
                page-break-inside: avoid;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .chart-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Action Buttons -->
    <div class="no-print">
        <button class="action-btn action-btn-secondary" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print
        </button>
        <button class="action-btn action-btn-primary" onclick="window.close()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Close
        </button>
    </div>

    <div class="report-container">
        <!-- Report Header -->
        <div class="report-header">
            <div class="report-logo">
                <div class="logo-icon">CPES</div>
                <span class="logo-text">Campus Preloved E-Shop</span>
            </div>
            <h1 class="report-title"><?= h($reportTitle) ?></h1>
            <p class="report-period">Period: <?= formatDate($startDate) ?> - <?= formatDate($endDate) ?></p>
            <div class="report-meta">
                <span>Generated: <?= date('d M Y, H:i:s') ?></span>
                <span>Admin: <?= h($_SESSION['admin_name']) ?></span>
            </div>
        </div>

        <div class="report-body">
            <?php if ($includeSummary && isset($reportData['users'])): ?>
            <!-- User Statistics Section -->
            <div class="section">
                <h2 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    User Statistics
                </h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value info"><?= number_format($reportData['users']['total']) ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= number_format($reportData['users']['active']) ?></div>
                        <div class="stat-label">Active Users</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value danger"><?= number_format($reportData['users']['blacklisted']) ?></div>
                        <div class="stat-label">Blacklisted</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($reportData['users']['with_sales']) ?></div>
                        <div class="stat-label">With Sales</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($includeSummary && isset($reportData['items'])): ?>
            <!-- Item Statistics Section -->
            <div class="section">
                <h2 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                    Item Statistics
                </h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value info"><?= number_format($reportData['items']['total']) ?></div>
                        <div class="stat-label">Total Items</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= number_format($reportData['items']['new_in_period']) ?></div>
                        <div class="stat-label">New Items (Period)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($reportData['items']['available']) ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value warning"><?= number_format($reportData['items']['pending']) ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>

                <?php if ($includeCharts && !empty($reportData['items']['categories'])): ?>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
                <?php endif; ?>

                <?php if (!empty($reportData['items']['categories'])): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Item Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['items']['categories'] as $cat): 
                            $percent = $reportData['items']['total'] > 0 ? round(($cat['count'] / $reportData['items']['total']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= h($cat['category']) ?></td>
                            <td><?= number_format($cat['count']) ?></td>
                            <td><?= $percent ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($includeSummary && isset($reportData['sales'])): ?>
            <!-- Sales Statistics Section -->
            <div class="section">
                <h2 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Sales Statistics (<?= formatDate($startDate) ?> - <?= formatDate($endDate) ?>)
                </h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value info"><?= number_format($reportData['sales']['in_period']['count']) ?></div>
                        <div class="stat-label">Items Sold</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= formatMoney($reportData['sales']['in_period']['total_value']) ?></div>
                        <div class="stat-label">Total Value</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= formatMoney($reportData['sales']['in_period']['avg_value']) ?></div>
                        <div class="stat-label">Average Price</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value warning"><?= formatMoney($reportData['sales']['in_period']['max_value']) ?></div>
                        <div class="stat-label">Highest Value</div>
                    </div>
                </div>

                <?php if ($includeCharts && !empty($reportData['sales']['daily'])): ?>
                <div class="chart-container">
                    <canvas id="salesChart"></canvas>
                </div>
                <?php endif; ?>

                <?php if (!empty($reportData['sales']['top_sellers'])): ?>
                <h3 style="font-size: 15px; font-weight: 600; margin: 24px 0 12px;">Top Sellers</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Matric No.</th>
                            <th>Items Sold</th>
                            <th>Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['sales']['top_sellers'] as $i => $seller): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= h($seller['name']) ?></td>
                            <td><?= h($seller['matricNo']) ?></td>
                            <td><?= number_format($seller['sold_count']) ?></td>
                            <td><strong><?= formatMoney($seller['total_sales']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($reportData['sales']['by_category'])): ?>
                <h3 style="font-size: 15px; font-weight: 600; margin: 24px 0 12px;">Sales by Category</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Items Sold</th>
                            <th>Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['sales']['by_category'] as $cat): ?>
                        <tr>
                            <td><?= h($cat['category'] ?? 'No Category') ?></td>
                            <td><?= number_format($cat['count']) ?></td>
                            <td><strong><?= formatMoney($cat['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($includeSummary && isset($reportData['reports'])): ?>
            <!-- Reports Statistics Section -->
            <div class="section">
                <h2 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                    Report Statistics
                </h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value info"><?= number_format($reportData['reports']['total']) ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value warning"><?= number_format($reportData['reports']['in_period']) ?></div>
                        <div class="stat-label">Reports (Period)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value danger"><?= number_format($reportData['reports']['unopened']) ?></div>
                        <div class="stat-label">Unopened</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= number_format($reportData['reports']['checked']) ?></div>
                        <div class="stat-label">Checked</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($includeSummary && isset($reportData['activity'])): ?>
            <!-- Activity Statistics Section -->
            <div class="section">
                <h2 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Activity Statistics
                </h2>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value info"><?= number_format($reportData['activity']['messages']) ?></div>
                        <div class="stat-label">Messages (Period)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= is_numeric($reportData['activity']['reviews_total']) ? number_format($reportData['activity']['reviews_total']) : $reportData['activity']['reviews_total'] ?></div>
                        <div class="stat-label">Total Reviews</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value success"><?= is_numeric($reportData['activity']['avg_rating']) ? number_format($reportData['activity']['avg_rating'], 1) : $reportData['activity']['avg_rating'] ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?= number_format($reportData['activity']['memos']) ?></div>
                        <div class="stat-label">Total Memos</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($includeDetails): ?>
            <!-- Detailed Lists -->
            <?php if (!empty($reportData['items']['list'])): ?>
            <div class="section">
                <h2 class="section-title">Item List (<?= formatDate($startDate) ?> - <?= formatDate($endDate) ?>)</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Seller</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['items']['list'] as $item): 
                            $badgeClass = 'badge-neutral';
                            if ($item['status'] === 'available') $badgeClass = 'badge-success';
                            elseif ($item['status'] === 'sold') $badgeClass = 'badge-info';
                            elseif ($item['status'] === 'under_review') $badgeClass = 'badge-warning';
                            elseif ($item['status'] === 'rejected') $badgeClass = 'badge-danger';
                        ?>
                        <tr>
                            <td><?= h($item['title']) ?></td>
                            <td><?= h($item['seller_name']) ?></td>
                            <td><?= h($item['category']) ?></td>
                            <td><?= formatMoney($item['price']) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= ucfirst(str_replace('_', ' ', $item['status'])) ?></span></td>
                            <td><?= formatDate($item['postDate']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($reportData['sales']['list'])): ?>
            <div class="section">
                <h2 class="section-title">Sales List (<?= formatDate($startDate) ?> - <?= formatDate($endDate) ?>)</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Seller</th>
                            <th>Category</th>
                            <th>Original Price</th>
                            <th>Sale Price</th>
                            <th>Sale Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['sales']['list'] as $item): ?>
                        <tr>
                            <td><?= h($item['title']) ?></td>
                            <td><?= h($item['seller_name']) ?></td>
                            <td><?= h($item['category']) ?></td>
                            <td><?= formatMoney($item['price']) ?></td>
                            <td><strong><?= formatMoney($item['sold_price'] ?? $item['price']) ?></strong></td>
                            <td><?= formatDate($item['sale_date']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($reportData['reports']['list'])): ?>
            <div class="section">
                <h2 class="section-title">Reports List (<?= formatDate($startDate) ?> - <?= formatDate($endDate) ?>)</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reporter</th>
                            <th>Reported</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['reports']['list'] as $report): ?>
                        <tr>
                            <td>#<?= $report['ReportID'] ?></td>
                            <td><?= h($report['reporter_name']) ?></td>
                            <td><?= h($report['reported_user_name'] ?? $report['reported_item_title'] ?? '-') ?></td>
                            <td><?= h(substr($report['reason'], 0, 50)) ?><?= strlen($report['reason']) > 50 ? '...' : '' ?></td>
                            <td>
                                <?php if ($report['is_opened']): ?>
                                    <span class="badge badge-success">Opened</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">New</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($report['submitDate']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($reportData['users']['list'])): ?>
            <div class="section">
                <h2 class="section-title">User List</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Matric No.</th>
                            <th>Total Items</th>
                            <th>Items Sold</th>
                            <th>Total Sales</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['users']['list'] as $user): ?>
                        <tr>
                            <td><?= h($user['name']) ?></td>
                            <td><?= h($user['matricNo']) ?></td>
                            <td><?= number_format($user['total_items']) ?></td>
                            <td><?= number_format($user['sold_items']) ?></td>
                            <td><strong><?= formatMoney($user['total_sales']) ?></strong></td>
                            <td>
                                <?php if ($user['blacklist_until'] && strtotime($user['blacklist_until']) > time()): ?>
                                    <span class="badge badge-danger">Blacklisted</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Active</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="report-footer">
            <p>This report was automatically generated by E-Kedai System</p>
            <p>© <?= date('Y') ?> E-Kedai - All Rights Reserved</p>
        </div>
    </div>

    <?php if ($includeCharts): ?>
    <script>
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.color = '#71717a';

        <?php if (isset($reportData['items']['categories']) && !empty($reportData['items']['categories'])): ?>
        // Category Distribution Chart
        new Chart(document.getElementById('categoryChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($reportData['items']['categories'], 'category')) ?>,
                datasets: [{
                    label: 'Item Count',
                    data: <?= json_encode(array_column($reportData['items']['categories'], 'count')) ?>,
                    backgroundColor: ['#2563eb', '#059669', '#7c3aed', '#d97706', '#dc2626', '#0891b2'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: {
                        display: true,
                        text: 'Item Distribution by Category',
                        font: { size: 14, weight: 600 }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#e4e4e7' } },
                    x: { grid: { display: false } }
                }
            }
        });
        <?php endif; ?>

        <?php if (isset($reportData['sales']['daily']) && !empty($reportData['sales']['daily'])): ?>
        // Daily Sales Chart
        new Chart(document.getElementById('salesChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('d M', strtotime($d['sale_date'])); }, $reportData['sales']['daily'])) ?>,
                datasets: [{
                    label: 'Sales Value (RM)',
                    data: <?= json_encode(array_column($reportData['sales']['daily'], 'total')) ?>,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#059669',
                    pointRadius: 4,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: {
                        display: true,
                        text: 'Daily Sales Trend',
                        font: { size: 14, weight: 600 }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#e4e4e7' } },
                    x: { grid: { display: false } }
                }
            }
        });
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
