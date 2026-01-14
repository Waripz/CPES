<?php
session_start();
// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['UserID'])) { header('Location: index.html'); exit; }

$myID = (int)$_SESSION['UserID'];
$myName = $_SESSION['name'] ?? 'User';

require_once 'config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { die("Connection failed."); }

// --- ACTIONS (Delete, Edit, Rate, Report) ---
if (isset($_POST['delete_msg_id'])) {
    $msgID = (int)$_POST['delete_msg_id'];
    $activeChat = (int)$_POST['return_to_chat'];
    $contextItem = isset($_POST['return_to_item']) ? (int)$_POST['return_to_item'] : 0;
    $check = $pdo->prepare("SELECT timestamp FROM message WHERE MessageID = ? AND senderID = ?");
    $check->execute([$msgID, $myID]);
    $data = $check->fetch();
    if ($data && (time() - strtotime($data['timestamp']) <= 3600)) {
        $pdo->prepare("DELETE FROM message WHERE MessageID = ?")->execute([$msgID]);
    }
    $redirectUrl = "messages.php?to=" . $activeChat;
    if ($contextItem > 0) {
        $redirectUrl .= "&context_item=" . $contextItem;
    }
    header("Location: " . $redirectUrl); exit;
}
if (isset($_POST['edit_msg_id'])) {
    $msgID = (int)$_POST['edit_msg_id'];
    $newText = trim($_POST['new_message_text']);
    $activeChat = (int)$_POST['return_to_chat'];
    $contextItem = isset($_POST['return_to_item']) ? (int)$_POST['return_to_item'] : 0;
    $check = $pdo->prepare("SELECT timestamp FROM message WHERE MessageID = ? AND senderID = ?");
    $check->execute([$msgID, $myID]);
    $data = $check->fetch();
    if ($data && !empty($newText) && (time() - strtotime($data['timestamp']) <= 3600)) {
        $pdo->prepare("UPDATE message SET message = ? WHERE MessageID = ?")->execute([$newText, $msgID]);
    }
    $redirectUrl = "messages.php?to=" . $activeChat;
    if ($contextItem > 0) {
        $redirectUrl .= "&context_item=" . $contextItem;
    }
    header("Location: " . $redirectUrl); exit;
}
if (isset($_POST['action_type'])) {
    $targetID = (int)$_POST['target_user_id'];
    if ($_POST['action_type'] === 'rate') {
        // Check if review already exists; update instead of insert
        $check = $pdo->prepare("SELECT reviewID FROM reviews WHERE reviewerID = ? AND targetUserID = ?");
        $check->execute([$myID, $targetID]);
        if ($check->fetch()) {
            // Update existing review
            $pdo->prepare("UPDATE reviews SET rating = ?, comment = ?, reviewDate = NOW() WHERE reviewerID = ? AND targetUserID = ?")
                ->execute([$_POST['rating'], $_POST['comment'], $myID, $targetID]);
        } else {
            // Insert new review
            $pdo->prepare("INSERT INTO reviews (reviewerID, targetUserID, rating, comment, reviewDate) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$myID, $targetID, $_POST['rating'], $_POST['comment']]);
        }
    } elseif ($_POST['action_type'] === 'report') {
        $reportType = $_POST['report_type'] ?? 'general';
        $pdo->prepare("INSERT INTO report (reason, submitDate, UserID, reportedUserID, report_type) VALUES (?, NOW(), ?, ?, ?)")
            ->execute([$_POST['reason'], $myID, $targetID, $reportType]);
    }
    $rateReportContext = isset($_POST['context_item']) ? (int)$_POST['context_item'] : 0;
    $redirectUrl = "messages.php?to=" . $targetID;
    if ($rateReportContext > 0) {
        $redirectUrl .= "&context_item=" . $rateReportContext;
    }
    header("Location: " . $redirectUrl); exit;
}

// Fetch Context Item
$contextItem = null;
if (isset($_GET['context_item'])) {
    $cStmt = $pdo->prepare("SELECT title, price, image FROM item WHERE ItemID = ?");
    $cStmt->execute([(int)$_GET['context_item']]);
    $contextItem = $cStmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch Saved Items for Bookmarks
require_once 'saved_items_helper.php';
$savedItems = saved_fetch_items($pdo, $myID, true);

// Fetch Conversations List
$sql = "SELECT u.UserID, u.name, u.profile_image,
        (SELECT COUNT(*) FROM message m2 WHERE m2.senderID = u.UserID AND m2.recipientUserID = ? AND m2.is_read = 0) as unread_count
        FROM users u
        JOIN message m ON (u.UserID = m.senderID OR u.UserID = m.recipientUserID)
        WHERE (m.senderID = ? OR m.recipientUserID = ?) AND u.UserID != ?
        GROUP BY u.UserID
        ORDER BY unread_count DESC, MAX(m.timestamp) DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$myID, $myID, $myID, $myID]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if context_info column exists to guard queries
$hasContextInfo = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM message LIKE 'context_info'")->fetch();
    $hasContextInfo = (bool)$colCheck;
} catch (Exception $e) {}

// Build per-item threads for sidebar (like FB Marketplace)
$threads = [];

