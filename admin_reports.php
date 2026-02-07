<?php
require_once 'config.php';
adminSecureSessionStart();

if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') { 
    header('Location: admin_login.php'); 
    exit; 
}

$pdo = getDBConnection();
require_once 'admin_functions.php';
ensureActivityLogTable($pdo);

// Add columns if they don't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'is_checked'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE report ADD COLUMN is_checked TINYINT(1) DEFAULT 0");
    }
} catch (PDOException $e) { }

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'is_opened'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE report ADD COLUMN is_opened TINYINT(1) DEFAULT 0");
    }
} catch (PDOException $e) { }

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'admin_notes'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE report ADD COLUMN admin_notes TEXT NULL");
    }
} catch (PDOException $e) { }

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'mark_opened') {
        $reportId = (int)$_POST['report_id'];
        $stmt = $pdo->prepare("UPDATE report SET is_opened = 1, is_checked = 1 WHERE ReportID = ?");
        $stmt->execute([$reportId]);
        logActivity($pdo, $_SESSION['AdminID'], 'report', $reportId, 'mark_read');
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] === 'save_notes') {
        $reportId = (int)$_POST['report_id'];
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $pdo->prepare("UPDATE report SET admin_notes = ? WHERE ReportID = ?");
        $stmt->execute([$notes, $reportId]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] === 'delete_report') {
        $reportId = (int)$_POST['report_id'];
        $stmt = $pdo->prepare("DELETE FROM report WHERE ReportID = ?");
        $stmt->execute([$reportId]);
        logActivity($pdo, $_SESSION['AdminID'], 'report', $reportId, 'delete');
        echo json_encode(['success' => true]);
        exit;
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Report Statistics
$stats['total'] = $pdo->query("SELECT COUNT(*) FROM report")->fetchColumn();
$stats['unopened'] = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();
$stats['checked'] = $pdo->query("SELECT COUNT(*) FROM report WHERE is_checked = 1")->fetchColumn();

// For sidebar badge
$stats['items_pending'] = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();

// Build query for reports
$sql = "SELECT r.ReportID, r.UserID, r.reportedUserID, r.reportedItemID, r.reason, r.submitDate,
               COALESCE(r.is_checked, 0) as is_checked, 
               COALESCE(r.is_opened, 0) as is_opened,
               r.admin_notes,
               COALESCE(r.report_type, 'other') as report_type,
               u1.name AS reporter_name, u1.matricNo AS reporter_matric, u1.profile_image AS reporter_image,
               i.ItemID as item_id, i.title AS item_title, i.price AS item_price, i.image AS item_image, i.status AS item_status,
               u2.UserID as reported_user_id, u2.name AS reported_user_name, u2.matricNo AS reported_user_matric, u2.profile_image AS reported_user_image
        FROM report r
        LEFT JOIN users u1 ON r.UserID = u1.UserID
        LEFT JOIN item i ON r.reportedItemID = i.ItemID
        LEFT JOIN users u2 ON r.reportedUserID = u2.UserID
        WHERE 1=1";

$params = [];

if ($statusFilter === 'unopened') {
    $sql .= " AND (r.is_opened = 0 OR r.is_opened IS NULL)";
} elseif ($statusFilter === 'checked') {
    $sql .= " AND r.is_checked = 1";
}

if ($typeFilter === 'item') {
    $sql .= " AND r.reportedItemID IS NOT NULL";
} elseif ($typeFilter === 'user') {
    $sql .= " AND r.reportedUserID IS NOT NULL AND r.reportedItemID IS NULL";
}

if (!empty($searchQuery)) {
    $sql .= " AND (r.reason LIKE :search OR u1.name LIKE :search OR u2.name LIKE :search OR i.title LIKE :search)";
    $params['search'] = "%$searchQuery%";
}

$sql .= " ORDER BY r.submitDate DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v) { 
        return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="admin.png">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        .report-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-subtle);
            transition: background var(--transition-fast);
            cursor: pointer;
        }
        .report-item:last-child {
            border-bottom: none;
        }
        .report-item:hover {
            background: var(--bg-light);
        }
        .report-item.unread {
            background: var(--blue-subtle);
            border-left: 3px solid var(--accent-blue);
        }
        .report-item.unread:hover {
            background: #dbeafe;
        }
        .report-thumb {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            object-fit: cover;
            background: var(--bg-light);
            flex-shrink: 0;
        }
        .letter-avatar-report {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 20px;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .letter-avatar-modal {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            color: var(--text-muted);
        }
        .report-content {
            flex: 1;
            min-width: 0;
        }
        .report-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        .report-type {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .type-item {
            background: var(--violet-subtle);
            color: var(--accent-violet);
        }
        .type-user {
            background: var(--blue-subtle);
            color: var(--accent-blue);
        }
        .type-scam {
            background: #fee2e2;
            color: #dc2626;
        }
        .type-help {
            background: #fef3c7;
            color: #d97706;
        }
        .type-spam {
            background: #fce7f3;
            color: #db2777;
        }
        .type-inappropriate {
            background: #ede9fe;
            color: #7c3aed;
        }
        .type-account {
            background: #e0e7ff;
            color: #4f46e5;
        }
        .type-other {
            background: #f3f4f6;
            color: #6b7280;
        }
        .report-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: auto;
        }
        .report-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .report-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .report-reason {
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-light);
            padding: 10px 14px;
            border-radius: var(--radius-md);
            line-height: 1.5;
        }

        /* Modal Styles */
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .detail-value {
            font-size: 14px;
            color: var(--text-primary);
        }
        .detail-card {
            background: var(--bg-light);
            padding: 14px;
            border-radius: var(--radius-md);
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .detail-card-img {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            object-fit: cover;
            background: var(--card-bg);
        }
        .detail-card-info h4 {
            margin: 0 0 2px;
            font-size: 14px;
            font-weight: 600;
        }
        .detail-card-info p {
            margin: 0;
            font-size: 12px;
            color: var(--text-muted);
        }
        .reason-box {
            background: var(--red-subtle);
            border: 1px solid rgba(220, 38, 38, 0.15);
            padding: 16px;
            border-radius: var(--radius-md);
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-primary);
        }
        .notes-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
            min-height: 80px;
            color: var(--text-primary);
        }
        .notes-textarea:focus {
            outline: none;
            border-color: var(--text-primary);
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
                    <a href="admin_dashboard.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        Dashboard
                    </a>
                    <a href="admin_items.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        Manage Items
                        <?php if ($stats['items_pending'] > 0): ?>
                        <span class="nav-badge"><?= $stats['items_pending'] ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="admin_users.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Users
                    </a>
                    <a href="admin_reports.php" class="nav-link active">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                        Reports
                        <?php if ($stats['unopened'] > 0): ?>
                        <span class="nav-badge"><?= $stats['unopened'] ?></span>
                        <?php endif; ?>
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
                        <h1 class="page-title">Reports</h1>
                        <p class="page-subtitle">Review and manage user-submitted reports</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row cols-3 mb-6">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value"><?= number_format($stats['total']) ?></div>
                        <div class="stat-card-label">Total Reports</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value"><?= number_format($stats['unopened']) ?></div>
                        <div class="stat-card-label">New Reports</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                        </div>
                        <div class="stat-card-value"><?= number_format($stats['checked']) ?></div>
                        <div class="stat-card-label">Reviewed</div>
                    </div>
                </div>

                <!-- Reports Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">All Reports</h3>
                        <div class="filter-bar">
                            <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <select name="status" class="filter-input form-select">
                                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="unopened" <?= $statusFilter === 'unopened' ? 'selected' : '' ?>>New</option>
                                    <option value="checked" <?= $statusFilter === 'checked' ? 'selected' : '' ?>>Reviewed</option>
                                </select>
                                <select name="type" class="filter-input form-select">
                                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                                    <option value="item" <?= $typeFilter === 'item' ? 'selected' : '' ?>>Item Reports</option>
                                    <option value="user" <?= $typeFilter === 'user' ? 'selected' : '' ?>>User Reports</option>
                                </select>
                                <input type="text" name="search" class="filter-input search-input" placeholder="Search..." value="<?= h($searchQuery) ?>" style="width: 200px;">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <?php if ($statusFilter !== 'all' || $typeFilter !== 'all' || !empty($searchQuery)): ?>
                                    <a href="admin_reports.php" class="btn btn-secondary">Reset</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card-body no-padding">
                        <?php if (empty($reports)): ?>
                            <div class="empty-state">
                                <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p class="empty-state-title">No reports found</p>
                                <p class="empty-state-text">No reports match your current filters.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($reports as $report): 
                                // Get reporter info for display (who submitted the report)
                                $reporterThumb = !empty($report['reporter_image']) && file_exists($report['reporter_image']) 
                                    ? $report['reporter_image'] 
                                    : '';
                                $hasReporterThumb = !empty($reporterThumb);
                                $reporterLetter = strtoupper(substr($report['reporter_name'] ?? 'A', 0, 1));
                                $reporterName = $report['reporter_name'] ?? 'Anonymous';
                            ?>
                            <div class="report-item <?= !$report['is_opened'] ? 'unread' : '' ?>" 
                                 onclick="openReportModal(<?= htmlspecialchars(json_encode($report), ENT_QUOTES) ?>)">
                                <?php if ($hasReporterThumb): ?>
                                    <img src="<?= h($reporterThumb) ?>" class="report-thumb" alt="">
                                <?php else: ?>
                                    <div class="letter-avatar-report"><?= $reporterLetter ?></div>
                                <?php endif; ?>
                                <div class="report-content">
                                    <?php
                                        // Format report type for display
                                        $reportType = $report['report_type'] ?? 'other';
                                        if (empty($reportType)) $reportType = 'other';
                                        
                                        // Map to CSS class
                                        $typeClasses = [
                                            'scam_fraud' => 'type-scam',
                                            'user_report' => 'type-user',
                                            'item_report' => 'type-item',
                                            'help_support' => 'type-help',
                                            'spam' => 'type-spam',
                                            'inappropriate' => 'type-inappropriate',
                                            'account_issue' => 'type-account',
                                            'other' => 'type-other'
                                        ];
                                        $typeClass = $typeClasses[$reportType] ?? 'type-other';
                                        
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
                                    ?>
                                    <div class="report-header">
                                        <span class="report-type <?= $typeClass ?>">
                                            <?= h($displayLabel) ?>
                                        </span>
                                        <span class="badge <?= !$report['is_opened'] ? 'badge-warning' : 'badge-success' ?>">
                                            <?= !$report['is_opened'] ? 'New' : 'Reviewed' ?>
                                        </span>
                                        <span class="report-date"><?= date('d M Y', strtotime($report['submitDate'])) ?></span>
                                    </div>
                                    <div class="report-title"><?= h($reporterName) ?></div>
                                    <div class="report-meta">
                                        <?php if ($report['reporter_matric']): ?>
                                            <?= h($report['reporter_matric']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="report-reason"><?= h(mb_strimwidth($report['reason'], 0, 150, '...')) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Report Detail Modal -->
    <div class="modal-overlay" id="reportModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Report Details</h3>
                <button class="modal-close" onclick="closeModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="detail-section">
                    <div class="detail-label">Report Type</div>
                    <div class="detail-value" id="m-type"></div>
                </div>
                
                <div class="detail-section" id="reported-item-section">
                    <div class="detail-label">Reported Item</div>
                    <div class="detail-card">
                        <img src="" id="m-item-img" class="detail-card-img" alt="">
                        <div class="detail-card-info">
                            <h4 id="m-item-title"></h4>
                            <p id="m-item-price"></p>
                        </div>
                    </div>
                </div>
                
                <div class="detail-section" id="reported-user-section">
                    <div class="detail-label">Reported User</div>
                    <div class="detail-card">
                        <img src="" id="m-user-img" class="detail-card-img" style="border-radius: 50%;" alt="">
                        <div id="m-user-letter" class="letter-avatar-modal" style="display:none;"></div>
                        <div class="detail-card-info">
                            <h4 id="m-user-name"></h4>
                            <p id="m-user-matric"></p>
                        </div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <div class="detail-label">Reported By</div>
                    <div class="detail-value" id="m-reporter"></div>
                </div>
                
                <div class="detail-section">
                    <div class="detail-label">Submitted On</div>
                    <div class="detail-value" id="m-date"></div>
                </div>
                
                <div class="detail-section">
                    <div class="detail-label">Reason / Description</div>
                    <div class="reason-box" id="m-reason"></div>
                </div>
                
                <div class="detail-section">
                    <div class="detail-label">Admin Notes</div>
                    <textarea class="notes-textarea" id="m-notes" placeholder="Add notes about this report..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" onclick="deleteReport()">Delete Report</button>
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>
    
    <input type="hidden" id="current-report-id" value="">

    <script>
        const modal = document.getElementById('reportModal');
        let currentReport = null;
        
        function openReportModal(report) {
            currentReport = report;
            document.getElementById('current-report-id').value = report.ReportID;
            
            // Mark as opened
            if (!report.is_opened) {
                fetch('admin_reports.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=mark_opened&report_id=${report.ReportID}`
                });
            }
            
            const isItemReport = report.item_id && report.item_id !== null;
            const isUserReport = report.reported_user_id && !isItemReport;
            
            // Format report type for display
            const reportType = report.report_type || 'other';
            const typeLabels = {
                'scam_fraud': 'Scam/Fraud',
                'user_report': 'User Report',
                'item_report': 'Item Report',
                'help_support': 'Help/Support',
                'spam': 'Spam',
                'inappropriate': 'Inappropriate',
                'account_issue': 'Account Issue',
                'other': 'Other'
            };
            const typeClasses = {
                'scam_fraud': 'type-scam',
                'user_report': 'type-user',
                'item_report': 'type-item',
                'help_support': 'type-help',
                'spam': 'type-spam',
                'inappropriate': 'type-inappropriate',
                'account_issue': 'type-account',
                'other': 'type-other'
            };
            const displayLabel = typeLabels[reportType] || reportType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const typeClass = typeClasses[reportType] || 'type-other';
            
            document.getElementById('m-type').innerHTML = `<span class="report-type ${typeClass}">${displayLabel}</span>`;
            
            document.getElementById('reported-item-section').style.display = isItemReport ? 'block' : 'none';
            document.getElementById('reported-user-section').style.display = isUserReport || isItemReport ? 'block' : 'none';
            
            if (isItemReport) {
                const img = report.item_image ? report.item_image.split(',')[0] : 'avatar.png';
                document.getElementById('m-item-img').src = img;
                document.getElementById('m-item-title').textContent = report.item_title || 'Unknown Item';
                document.getElementById('m-item-price').textContent = report.item_price ? 'RM ' + parseFloat(report.item_price).toFixed(2) : '';
            }
            
            if (isUserReport || isItemReport) {
                const userImg = document.getElementById('m-user-img');
                const userLetter = document.getElementById('m-user-letter');
                const userName = report.reported_user_name || 'Unknown User';
                
                if (report.reported_user_image && report.reported_user_image !== '') {
                    userImg.src = report.reported_user_image;
                    userImg.style.display = 'block';
                    userLetter.style.display = 'none';
                } else {
                    userImg.style.display = 'none';
                    userLetter.style.display = 'flex';
                    userLetter.textContent = userName.charAt(0).toUpperCase();
                }
                
                document.getElementById('m-user-name').textContent = userName;
                document.getElementById('m-user-matric').textContent = report.reported_user_matric || '';
            }
            
            document.getElementById('m-reporter').textContent = (report.reporter_name || 'Anonymous') + 
                (report.reporter_matric ? ' (' + report.reporter_matric + ')' : '');
            document.getElementById('m-date').textContent = new Date(report.submitDate).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
            document.getElementById('m-reason').textContent = report.reason || 'No reason provided';
            document.getElementById('m-notes').value = report.admin_notes || '';
            
            modal.classList.add('active');
        }
        
        function closeModal() {
            const notes = document.getElementById('m-notes').value;
            const reportId = document.getElementById('current-report-id').value;
            
            if (reportId && notes !== (currentReport?.admin_notes || '')) {
                fetch('admin_reports.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=save_notes&report_id=${reportId}&notes=${encodeURIComponent(notes)}`
                });
            }
            
            modal.classList.remove('active');
        }
        
        function deleteReport() {
            if (!confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
                return;
            }
            
            const reportId = document.getElementById('current-report-id').value;
            
            fetch('admin_reports.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_report&report_id=${reportId}`
            }).then(() => {
                location.reload();
            });
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>
