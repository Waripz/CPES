<?php
require_once 'config.php';
adminSecureSessionStart();

if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') { header('Location: admin_login.php'); exit; }

$pdo = getDBConnection();

// For sidebar badges
$pendingItemsCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
$unopenedReportsCount = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();

// Ensure activity_log table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type ENUM('user','item','report','memo','other') NOT NULL,
        entity_id INT NOT NULL,
        actor_admin_id INT NULL,
        action VARCHAR(64) NOT NULL,
        details JSON NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at),
        INDEX idx_actor (actor_admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Handle Blacklist Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['blacklist_action'])) {
    header('Content-Type: application/json');
    $userId = (int)$_POST['user_id'];
    $action = $_POST['blacklist_action'];
    $reason = trim($_POST['reason'] ?? '');
    $duration = $_POST['duration'] ?? '';
    $adminId = (int)($_SESSION['AdminID'] ?? 0);
    
    try {
        if ($action === 'blacklist') {
            $blacklistUntil = null;
            switch($duration) {
                case '1day': $blacklistUntil = date('Y-m-d H:i:s', strtotime('+1 day')); break;
                case '2days': $blacklistUntil = date('Y-m-d H:i:s', strtotime('+2 days')); break;
                case '1week': $blacklistUntil = date('Y-m-d H:i:s', strtotime('+1 week')); break;
                case '1month': $blacklistUntil = date('Y-m-d H:i:s', strtotime('+1 month')); break;
                case '1year': $blacklistUntil = date('Y-m-d H:i:s', strtotime('+1 year')); break;
                case 'permanent': $blacklistUntil = '9999-12-31 23:59:59'; break;
                default:
                    if (!empty($_POST['custom_date'])) {
                        $blacklistUntil = date('Y-m-d 23:59:59', strtotime($_POST['custom_date']));
                    }
                    break;
            }
            
            if ($blacklistUntil) {
                $prev = $pdo->prepare("SELECT blacklist_until, blacklist_reason FROM users WHERE UserID = ?");
                $prev->execute([$userId]);
                $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: ['blacklist_until' => null, 'blacklist_reason' => null];

                $stmt = $pdo->prepare("UPDATE users SET blacklist_until = ?, blacklist_reason = ? WHERE UserID = ?");
                $stmt->execute([$blacklistUntil, $reason, $userId]);

                $ins = $pdo->prepare("INSERT INTO activity_log (entity_type, entity_id, actor_admin_id, action, details) VALUES (?, ?, ?, ?, ?)");
                $details = json_encode(['reason' => $reason, 'duration' => $duration, 'blacklist_until' => $blacklistUntil, 'previous_blacklist_until' => $prevRow['blacklist_until'], 'previous_reason' => $prevRow['blacklist_reason']]);
                $ins->execute(['user', $userId, $adminId, 'blacklist', $details]);
                echo json_encode(['success' => true, 'message' => 'User has been blacklisted']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid duration']);
            }
        } elseif ($action === 'unblacklist') {
            $prev = $pdo->prepare("SELECT blacklist_until, blacklist_reason FROM users WHERE UserID = ?");
            $prev->execute([$userId]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: ['blacklist_until' => null, 'blacklist_reason' => null];

            $stmt = $pdo->prepare("UPDATE users SET blacklist_until = NULL, blacklist_reason = NULL WHERE UserID = ?");
            $stmt->execute([$userId]);

            $ins = $pdo->prepare("INSERT INTO activity_log (entity_type, entity_id, actor_admin_id, action, details) VALUES (?, ?, ?, ?, ?)");
            $unblacklistReason = $reason !== '' ? $reason : $prevRow['blacklist_reason'];
            $details = json_encode(['reason' => $unblacklistReason, 'previous_blacklist_until' => $prevRow['blacklist_until'], 'previous_reason' => $prevRow['blacklist_reason']]);
            $ins->execute(['user', $userId, $adminId, 'unblacklist', $details]);
            echo json_encode(['success' => true, 'message' => 'User has been unblacklisted']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

$search = $_GET['search'] ?? '';
$users = [];
try {
    if (!empty($search)) {
        $term = "%$search%";
        $sql = "SELECT * FROM users WHERE (name LIKE ? OR matricNo LIKE ? OR email LIKE ?) AND matricNo != 'ADMIN' ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$term, $term, $term]);
    } else {
        $sql = "SELECT * FROM users WHERE matricNo != 'ADMIN' ORDER BY UserID DESC LIMIT 20";
        $stmt = $pdo->query($sql);
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        .user-link {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: var(--text-primary);
            gap: 12px;
        }
        .user-link:hover { color: var(--accent-blue); }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
        }
        .user-link:hover .user-avatar {
            border-color: var(--accent-blue);
        }
        
        /* Letter Avatar */
        .letter-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            flex-shrink: 0;
            background: var(--bg-light);
            border: 2px solid var(--border-color);
        }
        .letter-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            flex-shrink: 0;
            background: var(--bg-light);
            border: 3px solid var(--border-color);
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: var(--violet-subtle);
            color: var(--accent-violet);
        }
        
        /* Profile Modal */
        .profile-header {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
            align-items: flex-start;
        }
        .profile-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid var(--accent-blue);
            flex-shrink: 0;
        }
        .profile-info { flex: 1; }
        .profile-name {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 6px;
        }
        .profile-meta {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0 0 12px;
        }
        
        .detail-section {
            margin-bottom: 20px;
            padding: 16px;
            background: var(--bg-page);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        .detail-section-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin: 0 0 12px;
            display: block;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-subtle);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            font-size: 13px;
            color: var(--text-muted);
        }
        .detail-value {
            font-size: 14px;
            color: var(--text-primary);
            font-weight: 600;
        }
        
        /* Dual Panel */
        .modal-dual {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            height: 100%;
        }
        .modal-panel {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-panel:first-child {
            border-right: 1px solid var(--border-color);
        }
        .modal-panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-page);
        }
        .modal-panel-header h4 {
            margin: 0;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }
        .modal-panel-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        
        /* Items Grid */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
        }
        .item-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .item-card:hover {
            border-color: var(--accent-blue);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        .item-card-image {
            width: 100%;
            height: 80px;
            object-fit: cover;
            background: var(--bg-page);
        }
        .item-card-info { padding: 8px; }
        .item-card-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .item-card-price {
            font-size: 11px;
            font-weight: 700;
            color: var(--accent-blue);
        }
        .items-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }
        
        /* Item Detail Panel */
        .item-detail-panel {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 100%;
            background: var(--card-bg);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            z-index: 10;
        }
        .item-detail-panel.show {
            transform: translateX(0);
        }
        .close-detail-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border: none;
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 18px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-sm);
            z-index: 11;
        }
        .close-detail-btn:hover {
            background: var(--bg-page);
            color: var(--text-primary);
        }
        .item-detail-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: var(--bg-page);
        }
        .item-detail-info {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .item-detail-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 8px;
        }
        .item-detail-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--accent-blue);
            margin: 12px 0;
        }
        .item-detail-description {
            background: var(--bg-page);
            padding: 12px;
            border-radius: var(--radius-md);
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        .status-active { color: var(--accent-green); }
        .status-blacklisted { color: var(--accent-red); font-weight: 600; }
        
        /* Blacklist Modal */
        .blacklist-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            pointer-events: none;
            transition: opacity var(--transition-base);
        }
        .blacklist-modal-overlay.active { opacity: 1; pointer-events: auto; }
        
        .blacklist-modal {
            background: var(--card-bg);
            width: 100%;
            max-width: 420px;
            border-radius: var(--radius-xl);
            overflow: hidden;
            transform: translateY(20px);
            transition: transform var(--transition-base);
        }
        .blacklist-modal-overlay.active .blacklist-modal {
            transform: translateY(0);
        }
        .blacklist-modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--red-subtle);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .blacklist-modal-header h3 {
            margin: 0;
            font-size: 16px;
            color: var(--accent-red);
            font-weight: 600;
        }
        .blacklist-modal-body { padding: 24px; }
        .blacklist-form-group { margin-bottom: 16px; }
        .blacklist-form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .blacklist-form-group select,
        .blacklist-form-group textarea,
        .blacklist-form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: var(--card-bg);
        }
        .blacklist-form-group select:focus,
        .blacklist-form-group textarea:focus,
        .blacklist-form-group input:focus {
            outline: none;
            border-color: var(--accent-red);
        }
        .blacklist-form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .custom-date-group { display: none; margin-top: 10px; }
        .custom-date-group.show { display: block; }
        .blacklist-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            background: var(--bg-page);
        }
        
        @media (max-width: 900px) {
            .modal-dual { grid-template-columns: 1fr; }
            .modal-panel:first-child { border-right: none; border-bottom: 1px solid var(--border-color); }
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
                    <a href="admin_users.php" class="nav-link active">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Users
                    </a>
                    <a href="admin_reports.php" class="nav-link">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/></svg>
                        Reports
                        <?php if ($unopenedReportsCount > 0): ?>
                        <span class="nav-badge"><?= $unopenedReportsCount ?></span>
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
                <header class="page-header">
                    <div class="header-content">
                        <h1>User Management</h1>
                        <p class="header-subtitle">Search and manage user accounts</p>
                    </div>
                </header>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Find Users</div>
                        <form method="GET" class="filter-bar">
                            <input type="text" name="search" class="form-input" placeholder="Search by name, matric, or email..." value="<?= h($search) ?>" style="width: 280px;">
                            <button type="submit" class="btn btn-primary btn-sm">Search</button>
                            <?php if (!empty($search)): ?>
                                <a href="admin_users.php" class="btn btn-outline btn-sm">Clear</a>
                            <?php endif; ?>
                        </form>
                </div>
                
                <div class="table-container">
                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <p>No users found. Try a different search.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Matric No</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th width="120">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach($users as $u): 
                                    $hasImage = !empty($u['profile_image']) && file_exists($u['profile_image']);
                                    $img = $hasImage ? $u['profile_image'] : '';
                                    $firstLetter = strtoupper(substr($u['name'], 0, 1));
                                    $isBlacklisted = !empty($u['blacklist_until']) && strtotime($u['blacklist_until']) > time();
                                    $userData = json_encode([
                                        'UserID' => $u['UserID'],
                                        'name' => $u['name'],
                                        'matricNo' => $u['matricNo'],
                                        'email' => $u['email'],
                                        'role' => $u['role'],
                                        'profile_image' => $img,
                                        'bio' => $u['bio'] ?? '',
                                        'blacklist_until' => $u['blacklist_until'] ?? null,
                                        'blacklist_reason' => $u['blacklist_reason'] ?? null,
                                        'is_blacklisted' => $isBlacklisted,
                                        'first_letter' => $firstLetter
                                    ]);
                                ?>
                                    <tr>
                                        <td>
                                            <a href="javascript:void(0)" onclick="openUserModal(<?= htmlspecialchars($userData, ENT_QUOTES, 'UTF-8') ?>)" class="user-link">
                                                <?php if ($hasImage): ?>
                                                    <img src="<?= h($img) ?>" class="user-avatar" alt="<?= h($u['name']) ?>">
                                                <?php else: ?>
                                                    <div class="letter-avatar"><?= $firstLetter ?></div>
                                                <?php endif; ?>
                                                <strong><?= h($u['name']) ?></strong>
                                            </a>
                                        </td>
                                        <td><?= h($u['matricNo']) ?></td>
                                        <td><?= h($u['email']) ?></td>
                                        <td><span class="role-badge"><?= ucfirst(h($u['role'])) ?></span></td>
                                        <td><button type="button" class="btn btn-outline btn-sm" onclick="openUserModal(<?= htmlspecialchars($userData, ENT_QUOTES, 'UTF-8') ?>)">View</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>
    </div>

    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" style="max-width: 900px; max-height: 80vh;">
            <div class="modal-header">
                <h3 class="modal-title">User Details</h3>
                <button class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0; height: 500px;">
                <div class="modal-dual">
                    <!-- Profile Panel -->
                    <div class="modal-panel">
                        <div class="modal-panel-header">
                            <h4>Profile</h4>
                        </div>
                        <div class="modal-panel-body">
                            <div class="profile-header">
                                <img src="" id="modal-avatar" class="profile-avatar-large" alt="Avatar" style="display:none;">
                                <div id="modal-letter-avatar" class="letter-avatar-large" style="display:none;"></div>
                                <div class="profile-info">
                                    <p class="profile-name" id="modal-name"></p>
                                    <p class="profile-meta">Matric: <strong id="modal-matric"></strong></p>
                                    <p class="profile-meta" id="modal-email"></p>
                                    <span class="role-badge" id="modal-role"></span>
                                </div>
                            </div>

                            <div class="detail-section">
                                <span class="detail-section-title">Account Info</span>
                                <div class="detail-row">
                                    <span class="detail-label">User ID</span>
                                    <span class="detail-value" id="modal-userid"></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Status</span>
                                    <span class="detail-value" id="modal-status">Active</span>
                                </div>
                                <div class="detail-row" id="blacklist-until-row" style="display: none;">
                                    <span class="detail-label">Blacklisted Until</span>
                                    <span class="detail-value" id="modal-blacklist-until"></span>
                                </div>
                            </div>
                            
                            <div class="detail-section" id="bio-section" style="display: none;">
                                <span class="detail-section-title">Bio</span>
                                <p style="margin: 0; font-size: 13px; color: var(--text-secondary); line-height: 1.6;" id="modal-bio"></p>
                            </div>
                            
                            <div class="detail-section">
                                <span class="detail-section-title">Admin Actions</span>
                                <button class="btn btn-danger" id="btn-blacklist" onclick="openBlacklistModal()" style="width: 100%;">Blacklist User</button>
                                <button class="btn btn-success" id="btn-unblacklist" onclick="unblacklistUser()" style="display: none; width: 100%;">Remove Blacklist</button>
                            </div>
                        </div>
                    </div>

                    <!-- Items Panel -->
                    <div class="modal-panel" style="position: relative;">
                        <div class="modal-panel-header">
                            <h4>User Items</h4>
                        </div>
                        <div class="modal-panel-body" style="max-height: 400px; overflow-y: auto;">
                            <!-- Active Items Section -->
                            <div id="activeItemsSection" style="display: none;">
                                <div class="items-section-header" style="font-size: 12px; font-weight: 600; color: var(--accent-green); margin-bottom: 10px; padding: 6px 10px; background: rgba(16, 185, 129, 0.1); border-radius: 6px; display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 8px; height: 8px; background: var(--accent-green); border-radius: 50%;"></span>
                                    ACTIVE LISTINGS
                                </div>
                                <div class="items-grid" id="activeItemsGrid"></div>
                            </div>
                            
                            <!-- Sold Items Section -->
                            <div id="soldItemsSection" style="display: none; margin-top: 16px;">
                                <div class="items-section-header" style="font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; padding: 6px 10px; background: var(--bg-light); border-radius: 6px; display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 8px; height: 8px; background: var(--text-muted); border-radius: 50%;"></span>
                                    SOLD ITEMS
                                </div>
                                <div class="items-grid" id="soldItemsGrid"></div>
                            </div>
                            
                            <!-- Other Items Section (under review, rejected, etc) -->
                            <div id="otherItemsSection" style="display: none; margin-top: 16px;">
                                <div class="items-section-header" style="font-size: 12px; font-weight: 600; color: var(--accent-amber); margin-bottom: 10px; padding: 6px 10px; background: rgba(245, 158, 11, 0.1); border-radius: 6px; display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 8px; height: 8px; background: var(--accent-amber); border-radius: 50%;"></span>
                                    OTHER
                                </div>
                                <div class="items-grid" id="otherItemsGrid"></div>
                            </div>
                            
                            <div class="items-empty" id="itemsEmpty" style="display: none;">No items listed</div>
                        </div>

                        <!-- Item Detail -->
                        <div class="item-detail-panel" id="itemDetailPanel">
                            <button class="close-detail-btn" onclick="closeItemDetail()">&times;</button>
                            <img src="" id="itemDetailImage" class="item-detail-image" alt="Item">
                            <div class="item-detail-info">
                                <h3 class="item-detail-title" id="itemDetailTitle"></h3>
                                <span class="badge" id="itemDetailBadge"></span>
                                <div class="item-detail-price" id="itemDetailPrice"></div>
                                <span class="detail-section-title">Description</span>
                                <div class="item-detail-description" id="itemDetailDescription"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Blacklist Modal -->
    <div class="blacklist-modal-overlay" id="blacklistModal">
        <div class="blacklist-modal">
            <div class="blacklist-modal-header">
                <h3>Blacklist User</h3>
                <button class="modal-close" onclick="closeBlacklistModal()">&times;</button>
            </div>
            <div class="blacklist-modal-body">
                <p style="margin: 0 0 16px; color: var(--text-secondary); font-size: 13px;">
                    This will prevent the user from publishing new items.
                </p>
                
                <div class="blacklist-form-group">
                    <label for="blacklist-duration">Duration</label>
                    <select id="blacklist-duration" onchange="toggleCustomDate()">
                        <option value="">Select duration...</option>
                        <option value="1day">1 Day</option>
                        <option value="2days">2 Days</option>
                        <option value="1week">1 Week</option>
                        <option value="1month">1 Month</option>
                        <option value="1year">1 Year</option>
                        <option value="permanent">Permanent</option>
                        <option value="custom">Custom Date</option>
                    </select>
                </div>
                
                <div class="blacklist-form-group custom-date-group" id="custom-date-group">
                    <label for="custom-date">End Date</label>
                    <input type="date" id="custom-date" min="">
                </div>
                
                <div class="blacklist-form-group">
                    <label for="blacklist-reason">Reason</label>
                    <textarea id="blacklist-reason" placeholder="Enter reason..."></textarea>
                </div>
            </div>
            <div class="blacklist-modal-footer">
                <button class="btn btn-outline" onclick="closeBlacklistModal()">Cancel</button>
                <button class="btn btn-danger" onclick="confirmBlacklist()">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        const userModal = document.getElementById('userModal');
        const blacklistModal = document.getElementById('blacklistModal');
        let currentUser = null;

        function openUserModal(user) {
            currentUser = user;
            
            const modalAvatar = document.getElementById('modal-avatar');
            const modalLetterAvatar = document.getElementById('modal-letter-avatar');
            
            if (user.profile_image && user.profile_image !== '') {
                modalAvatar.src = user.profile_image;
                modalAvatar.style.display = 'block';
                modalLetterAvatar.style.display = 'none';
            } else {
                modalAvatar.style.display = 'none';
                modalLetterAvatar.style.display = 'flex';
                modalLetterAvatar.innerText = user.first_letter;
            }
            
            document.getElementById('modal-name').innerText = user.name;
            document.getElementById('modal-matric').innerText = user.matricNo;
            document.getElementById('modal-email').innerText = user.email;
            document.getElementById('modal-userid').innerText = '#' + user.UserID;
            document.getElementById('modal-role').innerText = user.role.toUpperCase();
            
            const statusEl = document.getElementById('modal-status');
            const blacklistUntilRow = document.getElementById('blacklist-until-row');
            const blacklistUntilEl = document.getElementById('modal-blacklist-until');
            const btnBlacklist = document.getElementById('btn-blacklist');
            const btnUnblacklist = document.getElementById('btn-unblacklist');
            
            if (user.is_blacklisted) {
                statusEl.innerHTML = '<span class="status-blacklisted">Blacklisted</span>';
                blacklistUntilRow.style.display = 'flex';
                
                if (user.blacklist_until === '9999-12-31 23:59:59') {
                    blacklistUntilEl.innerHTML = '<span class="status-blacklisted">Permanent</span>';
                } else {
                    const date = new Date(user.blacklist_until);
                    blacklistUntilEl.innerHTML = '<span class="status-blacklisted">' + date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + '</span>';
                }
                
                btnBlacklist.style.display = 'none';
                btnUnblacklist.style.display = 'block';
            } else {
                statusEl.innerHTML = '<span class="status-active">Active</span>';
                blacklistUntilRow.style.display = 'none';
                btnBlacklist.style.display = 'block';
                btnUnblacklist.style.display = 'none';
            }

            const bioSection = document.getElementById('bio-section');
            if (user.bio && user.bio.trim()) {
                document.getElementById('modal-bio').innerText = user.bio;
                bioSection.style.display = 'block';
            } else {
                bioSection.style.display = 'none';
            }

            fetchUserItems(user.UserID);
            userModal.classList.add('active');
        }

        function fetchUserItems(userID) {
            const activeGrid = document.getElementById('activeItemsGrid');
            const soldGrid = document.getElementById('soldItemsGrid');
            const otherGrid = document.getElementById('otherItemsGrid');
            const activeSection = document.getElementById('activeItemsSection');
            const soldSection = document.getElementById('soldItemsSection');
            const otherSection = document.getElementById('otherItemsSection');
            const itemsEmpty = document.getElementById('itemsEmpty');
            const itemDetailPanel = document.getElementById('itemDetailPanel');
            
            activeGrid.innerHTML = '';
            soldGrid.innerHTML = '';
            otherGrid.innerHTML = '';
            activeSection.style.display = 'none';
            soldSection.style.display = 'none';
            otherSection.style.display = 'none';
            itemDetailPanel.classList.remove('show');

            fetch(`/e-kedai/get_user_items.php?userid=${userID}`)
                .then(response => response.json())
                .then(data => {
                    if (data.items && data.items.length > 0) {
                        itemsEmpty.style.display = 'none';
                        
                        let hasActive = false, hasSold = false, hasOther = false;
                        
                        data.items.forEach(item => {
                            const card = document.createElement('div');
                            card.className = 'item-card';
                            card.onclick = () => showItemDetail(item);
                            
                            const img = item.image ? item.image.split(',')[0] : 'placeholder.png';
                            const status = item.status.toLowerCase();
                            
                            let priceDisplay = '';
                            let statusBadge = '';
                            
                            if (status === 'sold') {
                                // Show sold price if available
                                const soldPrice = item.sold_price ? parseFloat(item.sold_price).toFixed(2) : parseFloat(item.price).toFixed(2);
                                priceDisplay = `<p class="item-card-price" style="color: var(--text-muted);">Sold: RM ${soldPrice}</p>`;
                                statusBadge = '<span style="position:absolute;top:6px;right:6px;background:#6b7280;color:#fff;font-size:9px;padding:2px 6px;border-radius:4px;font-weight:600;">SOLD</span>';
                            } else if (status === 'available') {
                                priceDisplay = `<p class="item-card-price">RM ${parseFloat(item.price).toFixed(2)}</p>`;
                            } else {
                                priceDisplay = `<p class="item-card-price">RM ${parseFloat(item.price).toFixed(2)}</p>`;
                                const badgeColor = status === 'under_review' ? '#f59e0b' : (status === 'rejected' ? '#ef4444' : '#6b7280');
                                const badgeText = status === 'under_review' ? 'REVIEW' : status.toUpperCase();
                                statusBadge = `<span style="position:absolute;top:6px;right:6px;background:${badgeColor};color:#fff;font-size:9px;padding:2px 6px;border-radius:4px;font-weight:600;">${badgeText}</span>`;
                            }
                            
                            card.innerHTML = `
                                <div style="position:relative;">
                                    <img src="${img}" class="item-card-image" alt="${item.title}">
                                    ${statusBadge}
                                </div>
                                <div class="item-card-info">
                                    <p class="item-card-title">${item.title}</p>
                                    ${priceDisplay}
                                </div>
                            `;
                            
                            if (status === 'available') {
                                activeGrid.appendChild(card);
                                hasActive = true;
                            } else if (status === 'sold') {
                                soldGrid.appendChild(card);
                                hasSold = true;
                            } else {
                                otherGrid.appendChild(card);
                                hasOther = true;
                            }
                        });
                        
                        if (hasActive) activeSection.style.display = 'block';
                        if (hasSold) soldSection.style.display = 'block';
                        if (hasOther) otherSection.style.display = 'block';
                    } else {
                        itemsEmpty.style.display = 'block';
                    }
                })
                .catch(err => {
                    itemsEmpty.style.display = 'block';
                    itemsEmpty.innerText = 'Error loading items';
                });
        }

        function showItemDetail(item) {
            const img = item.image ? item.image.split(',')[0] : 'placeholder.png';
            const status = item.status.toLowerCase();
            
            document.getElementById('itemDetailImage').src = img;
            document.getElementById('itemDetailTitle').innerText = item.title;
            
            // Show sold price for sold items
            if (status === 'sold' && item.sold_price) {
                const soldPrice = parseFloat(item.sold_price).toFixed(2);
                const originalPrice = parseFloat(item.price).toFixed(2);
                if (soldPrice !== originalPrice) {
                    document.getElementById('itemDetailPrice').innerHTML = 'Sold: RM ' + soldPrice + ' <span style="text-decoration:line-through;color:var(--text-muted);font-size:12px;">(RM ' + originalPrice + ')</span>';
                } else {
                    document.getElementById('itemDetailPrice').innerText = 'Sold: RM ' + soldPrice;
                }
            } else {
                document.getElementById('itemDetailPrice').innerText = 'RM ' + parseFloat(item.price).toFixed(2);
            }
            
            document.getElementById('itemDetailBadge').innerText = item.status.toUpperCase();
            document.getElementById('itemDetailBadge').className = 'badge badge-' + item.status;
            document.getElementById('itemDetailDescription').innerText = item.description || 'No description';

            document.getElementById('itemDetailPanel').classList.add('show');
        }

        function closeItemDetail() {
            document.getElementById('itemDetailPanel').classList.remove('show');
        }

        function closeUserModal() {
            userModal.classList.remove('active');
        }

        userModal.addEventListener('click', e => { if (e.target === userModal) closeUserModal(); });
        
        function openBlacklistModal() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('custom-date').min = today;
            document.getElementById('blacklist-duration').value = '';
            document.getElementById('blacklist-reason').value = '';
            document.getElementById('custom-date').value = '';
            document.getElementById('custom-date-group').classList.remove('show');
            blacklistModal.classList.add('active');
        }
        
        function closeBlacklistModal() {
            blacklistModal.classList.remove('active');
        }
        
        function toggleCustomDate() {
            const duration = document.getElementById('blacklist-duration').value;
            const customGroup = document.getElementById('custom-date-group');
            customGroup.classList.toggle('show', duration === 'custom');
        }
        
        function confirmBlacklist() {
            const duration = document.getElementById('blacklist-duration').value;
            const reason = document.getElementById('blacklist-reason').value.trim();
            const customDate = document.getElementById('custom-date').value;
            
            if (!duration) { alert('Please select a duration'); return; }
            if (duration === 'custom' && !customDate) { alert('Please select an end date'); return; }
            
            const formData = new FormData();
            formData.append('blacklist_action', 'blacklist');
            formData.append('user_id', currentUser.UserID);
            formData.append('duration', duration);
            formData.append('reason', reason);
            if (duration === 'custom') formData.append('custom_date', customDate);
            
            fetch('admin_users.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('User blacklisted successfully');
                        closeBlacklistModal();
                        closeUserModal();
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => alert('An error occurred'));
        }
        
        function unblacklistUser() {
            if (!confirm('Remove blacklist from this user?')) return;
            
            const formData = new FormData();
            formData.append('blacklist_action', 'unblacklist');
            formData.append('user_id', currentUser.UserID);
            
            fetch('admin_users.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Blacklist removed');
                        closeUserModal();
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => alert('An error occurred'));
        }
        
        blacklistModal.addEventListener('click', e => { if (e.target === blacklistModal) closeBlacklistModal(); });
    </script>
</body>
</html>