if ($hasContextInfo) {
    foreach ($conversations as $c) {
        $partnerID = (int)$c['UserID'];
        // 1) Item-based contexts (distinct titles or IDs discussed)
        try {
            $ctxStmt = $pdo->prepare("SELECT context_info, MAX(timestamp) AS last_time
                                       FROM message
                                       WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?))
                                         AND context_info IS NOT NULL AND context_info != ''
                                       GROUP BY context_info
                                       ORDER BY last_time DESC");
            $ctxStmt->execute([$myID, $partnerID, $partnerID, $myID]);
            $ctxRows = $ctxStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ctxRows as $row) {
                $title = $row['context_info'];
                $item = null;
                $thumb = 'uploads/avatars/default.png';
                $sold = false; $itemID = null; $price = null; $resolvedTitle = $title;
                if (!empty($title)) {
                    $isNumericCtx = ctype_digit((string)$title);
                    if ($isNumericCtx) {
                        $it = $pdo->prepare("SELECT ItemID, title, image, price, status, BuyerID FROM item WHERE ItemID = ? LIMIT 1");
                        $it->execute([(int)$title]);
                    } else {
                        $it = $pdo->prepare("SELECT ItemID, title, image, price, status, BuyerID FROM item WHERE title = ? LIMIT 1");
                        $it->execute([$title]);
                    }
                    $item = $it->fetch(PDO::FETCH_ASSOC);
                    if ($item) {
                        $itemID = (int)$item['ItemID'];
                        $resolvedTitle = $item['title'];
                        $thumbs = !empty($item['image']) ? explode(',', $item['image']) : [];
                        // Check if image file actually exists, otherwise use default
                        $potentialThumb = !empty($thumbs) ? trim($thumbs[0]) : '';
                        $thumb = (!empty($potentialThumb) && file_exists($potentialThumb)) ? $potentialThumb : $thumb;
                        $sold = (isset($item['status']) && strtolower($item['status']) === 'sold');
                        $soldToMe = ($sold && isset($item['BuyerID']) && (int)$item['BuyerID'] === $myID);
                        $price = $item['price'] ?? null;
                    }
                }
                // Unread for this context
                $unreadCtx = 0;
                $totalMsgCount = 0;
                try {
                    $u = $pdo->prepare("SELECT COUNT(*) FROM message WHERE senderID = ? AND recipientUserID = ? AND is_read = 0 AND context_info = ?");
                    $u->execute([$partnerID, $myID, $title]);
                    $unreadCtx = (int)$u->fetchColumn();
                    
                    // Count total messages in this context to verify thread should exist
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) AND context_info = ?");
                    $countStmt->execute([$myID, $partnerID, $partnerID, $myID, $title]);
                    $totalMsgCount = (int)$countStmt->fetchColumn();
                } catch (Exception $e) {}

                // Only add thread if there are actual messages
                if ($totalMsgCount > 0) {
                    $threads[] = [
                        'partnerID' => $partnerID,
                        'partnerName' => $c['name'],
                        'partnerImg' => $c['profile_image'] ?: 'uploads/avatars/default.png',
                        'itemID' => $itemID,
                        'itemTitle' => $resolvedTitle,
                        'itemThumb' => $thumb,
                        'itemPrice' => $price,
                        'isSold' => $sold,
                        'isSoldToMe' => $soldToMe,
                        'last_time' => $row['last_time'],
                        'unread' => $unreadCtx,
                    ];
                }
            }
        } catch (Exception $e) {}

        // 2) General chat with no item context (keep at bottom if exists)
        try {
            $lastGen = $pdo->prepare("SELECT MAX(timestamp) FROM message WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) AND (context_info IS NULL OR context_info = '')");
            $lastGen->execute([$myID, $partnerID, $partnerID, $myID]);
            $lg = $lastGen->fetchColumn();
            if ($lg) {
                $unreadGen = 0;
                $u2 = $pdo->prepare("SELECT COUNT(*) FROM message WHERE senderID = ? AND recipientUserID = ? AND is_read = 0 AND (context_info IS NULL OR context_info = '')");
                $u2->execute([$partnerID, $myID]);
                $unreadGen = (int)$u2->fetchColumn();
                $threads[] = [
                    'partnerID' => $partnerID,
                    'partnerName' => $c['name'],
                    'partnerImg' => $c['profile_image'] ?: 'uploads/avatars/default.png',
                    'itemID' => null,
                    'itemTitle' => 'General chat',
                    'itemThumb' => $c['profile_image'] ?: 'uploads/avatars/default.png',
                    'itemPrice' => null,
                    'itemPrice' => null,
                    'isSold' => false,
                    'isSoldToMe' => false,
                    'last_time' => $lg,
                    'unread' => $unreadGen,
                ];
            }
        } catch (Exception $e) {}
    }
} else {
    // Fallback: no context_info column; show per-user chats
    foreach ($conversations as $c) {
        $threads[] = [
            'partnerID' => (int)$c['UserID'],
            'partnerName' => $c['name'],
            'partnerImg' => $c['profile_image'] ?: 'uploads/avatars/default.png',
            'itemID' => null,
            'itemTitle' => $c['name'],
            'itemThumb' => $c['profile_image'] ?: 'uploads/avatars/default.png',
            'itemPrice' => null,
            'itemPrice' => null,
            'isSold' => false,
            'isSoldToMe' => false,
            'last_time' => null,
            'unread' => (int)$c['unread_count'],
        ];
    }
}

// Sort threads by last activity desc (fallback leaves nulls at end)
usort($threads, function($a,$b){
    $ta = $a['last_time'] ? strtotime($a['last_time']) : 0;
    $tb = $b['last_time'] ? strtotime($b['last_time']) : 0;
    return $tb <=> $ta;
});

// Active Chat Logic
$activeChatID = isset($_GET['to']) ? (int)$_GET['to'] : 0;
$messages = [];
$chatPartner = null;
$isTrusted = false;
$lastMsgID = 0;

// Prevent self-messaging
if ($activeChatID > 0 && $activeChatID == $myID) {
    header('Location: messages.php');
    exit;
}

if ($activeChatID > 0) {
    $uStmt = $pdo->prepare("SELECT UserID, name, profile_image FROM users WHERE UserID = ?");
    $uStmt->execute([$activeChatID]);
    $chatPartner = $uStmt->fetch(PDO::FETCH_ASSOC);

    $contextFilterValues = [];
    if ($hasContextInfo && isset($_GET['context_item'])) {
        $contextItemID = (int)$_GET['context_item'];
        $contextFilterValues[] = (string)$contextItemID;
        try {
            $tStmt = $pdo->prepare("SELECT title FROM item WHERE ItemID = ?");
            $tStmt->execute([$contextItemID]);
            $ctxTitleRow = $tStmt->fetch(PDO::FETCH_ASSOC);
            if ($ctxTitleRow && !empty($ctxTitleRow['title'])) {
                $contextFilterValues[] = $ctxTitleRow['title'];
            }
        } catch (Exception $e) {}
    }

    if ($chatPartner) {
        // Mark as read only within the active scope (item context or general)
        if ($hasContextInfo && !empty($contextFilterValues)) {
            $inPh = implode(',', array_fill(0, count($contextFilterValues), '?'));
            $mr = $pdo->prepare("UPDATE message SET is_read = 1 WHERE senderID = ? AND recipientUserID = ? AND context_info IS NOT NULL AND context_info != '' AND context_info IN ($inPh)");
            $mr->execute([$activeChatID, $myID, ...$contextFilterValues]);
        } elseif ($hasContextInfo) {
            $mr = $pdo->prepare("UPDATE message SET is_read = 1 WHERE senderID = ? AND recipientUserID = ? AND (context_info IS NULL OR context_info = '')");
            $mr->execute([$activeChatID, $myID]);
        } else {
            $pdo->prepare("UPDATE message SET is_read = 1 WHERE senderID = ? AND recipientUserID = ?")->execute([$activeChatID, $myID]);
        }
        
        if ($hasContextInfo && !empty($contextFilterValues)) {
            $placeholders = implode(' OR context_info = ', array_fill(0, count($contextFilterValues), '?'));
            $sqlMsg = "SELECT *, COALESCE(is_admin_message, 0) as is_admin_msg FROM message WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) AND context_info IS NOT NULL AND context_info != '' AND (context_info = $placeholders) ORDER BY timestamp ASC";
            $params = [$myID, $activeChatID, $activeChatID, $myID, ...$contextFilterValues];
            $mStmt = $pdo->prepare($sqlMsg);
            $mStmt->execute($params);
        } else {
            if ($hasContextInfo) {
                // General chat only (no item context)
                $mStmt = $pdo->prepare("SELECT *, COALESCE(is_admin_message, 0) as is_admin_msg FROM message WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) AND (context_info IS NULL OR context_info = '') ORDER BY timestamp ASC");
                $mStmt->execute([$myID, $activeChatID, $activeChatID, $myID]);
            } else {
                $mStmt = $pdo->prepare("SELECT *, COALESCE(is_admin_message, 0) as is_admin_msg FROM message WHERE (senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?) ORDER BY timestamp ASC");
                $mStmt->execute([$myID, $activeChatID, $activeChatID, $myID]);
            }
        }
        $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if this is an admin conversation (all messages from admin)
        $isAdminChat = false;
        if (!empty($messages)) {
            $allFromAdmin = true;
            foreach ($messages as $m) {
                if ($m['senderID'] == $myID || empty($m['is_admin_msg'])) {
                    $allFromAdmin = false;
                    break;
                }
            }
            $isAdminChat = $allFromAdmin;
        }
        
        // Report/Rate Logic - Check for seller report eligibility
        $meCount=0; $themCount=0;
        foreach($messages as $m) { if($m['senderID']==$myID) $meCount++; else $themCount++; }
        $chatCount = count($messages);
        
        // Check if user has interacted about a specific item that has been sold
        $itemSoldCheck = false;
        if ($hasContextInfo) {
            try {
                // Look for item context in conversation (context_info field in messages)
                $contextStmt = $pdo->prepare("SELECT DISTINCT context_info FROM message WHERE (senderID = ? OR recipientUserID = ?) AND (senderID = ? OR recipientUserID = ?) AND context_info IS NOT NULL AND context_info != ''");
                $contextStmt->execute([$myID, $myID, $activeChatID, $activeChatID]);
                $contextItems = $contextStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Check if any of those items are sold
                if (!empty($contextItems)) {
                    $itemIDs = [];
                    $titles = [];
                    foreach ($contextItems as $ci) {
                        if (ctype_digit((string)$ci)) $itemIDs[] = (int)$ci; else $titles[] = $ci;
                    }
                    if (!empty($itemIDs)) {
                        $ph = implode(',', array_fill(0, count($itemIDs), '?'));
                        $soldStmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE ItemID IN ($ph) AND status = 'sold'");
                        $soldStmt->execute($itemIDs);
                        $itemSoldCheck = $itemSoldCheck || ((int)$soldStmt->fetchColumn() > 0);
                    }
                    if (!$itemSoldCheck && !empty($titles)) {
                        $ph = implode(',', array_fill(0, count($titles), '?'));
                        $soldStmt = $pdo->prepare("SELECT COUNT(*) FROM item WHERE title IN ($ph) AND status = 'sold'");
                        $soldStmt->execute($titles);
                        $itemSoldCheck = (int)$soldStmt->fetchColumn() > 0;
                    }
                }
            } catch (Exception $e) {}
        }
        
        // Can report/rate if: more than 5 chats OR discussed item that is now sold
        $isTrusted = !$isAdminChat && ($chatCount > 5 || $itemSoldCheck);

        if (!empty($messages)) {
            $lastMsg = end($messages);
            $lastMsgID = $lastMsg['MessageID'];
        }
    }
}

