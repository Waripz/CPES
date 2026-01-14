<?php
session_start();
if (!isset($_SESSION['UserID'])) { header('Location: index.html'); exit; }

$userid = (int)$_SESSION['UserID'];

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { 
    die("Connection failed"); 
}

require_once __DIR__ . '/saved_items_helper.php';

// Initialize feedback message (check for flash message from redirect)
$feedback_message = '';
$feedback_type = 'success'; // default type
if (isset($_SESSION['flash_message'])) {
    $feedback_message = $_SESSION['flash_message'];
    $feedback_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message']); // Clear it so it doesn't show again on refresh
    unset($_SESSION['flash_type']);
}

// --- FEATURE 3: Quick Mark as Sold (UPDATED WITH BUYER INFO & SOLD PRICE) ---
if (isset($_POST['mark_sold_id'])) {
    $soldID = (int)$_POST['mark_sold_id'];
    $soldType = $_POST['sold_type'] ?? 'outside'; // 'outside' or 'user'
    $buyerID = null;
    $soldPrice = null;

    if ($soldType === 'user' && !empty($_POST['buyer_user_id'])) {
        $buyerID = (int)$_POST['buyer_user_id'];
    }

    // Capture the sold price if provided
    if (!empty($_POST['sold_price'])) {
        $soldPrice = (float)$_POST['sold_price'];
    }

    // Check if this is an event to use 'ended' status instead of 'sold'
    $checkCat = $pdo->prepare("SELECT category, price FROM item WHERE ItemID = ? AND UserID = ?");
    $checkCat->execute([$soldID, $userid]);
    $itemData = $checkCat->fetch(PDO::FETCH_ASSOC);
    $cat = strtolower(trim($itemData['category'] ?? ''));
    $isEventOrService = in_array($cat, ['event', 'events', 'service', 'services', 'peer-to-peer services']);
    
    // If no sold price provided, use original listing price
    if ($soldPrice === null && !$isEventOrService) {
        $soldPrice = (float)$itemData['price'];
    }
    
    $newStatus = $isEventOrService ? 'ended' : 'sold';
    
    // Update with BuyerID, sold_price, and sold_date
    try {
        $sql = "UPDATE item SET status = ?, BuyerID = ?, sold_price = ?, sold_date = NOW() WHERE ItemID = ? AND UserID = ?";
        $pdo->prepare($sql)->execute([$newStatus, $buyerID, $soldPrice, $soldID, $userid]);
    } catch (PDOException $e) {
        // Fallback if columns don't exist
        $sql = "UPDATE item SET status = ? WHERE ItemID = ? AND UserID = ?";
        $pdo->prepare($sql)->execute([$newStatus, $soldID, $userid]);
    }
    
    header("Location: profile.php"); exit;
}

// Handle Hold Item
if (isset($_POST['hold_item_id'])) {
    $holdID = (int)$_POST['hold_item_id'];
    $sql = "UPDATE item SET status = 'hold' WHERE ItemID = ? AND UserID = ?";
    $pdo->prepare($sql)->execute([$holdID, $userid]);
    header("Location: profile.php"); exit;
}

// Handle Unhold Item (Reactivate from hold)
if (isset($_POST['unhold_item_id'])) {
    $unholdID = (int)$_POST['unhold_item_id'];
    $sql = "UPDATE item SET status = 'available' WHERE ItemID = ? AND UserID = ?";
    $pdo->prepare($sql)->execute([$unholdID, $userid]);
    header("Location: profile.php"); exit;
}

// Handle Relist Item (Sold -> Under Review with relist flag)
if (isset($_POST['relist_item_id'])) {
    $relistID = (int)$_POST['relist_item_id'];
    // Set status to under_review and mark as relisted
    $sql = "UPDATE item SET status = 'under_review', is_relisted = 1, last_updated = NOW() WHERE ItemID = ? AND UserID = ?";
    $pdo->prepare($sql)->execute([$relistID, $userid]);
    header("Location: profile.php"); exit;
}

// Fetch User Reviews & Rating
$reviewStmt = $pdo->prepare("SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating FROM reviews WHERE targetUserID = ?");
$reviewStmt->execute([$userid]);
$reviewData = $reviewStmt->fetch(PDO::FETCH_ASSOC);
$totalReviews = (int)($reviewData['total_reviews'] ?? 0);
$avgRating = $reviewData['avg_rating'] ? round((float)$reviewData['avg_rating'], 1) : 0;

// Handle Delete
if (isset($_POST['delete_item_id'])) {
    $delID = (int)$_POST['delete_item_id'];
    $chk = $pdo->prepare("SELECT image FROM item WHERE ItemID = ? AND UserID = ?");
    $chk->execute([$delID, $userid]);
    if ($chk->fetch()) {
        $pdo->prepare("DELETE FROM item WHERE ItemID = ?")->execute([$delID]);
    }
    // Redirect to prevent form resubmission on refresh
    header("Location: profile.php");
    exit;
}

// Handle Edit
if (isset($_POST['edit_item_id'])) {
    $editID = (int)$_POST['edit_item_id'];
    
    // Check status first - REJECTED items cannot be edited
    $chk = $pdo->prepare("SELECT status FROM item WHERE ItemID = ? AND UserID = ?");
    $chk->execute([$editID, $userid]);
    $currentItem = $chk->fetch();

    if ($currentItem) {
        // Normalize status for check
        $currentStatus = strtolower($currentItem['status']);
        if ($currentStatus === 'rejected' || $currentStatus === 'blacklisted') {
            $_SESSION['flash_message'] = "Error: Rejected items cannot be edited.";
            $_SESSION['flash_type'] = 'error';
        } else {
            // Proceed with Edit
            $eTitle = trim($_POST['item_title']);
            $ePrice = (float)$_POST['item_price'];
            $eDesc  = trim($_POST['item_desc']);
            $eCat   = $_POST['item_category'];

            if ($currentStatus === 'under_review') { $eStatus = 'under_review'; } 
            else { 
                $eStatus = $_POST['item_status'];
                if ($eStatus !== 'available' && $eStatus !== 'sold') $eStatus = 'available';
            }

            // Handle images - combine kept existing images with new uploads
            $finalImages = [];
            
            // Get images to keep (from hidden field)
            if (!empty($_POST['keep_images'])) {
                $keptImages = array_filter(explode(',', $_POST['keep_images']));
                $finalImages = $keptImages;
            }
            
            // Add newly uploaded images
            if (!empty($_FILES['item_images']['name'][0])) {
                $uploadDir = 'uploads/items/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                foreach ($_FILES['item_images']['name'] as $key => $val) {
                    if (!empty($val)) {
                        $fileName = basename($_FILES['item_images']['name'][$key]);
                        $target = $uploadDir . time() . "_edit_" . $key . "_" . $fileName;
                        if (move_uploaded_file($_FILES['item_images']['tmp_name'][$key], $target)) {
                            $finalImages[] = $target;
                        }
                    }
                }
            }
            
            // Create final image string (only update if we have images or if user removed all)
            $newImageString = null;
            if (!empty($finalImages)) {
                $newImageString = implode(',', $finalImages);
            } elseif (isset($_POST['keep_images'])) {
                // User removed all images - keep at least original or set empty
                $origStmt = $pdo->prepare("SELECT image FROM item WHERE ItemID = ?");
                $origStmt->execute([$editID]);
                $newImageString = $origStmt->fetchColumn();
            }

            $sql = "UPDATE item SET title=?, price=?, description=?, status=?, category=?, last_updated=NOW()";
            $params = [$eTitle, $ePrice, $eDesc, $eStatus, $eCat];
            if ($newImageString) { $sql .= ", image=?"; $params[] = $newImageString; }
            $sql .= " WHERE ItemID=? AND UserID=?";
            $params[] = $editID; $params[] = $userid;
            $pdo->prepare($sql)->execute($params);
            $_SESSION['flash_message'] = "Item updated successfully.";
            $_SESSION['flash_type'] = 'success';
        }
    }
    // Redirect to prevent form resubmission (PRG pattern)
    header("Location: profile.php");
    exit;
}

