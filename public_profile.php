<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get User ID from URL
$userid = isset($_GET['userid']) ? (int)$_GET['userid'] : 0;

// --- FIX: Block System Admin (ID 0) ---
// If someone tries to view profile 0 (the admin bot), stop them.
if ($userid <= 0) {
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif; color:#666;'>Profile not found.</div>";
    exit;
}

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { echo "Connection failed."; exit; }

// Fetch User Data
$stmt = $pdo->prepare("SELECT name, email, role, profile_image, bio FROM users WHERE UserID = ? LIMIT 1");
$stmt->execute([$userid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "User not found."; exit; }

$userAvatar = (!empty($user['profile_image']) && file_exists($user['profile_image'])) ? $user['profile_image'] : '';
$hasAvatar = !empty($userAvatar);

// Fetch Reviews & Rating
$reviewStmt = $pdo->prepare("SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating FROM reviews WHERE targetUserID = ?");
$reviewStmt->execute([$userid]);
$reviewData = $reviewStmt->fetch(PDO::FETCH_ASSOC);
$totalReviews = (int)$reviewData['total_reviews'] ?? 0;
$avgRating = $reviewData['avg_rating'] ? round((float)$reviewData['avg_rating'], 1) : 0;

// Fetch Public Items
$itStmt = $pdo->prepare("SELECT ItemID,title,price,category,postDate,image,status,description FROM item WHERE UserID = ? AND status IN ('available', 'sold', 'ended') ORDER BY postDate DESC");
$itStmt->execute([$userid]);
$allItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

// Categorize
$lists = ['available' => [], 'sold' => []];
foreach ($allItems as $item) {
    $status = $item['status'];
    // Group both 'sold' and 'ended' into history
    if ($status === 'sold' || $status === 'ended') {
        $lists['sold'][] = $item;
    } elseif ($status === 'available') {
        $lists['available'][] = $item;
    }
}

// Header logic
$myID = $_SESSION['UserID'] ?? 0;
$myName = $_SESSION['name'] ?? 'Guest';
$unread = 0;
$myAvatar = '';
$myHasAvatar = false;
if ($myID) {
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0");
        $c->execute([$myID]);
        $unread = (int)$c->fetchColumn();
        
        // Get my avatar
        $myStmt = $pdo->prepare("SELECT profile_image FROM users WHERE UserID = ?");
        $myStmt->execute([$myID]);
        $myRow = $myStmt->fetch(PDO::FETCH_ASSOC);
        if ($myRow && !empty($myRow['profile_image']) && file_exists($myRow['profile_image'])) {
            $myAvatar = $myRow['profile_image'];
            $myHasAvatar = true;
        }
    } catch (Exception $e) {}
}

// Fetch Saved Items for logged-in user
$savedItems = [];
if ($myID) {
    require_once 'saved_items_helper.php';
    
    // Handle unsave action
    if (isset($_POST['unsave_id'])) {
        saved_remove($pdo, $myID, (int)$_POST['unsave_id']);
        header("Location: public_profile.php?userid=" . $userid);
        exit;
    }
    
    $savedItems = saved_fetch_items($pdo, $myID, true);
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPES - <?= h($user['name']) ?>'s Profile</title>
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

body {
    font-family: 'Outfit', sans-serif;
    margin: 0;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
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

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.header {
    display: flex;
    align-items: center;
    padding: 16px 30px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    animation: slideInDown 0.4s ease-out;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    gap: 16px;
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
}

.controls {
    flex: 1;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    align-items: center;
}

.avatar-small {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid var(--accent-light);
    transition: all 0.3s;
    cursor: pointer;
}

.avatar-small:hover {
    border-color: var(--accent);
    transform: scale(1.05);
    box-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
}

.badge {
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: white;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
    animation: pulse 2s infinite;
}

.controls a {
    color: var(--muted);
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s;
}

.controls a:hover {
    color: var(--accent);
}

/* --- UPDATED CONTAINER (Centering) --- */
.container {
    padding: 30px 40px;
    flex: 1;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto; /* Centers the content */
}

.back {
    display: inline-block;
    margin-bottom: 24px;
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
    animation: slideUp 0.4s;
}

.back:hover {
    color: var(--accent-dark);
    transform: translateX(-4px);
}

/* --- UPDATED PROFILE CARD (Wider) --- */
.profile-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 40px;
    display: flex;
    gap: 30px;
    align-items: flex-start;
    margin-bottom: 40px;
    box-shadow: 0 4px 20px rgba(139, 92, 246, 0.08);
    animation: slideUp 0.4s 0.1s both;
    width: 100%; /* Changed from fit-content to 100% to fill width */
    box-sizing: border-box;
}

.profile-avatar-large {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-light), #f8f9fb);
    border: 4px solid var(--accent-light);
    object-fit: cover;
    flex-shrink: 0;
    box-shadow: 0 8px 24px rgba(139, 92, 246, 0.2);
    cursor: zoom-in;
}

