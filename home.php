<?php
// 1. Start Session
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Helper for Time-Ago feature (inline for safety)
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        
        // Calculate weeks from days (without using dynamic property)
        $weeks = floor($diff->d / 7);
        $days = $diff->d - ($weeks * 7);
        
        $string = array(
            'y' => $diff->y,
            'm' => $diff->m,
            'w' => $weeks,
            'd' => $days,
            'h' => $diff->h,
            'i' => $diff->i,
            's' => $diff->s
        );
        
        $labels = array('y' => 'year','m' => 'month','w' => 'week','d' => 'day','h' => 'hour','i' => 'minute','s' => 'second');
        
        foreach ($string as $k => $v) {
            if ($v) {
                $string[$k] = $v . ' ' . $labels[$k] . ($v > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
        
        if (!$string) return 'just now';
        $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

// 2. Database Connection
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/saved_items_helper.php';
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 3. Setup User Variables
$userid = $_SESSION['UserID'] ?? null;
$name   = $_SESSION['name'] ?? 'Guest';
$role   = $_SESSION['role'] ?? 'user';

// --- Handle Unsave Action ---
if ($userid && isset($_POST['unsave_id'])) {
    saved_remove($pdo, $userid, (int)$_POST['unsave_id']);
    header("Location: home.php"); exit;
}

// --- Fetch Saved Items ---
$savedItems = $userid ? saved_fetch_items($pdo, $userid, true) : [];

// Avatar & Unread Messages
$avatarSrc = ''; 
$hasAvatar = false;
$unread = 0;
$notifications = [];
$unreadNotifications = 0;

if ($userid) {
    try {
        $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE UserID = ? LIMIT 1");
        $stmt->execute([$userid]);
        $u = $stmt->fetch();
        if (!empty($u['profile_image']) && file_exists($u['profile_image'])) {
            $avatarSrc = $u['profile_image'];
            $hasAvatar = true;
        }
        
        // Count regular messages (user-to-user, not admin)
        $c = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0 AND (is_admin_message = 0 OR is_admin_message IS NULL)");
        $c->execute([$userid]);
        $unread = (int)$c->fetchColumn();
        
        // Fetch admin messages as notifications
        try {
            $adminMsgStmt = $pdo->prepare("
                SELECT MessageID, message, timestamp
                FROM message
                WHERE recipientUserID = ? AND is_admin_message = 1
                ORDER BY timestamp DESC
                LIMIT 5
            ");
            $adminMsgStmt->execute([$userid]);
            $adminMessages = $adminMsgStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($adminMessages as $adminMsg) {
                // Consider admin messages unread if they're less than 7 days old
                $isRecent = (time() - strtotime($adminMsg['timestamp'])) < (7 * 86400);
                $isUnread = $isRecent ? 0 : 1;
                
                $notifications[] = [
                    'type' => 'admin_message',
                    'id' => $adminMsg['MessageID'],
                    'title' => 'System Admin',
                    'content' => $adminMsg['message'],
                    'image' => null,
                    'file' => null,
                    'date' => $adminMsg['timestamp'],
                    'is_read' => $isUnread
                ];
                
                if (!$isUnread) {
                    $unreadNotifications++;
                }
            }
        } catch (PDOException $e) {}
        
        // Fetch broadcast memos
        try {
            $memoStmt = $pdo->prepare("
                SELECT MemoID, subject, content, attachment_image, attachment_file, created_at
                FROM memo
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $memoStmt->execute();
            $memos = $memoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($memos as $memo) {
                // Consider memos unread if they're less than 7 days old
                $isRecent = (time() - strtotime($memo['created_at'])) < (7 * 86400);
                $isUnread = $isRecent ? 0 : 1;
                
                $notifications[] = [
                    'type' => 'memo',
                    'id' => $memo['MemoID'],
                    'title' => $memo['subject'],
                    'content' => $memo['content'],
                    'image' => $memo['attachment_image'],
                    'file' => $memo['attachment_file'],
                    'date' => $memo['created_at'],
                    'is_read' => $isUnread
                ];
                
                if (!$isUnread) {
                    $unreadNotifications++;
                }
            }
        } catch (PDOException $e) {}
        
        // Fetch upcoming events (within 3 days)
        try {
            $eventStmt = $pdo->prepare("
                SELECT ItemID, title, event_date, image
                FROM item
                WHERE category = 'Events' 
                AND status = 'available' 
                AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                ORDER BY event_date ASC
                LIMIT 5
            ");
            $eventStmt->execute();
            $upcomingEvents = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($upcomingEvents as $event) {
                $daysUntil = (strtotime($event['event_date']) - strtotime('today')) / 86400;
                $notifications[] = [
                    'type' => 'event',
                    'id' => $event['ItemID'],
                    'title' => $event['title'],
                    'content' => $daysUntil == 0 ? 'Event is TODAY!' : ($daysUntil == 1 ? 'Event is TOMORROW!' : "Event in {$daysUntil} days"),
                    'image' => $event['image'],
                    'date' => $event['event_date'],
                    'is_read' => 1 // Events are always "read"
                ];
            }
        } catch (PDOException $e) {}
        
        // Sort notifications by date
        usort($notifications, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
    } catch (Exception $e) {}
}

// --- Fetch Featured Events ---
$featuredEvents = [];
try {
    $fStmt = $pdo->prepare("SELECT * FROM item WHERE category = 'Events' AND status = 'available' AND event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
    $fStmt->execute();
    $featuredEvents = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($featuredEvents)) {
        $fStmt = $pdo->prepare("SELECT * FROM item WHERE category = 'Events' AND status = 'available' ORDER BY postDate DESC LIMIT 5");
        $fStmt->execute();
        $featuredEvents = $fStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// --- Fetch Most Viewed Items ---
$mostViewedItems = [];
try {
    $mvStmt = $pdo->prepare("SELECT ItemID, title, price, category, image, views FROM item WHERE status = 'available' AND views > 0 ORDER BY views DESC LIMIT 6");
    $mvStmt->execute();
    $mostViewedItems = $mvStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}



$category = $_GET['category'] ?? '';
$search   = $_GET['search'] ?? '';
$orderParam = $_GET['order'] ?? 'new';

switch ($orderParam) {
    case 'old':
        $orderBy = 'postDate ASC';
        break;
    case 'price_low':
        $orderBy = 'price ASC';
        break;
    case 'price_high':
        $orderBy = 'price DESC';
        break;
    default: // 'new'
        $orderBy = 'postDate DESC';
}

$items = [];
try {
    $sql = "SELECT ItemID,title,price,category,postDate,description,image,status,event_date FROM item WHERE status = 'available'";
    $params = [];
    if (!empty($search)) { $sql .= " AND (title LIKE ? OR description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($category) { $sql .= " AND category = ?"; $params[] = $category; }
    $sql .= " ORDER BY $orderBy LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Split items into three categories
$eventItems = array_filter($items, function($item) {
    return $item['category'] === 'Events';
});

$serviceItems = array_filter($items, function($item) {
    return $item['category'] === 'Peer-to-Peer Services';
});

$productItems = array_filter($items, function($item) {
    return !in_array($item['category'], ['Events', 'Peer-to-Peer Services']);
});

$categoriesForButtons = [
    'Academics & Study Materials' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
    'Housing & Dorm Living' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'Electronics & Tech' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    'Peer-to-Peer Services' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'Transportation & Travel' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'Clothing & Accessories' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>',
    'Garage Sale' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    'Events' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'Others' => '<svg class="cat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>'
];
if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPES - Home </title>
<link rel="icon" type="image/png" href="letter-w.png">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
/* ===== BRIGHT WHITE CLEAN THEME ===== */
:root {
    /* BRIGHT WHITE PALETTE - Clean, Modern, Professional */
    --bg-main: #ffffff;
    --bg-cream: #f8f9fc;
    --bg-card: #ffffff;
    --bg-dark: #1a1a2e;
    --text: #2d3748;
    --text-secondary: #4a5568;
    --muted: #718096;
    --border: rgba(0, 0, 0, 0.06);
    --border-dark: rgba(0, 0, 0, 0.1);
    --accent: #673de6;
    --accent-hover: #5025d1;
    --accent-light: #f0ebff;
    --accent-glow: rgba(103, 61, 230, 0.15);
    --danger: #e53e3e;
    --success: #38a169;
}

* { box-sizing: border-box; }

body { 
    font-family: 'Outfit', sans-serif; 
    margin: 0; 
    background: var(--bg-main);
    color: var(--text); 
    overflow-x: hidden;
    line-height: 1.6;
    min-height: 100vh;
}

/* Clean Minimal Background */
.page-bg {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
    background: var(--bg-main);
}

/* Subtle accent glow */
.page-bg::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -20%;
    width: 60%;
    height: 60%;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.06) 0%, transparent 60%);
    pointer-events: none;
}

/* Hide colorful orbs for minimal look */
.orb { display: none; }

/* Remove grain for cleaner look */
a { text-decoration: none; color: inherit; }

/* ===== LIGHT HEADER ===== */
.header { 
    display: flex; 
    align-items: center; 
    padding: 16px 30px; 
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border); 
    position: sticky; 
    top: 0; 
    z-index: 50; 
    padding-right: 90px;
}

.header .brand { 
    flex: 1; 
    font-weight: 800; 
    font-size: 24px; 
    background: linear-gradient(135deg, #4B0082 0%, #33005c 100%);
    -webkit-background-clip: text; 
    -webkit-text-fill-color: transparent; 
    background-clip: text;
    letter-spacing: -1px;
}

/* ... (Existing controls CSS) ... */

/* INDIGO THEMED SECTIONS */
.events-section .section-header,
.services-section .section-header,
.products-section .section-header { 
    border-bottom-color: var(--border); 
}

.events-section .section-icon { 
    background: linear-gradient(135deg, #5a189a, #4B0082);
    box-shadow: 0 4px 15px rgba(75, 0, 130, 0.3);
}

.services-section .section-icon { 
    background: linear-gradient(135deg, #7b2cbf, #5a189a);
    box-shadow: 0 4px 15px rgba(90, 24, 154, 0.3);
}

.products-section .section-icon { 
    background: linear-gradient(135deg, #9d4edd, #7b2cbf);
    box-shadow: 0 4px 15px rgba(123, 44, 191, 0.3);
}

.controls { display: flex; gap: 20px; align-items: center; }

.avatar { 
    width: 40px; 
    height: 40px; 
    border-radius: 12px; 
    object-fit: cover; 
    border: 1px solid var(--border);
    transition: all 0.2s;
}
.avatar:hover { 
    border-color: var(--accent); 
    transform: scale(1.05);
}

/* Letter Avatar */
.letter-avatar {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 700;
    color: var(--accent);
    background: var(--accent-light);
    border: 1px solid var(--accent);
    transition: all 0.2s;
    text-transform: uppercase;
}
.letter-avatar:hover {
    background: var(--accent);
    color: white;
    transform: scale(1.05);
    box-shadow: 0 4px 12px var(--accent-light);
}

/* Header Profile Link with Name */
.header-profile-link {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--text);
    padding: 6px 12px 6px 6px;
    border-radius: 25px;
    transition: all 0.2s;
    background: var(--bg-cream);
    border: 1px solid var(--border);
}
.header-profile-link:hover {
    background: var(--accent-light);
    border-color: var(--accent);
}
.header-username {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
    max-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
@media (max-width: 768px) {
    .header-username {
        display: none;
    }
    .header-profile-link {
        padding: 0;
        background: none;
        border: none;
    }
}

/* Mobile Menu Button - Hidden on desktop, shown on mobile */
.mobile-menu-btn {
    display: none;
    position: relative;
    width: 40px;
    height: 40px;
    align-items: center;
    justify-content: center;
    background: var(--bg-dark);
    border: none;
    border-radius: 10px;
    cursor: pointer;
    color: white;
    margin-left: 12px;
    transition: all 0.2s;
}
.mobile-menu-btn:hover {
    background: var(--accent);
}
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

/* ===== PREMIUM SIDEBAR ===== */
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

/* Sidebar Toggle Button - Dark for visibility on light header */
.sidebar-toggle-btn {
    width: 70px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: white; /* White color for visibility on dark sidebar */
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
.sidebar-link.active { color: white; padding-left: 10px; border-color: var(--accent); }
.sidebar-link.active .sidebar-icon { fill: var(--accent); }
.sidebar-icon { width: 22px; height: 22px; fill: currentColor; }

/* Social Icons REMOVED - Footer is kept simple */
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

/* NOTIFICATION BELL */
.notification-wrapper {
    position: relative;
}

.notification-btn {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    cursor: pointer;
    padding: 10px;
    color: var(--text);
    font-size: 20px;
    position: relative;
    transition: all 0.3s;
    border-radius: 12px;
}

.notification-btn:hover {
    background: var(--accent-light);
    border-color: var(--accent);
    transform: scale(1.05);
}

.notification-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--bg-dark);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
}

.notification-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 380px;
    max-width: calc(100vw - 90px);
    max-height: 480px;
    background: rgba(20, 20, 30, 0.95);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px;
    box-shadow: 0 25px 80px rgba(0,0,0,0.5);
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    overflow: hidden;
}

.notification-dropdown.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.notification-header {
    padding: 18px 22px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: white;
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 14px 20px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    cursor: pointer;
    transition: all 0.2s;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.notification-item:hover {
    background: rgba(255,255,255,0.05);
}

.notification-item.unread {
    background: rgba(139, 92, 246, 0.1);
}

.notification-item.unread::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--accent);
}

.notification-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.notification-icon.admin_message {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
}

.notification-icon.memo {
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: white;
}

.notification-icon.event {
    background: linear-gradient(135deg, #f59e0b, #fbbf24);
    color: white;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-title {
    font-weight: 600;
    font-size: 14px;
    color: white;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.notification-text {
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.notification-time {
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
}

.notification-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--muted);
}

.notification-empty-icon {
    font-size: 48px;
    margin-bottom: 12px;
}

/* MEMO MODAL */
.memo-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    backdrop-filter: blur(8px);
}

.memo-modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

.memo-modal {
    background: #12121a;
    border-radius: 20px;
    width: 95%;
    max-width: 650px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 30px 80px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.1);
    animation: memoSlideIn 0.4s ease-out;
}

@keyframes memoSlideIn {
    from { opacity: 0; transform: scale(0.9) translateY(20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.memo-modal-header {
    padding: 24px 28px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    background: linear-gradient(135deg, #4B0082, #6b21a8);
    border-radius: 20px 20px 0 0;
    color: white;
}

.memo-modal-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
}

.memo-modal-header .memo-date {
    font-size: 13px;
    opacity: 0.8;
    margin-top: 4px;
}

.memo-modal-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}

.memo-modal-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.memo-modal-body {
    padding: 28px;
    overflow-y: auto;
    flex: 1;
    background: #1a1a2e;
}

.memo-modal-content {
    font-size: 16px;
    line-height: 1.8;
    color: rgba(255, 255, 255, 0.9);
    white-space: pre-wrap;
}

.memo-attachment {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.memo-attachment-title {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
}

.memo-attachment-image {
    max-width: 100%;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.memo-attachment-file {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    background: rgba(139, 92, 246, 0.15);
    border-radius: 8px;
    color: #a78bfa;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s;
}

.memo-attachment-file:hover {
    background: rgba(139, 92, 246, 0.25);
}
    color: var(--accent);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s;
}

.memo-attachment-file:hover {
    background: var(--accent-light);
}


/* --- MAIN LAYOUT --- */
.main-container { 
    max-width: 1200px; 
    margin: 0 auto; 
    padding: 40px 20px; 
    padding-right: 90px;
    min-height: calc(100vh - 80px);
    position: relative;
    z-index: 1;
}

/* ===== WELCOME SECTION - COMPACT ===== */
.welcome-section { 
    margin-bottom: 24px;
    padding: 10px 0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.welcome-section::before,
.welcome-section::after { display: none; }

.welcome-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.welcome-icon {
    display: none;
}

.welcome-section h1 { 
    font-weight: 800; 
    font-size: 32px; 
    margin: 0; 
    color: var(--text);
    letter-spacing: -1px;
    line-height: 1.2;
    background: none;
    -webkit-text-fill-color: var(--text);
}

.welcome-subtitle {
    font-size: 15px;
    color: var(--muted);
    margin-top: 2px;
    font-weight: 400;
}

.welcome-date { 
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-secondary); 
    font-weight: 500; 
    font-size: 13px;
    background: var(--bg-cream);
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
    flex-shrink: 0;
}

.welcome-date svg {
    width: 14px;
    height: 14px;
    stroke: var(--accent);
    stroke-width: 2;
    fill: none;
}

/* ===== SEARCH BAR - MINIMALIST ===== */
.search-form { 
    display: flex; 
    gap: 12px; 
    margin-bottom: 32px;
}

.search-wrapper {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 20px;
    width: 20px;
    height: 20px;
    stroke: var(--muted);
    stroke-width: 2;
    fill: none;
    pointer-events: none;
    transition: stroke 0.2s;
}

.search-wrapper:focus-within .search-icon {
    stroke: var(--accent);
}

.search-input { 
    width: 100%;
    padding: 16px 20px 16px 52px; 
    border-radius: 14px; 
    border: 1px solid var(--border);
    background: var(--bg-card);
    font-size: 15px;
    font-weight: 400;
    color: var(--text);
    transition: all 0.2s;
}

.search-input::placeholder { color: var(--muted); }
.search-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-light);
}

.search-btn { 
    padding: 0 28px;
    height: 54px;
    background: var(--accent);
    color: white; 
    border: none; 
    border-radius: 14px; 
    font-weight: 600; 
    cursor: pointer; 
    transition: all 0.2s;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-btn svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.search-btn:hover { 
    background: var(--accent-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px var(--accent-glow);
}

/* ===== FILTER CHIPS - MINIMALIST ===== */
.filters-container { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 12px; 
    margin-bottom: 40px; 
    align-items: center;
}

.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.cat-icon {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
    flex-shrink: 0;
}

.chip { 
    border: 1px solid var(--border);
    background: var(--bg-card);
    font-size: 13px; 
    cursor: pointer; 
    padding: 10px 18px; 
    border-radius: 10px; 
    color: var(--text-secondary);
    font-weight: 500; 
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.chip:hover { 
    background: var(--bg-cream);
    border-color: var(--border-dark);
    color: var(--text);
}

.chip.active { 
    background: var(--accent);
    border-color: var(--accent);
    color: white;
}

.chip.active .cat-icon {
    stroke: white;
}

/* Category Button - Now visible on all screens */
.mobile-category-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 24px;
    background: var(--panel);
    border: 2px solid var(--border);
    border-radius: 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: all 0.3s;
    max-width: 300px;
}
.mobile-category-btn:hover {
    border-color: var(--accent);
    background: var(--accent-light);
}
.mobile-category-btn .current-cat {
    color: var(--accent);
    margin-left: auto;
}

/* Hide horizontal chips - now using modal instead */
.filter-chips {
    display: none;
}

/* Category Modal Overlay */
.category-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    backdrop-filter: blur(4px);
}
.category-modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
}
.category-modal {
    background: var(--panel);
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    border-radius: 24px;
    padding: 24px;
    transform: scale(0.9);
    transition: transform 0.3s ease-out;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.category-modal-overlay.active .category-modal {
    transform: scale(1);
}
.category-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}
.category-modal-header h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
}
.category-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: var(--muted);
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
}
.category-modal-close:hover {
    color: var(--accent);
}
.category-modal-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.category-modal-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 18px;
    background: var(--bg-dark);
    border: 2px solid transparent;
    border-radius: 14px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.category-modal-item:hover {
    border-color: var(--accent);
    background: var(--accent-light);
    transform: translateY(-2px);
}
.category-modal-item.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.sort-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.sort-icon {
    position: absolute;
    left: 14px;
    width: 16px;
    height: 16px;
    stroke: var(--muted);
    stroke-width: 2;
    fill: none;
    pointer-events: none;
}

.sort-select { 
    padding: 10px 36px 10px 38px; 
    border-radius: 10px; 
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
    appearance: none;
}

.sort-select:hover {
    border-color: var(--border-dark);
}

.sort-select option { background: var(--bg-card); color: var(--text); }

.sort-arrow {
    position: absolute;
    right: 12px;
    width: 14px;
    height: 14px;
    stroke: var(--muted);
    stroke-width: 2;
    fill: none;
    pointer-events: none;
}

/* ===== FEATURED SLIDER - MINIMALIST ===== */
.slider-container { 
    position: relative; 
    width: 100%; 
    height: 320px; 
    border-radius: 20px; 
    overflow: hidden; 
    border: 1px solid var(--border);
    background: var(--bg-cream);
    margin-bottom: 48px; 
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
}

.slide { 
    position: absolute; 
    width: 100%; 
    height: 100%; 
    opacity: 0; 
    transition: opacity 0.6s ease; 
    background-size: cover; 
    background-position: center; 
    display: flex; 
    align-items: flex-end; 
    cursor: pointer; 
}
.slide.active { opacity: 1; z-index: 1; }
.slide::before { 
    content: ''; 
    position: absolute; 
    top: 0; left: 0; right: 0; bottom: 0; 
    background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.2) 60%, transparent 100%);
    z-index: 1; 
}

.slide-overlay { 
    width: 100%; 
    padding: 40px; 
    color: #fff; 
    position: relative; 
    z-index: 2; 
}
.slide-overlay h2 { margin: 0; font-size: 32px; font-weight: 700; }
.slide-date-large { 
    font-size: 14px; 
    font-weight: 600; 
    color: white;
    background: var(--accent);
    display: inline-block;
    padding: 6px 16px;
    border-radius: 8px;
    margin-top: 10px;
}
.slider-dots { position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 10; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.2s; }
.dot:hover { background: rgba(255,255,255,0.7); }
.dot.active { background: white; width: 24px; border-radius: 4px; }

.section-title { font-size: 20px; font-weight: 700; margin-bottom: 6px; color: var(--text); display: flex; align-items: center; gap: 10px; }
.section-subtitle { font-size: 14px; color: var(--muted); font-weight: 400; margin-bottom: 20px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }

/* Content Sections - Minimalist */
.content-section { margin-bottom: 48px; }
.content-section:last-child { margin-bottom: 0; }

/* Section Header */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.section-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}

.section-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent);
}

.section-icon svg {
    width: 24px;
    height: 24px;
    stroke: white;
    stroke-width: 2;
    fill: none;
}

.section-header-text h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}

.section-header-text p {
    font-size: 13px;
    color: var(--muted);
    margin: 2px 0 0 0;
}

.section-count {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: var(--bg-cream);
    padding: 6px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
}

/* All Sections - Clean White Style */
.events-section,
.services-section,
.products-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 32px;
    margin-bottom: 32px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
}

