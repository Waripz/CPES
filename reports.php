<?php
session_start();
if (!isset($_SESSION['UserID'])) { header('Location: index.html'); exit; }

$userid = (int)$_SESSION['UserID'];

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/saved_items_helper.php';
} catch (Exception $e) { echo "Database connection failed."; exit; }

// Fetch User Info
$stmt = $pdo->prepare("SELECT name, profile_image FROM users WHERE UserID = ?");
$stmt->execute([$userid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userAvatar = (!empty($user['profile_image']) && file_exists($user['profile_image'])) ? $user['profile_image'] : '';
$hasAvatar = !empty($userAvatar);

// Check if report_type column exists, if not add it
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'report_type'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE report ADD COLUMN report_type ENUM('item_report', 'user_report', 'inappropriate', 'spam', 'help_support', 'account_issue', 'scam_fraud', 'other') DEFAULT 'other' AFTER reportedItemID");
    }
} catch (PDOException $e) {
    // Ignore errors
}

// Fetch Reports Made by User
$reportStmt = $pdo->prepare("
    SELECT r.reportID, r.reason, r.submitDate, r.reportedUserID, r.reportedItemID, 
           COALESCE(r.report_type, 'other') as report_type,
           u.name as reported_user_name, u.profile_image as reported_user_avatar,
           i.title as reported_item_title, i.image as reported_item_image
    FROM report r
    LEFT JOIN users u ON r.reportedUserID = u.UserID
    LEFT JOIN item i ON r.reportedItemID = i.ItemID
    WHERE r.UserID = ?
    ORDER BY r.submitDate DESC
");
$reportStmt->execute([$userid]);
$allReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$unread = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0");
    $stmt->execute([$userid]);
    $unread = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Fetch Saved Items (For the header icon modal)
$savedItems = saved_fetch_items($pdo, $userid);

// Handle Unsave (if triggered from modal)
if (isset($_POST['unsave_id'])) {
    saved_remove($pdo, $userid, (int)$_POST['unsave_id']);
    header("Location: reports.php"); exit;
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Reports — Campus Preloved E-Shop</title>
<link rel="icon" type="image/png" href="letter-w.png">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    /* INDIGO & WHITE THEME */
    --bg: linear-gradient(135deg, #f8f9fb 0%, #f3f0fb 100%);
    --panel: #ffffff;
    --text: #1a202c;
    --muted: #718096;
    --border: #e2e8f0;
    
    /* Updated to Indigo #4B0082 */
    --accent: #4B0082; 
    --accent-hover: #33005c; 
    --accent-light: #e6d9ff; 
    --accent-dark: #33005c; 
    
    --danger: #ef4444; 
    --success: #10b981; 
}

/* --- ANIMATIONS --- */
@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

* { box-sizing: border-box; }
body {
    font-family: 'Outfit', sans-serif;
    margin: 0;
    background: var(--bg);
    color: var(--text);
    overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }

/* --- HEADER --- */
.header {
    display: flex;
    align-items: center;
    padding: 16px 30px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 50;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    animation: slideInDown 0.4s ease-out;
    padding-right: 90px; /* Space for the black sidebar strip */
}
.header .brand {
    flex: 1;
    font-weight: 900;
    font-size: 24px;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    text-decoration: none;
    display: block;
    cursor: pointer;
}
.controls {
    display: flex;
    gap: 15px;
    align-items: center;
}
.avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid var(--accent-light);
    transition: all 0.3s;
    cursor: pointer;
}
.avatar:hover {
    border-color: var(--accent);
    transform: scale(1.05);
    box-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
}

/* Letter Avatar */
.letter-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 600;
    color: var(--muted);
    background: var(--panel);
    border: 2.5px solid var(--accent-light);
    transition: all 0.3s;
    text-transform: uppercase;
}
.letter-avatar:hover {
    border-color: var(--accent);
    transform: scale(1.05);
    box-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
}