$myAvatar = '';
$hasAvatar = false;
try{
    $s=$pdo->prepare("SELECT profile_image FROM users WHERE UserID=?"); $s->execute([$myID]); $r=$s->fetch();
    if(!empty($r['profile_image']) && file_exists($r['profile_image'])) {
        $myAvatar=$r['profile_image'];
        $hasAvatar = true;
    }
}catch(Exception $e){}

$unread = 0;
try{ $unread = (int)$pdo->query("SELECT COUNT(*) FROM message WHERE recipientUserID=$myID AND is_read=0")->fetchColumn(); }catch(Exception $e){}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v??'', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>CPES - Messages</title>
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
html {
    height: 100%;
    height: -webkit-fill-available;
}
* { box-sizing: border-box; }
body { 
    font-family: 'Outfit', sans-serif; 
    margin: 0; 
    background: var(--bg); 
    color: var(--text); 
    height: 100vh;
    height: 100dvh; /* Dynamic viewport height for mobile */
    height: -webkit-fill-available; /* iOS Safari fallback */
    display: flex; 
    flex-direction: column; 
    overflow: hidden; /* Prevent body scroll, layout handles it */
}

/* --- ANIMATIONS --- */
@keyframes slideInDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

/* --- HEADER --- */
.header { 
    display: flex; align-items: center; padding: 16px 30px; 
    background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); 
    border-bottom: 1px solid var(--border); flex-shrink: 0; 
    animation: slideInDown 0.4s ease-out; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    padding-right: 90px; /* Space for Global Sidebar Strip */
}
.header .brand { 
    flex: 1; font-weight: 900; font-size: 24px; 
    background: linear-gradient(135deg, var(--accent), var(--accent-dark)); 
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; 
    background-clip: text; text-decoration: none; 
}
.controls { flex: 1; display: flex; justify-content: flex-end; gap: 16px; align-items: center; }
.avatar-small { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2.5px solid var(--accent-light); transition: all 0.3s; cursor: pointer; }
.avatar-small:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }

/* Letter Avatar */
.letter-avatar-small {
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 600; color: var(--muted);
    background: var(--panel); border: 2.5px solid var(--accent-light);
    transition: all 0.3s; text-transform: uppercase;
}
.letter-avatar-small:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }

/* Mobile Menu Button (Hidden on desktop, shown on mobile via media query) */
.mobile-menu-btn {
    display: none; /* Hidden on desktop */
    position: relative;
    width: 40px; height: 40px;
    align-items: center; justify-content: center;
    background: #1a1a2e; border: none; border-radius: 10px;
    cursor: pointer; color: white; margin-left: 12px;
    position: relative;
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

/* --- GLOBAL SIDEBAR (Optimized - width for desktop) --- */
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

/* OVERLAY (Fixed z-index to be higher than left sidebar) */
.sidebar-overlay { 
    position: fixed; inset: 0; background: rgba(0,0,0,0.6); 
    z-index: 950; 
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease; 
}
.sidebar-overlay.active { opacity: 1; pointer-events: auto; }

/* --- CHAT LAYOUT --- */
.layout { 
    display: flex; flex: 1; overflow: hidden; height: 100%; 
    padding-right: 70px; /* Space for the collapsed black strip */
    transition: padding-right 0.3s;
}

/* Chat List Sidebar (Left - Fixed z-index to be lower than overlay) */
.chat-list-sidebar { 
    position: fixed; left: 0; top: 73px; height: calc(100vh - 73px); width: 280px; 
    z-index: 900; 
    transform: translateX(-100%); transition: transform 0.3s ease-out; border-right: 1px solid var(--border); 
    background: var(--panel); overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05); 
    -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
}
.chat-list-sidebar.active { transform: translateX(0); }
.sidebar-title { padding: 20px; font-size: 16px; font-weight: 800; border-bottom: 1px solid var(--border); color: var(--text); }
.user-item { display: flex; align-items: center; gap: 12px; padding: 15px 20px; text-decoration: none; color: var(--text); border-bottom: 1px solid var(--border); transition: all 0.3s; position: relative; }
.user-item:hover, .user-item.active { background: var(--accent-light); color: var(--accent); }
.user-pic { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border); transition: all 0.3s; }
.letter-avatar-chat { width: 32px; height: 32px; border-radius: 50%; background: var(--panel); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; color: var(--muted); }
.unread-dot { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 10px; margin-left: auto; animation: pulse 2s infinite; }