.events-section::before,
.events-section::after,
.services-section::before,
.products-section::before { display: none; }

.events-section .section-header,
.services-section .section-header,
.products-section .section-header { 
    border-bottom-color: var(--border); 
}

.events-section .section-icon,
.services-section .section-icon,
.products-section .section-icon { 
    background: var(--accent);
    box-shadow: none;
}

.events-section .section-count,
.services-section .section-count,
.products-section .section-count { 
    background: var(--bg-cream);
    border-color: var(--border);
    color: var(--text-secondary);
}

/* Section Divider */
.section-divider { display: none; }

/* ===== MOST VIEWED SECTION ===== */
.most-viewed-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 28px;
    margin-bottom: 32px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
}

.most-viewed-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.most-viewed-header h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}

.most-viewed-header h2 span {
    font-size: 24px;
}

.most-viewed-scroll {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 10px;
    scroll-behavior: smooth;
    scrollbar-width: thin;
    scrollbar-color: var(--accent-light) transparent;
}

.most-viewed-scroll::-webkit-scrollbar {
    height: 6px;
}

.most-viewed-scroll::-webkit-scrollbar-thumb {
    background: var(--accent-light);
    border-radius: 10px;
}

.mv-card {
    flex: 0 0 200px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    color: inherit;
}

.mv-card:hover {
    transform: translateY(-4px);
    border-color: var(--accent);
    box-shadow: 0 10px 25px rgba(103, 61, 230, 0.12);
}