.letter-avatar-large {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 56px;
    font-weight: 600;
    color: var(--muted);
    background: var(--panel);
    border: 4px solid var(--accent-light);
    flex-shrink: 0;
    box-shadow: 0 8px 24px rgba(139, 92, 246, 0.2);
    text-transform: uppercase;
}

.info {
    flex-grow: 1;
}

.rating-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: transparent; /* No background */
    border: none;            /* No border */
    border-radius: 0;
    padding: 0;              /* Tight layout */
    margin-bottom: 16px;
    font-size: 14px;
}

.rating-stars {
    font-size: 18px;
    color: var(--accent);
    letter-spacing: 0; /* Single star only */
}

.rating-text {
    color: var(--text);
    font-weight: 700;
}

.rating-count {
    color: var(--muted);
    font-size: 13px;
    margin-left: 8px;
}

.info h2 {
    margin: 0 0 12px;
    font-size: 32px;
    color: var(--text);
    font-weight: 800;
}

.role-badge {
    display: inline-block;
    background: linear-gradient(135deg, var(--accent-light), rgba(139, 92, 246, 0.05));
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 8px;
    color: var(--accent);
    margin-bottom: 16px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: 1px solid var(--accent-light);
}

.bio-display {
    margin-top: 16px;
    color: var(--text);
    font-size: 15px;
    line-height: 1.6;
    max-width: 600px;
    white-space: pre-line;
}

.btn-primary {
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: white;
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    display: inline-block;
    margin-top: 20px;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
}

.tabs {
    display: flex;
    gap: 30px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 30px;
    animation: slideUp 0.4s 0.2s both;
}

.tab-btn {
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--muted);
    font-size: 15px;
    font-weight: 700;
    padding: 12px 0;
    cursor: pointer;
    transition: all 0.3s;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tab-btn:hover {
    color: var(--text);
}

.tab-btn.active {
    color: var(--accent);
    border-bottom-color: var(--accent);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 24px;
    animation: slideUp 0.4s 0.3s both;
    max-width: 100%;
}

.card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.card:hover {
    transform: translateY(-8px);
    border-color: var(--accent);
    box-shadow: 0 8px 24px rgba(139, 92, 246, 0.15);
}

.card-image {
    height: 200px;
    background: linear-gradient(135deg, #f8f9fb, #f3f0fb);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}

.card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s;
}

/* Type label for Event/Services */
.type-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    background: rgba(255, 255, 255, 0.92);
    color: var(--text);
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    border: 1px solid var(--border);
    box-shadow: 0 4px 16px rgba(0,0,0,0.08);
    z-index: 1;
}

/* Status label for history items */
.status-label {
    position: absolute;
    bottom: 12px;
    left: 12px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    z-index: 1;
}

