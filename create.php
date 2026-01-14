<?php
session_start();
if (!isset($_SESSION['UserID'])) { header('Location: index.html'); exit; }

$userid = (int)$_SESSION['UserID'];
$name = $_SESSION['name'] ?? 'User';

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/saved_items_helper.php';
} catch (Exception $e) { die("Database connection failed."); }

$msg = '';
$isBlacklisted = false;
$blacklistUntil = null;


try {
    $stmt = $pdo->prepare("SELECT blacklist_until FROM users WHERE UserID = ?");
    $stmt->execute([$userid]);
    $userCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userCheck && !empty($userCheck['blacklist_until']) && strtotime($userCheck['blacklist_until']) > time()) {
        $isBlacklisted = true;
        $blacklistUntil = $userCheck['blacklist_until'];
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   
    if ($isBlacklisted) {
        $msg = "You are currently restricted from publishing items.";
    } else {
        $type        = $_POST['listing_type']; 
        $title       = trim($_POST['title']);
        $desc        = trim($_POST['description']);
        
        $price       = 0.00; $category = 'Events'; $cond = 'N/A'; $meetup = 'N/A';
        $eventDate   = null; $eventTimeStart = null; $eventTimeEnd = null;

        if ($type === 'item') {
            $price       = abs((float)$_POST['price']); // Ensure positive price
            $category    = $_POST['category'];
            $cond        = $_POST['condition'];
            $meetup      = $_POST['meetup'];
            // If Others selected, use the custom input
            if ($meetup === 'Others' && !empty($_POST['meetup_other'])) {
                $meetup = trim($_POST['meetup_other']);
            }
        } else {
            $category    = 'Events';
            // Optional price for events (0 means free)
            $price       = !empty($_POST['event_price']) ? (float)$_POST['event_price'] : 0.00;
            $eventDate   = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
            $eventDateEnd = !empty($_POST['event_date_end']) ? $_POST['event_date_end'] : null;
            $eventTimeStart = !empty($_POST['event_time_start']) ? $_POST['event_time_start'] : null;
            $eventTimeEnd = !empty($_POST['event_time_end']) ? $_POST['event_time_end'] : null;
        }
        
        // Handle base64 images from new upload system
        $imagePaths = [];
        if (!empty($_POST['images_data'])) {
            $imagesData = json_decode($_POST['images_data'], true);
            if ($imagesData && is_array($imagesData)) {
                $uploadDir = 'uploads/items/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                
                foreach ($imagesData as $key => $base64Image) {
                    // Extract image data from base64 string
                    if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $matches)) {
                        $imageType = $matches[1];
                        $base64Data = substr($base64Image, strpos($base64Image, ',') + 1);
                        $imageData = base64_decode($base64Data);
                        
                        if ($imageData !== false) {
                            $fileName = time() . "_" . $key . "_" . uniqid() . "." . $imageType;
                            $target = $uploadDir . $fileName;
                            
                            if (file_put_contents($target, $imageData)) {
                                $imagePaths[] = $target;
                            }
                        }
                    }
                }
            }
        }
        $imageString = implode(',', $imagePaths);

        // Check if at least one image was uploaded
        if (empty($imagePaths)) {
            $msg = "Please upload at least one image.";
        } else {
            try {
                $sql = "INSERT INTO item (title, price, category, `condition`, meetup_preferences, description, image, UserID, postDate, status, event_date, event_date_end, event_time, event_time_end) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'under_review', ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$title, $price, $category, $cond, $meetup, $desc, $imageString, $userid, $eventDate, $eventDateEnd, $eventTimeStart, $eventTimeEnd]);
                $submitSuccess = true; // Flag for success modal
            } catch (Exception $e) { $msg = "Error: " . $e->getMessage(); }
        }
    }
}

// Header Logic
$avatarSrc = ''; 
$hasAvatar = false;
try {
    $s = $pdo->prepare("SELECT profile_image FROM users WHERE UserID = ?");
    $s->execute([$userid]);
    $u = $s->fetch();
    if (!empty($u['profile_image']) && file_exists($u['profile_image'])) {
        $avatarSrc = $u['profile_image'];
        $hasAvatar = true;
    }
} catch (Exception $e) {}

