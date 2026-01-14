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

// --- REPORT SYSTEM ISSUE HANDLER ---
$msg = '';

// Check for flash message from redirect
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

if (isset($_POST['report_system_issue'])) {
    $reason = trim($_POST['reason']);
    $reportType = trim($_POST['report_type'] ?? 'help_support');
    
    if (!empty($reason)) {
        // Ensure report_type column exists
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'report_type'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE report ADD COLUMN report_type ENUM('item_report', 'user_report', 'inappropriate', 'spam', 'help_support', 'account_issue', 'scam_fraud', 'other') DEFAULT 'other' AFTER reportedItemID");
            }
        } catch (PDOException $e) {}
        
        // Insert with NULL for reportedUserID/ItemID and report_type
        $stmt = $pdo->prepare("INSERT INTO report (reason, submitDate, UserID, reportedUserID, reportedItemID, report_type) VALUES (?, NOW(), ?, NULL, NULL, ?)");
        $stmt->execute([$reason, $userid, $reportType]);
        
        // PRG Pattern: Set session message and redirect
        $_SESSION['flash_msg'] = "Report submitted successfully! The admin team will review it.";
        header("Location: help.php");
        exit;
    }
}

// Fetch User Info for Sidebar/Header
$stmt = $pdo->prepare("SELECT name, profile_image FROM users WHERE UserID = ?");
$stmt->execute([$userid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userAvatar = (!empty($user['profile_image']) && file_exists($user['profile_image'])) ? $user['profile_image'] : '';
$hasAvatar = !empty($userAvatar);

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
    header("Location: help.php"); exit;
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPES - Help Center</title>
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
    
    /* Indigo Theme */
    --accent: #4B0082; 
    --accent-hover: #33005c; 
    --accent-light: #e6d9ff; 
    --accent-dark: #33005c; 
    
    --danger: #ef4444;
    --success: #10b981;
}

/* --- ANIMATIONS --- */
@keyframes slideInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

* { box-sizing: border-box; }
body {
    font-family: 'Outfit', sans-serif;
    margin: 0;
    background: var(--bg);
    color: var(--text);
    overflow-x: hidden;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
a { text-decoration: none; color: inherit; }

/* --- HEADER --- */
.header {
    display: flex; align-items: center; padding: 16px 30px;
    background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 50;
    padding-right: 90px; /* Space for sidebar strip */
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    animation: slideInDown 0.4s ease-out;
}
.header .brand {
    flex: 1; font-weight: 900; font-size: 24px;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    text-decoration: none; display: block; cursor: pointer;
}
.controls { display: flex; gap: 15px; align-items: center; }
.avatar {
    width: 44px; height: 44px; border-radius: 50%; object-fit: cover;
    border: 2.5px solid var(--accent-light); transition: all 0.3s; cursor: pointer;
}
.avatar:hover { border-color: var(--accent); transform: scale(1.05); }

/* Letter Avatar */
.letter-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 600; color: var(--muted);
    background: var(--panel); border: 2.5px solid var(--accent-light);
    transition: all 0.3s; text-transform: uppercase;
}
.letter-avatar:hover { border-color: var(--accent); transform: scale(1.05); }

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

/* --- SIDEBAR (Optimized - width for desktop, transform for mobile) --- */
.right-sidebar { 
    position: fixed; top: 0; right: 0; width: 70px; height: 100vh; 
    background: #111111; color: white; z-index: 1000; 
    transition: width 0.3s ease-out;
    box-shadow: -5px 0 30px rgba(0,0,0,0.15); overflow: hidden; display: flex; flex-direction: column;
}
.right-sidebar.open { width: 300px; }
.sidebar-toggle-btn {
    width: 70px; height: 80px; display: flex; align-items: center; justify-content: center;
    background: transparent; border: none; color: white; cursor: pointer; font-size: 24px;
    flex-shrink: 0; transition: color 0.3s; position: absolute; top: 0; right: 0; z-index: 1002;
}
.sidebar-toggle-btn:hover { color: var(--accent); }
.sidebar-content { margin-top: 80px; opacity: 0; padding: 20px 30px; width: 300px; transition: opacity 0.2s ease; flex: 1; }
.right-sidebar.open .sidebar-content { opacity: 1; transition-delay: 0.1s; }
.sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 15px; }
.sidebar-link { 
    display: flex; align-items: center; gap: 16px; padding: 12px 0; color: #888; font-weight: 600; font-size: 16px; 
    transition: all 0.3s; border-bottom: 1px solid #222; text-decoration: none;
}
.sidebar-link:hover { color: white; padding-left: 10px; border-color: #444; }
.sidebar-icon { width: 22px; height: 22px; fill: currentColor; }
.sidebar-link.active { color: white; padding-left: 10px; border-color: var(--accent); }
.sidebar-link.active .sidebar-icon { fill: var(--accent); }
.menu-badge { background: white; color: black; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 900; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }

/* --- CONTENT --- */
.main-container {
    max-width: 1000px; margin: 0 auto; width: 100%;
    padding: 40px 20px; padding-right: 90px; 
    min-height: calc(100vh - 80px);
}

.page-header { margin-bottom: 40px; text-align: center; }
.page-header h1 { margin: 0 0 10px; font-size: 36px; font-weight: 900; color: var(--text); letter-spacing: -1px; }
.page-header p { margin: 0; color: var(--muted); font-size: 16px; max-width: 600px; margin: 0 auto; }

/* Help Sections */
.help-section { margin-bottom: 40px; animation: slideUp 0.5s ease-out; }
.section-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--border); }