.chat-area { display: flex; flex-direction: column; flex: 1; background: linear-gradient(135deg, var(--bg) 0%, var(--bg-alt) 100%); }
.chat-header { background: var(--panel); border-bottom: 1px solid var(--border); padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
.profile-link { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--text); font-weight: 700; transition: color 0.3s; }
.icon-btn { background: none; color: var(--accent); border: none; padding: 8px 12px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s; font-size: 13px; font-weight: 600; }
.icon-btn:hover { background: var(--accent-light); color: var(--accent-dark); }
.context-card { background: var(--panel); border-left: 4px solid var(--accent); padding: 15px 20px; display: flex; align-items: center; gap: 15px; animation: slideInDown 0.3s; margin-bottom: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
.context-thumb { width: 50px; height: 50px; border-radius: 6px; object-fit: cover; border: 1px solid var(--border); }

/* Messages */
.messages-list { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
.msg { max-width: 70%; padding: 12px 16px; border-radius: 8px; font-size: 14px; line-height: 1.4; position: relative; word-wrap: break-word; display: flex; flex-direction: column; animation: slideUp 0.3s; }
.msg.me { align-self: flex-end; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; border-radius: 20px 20px 0px 20px; box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3); }
.msg.them { align-self: flex-start; background: var(--panel); color: var(--text); border-radius: 20px 20px 20px 0px; border: 1px solid var(--border); }
.msg.admin-msg { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; border: 2px solid #fbbf24; border-left-width: 4px; max-width: 80%; box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3); }
.msg.admin-msg::before { content: '⚠️ Admin Notice'; display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #b45309; margin-bottom: 6px; letter-spacing: 0.5px; }
.msg-meta { display: flex; align-items: center; gap: 4px; justify-content: flex-end; margin-top: 4px; font-size: 10px; opacity: 0.7; }
.msg-img { max-width: 200px; border-radius: 8px; margin-top: 5px; display: block; }
.msg-images { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.msg-images.multi .msg-img { max-width: 120px; max-height: 120px; object-fit: cover; }
@media (max-width: 480px) {
    .msg-images.multi .msg-img { max-width: 80px; max-height: 80px; }
}
/* WhatsApp-style Message Options */
.msg-options { 
    position: absolute; 
    top: 6px; 
    right: 6px; 
    z-index: 5; 
}
.opts-btn { 
    background: rgba(0,0,0,0.2); 
    border: none; 
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    font-size: 10px; 
    cursor: pointer; 
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    line-height: 1;
}
.opts-btn:hover {
    background: rgba(0,0,0,0.4);
}
.opts-menu { 
    display: none; 
    position: absolute; 
    right: 0; 
    top: 24px; 
    background: white; 
    border-radius: 8px; 
    z-index: 100; 
    min-width: 100px; 
    box-shadow: 0 4px 20px rgba(0,0,0,0.25);
    overflow: hidden;
}
.opts-menu button, .opts-menu input[type="submit"] { 
    display: block; 
    width: 100%; 
    padding: 10px 14px; 
    background: none; 
    border: none; 
    color: #333; 
    text-align: left; 
    font-size: 13px; 
    cursor: pointer;
    font-weight: 500;
}
.opts-menu button:hover, .opts-menu input[type="submit"]:hover { 
    background: #f0f0f0; 
}
.opts-menu input[type="submit"][value="Delete"] { 
    color: #dc2626; 
}
.msg-options.active .opts-menu { display: block; }

/* For received messages (them), hide options */
.msg.them .msg-options { display: none; }

/* Adjust padding for messages with options */
.msg.me { 
    padding-right: 32px; 
}

/* Input */
.chat-input { 
    padding: 20px; 
    padding-bottom: calc(20px + env(safe-area-inset-bottom, 0px));
    background: var(--panel); 
    border-top: 1px solid var(--border); 
    display: flex; 
    gap: 12px; 
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.05); 
}
.chat-input input { flex: 1; padding: 12px 16px; border-radius: 20px; border: 1px solid var(--border); background: linear-gradient(135deg, #f8f9fb, #f3f0fb); color: var(--text); font-size: 14px; }
.chat-input input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }
.btn-send { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; border: none; padding: 12px 24px; border-radius: 20px; font-weight: 700; cursor: pointer; transition: all 0.3s; }
.btn-send:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(139, 92, 246, 0.4); }
.file-label { cursor: pointer; font-size: 20px; padding: 8px; color: var(--muted); transition: color 0.3s; display: flex; align-items: center; }
.file-label:hover { color: var(--accent); }

/* Image Preview Container */
.image-preview-container {
    display: none;
    padding: 12px 20px;
    background: var(--panel);
    border-top: 1px solid var(--border);
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.image-preview-container.has-images {
    display: flex;
}
.image-preview-item {
    position: relative;
    width: 60px;
    height: 60px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--accent-light);
    transition: all 0.2s;
}
.image-preview-item:hover {
    border-color: var(--accent);
    transform: scale(1.05);
}
.image-preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.image-preview-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 22px;
    height: 22px;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    border: none;
    border-radius: 50%;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 1;
    transition: background 0.2s;
    line-height: 1;
}
.image-preview-remove:hover {
    background: rgba(0, 0, 0, 0.85);
}
.preview-count {
    font-size: 12px;
    color: var(--muted);
    margin-left: auto;
    padding: 4px 10px;
    background: var(--accent-light);
    border-radius: 12px;
}

/* Modals */
.modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(4px); }
.modal-overlay.is-visible { opacity: 1; pointer-events: auto; }
.modal-content { background: var(--panel); border-radius: 12px; padding: 24px; width: 100%; max-width: 400px; animation: slideUp 0.3s; }
.modal-close { float: right; background: none; border: 0; color: var(--muted); font-size: 24px; cursor: pointer; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text); font-size: 13px; }
.form-group textarea, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; }

/* Mobile Left Sidebar Toggle */
.hamburger-menu { display: none; }
@media (min-width: 900px) { .chat-list-sidebar { position: relative; top: 0; transform: translateX(0); height: 100%; } }
@media (max-width: 900px) { 
    .hamburger-menu { display: flex; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 8px; } 
    .hamburger-menu span { width: 24px; height: 3px; background: var(--accent); border-radius: 2px; } 
    .layout { padding-right: 0; }
    
    /* Mobile sidebar - GPU accelerated */
    .right-sidebar { width: 300px; transform: translateX(100%); transition: transform 0.3s ease-out; }
    .right-sidebar.open { transform: translateX(0); }
    .sidebar-toggle-btn { display: none; }
    .mobile-menu-btn { display: flex !important; }
    .header { padding-right: 20px; }
}