// Unread
$unread = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE recipientUserID = ? AND is_read = 0");
    $stmt->execute([$userid]);
    $unread = (int)$stmt->fetchColumn();
} catch (Exception $e) { }

// Fetch Saved Items (For Sidebar Modal)
$savedItems = saved_fetch_items($pdo, $userid);

// Handle Unsave
if (isset($_POST['unsave_id'])) {
    saved_remove($pdo, $userid, (int)$_POST['unsave_id']);
    header("Location: create.php"); exit;
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPES - Create Your Own Listing</title>
<link rel="icon" type="image/png" href="letter-w.png">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
/* Flatpickr Indigo Theme Override */
.flatpickr-calendar {
    font-family: 'Inter', sans-serif;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(75, 0, 130, 0.2);
    border: 1px solid #e2e8f0;
}
.flatpickr-month, .flatpickr-weekdays { background: #4B0082; }
.flatpickr-monthDropdown-months, .flatpickr-current-month .numInputWrapper { background: #4B0082; }
.flatpickr-monthDropdown-months { color: white; font-weight: 600; }
.flatpickr-current-month input.cur-year { color: white; font-weight: 700; }
.flatpickr-months .flatpickr-prev-month, .flatpickr-months .flatpickr-next-month { fill: white; }
.flatpickr-months .flatpickr-prev-month:hover, .flatpickr-months .flatpickr-next-month:hover { fill: #e6d9ff; }
span.flatpickr-weekday { color: white; font-weight: 600; font-size: 11px; }
.flatpickr-day { border-radius: 8px; font-weight: 500; }
.flatpickr-day:hover { background: #e6d9ff; border-color: #e6d9ff; }
.flatpickr-day.selected { background: #4B0082; border-color: #4B0082; color: white; }
.flatpickr-day.today { border-color: #4B0082; }
.flatpickr-day.today:hover { background: #4B0082; color: white; }
.flatpickr-time input { font-weight: 600; }
.flatpickr-time .numInputWrapper span.arrowUp:after { border-bottom-color: #4B0082; }
.flatpickr-time .numInputWrapper span.arrowDown:after { border-top-color: #4B0082; }
</style>
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
    overflow-x: hidden;
}

/* --- HEADER --- */
.header {
    display: flex; align-items: center; padding: 16px 30px;
    background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 50;
    padding-right: 90px; /* Space for sidebar strip */
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
    border: 2.5px solid var(--accent-light); transition: all 0.3s;
}
.avatar:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }

/* Letter Avatar */
.letter-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 600; color: var(--muted);
    background: var(--panel); border: 2.5px solid var(--accent-light);
    transition: all 0.3s; text-transform: uppercase;
}
.letter-avatar:hover { border-color: var(--accent); transform: scale(1.05); box-shadow: 0 0 15px rgba(139, 92, 246, 0.4); }

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
    padding: 40px 20px;
    padding-right: 90px; /* Make space for black strip */
    width: 100%;
    min-height: calc(100vh - 80px);
}

/* --- CONTENT AREA --- */
.content-area { 
    padding: 0; /* Removed padding/border as sidebar is now fixed overlay */
    border: none;
    display: flex;
    flex-direction: column;
}

h1 { font-size: 32px; font-weight: 900; color: var(--text); margin: 0 0 24px; letter-spacing: -0.5px; }

/* --- FORM STYLES --- */
.form-card {
    background: var(--panel); border: 1px solid var(--border); border-radius: 16px;
    padding: 32px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    width: 100%; 
    box-sizing: border-box;
}

.type-switch { display: flex; gap: 12px; margin-bottom: 28px; }
.type-btn {
    flex: 1; padding: 16px 18px; background: #f8f9fa; border: 2px solid var(--border);
    color: var(--muted); font-weight: 700; cursor: pointer; border-radius: 12px;
    text-align: center; transition: all 0.3s ease; font-size: 14px; text-transform: uppercase;
}
.type-btn:hover { border-color: var(--accent); color: var(--accent); }
.type-btn.active { background: var(--accent); color: white; border-color: var(--accent); box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }

.form-group { margin-bottom: 24px; }
.form-group label { display: block; margin-bottom: 10px; font-weight: 700; color: var(--text); font-size: 14px; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 14px 16px; background: #f9fafb; border: 1px solid var(--border);
    border-radius: 10px; color: var(--text); font-size: 15px; font-family: inherit; transition: 0.3s;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); background: white;
}

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.hidden { display: none; }

/* Select placeholder styling */
#meetupSelect:invalid, #categorySelect:invalid, #conditionSelect:invalid { color: #9ca3af; }
#meetupSelect option, #categorySelect option, #conditionSelect option { color: var(--text); }
#meetupSelect option[value=""], #categorySelect option[value=""], #conditionSelect option[value=""] { color: #9ca3af; }

/* Event dates/times grid - always 2 columns */
.event-dates-grid, .event-times-grid { 
    display: grid; 
    grid-template-columns: 1fr 1fr; 
    gap: 12px; 
}
.event-dates-grid .form-group, .event-times-grid .form-group { margin-bottom: 16px; }
.event-dates-grid .form-group label, .event-times-grid .form-group label { font-size: 12px; margin-bottom: 6px; }
.event-dates-grid input, .event-times-grid input { padding: 10px 12px; font-size: 13px; }

.file-input-wrapper {
    position: relative; overflow: hidden; display: block;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(139, 92, 246, 0.02)); 
    border: 2px dashed var(--accent);
    border-radius: 12px; padding: 40px 20px; width: 100%; text-align: center;
    cursor: pointer; color: var(--accent); transition: 0.3s; font-weight: 700;
}
.file-input-wrapper:hover { background: var(--accent-light); transform: scale(1.01); }
.file-input-wrapper input[type=file] { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }

/* Large Image Preview Grid */
.image-preview-container { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); 
    gap: 16px; 
    margin-top: 20px; 
}
.preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid var(--border);
    background: #f9fafb;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
}
.preview-item:hover {
    border-color: var(--accent);
    box-shadow: 0 8px 24px rgba(75, 0, 130, 0.15);
    transform: translateY(-2px);
}
.preview-item:first-child {
    grid-column: span 2;
    grid-row: span 2;
}
.preview-thumb { 
    width: 100%; 
    height: 100%; 
    object-fit: cover; 
}
.preview-remove {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 32px;
    height: 32px;
    background: rgba(239, 68, 68, 0.9);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 18px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.preview-remove:hover {
    background: #dc2626;
    transform: scale(1.1);
}
.preview-badge {
    position: absolute;
    bottom: 10px;
    left: 10px;
    background: var(--accent);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.upload-section {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
}
.upload-section h3 {
    margin: 0 0 16px;
    font-size: 18px;
    color: var(--text);
}
.image-count {
    display: inline-block;
    background: var(--accent-light);
    color: var(--accent);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    margin-left: 10px;
}

.btn-submit {
    width: 100%; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: white; 
    padding: 16px; border: none; border-radius: 12px; font-weight: 800; font-size: 15px; cursor: pointer;
    transition: 0.3s; text-transform: uppercase; box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
}
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4); }

/* Saved Items Modal */
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
.error-msg{background:var(--danger);color:white;padding:10px;border-radius:8px;margin-bottom:20px;font-weight:600;}

/* Mobile sidebar - GPU accelerated */
@media (max-width: 768px) {
    .right-sidebar { width: 300px; transform: translateX(100%); transition: transform 0.3s ease-out; }
    .right-sidebar.open { transform: translateX(0); }
    .sidebar-toggle-btn { display: none; }
    .mobile-menu-btn { display: flex !important; }
    .header { padding-right: 20px; }
    .main-container { padding-right: 20px; }
}

@media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }

/* Mobile optimization for 480px */
@media (max-width: 480px) {
    /* Header */
    .header { padding: 12px 16px; padding-right: 20px; }
    .header .brand { font-size: 16px; }
    .avatar { width: 32px; height: 32px; }
    .letter-avatar { width: 32px; height: 32px; font-size: 14px; }
    
    /* Main Container */
    .main-container { padding: 16px 12px; padding-right: 20px; }
    h1 { font-size: 22px; margin-bottom: 16px; }
    
    /* Form Card */
    .form-card { padding: 18px; border-radius: 12px; }
    
    /* Type Switch */
    .type-switch { gap: 8px; margin-bottom: 20px; }
    .type-btn { padding: 12px 10px; font-size: 12px; border-radius: 10px; }
    
    /* Form Groups */
    .form-group { margin-bottom: 16px; }
    .form-group label { font-size: 13px; margin-bottom: 8px; }
    .form-group input, .form-group select, .form-group textarea { padding: 12px; font-size: 14px; border-radius: 8px; }
    
    /* Upload Section */
    .upload-section { padding: 16px; margin-bottom: 16px; border-radius: 12px; }
    .upload-section h3 { font-size: 15px; margin-bottom: 12px; }
    .file-input-wrapper { padding: 28px 16px; border-radius: 10px; font-size: 13px; }
    .file-input-wrapper svg { width: 36px; height: 36px; }
    .image-count { font-size: 11px; padding: 3px 10px; }
    
    /* Image Preview Grid */
    .image-preview-container { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 14px; }
    .preview-item:first-child { grid-column: span 1; grid-row: span 1; }
    .preview-remove { width: 26px; height: 26px; font-size: 14px; top: 6px; right: 6px; }
    .preview-badge { font-size: 10px; padding: 3px 8px; bottom: 6px; left: 6px; }
    
    /* Submit Button */
    .btn-submit { padding: 14px; font-size: 14px; border-radius: 10px; }
    
    /* Info Notices */
    .main-container > .content-area > div[style*="background: rgba"] { padding: 14px; font-size: 13px; border-radius: 8px; margin-bottom: 16px; }
}
</style>
</head>
<body>

<header class="header">
  <a href="home.php" class="brand">Campus Preloved E-Shop</a>
  <div class="controls">
    <a href="profile.php">
      <?php if ($hasAvatar): ?>
        <img src="<?= h($avatarSrc) ?>" class="avatar">
      <?php else: ?>
        <div class="letter-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
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
            <li>
                <a href="home.php" class="sidebar-link">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                    Home
                </a>
            </li>
            <li>
                <a href="profile.php" class="sidebar-link">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    Profile
                </a>
            </li>
            <li>
                <a href="messages.php" class="sidebar-link">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                    Messages
                    <?php if($unread>0): ?><span class="menu-badge"><?= $unread ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a href="create.php" class="sidebar-link active">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    Create List/Event
                </a>
            </li>
            <li>
                <a href="#" class="sidebar-link" onclick="event.preventDefault(); openSavedModal(); toggleSidebar();">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>
                    Bookmarks
                </a>
            </li>
            <li>
                <a href="help.php" class="sidebar-link">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>
                    Help
                </a>
            </li>
            <li>
                <a href="reports.php" class="sidebar-link">
                    <svg class="sidebar-icon" viewBox="0 0 24 24"><path d="M15.73 3H8.27L3 8.27v7.46L8.27 21h7.46L21 15.73V8.27L15.73 3zM12 17c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm1-4h-2V7h2v6z"/></svg>
                    Reports
                </a>
            </li>
            <li>
                <a href="logout.php" class="sidebar-link" style="color: #ef4444;">
                    <svg class="sidebar-icon" viewBox="0 0 24 24" style="fill: #ef4444;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    Log Out
                </a>
            </li>
        </ul>
    </div>
</aside>

<div class="main-container">
  <main class="content-area">
      <h1>Create New</h1>
      
      <?php if($isBlacklisted): ?>
      <div style="background:rgba(239, 68, 68, 0.1); border:1px solid var(--danger); color:#dc2626; padding:20px; border-radius:10px; margin-bottom:24px; font-weight:500; font-size:14px;">
          <strong>🚫 Publishing Restricted</strong><br><br>
          Your account has been temporarily restricted from publishing new items.
          <?php 
          if ($blacklistUntil === '9999-12-31 23:59:59') {
              echo '<br>Duration: <strong>Permanent</strong>';
          } else {
              $date = new DateTime($blacklistUntil);
              echo '<br>Restriction ends: <strong>' . $date->format('F j, Y \a\t g:i A') . '</strong>';
          }
          ?>
          <br><br>If you believe this is a mistake, please contact the administrator.
      </div>
      <?php else: ?>
      <div style="background:rgba(16, 185, 129, 0.1); border:1px solid var(--success); color:#047857; padding:16px; border-radius:10px; margin-bottom:24px; font-weight:500; font-size:14px;">
          <strong>Note:</strong> All new listings are subject to admin review before appearing on the feed.
      </div>
      <?php endif; ?>

      <?php if($msg): ?><div class="error-msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <form class="form-card" method="POST" enctype="multipart/form-data">
          <div class="type-switch">
              <div class="type-btn active" id="btn-item" onclick="setType('item')">Create List</div>
              <div class="type-btn" id="btn-event" onclick="setType('event')">Promote Event</div>
          </div>
          <input type="hidden" name="listing_type" id="listing_type" value="item">

          <!-- IMAGE UPLOAD SECTION AT TOP -->
          <div class="upload-section">
              <h3>📷 Photos <span class="image-count" id="imageCount">0 selected</span></h3>
              <p style="color: var(--muted); font-size: 13px; margin: 0 0 16px;">First image will be the main cover photo. Click X to remove any image.</p>
              <div class="file-input-wrapper" id="dropZone">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 10px;">
                      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                      <circle cx="8.5" cy="8.5" r="1.5"></circle>
                      <polyline points="21 15 16 10 5 21"></polyline>
                  </svg>
                  <span>+ Click or drag images here to add</span>
                  <input type="file" id="imageInput" multiple accept="image/*">
              </div>
              <div class="image-preview-container" id="previewContainer"></div>
              <!-- Hidden input to store image data -->
              <input type="hidden" name="images_data" id="imagesData">
          </div>

          <div class="form-group">
              <label id="label-title">Item Name</label>
              <input type="text" name="title" required placeholder="Title...">
          </div>

          <div id="item-fields">
              <div class="grid-2">
                  <div class="form-group"><label>Price (RM)</label><input type="number" name="price" step="0.01" min="0" placeholder="0.00" id="itemPrice"></div>
                  <div class="form-group">
                      <label>Category</label>
                      <select name="category" id="categorySelect">
                          <option value="" disabled selected>Select your category</option>
                          <option value="Academics & Study Materials">Academics & Study Materials</option>
                          <option value="Housing & Dorm Living">Housing & Dorm Living</option>
                          <option value="Electronics & Tech">Electronics & Tech</option>
                          <option value="Clothing & Accessories">Clothing & Accessories</option>
                          <option value="Transportation & Travel">Transportation & Travel</option>
                          <option value="Peer-to-Peer Services">Peer-to-Peer Services</option>
                          <option value="Garage Sale">Garage Sale</option>
                          <option value="Others">Others</option>
                      </select>
                  </div>
              </div>
              <div class="grid-2">
                  <div class="form-group"><label>Condition</label><select name="condition" id="conditionSelect"><option value="" disabled selected>Select condition</option><option value="New">New</option><option value="Like New">Like New</option><option value="Good">Good</option><option value="Fair">Fair</option></select></div>
                  <div class="form-group">
                      <label>Meetup Preferences</label>
                      <select name="meetup" id="meetupSelect" onchange="toggleMeetupOther()">
                          <option value="" disabled selected style="color:#999;">Select your preference</option>
                          <option value="Campus Meetup">Campus Meetup</option>
                          <option value="Meetup">Meetup</option>
                          <option value="Delivery">Delivery</option>
                          <option value="Pickup Only">Pickup Only</option>
                          <option value="Others">Others (Please Specify)</option>
                      </select>
                      <input type="text" name="meetup_other" id="meetupOther" placeholder="Please specify..." style="display:none; margin-top:10px;">
                  </div>
              </div>
          </div>

          <div id="event-fields" class="hidden">
              <div class="grid-2 event-dates-grid">
                  <div class="form-group">
                      <label>Start Date <span style="color: var(--muted); font-weight: 400;">— optional</span></label>
                      <input type="text" name="event_date" id="eventDateStart" placeholder="Select date...">
                  </div>
                  <div class="form-group">
                      <label>End Date <span style="color: var(--muted); font-weight: 400;">— optional</span></label>
                      <input type="text" name="event_date_end" id="eventDateEnd" placeholder="Select date...">
                  </div>
              </div>
              <div class="grid-2 event-times-grid">
                  <div class="form-group">
                      <label>Start Time <span style="color: var(--muted); font-weight: 400;">— optional</span></label>
                      <input type="text" name="event_time_start" id="eventTimeStart" placeholder="e.g. 8:00 AM">
                  </div>
                  <div class="form-group">
                      <label>End Time <span style="color: var(--muted); font-weight: 400;">— optional</span></label>
                      <input type="text" name="event_time_end" id="eventTimeEnd" placeholder="e.g. 5:00 PM">
                  </div>
              </div>
              <div class="form-group">
                  <label>Fee Price (RM) <span style="color: var(--muted); font-weight: 400;">— optional, leave empty for free events</span></label>
                  <input type="number" name="event_price" step="0.01" min="0" placeholder="0.00 (Free)">
              </div>
          </div>

          <div class="form-group"><label>Description</label><textarea name="description" rows="5" required placeholder="Details..."></textarea></div>

          <button type="submit" class="btn-submit" <?php if($isBlacklisted): ?>disabled style="opacity: 0.5; cursor: not-allowed;"<?php endif; ?>>Submit for Review</button>
      </form>
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

<!-- Success Modal -->
<div class="modal-overlay-modal <?php if(!empty($submitSuccess)): ?>is-visible<?php endif; ?>" id="successModal">
    <div class="modal-content success-modal-content">
        <div class="success-icon">
            <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
                <circle cx="40" cy="40" r="38" stroke="#10b981" stroke-width="4" fill="rgba(16, 185, 129, 0.1)"/>
                <path d="M24 42L34 52L56 30" stroke="#10b981" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" class="checkmark-path"/>
            </svg>
        </div>
        <h2 style="color: var(--text); margin: 20px 0 10px; font-size: 24px;">Submission Successful!</h2>
        <p style="color: var(--muted); margin: 0 0 24px; font-size: 14px; line-height: 1.6;">
            Your listing has been submitted for review.<br>
            Admin will review it shortly.
        </p>
        <div style="display: flex; gap: 12px; flex-direction: column; width: 100%;">
            <a href="profile.php" class="btn-view-listing">View My Listings</a>
            <button onclick="closeSuccessModal()" class="btn-create-another">Create Another</button>
        </div>
    </div>
</div>

<style>
/* Success Modal Styles */
.success-modal-content {
    text-align: center;
    padding: 40px 32px !important;
    max-width: 380px !important;
}
.success-icon {
    margin-bottom: 10px;
}
.checkmark-path {
    stroke-dasharray: 50;
    stroke-dashoffset: 50;
    animation: drawCheck 0.6s ease forwards 0.3s;
}
@keyframes drawCheck {
    to { stroke-dashoffset: 0; }
}
.btn-view-listing {
    display: block;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: white;
    padding: 14px 24px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
    text-align: center;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(75, 0, 130, 0.3);
}
.btn-view-listing:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(75, 0, 130, 0.4);
}
.btn-create-another {
    background: transparent;
    border: 2px solid var(--border);
    color: var(--muted);
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-create-another:hover {
    border-color: var(--accent);
    color: var(--accent);
}
</style>

<script>
function openSavedModal(){ document.getElementById('savedModal').classList.add('is-visible'); }
function closeSuccessModal(){ 
    document.getElementById('successModal').classList.remove('is-visible'); 
    // Refresh the page to reset form
    window.location.href = 'create.php';
}
// Updated Sidebar Toggle
function toggleSidebar() {
    const s = document.getElementById('sidebar'); 
    const o = document.querySelector('.sidebar-overlay');
    s.classList.toggle('open'); 
    o.classList.toggle('active');
}
document.getElementById('savedModal').addEventListener('click', (e)=>{if(e.target.id==='savedModal')document.getElementById('savedModal').classList.remove('is-visible');});

function setType(type) {
    document.getElementById('listing_type').value = type;
    
    // Get item-specific fields
    const categorySelect = document.getElementById('categorySelect');
    const conditionSelect = document.getElementById('conditionSelect');
    const meetupSelect = document.getElementById('meetupSelect');
    const itemPrice = document.getElementById('itemPrice');
    
    if(type === 'item') {
        document.getElementById('btn-item').classList.add('active');
        document.getElementById('btn-event').classList.remove('active');
        document.getElementById('item-fields').classList.remove('hidden');
        document.getElementById('event-fields').classList.add('hidden');
        document.getElementById('label-title').innerText = "Item Name";
        
        // Enable required for item fields
        categorySelect.setAttribute('required', 'required');
        conditionSelect.setAttribute('required', 'required');
        meetupSelect.setAttribute('required', 'required');
        itemPrice.setAttribute('required', 'required');
    } else {
        document.getElementById('btn-event').classList.add('active');
        document.getElementById('btn-item').classList.remove('active');
        document.getElementById('item-fields').classList.add('hidden');
        document.getElementById('event-fields').classList.remove('hidden');
        document.getElementById('label-title').innerText = "Event Name";
        
        // Remove required from item fields for events
        categorySelect.removeAttribute('required');
        conditionSelect.removeAttribute('required');
        meetupSelect.removeAttribute('required');
        itemPrice.removeAttribute('required');
    }
}

// Image Upload Manager
let uploadedImages = []; // Store all images as base64

function updateImageCount() {
    const countEl = document.getElementById('imageCount');
    countEl.textContent = uploadedImages.length + ' selected';
}

function renderPreviews() {
    const container = document.getElementById('previewContainer');
    container.innerHTML = '';
    
    uploadedImages.forEach((imgData, index) => {
        const previewItem = document.createElement('div');
        previewItem.className = 'preview-item';
        
        const img = document.createElement('img');
        img.src = imgData.base64;
        img.className = 'preview-thumb';
        img.alt = 'Preview ' + (index + 1);
        
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'preview-remove';
        removeBtn.innerHTML = '×';
        removeBtn.onclick = function() { removeImage(index); };
        
        previewItem.appendChild(img);
        previewItem.appendChild(removeBtn);
        
        // Add badge to first image
        if (index === 0) {
            const badge = document.createElement('span');
            badge.className = 'preview-badge';
            badge.textContent = 'Main Photo';
            previewItem.appendChild(badge);
        }
        
        container.appendChild(previewItem);
    });
    
    updateImageCount();
    updateHiddenInput();
}

function removeImage(index) {
    uploadedImages.splice(index, 1);
    renderPreviews();
}

function updateHiddenInput() {
    // Store image data for form submission
    document.getElementById('imagesData').value = JSON.stringify(uploadedImages.map(img => img.base64));
}

function addImages(files) {
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        if (file.type.match('image.*')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                uploadedImages.push({
                    name: file.name,
                    base64: e.target.result
                });
                renderPreviews();
            };
            reader.readAsDataURL(file);
        }
    }
}

