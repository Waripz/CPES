<?php
session_start();

// 1. Validate ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "Invalid item id."; exit; }

// 2. Database Connection
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/saved_items_helper.php';
    date_default_timezone_set('Asia/Kuala_Lumpur');
} catch (Exception $e) { echo "Database connection failed."; exit; }

// 3. Handle Save/Unsave Action
$userid = $_SESSION['UserID'] ?? 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_save'])) {
    if (!$userid) { header("Location: index.html"); exit; }
    $savedIds = saved_get_ids($pdo, $userid);
    if (in_array($id, $savedIds, true)) {
        saved_remove($pdo, $userid, $id);
    } else {
        saved_add($pdo, $userid, $id);
    }
    header("Location: item_detail.php?id=" . $id);
    exit;
}

// 4. Fetch Item Data
$stmt = $pdo->prepare("SELECT * FROM item WHERE ItemID = ? LIMIT 1");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) { echo "Item not found."; exit; }

// Increment Views
if ($userid && $userid != $item['UserID']) {
    $pdo->prepare("UPDATE item SET views = views + 1 WHERE ItemID = ?")->execute([$id]);
}

// Handle "Make Offer"
$offerSent = false;
if (isset($_SESSION['flash_offer_success'])) {
    $offerSent = true;
    unset($_SESSION['flash_offer_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_offer'])) {
    if (!$userid) { header('Location: index.html'); exit; }
    
    $offerAmount = (float)$_POST['offer_amount'];
    $sellerID = $item['UserID'];
    
    $itemImg = null;
    if (!empty($item['image'])) {
        $itemImg = explode(',', $item['image'])[0];
    }

    $msgText = "I would like to offer RM " . number_format($offerAmount, 2) . " for '{$item['title']}'.";

    // If message.context_info exists, store ItemID as context to keep this in the item thread
    $hasContextInfo = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM message LIKE 'context_info'")->fetch();
        $hasContextInfo = (bool)$col;
    } catch (Exception $e) {}

    if ($hasContextInfo) {
        $ts = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO message (senderID, recipientUserID, message, attachment_image, timestamp, is_read, context_info) VALUES (?, ?, ?, ?, ?, 0, ?)");
        $stmt->execute([$userid, $sellerID, $msgText, $itemImg, $ts, (string)$id]);
    } else {
        $ts = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO message (senderID, recipientUserID, message, attachment_image, timestamp, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$userid, $sellerID, $msgText, $itemImg, $ts]);
    }
    
    
    $_SESSION['flash_offer_success'] = true;
    header("Location: item_detail.php?id=" . $id);
    exit;
}

// Handle Item Report
$reportSent = false;
if (isset($_SESSION['flash_report_success'])) {
    $reportSent = true;
    unset($_SESSION['flash_report_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_item_report'])) {
    if (!$userid) { header('Location: index.html'); exit; }
    
    $reportReason = trim($_POST['report_reason'] ?? '');
    $reportType = trim($_POST['report_type'] ?? 'item_report');
    
    if (!empty($reportReason)) {
        // Ensure report_type column exists
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM report LIKE 'report_type'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE report ADD COLUMN report_type ENUM('item_report', 'user_report', 'inappropriate', 'spam', 'help_support', 'account_issue', 'scam_fraud', 'other') DEFAULT 'other' AFTER reportedItemID");
            }
        } catch (PDOException $e) {}
        
        $stmt = $pdo->prepare("INSERT INTO report (reason, submitDate, UserID, reportedItemID, report_type) VALUES (?, NOW(), ?, ?, ?)");
        $stmt->execute([$reportReason, $userid, $id, $reportType]);
        $stmt->execute([$reportReason, $userid, $id, $reportType]);
        $_SESSION['flash_report_success'] = true;
        header("Location: item_detail.php?id=" . $id);
        exit;
    }
}

// Check if Event
$isEvent = ($item['category'] === 'Events');

// Check if Saved
$isSaved = false;
if ($userid) {
    $savedIds = saved_get_ids($pdo, $userid);
    $isSaved = in_array($id, $savedIds, true);
}

// Fetch Seller
$seller = null;
if (!empty($item['UserID'])) {
    $s = $pdo->prepare("SELECT UserID,name,email,matricNo,role,profile_image FROM users WHERE UserID = ? LIMIT 1");
    $s->execute([$item['UserID']]);
    $seller = $s->fetch(PDO::FETCH_ASSOC);
}