.mv-card-image {
    height: 130px;
    background: var(--bg-cream);
    position: relative;
    overflow: hidden;
}

.mv-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.mv-card:hover .mv-card-image img {
    transform: scale(1.05);
}

.hot-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: linear-gradient(135deg, #ff6b35, #f7931e);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 8px rgba(255, 107, 53, 0.4);
    animation: pulse-hot 2s infinite;
}

@keyframes pulse-hot {
    0%, 100% { box-shadow: 0 2px 8px rgba(255, 107, 53, 0.4); }
    50% { box-shadow: 0 2px 16px rgba(255, 107, 53, 0.6); }
}

.views-badge {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}

.mv-card-content {
    padding: 12px;
}

.mv-card-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 6px 0;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.mv-card-price {
    font-size: 15px;
    font-weight: 700;
    color: var(--accent);
}

/* ===== QUICK FILTER CHIPS ===== */
.quick-filter-section {
    margin-bottom: 28px;
}

.quick-filter-scroll {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding: 8px 0;
    scroll-behavior: smooth;
    scrollbar-width: none;
}

.quick-filter-scroll::-webkit-scrollbar {
    display: none;
}

.quick-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    padding: 12px 16px;
    min-width: 85px;
    border-radius: 16px;
    background: var(--bg-card);
    border: 2px solid var(--border);
    transition: all 0.2s ease;
    cursor: pointer;
}