/* Mobile Menu Button */
.mobile-menu-btn {
    display: none;
    position: relative;
    width: 40px; height: 40px;
    align-items: center; justify-content: center;
    background: #1a1a2e; border: none; border-radius: 10px;
    cursor: pointer; color: white; margin-left: 12px;
}
.mobile-menu-btn:hover { background: var(--accent); }
.mobile-menu-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 18px;
    height: 18px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    font-size: 10px;
    font-weight: 700;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    animation: pulse 2s infinite;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
}
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

/* --- BLACK STRIP SIDEBAR (Optimized - width for desktop) --- */
.right-sidebar { 
    position: fixed; 
    top: 0; 
    right: 0; 
    width: 70px; 
    height: 100vh; 
    background: #111111; 
    color: white; 
    z-index: 1000; 
    transition: width 0.3s ease-out;
    box-shadow: -5px 0 30px rgba(0,0,0,0.15);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* Expanded State */
.right-sidebar.open { width: 300px; }

/* Hamburger in Strip */
.sidebar-toggle-btn {
    width: 70px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: white;
    cursor: pointer;
    font-size: 24px;
    flex-shrink: 0;
    transition: color 0.3s;
    position: absolute;
    top: 0;
    right: 0;
    z-index: 1002;
}
.sidebar-toggle-btn:hover { color: var(--accent); }

/* Sidebar Content */
.sidebar-content {
    margin-top: 80px; 
    opacity: 0; 
    padding: 20px 30px;
    width: 300px;
    transition: opacity 0.2s ease;
    flex: 1;
}
.right-sidebar.open .sidebar-content { opacity: 1; transition-delay: 0.1s; }

/* Menu Links */
.sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 15px; }
.sidebar-link { 
    display: flex; align-items: center; gap: 16px; 
    padding: 12px 0; 
    color: #888; 
    font-weight: 600; font-size: 16px; 
    transition: all 0.3s; 
    border-bottom: 1px solid #222;
    text-decoration: none;
}
.sidebar-link:hover { color: white; padding-left: 10px; border-color: #444; }
.sidebar-icon { width: 22px; height: 22px; fill: currentColor; }

/* Active State */
.sidebar-link.active {
    color: white;
    padding-left: 10px;
    border-color: var(--accent);
}
.sidebar-link.active .sidebar-icon { fill: var(--accent); }

.menu-badge { background: white; color: black; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: auto; }

/* Sidebar Overlay */
.sidebar-overlay { 
    position: fixed; inset: 0; background: rgba(0,0,0,0.6); 
    z-index: 900; opacity: 0; pointer-events: none; 
    transition: opacity 0.3s ease;
}
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }


/* --- LAYOUT ADJUSTMENT --- */
.main-container {
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    /* Replaced grid with block + padding for fixed sidebar */
    padding: 40px 20px;
    padding-right: 90px; 
    min-height: calc(100vh - 80px);
}

/* --- CONTENT AREA --- */
.content-area { 
    /* Removed border and padding specific to old grid layout */
    width: 100%;
    animation: slideUp 0.5s ease-out;
}

.page-header { margin-bottom: 30px; }
.page-header h1 { 
    margin: 0 0 8px; 
    font-size: 32px; 
    font-weight: 900; 
    color: var(--text); 
    letter-spacing: -0.5px; 
}
.page-header p { margin: 0; color: var(--muted); font-size: 15px; }

/* --- REPORTS LIST --- */
.reports-list { display: flex; flex-direction: column; gap: 20px; }
.report-card {
    background: white; border: 1px solid var(--border); border-radius: 16px; padding: 24px;
    transition: all 0.2s; display: flex; flex-direction: column; gap: 16px;
}
.report-card:hover {
    border-color: var(--accent);
    box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.report-header { display: flex; align-items: center; gap: 16px; }
.report-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border); }
.letter-avatar-report { width: 48px; height: 48px; border-radius: 50%; background: var(--panel); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; color: var(--muted); }
.report-user-info { flex: 1; }
.report-user-name { margin: 0; font-size: 16px; font-weight: 700; color: var(--text); }
.report-date { margin: 0; font-size: 13px; color: var(--muted); }
.report-status {
    display: inline-block; background: #ecfdf5; border: 1px solid #a7f3d0; color: #059669;
    font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 20px; text-transform: uppercase;
}