/* Accordion */
.accordion { display: flex; flex-direction: column; gap: 12px; }
.accordion-item { border: 1px solid var(--border); border-radius: 12px; background: var(--panel); overflow: hidden; transition: all 0.3s; }
.accordion-item:hover { border-color: var(--accent); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.accordion-header { 
    width: 100%; padding: 18px 24px; background: none; border: none; text-align: left; 
    font-size: 16px; font-weight: 700; color: var(--text); cursor: pointer; 
    display: flex; justify-content: space-between; align-items: center;
}
.accordion-header:after { content: '+'; font-size: 20px; color: var(--accent); transition: transform 0.3s; }
.accordion-header.active:after { transform: rotate(45deg); }
.accordion-body { 
    padding: 0 24px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out, padding 0.3s ease; 
    color: var(--text); line-height: 1.6; font-size: 15px;
}
.accordion-header.active + .accordion-body { padding: 0 24px 24px; max-height: 500px; }

/* Contact Support Box */
.support-box { 
    background: linear-gradient(135deg, var(--accent), var(--accent-dark)); 
    border-radius: 16px; padding: 40px; text-align: center; color: white;
    box-shadow: 0 10px 30px rgba(75, 0, 130, 0.3); margin-top: 60px;
}
.support-box h3 { margin: 0 0 10px; font-size: 24px; font-weight: 800; }
.support-box p { opacity: 0.9; margin-bottom: 24px; font-size: 16px; }
.btn-support { 
    background: white; color: var(--accent); padding: 12px 30px; border-radius: 30px; 
    font-weight: 700; text-decoration: none; display: inline-block; transition: all 0.3s; cursor: pointer; border:none;
}
.btn-support:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }

/* Report Modal */
.modal-overlay-modal {position:fixed;inset:0;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:2000;opacity:0;pointer-events:none;transition:opacity 0.2s;}
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