.quick-chip:hover {
    border-color: var(--accent-light);
    background: var(--accent-light);
    transform: translateY(-2px);
}

.quick-chip.active {
    border-color: var(--accent);
    background: var(--accent);
    color: white;
}

.quick-chip-icon {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.quick-chip.active .quick-chip-icon {
    /* No background needed */
}

.quick-chip-icon svg {
    width: 22px;
    height: 22px;
    stroke: var(--accent);
    stroke-width: 2;
    fill: none;
}

.quick-chip.active .quick-chip-icon svg {
    stroke: white;
}

.quick-chip-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-align: center;
    white-space: nowrap;
}

.quick-chip.active .quick-chip-label {
    color: white;
}

/* ===== RECENTLY VIEWED SECTION ===== */
.recently-viewed-section {
    background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%);
    border: 1px solid var(--accent-light);
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 32px;
}

.recently-viewed-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.recently-viewed-header h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}

.recently-viewed-clear {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 8px;
    transition: all 0.2s;
}

.recently-viewed-clear:hover {
    background: rgba(0, 0, 0, 0.05);
    color: var(--danger);
}

.recently-viewed-scroll {
    display: flex;
    gap: 14px;
    overflow-x: auto;
    padding-bottom: 8px;
    scrollbar-width: none;
}

.recently-viewed-scroll::-webkit-scrollbar {
    display: none;
}

.rv-card {
    flex: 0 0 140px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: inherit;
}

.rv-card:hover {
    transform: translateY(-3px);
    border-color: var(--accent);
    box-shadow: 0 8px 20px rgba(103, 61, 230, 0.1);
}

.rv-card-image {
    height: 100px;
    background: var(--bg-cream);
    overflow: hidden;
}

.rv-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.rv-card-content {
    padding: 10px;
}

.rv-card-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rv-card-price {
    font-size: 13px;
    font-weight: 700;
    color: var(--accent);
}

/* Mobile responsive for new sections */
@media (max-width: 768px) {
    .most-viewed-section,
    .recently-viewed-section {
        padding: 20px 16px;
        border-radius: 16px;
    }
    
    .most-viewed-header h2,
    .recently-viewed-header h2 {
        font-size: 16px;
    }
    
    .mv-card {
        flex: 0 0 160px;
    }
    
    .mv-card-image {
        height: 100px;
    }
    
    .quick-chip {
        min-width: 75px;
        padding: 10px 12px;
    }
    
    .quick-chip-icon {
        width: 38px;
        height: 38px;
        font-size: 18px;
    }
    
    .quick-chip-label {
        font-size: 10px;
    }
}

@media (max-width: 480px) {
    .mv-card {
        flex: 0 0 140px;
    }
    
    .mv-card-image {
        height: 90px;
    }
    
    .mv-card-content {
        padding: 10px;
    }
    
    .mv-card-title {
        font-size: 12px;
    }
    
    .mv-card-price {
        font-size: 13px;
    }
    
    .rv-card {
        flex: 0 0 120px;
    }
    
    .rv-card-image {
        height: 80px;
    }
}


/* ===== CARDS - MINIMALIST ===== */
.card { 
    background: var(--bg-card);
    border: 1px solid var(--border);
    padding: 0; 
    border-radius: 16px; 
    display: flex; 
    flex-direction: column; 
    cursor: pointer; 
    transition: all 0.25s ease; 
    overflow: hidden;
}

.card:hover { 
    transform: translateY(-6px);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.1);
    border-color: var(--border-dark);
}

.events-section .card:hover,
.services-section .card:hover,
.products-section .card:hover {
    border-color: var(--accent);
    box-shadow: 0 16px 40px rgba(99, 102, 241, 0.12);
}

.card-image { 
    height: 180px; 
    background: var(--bg-cream);
    position: relative;
    overflow: hidden;
}

.card-image img { 
    width: 100%; 
    height: 100%; 
    object-fit: cover;
    transition: transform 0.4s;
}

.card:hover .card-image img { transform: scale(1.05); }

.card-badge { 
    position: absolute; 
    top: 12px; 
    right: 12px; 
    background: var(--accent);
    color: white; 
    padding: 6px 12px; 
    border-radius: 8px; 
    font-size: 11px; 
    font-weight: 600;
}

.card-content { 
    padding: 20px; 
    display: flex; 
    flex-direction: column; 
    gap: 10px;
}

.card-title { 
    margin: 0; 
    font-weight: 600; 
    font-size: 15px; 
    color: var(--text);
    line-height: 1.4; 
}

.card-meta { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    font-size: 12px; 
    color: var(--muted);
}

.price { 
    font-size: 18px; 
    font-weight: 700; 
    color: var(--accent);
}

.events-section .price,
.services-section .price,
.products-section .price {
    color: var(--accent);
}

.empty-grid { 
    grid-column: 1/-1; 
    padding: 60px; 
    text-align: center; 
    color: var(--muted);
    background: var(--bg-cream);
    border-radius: 16px; 
    border: 1px dashed var(--border);
    font-size: 14px;
}

/* ===== MODAL - GLASSMORPHISM ===== */
.modal-overlay { 
    position: fixed; 
    inset: 0; 
    background: rgba(0,0,0,0.7);
    display: flex; 
    align-items: center; 
    justify-content: center; 
    z-index: 2000; 
    opacity: 0; 
    pointer-events: none; 
    transition: opacity 0.3s; 
    backdrop-filter: blur(8px);
}
.modal-overlay.is-visible { opacity: 1; pointer-events: auto; }

.modal-content { 
    background: rgba(20, 20, 30, 0.95);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px; 
    width: 90%; 
    max-width: 500px; 
    max-height: 80vh; 
    display: flex; 
    flex-direction: column; 
    box-shadow: 0 30px 80px rgba(0,0,0,0.5);
}

.modal-header { 
    padding: 24px; 
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex; 
    justify-content: space-between; 
    align-items: center;
}

.modal-header h3 { color: white; margin: 0; font-weight: 700; }
.modal-close { background: none; border: none; color: var(--muted); font-size: 24px; cursor: pointer; }
.modal-body { padding: 0; overflow-y: auto; }