.report-reason {
    background: var(--panel); padding: 16px; border-radius: 12px; border-left: 4px solid var(--accent);
}
.report-reason-label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; display: block; margin-bottom: 6px; }
.report-reason-text { color: var(--text); font-size: 15px; line-height: 1.6; }

.report-id { font-size: 12px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 12px; margin-top: 4px; }

.empty-state { 
    background: var(--panel); border: 1px dashed var(--border); border-radius: 16px; 
    padding: 60px 40px; text-align: center; 
}
.empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
.empty-state-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.empty-state-text { color: var(--muted); font-size: 15px; }

/* Saved Items Modal */
.modal-overlay-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:2000;opacity:0;pointer-events:none;transition:opacity 0.2s;}
.modal-overlay-modal.is-visible{opacity:1;pointer-events:auto;}
.modal-content{background:white;border-radius:16px;width:100%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;border:1px solid var(--border);}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{margin:0;color:#111827;font-weight:800;}
.modal-close{background:none;border:none;color:var(--muted);font-size:24px;cursor:pointer;}
.modal-body{padding:0;overflow-y:auto;}
.saved-item{display:flex;gap:15px;padding:15px 24px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);align-items:center;}
.saved-item:hover{background:#f9fafb;}
.saved-img{width:60px;height:60px;border-radius:8px;object-fit:cover;border:1px solid var(--border);}
.saved-info{flex:1;}
.unsave-btn{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:5px;}
.unsave-btn:hover{color:var(--danger);}
.empty-saved{padding:40px;text-align:center;color:var(--muted);}

/* Mobile sidebar - GPU accelerated */
@media (max-width: 768px) {
    .right-sidebar { width: 300px; transform: translateX(100%); transition: transform 0.3s ease-out; }
    .right-sidebar.open { transform: translateX(0); }
    .sidebar-toggle-btn { display: none; }
    .mobile-menu-btn { display: flex !important; }
    .header { padding-right: 20px; }
    .main-container { padding-right: 20px; }
}

/* Mobile optimization for 480px */
@media (max-width: 480px) {
    /* Header */
    .header { padding: 12px 16px; padding-right: 20px; }
    .header .brand { font-size: 16px; }
    .avatar, .letter-avatar { width: 32px; height: 32px; font-size: 14px; }
    
    /* Main Container */
    .main-container { padding: 16px 12px; padding-right: 20px; }
    
    /* Page Header */
    .page-header { margin-bottom: 20px; }
    .page-header h1 { font-size: 22px; margin-bottom: 4px; }
    .page-header p { font-size: 13px; }
    
    /* Report Cards */
    .reports-list { gap: 14px; }
    .report-card { padding: 16px; border-radius: 12px; gap: 12px; }
    
    /* Report Header */
    .report-header { gap: 10px; flex-wrap: wrap; }
    .report-avatar, .letter-avatar-report { width: 40px; height: 40px; font-size: 16px; }
    .report-user-name { font-size: 14px; }
    .report-date { font-size: 11px; }
    .report-status { font-size: 9px; padding: 3px 8px; }
    
    /* Report Reason */
    .report-reason { padding: 12px; border-radius: 10px; }
    .report-reason-label { font-size: 10px; margin-bottom: 4px; }
    .report-reason-text { font-size: 13px; }
    
    /* Report ID */
    .report-id { font-size: 10px; padding-top: 10px; }
    
    /* Empty State */
    .empty-state { padding: 40px 20px; }
    .empty-state-icon { font-size: 36px; }
    .empty-state-title { font-size: 16px; }
    .empty-state-text { font-size: 13px; }
    
    /* Modal */
    .modal-content { max-width: 95%; max-height: 85vh; border-radius: 12px; }
    .modal-header { padding: 14px 18px; }
    .modal-header h3 { font-size: 16px; }
    .saved-item { padding: 12px 16px; gap: 10px; }
    .saved-img { width: 50px; height: 50px; }
}
</style>
</head>
<body>

<header class="header">
    <a href="home.php" class="brand">Campus Preloved E-Shop</a>
    <div class="controls">
        <a href="profile.php">
            <?php if ($hasAvatar): ?>
                <img src="<?= h($userAvatar) ?>" class="avatar">
            <?php else: ?>
                <div class="letter-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <?php endif; ?>
        </a>
        <button class="mobile-menu-btn" onclick="toggleSidebar()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
            <?php if($unread > 0): ?>
                <span class="mobile-menu-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
            <?php endif; ?>
        </button>
    </div>
</header>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<aside class="right-sidebar" id="sidebar">
    <button class="sidebar-toggle-btn" onclick="toggleSidebar()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>
    <div class="sidebar-content">
        <h3 style="color:white; margin-bottom:20px; font-size:24px;">Menu</h3>
        <ul class="sidebar-menu">
            <li><a href="home.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Home</a></li>
            <li><a href="profile.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Profile</a></li>
            <li><a href="messages.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>Messages <?php if($unread>0): ?><span class="menu-badge"><?= $unread ?></span><?php endif; ?></a></li>
            <li><a href="create.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>Create List/Event</a></li>
            <li><a href="#" class="sidebar-link" onclick="event.preventDefault(); openSavedModal(); toggleSidebar();"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>Bookmarks</a></li>
            <li><a href="help.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>Help</a></li>
            <li><a href="reports.php" class="sidebar-link active"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M15.73 3H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27L15.73 3zM12 17c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm1-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
    </div>
</aside>

<div class="main-container">
    <main class="content-area">
        <div class="page-header">
            <h1>My Reports</h1>
            <p>View all reports you've submitted about users or items.</p>
        </div>

        <?php if (empty($allReports)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <div class="empty-state-title">No Reports Yet</div>
                <div class="empty-state-text">You haven't submitted any reports.</div>
            </div>
        <?php else: ?>
            <div class="reports-list">
                <?php foreach ($allReports as $report): 
                    // Only show time if it's not midnight (00:00:00)
                    $submitTime = date('H:i:s', strtotime($report['submitDate']));
                    if ($submitTime === '00:00:00') {
                        $reportDate = date('M d, Y', strtotime($report['submitDate']));
                    } else {
                        $reportDate = date('M d, Y - H:i', strtotime($report['submitDate']));
                    }
                    
                    // Determine display type based on report_type and data
                    $reportType = '';
                    $typeColors = [
                        'item_report' => ['bg' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#059669', 'label' => 'Item Report'],
                        'user_report' => ['bg' => '#fef3c7', 'border' => '#fcd34d', 'text' => '#d97706', 'label' => 'User Report'],
                        'inappropriate' => ['bg' => '#fee2e2', 'border' => '#fecaca', 'text' => '#dc2626', 'label' => 'Inappropriate'],
                        'spam' => ['bg' => '#f3e8ff', 'border' => '#e9d5ff', 'text' => '#9333ea', 'label' => 'Spam'],
                        'help_support' => ['bg' => '#dbeafe', 'border' => '#bfdbfe', 'text' => '#1e40af', 'label' => 'Help/Support'],
                        'account_issue' => ['bg' => '#f5d5ff', 'border' => '#f0abfc', 'text' => '#c2185b', 'label' => 'Account Issue'],
                        'other' => ['bg' => '#f0f0f0', 'border' => '#d0d0d0', 'text' => '#666', 'label' => 'Other']
                    ];
                    
                    $type = $report['report_type'] ?? 'other';
                    $reportTypeInfo = $typeColors[$type] ?? $typeColors['other'];
                    
                    // Fallback logic for legacy data
                    if ($type === 'other' || empty($report['report_type'])) {
                        if (!empty($report['reportedItemID'])) {
                            $type = 'item_report';
                            $reportTypeInfo = $typeColors['item_report'];
                        } elseif (!empty($report['reportedUserID'])) {
                            $type = 'user_report';
                            $reportTypeInfo = $typeColors['user_report'];
                        }
                    }
                    
                    $isUserReport = !empty($report['reportedUserID']);
                    $reportedAvatar = '';
                    $reportedName = '';
                    $hasReportAvatar = false;
                    
                    if ($isUserReport) {
                        $reportedAvatar = !empty($report['reported_user_avatar']) && file_exists($report['reported_user_avatar']) ? $report['reported_user_avatar'] : '';
                        $hasReportAvatar = !empty($reportedAvatar);
                        $reportedName = h($report['reported_user_name']);
                    } else {
                        $img = !empty($report['reported_item_image']) ? explode(',', $report['reported_item_image'])[0] : '';
                        $reportedAvatar = !empty($img) && file_exists($img) ? $img : '';
                        $hasReportAvatar = !empty($reportedAvatar);
                        $reportedName = h($report['reported_item_title']);
                    }
                    $reportLetter = strtoupper(substr($reportedName, 0, 1));
                ?>
                    <div class="report-card">
                        <div class="report-header">
                            <?php if ($hasReportAvatar): ?>
                                <img src="<?= h($reportedAvatar) ?>" alt="" class="report-avatar">
                            <?php else: ?>
                                <div class="letter-avatar-report"><?= $reportLetter ?></div>
                            <?php endif; ?>
                            <div class="report-user-info">
                                <p class="report-user-name"><?= $reportedName ?></p>
                                <p class="report-date"><?= $reportDate ?></p>
                            </div>
                            <span class="report-status" style="background: <?= $reportTypeInfo['bg'] ?>; border-color: <?= $reportTypeInfo['border'] ?>; color: <?= $reportTypeInfo['text'] ?>;">
                                <?= $reportTypeInfo['label'] ?>
                            </span>
                        </div>

                        <div class="report-reason">
                            <span class="report-reason-label">Report Reason</span>
                            <div class="report-reason-text"><?= h($report['reason']) ?></div>
                        </div>

                        <div class="report-id">Report ID: #<?= str_pad($report['reportID'], 6, '0', STR_PAD_LEFT) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<div class="modal-overlay-modal" id="savedModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Saved Items</h3><button class="modal-close" onclick="document.getElementById('savedModal').classList.remove('is-visible')">&times;</button></div>
        <div class="modal-body">
            <?php if(empty($savedItems)): ?><div class="empty-saved">No saved items yet.</div><?php else: foreach($savedItems as $sv): 
                $sImg = !empty($sv['image']) ? explode(',', $sv['image'])[0] : 'uploads/avatars/default.png';
            ?>
                <div class="saved-item">
                    <a href="item_detail.php?id=<?= $sv['ItemID'] ?>" style="display:flex;gap:15px;flex:1;text-decoration:none;color:inherit;align-items:center;">
                        <img src="<?= h($sImg) ?>" class="saved-img">
                        <div class="saved-info"><div style="font-weight:700;font-size:15px;"><?= h($sv['title']) ?></div><div style="color:var(--accent);font-weight:700;">RM <?= number_format($sv['price'], 2) ?></div></div>
                    </a>
                    <form method="POST" onsubmit="return confirm('Unsave?');"><input type="hidden" name="unsave_id" value="<?= $sv['ItemID'] ?>"><button type="submit" class="unsave-btn">✕</button></form>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<script>
function openSavedModal(){ document.getElementById('savedModal').classList.add('is-visible'); }
// New Sidebar Toggle
function toggleSidebar() {
    const s = document.getElementById('sidebar'); 
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open'); 
    o.classList.toggle('active');
}

// Stagger card animations on load
window.addEventListener('load', () => {
    const cards = document.querySelectorAll('.report-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.animation = `slideUp 0.5s ease-out ${index * 0.05}s forwards`;
    });
});

document.getElementById('savedModal').addEventListener('click', (e)=>{if(e.target.id==='savedModal')document.getElementById('savedModal').classList.remove('is-visible');});
</script>
</body>
</html>