// Handle Unsave
if (isset($_POST['unsave_id'])) {
    saved_remove($pdo, $userid, (int)$_POST['unsave_id']);
    header("Location: profile.php"); exit;
}

// Handle Profile Update
if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $newName = $_POST['name'] ?? '';
    $newBio  = $_POST['bio'] ?? '';
    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE UserID = ?");
    $stmt->execute([$userid]);
    $newAvatarPath = $stmt->fetchColumn(); 

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_image'];
        $uploadDir = 'uploads/avatars/'; 
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $target = $uploadDir . 'user_' . $userid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $target)) $newAvatarPath = $target;
        }
    }
    $pdo->prepare("UPDATE users SET name = ?, bio = ?, profile_image = ? WHERE UserID = ?")->execute([$newName, $newBio, $newAvatarPath, $userid]);
    $_SESSION['name'] = $newName;
    
    // Store success message in session and redirect (PRG pattern)
    $_SESSION['flash_message'] = "Profile updated successfully!";
    header("Location: profile.php");
    exit;
}

// Fetch Current User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE UserID = ?");
$stmt->execute([$userid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userAvatar = (!empty($user['profile_image']) && file_exists($user['profile_image'])) ? $user['profile_image'] : '';
$hasAvatar = !empty($userAvatar);

// Fetch Users (Chat Partners Only) for the "Sold To" Dropdown
// Filters: Exclude current user, Exclude Admin, Only show users involved in messages
$usersStmt = $pdo->prepare("
    SELECT DISTINCT u.UserID, u.name 
    FROM users u
    JOIN message m ON (u.UserID = m.senderID OR u.UserID = m.recipientUserID)
    WHERE (m.senderID = ? OR m.recipientUserID = ?)
    AND u.UserID != ?
    AND u.matricNo != 'ADMIN'
    ORDER BY u.name ASC
");
$usersStmt->execute([$userid, $userid, $userid]);
$allUsersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Items (including sold_price and sold_date for history)
$itStmt = $pdo->prepare("SELECT ItemID,title,price,category,postDate,last_updated,image,status,description,rejection_reason,is_relisted,sold_price,sold_date FROM item WHERE UserID = ? ORDER BY postDate DESC");
$itStmt->execute([$userid]);
$allItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

// Organize Items into Lists
$lists = ['available'=>[], 'history'=>[], 'under_review'=>[], 'rejected'=>[]];
foreach ($allItems as $item) {
    $s = strtolower($item['status']);
    
    // Map database 'blacklisted' to 'rejected' for UI consistency
    if ($s == 'blacklisted') $s = 'rejected';
    
    // History includes: sold, ended, and hold items
    if ($s == 'sold' || $s == 'ended' || $s == 'hold') {
        $lists['history'][] = $item;
    } elseif (array_key_exists($s, $lists)) {
        $lists[$s][] = $item;
    } else {
        // Fallback for weird statuses
        $lists['available'][] = $item;
    }
}

// Fetch Saved Items
$savedItems = saved_fetch_items($pdo, $userid);

// Unread Messages Count
$unread = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0");
    $stmt->execute([$userid]);
    $unread = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPES - <?= h($user['name']) ?></title>
<link rel="icon" type="image/png" href="letter-w.png">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root {
    /* INDIGO & WHITE THEME */
    --bg: linear-gradient(135deg, #f8f9fb 0%, #f3f0fb 100%);
    --panel: #ffffff;
    --text: #1a202c;
    --muted: #718096;
    --border: #e2e8f0;
    
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
    overflow-x: hidden;
}

/* --- ANIMATIONS --- */
@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

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
    padding-right: 90px;
    animation: slideInDown 0.4s ease-out;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
.letter-avatar-large {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 52px;
    font-weight: 600;
    color: var(--muted);
    background: var(--panel);
    border: 4px solid var(--accent-light);
    text-transform: uppercase;
    cursor: default;
}

/* Mobile Menu Button */
.mobile-menu-btn {
    display: none; /* Hidden by default, shown on mobile */
    position: relative;
    width: 40px; height: 40px;
    align-items: center; justify-content: center;
    background: var(--bg-dark, #1a1a2e); border: none; border-radius: 10px;
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

/* --- SIDEBAR --- */
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
.right-sidebar.open { width: 300px; }

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

.sidebar-content {
    margin-top: 80px; 
    opacity: 0; 
    padding: 20px 30px;
    width: 300px;
    transition: opacity 0.2s ease;
    flex: 1;
}
.right-sidebar.open .sidebar-content { opacity: 1; transition-delay: 0.1s; }

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
.sidebar-link.active { color: white; padding-left: 10px; border-color: var(--accent); }
.sidebar-link.active .sidebar-icon { fill: var(--accent); }

.menu-badge { background: white; color: black; font-size: 10px; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
.sidebar-footer { width: 70px; padding-bottom: 30px; position: absolute; bottom: 0; right: 0; background: #111; }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 900; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }

/* --- MAIN LAYOUT --- */
.main-container {
    max-width: 1200px; 
    margin: 0 auto; 
    padding: 40px 20px; 
    padding-right: 90px;
    min-height: calc(100vh - 80px);
}
.content-area { padding: 0; }

/* --- PROFILE COMPONENTS --- */
.profile-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px;
    display: flex;
    gap: 32px;
    align-items: flex-start;
    margin-bottom: 30px;
    animation: slideUp 0.5s ease-out;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.profile-avatar-large {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: var(--accent-light);
    border: 3px solid var(--accent);
    object-fit: cover;
    flex-shrink: 0;
    transition: all 0.3s;
    box-shadow: 0 8px 25px rgba(139, 92, 246, 0.2);
    cursor: zoom-in;
}
.profile-avatar-large:hover { transform: scale(1.05); }

.info { flex-grow: 1; }
.info h2 { margin: 0 0 8px; font-size: 28px; font-weight: 800; color: var(--text); }
.info-text { color: var(--muted); font-weight: 500; font-size: 14px; }
.bio-display { margin-top: 16px; color: var(--text); font-size: 15px; line-height: 1.6; max-width: 600px; white-space: pre-line; }
.actions { margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap; }

.btn { display: inline-block; padding: 11px 20px; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 14px; border: 0; cursor: pointer; transition: all 0.3s; }
.btn-primary { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4); }
.btn-secondary { background: var(--accent-light); color: var(--accent); border: 1px solid var(--accent); }
.btn-secondary:hover { background: var(--accent); color: white; transform: translateY(-2px); }
.btn-green { background: var(--success); color: white; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
.btn-green:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); }

.rating-badge { display: inline-flex; align-items: center; gap: 10px; background: transparent; border: none; border-radius: 0; padding: 0; margin-bottom: 16px; font-size: 14px; }
.rating-stars { font-size: 18px; color: var(--accent); letter-spacing: 0; }
.rating-text { color: var(--text); font-weight: 700; }
.rating-count { color: var(--muted); font-size: 13px; margin-left: 8px; }

.tabs { display: flex; gap: 20px; border-bottom: 2px solid var(--border); margin-bottom: 24px; overflow-x: auto; padding-bottom: 0; }
.tab-btn { background: transparent; border: none; border-bottom: 3px solid transparent; color: var(--muted); font-size: 15px; font-weight: 600; padding: 12px 4px; cursor: pointer; transition: all 0.3s; white-space: nowrap; position: relative; }
.tab-btn:hover { color: var(--accent); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab-content { display: none; animation: fadeIn 0.3s ease-out; }
.tab-content.active { display: block; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 24px; animation: slideUp 0.5s ease-out; }
.card { background: var(--panel); border: 1px solid var(--border); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; position: relative; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); animation: slideUp 0.5s ease-out; }
.card:hover { transform: translateY(-8px); border-color: var(--accent); box-shadow: 0 20px 40px rgba(139, 92, 246, 0.15); }
.card-content-link { text-decoration: none; color: inherit; display: flex; flex-direction: column; flex-grow: 1; }
.card-image { height: 180px; background: var(--accent-light); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.card-image img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s ease; }
.card:hover .card-image img { transform: scale(1.08); }
.card-body { padding: 16px; display: flex; flex-direction: column; flex-grow: 1; gap: 10px; }
.card-title { margin: 0; font-weight: 700; color: var(--text); font-size: 16px; line-height: 1.4; }
.card-price { font-weight: 800; color: var(--accent); font-size: 18px; }
.card-date { color: var(--muted); font-size: 12px; margin-top: auto; padding-top: 10px; border-top: 1px solid var(--border); }

.card-actions { padding: 12px 16px; border-top: 1px solid var(--border); display: flex; gap: 10px; background: var(--accent-light); }
.btn-card-edit { flex: 1; background: var(--accent); border: none; padding: 10px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-edit:hover { background: var(--accent-dark); transform: translateY(-2px); }
.btn-card-edit.disabled { background: #ccc; cursor: not-allowed; transform: none; }

.btn-card-delete { flex: 1; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); padding: 10px; border-radius: 8px; color: var(--danger); font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-delete:hover { background: var(--danger); color: white; transform: translateY(-2px); }

.btn-card-sold { flex: 1; background: #10b981; border: none; padding: 10px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-sold:hover { background: #059669; transform: translateY(-2px); }

/* Hold Button */
.btn-card-hold { flex: 1; background: #f59e0b; border: none; padding: 10px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-hold:hover { background: #d97706; transform: translateY(-2px); }

/* Relist Button */
.btn-card-relist { flex: 1; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border: none; padding: 10px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-relist:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); }

/* Unhold/Reactivate Button */
.btn-card-unhold { flex: 1; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); border: none; padding: 10px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 13px; transition: all 0.3s; }
.btn-card-unhold:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(75, 0, 130, 0.4); }

/* Type Badge for Events/Services */
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

/* Status Labels on Card Images */
.card-image { position: relative; }
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
}
.sold-label { background: linear-gradient(135deg, #10b981, #059669); color: white; }
.ended-label { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: 2px solid rgba(255, 255, 255, 0.3); }
.hold-label { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
.review-label { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
.rejected-label { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }

/* Relist Notice */
.relist-notice {
    margin-top: 8px;
    padding: 8px 12px;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 8px;
    font-size: 12px;
    color: #1d4ed8;
    font-weight: 600;
}

/* Rejected Card Styling */
.rejected-card { opacity: 0.85; }
.rejected-card:hover { opacity: 1; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(4px); }
.modal-overlay.is-visible { opacity: 1; pointer-events: auto; }
.modal-content { background: var(--panel); border-radius: 16px; padding: 24px; width: 100%; max-width: 450px; position: relative; transform: translateY(20px); transition: transform 0.3s; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); animation: slideUp 0.4s ease-out; }
.modal-overlay.is-visible .modal-content { transform: translateY(0); }
.modal-overlay h3 { margin: 0 0 16px; color: var(--text); font-weight: 800; font-size: 18px; }
.modal-close { position: absolute; top: 15px; right: 15px; background: none; border: none; color: var(--muted); font-size: 24px; cursor: pointer; transition: all 0.3s; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
.modal-close:hover { background: var(--accent-light); color: var(--accent); }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text); font-size: 14px; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 11px 14px; background: var(--accent-light); border: 1px solid var(--border); border-radius: 8px; color: var(--text); font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); background: white; }

.avatar-upload-container { display: flex; justify-content: center; margin-bottom: 16px; }
.avatar-edit-label { position: relative; cursor: pointer; width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--accent); overflow: hidden; background: var(--accent-light); transition: all 0.3s; }
.avatar-edit-label:hover { transform: scale(1.05); box-shadow: 0 0 20px rgba(139, 92, 246, 0.3); }
.avatar-overlay { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.6); display: flex; flex-direction: column; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; color: white; font-size: 13px; font-weight: 600; }
.avatar-edit-label:hover .avatar-overlay { opacity: 1; }
.avatar-preview-modal { width: 100%; height: 100%; object-fit: cover; }

.feedback { padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 600; animation: slideUp 0.4s ease-out; }
.feedback.success { background: var(--success); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
.feedback.error { background: var(--danger); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

/* Saved Items */
.saved-item { display: flex; gap: 15px; padding: 15px 0; border-bottom: 1px solid var(--border); text-decoration: none; color: var(--text); align-items: center; transition: all 0.3s; }
.saved-item:hover { background: var(--accent-light); padding-left: 8px; }
.saved-img { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); transition: transform 0.3s; }
.saved-item:hover .saved-img { transform: scale(1.05); }
.saved-info { flex: 1; }
.saved-info > div:first-child { font-weight: 700; font-size: 15px; color: var(--text); }
.unsave-btn { background: none; border: none; color: var(--muted); font-size: 18px; cursor: pointer; padding: 8px; transition: all 0.3s; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
.unsave-btn:hover { color: white; background: var(--danger); }
.empty-saved { padding: 40px 24px; text-align: center; color: var(--muted); }
.empty { padding: 40px; text-align: center; color: var(--muted); background: var(--panel); border-radius: 12px; border: 1px dashed var(--border); }

/* ---- Image Lightbox Modal ---- */
.image-modal {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.8);
    z-index: 3000;
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
.image-modal .modal-close {
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

/* Rejection Notice Styles */
.rejection-notice {
    margin-top: 10px;
    padding: 10px;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
}

.rejection-badge {
    color: var(--danger);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 6px;
}

.rejection-reason {
    font-size: 12px;
    color: #991b1b;
    line-height: 1.5;
    word-wrap: break-word;
}

.rejection-reason strong {
    font-weight: 700;
}

/* Image Preview Grid for Edit Modal */
.image-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}

.image-preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 10px;
    overflow: hidden;
    border: 2px solid var(--border);
    transition: all 0.3s;
}

.image-preview-item:hover {
    border-color: var(--accent);
}

.image-preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-preview-item .remove-image-btn {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    border: none;
    border-radius: 50%;
    color: white;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transform: scale(0.8);
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
}

.image-preview-item:hover .remove-image-btn {
    opacity: 1;
    transform: scale(1);
}

.image-preview-item .remove-image-btn:hover {
    transform: scale(1.1);
}

.image-preview-item.removed {
    opacity: 0.3;
    border-color: var(--danger);
}

.image-preview-item.removed::after {
    content: 'Removed';
    position: absolute;
    inset: 0;
    background: rgba(239, 68, 68, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.no-images-notice {
    padding: 20px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
    background: var(--accent-light);
    border-radius: 10px;
    border: 2px dashed var(--border);
}

/* Modern NEW Badge for newly added images */
.new-image-badge {
    position: absolute;
    bottom: 6px;
    left: 6px;
    background: #1a1a2e;
    color: white;
    font-size: 8px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 4px;
    letter-spacing: 0.8px;
    text-transform: uppercase;
}

/* New image border highlight */
.image-preview-item.new-image {
    border-color: var(--accent);
}

.disabled-field { opacity: 0.5; pointer-events: none; }
.form-actions { display: flex; gap: 10px; margin-top: 20px; }
.form-actions .btn { flex: 1; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-body { padding: 0 24px 24px; }

/* Radio Buttons for Sold Modal */
.radio-group { display: flex; gap: 15px; margin-bottom: 15px; }
.radio-option { flex: 1; position: relative; }
.radio-option input { opacity: 0; position: absolute; }
.radio-option label {
    display: block; padding: 10px; border: 2px solid var(--border); border-radius: 8px; 
    text-align: center; font-weight: 600; cursor: pointer; transition: 0.2s;
}
.radio-option input:checked + label {
    border-color: var(--accent); background: var(--accent-light); color: var(--accent);
}

@media (max-width: 768px) {
    .profile-card { flex-direction: column; align-items: center; text-align: center; gap: 20px; }
    .info { width: 100%; }
    .actions { justify-content: center; width: 100%; }
    .grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
    .tabs { gap: 10px; }
    .modal-content { max-width: 90%; max-height: 80vh; overflow-y: auto; }
    
    /* Mobile sidebar - GPU accelerated */
    .right-sidebar { width: 300px; transform: translateX(100%); transition: transform 0.3s ease-out; }
    .right-sidebar.open { transform: translateX(0); }
    .sidebar-toggle-btn { display: none; }
    .mobile-menu-btn { display: flex !important; }
    .header { padding-right: 20px; }
    .main-container { padding-right: 16px; }
}
@media (max-width: 480px) {
    .header { padding: 12px 16px; }
    .header .brand { font-size: 16px; }
    .controls { gap: 10px; }
    .avatar { width: 32px; height: 32px; }
    .letter-avatar { width: 32px; height: 32px; font-size: 14px; }
    
    .profile-card { padding: 20px; gap: 16px; }
    .profile-avatar-large { width: 80px; height: 80px; }
    .letter-avatar-large { width: 80px; height: 80px; font-size: 32px; }
    .info h2 { font-size: 20px; }
    .info-text { font-size: 12px; }
    .bio-display { font-size: 13px; margin-top: 12px; }
    .actions { 
        flex-direction: row; 
        width: 100%; 
        gap: 8px; 
        flex-wrap: wrap;
    }
    .btn { 
        flex: 1; 
        min-width: calc(50% - 4px);
        text-align: center; 
        padding: 8px 12px; 
        font-size: 11px; 
    }
    .btn-green { 
        width: 100%; 
        flex: none;
    }
    
    .main-container { padding: 20px 12px; }
    
    /* Cards - 2 per row with better mobile proportions */
    .grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .card { border-radius: 12px; }
    .card-image { height: 110px; }
    .card-body { padding: 10px; gap: 6px; }
    .card-title { 
        font-size: 12px; 
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .card-price { font-size: 14px; }
    .card-date { font-size: 10px; padding-top: 6px; }
    .type-badge { font-size: 9px; padding: 3px 6px; top: 6px; left: 6px; }
    .status-label { font-size: 9px; padding: 4px 8px; bottom: 6px; left: 6px; }
    
    /* Card action buttons - compact */
    .card-actions { 
        padding: 8px 10px; 
        gap: 6px;
        flex-wrap: wrap;
    }
    .btn-card-edit, .btn-card-delete, .btn-card-sold, .btn-card-hold, .btn-card-relist, .btn-card-unhold { 
        padding: 6px 8px; 
        font-size: 10px; 
        border-radius: 6px;
    }
    
    /* Tabs - smaller for mobile */
    .tabs { gap: 6px; margin-bottom: 16px; }
    .tab-btn { font-size: 12px; padding: 10px 2px; }
    
    /* Modal - smaller for mobile */
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
    
    /* Rejected modal specifics */
    #rejected-modal .modal-content { max-width: 85%; padding: 14px; }
    #rejected-item-image { height: 120px !important; margin-bottom: 10px !important; border-radius: 8px !important; }
    #rejected-modal [style*="font-size: 18px"] { font-size: 13px !important; }
    #rejected-modal [style*="font-size: 14px"] { font-size: 11px !important; }
    #rejected-modal [style*="font-size: 12px"] { font-size: 9px !important; }
    #rejected-modal [style*="padding: 16px"] { padding: 10px !important; }
    #rejected-modal [style*="margin-bottom: 16px"] { margin-bottom: 10px !important; }
    #rejected-modal .form-actions { margin-top: 12px !important; }
    #rejected-modal .btn { padding: 8px 14px; font-size: 12px; }
}
</style>
</head><body>

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
            <li><a href="profile.php" class="sidebar-link active"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Profile</a></li>
            <li><a href="messages.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>Messages<?php if($unread>0): ?><span class="menu-badge"><?= $unread ?></span><?php endif; ?></a></li>
            <li><a href="create.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>Create List</a></li>
            <li><a href="#" class="sidebar-link" onclick="openSavedModal(); toggleSidebar();"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>Bookmarks</a></li>
            <li><a href="help.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>Help</a></li>
            <li><a href="reports.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
    </div>
    <div class="sidebar-footer"></div>
</aside>

<div class="main-container">

    <main class="content-area">
        <?php if ($feedback_message): ?>
            <div class="feedback <?= $feedback_type ?>">
                <?= h($feedback_message) ?>
            </div>
        <?php endif; ?>

        <div class="profile-card">
            <?php if ($hasAvatar): ?>
                <img src="<?= h($userAvatar) ?>" class="profile-avatar-large" onclick="openImageModal('<?= h($userAvatar) ?>')">
            <?php else: ?>
                <div class="letter-avatar-large"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="info">
                <h2><?= h($user['name']) ?></h2>
                <div class="info-text"><?= h($user['matricNo']) ?></div>
                <div class="info-text"><?= h($user['email']) ?></div>
                
                <?php if ($totalReviews > 0): ?>
                    <div class="rating-badge">
                        <span class="rating-stars">★</span>
                        <span class="rating-text"><?= number_format($avgRating, 1) ?></span>
                        <span class="rating-count"><?= $totalReviews ?> review<?= $totalReviews !== 1 ? 's' : '' ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($user['bio'])): ?>
                    <div class="bio-display"><?= nl2br(h($user['bio'])) ?></div>
                <?php else: ?>
                    <div class="bio-display" style="color:var(--muted);font-style:italic">No bio added.</div>
                <?php endif; ?>
                <div class="actions">
                    <button class="btn btn-secondary" id="edit-profile-btn">Edit Profile</button>
                    <a href="messages.php" class="btn btn-secondary">Messages</a>
                    <a href="create.php" class="btn btn-green">Sell or Create Event</a>
                </div>
            </div>
        </div>

        <div style="margin-top: 32px;">
            <h3 style="color:var(--text); margin-bottom:16px; font-size:20px; font-weight:800;">Your Items</h3>
            <div class="tabs">
                <button class="tab-btn active" onclick="openTab(event, 'available')">Active (<?= count($lists['available']) ?>)</button>
                <button class="tab-btn" onclick="openTab(event, 'history')">History (<?= count($lists['history']) ?>)</button>
                <button class="tab-btn" onclick="openTab(event, 'under_review')">Under Review (<?= count($lists['under_review']) ?>)</button>
                <button class="tab-btn" onclick="openTab(event, 'rejected')" style="color:var(--danger)">Rejected (<?= count($lists['rejected']) ?>)</button>
            </div>

            <div id="available" class="tab-content active"><?php renderGridActive($lists['available']); ?></div>
            <div id="history" class="tab-content"><?php renderGridHistory($lists['history']); ?></div>
            <div id="under_review" class="tab-content"><?php renderGridReview($lists['under_review']); ?></div>
            <div id="rejected" class="tab-content"><?php renderGridRejected($lists['rejected']); ?></div>
        </div>
    </main>
</div>

<?php 
// ACTIVE TAB - Edit, Delete, Hold, Sold buttons
function renderGridActive($items) {
    if (empty($items)) { echo '<div class="empty">No active items yet.</div>'; return; }
    echo '<div class="grid">';
    foreach($items as $i => $it): 
        $img = !empty($it['image']) ? explode(',', $it['image'])[0] : 'avatar.png';
        $date = !empty($it['postDate']) ? date('d M Y', strtotime($it['postDate'])) : '';
        $updatedText = !empty($it['last_updated']) ? "Updated: " . date('d M', strtotime($it['last_updated'])) : "Posted: " . $date;
        $priceDisplay = ($it['category'] === 'Events') ? 'Event' : 'RM ' . number_format((float)$it['price'],2);
        $itemData = htmlspecialchars(json_encode($it), ENT_QUOTES, 'UTF-8');
        
        // Determine type label
        $typeLabel = '';
        $cat = strtolower(trim($it['category'] ?? ''));
        if (in_array($cat, ['event', 'events'])) { $typeLabel = 'Event'; }
        elseif (in_array($cat, ['service', 'services', 'peer-to-peer services'])) { $typeLabel = 'Services'; }
        
        // Determine if this is an event or service (both use 'ended' status)
        $isEventOrService = in_array($cat, ['event', 'events', 'service', 'services', 'peer-to-peer services']);
        $isService = in_array($cat, ['service', 'services', 'peer-to-peer services']);
        $soldButtonText = $isService ? 'End Service' : ($isEventOrService ? 'End Event' : 'Sold');
        ?>
        <div class="card" style="animation-delay: <?= $i * 0.05 ?>s;">
            <a href="item_detail.php?id=<?= (int)$it['ItemID'] ?>" class="card-content-link">
                <div class="card-image">
                    <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                    <img src="<?= htmlspecialchars(trim($img)) ?>" alt="<?= htmlspecialchars($it['title']) ?>">
                </div>
                <div class="card-body">
                    <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="card-price"><?= $priceDisplay ?></div>
                    <div class="card-date"><?= $updatedText ?></div>
                </div>
            </a>
            <div class="card-actions">
                <button type="button" class="btn-card-edit" onclick='openItemEdit(<?= $itemData ?>)'>Edit</button>
                <button type="button" class="btn-card-hold" onclick="confirmHold(<?= (int)$it['ItemID'] ?>)">Hold</button>
                <button type="button" class="btn-card-sold" onclick="openSoldModal(<?= (int)$it['ItemID'] ?>, <?= $isEventOrService ? 'true' : 'false' ?>, <?= (float)$it['price'] ?>)"><?= $soldButtonText ?></button>
            </div>
        </div>
        <?php
    endforeach;
    echo '</div>';
}

// HISTORY TAB - Shows sold/hold items with labels and Relist/Unhold buttons
function renderGridHistory($items) {
    if (empty($items)) { echo '<div class="empty">No items in history yet.</div>'; return; }
    echo '<div class="grid">';
    foreach($items as $i => $it): 
        $img = !empty($it['image']) ? explode(',', $it['image'])[0] : 'avatar.png';
        $date = !empty($it['postDate']) ? date('d M Y', strtotime($it['postDate'])) : '';
        
        // For sold items, show sold_price if available
        $status = strtolower($it['status']);
        $isSold = ($status === 'sold');
        
        if ($isSold && !empty($it['sold_price'])) {
            $priceDisplay = 'Sold: RM ' . number_format((float)$it['sold_price'], 2);
            // Show original price if different
            if ((float)$it['sold_price'] != (float)$it['price']) {
                $priceDisplay .= ' <span style="text-decoration:line-through;color:var(--muted);font-size:12px;">(RM ' . number_format((float)$it['price'], 2) . ')</span>';
            }
        } elseif ($it['category'] === 'Events') {
            $priceDisplay = 'Event';
        } else {
            $priceDisplay = 'RM ' . number_format((float)$it['price'], 2);
        }
        
        $isEnded = ($status === 'ended');
        $isHold = ($status === 'hold');
        
        // Show sold date if available
        $soldDateText = '';
        if ($isSold && !empty($it['sold_date'])) {
            $soldDateText = 'Sold: ' . date('d M Y', strtotime($it['sold_date']));
        }
        
        // Determine type label
        $typeLabel = '';
        $cat = strtolower(trim($it['category'] ?? ''));
        if (in_array($cat, ['event', 'events'])) { $typeLabel = 'Event'; }
        elseif (in_array($cat, ['service', 'services', 'peer-to-peer services'])) { $typeLabel = 'Services'; }
        
        // Determine status label text and class
        if ($isEnded) {
            $statusLabelText = 'EVENT ENDED';
            $statusLabelClass = 'ended-label';
        } elseif ($isSold) {
            $statusLabelText = 'SOLD';
            $statusLabelClass = 'sold-label';
        } else {
            $statusLabelText = 'ON HOLD';
            $statusLabelClass = 'hold-label';
        }
        ?>
        <div class="card" style="animation-delay: <?= $i * 0.05 ?>s;">
            <a href="item_detail.php?id=<?= (int)$it['ItemID'] ?>" class="card-content-link">
                <div class="card-image">
                    <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                    <img src="<?= htmlspecialchars(trim($img)) ?>" alt="<?= htmlspecialchars($it['title']) ?>">
                    <?php if($isSold || $isEnded || $isHold): ?>
                        <div class="status-label <?= $statusLabelClass ?>"><?= $statusLabelText ?></div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="card-price"><?= $priceDisplay ?></div>
                    <div class="card-date"><?= $soldDateText ?: 'Posted: ' . $date ?></div>
                </div>
            </a>
            <div class="card-actions">
                <?php if($isSold): ?>
                    <button type="button" class="btn-card-relist" onclick="confirmRelist(<?= (int)$it['ItemID'] ?>)">Relist</button>
                    <button type="button" class="btn-card-delete" onclick="confirmDelete(<?= (int)$it['ItemID'] ?>)">Delete</button>
                <?php elseif($isEnded): ?>
                    <!-- Events cannot be relisted, only deleted -->
                    <button type="button" class="btn-card-delete" onclick="confirmDelete(<?= (int)$it['ItemID'] ?>)" style="width:100%;">Delete</button>
                <?php elseif($isHold): ?>
                    <button type="button" class="btn-card-unhold" onclick="confirmUnhold(<?= (int)$it['ItemID'] ?>)">Reactivate</button>
                    <button type="button" class="btn-card-delete" onclick="confirmDelete(<?= (int)$it['ItemID'] ?>)">Delete</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    endforeach;
    echo '</div>';
}

// UNDER REVIEW TAB - View only, no actions except delete
function renderGridReview($items) {
    if (empty($items)) { echo '<div class="empty">No items under review.</div>'; return; }
    echo '<div class="grid">';
    foreach($items as $i => $it): 
        $img = !empty($it['image']) ? explode(',', $it['image'])[0] : 'avatar.png';
        $date = !empty($it['postDate']) ? date('d M Y', strtotime($it['postDate'])) : '';
        $priceDisplay = ($it['category'] === 'Events') ? 'Event' : 'RM ' . number_format((float)$it['price'],2);
        $isRelisted = !empty($it['is_relisted']);
        
        // Determine type label
        $typeLabel = '';
        $cat = strtolower(trim($it['category'] ?? ''));
        if (in_array($cat, ['event', 'events'])) { $typeLabel = 'Event'; }
        elseif (in_array($cat, ['service', 'services', 'peer-to-peer services'])) { $typeLabel = 'Services'; }
        ?>
        <div class="card" style="animation-delay: <?= $i * 0.05 ?>s;">
            <a href="item_detail.php?id=<?= (int)$it['ItemID'] ?>" class="card-content-link">
                <div class="card-image">
                    <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                    <img src="<?= htmlspecialchars(trim($img)) ?>" alt="<?= htmlspecialchars($it['title']) ?>">
                    <div class="status-label review-label"><?= $isRelisted ? 'RELISTED' : 'PENDING' ?></div>
                </div>
                <div class="card-body">
                    <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="card-price"><?= $priceDisplay ?></div>
                    <div class="card-date">Submitted: <?= $date ?></div>
                    <?php if($isRelisted): ?>
                        <div class="relist-notice">🔄 Relisted item awaiting approval</div>
                    <?php endif; ?>
                </div>
            </a>
            <div class="card-actions">
                <button type="button" class="btn-card-delete" onclick="confirmDelete(<?= (int)$it['ItemID'] ?>)">Cancel & Delete</button>
            </div>
        </div>
        <?php
    endforeach;
    echo '</div>';
}

// REJECTED TAB - Shows popup instead of navigating to detail page
function renderGridRejected($items) {
    if (empty($items)) { echo '<div class="empty">No rejected items.</div>'; return; }
    echo '<div class="grid">';
    foreach($items as $i => $it): 
        $img = !empty($it['image']) ? explode(',', $it['image'])[0] : 'avatar.png';
        $date = !empty($it['postDate']) ? date('d M Y', strtotime($it['postDate'])) : '';
        $priceDisplay = ($it['category'] === 'Events') ? 'Event' : 'RM ' . number_format((float)$it['price'],2);
        $rejectionReason = $it['rejection_reason'] ?? 'No reason provided';
        $itemData = htmlspecialchars(json_encode($it), ENT_QUOTES, 'UTF-8');
        
        // Determine type label
        $typeLabel = '';
        $cat = strtolower(trim($it['category'] ?? ''));
        if (in_array($cat, ['event', 'events'])) { $typeLabel = 'Event'; }
        elseif (in_array($cat, ['service', 'services', 'peer-to-peer services'])) { $typeLabel = 'Services'; }
        ?>
        <div class="card rejected-card" style="animation-delay: <?= $i * 0.05 ?>s;">
            <div class="card-content-link" onclick='openRejectedModal(<?= $itemData ?>)' style="cursor:pointer;">
                <div class="card-image">
                    <?php if ($typeLabel): ?><span class="type-badge"><?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                    <img src="<?= htmlspecialchars(trim($img)) ?>" alt="<?= htmlspecialchars($it['title']) ?>">
                    <div class="status-label rejected-label">REJECTED</div>
                </div>
                <div class="card-body">
                    <div class="card-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="card-price"><?= $priceDisplay ?></div>
                    <div class="card-date">Posted: <?= $date ?></div>
                    <div class="rejection-notice">
                        <div class="rejection-badge">❌ Reason:</div>
                        <div class="rejection-reason"><?= htmlspecialchars($rejectionReason) ?></div>
                    </div>
                </div>
            </div>
            <div class="card-actions">
                <button type="button" class="btn-card-delete" onclick="confirmDelete(<?= (int)$it['ItemID'] ?>)">Delete</button>
            </div>
        </div>
        <?php
    endforeach;
    echo '</div>';
}
?>

<!-- MODALS -->
<div class="modal-overlay" id="savedModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Saved Items</h3><button class="modal-close" onclick="closeModal('savedModal')">&times;</button></div>
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

<!-- NEW SOLD MODAL -->
<div class="modal-overlay" id="sold-modal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="sold-modal-title">Mark as Sold</h3><button class="modal-close" onclick="closeModal('sold-modal')">&times;</button></div>
        <div class="modal-body">
            <p id="sold-modal-question" style="margin-bottom:20px; color:var(--muted);">Who bought this item?</p>
            <form method="POST">
                <input type="hidden" name="mark_sold_id" id="sold_item_id_input">
                <input type="hidden" id="sold_original_price" value="0">
                
                <div class="radio-group" id="sold-buyer-selection">
                    <div class="radio-option">
                        <input type="radio" name="sold_type" id="sold_outside" value="outside" checked onchange="toggleSoldUser(false)">
                        <label for="sold_outside">Outside</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="sold_type" id="sold_user" value="user" onchange="toggleSoldUser(true)">
                        <label for="sold_user">Platform User</label>
                    </div>
                </div>

                <div id="sold-user-select" style="display:none; margin-bottom:20px;">
                    <label style="display:block;margin-bottom:8px;font-weight:600;">Select Buyer</label>
                    <select name="buyer_user_id" class="form-control" style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--border);">
                        <option value="">-- Choose User --</option>
                        <?php foreach($allUsersList as $u): ?>
                            <option value="<?= $u['UserID'] ?>"><?= h($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="sold-price-section" style="margin-bottom:20px;">
                    <label style="display:block;margin-bottom:8px;font-weight:600;">Sold Price (RM)</label>
                    <input type="number" name="sold_price" id="sold_price_input" step="0.01" min="0" 
                           class="form-control" style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--border);font-size:16px;" 
                           placeholder="Enter sold price">
                    <p style="font-size:12px;color:var(--muted);margin-top:6px;">Leave blank to use original listing price</p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-green" id="sold-confirm-btn">Confirm Sold</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('sold-modal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="edit-modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('edit-modal')">&times;</button>
        <h3>Edit Profile</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group" style="text-align:center">
                <label>Profile Picture</label>
                <div class="avatar-upload-container">
                    <label for="p-upload" class="avatar-edit-label"><img src="<?= h($userAvatar) ?>" class="avatar-preview-modal" id="p-preview"><div class="avatar-overlay">Change Photo</div></label>
                    <input type="file" name="profile_image" id="p-upload" accept="image/*" style="display:none">
                </div>
            </div>
            <div class="form-group"><label>Name</label><input type="text" name="name" value="<?= h($user['name']) ?>" required></div>
            <div class="form-group"><label>Bio</label><textarea name="bio" rows="4"><?= h($user['bio'] ?? '') ?></textarea></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save Changes</button><button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">Cancel</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="item-edit-modal">
    <div class="modal-content" style="max-width: 500px;">
        <button class="modal-close" onclick="closeModal('item-edit-modal')">&times;</button>
        <h3>Edit Item</h3>
        <form method="POST" enctype="multipart/form-data" id="item-edit-form">
            <input type="hidden" name="edit_item_id" id="edit_item_id">
            <input type="hidden" name="item_status" value="available">
            <input type="hidden" name="keep_images" id="keep_images">
            
            <div class="form-group"><label>Title</label><input type="text" name="item_title" id="item_title" required></div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="form-group"><label>Price</label><input type="number" name="item_price" id="item_price" step="0.01"></div>
                <div class="form-group"><label>Category</label><select name="item_category" id="item_category"><option value="Academics & Study Materials">Academics</option><option value="Housing & Dorm Living">Housing</option><option value="Electronics & Tech">Electronics</option><option value="Clothing & Accessories">Clothing</option><option value="Transportation & Travel">Transport</option><option value="Peer-to-Peer Services">Services</option><option value="Events">Events</option><option value="Garage Sale">Garage Sale</option></select></div>
            </div>
            
            <!-- Current Images Preview -->
            <div class="form-group">
                <label>Current Photos</label>
                <div id="current-images-preview" class="image-preview-grid">
                    <div class="no-images-notice">No images</div>
                </div>
                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Click × to remove an image</div>
            </div>
            
            <!-- Add New Images -->
            <div class="form-group">
                <label>Add New Photos</label>
                <div id="new-images-preview" class="image-preview-grid" style="display: none;"></div>
                <div style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                    <label for="item_images_input" class="btn btn-secondary" style="cursor: pointer; margin: 0; flex: 1; text-align: center;">
                        <i class="fas fa-plus"></i> Choose Photos
                    </label>
                    <input type="file" name="item_images[]" id="item_images_input" multiple accept="image/*" style="display: none;">
                </div>
                <div style="font-size: 12px; color: var(--muted); margin-top: 6px;">Select multiple photos or click again to add more</div>
            </div>
            
            <div class="form-group"><label>Description</label><textarea name="item_desc" id="item_desc" rows="4"></textarea></div>
            
            <div class="form-actions" style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn" style="background-color: #ef4444; color: white; margin-right: auto;" onclick="confirmDelete(document.getElementById('edit_item_id').value)">Delete Item</button>
                <button type="submit" class="btn btn-primary">Update Item</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('item-edit-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<form id="delete-form" method="POST" style="display:none;"><input type="hidden" name="delete_item_id" id="delete_item_id"></form>
<form id="hold-form" method="POST" style="display:none;"><input type="hidden" name="hold_item_id" id="hold_item_id"></form>
<form id="unhold-form" method="POST" style="display:none;"><input type="hidden" name="unhold_item_id" id="unhold_item_id"></form>
<form id="relist-form" method="POST" style="display:none;"><input type="hidden" name="relist_item_id" id="relist_item_id"></form>

<!-- REJECTED ITEM DETAIL MODAL -->
<div class="modal-overlay" id="rejected-modal">
    <div class="modal-content" style="max-width: 550px;">
        <button class="modal-close" onclick="closeModal('rejected-modal')">&times;</button>
        <h3 style="color: var(--danger);">❌ Rejected Item</h3>
        
        <div style="margin-top: 20px;">
            <div id="rejected-item-image" style="width: 100%; height: 250px; border-radius: 12px; overflow: hidden; margin-bottom: 20px; background: var(--accent-light);">
                <img id="rejected-img" src="" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            
            <div style="margin-bottom: 16px;">
                <div style="font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Title</div>
                <div id="rejected-title" style="font-size: 20px; font-weight: 800; color: var(--text);"></div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <div style="font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Price</div>
                    <div id="rejected-price" style="font-size: 18px; font-weight: 700; color: var(--accent);"></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Category</div>
                    <div id="rejected-category" style="font-size: 14px; font-weight: 600; color: var(--text);"></div>
                </div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <div style="font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Description</div>
                <div id="rejected-desc" style="font-size: 14px; color: var(--text); line-height: 1.6; max-height: 100px; overflow-y: auto;"></div>
            </div>
            
            <div style="padding: 16px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px;">
                <div style="font-size: 12px; color: var(--danger); text-transform: uppercase; font-weight: 700; margin-bottom: 8px;">⚠️ Rejection Reason</div>
                <div id="rejected-reason" style="font-size: 14px; color: #991b1b; line-height: 1.6;"></div>
            </div>
        </div>
        
        <div class="form-actions" style="margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('rejected-modal')">Close</button>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="image-modal" id="imageModal" onclick="closeImageModal()" aria-hidden="true" role="dialog">
    <img id="modalImage" alt="Profile image" onclick="event.stopPropagation()">
    <button class="modal-close" aria-label="Close" onclick="closeImageModal(event)">&times;</button>
</div>

<script>
function openTab(e, n) {
    var i, c, l;
    c = document.getElementsByClassName("tab-content");
    for (i = 0; i < c.length; i++) c[i].classList.remove("active");
    l = document.getElementsByClassName("tab-btn");
    for (i = 0; i < l.length; i++) l[i].classList.remove("active");
    document.getElementById(n).classList.add("active");
    e.currentTarget.classList.add("active");
}

function closeModal(id) { document.getElementById(id).classList.remove('is-visible'); }
function openSavedModal() { document.getElementById('savedModal').classList.add('is-visible'); }
function toggleSidebar() { const sidebar = document.getElementById('sidebar'); const overlay = document.querySelector('.sidebar-overlay'); sidebar.classList.toggle('open'); overlay.classList.toggle('active'); }

// Profile image preview
document.getElementById('p-upload')?.addEventListener('change', function() {
    const f = this.files[0];
    if (f) { const r = new FileReader(); r.onload = function(e) { document.getElementById('p-preview').src = e.target.result; }; r.readAsDataURL(f); }
});

document.getElementById('edit-profile-btn')?.addEventListener('click', () => { document.getElementById('edit-modal').classList.add('is-visible'); });

// Track which images to keep
let keepImages = [];

function openItemEdit(item) {
    document.getElementById('edit_item_id').value = item.ItemID;
    document.getElementById('item_title').value = item.title;
    document.getElementById('item_price').value = item.price;
    document.getElementById('item_desc').value = item.description || '';
    document.getElementById('item_category').value = item.category;
    
    // Reset file input
    const fileInput = document.getElementById('item_images_input');
    if (fileInput) fileInput.value = '';
    
    // Parse and display existing images
    const previewContainer = document.getElementById('current-images-preview');
    previewContainer.innerHTML = '';
    
    keepImages = []; // Reset
    
    if (item.image && item.image.trim()) {
        const images = item.image.split(',').map(img => img.trim()).filter(img => img);
        
        if (images.length > 0) {
            images.forEach((imgSrc, index) => {
                keepImages.push(imgSrc);
                
                const imgItem = document.createElement('div');
                imgItem.className = 'image-preview-item';
                imgItem.setAttribute('data-image', imgSrc);
                imgItem.innerHTML = `
                    <img src="${imgSrc}" alt="Image ${index + 1}" onerror="this.src='avatar.png'">
                    <button type="button" class="remove-image-btn" onclick="removeEditImage('${imgSrc}', this)" title="Remove image">&times;</button>
                `;
                previewContainer.appendChild(imgItem);
            });
        } else {
            previewContainer.innerHTML = '<div class="no-images-notice">No images uploaded</div>';
        }
    } else {
        previewContainer.innerHTML = '<div class="no-images-notice">No images uploaded</div>';
    }
    
    // Update hidden field
    document.getElementById('keep_images').value = keepImages.join(',');
    
    // Reset new files preview
    resetNewFilesPreview();
    
    document.getElementById('item-edit-modal').classList.add('is-visible');
}

// Remove image from the edit preview
function removeEditImage(imageSrc, btnElement) {
    // Remove from keepImages array
    keepImages = keepImages.filter(img => img !== imageSrc);
    
    // Update hidden field
    document.getElementById('keep_images').value = keepImages.join(',');
    
    // Visual feedback - mark as removed or hide
    const imgItem = btnElement.closest('.image-preview-item');
    if (imgItem) {
        imgItem.style.display = 'none';
    }
    
    // Show "no images" notice if all removed
    const previewContainer = document.getElementById('current-images-preview');
    const visibleItems = previewContainer.querySelectorAll('.image-preview-item[style*="display: none"]');
    const allItems = previewContainer.querySelectorAll('.image-preview-item');
    
    if (visibleItems.length === allItems.length) {
        previewContainer.innerHTML = '<div class="no-images-notice">All images removed. Add new photos below.</div>';
    }
}

// Track new files to upload
let newFilesToUpload = [];

// Handle new image file selection with preview
document.getElementById('item_images_input')?.addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    if (files.length === 0) return;
    
    // Add new files to our array
    files.forEach(file => {
        if (file.type.startsWith('image/')) {
            newFilesToUpload.push(file);
        }
    });
    
    // Update previews
    updateNewImagesPreview();
    
    // Update the actual file input with all accumulated files
    updateFileInput();
});

function updateNewImagesPreview() {
    const previewContainer = document.getElementById('new-images-preview');
    previewContainer.innerHTML = '';
    
    if (newFilesToUpload.length === 0) {
        previewContainer.style.display = 'none';
        return;
    }
    
    previewContainer.style.display = 'grid';
    
    newFilesToUpload.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const imgItem = document.createElement('div');
            imgItem.className = 'image-preview-item new-image';
            imgItem.setAttribute('data-index', index);
            imgItem.innerHTML = `
                <img src="${e.target.result}" alt="New image ${index + 1}">
                <button type="button" class="remove-image-btn" onclick="removeNewImage(${index})" title="Remove image">&times;</button>
                <span class="new-image-badge">NEW</span>
            `;
            previewContainer.appendChild(imgItem);
        };
        reader.readAsDataURL(file);
    });
}

function removeNewImage(index) {
    // Remove from array
    newFilesToUpload.splice(index, 1);
    
    // Update previews
    updateNewImagesPreview();
    
    // Update file input
    updateFileInput();
}

function updateFileInput() {
    // Create a new DataTransfer to hold our files
    const dt = new DataTransfer();
    newFilesToUpload.forEach(file => dt.items.add(file));
    
    // Update the file input
    document.getElementById('item_images_input').files = dt.files;
}

// Reset new files when modal opens
function resetNewFilesPreview() {
    newFilesToUpload = [];
    const previewContainer = document.getElementById('new-images-preview');
    if (previewContainer) {
        previewContainer.innerHTML = '';
        previewContainer.style.display = 'none';
    }
}

// NEW FUNCTION: Open Sold Modal
function openSoldModal(itemId, isEvent, itemPrice = 0) {
    document.getElementById('sold_item_id_input').value = itemId;
    document.getElementById('sold_original_price').value = itemPrice;
    
    const modalTitle = document.getElementById('sold-modal-title');
    const modalQuestion = document.getElementById('sold-modal-question');
    const buyerSelection = document.getElementById('sold-buyer-selection');
    const userSelect = document.getElementById('sold-user-select');
    const priceSection = document.getElementById('sold-price-section');
    const priceInput = document.getElementById('sold_price_input');
    const confirmBtn = document.getElementById('sold-confirm-btn');
    
    if (isEvent) {
        // Event-specific content
        modalTitle.textContent = 'End Event';
        modalQuestion.textContent = 'Are you sure you want to end this event?';
        buyerSelection.style.display = 'none'; // Hide buyer selection radio buttons
        userSelect.style.display = 'none'; // Hide user dropdown
        priceSection.style.display = 'none'; // Hide price section for events
        confirmBtn.textContent = 'Confirm End Event';
    } else {
        // Regular item content
        modalTitle.textContent = 'Mark as Sold';
        modalQuestion.textContent = 'Who bought this item?';
        buyerSelection.style.display = 'flex'; // Show buyer selection
        priceSection.style.display = 'block'; // Show price section
        priceInput.value = itemPrice > 0 ? itemPrice.toFixed(2) : ''; // Pre-fill with original price
        priceInput.placeholder = itemPrice > 0 ? 'Original: RM ' + itemPrice.toFixed(2) : 'Enter sold price';
        confirmBtn.textContent = 'Confirm Sold';
        
        // Reset form defaults for items
        document.getElementById('sold_outside').checked = true;
        toggleSoldUser(false);
    }
    
    document.getElementById('sold-modal').classList.add('is-visible');
}

function toggleSoldUser(isUser) {
    const selector = document.getElementById('sold-user-select');
    selector.style.display = isUser ? 'block' : 'none';
}

function confirmDelete(id) {
    if (confirm("Delete this listing? This cannot be undone.")) {
        document.getElementById('delete_item_id').value = id;
        document.getElementById('delete-form').submit();
    }
}

// Hold Item
function confirmHold(id) {
    if (confirm("Put this item on hold? It will be hidden from the marketplace but you can reactivate it later.")) {
        document.getElementById('hold_item_id').value = id;
        document.getElementById('hold-form').submit();
    }
}

// Unhold/Reactivate Item
function confirmUnhold(id) {
    if (confirm("Reactivate this item? It will be visible on the marketplace again.")) {
        document.getElementById('unhold_item_id').value = id;
        document.getElementById('unhold-form').submit();
    }
}

// Relist Sold Item
function confirmRelist(id) {
    if (confirm("Relist this item? It will go through admin review again before being published.")) {
        document.getElementById('relist_item_id').value = id;
        document.getElementById('relist-form').submit();
    }
}

// Open Rejected Item Modal
function openRejectedModal(item) {
    const img = item.image ? item.image.split(',')[0] : 'uploads/avatars/default.png';
    document.getElementById('rejected-img').src = img;
    document.getElementById('rejected-title').textContent = item.title;
    document.getElementById('rejected-price').textContent = item.category === 'Events' ? 'Event' : 'RM ' + parseFloat(item.price).toFixed(2);
    document.getElementById('rejected-category').textContent = item.category;
    document.getElementById('rejected-desc').textContent = item.description || 'No description';
    document.getElementById('rejected-reason').textContent = item.rejection_reason || 'No reason provided by administrator.';
    
    document.getElementById('rejected-modal').classList.add('is-visible');
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('is-visible'); });
});

window.addEventListener('load', () => {
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.animation = `slideUp 0.5s ease-out ${index * 0.05}s forwards`;
    });
});

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
</script>
</body>
</html>