.saved-item { 
    display: flex; 
    gap: 15px; 
    padding: 18px 24px; 
    border-bottom: 1px solid rgba(255,255,255,0.05);
    text-decoration: none; 
    color: white;
    align-items: center; 
    transition: all 0.3s; 
    background: transparent;
}
.saved-item:hover { background: rgba(139, 92, 246, 0.1); }
.saved-img { width: 60px; height: 60px; border-radius: 12px; object-fit: cover; border: 1px solid rgba(255,255,255,0.1); }
.unsave-btn { background: none; border: none; color: var(--muted); font-size: 18px; cursor: pointer; }
.unsave-btn:hover { color: var(--danger); }

@media (max-width: 768px) {
    /* MOBILE: Use transform animation for smooth GPU-accelerated performance */
    .right-sidebar {
        width: 300px; /* Full width for mobile */
        transform: translateX(100%); /* Hidden off-screen */
        transition: transform 0.3s ease-out; /* GPU-accelerated transition */
    }
    .right-sidebar.open {
        transform: translateX(0); /* Slide in */
    }
    .sidebar-toggle-btn {
        display: none;
    }
    
    /* Show hamburger in header on mobile */
    .mobile-menu-btn {
        display: flex !important;
    }
    
    /* Adjust header padding since no sidebar visible */
    .header {
        padding-right: 20px;
    }
    
    /* Main container full width on mobile */
    .main-container {
        padding-right: 16px;
    }
    
    .grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .events-section, .services-section, .products-section { padding: 24px; border-radius: 20px; }
    .section-header { flex-direction: column; align-items: flex-start; gap: 16px; }
    .section-header-text h2 { font-size: 18px; }
    .section-header-text p { font-size: 12px; }
    .section-icon { width: 40px; height: 40px; }
    .section-icon svg { width: 20px; height: 20px; }
    .welcome-section h1 { font-size: 28px; }
    
    /* Horizontal Scrollable Category Chips */
    .filters-container {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    
    /* Hide horizontal chips on mobile, show modal trigger instead */
    .filter-chips {
        display: none;
    }
    
    /* Mobile Category Button - Full width on mobile */
    .mobile-category-btn {
        width: 100%;
        max-width: none;
    }
    
    /* Category Modal - Bottom sheet on mobile */
    .category-modal-overlay {
        align-items: flex-end;
    }
    .category-modal {
        width: 100%;
        max-width: 100%;
        border-radius: 24px 24px 0 0;
        transform: translateY(100%);
    }
    .category-modal-overlay.active .category-modal {
        transform: translateY(0);
    }
    .category-modal-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) { 
    .header { padding: 12px 20px; } 
    .grid { grid-template-columns: 1fr; gap: 16px; } 
    .card-content { padding: 16px; } 
    .slider-container { height: 220px; margin-bottom: 30px; border-radius: 16px; }
    .main-container { padding: 24px 16px; }
    
    /* Section Header - Extra small for mobile */
    .section-header-text h2 { font-size: 16px; }
    .section-header-text p { font-size: 11px; }
    .section-icon { width: 36px; height: 36px; }
    .section-icon svg { width: 18px; height: 18px; }
    .section-count { font-size: 10px; padding: 4px 10px; }
    
    /* Smaller chips on very small screens */
    .chip {
        padding: 8px 14px;
        font-size: 11px;
    }
    .cat-icon {
        width: 14px;
        height: 14px;
    }
    
    /* Notification Dropdown - Fit Screen */
    .notification-dropdown {
        position: fixed;
        top: 70px;
        left: 10px;
        right: 70px;
        width: auto;
        max-height: 70vh;
        border-radius: 16px;
    }
    .notification-header {
        padding: 14px 16px;
    }
    .notification-header h3 {
        font-size: 14px;
    }
    .notification-list {
        max-height: 55vh;
    }
    .notification-item {
        padding: 12px 14px;
    }
    .notification-icon {
        width: 36px;
        height: 36px;
    }
    .notification-title {
        font-size: 13px;
    }
    .notification-text {
        font-size: 11px;
    }
    .notification-time {
        font-size: 10px;
    }
    
    /* Cards - 2 per row on mobile with better proportions */
    .grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .card {
        border-radius: 12px;
    }
    .card-image {
        height: 120px;
    }
    .card-content {
        padding: 10px 12px 12px;
    }
    .card-title {
        font-size: 13px;
        line-height: 1.3;
        margin-bottom: 6px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .card-meta {
        font-size: 10px;
        margin-bottom: 6px;
    }
    .price {
        font-size: 14px;
    }
    .card-badge {
        padding: 4px 8px;
        font-size: 9px;
        border-radius: 6px;
    }
    
    /* Event Slider - Smaller text for mobile */
    .slide-overlay {
        padding: 16px 20px;
    }
    .slide-overlay h2 {
        font-size: 16px;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .slide-date-large {
        font-size: 11px;
        padding: 4px 10px;
        margin-top: 6px;
    }
    .slider-dots {
        bottom: 10px;
        gap: 6px;
    }
    .dot {
        width: 6px;
        height: 6px;
    }
    .dot.active {
        width: 18px;
    }
    
    /* Search - Smaller for mobile */
    .search-form {
        gap: 8px;
    }
    .search-input {
        padding: 12px 16px 12px 40px;
        font-size: 13px;
        border-radius: 10px;
    }
    .search-icon {
        left: 12px;
        width: 14px;
        height: 14px;
    }
    .search-btn {
        padding: 0 12px;
        height: 38px;
        font-size: 12px;
        border-radius: 8px;
    }
    .search-btn svg {
        width: 14px;
        height: 14px;
    }
    
    /* Welcome Section - Ultra Compact for mobile */
    .welcome-section {
        margin-bottom: 12px;
        padding: 6px 0;
    }
    .welcome-icon {
        display: none;
    }
    .welcome-section h1 {
        font-size: 18px;
        letter-spacing: -0.5px;
    }
    .welcome-subtitle {
        font-size: 11px;
        margin-top: 2px;
    }
    .welcome-date {
        display: none;
    }
    
    /* Header Brand - Smaller for mobile */
    .brand {
        font-size: 16px !important;
    }
    
    /* Notification - Smaller for mobile */
    .notification-btn {
        width: 36px;
        height: 36px;
    }
    .notification-btn svg {
        width: 18px;
        height: 18px;
    }
    .notification-badge {
        min-width: 14px;
        height: 14px;
        font-size: 8px;
        top: -2px;
        right: -2px;
    }
    
    /* Avatar - Smaller for mobile */
    .avatar, .letter-avatar {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        font-size: 13px;
    }
}
</style>
</head>
<body>

<!-- Animated Background -->
<div class="page-bg"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div class="grain"></div>

<header class="header">
  <a href="home.php" class="brand">Campus Preloved E-Shop</a>
  <div class="controls">
    <?php if (!empty($userid)): ?>
        <!-- Notification Bell -->
        <div class="notification-wrapper">
            <button class="notification-btn" onclick="toggleNotifications(event)">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php if ($unreadNotifications > 0): ?>
                    <span class="notification-badge"><?= $unreadNotifications ?></span>
                <?php endif; ?>
            </button>
            
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <h3>Notifications</h3>
                </div>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="notification-empty">
                            <div class="notification-empty-icon">🔕</div>
                            <p>No notifications yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-item <?= !$notif['is_read'] ? 'unread' : '' ?>" 
                                 onclick="<?= $notif['type'] === 'memo' ? "openMemoModal({$notif['id']})" : ($notif['type'] === 'admin_message' ? "showAdminMessage('{$notif['id']}')" : "window.location.href='item_detail.php?id={$notif['id']}'") ?>">
                                <div class="notification-icon <?= $notif['type'] ?>">
                                    <?= $notif['type'] === 'memo' ? '📧' : ($notif['type'] === 'admin_message' ? '📧' : '🎫') ?>
                                </div>
                                <div class="notification-content">
                                    <div class="notification-title"><?= h($notif['title']) ?></div>
                                    <div class="notification-text"><?= h(substr($notif['content'], 0, 60)) ?>...</div>
                                    <div class="notification-time"><?= time_elapsed_string($notif['date']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <a href="profile.php" class="header-profile-link">
            <?php if ($hasAvatar): ?>
                <img src="<?= h($avatarSrc) ?>" class="avatar">
            <?php else: ?>
                <div class="letter-avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
            <?php endif; ?>
            <span class="header-username"><?= h($name) ?></span>
        </a>
        
        <!-- Mobile hamburger button -->
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
        <a href="index.html" style="font-weight:600; font-size:14px; color:var(--accent);">Login</a>
    <?php endif; ?>
    </div>
</header>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Category Modal (Mobile) -->
<div class="category-modal-overlay" id="categoryModal" onclick="closeCategoryModal(event)">
    <div class="category-modal" onclick="event.stopPropagation()">
        <div class="category-modal-header">
            <h3>Select Category</h3>
            <button class="category-modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        <div class="category-modal-grid">
            <a href="?category=" class="category-modal-item <?= empty($category) ? 'active' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/>
                    <rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/>
                </svg>
                All
            </a>
            <?php foreach ($categoriesForButtons as $label => $icon): ?>
                <a href="?category=<?= urlencode($label) ?>" class="category-modal-item <?= ($category === $label) ? 'active' : '' ?>">
                    <?= $icon ?> <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

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
        
        <?php if (!empty($userid)): ?>
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
                <p style="color:#666; margin-bottom:20px;">Please login to access.</p>
                <a href="index.html" class="search-btn" style="text-align:center; display:block; text-decoration:none; padding:12px;">Login</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
    </div>
</aside>

<div class="main-container">
    <div class="welcome-section">
        <div class="welcome-content">
            <div class="welcome-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <div>
                <h1>Welcome back, <?= h($name) ?>.</h1>
                <p class="welcome-subtitle">Discover preloved items and campus services</p>
            </div>
        </div>
        <div class="welcome-date">
            <svg viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <?= date('l, F j, Y') ?>
        </div>
    </div>

    <form method="get" class="search-form">
        <div class="search-wrapper">
            <svg class="search-icon" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" name="search" class="search-input" placeholder="Search for items, services, or events..." value="<?= h($search) ?>">
        </div>
        <button type="submit" class="search-btn">
            <svg viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.35-4.35"/>
            </svg>
            Search
        </button>
    </form>

    <div class="filters-container">
        <form method="get">
            <input type="hidden" name="category" value="<?= h($category) ?>">
            <div class="sort-wrapper">
                <svg class="sort-icon" viewBox="0 0 24 24">
                    <line x1="4" y1="6" x2="11" y2="6"/>
                    <line x1="4" y1="12" x2="11" y2="12"/>
                    <line x1="4" y1="18" x2="13" y2="18"/>
                    <polyline points="15 15 18 18 21 15"/>
                    <line x1="18" y1="6" x2="18" y2="18"/>
                </svg>
                <select name="order" class="sort-select" onchange="this.form.submit()">
                    <option value="new" <?= $orderParam === 'new' ? 'selected' : '' ?>>Newest First</option>
                    <option value="old" <?= $orderParam === 'old' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="price_low" <?= $orderParam === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_high" <?= $orderParam === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                </select>
                <svg class="sort-arrow" viewBox="0 0 24 24">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>
        </form>
    </div>

    <!-- Event slider moved to Events section below -->

    <!-- Quick Filter Chips -->
    <?php if (empty($search)): ?>
    <div class="quick-filter-section">
        <div class="quick-filter-scroll">
            <a href="?category=" class="quick-chip <?= empty($category) ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <span class="quick-chip-label">All</span>
            </a>
            <a href="?category=Academics+%26+Study+Materials" class="quick-chip <?= $category === 'Academics & Study Materials' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                </div>
                <span class="quick-chip-label">Academic</span>
            </a>
            <a href="?category=Electronics+%26+Tech" class="quick-chip <?= $category === 'Electronics & Tech' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </div>
                <span class="quick-chip-label">Tech</span>
            </a>
            <a href="?category=Clothing+%26+Accessories" class="quick-chip <?= $category === 'Clothing & Accessories' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>
                </div>
                <span class="quick-chip-label">Fashion</span>
            </a>
            <a href="?category=Housing+%26+Dorm+Living" class="quick-chip <?= $category === 'Housing & Dorm Living' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <span class="quick-chip-label">Housing</span>
            </a>
            <a href="?category=Peer-to-Peer+Services" class="quick-chip <?= $category === 'Peer-to-Peer Services' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <span class="quick-chip-label">Services</span>
            </a>
            <a href="?category=Transportation+%26+Travel" class="quick-chip <?= $category === 'Transportation & Travel' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <span class="quick-chip-label">Transport</span>
            </a>
            <a href="?category=Garage+Sale" class="quick-chip <?= $category === 'Garage Sale' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                </div>
                <span class="quick-chip-label">Sale</span>
            </a>
            <a href="?category=Events" class="quick-chip <?= $category === 'Events' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <span class="quick-chip-label">Events</span>
            </a>
            <a href="?category=Others" class="quick-chip <?= $category === 'Others' ? 'active' : '' ?>">
                <div class="quick-chip-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                </div>
                <span class="quick-chip-label">Others</span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Most Viewed Section -->
    <?php if (!empty($mostViewedItems) && empty($search) && empty($category)): ?>
    <section class="most-viewed-section">
        <div class="most-viewed-header">
        <h2>Most Viewed</h2>
        </div>
        <div class="most-viewed-scroll">
            <?php foreach($mostViewedItems as $i => $mv): 
                $mvImg = !empty($mv['image']) ? explode(',', $mv['image'])[0] : '';
                $mvPrice = ($mv['category'] === 'Events') ? 'Event' : 'RM ' . number_format($mv['price'], 2);
            ?>
            <a href="item_detail.php?id=<?= $mv['ItemID'] ?>" class="mv-card">
                <div class="mv-card-image">
                    <img src="<?= h($mvImg) ?>" alt="<?= h($mv['title']) ?>">
                    <?php if($i === 0): ?>
                    <div class="hot-badge">🔥 HOT</div>
                    <?php endif; ?>
                    <div class="views-badge">👁 <?= number_format($mv['views']) ?></div>
                </div>
                <div class="mv-card-content">
                    <h3 class="mv-card-title"><?= h($mv['title']) ?></h3>
                    <div class="mv-card-price"><?= $mvPrice ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>


    <?php if($search || $category): ?>
        <!-- Search/Filter Results - Show all in one grid -->
        <div class="content-section">
            <h2 class="section-title">
                <?php if($search): ?>Results for "<?= h($search) ?>"<?php else: ?><?= h($category) ?><?php endif; ?>
            </h2>
            <div class="grid">
                <?php if(empty($items)): ?>
                    <div class="empty-grid">No items found based on your criteria.</div>
                <?php else: foreach($items as $it): 
                    $img=!empty($it['image'])?explode(',',$it['image'])[0]:''; 
                    $priceDisplay = ($it['category'] === 'Events') ? 'Event' : 'RM ' . number_format($it['price'],2);
                    $timeAgo = time_elapsed_string($it['postDate']);
                ?>
                    <div class="card" onclick="location.href='item_detail.php?id=<?= $it['ItemID'] ?>'">
                        <div class="card-image">
                            <img src="<?= h($img) ?>" alt="<?= h($it['title']) ?>">
                            <?php if($it['category'] === 'Events'): ?>
                                <span class="card-badge">🎫 Event</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <h3 class="card-title"><?= h($it['title']) ?></h3>
                            <div class="card-meta">
                                <span><?= h($it['category']) ?></span>
                                <span><?= $timeAgo ?></span>
                            </div>
                            <div class="price"><?= $priceDisplay ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Default View - Item-focused sections first, then Events/Services -->
        
        <?php if(!empty($productItems)): ?>
        <!-- SECTION 1: Browse Items (Marketplace) - Primary focus -->
        <div class="products-section content-section">
            <div class="section-header">
                <div class="section-header-left">
                    <div class="section-icon" style="color: #f472b6;">
                        <svg viewBox="0 0 24 24">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                    </div>
                    <div class="section-header-text">
                        <h2>Browse Items</h2>
                        <p>Preloved items from fellow UTHM students</p>
                    </div>
                </div>
                <span class="section-count"><?= count($productItems) ?> items</span>
            </div>
            <div class="grid">
                <?php foreach($productItems as $it): 
                    $img=!empty($it['image'])?explode(',',$it['image'])[0]:''; 
                    $timeAgo = time_elapsed_string($it['postDate']);
                ?>
                    <div class="card" onclick="location.href='item_detail.php?id=<?= $it['ItemID'] ?>'">
                        <div class="card-image">
                            <img src="<?= h($img) ?>" alt="<?= h($it['title']) ?>">
                        </div>
                        <div class="card-content">
                            <h3 class="card-title"><?= h($it['title']) ?></h3>
                            <div class="card-meta">
                                <span><?= h($it['category']) ?></span>
                                <span><?= $timeAgo ?></span>
                            </div>
                            <div class="price">RM <?= number_format($it['price'],2) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recently Viewed Section (JS Populated) -->
        <?php if (!empty($userid) && empty($search) && empty($category)): ?>
        <section class="recently-viewed-section" id="recentlyViewedSection" style="display: none;">
            <div class="recently-viewed-header">
                <h2>👁️ Recently Viewed</h2>
                <button class="recently-viewed-clear" onclick="clearRecentlyViewed()">Clear All</button>
            </div>
            <div class="recently-viewed-scroll" id="recentlyViewedScroll">
                <!-- Populated by JavaScript -->
            </div>
        </section>
        <?php endif; ?>

        <?php if(!empty($eventItems)): ?>
        <!-- SECTION 2: Events - Below items -->
        <div class="events-section content-section">
            <div class="section-header">
                <div class="section-header-left">
                    <div class="section-icon" style="color: #a78bfa;">
                        <svg viewBox="0 0 24 24">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                            <path d="M8 14h.01"/>
                            <path d="M12 14h.01"/>
                            <path d="M16 14h.01"/>
                            <path d="M8 18h.01"/>
                            <path d="M12 18h.01"/>
                        </svg>
                    </div>
                    <div class="section-header-text">
                        <h2>Campus Events</h2>
                        <p>Don't miss out on what's happening around campus</p>
                    </div>
                </div>
                <span class="section-count"><?= count($eventItems) ?> upcoming</span>
            </div>
            
            <!-- Featured Event Slider -->
            <?php if (!empty($featuredEvents)): ?>
            <section class="slider-container" style="margin-bottom: 24px;">
                <?php foreach($featuredEvents as $i => $ev): 
                    $img=!empty($ev['image'])?explode(',',$ev['image'])[0]:'assets/event-placeholder.jpg'; 
                    $dateText = !empty($ev['event_date']) ? date('F j, Y', strtotime($ev['event_date'])) : '';
                ?>
                    <div class="slide <?= $i===0?'active':'' ?>" style="background-image:url('<?= h($img) ?>')" onclick="location.href='item_detail.php?id=<?= $ev['ItemID'] ?>'">
                        <div class="slide-overlay">
                            <h2><?= h($ev['title']) ?></h2>
                            <div class="slide-date-large">📅 <?= $dateText ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="slider-dots">
                    <?php foreach($featuredEvents as $i => $ev): ?>
                        <div class="dot <?= $i===0?'active':'' ?>" onclick="currentSlide(<?= $i ?>)"></div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            
            <div class="grid">
                <?php foreach($eventItems as $it): 
                    $img=!empty($it['image'])?explode(',',$it['image'])[0]:''; 
                    $dateText = !empty($it['event_date']) ? date('M j, Y', strtotime($it['event_date'])) : '';
                    $timeAgo = time_elapsed_string($it['postDate']);
                ?>
                    <div class="card" onclick="location.href='item_detail.php?id=<?= $it['ItemID'] ?>'">
                        <div class="card-image">
                            <img src="<?= h($img) ?>" alt="<?= h($it['title']) ?>">
                            <span class="card-badge">📅 <?= $dateText ?></span>
                        </div>
                        <div class="card-content">
                            <h3 class="card-title"><?= h($it['title']) ?></h3>
                            <div class="card-meta">
                                <span>Event</span>
                                <span><?= $timeAgo ?></span>
                            </div>
                            <div class="price"><?= $it['price'] > 0 ? 'RM ' . number_format($it['price'],2) : 'Free Entry' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if(!empty($serviceItems)): ?>
        <!-- SECTION 3: Campus Services - Below events -->
        <div class="services-section content-section">
            <div class="section-header">
                <div class="section-header-left">
                    <div class="section-icon" style="color: #60a5fa;">
                        <svg viewBox="0 0 24 24">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <div class="section-header-text">
                        <h2>Campus Services</h2>
                        <p>Peer-to-peer services offered by UTHM students</p>
                    </div>
                </div>
                <span class="section-count"><?= count($serviceItems) ?> available</span>
            </div>
            <div class="grid">
                <?php foreach($serviceItems as $it): 
                    $img=!empty($it['image'])?explode(',',$it['image'])[0]:''; 
                    $timeAgo = time_elapsed_string($it['postDate']);
                    $serviceIcon = ($it['category'] === 'Transportation & Travel') ? '🚌' : '🤝';
                ?>
                    <div class="card" onclick="location.href='item_detail.php?id=<?= $it['ItemID'] ?>'">
                        <div class="card-image">
                            <img src="<?= h($img) ?>" alt="<?= h($it['title']) ?>">
                            <span class="card-badge"><?= $serviceIcon ?> Service</span>
                        </div>
                        <div class="card-content">
                            <h3 class="card-title"><?= h($it['title']) ?></h3>
                            <div class="card-meta">
                                <span><?= h($it['category']) ?></span>
                                <span><?= $timeAgo ?></span>
                            </div>
                            <div class="price">RM <?= number_format($it['price'],2) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if(empty($eventItems) && empty($serviceItems) && empty($productItems)): ?>
        <div class="content-section">
            <div class="empty-grid">No items available at the moment. Check back soon!</div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<div class="modal-overlay" id="savedModal">
    <div class="modal-content">
        <div class="modal-header"><h3>Saved Items</h3><button class="modal-close" onclick="document.getElementById('savedModal').classList.remove('is-visible')">&times;</button></div>
        <div class="modal-body">
            <?php if(empty($savedItems)): ?><div style="padding:40px; text-align:center; color:var(--muted);">No saved items yet.</div><?php else: foreach($savedItems as $sv): 
                $sImg = !empty($sv['image']) ? explode(',', $sv['image'])[0] : 'avatar.png';
            ?>
                <div class="saved-item">
                    <a href="item_detail.php?id=<?= $sv['ItemID'] ?>" style="display:flex;gap:15px;flex:1;text-decoration:none;color:inherit; align-items:center;">
                        <img src="<?= h($sImg) ?>" class="saved-img">
                        <div class="saved-info"><div style="font-weight:700; font-size:15px;"><?= h($sv['title']) ?></div><div style="color:var(--accent);font-weight:700;">RM <?= number_format($sv['price'], 2) ?></div></div>
                    </a>
                    <form method="POST" onsubmit="return confirm('Unsave this item?');"><input type="hidden" name="unsave_id" value="<?= $sv['ItemID'] ?>"><button type="submit" class="unsave-btn" title="Remove">✕</button></form>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Memo Modal -->
<div class="memo-modal-overlay" id="memoModal" onclick="if(event.target === this) closeMemoModal()">
    <div class="memo-modal">
        <div class="memo-modal-header">
            <div>
                <h2 id="memoModalTitle">Memo Subject</h2>
                <div class="memo-date" id="memoModalDate"></div>
            </div>
            <button class="memo-modal-close" onclick="closeMemoModal()">&times;</button>
        </div>
        <div class="memo-modal-body">
            <div class="memo-modal-content" id="memoModalContent"></div>
            <div class="memo-attachment" id="memoModalAttachment" style="display:none;">
                <div class="memo-attachment-title">Attachments</div>
                <div id="memoAttachmentContent"></div>
            </div>
        </div>
    </div>
</div>

<!-- Admin Message Modal -->
<div class="memo-modal-overlay" id="adminMessageModal" onclick="if(event.target === this) closeAdminMessageModal()">
    <div class="memo-modal" style="max-width: 500px;">
        <div class="memo-modal-header">
            <div>
                <h2>System Admin</h2>
                <div class="memo-date" id="adminMessageDate"></div>
            </div>
            <button class="memo-modal-close" onclick="closeAdminMessageModal()" style="color: #78350f;">&times;</button>
        </div>
        <div class="memo-modal-body">
            <div class="memo-modal-content" id="adminMessageContent" style="white-space: pre-wrap; line-height: 1.6;"></div>
        </div>
    </div>
</div>

<!-- Store memo and admin message data for JavaScript -->
<script>
const memoData = <?= json_encode(array_values(array_map(function($n) {
    return [
        'id' => $n['id'],
        'title' => $n['title'],
        'content' => $n['content'],
        'date' => $n['date'],
        'image' => $n['image'],
        'file' => $n['file']
    ];
}, array_filter($notifications, function($n) { return $n['type'] === 'memo'; })))) ?>;

const adminMessageData = <?= json_encode(array_values(array_map(function($n) {
    return [
        'id' => $n['id'],
        'title' => $n['title'],
        'content' => $n['content'],
        'date' => $n['date']
    ];
}, array_filter($notifications, function($n) { return $n['type'] === 'admin_message'; })))) ?>;
</script>

<script>
function openSavedModal(){ document.getElementById('savedModal').classList.add('is-visible'); }
function closeSavedModal(){ document.getElementById('savedModal').classList.remove('is-visible'); }

// Auto-open bookmarks modal if #bookmarks hash is in URL
if(window.location.hash === '#bookmarks') {
    setTimeout(function() { openSavedModal(); }, 100);
    history.replaceState(null, null, 'home.php');
}

function toggleSidebar() { 
    document.getElementById('sidebar').classList.toggle('open'); 
    document.querySelector('.sidebar-overlay').classList.toggle('active'); 
}

// Category Modal Functions (Mobile)
function openCategoryModal() {
    document.getElementById('categoryModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeCategoryModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('categoryModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Notification Functions
function toggleNotifications(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('notificationDropdown');
    const isOpening = !dropdown.classList.contains('active');
    dropdown.classList.toggle('active');
    
    // Mark notifications as viewed when opening
    if (isOpening) {
        localStorage.setItem('lastNotificationView', Date.now());
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.style.display = 'none';
        }
    }
}

// Close notification dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('notificationDropdown');
    const wrapper = e.target.closest('.notification-wrapper');
    if (!wrapper && dropdown) {
        dropdown.classList.remove('active');
    }
});

// Check if notifications were already viewed on page load
window.addEventListener('DOMContentLoaded', function() {
    const lastView = localStorage.getItem('lastNotificationView');
    const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
    
    if (lastView && parseInt(lastView) > sevenDaysAgo) {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.style.display = 'none';
        }
    }
});

// Memo Modal Functions
function openMemoModal(memoId) {
    console.log('Opening memo:', memoId, memoData);
    const memo = memoData.find(m => m.id == memoId);
    if (!memo) {
        console.log('Memo not found!');
        return;
    }
    
    console.log('Memo found:', memo);
    
    document.getElementById('memoModalTitle').textContent = memo.title;
    document.getElementById('memoModalDate').textContent = formatDate(memo.date);
    document.getElementById('memoModalContent').textContent = memo.content;
    
    const attachmentSection = document.getElementById('memoModalAttachment');
    const attachmentContent = document.getElementById('memoAttachmentContent');
    attachmentContent.innerHTML = '';
    
    console.log('Attachment image:', memo.image, 'file:', memo.file);
    
    let hasAttachment = false;
    
    if (memo.image && memo.image.trim() !== '') {
        hasAttachment = true;
        const img = document.createElement('img');
        img.src = memo.image;
        img.className = 'memo-attachment-image';
        img.style.marginBottom = '12px';
        img.onerror = function() { console.log('Image failed to load:', memo.image); };
        attachmentContent.appendChild(img);
    }
    
    if (memo.file && memo.file.trim() !== '') {
        hasAttachment = true;
        const link = document.createElement('a');
        link.href = memo.file;
        link.className = 'memo-attachment-file';
        link.target = '_blank';
        link.innerHTML = '📎 Download Attachment';
        attachmentContent.appendChild(link);
    }
    
    attachmentSection.style.display = hasAttachment ? 'block' : 'none';
    
    document.getElementById('memoModal').classList.add('active');
    document.getElementById('notificationDropdown').classList.remove('active');
    
    // Mark as read via AJAX
    fetch('mark_memo_read.php?memo_id=' + memoId);
}

function closeMemoModal() {
    document.getElementById('memoModal').classList.remove('active');
}

// Admin Message Modal Functions
function showAdminMessage(messageId) {
    const message = Object.values(adminMessageData).find(m => m.id == messageId);
    if (!message) return;
    
    document.getElementById('adminMessageDate').textContent = formatDate(message.date);
    document.getElementById('adminMessageContent').textContent = message.content;
    
    document.getElementById('adminMessageModal').classList.add('active');
    document.getElementById('notificationDropdown').classList.remove('active');
}

function closeAdminMessageModal() {
    document.getElementById('adminMessageModal').classList.remove('active');
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

let slideIndex = 0; const slides = document.querySelectorAll('.slide'); const dots = document.querySelectorAll('.dot');
function currentSlide(n) { slideIndex = n; updateSlide(); }
function updateSlide() {
    slides.forEach(s => s.classList.remove('active')); dots.forEach(d => d.classList.remove('active'));
    if(slides[slideIndex]) slides[slideIndex].classList.add('active');
    if(dots[slideIndex]) dots[slideIndex].classList.add('active');
}
if(slides.length > 1) { setInterval(() => { slideIndex = (slideIndex + 1) % slides.length; updateSlide(); }, 5000); }

document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault(); const target = document.querySelector(this.getAttribute('href'));
        if(target) target.scrollIntoView({ behavior: 'smooth' });
    });
});
document.getElementById('savedModal')?.addEventListener('click', (e) => { if(e.target.id === 'savedModal') e.target.classList.remove('is-visible'); });