// Fetch Similar Items
$similarItems = [];
try {
    $simStmt = $pdo->prepare("SELECT ItemID, title, price, image FROM item WHERE category = ? AND ItemID != ? AND status = 'available' LIMIT 3");
    $simStmt->execute([$item['category'], $id]);
    $similarItems = $simStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Images Logic
$images = [];
if (!empty($item['image'])) {
    $raw = $item['image'];
    $parts = preg_split('/\s*,\s*/', trim($raw));
    foreach ($parts as $p) { if ($p !== '') $images[] = $p; }
}
if (empty($images)) { $images[] = 'uploads/avatars/default.png'; }

// Header Logic & Current User Image
$name = $_SESSION['name'] ?? 'User';
$unread = 0;
$currentUserImg = ''; 
$hasAvatar = false;
$avatarLetter = strtoupper(substr($name, 0, 1));

if ($userid) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0");
        $stmt->execute([$userid]);
        $unread = (int)$stmt->fetchColumn();

        $uStmt = $pdo->prepare("SELECT profile_image FROM users WHERE UserID = ? LIMIT 1");
        $uStmt->execute([$userid]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow && !empty($uRow['profile_image']) && file_exists($uRow['profile_image'])) {
            $currentUserImg = $uRow['profile_image'];
            $hasAvatar = true;
        }
    } catch (Exception $e) {}
}

// Fetch Saved Items (For Sidebar Modal)
$savedItems = $userid ? saved_fetch_items($pdo, $userid) : [];