/* Extra mobile optimization for 480px */
@media (max-width: 480px) {
    /* Header */
    .header { padding: 12px 16px; }
    .header .brand { font-size: 16px; }
    .avatar-small, .letter-avatar-small { width: 32px; height: 32px; font-size: 12px; }
    .controls { gap: 10px; }
    .hamburger-menu span { width: 20px; height: 2px; }
    
    /* Chat List Sidebar */
    .chat-list-sidebar { 
        width: 240px; 
        top: 56px; 
        height: calc(100vh - 56px); 
        height: calc(100dvh - 56px); /* Dynamic viewport height for mobile */
        max-height: -webkit-fill-available;
        position: fixed; 
        overflow-y: auto !important;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    .sidebar-title { padding: 14px 16px; font-size: 14px; }
    .user-item { padding: 12px 14px; gap: 10px; }
    .user-pic { width: 36px; height: 36px; }
    .user-name span:first-child { font-size: 12px; }
    .user-name span:last-child { font-size: 10px; }
    .unread-dot { font-size: 9px; padding: 2px 6px; }
    
    /* Chat Header */
    .chat-header { padding: 10px 14px; }
    .profile-link { gap: 8px; font-size: 13px; }
    .profile-link .user-pic { width: 28px; height: 28px; }
    .letter-avatar-chat { width: 28px; height: 28px; font-size: 12px; }
    .header-actions { gap: 8px; }
    .icon-btn { padding: 6px 10px; font-size: 11px; }
    
    /* Context Card */
    .context-card { padding: 10px 14px; gap: 10px; margin-bottom: 10px; }
    .context-thumb { width: 40px; height: 40px; }
    .context-info h4 { font-size: 13px; margin: 0; }
    .context-info p { font-size: 12px; margin: 4px 0 0; }
    
    /* Messages */
    .messages-list { padding: 14px; gap: 8px; }
    .msg { max-width: 75%; padding: 10px 12px; font-size: 13px; border-radius: 16px; }
    .msg.me { border-radius: 16px 16px 0 16px; margin-right: 0; }
    .msg.them { border-radius: 16px 16px 16px 0; }
    .msg-meta { font-size: 9px; margin-top: 3px; }
    .msg-img { max-width: 140px; }
    
    /* Chat Area */
    .chat-area { padding-right: 0; }
    
    /* Chat Input - iOS Safe Area Fix */
    .chat-input { 
        padding: 12px 14px; 
        padding-right: 20px; 
        padding-bottom: calc(16px + env(safe-area-inset-bottom, 20px));
        gap: 8px; 
    }
    .chat-input input { padding: 10px 14px; font-size: 13px; border-radius: 16px; }
    .btn-send { padding: 10px 18px; font-size: 12px; border-radius: 16px; }
    .file-label { font-size: 18px; padding: 6px; }
    
    /* Modal */
    .modal-content { max-width: 90%; padding: 18px; }
    .modal-content h3 { font-size: 16px; }
    .form-group label { font-size: 12px; }
    .form-group textarea, .form-group select { padding: 10px; font-size: 13px; }
    
    /* Star Rating Modal */
    #star-rating label { font-size: 16px !important; }
    #rate-modal .modal-content { max-width: 95%; width: 95%; }
    
    /* Chat Toggle Arrow Button */
    .chat-toggle-btn {
        display: flex;
    }
}

/* Chat Toggle Arrow Button - Default hidden on desktop */
.chat-toggle-btn {
    display: none;
    position: fixed;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    z-index: 901;
    width: 32px;
    height: 48px;
    background: var(--accent);
    border: none;
    border-radius: 0 12px 12px 0;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    box-shadow: 2px 0 12px rgba(75, 0, 130, 0.3);
    transition: all 0.3s;
}
.chat-toggle-btn:hover {
    background: var(--accent-dark);
    width: 36px;
}
.chat-toggle-btn svg {
    stroke: white;
    transition: transform 0.3s;
}
.chat-toggle-btn.active svg {
    transform: rotate(180deg);
}

@media (max-width: 900px) {
    .chat-toggle-btn {
        display: flex;
    }
}
</style>
</head>
<body>

<header class="header">
    <a href="home.php" class="brand">Campus Preloved E-Shop</a>
    <div class="controls">
        <a href="profile.php" style="display:flex;align-items:center;">
            <?php if ($hasAvatar): ?>
                <img src="<?= h($myAvatar) ?>" class="avatar-small">
            <?php else: ?>
                <div class="letter-avatar-small"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
            <?php endif; ?>
        </a>
        <button class="mobile-menu-btn" onclick="toggleGlobalSidebar()">
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

<div class="sidebar-overlay" onclick="toggleGlobalSidebar()"></div>

<aside class="right-sidebar" id="global-sidebar">
    <button class="sidebar-toggle-btn" onclick="toggleGlobalSidebar()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter">
            <line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>
    <div class="sidebar-content">
        <h3 style="color:white; margin-bottom:20px; font-size:24px;">Menu</h3>
        <ul class="sidebar-menu">
            <li><a href="home.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Home</a></li>
            <li><a href="profile.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>Profile</a></li>
            <li><a href="messages.php" class="sidebar-link active"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>Messages <?php if($unread>0): ?><span class="menu-badge"><?= $unread ?></span><?php endif; ?></a></li>
            <li><a href="create.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>Create List/Event</a></li>
            <li><a href="#" class="sidebar-link" onclick="openSavedModal(); toggleGlobalSidebar();"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>Bookmarks</a></li>
            <li><a href="help.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>Help</a></li>
            <li><a href="reports.php" class="sidebar-link"><svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M15.73 3H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27L15.73 3zM12 17c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm1-4h-2V7h2v6z"/></svg>Reports</a></li>
            <li><a href="logout.php" class="sidebar-link" style="color: #ef4444;"><svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>Log Out</a></li>
        </ul>
    </div>
</aside>

<div class="layout">
    <!-- Chat List Toggle Arrow -->
    <button class="chat-toggle-btn" id="chatToggle" onclick="toggleChatList()">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
    </button>
    
    <aside class="chat-list-sidebar">
        <div class="sidebar-title">Chats by Item</div>
        <?php if (empty($threads)): ?>
            <div style="padding:20px; color:var(--muted); font-size:14px;">No chats.</div>
        <?php else: foreach($threads as $t): 
            $thumb = !empty($t['itemThumb']) ? $t['itemThumb'] : 'uploads/avatars/default.png';
            $isActive = '';
            if ($t['partnerID'] == $activeChatID) {
                if (!isset($_GET['context_item']) && $t['itemID'] === null) {
                    $isActive = 'active';
                }
                if (isset($_GET['context_item']) && $t['itemID'] !== null && (int)$_GET['context_item'] === (int)$t['itemID']) {
                    $isActive = 'active';
                }
            }
            $href = 'messages.php?to=' . $t['partnerID'] . ($t['itemID'] ? ('&context_item=' . $t['itemID']) : '');
        ?>
            <a href="<?= $href ?>" class="user-item <?= $isActive ?>">
                <img src="<?= h($thumb) ?>" class="user-pic">
                <div class="user-name" style="display:flex; flex-direction:column;">
                    <span style="font-weight:700; font-size:14px;"><?= h($t['itemTitle']) ?></span>
                    <span style="font-size:11px; color:var(--muted);"><?= h($t['partnerName']) ?></span>
                </div>
                <?php if ($t['isSold']): ?>
                    <?php if ($t['isSoldToMe']): ?>
                        <span class="unread-dot" style="background: #10b981; animation:none;">BOUGHT</span>
                    <?php else: ?>
                        <span class="unread-dot" style="background: #ef4444; animation:none;">SOLD</span>
                    <?php endif; ?>
                <?php elseif ($t['unread']>0): ?><span class="unread-dot"><?= $t['unread'] ?></span><?php endif; ?>
            </a>
        <?php endforeach; endif; ?>
    </aside>

    <main class="chat-area">
        <?php if ($chatPartner): 
            $partnerHasAvatar = !empty($chatPartner['profile_image']) && file_exists($chatPartner['profile_image']);
            $partnerLetter = strtoupper(substr($chatPartner['name'] ?? 'U', 0, 1));
        ?>
            
            <div class="chat-header">
                <a href="public_profile.php?userid=<?= $chatPartner['UserID'] ?>" class="profile-link" title="View Profile">
                    <?php if ($partnerHasAvatar): ?>
                        <img src="<?= h($chatPartner['profile_image']) ?>" class="user-pic" style="width:32px;height:32px;">
                    <?php else: ?>
                        <div class="letter-avatar-chat"><?= $partnerLetter ?></div>
                    <?php endif; ?>
                    <span><?= h($chatPartner['name']) ?></span>
                </a>

                <div class="header-actions" style="display:flex; gap:15px;">
                    <button class="icon-btn <?= $isTrusted ? 'active rate-active' : 'btn-disabled' ?>" 
                            title="<?= $isTrusted ? 'Rate User' : 'Rating unavailable' ?>"
                            onclick="<?= $isTrusted ? "openModal('rate-modal')" : "showTrustToast('rate')" ?>">
                        Rate
                    </button>
                    
                    <button class="icon-btn <?= $isTrusted ? 'active report-active' : 'btn-disabled' ?>" 
                            title="<?= $isTrusted ? 'Report User' : 'Report unavailable' ?>"
                            onclick="<?= $isTrusted ? "openModal('report-modal')" : "showTrustToast('report')" ?>">
                        Report
                    </button>
                </div>
            </div>

            <?php if ($contextItem): $cImg = !empty($contextItem['image'])?explode(',',$contextItem['image'])[0]:'uploads/avatars/default.png'; ?>
                <div class="context-card">
                    <img src="<?= h($cImg) ?>" class="context-thumb">
                    <div class="context-info"><h4><?= h($contextItem['title']) ?></h4><p>RM <?= number_format($contextItem['price'], 2) ?></p></div>
                </div>
            <?php endif; ?>

            <?php if ($isTrusted): ?><div style="text-align:center; padding:10px; color:var(--accent); font-size:14px; font-weight:600;">Trust established! You can now Rate this user.</div><?php endif; ?>

            <div class="messages-list" id="msgList">
                <?php if (empty($messages)): ?><div class="empty-state" style="text-align:center;color:var(--muted);margin-top:20px;">Say hello!</div><?php else: foreach($messages as $m): 
                    if (!empty($m['is_admin_msg'])) continue; // Skip admin messages
                    $isMe = ($m['senderID'] == $myID);
                    $isFromAdmin = !empty($m['is_admin_msg']);
                    $canEdit = $isMe && !$isFromAdmin && (time() - strtotime($m['timestamp']) <= 3600);
                ?>
                    <div class="msg <?= $isMe ? 'me' : 'them' ?> <?= $isFromAdmin ? 'admin-msg' : '' ?>">
                        <?= h($m['message']) ?>
                        
                        <?php if(!empty($m['attachment_image'])): 
                            $images = explode(',', $m['attachment_image']);
                        ?>
                            <div class="msg-images <?= count($images) > 1 ? 'multi' : '' ?>">
                                <?php foreach($images as $img): $img = trim($img); if(!empty($img)): ?>
                                    <img src="<?= h($img) ?>" class="msg-img" onclick="openImageLightbox('<?= h($img) ?>')" style="cursor:zoom-in;" title="Click to view">
                                <?php endif; endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="msg-meta">
                            <span class="msg-time"><?= date('H:i', strtotime($m['timestamp'])) ?></span>
                            <?php if($isMe): if($m['is_read']): ?>
                                <span style="color:#a5b4fc">✓✓</span>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,0.6)">✓</span>
                            <?php endif; endif; ?>
                        </div>

                        <?php if ($canEdit): ?>
                            <div class="msg-options">
                                <button class="opts-btn">▼</button>
                                <div class="opts-menu">
                                    <button type="button" onclick="openMsgEdit(<?= $m['MessageID'] ?>, '<?= h($m['message']) ?>')">Edit</button>
                                    <form method="POST" onsubmit="return confirm('Delete this message?');">
                                        <input type="hidden" name="delete_msg_id" value="<?= $m['MessageID'] ?>">
                                        <input type="hidden" name="return_to_chat" value="<?= $activeChatID ?>">
                                        <?php if(isset($contextItemID) && $contextItemID > 0): ?>
                                            <input type="hidden" name="return_to_item" value="<?= $contextItemID ?>">
                                        <?php endif; ?>
                                        <input type="submit" value="Delete">
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <?php if (true): ?>
                <!-- Image Preview Container -->
                <div class="image-preview-container" id="imagePreviewContainer">
                    <div id="previewList"></div>
                    <span class="preview-count" id="previewCount"></span>
                </div>
                
                <form class="chat-input" id="chatForm" enctype="multipart/form-data">
                    <input type="hidden" name="to_user" value="<?= $activeChatID ?>">
                    <?php if($contextItem): ?>
                        <input type="hidden" name="context_item" value="<?= (int)$_GET['context_item'] ?>">
                        <input type="hidden" name="context_info" value="<?= h($contextItem['title']) ?>">
                    <?php endif; ?>
                    
                    <label for="imgUpload" class="file-label" title="Send Images (max 5)">📷</label>
                    <input type="file" name="chat_images[]" id="imgUpload" style="display:none;" accept="image/*" multiple>
                    
                    <input type="text" name="message" id="msgInput" placeholder="Type a message..." autocomplete="off">
                    <button type="submit" class="btn-send">Send</button>
                </form>
            <?php endif; ?>

        <?php else: ?>
            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:var(--muted);">Select a conversation to start chatting</div>
        <?php endif; ?>
    </main>