.sold-label { 
    background: linear-gradient(135deg, #10b981, #059669); 
    color: white; 
}

.ended-label { 
    background: linear-gradient(135deg, #8b5cf6, #6d28d9); 
    color: white; 
    border: 2px solid rgba(255, 255, 255, 0.3); 
}

.card:hover .card-image img {
    transform: scale(1.08);
}

.card-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}

.card-title {
    margin: 0 0 8px;
    font-weight: 700;
    color: var(--text);
    font-size: 15px;
    line-height: 1.4;
    min-height: 44px;
}

.card-price {
    font-weight: 800;
    color: var(--accent);
    font-size: 18px;
    margin-bottom: 8px;
}

.card-date {
    color: var(--muted);
    font-size: 12px;
    margin-top: auto;
    padding-top: 8px;
}

.empty {
    background: var(--panel);
    border: 2px dashed var(--border);
    padding: 50px 30px;
    border-radius: 12px;
    color: var(--muted);
    text-align: center;
    font-size: 15px;
    animation: fadeIn 0.3s;
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

/* ===== PREMIUM SIDEBAR (Optimized - width for desktop) ===== */
.right-sidebar { 
    position: fixed; 
    top: 0; 
    right: 0; 
    width: 70px;
    height: 100vh; 
    background: rgba(10, 10, 15, 0.95);
    color: white; 
    z-index: 1000; 
    transition: width 0.3s ease-out;
    box-shadow: -5px 0 30px rgba(0,0,0,0.15);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* Expanded State */
.right-sidebar.open { 
    width: 300px; 
}

/* Sidebar Toggle Button (Hamburger) - Always visible in the strip */
.sidebar-toggle-btn {
    width: 70px; /* Full width of the strip */
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
    /* Positioned absolute to stay right when expanded */
    position: absolute;
    top: 0;
    right: 0;
    z-index: 1002;
}
.sidebar-toggle-btn:hover { color: var(--accent); }

/* Sidebar Content Wrapper */
.sidebar-content {
    margin-top: 80px; /* Space for hamburger */
    opacity: 0; /* Hidden when collapsed */
    padding: 20px 30px;
    width: 300px; /* Fixed width to prevent text wrap during anim */
    transition: opacity 0.2s ease;
    flex: 1;
}
.right-sidebar.open .sidebar-content {
    opacity: 1;
    transition-delay: 0.1s;
}

/* Sidebar Links */
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

/* Footer */
.sidebar-footer {
    width: 70px;
    padding-bottom: 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    position: absolute;
    bottom: 0;
    right: 0;
    background: #111;
}

/* Overlay (Darken screen when opened) */
.sidebar-overlay { 
    position: fixed; inset: 0; background: rgba(0,0,0,0.6); 
    z-index: 900; opacity: 0; pointer-events: none; 
    transition: opacity 0.3s ease;
}
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }

/* ---- Image Lightbox Modal ---- */
.image-modal {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.9);
    z-index: 2000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}
.image-modal.open { opacity: 1; pointer-events: auto; }
.image-modal img {
    max-width: 90vw;
    max-height: 90vh;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
}
.modal-close {
    position: absolute;
    top: 20px;
    right: 26px;
    font-size: 32px;
    color: #fff;
    background: transparent;
    border: none;
    cursor: pointer;
    line-height: 1;
}

/* Saved Items Modal Overlay */
.modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(4px); }
.modal-overlay.is-visible { opacity: 1; pointer-events: auto; }
.modal-content { background: var(--panel); border-radius: 16px; padding: 24px; width: 100%; max-width: 450px; position: relative; transform: translateY(20px); transition: transform 0.3s; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); animation: slideUp 0.4s ease-out; }
.modal-overlay.is-visible .modal-content { transform: translateY(0); }
.modal-overlay h3 { margin: 0 0 16px; color: var(--text); font-weight: 800; font-size: 18px; }
.modal-close { position: absolute; top: 15px; right: 15px; background: none; border: none; color: var(--muted); font-size: 24px; cursor: pointer; transition: all 0.3s; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
.modal-close:hover { background: var(--accent-light); color: var(--accent); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-body { max-height: 60vh; overflow-y: auto; }

/* Mobile Menu Button */
.mobile-menu-btn {
    display: none;
    position: relative;
    width: 40px;
    height: 40px;
    align-items: center;
    justify-content: center;
    background: #1a1a2e;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    color: white;
    margin-left: 12px;
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

/* Saved Items Modal Styles */
.saved-item {
    display: flex;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid var(--border);
    align-items: center;
    transition: all 0.3s;
}
.saved-item:hover {
    background: var(--accent-light);
    padding-left: 8px;
}
.saved-img {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid var(--border);
    transition: transform 0.3s;
}
.saved-item:hover .saved-img {
    transform: scale(1.05);
}
.saved-info {
    flex: 1;
}
.saved-info > div:first-child {
    font-weight: 700;
    font-size: 15px;
    color: var(--text);
}
.unsave-btn {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 18px;
    cursor: pointer;
    padding: 8px;
    transition: all 0.3s;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}
.unsave-btn:hover {
    color: white;
    background: var(--danger);
}
.empty-saved { padding: 40px 24px; text-align: center; color: var(--muted); }

@media (max-width: 768px) {
    .mobile-menu-btn { display: flex !important; }
}

/* Item Detail Modal */
.item-modal {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.7);
    z-index: 1200;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
    backdrop-filter: blur(4px);
}
.item-modal.open { opacity: 1; pointer-events: auto; }
.item-modal-content {
    background: var(--panel);
    border-radius: 16px;
    width: 95%;
    max-width: 800px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
    position: relative;
    transform: scale(0.9);
    transition: transform 0.3s ease;
}
.item-modal.open .item-modal-content { transform: scale(1); }
.item-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    background: rgba(0,0,0,0.1);
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text);
    font-size: 24px;
    transition: all 0.3s;
    z-index: 10;
}
.item-modal-close:hover {
    background: var(--danger);
    color: white;
}
.item-modal-header {
    position: sticky;
    top: 0;
    background: var(--panel);
    z-index: 10;
    padding: 0;
    border-radius: 16px 16px 0 0;
}
.item-modal-image {
    width: 100%;
    height: 220px;
    object-fit: cover;
    border-radius: 16px 16px 0 0;
    cursor: zoom-in;
}
.item-modal-body {
    padding: 24px;
}
.item-modal-title {
    font-size: 24px;
    font-weight: 800;
    color: var(--text);
    margin: 0 0 16px;
}
.item-modal-price {
    font-size: 28px;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 16px;
}
.item-modal-category {
    display: inline-block;
    background: var(--accent-light);
    color: var(--accent);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 16px;
}
.item-modal-desc {
    color: var(--text);
    line-height: 1.8;
    margin-bottom: 20px;
    white-space: pre-line;
    font-size: 15px;
    padding: 16px;
    background: rgba(75, 0, 130, 0.03);
    border-radius: 8px;
    border-left: 3px solid var(--accent);
}
.item-modal-date {
    color: var(--muted);
    font-size: 13px;
}
.item-modal-status-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 16px;
}
.status-sold { background: linear-gradient(135deg, #10b981, #059669); color: white; }
.status-ended { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; }

/* Mobile sidebar - GPU accelerated */
@media (max-width: 768px) {
    .right-sidebar { 
        width: 300px; 
        transform: translateX(100%); 
        transition: transform 0.3s ease-out;
        height: 100vh;
        height: 100dvh; /* Dynamic viewport height for mobile */
        max-height: -webkit-fill-available;
        overflow-y: auto !important;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    .right-sidebar.open { transform: translateX(0); }
    .sidebar-toggle-btn { display: none; }
    .mobile-menu-btn { display: flex !important; }
    .header { padding-right: 20px; }
    .container { padding-right: 20px; }
    
    /* Make sidebar content scrollable */
    .sidebar-content {
        overflow-y: auto;
        max-height: calc(100vh - 120px);
        max-height: calc(100dvh - 120px);
        -webkit-overflow-scrolling: touch;
    }
    
    /* Modal for tablet/mobile - matches profile.php */
    .modal-content { max-width: 90%; max-height: 80vh; overflow-y: auto; }
}

/* Mobile optimization for 480px */
@media (max-width: 480px) {
    /* Header */
    .header { padding: 12px 16px; padding-right: 20px; }
    .header .brand { font-size: 16px; }
    .avatar-small { width: 32px; height: 32px; }
    
    /* Container */
    .container { padding: 16px 12px; padding-right: 20px; }
    .back { font-size: 13px; margin-bottom: 14px; }
    
    /* Profile Card */
    .profile-card { 
        padding: 18px; 
        gap: 16px; 
        flex-direction: column; 
        align-items: center; 
        text-align: center;
        margin-bottom: 24px;
    }
    .profile-avatar-large, .letter-avatar-large { 
        width: 90px; 
        height: 90px; 
        font-size: 36px;
    }
    .info h2 { font-size: 22px; margin-bottom: 8px; }
    .rating-badge { justify-content: center; }
    .rating-stars { font-size: 16px; }
    .rating-text { font-size: 13px; }
    .rating-count { font-size: 11px; }
    .role-badge { font-size: 10px; padding: 5px 10px; }
    .bio-display { font-size: 13px; max-width: 100%; }
    .btn-primary { 
        padding: 10px 20px; 
        font-size: 13px; 
        margin-top: 14px;
        display: block;
        width: 100%;
    }
    
    /* Tabs */
    .tabs { gap: 16px; margin-bottom: 20px; }
    .tab-btn { font-size: 12px; padding: 10px 0; }
    
    /* Grid */
    .grid { 
        grid-template-columns: repeat(2, 1fr); 
        gap: 12px; 
    }
    
    /* Cards */
    .card { border-radius: 10px; }
    .card-image { height: 120px; }
    .type-badge { font-size: 10px; padding: 3px 8px; top: 8px; left: 8px; }
    .status-label { font-size: 9px; padding: 4px 10px; bottom: 8px; left: 8px; }
    .card-body { padding: 10px; }
    .card-title { font-size: 12px; min-height: 34px; margin-bottom: 4px; }
    .card-price { font-size: 14px; margin-bottom: 4px; }
    .card-date { font-size: 10px; }
    
    /* Empty state */
    .empty { padding: 30px 20px; font-size: 13px; }
    
    /* Modal - smaller for mobile (matches profile.php) */
    .modal-content { 
        max-width: 95%; 
        max-height: 85vh; 
        padding: 16px;
        margin: 10px;
    }
    .modal-overlay h3 { font-size: 16px; margin-bottom: 12px; }
    .modal-close { 
        top: 10px; 
        right: 10px; 
        width: 28px; 
        height: 28px; 
        font-size: 20px; 
    }
    
    /* Saved Items Mobile */
    .saved-item { gap: 10px; padding: 12px 0; }
    .saved-img { width: 50px; height: 50px; }
    .saved-info > div:first-child { font-size: 13px; }
    .unsave-btn { width: 28px; height: 28px; font-size: 16px; }
    
    .image-modal img { max-width: 95vw; max-height: 85vh; }
    
    /* Item Modal */
    .item-modal-content { width: 95%; max-height: 90vh; border-radius: 12px; }
    .item-modal-image { height: 200px; }
    .item-modal-body { padding: 16px; }
    .item-modal-title { font-size: 18px; }
    .item-modal-price { font-size: 22px; margin-bottom: 12px; }
    .item-modal-category { font-size: 10px; padding: 5px 10px; }
    .item-modal-desc { font-size: 13px; padding: 12px; }
    .item-modal-date { font-size: 11px; }
    .item-modal-status-badge { font-size: 10px; padding: 6px 12px; }
    .item-modal-close { width: 30px; height: 30px; font-size: 20px; top: 12px; right: 12px; }
}
</style>
</head>
<body>

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
        
        <?php if ($myID): ?>
        <ul class="sidebar-menu">
            <li><a href="home.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Home</a></li>
            <li><a href="profile.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Profile</a></li>
            <li><a href="messages.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>Messages <?php if($unread>0): ?><span style="background:white; color:black; font-size:10px; padding:2px 8px; border-radius:10px; margin-left:auto;"><?= $unread ?></span><?php endif; ?></a></li>
            <li><a href="create.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>Create List</a></li>
            <li><a href="#" class="sidebar-link" onclick="openSavedModal(); toggleSidebar();"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>Bookmarks</a></li>
            <li><a href="help.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>Help</a></li>
            <li><a href="reports.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
        <?php else: ?>
            <div style="text-align:center;">
                <p style="color:rgba(255,255,255,0.7); margin-bottom:20px;">Please login to access.</p>
                <a href="index.html" style="background:white; color:var(--accent); text-align:center; display:block; text-decoration:none; padding:12px; border-radius:8px; font-weight:700;">Login</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
    </div>
</aside>

<header class="header">
    <a href="home.php" class="brand">Campus Preloved E-Shop</a>
    <div class="controls">
        <?php if ($myID): ?>
            <a href="profile.php" style="display:flex;align-items:center;">
                <?php if ($myHasAvatar): ?>
                    <img src="<?= h($myAvatar) ?>" class="avatar-small">
                <?php else: ?>
                    <div class="letter-avatar-small"><?= strtoupper(substr($myName, 0, 1)) ?></div>
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

<div class="container">
    
    <div class="profile-card">
        <?php if ($hasAvatar): ?>
            <img src="<?= h($userAvatar) ?>" alt="Profile avatar" class="profile-avatar-large" onclick="openImageModal('<?= h($userAvatar) ?>')">
        <?php else: ?>
            <div class="letter-avatar-large"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="info">
            <h2><?= h($user['name']) ?></h2>
            
            
            <?php if ($totalReviews >= 2): ?>
                <div class="rating-badge">
                    <span class="rating-stars">★</span>
                    <span class="rating-text"><?= number_format($avgRating, 1) ?></span>
                    <span class="rating-count"><?= $totalReviews ?> review<?= $totalReviews !== 1 ? 's' : '' ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($user['bio'])): ?>
                <div class="bio-display"><?= nl2br(h($user['bio'])) ?></div>
            <?php else: ?>
                <div class="bio-display" style="color:var(--muted); font-style:italic;">No bio provided.</div>
            <?php endif; ?>

            <?php if ($myID && $myID !== $userid): ?>
                <a href="messages.php?to=<?= $userid ?>" class="btn-primary">Message Seller</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" onclick="openTab(event, 'available')">Active Listings (<?= count($lists['available']) ?>)</button>
        <button class="tab-btn" onclick="openTab(event, 'sold')">History (<?= count($lists['sold']) ?>)</button>
    </div>

    <div id="available" class="tab-content active">
        <?php if (empty($lists['available'])): ?><div class="empty">No active listings.</div><?php else: renderGrid($lists['available'], 'active'); endif; ?>
    </div>

    <div id="sold" class="tab-content">
        <?php if (empty($lists['sold'])): ?><div class="empty">No items in history yet.</div><?php else: renderGrid($lists['sold'], 'history'); endif; ?>
    </div>
</div>

<?php 
function renderGrid($items, $type = 'active') {
    echo '<div class="grid">';
    foreach($items as $it): 
        $img = !empty($it['image']) ? explode(',', $it['image'])[0] : 'avatar.png';
        $date = !empty($it['postDate']) ? date('d M Y', strtotime($it['postDate'])) : '';
        $typeLabel = '';
        $cat = strtolower(trim($it['category'] ?? ''));
        if (in_array($cat, ['event', 'events'])) { $typeLabel = 'Event'; }
        elseif (in_array($cat, ['service', 'services', 'peer-to-peer services'])) { $typeLabel = 'Services'; }
        
        // Determine status label based on actual status
        $status = strtolower($it['status'] ?? '');
        $statusLabelClass = ($status === 'ended') ? 'ended-label' : 'sold-label';
        $statusLabelText = ($status === 'ended') ? 'EVENT ENDED' : 'SOLD';
        
        // For history items, use modal; for active items, use link
        if ($type === 'history'):
            $itemData = htmlspecialchars(json_encode($it), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="card" onclick='openItemModal(<?= $itemData ?>)' style="cursor: pointer;">
            <div class="card-image">
                <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                <img src="<?= htmlspecialchars(trim($img)) ?>">
                <div class="status-label <?= $statusLabelClass ?>"><?= $statusLabelText ?></div>
            </div>
            <div class="card-body">
                <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                <div class="card-price">RM <?= number_format((float)$it['price'],2) ?></div>
                <div class="card-date"><?= htmlspecialchars($date) ?></div>
            </div>
        </div>
        <?php else: ?>
        <a href="item_detail.php?id=<?= (int)$it['ItemID'] ?>" class="card">
            <div class="card-image">
                <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                <img src="<?= htmlspecialchars(trim($img)) ?>">
            </div>
            <div class="card-body">
                <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                <div class="card-price">RM <?= number_format((float)$it['price'],2) ?></div>
                <div class="card-date"><?= htmlspecialchars($date) ?></div>
            </div>
        </a>
        <?php endif;
    endforeach;
    echo '</div>';
}
?>

<!-- Image Viewer Modal -->
<div class="image-modal" id="imageModal" onclick="closeImageModal()" aria-hidden="true" role="dialog">
    <img id="modalImage" alt="Profile image" onclick="event.stopPropagation()">
    <button class="modal-close" aria-label="Close" onclick="closeImageModal(event)">&times;</button>
</div>

<!-- Item Detail Modal -->
<div class="item-modal" id="itemModal" onclick="closeItemModal(event)">
    <div class="item-modal-content" onclick="event.stopPropagation()">
        <div class="item-modal-header">
            <button class="item-modal-close" onclick="closeItemModal(event)" aria-label="Close">&times;</button>
            <img id="modalItemImage" class="item-modal-image" alt="Item image" onclick="openImageModal(this.src)">
        </div>
        <div class="item-modal-body">
            <div id="modalItemStatus" class="item-modal-status-badge"></div>
            <h2 id="modalItemTitle" class="item-modal-title"></h2>
            <div id="modalItemCategory" class="item-modal-category"></div>
            <div id="modalItemPrice" class="item-modal-price"></div>
            <div id="modalItemDesc" class="item-modal-desc"></div>
            <div id="modalItemDate" class="item-modal-date"></div>
        </div>
    </div>
</div>

<!-- SAVED ITEMS MODAL -->
<div class="modal-overlay" id="savedModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Saved Items</h3><button class="modal-close" onclick="closeSavedModal()">&times;</button></div>
        <div class="modal-body">
            <?php if(empty($savedItems)): ?>
                <div class="empty-saved">No saved items yet.</div>
            <?php else: foreach($savedItems as $sv): 
                $sImg = !empty($sv['image']) ? explode(',', $sv['image'])[0] : 'avatar.png';
            ?>
                <div class="saved-item">
                    <a href="item_detail.php?id=<?= $sv['ItemID'] ?>" style="display:flex;gap:15px;flex:1;text-decoration:none;color:inherit;">
                        <img src="<?= h($sImg) ?>" class="saved-img" alt="<?= h($sv['title']) ?>">
                        <div class="saved-info"><div><?= h($sv['title']) ?></div><div style="color:var(--accent);font-weight:700;">RM <?= number_format($sv['price'], 2) ?></div></div>
                    </a>
                    <form method="POST" onsubmit="return confirm('Unsave this item?');">
                        <input type="hidden" name="unsave_id" value="<?= $sv['ItemID'] ?>">
                        <button type="submit" class="unsave-btn" title="Remove">✕</button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<script>
function openSavedModal() { 
    document.getElementById('savedModal').classList.add('is-visible'); 
}

function closeSavedModal() { 
    document.getElementById('savedModal').classList.remove('is-visible'); 
}

function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) tabcontent[i].classList.remove("active");
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) tablinks[i].classList.remove("active");
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open');
    o.classList.toggle('active');
}

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