// File input change handler - ADD to existing, not replace
document.getElementById('imageInput').addEventListener('change', function(event) {
    addImages(event.target.files);
    // Reset input so same file can be selected again
    this.value = '';
});

// Drag and drop support
const dropZone = document.getElementById('dropZone');

dropZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.style.borderColor = 'var(--accent)';
    this.style.background = 'var(--accent-light)';
});

dropZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.style.borderColor = '';
    this.style.background = '';
});

dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.style.borderColor = '';
    this.style.background = '';
    addImages(e.dataTransfer.files);
});

// Toggle meetup other input
function toggleMeetupOther() {
    const select = document.getElementById('meetupSelect');
    const otherInput = document.getElementById('meetupOther');
    if (select.value === 'Others') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}
</script>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Initialize Flatpickr for date inputs
flatpickr("#eventDateStart", {
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "d M Y",
    allowInput: true,
    clickOpens: true
});

flatpickr("#eventDateEnd", {
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "d M Y",
    allowInput: true,
    clickOpens: true
});

// Initialize Flatpickr for time inputs
flatpickr("#eventTimeStart", {
    enableTime: true,
    noCalendar: true,
    dateFormat: "H:i",
    altInput: true,
    altFormat: "h:i K",
    time_24hr: false,
    allowInput: true
});

flatpickr("#eventTimeEnd", {
    enableTime: true,
    noCalendar: true,
    dateFormat: "H:i",
    altInput: true,
    altFormat: "h:i K",
    time_24hr: false,
    allowInput: true
});

// Allow clearing by selecting text and deleting
document.querySelectorAll('.flatpickr-input').forEach(input => {
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' || e.key === 'Delete') {
            this._flatpickr.clear();
        }
    });
});
</script>
</body>
</html>