// Handle Unsave Modal
if ($userid && isset($_POST['unsave_id_modal'])) {
    saved_remove($pdo, $userid, (int)$_POST['unsave_id_modal']);
    header("Location: item_detail.php?id=" . $id); exit;
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($item['title']) ?></title>
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
* { box-sizing: border-box; }
body { font-family: 'Outfit', sans-serif; margin: 0; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
@keyframes slideInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.header { display: flex; align-items: center; padding: 16px 30px; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); flex-shrink: 0; animation: slideInDown 0.4s ease-out; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); gap: 16px; padding-right: 90px; }
.header .brand { flex: 1; font-weight: 900; font-size: 24px; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; text-decoration: none; display: block; cursor: pointer; }
.controls { flex: 1; display: flex; justify-content: flex-end; gap: 16px; align-items: center; }
.avatar-small { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2.5px solid var(--accent-light); transition: all 0.3s; cursor: pointer; }
.avatar-small:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }
.letter-avatar-small { width: 40px; height: 40px; border-radius: 50%; background: var(--panel); border: 2.5px solid var(--accent-light); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; color: var(--muted); cursor: pointer; transition: all 0.3s; }
.letter-avatar-small:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }
.letter-avatar-seller { width: 60px; height: 60px; border-radius: 50%; background: var(--panel); border: 3px solid var(--accent-light); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; color: var(--muted); transition: all 0.3s; }
.badge { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 800; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
.controls a { color: var(--muted); text-decoration: none; font-weight: 600; transition: color 0.3s; }
.controls a:hover { color: var(--accent); }

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

/* Sidebar (Optimized - width for desktop, transform for mobile) */
.right-sidebar { position: fixed; top: 0; right: 0; width: 70px; height: 100vh; background: #111111; color: white; z-index: 1000; transition: width 0.3s ease-out; box-shadow: -5px 0 30px rgba(0,0,0,0.15); overflow: hidden; display: flex; flex-direction: column; }
.right-sidebar.open { width: 300px; }
.sidebar-toggle-btn { width: 70px; height: 80px; display: flex; align-items: center; justify-content: center; background: transparent; border: none; color: white; cursor: pointer; font-size: 24px; flex-shrink: 0; transition: color 0.3s; position: absolute; top: 0; right: 0; z-index: 1002; }
.sidebar-toggle-btn:hover { color: var(--accent); }
.sidebar-content { margin-top: 80px; opacity: 0; padding: 20px 30px; width: 300px; transition: opacity 0.2s ease; flex: 1; }
.right-sidebar.open .sidebar-content { opacity: 1; transition-delay: 0.1s; }
.sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 15px; }
.sidebar-link { display: flex; align-items: center; gap: 16px; padding: 12px 0; color: #888; font-weight: 600; font-size: 16px; transition: all 0.3s; border-bottom: 1px solid #222; text-decoration: none; }
.sidebar-link:hover { color: white; padding-left: 10px; border-color: #444; }
.sidebar-icon { width: 22px; height: 22px; fill: currentColor; }
.sidebar-link.active { color: white; padding-left: 10px; border-color: var(--accent); }
.sidebar-link.active .sidebar-icon { fill: var(--accent); }
.menu-badge { background: white; color: black; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 900; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }

/* Main Layout */
.main-container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; padding-right: 90px; width: 100%; flex: 1; }
.back { display: inline-block; margin-bottom: 20px; color: var(--accent); text-decoration: none; font-weight: 600; transition: all 0.3s; animation: slideUp 0.4s; }
.back:hover { color: var(--accent-dark); transform: translateX(-4px); }

/* Breadcrumbs */
.breadcrumbs { margin-bottom: 20px; color: var(--muted); font-size: 14px; }
.breadcrumbs a { color: var(--accent); text-decoration: none; font-weight: 600; }
.breadcrumbs a:hover { text-decoration: underline; }

.gallery { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); animation: slideUp 0.4s 0.1s both; }
.main-image { height: 500px; background: linear-gradient(135deg, #f8f9fb, #f3f0fb); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid var(--border); margin-bottom: 16px; position:relative; }
.main-image img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; transition: opacity 0.3s ease; }
/* Share Button Overlay */
.share-btn-overlay { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.9); border: 1px solid var(--border); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s; z-index: 10; color: var(--text); }
.share-btn-overlay:hover { background: var(--accent); color: white; transform: scale(1.1); }

.thumbs-wrap { overflow-x: auto; white-space: nowrap; padding: 12px 0 4px; scrollbar-width: none; }
.thumbs { display: flex; gap: 12px; }
.thumb { width: 80px; height: 80px; border: 2px solid var(--border); border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--panel); flex-shrink: 0; opacity: 0.6; transition: all 0.3s ease; }
.thumb:hover { opacity: 1; border-color: var(--accent); transform: scale(1.05); }
.thumb.active { opacity: 1; border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

.info-wrap { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
@media (max-width: 900px) { .info-wrap { grid-template-columns: 1fr; } }

.info-panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); animation: slideUp 0.4s 0.2s both; }
.info-panel .title { font-size: 32px; margin: 0 0 16px; line-height: 1.3; color: var(--text); font-weight: 800; }
.info-panel .price { font-size: 28px; color: var(--accent); font-weight: 800; margin-bottom: 16px; }
.info-panel .meta { color: var(--muted); font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
.info-panel .desc-title { font-size: 18px; margin-bottom: 12px; color: var(--text); font-weight: 700; }
.info-panel .desc { color: var(--text); line-height: 1.6; white-space: pre-line; font-size: 15px; }

.sidebar { display: flex; flex-direction: column; gap: 20px; }
.seller-card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; text-decoration: none; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); animation: slideUp 0.4s 0.3s both; }
.seller-card:hover { border-color: var(--accent); box-shadow: 0 4px 16px rgba(139, 92, 246, 0.15); transform: translateY(-2px); }
.seller-pic { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-light); transition: all 0.3s; }
.seller-card:hover .seller-pic { border-color: var(--accent); }
.seller-details .label { font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
.seller-details .name { font-size: 16px; color: var(--text); font-weight: 700; margin-top: 4px; }
.seller-details .role { font-size: 13px; color: var(--accent); margin-top: 2px; }

.event-time-box { background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(139, 92, 246, 0.02)); border: 1px solid var(--accent-light); border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 8px rgba(139, 92, 246, 0.05); }
.calendar-icon { font-size: 32px; min-width: 40px; text-align: center; }
.event-date { font-weight: 700; color: var(--accent); font-size: 18px; display: block; }
.event-hour { color: var(--muted); font-size: 14px; margin-top: 4px; }