// ===== RECENTLY VIEWED FEATURE =====
const RECENTLY_VIEWED_KEY = 'ekedai_recently_viewed';
const MAX_RECENTLY_VIEWED = 10;
const EXPIRY_DAYS = 7;

function getRecentlyViewed() {
    try {
        const data = JSON.parse(localStorage.getItem(RECENTLY_VIEWED_KEY) || '[]');
        const now = Date.now();
        const expiryMs = EXPIRY_DAYS * 24 * 60 * 60 * 1000;
        // Filter out expired items
        return data.filter(item => (now - item.timestamp) < expiryMs);
    } catch (e) {
        return [];
    }
}

function saveRecentlyViewed(items) {
    try {
        localStorage.setItem(RECENTLY_VIEWED_KEY, JSON.stringify(items.slice(0, MAX_RECENTLY_VIEWED)));
    } catch (e) {}
}

function renderRecentlyViewed() {
    const section = document.getElementById('recentlyViewedSection');
    const scroll = document.getElementById('recentlyViewedScroll');
    if (!section || !scroll) return;
    
    const items = getRecentlyViewed();
    if (items.length === 0) {
        section.style.display = 'none';
        return;
    }
    
    section.style.display = 'block';
    scroll.innerHTML = items.map(item => `
        <a href="item_detail.php?id=${item.id}" class="rv-card">
            <div class="rv-card-image">
                <img src="${item.image}" alt="${item.title}">
            </div>
            <div class="rv-card-content">
                <h3 class="rv-card-title">${item.title}</h3>
                <div class="rv-card-price">${item.price}</div>
            </div>
        </a>
    `).join('');
}

function clearRecentlyViewed() {
    if (confirm('Clear all recently viewed items?')) {
        localStorage.removeItem(RECENTLY_VIEWED_KEY);
        const section = document.getElementById('recentlyViewedSection');
        if (section) section.style.display = 'none';
    }
}

// Initialize Recently Viewed on page load
document.addEventListener('DOMContentLoaded', renderRecentlyViewed);
</script>
</body>
</html>