</div>

<div class="modal-overlay" id="rate-modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('rate-modal')">&times;</button>
        <h3 style="color:var(--accent); margin-top:0;">Rate Seller</h3>
        <form method="POST">
            <input type="hidden" name="action_type" value="rate">
            <input type="hidden" name="target_user_id" value="<?= $activeChatID ?>">
            <?php if(isset($_GET['context_item'])): ?>
                <input type="hidden" name="context_item" value="<?= (int)$_GET['context_item'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>How would you rate this seller?</label>
                <div style="display:flex; gap:4px; margin-top:12px; justify-content:center; flex-wrap:nowrap; padding:8px 4px;" id="star-rating">
                    <?php for($i = 1; $i <= 10; $i++): ?>
                        <input type="radio" name="rating" value="<?= $i ?>" id="r<?= $i ?>" style="display:none;">
                        <label for="r<?= $i ?>" style="cursor:pointer; font-size:20px; line-height:1; transition:color 0.12s; color:#cbd5e1; user-select:none;" onmouseover="highlightStars(<?= $i ?>)" onmouseout="unhighlightStars()">&#9733;</label>
                    <?php endfor; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label>Your feedback (optional)</label>
                <textarea name="comment" rows="3" placeholder="Tell others about your experience with this seller..."></textarea>
            </div>
            <button type="submit" class="btn-send" style="width:100%;">Submit Review</button>
        </form>
    </div>
</div>

<style>
#star-rating label:hover,
#star-rating label.hovered {
    color: #f5b301 !important;
}

input[type="radio"]:checked + label {
    color: #f5b301 !important;
}

#rate-modal .modal-content { max-width: 520px; width: 95%; }
</style>

<script>
const starContainer = document.getElementById('star-rating');

function highlightStars(count) {
    for(let i = 1; i <= 10; i++) {
        const lbl = document.querySelector(`label[for="r${i}"]`);
        if(i <= count) {
            lbl.classList.add('hovered');
            lbl.style.color = '#f5b301';
        } else {
            lbl.classList.remove('hovered');
            lbl.style.color = '#cbd5e1';
        }
    }
}

function unhighlightStars() {
    const checkedVal = document.querySelector('input[name="rating"]:checked');
    for(let i = 1; i <= 10; i++) {
        const lbl = document.querySelector(`label[for="r${i}"]`);
        if(checkedVal && i <= checkedVal.value) {
            lbl.style.color = '#f5b301';
        } else {
            lbl.style.color = '#cbd5e1';
        }
        lbl.classList.remove('hovered');
    }
}

if(starContainer) {
    starContainer.addEventListener('mouseleave', () => {
        const checkedVal = document.querySelector('input[name="rating"]:checked');
        if(!checkedVal) {
            // Reset to zero when not hovered and not selected
            for(let i = 1; i <= 10; i++) {
                const lbl = document.querySelector(`label[for="r${i}"]`);
                lbl.style.color = '#cbd5e1';
                lbl.classList.remove('hovered');
            }
        } else {
            unhighlightStars();
        }
    });
}