// Item detail modal controls
function openItemModal(item) {
    const modal = document.getElementById('itemModal');
    const img = document.getElementById('modalItemImage');
    const title = document.getElementById('modalItemTitle');
    const category = document.getElementById('modalItemCategory');
    const price = document.getElementById('modalItemPrice');
    const desc = document.getElementById('modalItemDesc');
    const date = document.getElementById('modalItemDate');
    const status = document.getElementById('modalItemStatus');
    
    // Set image
    const itemImg = item.image ? item.image.split(',')[0] : 'avatar.png';
    img.src = itemImg;
    
    // Set content
    title.textContent = item.title;
    category.textContent = item.category;
    price.textContent = 'RM ' + parseFloat(item.price).toFixed(2);
    desc.textContent = item.description || 'No description available.';
    
    // Format date
    if (item.postDate) {
        const d = new Date(item.postDate);
        date.textContent = 'Posted: ' + d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }
    
    // Set status badge
    const itemStatus = item.status.toLowerCase();
    if (itemStatus === 'ended') {
        status.textContent = 'EVENT ENDED';
        status.className = 'item-modal-status-badge status-ended';
    } else if (itemStatus === 'sold') {
        status.textContent = 'SOLD';
        status.className = 'item-modal-status-badge status-sold';
    }
    
    modal.classList.add('open');
}

function closeItemModal(e) {
    if (e) e.preventDefault();
    const modal = document.getElementById('itemModal');
    modal.classList.remove('open');
}

document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') {
        closeImageModal();
        closeItemModal();
    }
});
</script>
</body>
</html>