.item-specs { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); animation: slideUp 0.4s 0.4s both; }
.spec-row { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.spec-row:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: 0; }
.spec-label { font-size: 13px; color: var(--muted); display: block; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.spec-value { font-size: 15px; color: var(--text); font-weight: 700; }

.btn { display: block; width: 100%; padding: 14px 20px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 15px; border: 0; cursor: pointer; text-align: center; margin-top: 12px; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
.btn-primary { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4); }
.btn-primary:active { transform: translateY(0); }
.btn-secondary { background: var(--panel); color: var(--accent); border: 2px solid var(--accent-light); transition: all 0.3s; }
.btn-secondary:hover { background: var(--accent-light); border-color: var(--accent); color: var(--accent-dark); }

/* Similar Items */
.similar-section { margin-top: 50px; border-top: 2px solid var(--border); padding-top: 30px; animation: slideUp 0.5s 0.5s both; }
.similar-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 20px; }
.similar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
.sim-card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: 0.3s; display: flex; flex-direction: column; text-decoration: none; color: inherit; }
.sim-card:hover { transform: translateY(-5px); border-color: var(--accent); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
.sim-img { height: 160px; background: #f8f9fb; }
.sim-img img { width: 100%; height: 100%; object-fit: cover; }
.sim-body { padding: 12px; }
.sim-title { font-weight: 700; font-size: 14px; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sim-price { color: var(--accent); font-weight: 800; font-size: 15px; }

/* Saved Modal */
.modal-overlay-modal {position:fixed;inset:0;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:2000;opacity:0;pointer-events:none;transition:opacity 0.2s;}
.modal-overlay-modal.is-visible{opacity:1;pointer-events:auto;}
.modal-content{background:#ffffff;border-radius:16px;width:100%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{margin:0;color:var(--text);font-weight:800;}
.modal-close{background:none;border:none;color:var(--muted);font-size:24px;cursor:pointer;}
.modal-body{padding:0;overflow-y:auto;}
.saved-item{display:flex;gap:15px;padding:15px 24px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);align-items:center;}
.saved-item:hover{background:#f9fafb;}
.saved-img{width:60px;height:60px;border-radius:8px;object-fit:cover;border:1px solid var(--border);}
.saved-info{flex:1;}
.unsave-btn{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:5px;}
.unsave-btn:hover{color:var(--danger);}
.empty-saved{padding:40px;text-align:center;color:var(--muted);}

/* ---- Image Lightbox Modal ---- */
.image-modal { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.8); z-index: 3000; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; }
.image-modal.open { opacity: 1; pointer-events: auto; }
.image-modal img { max-width: 95vw; max-height: 95vh; width: auto; height: auto; object-fit: contain; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
.image-modal .modal-close { position: absolute; top: 20px; right: 26px; font-size: 32px; color: #fff; background: transparent; border: none; cursor: pointer; line-height: 1; }

.main-image img { cursor: zoom-in; }

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
    .header { padding: 12px 16px; padding-right: 20px; gap: 10px; }
    .header .brand { font-size: 16px; }
    .avatar-small, .letter-avatar-small { width: 32px; height: 32px; font-size: 14px; }
    
    /* Main Container */
    .main-container { padding: 16px 12px; padding-right: 20px; }
    .breadcrumbs { font-size: 12px; margin-bottom: 14px; }
    
    /* Gallery */
    .gallery { padding: 14px; margin-bottom: 20px; border-radius: 10px; }
    .main-image { height: 280px; border-radius: 10px; margin-bottom: 12px; }
    .share-btn-overlay { width: 36px; height: 36px; font-size: 13px; top: 10px; right: 10px; }
    .thumbs { gap: 8px; }
    .thumb { width: 56px; height: 56px; border-radius: 6px; }
    
    /* Info Panel */
    .info-wrap { gap: 16px; }
    .info-panel { padding: 18px; border-radius: 10px; }
    .info-panel .title { font-size: 22px; margin-bottom: 10px; }
    .info-panel .price { font-size: 22px; margin-bottom: 12px; }
    .info-panel .meta { font-size: 12px; margin-bottom: 16px; padding-bottom: 12px; }
    .info-panel .desc-title { font-size: 15px; margin-bottom: 8px; }
    .info-panel .desc { font-size: 14px; }
    
    /* Event Time Box */
    .event-time-box { padding: 14px; gap: 12px; }
    .calendar-icon { font-size: 26px; min-width: 32px; }
    .event-date { font-size: 15px; }
    .event-hour { font-size: 12px; }
    
    /* Seller Card */
    .seller-card { padding: 14px; gap: 12px; border-radius: 10px; }
    .seller-pic, .letter-avatar-seller { width: 48px; height: 48px; font-size: 18px; }
    .seller-details .label { font-size: 10px; }
    .seller-details .name { font-size: 14px; }
    .seller-details .role { font-size: 12px; }
    
    /* Item Specs */
    .item-specs { padding: 16px; border-radius: 10px; }
    .spec-row { margin-bottom: 12px; padding-bottom: 12px; }
    .spec-label { font-size: 11px; }
    .spec-value { font-size: 13px; }
    
    /* Make Offer Box */
    div[style*="background:#f0f9ff"] { padding: 12px !important; border-radius: 8px !important; }
    div[style*="background:#f0f9ff"] h4 { font-size: 13px !important; margin-bottom: 8px !important; }
    
    /* Buttons */
    .btn { padding: 12px 16px; font-size: 13px; border-radius: 8px; margin-top: 10px; }
    
    /* Similar Section */
    .similar-section { margin-top: 30px; padding-top: 20px; }
    .similar-title { font-size: 18px; margin-bottom: 14px; }
    .similar-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .sim-card { border-radius: 10px; }
    .sim-img { height: 110px; }
    .sim-body { padding: 10px; }
    .sim-title { font-size: 12px; }
    .sim-price { font-size: 13px; }
    
    /* Modal */
    .modal-content { max-width: 95%; max-height: 85vh; border-radius: 12px; }
    .modal-header { padding: 14px 18px; }
    .modal-header h3 { font-size: 16px; }
    .saved-item { padding: 12px 16px; gap: 12px; }
    .saved-img { width: 50px; height: 50px; }
    
    /* Image Modal */
    .image-modal img { border-radius: 8px; }
    .image-modal .modal-close { top: 12px; right: 16px; font-size: 28px; }
}
</style>
</head>
<body>

<header class="header">
    <a href="home.php" class="brand">Campus Preloved E-Shop</a>
    <div class="controls">
        <?php if (isset($_SESSION['UserID'])): ?>
            <!-- Message Link Removed -->
            <a href="profile.php" style="display:flex;align-items:center;">
                <?php if ($hasAvatar): ?>
                    <img src="<?= h($currentUserImg) ?>" alt="profile" class="avatar-small">
                <?php else: ?>
                    <div class="letter-avatar-small"><?= $avatarLetter ?></div>
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
        <?php else: ?>
            <a href="index.html" style="font-weight:600;">Login</a>
        <?php endif; ?>
    </div>
</header>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- BLACK STRIP SIDEBAR -->
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
            <li><a href="reports.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M15.73 3H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27L15.73 3zM12 17c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm1-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
    </div>
</aside>

<div class="main-container">
    <div class="breadcrumbs">
        <a href="home.php">Home</a> &gt; 
        <a href="home.php?category=<?= urlencode($item['category']) ?>"><?= h($item['category']) ?></a> &gt; 
        <span><?= h($item['title']) ?></span>
    </div>

    <div class="gallery">
        <div class="main-image" id="mainImage">
            <img src="<?= h($images[0]) ?>" alt="Item" style="cursor:zoom-in;" onclick="openImageModal(this.src)">
            <button class="share-btn-overlay" title="Copy Link" onclick="event.stopPropagation(); shareItem()">🔗</button>
        </div>
        <?php if (count($images) > 1): ?>
            <div class="thumbs-wrap">
                <div class="thumbs" id="thumbs">
                    <?php foreach ($images as $i => $src): ?>
                        <div class="thumb" data-src="<?= h($src) ?>"><img src="<?= h($src) ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="info-wrap">
        <main class="info-panel">
            <h1 class="title"><?= h($item['title']) ?></h1>

            <div style="color:var(--muted); font-size:13px; margin-bottom:10px;">
                👁️ <?= number_format($item['views'] ?? 0) ?> views
            </div>

            <?php if ($isEvent): ?>
                <?php if (!empty($item['event_date'])): ?>
                    <div class="event-time-box">
                        <div class="calendar-icon">📅</div>
                        <div>
                            <span class="event-date"><?= date('l, d M Y', strtotime($item['event_date'])) ?></span>
                            <?php if(!empty($item['event_time'])): ?>
                                <span class="event-hour">Time: <?= date('h:i A', strtotime($item['event_time'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="price">RM <?= number_format((float)$item['price'], 2) ?></div>
            <?php endif; ?>

            <div class="meta">Posted on <?= !empty($item['postDate']) ? date('d M Y', strtotime($item['postDate'])) : '' ?></div>
            <h2 class="desc-title">Description</h2>
            <div class="desc"><?= nl2br(h($item['description'] ?? 'No description')) ?></div>
        </main>

        <aside class="sidebar">
            <?php if ($seller): 
                $sellerHasAvatar = !empty($seller['profile_image']) && file_exists($seller['profile_image']);
                $sellerLetter = strtoupper(substr($seller['name'] ?? 'U', 0, 1));
            ?>
                <a href="public_profile.php?userid=<?= (int)$seller['UserID'] ?>" class="seller-card" title="View Seller Profile">
                    <?php if ($sellerHasAvatar): ?>
                        <img src="<?= h($seller['profile_image']) ?>" alt="Seller" class="seller-pic">
                    <?php else: ?>
                        <div class="letter-avatar-seller"><?= $sellerLetter ?></div>
                    <?php endif; ?>
                    <div class="seller-details">
                        <div class="label"><?= $isEvent ? 'Organizer' : 'Sold by' ?></div>
                        <div class="name"><?= h($seller['name']) ?></div>
                        <div class="role"><?= h($seller['role'] ?? 'Student') ?></div>
                    </div>
                    <div style="margin-left:auto; color:var(--muted);">›</div>
                </a>
            <?php endif; ?>
            
            <?php if(!$isEvent && $userid != $item['UserID']): ?>
                <div class="offer-box">
                    <div class="offer-title">Make an Offer</div>
                    
                    <?php if($offerSent): ?>
                        <div class="offer-success">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <span>Offer sent successfully!</span>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="offer-form">
                            <div class="offer-input-group">
                                <span class="currency-prefix">RM</span>
                                <input type="number" name="offer_amount" step="0.01" placeholder="0.00" required>
                            </div>
                            <button type="submit" name="make_offer" class="offer-send-btn">Send</button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <style>
                .offer-box {
                    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
                    border: none;
                    border-radius: 14px;
                    padding: 18px;
                    margin-bottom: 16px;
                    box-shadow: 0 4px 16px rgba(30, 27, 75, 0.3);
                }
                .offer-title {
                    font-weight: 700;
                    font-size: 15px;
                    color: #e0e7ff;
                    margin-bottom: 14px;
                }
                .offer-success {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    background: rgba(16, 185, 129, 0.2);
                    color: #6ee7b7;
                    padding: 12px 16px;
                    border-radius: 10px;
                    font-weight: 600;
                    font-size: 14px;
                    border: 1px solid rgba(16, 185, 129, 0.3);
                }
                .offer-form {
                    display: flex;
                    gap: 10px;
                    align-items: stretch;
                }
                .offer-input-group {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    background: rgba(255, 255, 255, 0.1);
                    border: 2px solid rgba(255, 255, 255, 0.2);
                    border-radius: 10px;
                    overflow: hidden;
                    transition: all 0.3s;
                }
                .offer-input-group:focus-within {
                    border-color: var(--accent);
                    background: rgba(255, 255, 255, 0.15);
                }
                .currency-prefix {
                    padding: 10px 12px;
                    background: rgba(255, 255, 255, 0.1);
                    color: #c7d2fe;
                    font-weight: 700;
                    font-size: 13px;
                    border-right: 1px solid rgba(255, 255, 255, 0.1);
                }
                .offer-input-group input {
                    flex: 1;
                    border: none;
                    padding: 10px 12px;
                    font-size: 15px;
                    font-weight: 600;
                    color: white;
                    background: transparent;
                    outline: none;
                }
                .offer-input-group input::placeholder {
                    color: rgba(255, 255, 255, 0.4);
                }
                .offer-send-btn {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 10px 20px;
                    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                    border: none;
                    border-radius: 10px;
                    cursor: pointer;
                    transition: all 0.3s;
                    box-shadow: 0 4px 12px rgba(75, 0, 130, 0.4);
                    color: white;
                    font-weight: 700;
                    font-size: 13px;
                }
                .offer-send-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(75, 0, 130, 0.5);
                }
                .offer-send-btn:active {
                    transform: translateY(0);
                }
                
                /* Mobile optimization for offer box */
                @media (max-width: 480px) {
                    .offer-box { padding: 14px; border-radius: 12px; }
                    .offer-title { font-size: 14px; margin-bottom: 12px; }
                    .offer-form { gap: 8px; }
                    .currency-prefix { padding: 8px 10px; font-size: 12px; }
                    .offer-input-group input { padding: 8px 10px; font-size: 14px; }
                    .offer-send-btn { padding: 8px 16px; font-size: 12px; border-radius: 8px; }
                }
                </style>
            <?php endif; ?>

            <?php if (!$isEvent): ?>
            <div class="item-specs">
                <div class="spec-row"><span class="spec-label">Condition</span><span class="spec-value"><?= h($item['condition'] ?? 'N/A') ?></span></div>
                <div class="spec-row"><span class="spec-label">Status</span><span class="spec-value" style="text-transform:capitalize;"><?= h($item['status'] ?? 'Available') ?></span></div>
                
                <div class="spec-row" style="align-items: flex-start; justify-content: flex-start;">
                    <span class="spec-label" style="width: 100px; flex-shrink: 0;">Meetup</span>
                    <span class="spec-value" style="text-align: left; line-height: 1.4;"><?= h($item['meetup_preferences'] ?? '-') ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top:20px;">
                <?php if ($userid && $userid != $item['UserID']): ?>
                <a class="btn btn-primary" href="messages.php?to=<?= urlencode($seller['UserID'] ?? '') ?>&context_item=<?= $item['ItemID'] ?>">
                    <?= $isEvent ? 'Contact Organizer' : 'Message Seller' ?>
                </a>
                
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="toggle_save" value="1">
                    <button type="submit" class="btn btn-secondary">
                        <?= $isSaved ? 'Remove from Saved' : 'Save Item' ?>
                    </button>
                </form>
                
                <button class="btn btn-danger" onclick="document.getElementById('reportModal').classList.add('is-visible')" style="background:#ef4444; color:white; border:none; cursor:pointer; padding:12px 24px; border-radius:8px; font-weight:600;">
                    Report
                </button>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <!-- Similar Items Section -->
    <?php if(!empty($similarItems)): ?>
    <div class="similar-section">
        <h3 class="similar-title">You might also like</h3>
        <div class="similar-grid">
            <?php foreach($similarItems as $sim): 
                $sImg = !empty($sim['image']) ? explode(',', $sim['image'])[0] : 'uploads/avatars/default.png';
            ?>
            <a href="item_detail.php?id=<?= $sim['ItemID'] ?>" class="sim-card">
                <div class="sim-img"><img src="<?= h($sImg) ?>"></div>
                <div class="sim-body">
                    <div class="sim-title"><?= h($sim['title']) ?></div>
                    <div class="sim-price">RM <?= number_format($sim['price'], 2) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Report Item Modal -->
<div class="modal-overlay-modal" id="reportModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Report</h3>
            <button class="modal-close" onclick="document.getElementById('reportModal').classList.remove('is-visible')">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($reportSent): ?>
                <div style="color:#059669; font-weight:700; font-size:15px; display:flex; align-items:center; gap:6px; background:#d1fae5; padding:15px; border-radius:8px; margin-bottom:20px;">
                    <span>✓</span> Thank you for reporting. Our team will review it shortly.
                </div>
            <?php else: ?>
            <form method="POST" style="padding:20px 0;">
                <input type="hidden" name="submit_item_report" value="1">
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:8px; font-weight:700; color:var(--text);">Report Type</label>
                    <select name="report_type" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-family:inherit; font-size:15px;">
                        <option value="item_report">Inappropriate Item Content</option>
                        <option value="spam">Spam/Duplicate Listing</option>
                        <option value="inappropriate">Offensive or Hateful Content</option>
                        <option value="other">Other (Please Specify Below)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:8px; font-weight:700; color:var(--text);">Description</label>
                    <textarea name="report_reason" rows="4" required placeholder="Explain why you're reporting this item..." style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; resize:vertical; font-family:inherit;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-weight:600;">Submit Report</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay-modal" id="savedModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Saved List</h3><button class="modal-close" onclick="document.getElementById('savedModal').classList.remove('is-visible')">&times;</button></div>
        <div class="modal-body">
            <?php if(empty($savedItems)): ?><div class="empty-saved">No saved items yet.</div><?php else: foreach($savedItems as $sv): 
                $sImg = !empty($sv['image']) ? explode(',', $sv['image'])[0] : 'uploads/avatars/default.png';
            ?>
                <div class="saved-item">
                    <a href="item_detail.php?id=<?= $sv['ItemID'] ?>" style="display:flex;gap:15px;flex:1;text-decoration:none;color:inherit;align-items:center;">
                        <img src="<?= h($sImg) ?>" class="saved-img">
                        <div class="saved-info"><div style="font-weight:700;font-size:15px;"><?= h($sv['title']) ?></div><div style="color:var(--accent);font-weight:700;">RM <?= number_format($sv['price'], 2) ?></div></div>
                    </a>
                    <form method="POST" onsubmit="return confirm('Unsave?');"><input type="hidden" name="unsave_id_modal" value="<?= $sv['ItemID'] ?>"><button type="submit" class="unsave-btn">✕</button></form>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="image-modal" id="imageModal" onclick="closeImageModal()" aria-hidden="true" role="dialog">
    <img id="modalImage" alt="Item image" onclick="event.stopPropagation()">
    <button class="modal-close" aria-label="Close" onclick="closeImageModal(event)">&times;</button>
</div>

<script>
function openSavedModal(){ document.getElementById('savedModal').classList.add('is-visible'); }
// Toggle Sidebar
function toggleSidebar() {
    const s = document.getElementById('sidebar'); 
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open'); 
    o.classList.toggle('active');
}
document.getElementById('savedModal').addEventListener('click', (e)=>{if(e.target.id==='savedModal')document.getElementById('savedModal').classList.remove('is-visible');});
document.getElementById('reportModal').addEventListener('click', (e)=>{if(e.target.id==='reportModal')document.getElementById('reportModal').classList.remove('is-visible');});

function shareItem() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        alert("Link copied to clipboard!");
    });
}

(function(){
    const main = document.getElementById('mainImage');
    const img = main.querySelector('img');
    const thumbs = document.getElementById('thumbs');
    if (!thumbs) return;
    thumbs.querySelector('.thumb').classList.add('active');
    thumbs.addEventListener('click', function(e){
        const t = e.target.closest('.thumb');
        if (!t) return;
        const src = t.getAttribute('data-src');
        if (!src || src === img.src) return;
        thumbs.querySelectorAll('.thumb').forEach(th => th.classList.remove('active'));
        t.classList.add('active');
        img.style.opacity = 0;
        setTimeout(()=> { img.src = src; img.onload = ()=> img.style.opacity = 1; }, 150);
    });
})();

// Image modal controls
function openImageModal(src) {
    const m = document.getElementById('imageModal');
    const img = document.getElementById('modalImage');
    img.src = src;
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
}
function closeImageModal(e) {
    if (e) e.preventDefault();
    const m = document.getElementById('imageModal');
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
}
document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') closeImageModal();
});

// ===== TRACK RECENTLY VIEWED ITEM =====
(function() {
    const RECENTLY_VIEWED_KEY = 'ekedai_recently_viewed';
    const MAX_ITEMS = 10;
    
    // Get current item data from the page
    const itemId = <?= (int)$id ?>;
    const itemTitle = <?= json_encode($item['title']) ?>;
    const itemPrice = <?= $isEvent ? json_encode('Event') : json_encode('RM ' . number_format((float)$item['price'], 2)) ?>;
    const itemImage = <?= json_encode(!empty($images[0]) ? $images[0] : '') ?>;
    
    if (!itemId) return;
    
    try {
        let items = JSON.parse(localStorage.getItem(RECENTLY_VIEWED_KEY) || '[]');
        
        // Remove if already exists (to move to front)
        items = items.filter(item => item.id !== itemId);
        
        // Add to front
        items.unshift({
            id: itemId,
            title: itemTitle,
            price: itemPrice,
            image: itemImage,
            timestamp: Date.now()
        });
        
        // Keep only max items
        items = items.slice(0, MAX_ITEMS);
        
        localStorage.setItem(RECENTLY_VIEWED_KEY, JSON.stringify(items));
    } catch (e) {}
})();
</script>
</body>
</html>