document.addEventListener('change', function(e) {
    if(e.target.name === 'rating') {
        unhighlightStars();
    }
});
</script>

<div class="modal-overlay" id="report-modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('report-modal')">&times;</button>
        <h3 style="color:var(--danger); margin-top:0;">Report Seller</h3>
        <form method="POST">
            <input type="hidden" name="action_type" value="report">
            <input type="hidden" name="target_user_id" value="<?= $activeChatID ?>">
            <?php if(isset($_GET['context_item'])): ?>
                <input type="hidden" name="context_item" value="<?= (int)$_GET['context_item'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Report Type</label>
                <select name="report_type" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px;">
                    <option value="" disabled selected>Select report type...</option>
                    <option value="scam_fraud">Seller Fraud/Scam</option>
                    <option value="item_report">Non-Delivery</option>
                    <option value="item_report">Item Misrepresentation</option>
                    <option value="item_report">Poor Item Quality</option>
                    <option value="user_report">Inappropriate Behavior</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Detailed Description</label>
                <textarea name="reason" rows="4" required placeholder="Please provide specific details about the issue..."></textarea>
            </div>
            <button type="submit" class="btn-send" style="width:100%; background:var(--danger);">Submit Report</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="msg-edit-modal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal('msg-edit-modal')">&times;</button>
        <h3 style="margin-top:0;color:var(--accent)">Edit Message</h3>
        <form method="POST">
            <input type="hidden" name="edit_msg_id" id="edit_msg_id">
            <input type="hidden" name="return_to_chat" value="<?= $activeChatID ?>">
            <?php if(isset($contextItemID) && $contextItemID > 0): ?>
                <input type="hidden" name="return_to_item" value="<?= $contextItemID ?>">
            <?php endif; ?>
            <div class="form-group"><textarea name="new_message_text" id="new_message_text" rows="3"></textarea></div>
            <button type="submit" class="btn-send" style="width:100%">Save Changes</button>
        </form>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div class="image-lightbox" id="imageLightbox" onclick="closeImageLightbox(event)">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <div class="lightbox-header">
            <a id="lightboxDownload" href="" download class="lightbox-icon-btn" title="Download">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
            </a>
            <button class="lightbox-icon-btn" onclick="closeImageLightbox()" title="Close">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
        <img id="lightboxImage" src="" alt="Chat image">
    </div>
</div>

<style>
.image-lightbox {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    backdrop-filter: blur(4px);
}
.image-lightbox.active {
    opacity: 1;
    pointer-events: auto;
}
.lightbox-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    animation: slideUp 0.3s;
}
.lightbox-header {
    position: absolute;
    top: -50px;
    right: 0;
    display: flex;
    gap: 8px;
}
.lightbox-icon-btn {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.lightbox-icon-btn svg {
    stroke: white;
}
.lightbox-icon-btn:hover {
    background: var(--accent);
    transform: scale(1.1);
}
.lightbox-content img {
    max-width: 90vw;
    max-height: 80vh;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}
</style>

<script>
const msgList = document.getElementById('msgList');
if(msgList) msgList.scrollTop = msgList.scrollHeight;

function openMsgEdit(id, text) {
    document.getElementById('edit_msg_id').value = id;
    document.getElementById('new_message_text').value = text;
    document.getElementById('msg-edit-modal').classList.add('is-visible');
}
function openModal(id) { document.getElementById(id).classList.add('is-visible'); }
function closeModal(id) { document.getElementById(id).classList.remove('is-visible'); }

// Global Sidebar Toggle
function toggleGlobalSidebar() {
    const s = document.getElementById('global-sidebar'); 
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open'); 
    o.classList.toggle('active');
}

// Image Lightbox Functions
function openImageLightbox(src) {
    const lightbox = document.getElementById('imageLightbox');
    const img = document.getElementById('lightboxImage');
    const downloadBtn = document.getElementById('lightboxDownload');
    
    img.src = src;
    downloadBtn.href = src;
    lightbox.classList.add('active');
}

function closeImageLightbox(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('imageLightbox').classList.remove('active');
}

// Close lightbox on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageLightbox();
    }
});

// Chat List Toggle (Arrow Button)
function toggleChatList() {
    const sidebar = document.querySelector('.chat-list-sidebar');
    const btn = document.getElementById('chatToggle');
    sidebar.classList.toggle('active');
    btn.classList.toggle('active');
}

// Mobile: Handle tap on message options button (since hover doesn't work on touch)
document.addEventListener('click', function(e) {
    // If clicking on opts-btn, toggle the menu
    if (e.target.classList.contains('opts-btn')) {
        e.stopPropagation();
        const msgOptions = e.target.closest('.msg-options');
        if (msgOptions) {
            // Close all other open menus first
            document.querySelectorAll('.msg-options.active').forEach(el => {
                if (el !== msgOptions) el.classList.remove('active');
            });
            msgOptions.classList.toggle('active');
        }
    } else {
        // Close all open menus when clicking elsewhere
        document.querySelectorAll('.msg-options.active').forEach(el => el.classList.remove('active'));
    }
});

// --- AJAX CHAT LOGIC ---
const chatForm = document.getElementById('chatForm');
const activeChatID = <?= $activeChatID ?>;
const contextItemID = <?= isset($_GET['context_item']) ? (int)$_GET['context_item'] : 0 ?>;
let lastMsgID = <?= $lastMsgID ?>;

// Image Preview Management
let selectedFiles = [];
const maxImages = 5;

const imgUpload = document.getElementById('imgUpload');
const previewContainer = document.getElementById('imagePreviewContainer');
const previewList = document.getElementById('previewList');
const previewCount = document.getElementById('previewCount');

if(imgUpload) {
    imgUpload.addEventListener('change', function(e) {
        const files = Array.from(e.target.files);
        
        // Limit total files to maxImages
        const remainingSlots = maxImages - selectedFiles.length;
        const filesToAdd = files.slice(0, remainingSlots);
        
        filesToAdd.forEach(file => {
            if (file.type.startsWith('image/')) {
                selectedFiles.push(file);
            }
        });
        
        if (files.length > remainingSlots) {
            alert(`Maximum ${maxImages} images allowed. Only first ${remainingSlots} image(s) added.`);
        }
        
        updateImagePreviews();
        // Reset input so same file can be selected again if removed
        imgUpload.value = '';
    });
}

function updateImagePreviews() {
    if (!previewList || !previewContainer || !previewCount) return;
    
    previewList.innerHTML = '';
    
    if (selectedFiles.length > 0) {
        previewContainer.classList.add('has-images');
        previewCount.textContent = `${selectedFiles.length}/${maxImages} images`;
        
        selectedFiles.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const item = document.createElement('div');
                item.className = 'image-preview-item';
                item.innerHTML = `
                    <img src="${e.target.result}" alt="Preview">
                    <button type="button" class="image-preview-remove" onclick="removeImage(${index})" title="Remove">×</button>
                `;
                previewList.appendChild(item);
            };
            reader.readAsDataURL(file);
        });
    } else {
        previewContainer.classList.remove('has-images');
    }
}

function removeImage(index) {
    selectedFiles.splice(index, 1);
    updateImagePreviews();
}