.success-msg { background:#d1fae5; color:#065f46; padding:15px; border-radius:10px; margin-bottom:20px; font-weight:600; text-align:center; }
.form-group { margin-bottom:20px; text-align:left; }
.form-group label { display:block; margin-bottom:8px; font-weight:700; color:var(--text); }
.form-group textarea { width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; resize:vertical; font-family:inherit; }
.btn-submit { width:100%; padding:12px; background:var(--accent); color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer; }

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
    .main-container { padding: 20px 12px; }
    
    /* Page Header */
    .page-header { margin-bottom: 24px; }
    .page-header h1 { font-size: 24px; margin-bottom: 6px; }
    .page-header p { font-size: 13px; }
    
    /* Help Sections */
    .help-section { margin-bottom: 24px; }
    .section-title { font-size: 18px; margin-bottom: 14px; padding-bottom: 8px; }
    
    /* Accordion */
    .accordion { gap: 10px; }
    .accordion-item { border-radius: 10px; }
    .accordion-header { padding: 14px 16px; font-size: 14px; }
    .accordion-header:after { font-size: 18px; }
    .accordion-body { font-size: 13px; }
    .accordion-header.active + .accordion-body { padding: 0 16px 16px; }
    
    /* Support Box */
    .support-box { padding: 24px 18px; border-radius: 12px; margin-top: 30px; }
    .support-box h3 { font-size: 18px; }
    .support-box p { font-size: 13px; margin-bottom: 16px; }
    .btn-support { padding: 10px 24px; font-size: 13px; }
    
    /* Success Message */
    .success-msg { padding: 12px; font-size: 13px; border-radius: 8px; }
    
    /* Modals */
    .modal-content { max-width: 95%; max-height: 85vh; border-radius: 12px; }
    .modal-header { padding: 14px 18px; }
    .modal-header h3 { font-size: 16px; }
    .modal-body { padding: 16px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { font-size: 13px; margin-bottom: 6px; }
    .form-group textarea, .form-group select { padding: 10px; font-size: 13px; }
    .btn-submit { padding: 10px; font-size: 13px; }
    
    /* Saved Items */
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
            <line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line>
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
            <li><a href="help.php" class="sidebar-link active"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>Help</a></li>
            <li><a href="reports.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M15.73 3H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27L15.73 3zM12 17c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm1-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
    </div>
</aside>

<div class="main-container">
    <div class="page-header">
        <h1>Help Center</h1>
        <p>Everything you need to know about using Campus Preloved safely and effectively.</p>
    </div>

    <?php if($msg): ?><div class="success-msg"><?= h($msg) ?></div><?php endif; ?>

    <!-- Community Guidelines -->
    <div class="help-section">
        <h2 class="section-title">Community Guidelines</h2>
        <div class="accordion">
            <div class="accordion-item">
                <button class="accordion-header">Respect & Professional Conduct</button>
                <div class="accordion-body">
                    <p><strong>Campus Preloved E-Shop</strong> is a trusted marketplace for UTHM students. All users must:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Communicate respectfully with buyers and sellers</li>
                        <li>Respond to messages within a reasonable timeframe</li>
                        <li>Honor agreed prices and meetup arrangements</li>
                        <li>Report any suspicious activity immediately</li>
                    </ul>
                    <p>Harassment, hate speech, discrimination, or abusive language will result in account suspension or permanent ban.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">Prohibited Items & Content</button>
                <div class="accordion-body">
                    <p>The following items are <strong>strictly prohibited</strong> on Campus Preloved E-Shop:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Weapons, firearms, or replica weapons</li>
                        <li>Illegal substances, drugs, or alcohol</li>
                        <li>Counterfeit goods, pirated materials, or stolen property</li>
                        <li>Hazardous or flammable materials</li>
                        <li>Academic materials that promote cheating (e.g., assignment answers)</li>
                        <li>Adult content or inappropriate imagery</li>
                        <li>Items violating UTHM or university policies</li>
                    </ul>
                    <p>Violations will result in item removal and potential account action.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">Safe Trading & Meetups</button>
                <div class="accordion-body">
                    <p>For your safety when conducting transactions:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li><strong>Meet in public campus areas</strong> - library, cafeteria, student center</li>
                        <li><strong>Daytime meetings</strong> - avoid late night transactions</li>
                        <li><strong>Bring a friend</strong> - especially for high-value items</li>
                        <li><strong>Inspect before payment</strong> - check item condition carefully</li>
                        <li><strong>No off-campus deliveries</strong> - keep transactions within UTHM grounds</li>
                        <li><strong>Use platform messaging</strong> - keep all communication on Campus Preloved E-Shop</li>
                    </ul>
                    <p>Campus Preloved E-Shop is not responsible for damages from off-platform transactions.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">Listing Accuracy & Honesty</button>
                <div class="accordion-body">
                    <p>All sellers must provide accurate information:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Use real, clear photos of your actual item</li>
                        <li>Describe condition honestly (New, Like New, Good, Fair)</li>
                        <li>State any defects, damages, or issues clearly</li>
                        <li>Set fair and reasonable prices</li>
                        <li>Remove or mark items as sold when no longer available</li>
                    </ul>
                    <p>Misleading listings will be removed and may result in account restrictions.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Policy -->
    <div class="help-section">
        <h2 class="section-title">Data & Privacy Policy</h2>
        <div class="accordion">
            <div class="accordion-item">
                <button class="accordion-header">Information We Collect</button>
                <div class="accordion-body">
                    <p><strong>Campus Preloved E-Shop</strong> collects the following data:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li><strong>Account Information:</strong> Name, matric number, email address, password (encrypted)</li>
                        <li><strong>Profile Data:</strong> Profile picture, bio, contact preferences</li>
                        <li><strong>Listing Data:</strong> Item titles, descriptions, photos, prices, categories</li>
                        <li><strong>Transaction History:</strong> Items bought/sold, messages exchanged</li>
                        <li><strong>Usage Data:</strong> Login times, page views, saved items</li>
                    </ul>
                    <p>We do <strong>NOT</strong> collect payment or banking information.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">How We Use Your Data</button>
                <div class="accordion-body">
                    <p>Your data is used exclusively for:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Facilitating marketplace transactions between students</li>
                        <li>Displaying your listings to potential buyers</li>
                        <li>Enabling communication between buyers and sellers</li>
                        <li>Verifying UTHM student identity (matric number)</li>
                        <li>Improving platform features and user experience</li>
                        <li>Ensuring community safety and moderation</li>
                    </ul>
                    <p>We <strong>never sell, share, or trade</strong> your personal data with third parties.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">Data Security & Protection</button>
                <div class="accordion-body">
                    <p>We take security seriously:</p>
                    <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Passwords are encrypted using industry-standard hashing</li>
                        <li>All data is stored securely on protected servers</li>
                        <li>User sessions are managed with secure authentication</li>
                        <li>Admin access is restricted and monitored</li>
                    </ul>
                    <p>Report any security concerns immediately via the Help section.</p>
                </div>
            </div>
            <div class="accordion-item">
                <button class="accordion-header">Account Deletion & Data Removal</button>
                <div class="accordion-body">
                    <p>To request account deletion:</p>
                    <ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
                        <li>Go to the <strong>Reports</strong> section</li>
                        <li>Select <strong>"Account Issue"</strong> as report type</li>
                        <li>Include "Account Deletion Request" in your description</li>
                        <li>Admin will process within 7 working days</li>
                    </ol>
                    <p>Upon deletion, all your listings, messages, and profile data will be permanently removed from our system.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Box -->
    <div class="support-box">
        <h3>Still need help?</h3>
        <p>If you have found a system issue, bug, or require assistance, please let us know.</p>
        <button class="btn-support" onclick="openSystemReport()">Report System Issue</button>
    </div>

</div>

<!-- System Report Modal -->
<div class="modal-overlay-modal" id="reportModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Report System Issue</h3>
            <button class="modal-close" onclick="document.getElementById('reportModal').classList.remove('is-visible')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="report_system_issue" value="1">
                <div class="form-group">
                    <label>Report Type</label>
                    <select name="report_type" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-family:inherit; font-size:15px;">
                        <option value="help_support">Help/Support (Bug Report, Feature Request)</option>
                        <option value="inappropriate">Inappropriate Content (Offensive, Hate Speech)</option>
                        <option value="spam">Spam (Duplicate, Promotional)</option>
                        <option value="account_issue">Account Issue (Security, Unauthorized Access)</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Description of Issue</label>
                    <textarea name="reason" rows="5" required placeholder="Describe the bug or issue you are facing..."></textarea>
                </div>
                <button type="submit" class="btn-submit">Submit Report</button>
            </form>
        </div>
    </div>
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
function openSystemReport() { document.getElementById('reportModal').classList.add('is-visible'); }
function toggleSidebar() {
    const s = document.getElementById('sidebar'); 
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open'); 
    o.classList.toggle('active');
}
document.getElementById('savedModal').addEventListener('click', (e)=>{if(e.target.id==='savedModal')document.getElementById('savedModal').classList.remove('is-visible');});
document.getElementById('reportModal').addEventListener('click', (e)=>{if(e.target.id==='reportModal')document.getElementById('reportModal').classList.remove('is-visible');});

// Accordion Logic
const acc = document.getElementsByClassName("accordion-header");
for (let i = 0; i < acc.length; i++) {
    acc[i].addEventListener("click", function() {
        this.classList.toggle("active");
        const panel = this.nextElementSibling;
        if (panel.style.maxHeight) {
            panel.style.maxHeight = null;
        } else {
            panel.style.maxHeight = panel.scrollHeight + "px";
        }
    });
}
</script>
</body>
</html>