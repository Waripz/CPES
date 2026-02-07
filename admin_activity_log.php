<?php
require_once 'config.php';
adminSecureSessionStart();

if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') { header('Location: admin_login.php'); exit; }

$pdo = getDBConnection();
require_once 'admin_functions.php';
ensureActivityLogTable($pdo);

// For sidebar badge
$pendingItemsCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
$newReportsCount = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();

// --- PAGINATION & FILTER LOGIC ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 15; // Items per page
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$dateFilter = $_GET['date'] ?? '';

// Build Query
$sqlBase = "SELECT l.*, a.username as admin_name,
                   i.title as item_title,
                   u.name as target_user_name, u.matricNo as target_user_matric,
                   m_memo.subject as memo_subject
            FROM activity_log l 
            LEFT JOIN admin a ON l.actor_admin_id = a.AdminID 
            LEFT JOIN item i ON l.entity_type = 'item' AND l.entity_id = i.ItemID
            LEFT JOIN users u ON l.entity_type = 'user' AND l.entity_id = u.UserID
            LEFT JOIN memo m_memo ON l.entity_type = 'memo' AND l.entity_id = m_memo.MemoID
            WHERE 1=1";

$params = [];

if (!empty($search)) {
    $sqlBase .= " AND (l.details LIKE ? OR a.username LIKE ? OR u.name LIKE ? OR i.title LIKE ?)";
    $term = "%$search%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

if (!empty($actionFilter)) {
    $sqlBase .= " AND l.action = ?";
    $params[] = $actionFilter;
}

if (!empty($dateFilter)) {
    $sqlBase .= " AND DATE(l.created_at) = ?";
    $params[] = $dateFilter;
}

// Count Total
$countSql = "SELECT COUNT(*) FROM (" . str_replace("l.*, a.username as admin_name,
                   i.title as item_title,
                   u.name as target_user_name, u.matricNo as target_user_matric,
                   m_memo.subject as memo_subject", "l.id", $sqlBase) . ") as count_table";

// For count, we need to reconstruct query carefully or just use valid count wrapper
// Simple count aproach:
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log l 
            LEFT JOIN admin a ON l.actor_admin_id = a.AdminID 
            LEFT JOIN item i ON l.entity_type = 'item' AND l.entity_id = i.ItemID
            LEFT JOIN users u ON l.entity_type = 'user' AND l.entity_id = u.UserID
            WHERE 1=1 " . 
            (!empty($search) ? " AND (l.details LIKE ? OR a.username LIKE ? OR u.name LIKE ? OR i.title LIKE ?)" : "") .
            (!empty($actionFilter) ? " AND l.action = ?" : "") .
            (!empty($dateFilter) ? " AND DATE(l.created_at) = ?" : ""));
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $limit);

// Fetch Data
$sql = $sqlBase . " ORDER BY l.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct actions for dropdown
$actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="admin.png">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        .filter-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-select {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-page);
            font-size: 14px;
            color: var(--text-primary);
        }
        /* Pagination */
        .pagination-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 0;
            border-top: 1px solid var(--border-color);
            margin-top: 20px;
        }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            min-width: 32px;
            padding: 0 8px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-primary);
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .page-btn:hover { border-color: var(--accent-blue); color: var(--accent-blue); }
        .page-btn.active { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }
        .page-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
        
        /* Activity Item Styling Override for Table feel */
        .activity-table-row {
            display: grid;
            grid-template-columns: 60px 2fr 1.5fr 1fr;
            padding: 16px;
            border-bottom: 1px solid var(--border-subtle);
            align-items: center;
        }
        .activity-table-header {
            background: var(--bg-light);
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 13px;
            border-bottom: 1px solid var(--border-color);
        }
        .log-icon {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .log-details { font-size: 14px; color: var(--text-primary); }
        .log-meta { font-size: 13px; color: var(--text-muted); }
        .log-time { font-size: 13px; color: var(--text-muted); text-align: right; }
        
        @media (max-width: 768px) {
            .activity-table-row { grid-template-columns: 50px 1fr; gap: 10px; }
            .activity-table-header { display: none; }
            .log-meta, .log-time { grid-column: 2; text-align: left; }
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
                        <?php if ($pendingItemsCount > 0): ?>
                        <span class="nav-badge"><?= $pendingItemsCount ?></span>
                        <?php endif; ?>
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
                        <?php if ($newReportsCount > 0): ?>
                        <span class="nav-badge"><?= $newReportsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="admin_memo.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>
                        Memos
                    </a>
                    <a href="admin_activity_log.php" class="nav-link active">
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
                <header class="page-header">
                    <div class="header-content">
                        <h1>Activity Log</h1>
                        <p class="header-subtitle">Track all administrative actions and system events</p>
                    </div>
                </header>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">System Activities</div>
                        <form method="GET" class="filter-container">
                            <input type="text" name="search" class="form-input" placeholder="Search logs..." value="<?= h($search) ?>" style="width: 200px;">
                            <select name="action" class="filter-select">
                                <option value="">All Actions</option>
                                <?php foreach($actions as $act): ?>
                                    <option value="<?= h($act) ?>" <?= $actionFilter == $act ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $act)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="date" class="form-input" value="<?= h($dateFilter) ?>" style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-page); color: var(--text-primary); font-family: inherit;">
                            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            <?php if(!empty($search) || !empty($actionFilter) || !empty($dateFilter)): ?>
                                <a href="admin_activity_log.php" class="btn btn-outline btn-sm">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="card-body no-padding">
                        <div class="activity-table-header activity-table-row">
                            <div>Type</div>
                            <div>Activity Detail</div>
                            <div>Actor (Admin)</div>
                            <div style="text-align: right;">Time</div>
                        </div>

                        <?php if (empty($activityLogs)): ?>
                            <div class="empty-state">
                                <p>No activity logs found matching your criteria.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($activityLogs as $log): 
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
                                    default:
                                        $iconColor = 'var(--bg-light)';
                                        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
                                        $displayText = ucfirst(str_replace('_', ' ', $action));
                                }
                            ?>
                            <div class="activity-table-row">
                                <div class="log-icon-container">
                                    <div class="log-icon" style="background: <?= $iconColor ?>;">
                                        <?= $iconSvg ?>
                                    </div>
                                </div>
                                <div class="log-details-container">
                                    <div class="log-details"><?= $displayText ?></div>
                                    <div class="log-meta text-xs">
                                        <?php if (!empty($details) && $action !== 'create_user'): ?>
                                            <?php 
                                            foreach($details as $k => $v) { 
                                                if ($k != 'ip' && $k != 'user_agent' && $k != 'password') echo h(ucfirst($k)) . ': ' . h($v) . ' • '; 
                                            } 
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="log-actor text-sm">
                                    <?= h($adminName) ?>
                                </div>
                                <div class="log-time text-xs">
                                    <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalLogs) ?> of <?= $totalLogs ?> activities
                    </div>
                    <div class="pagination-controls">
                        <a href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($actionFilter) ?>&date=<?= urlencode($dateFilter) ?>" 
                           class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </a>
                        
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($actionFilter) ?>&date=<?= urlencode($dateFilter) ?>" 
                                   class="page-btn <?= $i === $page ? 'active' : '' ?>">
                                   <?= $i ?>
                                </a>
                            <?php elseif ($i == $page - 2 || $i == $page + 2): ?>
                                <span class="page-btn disabled">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <a href="?page=<?= min($totalPages, $page + 1) ?>&search=<?= urlencode($search) ?>&action=<?= urlencode($actionFilter) ?>&date=<?= urlencode($dateFilter) ?>" 
                           class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</body>
</html>