if(chatForm) {
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const input = document.getElementById('msgInput');
        
        if(input.value.trim() === '' && selectedFiles.length === 0) return;

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('to_user', document.querySelector('input[name="to_user"]').value);
        formData.append('message', input.value);
        
        // Add context fields if present
        const contextItem = document.querySelector('input[name="context_item"]');
        const contextInfo = document.querySelector('input[name="context_info"]');
        if (contextItem) formData.append('context_item', contextItem.value);
        if (contextInfo) formData.append('context_info', contextInfo.value);
        
        // Add selected files
        selectedFiles.forEach((file, index) => {
            formData.append('chat_images[]', file);
        });

        fetch('api_chat.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                input.value = '';
                selectedFiles = [];
                updateImagePreviews();
                fetchMessages(); 
            } else if(data.error) {
                console.error('Chat send error:', data.error);
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            alert('Failed to send message. Please try again.');
        });
    });

    setInterval(fetchMessages, 3000);
}

function fetchMessages() {
    if(activeChatID === 0) return;
    
    const formData = new FormData();
    formData.append('action', 'fetch');
    formData.append('partner_id', activeChatID);
    formData.append('last_id', lastMsgID);
    if(contextItemID) formData.append('context_item', contextItemID);

    fetch('api_chat.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.messages && data.messages.length > 0) {
            data.messages.forEach(m => {
                lastMsgID = m.MessageID;
                appendMessage(m, data.myID);
            });
            msgList.scrollTop = msgList.scrollHeight;
        }
    });
}

function appendMessage(m, myID) {
    const isMe = (m.senderID == myID);
    const cls = isMe ? 'me' : 'them';
    let content = escapeHtml(m.message);
    
    if(m.attachment_image) {
        const images = m.attachment_image.split(',').filter(img => img.trim());
        const multiClass = images.length > 1 ? 'multi' : '';
        content += `<div class="msg-images ${multiClass}">`;
        images.forEach(img => {
            img = img.trim();
            content += `<img src="${img}" class="msg-img" onclick="openImageLightbox('${img}')" style="cursor:zoom-in;" title="Click to view">`;
        });
        content += `</div>`;
    }

    const time = new Date(m.timestamp).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    
    // Determine tick status - new messages are unread (single tick)
    const isRead = m.is_read == 1 || m.is_read === true;
    const tickHtml = isMe ? (isRead ? '<span style="color:#a5b4fc">✓✓</span>' : '<span style="color:rgba(255,255,255,0.6)">✓</span>') : '';
    
    let html = `<div class="msg ${cls}" data-msgid="${m.MessageID}">${content}`;
    html += `<div class="msg-meta"><span class="msg-time">${time}</span>${tickHtml}</div>`;
    
    // Add edit/delete options for own messages (within 1 hour)
    if(isMe) {
        const msgTime = new Date(m.timestamp).getTime();
        const now = Date.now();
        const oneHour = 60 * 60 * 1000;
        if((now - msgTime) <= oneHour) {
            const escapedMsg = escapeHtml(m.message).replace(/'/g, "\\'");
            const itemInput = contextItemID > 0 ? `<input type="hidden" name="return_to_item" value="${contextItemID}">` : '';
            html += `<div class="msg-options">
                <button class="opts-btn">▼</button>
                <div class="opts-menu">
                    <button type="button" onclick="openMsgEdit(${m.MessageID}, '${escapedMsg}')">Edit</button>
                    <form method="POST" onsubmit="return confirm('Delete this message?');">
                        <input type="hidden" name="delete_msg_id" value="${m.MessageID}">
                        <input type="hidden" name="return_to_chat" value="${activeChatID}">
                        ${itemInput}
                        <input type="submit" value="Delete">
                    </form>
                </div>
            </div>`;
        }
    }
    
    html += `</div>`;
    
    msgList.insertAdjacentHTML('beforeend', html);
}

function escapeHtml(text) {
    if(!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Saved Modal Functions
function openSavedModal(){ document.getElementById('savedModal').classList.add('is-visible'); }
function closeSavedModal(){ document.getElementById('savedModal').classList.remove('is-visible'); }
</script>

<!-- Saved Items Modal -->
<div class="modal-overlay" id="savedModal" onclick="if(event.target === this) closeSavedModal()">
    <div class="modal-content" style="background: var(--panel); border-radius: 16px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid var(--border);">
            <h3 style="margin: 0; font-size: 18px; font-weight: 700;">Saved Items</h3>
            <button onclick="closeSavedModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--muted);">&times;</button>
        </div>
        <div class="modal-body" style="padding: 0;">
            <?php if(empty($savedItems)): ?>
                <div style="padding:40px; text-align:center; color:var(--muted);">No saved items yet.</div>
            <?php else: foreach($savedItems as $sv): 
                $sImg = !empty($sv['image']) ? explode(',', $sv['image'])[0] : 'avatar.png';
            ?>
                <div style="display: flex; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--border);">
                    <a href="item_detail.php?id=<?= $sv['ItemID'] ?>" style="display:flex; gap:15px; flex:1; text-decoration:none; color:inherit; align-items:center;">
                        <img src="<?= htmlspecialchars($sImg) ?>" style="width: 60px; height: 60px; border-radius: 12px; object-fit: cover; border: 1px solid var(--border);">
                        <div>
                            <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($sv['title']) ?></div>
                            <div style="color:var(--accent); font-weight:700;">RM <?= number_format($sv['price'], 2) ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<style>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
.modal-overlay.is-visible { opacity: 1; pointer-events: auto; }

/* Disabled button style for Rate/Report */
.icon-btn.btn-disabled {
    opacity: 0.5;
    cursor: not-allowed;
    color: var(--muted);
}
.icon-btn.btn-disabled:hover {
    background: transparent;
    color: var(--muted);
}

/* Trust Toast Message */
.trust-toast {
    position: fixed;
    bottom: 100px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    color: white;
    padding: 16px 24px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 500;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    z-index: 3000;
    opacity: 0;
    pointer-events: none;
    transition: all 0.4s ease;
    max-width: 320px;
    text-align: center;
    line-height: 1.5;
    border-left: 4px solid var(--accent);
}
.trust-toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    pointer-events: auto;
}
.trust-toast-icon {
    display: inline-block;
    margin-right: 8px;
    font-size: 16px;
}
</style>

<!-- Trust Toast Message -->
<div class="trust-toast" id="trustToast">
    <span class="trust-toast-icon">🔒</span>
    <span id="trustToastMessage">Trust level not reached yet.</span>
</div>

<script>
// Trust Toast Function
let trustToastTimeout = null;
function showTrustToast(action) {
    const toast = document.getElementById('trustToast');
    const message = document.getElementById('trustToastMessage');
    
    if (action === 'rate') {
        message.innerHTML = '<strong>Rate not available</strong><br>Trust level not reached yet.';
    } else {
        message.innerHTML = '<strong>Report not available</strong><br>Trust level not reached yet.';
    }
    
    // Show toast
    toast.classList.add('show');
    
    // Clear existing timeout
    if (trustToastTimeout) {
        clearTimeout(trustToastTimeout);
    }
    
    // Auto-hide after 4 seconds
    trustToastTimeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 4000);
}

// Click outside to close toast
document.addEventListener('click', function(e) {
    const toast = document.getElementById('trustToast');
    if (toast && toast.classList.contains('show') && !e.target.closest('.trust-toast') && !e.target.closest('.btn-disabled')) {
        toast.classList.remove('show');
    }
});
</script>

</body>
</html>