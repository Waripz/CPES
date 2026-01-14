<?php
session_start();

// 1. Security
if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// 2. Database
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    require_once 'admin_functions.php';
    ensureActivityLogTable($pdo);
} catch (Exception $e) { die("Database Error"); }

// ===== GATHER ALL SYSTEM STATISTICS =====

// User Statistics
$stats['users_total'] = $pdo->query("SELECT COUNT(*) FROM users WHERE matricNo != 'ADMIN'")->fetchColumn();
$stats['users_active'] = $pdo->query("SELECT COUNT(*) FROM users WHERE matricNo != 'ADMIN' AND (blacklist_until IS NULL OR blacklist_until < NOW())")->fetchColumn();
$stats['users_blacklisted'] = $pdo->query("SELECT COUNT(*) FROM users WHERE blacklist_until IS NOT NULL AND blacklist_until > NOW()")->fetchColumn();
$stats['users_with_items'] = $pdo->query("SELECT COUNT(DISTINCT UserID) FROM item")->fetchColumn();
$stats['users_with_sales'] = $pdo->query("SELECT COUNT(DISTINCT UserID) FROM item WHERE status = 'sold'")->fetchColumn();

// Item Statistics
$stats['items_total'] = $pdo->query("SELECT COUNT(*) FROM item")->fetchColumn();
$stats['items_available'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'available'")->fetchColumn();
$stats['items_sold'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'sold'")->fetchColumn();
$stats['items_pending'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
$stats['items_rejected'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'rejected'")->fetchColumn();
$stats['items_blacklisted'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'blacklisted'")->fetchColumn();
$stats['items_events'] = $pdo->query("SELECT COUNT(*) FROM item WHERE category = 'Events'")->fetchColumn();
$stats['items_products'] = $pdo->query("SELECT COUNT(*) FROM item WHERE category != 'Events'")->fetchColumn();
$stats['items_new_today'] = $pdo->query("SELECT COUNT(*) FROM item WHERE DATE(postDate) = CURDATE()")->fetchColumn();
$stats['items_new_week'] = $pdo->query("SELECT COUNT(*) FROM item WHERE postDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

// Financial/Transaction Statistics - Use sold_price if available, fallback to price
$stats['total_value'] = $pdo->query("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold'")->fetchColumn();
$stats['avg_price'] = $pdo->query("SELECT COALESCE(AVG(price), 0) FROM item WHERE status IN ('available', 'sold')")->fetchColumn();
$stats['avg_sold_price'] = $pdo->query("SELECT COALESCE(AVG(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold'")->fetchColumn();
$stats['highest_price'] = $pdo->query("SELECT COALESCE(MAX(price), 0) FROM item WHERE status IN ('available', 'sold')")->fetchColumn();
$stats['highest_sold'] = $pdo->query("SELECT COALESCE(MAX(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold'")->fetchColumn();
$stats['sales_today'] = $pdo->query("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold' AND DATE(sold_date) = CURDATE()")->fetchColumn();
$stats['sales_week'] = $pdo->query("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold' AND sold_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

// Report Statistics
$stats['reports_total'] = $pdo->query("SELECT COUNT(*) FROM report")->fetchColumn() ?: 0;
$stats['reports_unopened'] = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn() ?: 0;

// Review Statistics
$stats['reviews_total'] = $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn() ?: 0;
$stats['reviews_avg_rating'] = $pdo->query("SELECT COALESCE(AVG(rating), 0) FROM reviews")->fetchColumn();

// Message Statistics
$stats['messages_total'] = $pdo->query("SELECT COUNT(*) FROM message")->fetchColumn() ?: 0;
$stats['messages_today'] = $pdo->query("SELECT COUNT(*) FROM message WHERE DATE(timestamp) = CURDATE()")->fetchColumn() ?: 0;

// Memo Statistics
$stats['memos_total'] = $pdo->query("SELECT COUNT(*) FROM memo")->fetchColumn() ?: 0;

// Category Distribution
$categoryData = $pdo->query("SELECT category, COUNT(*) as count FROM item WHERE category IS NOT NULL GROUP BY category ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

// Weekly Item Trend (Last 7 days)
$weeklyItems = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE DATE(postDate) = ?");
    $stmt->execute([$date]);
    $weeklyItems[] = [
        'date' => date('D', strtotime($date)),
        'full_date' => $date,
        'count' => (int)$stmt->fetchColumn()
    ];
}

// Weekly Sales Trend (using sold_date for more accurate data)
$weeklySales = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    // Count items sold on this date
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE status = 'sold' AND (DATE(sold_date) = ? OR (sold_date IS NULL AND DATE(last_updated) = ?))");
    $stmt->execute([$date, $date]);
    $soldCount = (int)$stmt->fetchColumn();
    
    // Get total sales value for this date
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(sold_price, price)), 0) FROM item WHERE status = 'sold' AND (DATE(sold_date) = ? OR (sold_date IS NULL AND DATE(last_updated) = ?))");
    $stmt2->execute([$date, $date]);
    $salesValue = (float)$stmt2->fetchColumn();
    
    $weeklySales[] = [
        'date' => date('D', strtotime($date)),
        'count' => $soldCount,
        'value' => $salesValue
    ];
}

// Recent Items (Latest 5)
$recentItems = $pdo->query("SELECT i.ItemID, i.title, i.price, i.status, i.postDate, i.image, u.name as seller_name 
                            FROM item i JOIN users u ON i.UserID = u.UserID 
                            ORDER BY i.postDate DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Recent Reports (Latest 5)
$recentReports = $pdo->query("SELECT r.*, COALESCE(r.report_type, 'other') as report_type, u.name as reporter_name 
                              FROM report r LEFT JOIN users u ON r.UserID = u.UserID 
                              ORDER BY r.submitDate DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Recent Activity Log (Latest 5)
$recentActivity = $pdo->query("SELECT l.*, a.username as admin_name,
                                      i.title as item_title,
                                      u.name as target_user_name, u.matricNo as target_user_matric,
                                      m_memo.subject as memo_subject
                               FROM activity_log l 
                               LEFT JOIN admin a ON l.actor_admin_id = a.AdminID 
                               LEFT JOIN item i ON l.entity_type = 'item' AND l.entity_id = i.ItemID
                               LEFT JOIN users u ON l.entity_type = 'user' AND l.entity_id = u.UserID
                               LEFT JOIN memo m_memo ON l.entity_type = 'memo' AND l.entity_id = m_memo.MemoID
                               ORDER BY l.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Top Sellers (by items sold and total sales value)
$topSellers = $pdo->query("SELECT u.UserID, u.name, u.profile_image,
                           COUNT(CASE WHEN i.status = 'sold' THEN 1 END) as sold_count,
                           COUNT(i.ItemID) as total_items,
                           COALESCE(SUM(CASE WHEN i.status = 'sold' THEN COALESCE(i.sold_price, i.price) END), 0) as total_sales
                           FROM users u 
                           LEFT JOIN item i ON u.UserID = i.UserID
                           WHERE u.matricNo != 'ADMIN'
                           GROUP BY u.UserID
                           HAVING sold_count > 0
                           ORDER BY total_sales DESC
                           LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
function formatMoney($v) { return 'RM ' . number_format((float)$v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="admin.png">
    <link rel="stylesheet" href="admin_style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .mini-stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .mini-stat {
            padding: 14px 16px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mini-stat-label {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .mini-stat-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .mini-stat-value.success { color: var(--accent-green); }
        .mini-stat-value.warning { color: var(--accent-amber); }
        .mini-stat-value.danger { color: var(--accent-red); }
        .mini-stat-value.info { color: var(--accent-blue); }

        .progress-item {
            margin-bottom: 16px;
        }
        .progress-item:last-child {
            margin-bottom: 0;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .progress-label {
            color: var(--text-secondary);
        }
        .progress-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-subtle);
            transition: background var(--transition-fast);
        }
        .recent-item:last-child {
            border-bottom: none;
        }
        .recent-item:hover {
            background: var(--bg-light);
        }
        .recent-img {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            object-fit: cover;
            background: var(--bg-light);
        }
        .recent-info {
            flex: 1;
            min-width: 0;
        }
        .recent-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .recent-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .seller-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-subtle);
        }
        .seller-item:last-child {
            border-bottom: none;
        }
        .seller-rank {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
        }
        .seller-item:nth-child(1) .seller-rank {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #78350f;
        }
        .seller-item:nth-child(2) .seller-rank {
            background: linear-gradient(135deg, #d1d5db, #9ca3af);
            color: #374151;
        }
        .seller-item:nth-child(3) .seller-rank {
            background: linear-gradient(135deg, #fcd6a4, #f59e0b);
            color: #78350f;
        }
        .seller-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--bg-light);
        }
        .letter-avatar-seller-dash {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            color: var(--text-muted);
        }
        .seller-info {
            flex: 1;
        }
        .seller-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .seller-stats {
            font-size: 12px;
            color: var(--text-muted);
        }

        .system-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .system-stat {
            text-align: center;
            padding: 16px;
            background: var(--bg-light);
            border-radius: var(--radius-md);
        }
        .system-stat-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .system-stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .chart-container {
            height: 260px;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="admin_dashboard.php" class="sidebar-logo">
                    <div class="sidebar-logo-icon">EK</div>
                    <span class="sidebar-logo-text">E-Kedai</span>
                    <span class="sidebar-logo-badge">Admin</span>
                </a>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="admin_dashboard.php" class="nav-link active">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        Dashboard
                    </a>
                    <a href="admin_items.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        Manage Items
                        <span class="nav-badge" id="nav-badge-items" style="<?= $stats['items_pending'] > 0 ? '' : 'display:none' ?>"><?= $stats['items_pending'] ?></span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="admin_users.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Users
                    </a>
                    <a href="admin_reports.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                        Reports
                        <span class="nav-badge" id="nav-badge-reports" style="<?= $stats['reports_unopened'] > 0 ? '' : 'display:none' ?>"><?= $stats['reports_unopened'] ?></span>
                    </a>
                    <a href="admin_memo.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>
                        Memos
                    </a>
                    <a href="admin_activity_log.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                        Activity Log
                    </a>
                </div>
            </nav>
            
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['admin_name'], 0, 1)) ?></div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name"><?= h($_SESSION['admin_name']) ?></div>
                        <div class="sidebar-user-role">Administrator</div>
                    </div>
                </div>
                <a href="admin_logout.php" class="logout-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                    Sign Out
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Dashboard</h1>
                        <p class="page-subtitle">Overview of system statistics and recent activity</p>
                    </div>
                    <div class="page-actions" style="display: flex; align-items: center; gap: 16px;">
                        <div class="live-indicator">
                            <span class="live-dot"></span>
                            <span>LIVE</span>
                        </div>
                        <span class="text-muted text-sm" id="last-update-time"><?= date('l, d M Y') ?></span>
                    </div>
                </div>

                <!-- Alerts -->
                <div class="alert alert-warning mb-5" id="alert-pending" style="<?= $stats['items_pending'] > 0 ? '' : 'display:none' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <strong><?= $stats['items_pending'] ?></strong> item(s) pending review. <a href="admin_items.php" style="font-weight: 600;">Review now →</a>
                </div>

                <div class="alert alert-error mb-5" id="alert-reports" style="<?= $stats['reports_unopened'] > 0 ? '' : 'display:none' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                    <strong><?= $stats['reports_unopened'] ?></strong> new report(s) require attention. <a href="admin_reports.php" style="font-weight: 600;">View reports →</a>
                </div>

                <!-- Report Generator Section -->
                <div class="card mb-6 report-generator-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <svg class="card-title-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            System Report Generator
                        </h3>
                        <button type="button" class="btn btn-primary" id="openReportBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Generate Report
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="report-quick-options">
                            <div class="report-option" data-period="today">
                                <div class="report-option-icon blue">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </div>
                                <div class="report-option-text">
                                    <span class="report-option-title">Today</span>
                                    <span class="report-option-desc"><?= date('d M Y') ?></span>
                                </div>
                            </div>
                            <div class="report-option" data-period="week">
                                <div class="report-option-icon green">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </div>
                                <div class="report-option-text">
                                    <span class="report-option-title">Last 7 Days</span>
                                    <span class="report-option-desc"><?= date('d M', strtotime('-7 days')) ?> - <?= date('d M Y') ?></span>
                                </div>
                            </div>
                            <div class="report-option" data-period="month">
                                <div class="report-option-icon violet">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </div>
                                <div class="report-option-text">
                                    <span class="report-option-title">Last 30 Days</span>
                                    <span class="report-option-desc"><?= date('d M', strtotime('-30 days')) ?> - <?= date('d M Y') ?></span>
                                </div>
                            </div>
                            <div class="report-option" data-period="custom">
                                <div class="report-option-icon amber">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                </div>
                                <div class="report-option-text">
                                    <span class="report-option-title">Custom Period</span>
                                    <span class="report-option-desc">Select your own date range</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="stats-row mb-6">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value" id="stat-users-total"><?= number_format($stats['users_total']) ?></div>
                        <div class="stat-card-label">Total Users</div>
                        <div class="stat-card-change positive">+<span id="stat-users-active"><?= $stats['users_active'] ?></span> active</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value" id="stat-items-total"><?= number_format($stats['items_total']) ?></div>
                        <div class="stat-card-label">Total Items</div>
                        <div class="stat-card-change positive">+<span id="stat-items-week"><?= $stats['items_new_week'] ?></span> this week</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon violet">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value" id="stat-sales-value"><?= formatMoney($stats['total_value']) ?></div>
                        <div class="stat-card-label">Sales Value</div>
                        <div class="stat-card-change positive"><span id="stat-items-sold"><?= $stats['items_sold'] ?></span> items sold</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon amber">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value" id="stat-reports-total"><?= number_format($stats['reports_total']) ?></div>
                        <div class="stat-card-label">Reports</div>
                        <div class="stat-card-change <?= $stats['reports_unopened'] > 0 ? 'negative' : '' ?>"><span id="stat-reports-unopened"><?= $stats['reports_unopened'] ?></span> unopened</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid-2 mb-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <svg class="card-title-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>
                                Weekly Activity
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="weeklyChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <svg class="card-title-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                                Item Status
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Row -->
                <div class="grid-3 mb-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">User Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="mini-stat-grid">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Total</span>
                                    <span class="mini-stat-value info"><?= number_format($stats['users_total']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Active</span>
                                    <span class="mini-stat-value success"><?= number_format($stats['users_active']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Blacklisted</span>
                                    <span class="mini-stat-value danger"><?= number_format($stats['users_blacklisted']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">With Sales</span>
                                    <span class="mini-stat-value"><?= number_format($stats['users_with_sales']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Item Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="mini-stat-grid">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Available</span>
                                    <span class="mini-stat-value success"><?= number_format($stats['items_available']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Sold</span>
                                    <span class="mini-stat-value info"><?= number_format($stats['items_sold']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Pending</span>
                                    <span class="mini-stat-value warning"><?= number_format($stats['items_pending']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Events</span>
                                    <span class="mini-stat-value"><?= number_format($stats['items_events']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Marketplace Sales</h3>
                        </div>
                        <div class="card-body">
                            <div class="mini-stat-grid">
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Total Sales</span>
                                    <span class="mini-stat-value success"><?= formatMoney($stats['total_value']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">This Week</span>
                                    <span class="mini-stat-value success"><?= formatMoney($stats['sales_week']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Avg Sold Price</span>
                                    <span class="mini-stat-value"><?= formatMoney($stats['avg_sold_price']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Highest Sold</span>
                                    <span class="mini-stat-value info"><?= formatMoney($stats['highest_sold']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Today's Sales</span>
                                    <span class="mini-stat-value warning"><?= formatMoney($stats['sales_today']) ?></span>
                                </div>
                                <div class="mini-stat">
                                    <span class="mini-stat-label">Reviews</span>
                                    <span class="mini-stat-value"><?= number_format($stats['reviews_total']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Item Status Breakdown -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h3 class="card-title">Item Status Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        $totalItems = $stats['items_total'] ?: 1;
                        $statuses = [
                            ['label' => 'Available', 'count' => $stats['items_available'], 'color' => 'green'],
                            ['label' => 'Sold', 'count' => $stats['items_sold'], 'color' => 'blue'],
                            ['label' => 'Pending Review', 'count' => $stats['items_pending'], 'color' => 'amber'],
                            ['label' => 'Rejected', 'count' => $stats['items_rejected'], 'color' => 'violet'],
                            ['label' => 'Blacklisted', 'count' => $stats['items_blacklisted'], 'color' => 'red'],
                        ];
                        foreach ($statuses as $s):
                            $percent = round(($s['count'] / $totalItems) * 100, 1);
                        ?>
                        <div class="progress-item">
                            <div class="progress-header">
                                <span class="progress-label"><?= $s['label'] ?></span>
                                <span class="progress-value"><?= number_format($s['count']) ?> (<?= $percent ?>%)</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill <?= $s['color'] ?>" style="width: <?= $percent ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Items & Top Sellers -->
                <div class="grid-2 mb-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Recent Lists</h3>
                            <a href="admin_items.php" class="btn btn-sm btn-secondary">View All</a>
                        </div>
                        <div class="card-body no-padding">
                            <?php if (empty($recentItems)): ?>
                                <div class="empty-state">
                                    <p class="empty-state-text">No items yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentItems as $item): 
                                    $img = !empty($item['image']) ? explode(',', $item['image'])[0] : 'avatar.png';
                                    $badgeClass = 'badge-neutral';
                                    if ($item['status'] === 'available') $badgeClass = 'badge-success';
                                    elseif ($item['status'] === 'sold') $badgeClass = 'badge-info';
                                    elseif ($item['status'] === 'under_review') $badgeClass = 'badge-warning';
                                    elseif ($item['status'] === 'rejected') $badgeClass = 'badge-violet';
                                ?>
                                <div class="recent-item">
                                    <img src="<?= h($img) ?>" class="recent-img" alt="">
                                    <div class="recent-info">
                                        <div class="recent-title"><?= h($item['title']) ?></div>
                                        <div class="recent-meta"><?= h($item['seller_name']) ?> · <?= formatMoney($item['price']) ?></div>
                                    </div>
                                    <span class="badge <?= $badgeClass ?>"><?= ucfirst(str_replace('_', ' ', $item['status'])) ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Top Sellers</h3>
                        </div>
                        <div class="card-body">
                            <?php if (empty($topSellers)): ?>
                                <div class="empty-state">
                                    <p class="empty-state-text">No sales data yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($topSellers as $i => $seller): 
                                    $hasSellerAvatar = !empty($seller['profile_image']) && file_exists($seller['profile_image']);
                                    $sellerLetter = strtoupper(substr($seller['name'] ?? 'U', 0, 1));
                                ?>
                                <div class="seller-item">
                                    <div class="seller-rank"><?= $i + 1 ?></div>
                                    <?php if ($hasSellerAvatar): ?>
                                        <img src="<?= h($seller['profile_image']) ?>" class="seller-avatar" alt="">
                                    <?php else: ?>
                                        <div class="letter-avatar-seller-dash"><?= $sellerLetter ?></div>
                                    <?php endif; ?>
                                    <div class="seller-info">
                                        <div class="seller-name"><?= h($seller['name']) ?></div>
                                        <div class="seller-stats"><?= $seller['sold_count'] ?> sold · <?= formatMoney($seller['total_sales']) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Category & Reports -->
                <div class="grid-2 mb-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Category Distribution</h3>
                        </div>
                        <div class="card-body">
                            <?php 
                            $colors = ['green', 'blue', 'violet', 'amber', 'red'];
                            foreach ($categoryData as $i => $cat): 
                                $percent = round(($cat['count'] / $totalItems) * 100, 1);
                                $color = $colors[$i % count($colors)];
                            ?>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span class="progress-label"><?= h($cat['category']) ?></span>
                                    <span class="progress-value"><?= number_format($cat['count']) ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-bar-fill <?= $color ?>" style="width: <?= $percent ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Recent Reports</h3>
                            <a href="admin_reports.php" class="btn btn-sm btn-secondary">View All</a>
                        </div>
                        <div class="card-body no-padding">
                            <?php if (empty($recentReports)): ?>
                                <div class="empty-state">
                                    <p class="empty-state-text">No reports yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentReports as $report): 
                                    // Format report type for display
                                    $reportType = $report['report_type'] ?? 'other';
                                    if (empty($reportType)) $reportType = 'other';
                                    
                                    // Map to display labels
                                    $labelMap = [
                                        'scam_fraud' => 'Scam/Fraud',
                                        'user_report' => 'User Report',
                                        'item_report' => 'Item Report',
                                        'help_support' => 'Help/Support',
                                        'spam' => 'Spam',
                                        'inappropriate' => 'Inappropriate',
                                        'account_issue' => 'Account Issue',
                                        'other' => 'Other'
                                    ];
                                    $displayLabel = $labelMap[$reportType] ?? ucwords(str_replace('_', ' ', $reportType));
                                    
                                    // Map to icon background color
                                    $iconColors = [
                                        'scam_fraud' => 'var(--red-subtle)',
                                        'help_support' => 'var(--amber-subtle)',
                                        'user_report' => 'var(--blue-subtle)',
                                        'item_report' => 'var(--violet-subtle)',
                                        'spam' => '#fce7f3',
                                        'inappropriate' => 'var(--violet-subtle)',
                                        'account_issue' => 'var(--blue-subtle)',
                                        'other' => 'var(--bg-light)'
                                    ];
                                    $iconColor = $iconColors[$reportType] ?? 'var(--bg-light)';
                                ?>
                                <div class="recent-item">
                                    <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: <?= $iconColor ?>; display: flex; align-items: center; justify-content: center;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent-red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                                    </div>
                                    <div class="recent-info">
                                        <div class="recent-title"><?= h($displayLabel) ?></div>
                                        <div class="recent-meta"><?= h($report['reporter_name'] ?? 'Anonymous') ?> · <?= date('d M', strtotime($report['submitDate'])) ?></div>
                                    </div>
                                    <span class="badge <?= ($report['is_opened'] ?? 0) ? 'badge-success' : 'badge-warning' ?>">
                                        <?= ($report['is_opened'] ?? 0) ? 'Reviewed' : 'New' ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Log -->
                <div class="card mb-6">
                    <div class="card-header">
                        <div class="card-title">Recent Admin Activity</div>
                        <a href="admin_activity_log.php" class="btn btn-outline btn-sm">View All</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recentActivity)): ?>
                            <div class="empty-state">
                                <p class="empty-state-text">No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $log): 
                                $action = $log['action'];
                                $entityType = $log['entity_type'];
                                $adminName = $log['admin_name'] ?? 'System';
                                $details = !empty($log['details']) ? json_decode($log['details'], true) : [];
                                
                                // Enhanced Display Name logic
                                $targetName = '';
                                if ($entityType === 'item' && !empty($log['item_title'])) {
                                    $targetName = h($log['item_title']);
                                } elseif ($entityType === 'user' && !empty($log['target_user_name'])) {
                                    $targetName = h($log['target_user_name']);
                                } elseif ($entityType === 'memo' && !empty($log['memo_subject'])) {
                                    $targetName = h($log['memo_subject']);
                                }

                                // Formatting
                                $iconColor = 'var(--bg-light)';
                                $iconSvg = '';
                                $displayText = '';

                                switch ($entityType) {
                                    case 'user':
                                        $iconColor = 'var(--blue-subtle)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
                                        if ($action == 'blacklist') $displayText = "Blacklisted user " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        elseif ($action == 'unblacklist') $displayText = "Unblacklisted user " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        else $displayText = "Updated user " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        break;
                                    case 'item':
                                        $iconColor = 'var(--green-subtle)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>';
                                        if ($action == 'approve') $displayText = "Approved item " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        elseif ($action == 'reject') $displayText = "Rejected item " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        elseif ($action == 'restore') $displayText = "Restored item " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        else $displayText = "Updated item " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        
                                        // Change color for rejection
                                        if ($action == 'reject') {
                                            $iconColor = 'var(--red-subtle)';
                                            $iconSvg = str_replace('var(--accent-green)', 'var(--accent-red)', $iconSvg);
                                        }
                                        break;
                                    case 'report':
                                        $iconColor = 'var(--amber-subtle)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>';
                                        $displayText = "Processed report"; 
                                        break;
                                    case 'memo':
                                        $iconColor = 'var(--violet-subtle)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-violet)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>';
                                        if ($action == 'create_memo') $displayText = "Created memo " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        elseif ($action == 'delete_memo') $displayText = "Deleted memo " . ($targetName ? "<strong>$targetName</strong>" : "");
                                        break;
                                    case 'message':
                                        $iconColor = 'var(--blue-subtle)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                                        $displayText = "Sent a direct message";
                                        break;
                                    default:
                                        $iconColor = 'var(--bg-light)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
                                        $displayText = ucfirst(str_replace('_', ' ', $action));
                                }
                            ?>
                            <div class="activity-item">
                                <div style="background: <?= $iconColor ?>; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <?= $iconSvg ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <?= $displayText ?> <span class="text-muted">by <?= h($adminName) ?></span>
                                    </div>
                                    <div class="text-xs text-muted">
                                        <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Info -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">System Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="system-grid">
                            <div class="system-stat">
                                <div class="system-stat-value"><?= number_format($stats['messages_total']) ?></div>
                                <div class="system-stat-label">Total Messages</div>
                            </div>
                            <div class="system-stat">
                                <div class="system-stat-value"><?= number_format($stats['messages_today']) ?></div>
                                <div class="system-stat-label">Messages Today</div>
                            </div>
                            <div class="system-stat">
                                <div class="system-stat-value"><?= number_format($stats['memos_total']) ?></div>
                                <div class="system-stat-label">Total Memos</div>
                            </div>
                            <div class="system-stat">
                                <div class="system-stat-value"><?= number_format($stats['reviews_avg_rating'], 1) ?></div>
                                <div class="system-stat-label">Avg. Rating</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="modal-overlay">
        <div class="modal-container report-modal">
            <div class="modal-header">
                <h3 class="modal-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    Generate System Report
                </h3>
                <button type="button" class="modal-close" id="closeModalBtn">&times;</button>
            </div>
            <div class="modal-body">
                <form id="reportForm">
                    <div class="form-group">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" id="reportType" class="form-select" required>
                            <option value="complete">Complete Report (All Data)</option>
                            <option value="users">Users Report</option>
                            <option value="items">Items/Products Report</option>
                            <option value="sales">Sales Report</option>
                            <option value="reports">Complaints Report</option>
                            <option value="activity">Activity Report</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Report Format</label>
                        <div class="format-options">
                            <label class="format-option">
                                <input type="radio" name="format" value="html" checked>
                                <span class="format-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </span>
                                <span>HTML</span>
                            </label>
                            <label class="format-option">
                                <input type="radio" name="format" value="csv">
                                <span class="format-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                                </span>
                                <span>CSV</span>
                            </label>
                            <label class="format-option">
                                <input type="radio" name="format" value="print">
                                <span class="format-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                </span>
                                <span>Print</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Options</label>
                        <div class="checkbox-group">
                            <label class="checkbox-option">
                                <input type="checkbox" name="include_charts" value="1" checked>
                                <span>Include charts & graphs</span>
                            </label>
                            <label class="checkbox-option">
                                <input type="checkbox" name="include_summary" value="1" checked>
                                <span>Include statistics summary</span>
                            </label>
                            <label class="checkbox-option">
                                <input type="checkbox" name="include_details" value="1">
                                <span>Include detailed list</span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelModalBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="generateReportBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Generate Report
                </button>
            </div>
        </div>
    </div>

    <style>
        /* Report Generator Styles */
        .report-generator-card {
            background: linear-gradient(135deg, var(--card-bg) 0%, #f8fafc 100%);
            border: 1px solid var(--accent-blue);
        }
        .report-quick-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .report-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .report-option:hover {
            border-color: var(--accent-blue);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        .report-option-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .report-option-icon.blue {
            background: var(--blue-subtle);
            color: var(--accent-blue);
        }
        .report-option-icon.green {
            background: var(--green-subtle);
            color: var(--accent-green);
        }
        .report-option-icon.violet {
            background: var(--violet-subtle);
            color: var(--accent-violet);
        }
        .report-option-icon.amber {
            background: var(--amber-subtle);
            color: var(--accent-amber);
        }
        .report-option-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .report-option-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
        }
        .report-option-desc {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-container {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow: hidden;
            animation: modalSlideIn 0.2s ease;
        }
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--bg-light);
            border-radius: var(--radius-md);
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
            transition: all var(--transition-fast);
        }
        .modal-close:hover {
            background: var(--bg-page);
            color: var(--text-primary);
        }
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(90vh - 140px);
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-light);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--card-bg);
            color: var(--text-primary);
            transition: all var(--transition-fast);
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        /* Format Options */
        .format-options {
            display: flex;
            gap: 12px;
        }
        .format-option {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .format-option:has(input:checked) {
            border-color: var(--accent-blue);
            background: var(--blue-subtle);
        }
        .format-option input {
            display: none;
        }
        .format-option .format-icon {
            color: var(--text-muted);
        }
        .format-option:has(input:checked) .format-icon {
            color: var(--accent-blue);
        }
        .format-option span:last-child {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        
        /* Checkbox Options */
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .checkbox-option {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        .checkbox-option input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-blue);
        }
        .checkbox-option span {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        @media (max-width: 768px) {
            .report-quick-options {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .format-options {
                flex-direction: column;
            }
        }
    </style>

    <script>
        // Chart.js defaults
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.color = '#71717a';

        // Weekly Activity Chart
        const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
        new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($weeklyItems, 'date')) ?>,
                datasets: [{
                    label: 'New Items',
                    data: <?= json_encode(array_column($weeklyItems, 'count')) ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#2563eb',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2
                }, {
                    label: 'Items Sold',
                    data: <?= json_encode(array_column($weeklySales, 'count')) ?>,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.08)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#059669',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { size: 12, weight: 500 }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                        grid: { color: '#f4f4f5' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Available', 'Sold', 'Pending', 'Rejected', 'Blacklisted'],
                datasets: [{
                    data: [
                        <?= $stats['items_available'] ?>,
                        <?= $stats['items_sold'] ?>,
                        <?= $stats['items_pending'] ?>,
                        <?= $stats['items_rejected'] ?>,
                        <?= $stats['items_blacklisted'] ?>
                    ],
                    backgroundColor: ['#059669', '#2563eb', '#d97706', '#7c3aed', '#dc2626'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 16,
                            font: { size: 12, weight: 500 }
                        }
                    }
                },
                cutout: '70%'
            }
        });

        // ========== REPORT GENERATOR FUNCTIONS ==========
        document.addEventListener('DOMContentLoaded', function() {
            const reportModal = document.getElementById('reportModal');
            const openReportBtn = document.getElementById('openReportBtn');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const cancelModalBtn = document.getElementById('cancelModalBtn');
            const generateReportBtn = document.getElementById('generateReportBtn');
            const reportOptions = document.querySelectorAll('.report-option');
            
            // Open modal function
            function openModal() {
                reportModal.classList.add('active');
                // Set default dates
                const today = new Date().toISOString().split('T')[0];
                const weekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                document.getElementById('startDate').value = weekAgo;
                document.getElementById('endDate').value = today;
            }
            
            // Close modal function
            function closeModal() {
                reportModal.classList.remove('active');
            }
            
            // Generate quick report
            function generateQuickReport(period) {
                const today = new Date().toISOString().split('T')[0];
                let startDate;
                
                switch(period) {
                    case 'today':
                        startDate = today;
                        break;
                    case 'week':
                        startDate = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                        break;
                    case 'month':
                        startDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                        break;
                    default:
                        startDate = today;
                }
                
                const params = new URLSearchParams({
                    type: 'complete',
                    start: startDate,
                    end: today,
                    format: 'html',
                    charts: 1,
                    summary: 1,
                    details: 0
                });
                
                window.open('generate_report.php?' + params.toString(), '_blank');
            }
            
            // Generate report from form
            function generateReport() {
                const form = document.getElementById('reportForm');
                const formData = new FormData(form);
                
                const params = new URLSearchParams({
                    type: formData.get('report_type'),
                    start: formData.get('start_date'),
                    end: formData.get('end_date'),
                    format: formData.get('format'),
                    charts: formData.get('include_charts') ? 1 : 0,
                    summary: formData.get('include_summary') ? 1 : 0,
                    details: formData.get('include_details') ? 1 : 0
                });
                
                if (formData.get('format') === 'print') {
                    const printWindow = window.open('generate_report.php?' + params.toString(), '_blank');
                    if (printWindow) {
                        printWindow.onload = function() {
                            printWindow.print();
                        };
                    }
                } else {
                    window.open('generate_report.php?' + params.toString(), '_blank');
                }
                
                closeModal();
            }
            
            // Event listeners
            if (openReportBtn) {
                openReportBtn.addEventListener('click', openModal);
            }
            
            if (closeModalBtn) {
                closeModalBtn.addEventListener('click', closeModal);
            }
            
            if (cancelModalBtn) {
                cancelModalBtn.addEventListener('click', closeModal);
            }
            
            if (generateReportBtn) {
                generateReportBtn.addEventListener('click', generateReport);
            }
            
            // Report option clicks
            reportOptions.forEach(function(option) {
                option.addEventListener('click', function() {
                    const period = this.getAttribute('data-period');
                    if (period === 'custom') {
                        openModal();
                    } else {
                        generateQuickReport(period);
                    }
                });
            });
            
            // Close modal on outside click
            if (reportModal) {
                reportModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            }
            
            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && reportModal.classList.contains('active')) {
                    closeModal();
                }
            });
        });
    </script>

    <!-- Real-time Updates Script -->
    <script>
    (function() {
        // Configuration
        const POLL_INTERVAL = 10000; // Poll every 10 seconds
        const API_ENDPOINT = 'api_admin_stats.php';
        
        // Store previous values to detect changes
        let previousStats = {
            items_pending: <?= $stats['items_pending'] ?>,
            reports_unopened: <?= $stats['reports_unopened'] ?>
        };
        
        // Format money helper
        function formatMoney(value) {
            return 'RM ' + parseFloat(value).toLocaleString('en-MY', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Update function
        async function fetchRealTimeStats() {
            try {
                const response = await fetch(API_ENDPOINT + '?t=' + Date.now());
                const data = await response.json();
                
                if (!data.success || !data.stats) return;
                
                const stats = data.stats;
                
                // Check for changes and show notifications
                if (stats.items_pending > previousStats.items_pending) {
                    showNotification('New item pending review!', 'warning');
                }
                if (stats.reports_unopened > previousStats.reports_unopened) {
                    showNotification('New report received!', 'error');
                }
                
                // Update sidebar badges
                updateBadge('nav-badge-items', stats.items_pending);
                updateBadge('nav-badge-reports', stats.reports_unopened);
                
                // Update alert boxes
                updateAlert('alert-pending', stats.items_pending, 'item(s) pending review');
                updateAlert('alert-reports', stats.reports_unopened, 'new report(s) require attention');
                
                // Update stat cards
                updateStatValue('stat-users-total', stats.users_total);
                updateStatValue('stat-items-total', stats.items_total);
                updateStatValue('stat-reports-total', stats.reports_total);
                updateStatValue('stat-items-pending', stats.items_pending);
                updateStatValue('stat-items-available', stats.items_available);
                updateStatValue('stat-items-sold', stats.items_sold);
                updateStatValue('stat-items-week', stats.items_new_week);
                updateStatValue('stat-reports-unopened', stats.reports_unopened);
                
                // Update money values
                updateMoneyValue('stat-sales-value', stats.total_value);
                updateMoneyValue('stat-sales-week', stats.sales_week);
                
                // Store current values
                previousStats = {
                    items_pending: stats.items_pending,
                    reports_unopened: stats.reports_unopened
                };
                
                // Update last refresh time
                const lastUpdate = document.getElementById('last-update-time');
                if (lastUpdate) {
                    lastUpdate.textContent = 'Updated: ' + new Date().toLocaleTimeString();
                }
                
            } catch (error) {
                console.error('Real-time update error:', error);
            }
        }
        
        // Update badge helper
        function updateBadge(id, count) {
            const badge = document.getElementById(id);
            if (!badge) return;
            
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-flex';
                // Pulse animation on change
                badge.classList.add('pulse-update');
                setTimeout(() => badge.classList.remove('pulse-update'), 1000);
            } else {
                badge.style.display = 'none';
            }
        }
        
        // Update alert helper
        function updateAlert(id, count, message) {
            const alert = document.getElementById(id);
            if (!alert) return;
            
            if (count > 0) {
                alert.style.display = 'flex';
                const strong = alert.querySelector('strong');
                if (strong) strong.textContent = count;
            } else {
                alert.style.display = 'none';
            }
        }
        
        // Update stat value helper
        function updateStatValue(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            
            const newValue = Number(value).toLocaleString();
            if (el.textContent !== newValue) {
                el.textContent = newValue;
                el.classList.add('value-updated');
                setTimeout(() => el.classList.remove('value-updated'), 500);
            }
        }
        
        // Update money value helper
        function updateMoneyValue(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            
            const newValue = formatMoney(value);
            if (el.textContent !== newValue) {
                el.textContent = newValue;
                el.classList.add('value-updated');
                setTimeout(() => el.classList.remove('value-updated'), 500);
            }
        }
        
        // Show notification
        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'realtime-notification ' + type;
            notification.innerHTML = `
                <span class="notification-dot"></span>
                <span class="notification-text">${message}</span>
            `;
            
            // Add to page
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => notification.classList.add('show'), 10);
            
            // Remove after 4 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 4000);
            
            // Play notification sound (optional)
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2teleVVKYH5/');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch(e) {}
        }
        
        // Start polling
        setInterval(fetchRealTimeStats, POLL_INTERVAL);
        
        // Initial fetch after page load
        setTimeout(fetchRealTimeStats, 2000);
    })();
    </script>
    
    <!-- Real-time update styles -->
    <style>
        /* Pulse animation for badges */
        @keyframes pulseUpdate {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .pulse-update {
            animation: pulseUpdate 0.3s ease;
        }
        
        /* Value update flash */
        @keyframes valueFlash {
            0% { background-color: rgba(16, 185, 129, 0.3); }
            100% { background-color: transparent; }
        }
        .value-updated {
            animation: valueFlash 0.5s ease;
            border-radius: 4px;
        }
        
        /* Real-time notification toast */
        .realtime-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1a1a2e;
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            transform: translateX(120%);
            transition: transform 0.3s ease;
            z-index: 9999;
        }
        .realtime-notification.show {
            transform: translateX(0);
        }
        .realtime-notification.warning {
            border-left: 4px solid #f59e0b;
        }
        .realtime-notification.error {
            border-left: 4px solid #ef4444;
        }
        .notification-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 1.5s infinite;
        }
        .realtime-notification.warning .notification-dot {
            background: #f59e0b;
        }
        .realtime-notification.error .notification-dot {
            background: #ef4444;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .notification-text {
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Live indicator */
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            margin-left: auto;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
    </style>
</body>
</html>
