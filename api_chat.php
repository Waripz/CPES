<?php
session_start();
// Database Connection
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { echo json_encode(['error' => 'DB Connection failed']); exit; }

$myID = $_SESSION['UserID'] ?? 0;
if(!$myID) { echo json_encode(['error'=>'Auth']); exit; }

$action = $_POST['action'] ?? '';
date_default_timezone_set('Asia/Kuala_Lumpur');

// Detect or add message.context_info column (no new tables)
$hasContext = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM message LIKE 'context_info'")->fetch();
    if ($col) { $hasContext = true; }
    else {
        // Try to add the column gracefully
        $pdo->exec("ALTER TABLE message ADD COLUMN context_info VARCHAR(255) NULL");
        $hasContext = true;
    }
} catch (Exception $e) { /* ignore, run without context feature */ }

// --- 1. SEND MESSAGE ---
if ($action === 'send') {
    $to = (int)$_POST['to_user'];
    $msg = trim($_POST['message'] ?? '');
    $imgPaths = [];
    $ctxVal = null;
    if (!empty($_POST['context_item'])) {
        $ctxVal = (string)((int)$_POST['context_item']);
    } elseif (!empty($_POST['context_info'])) {
        $ctxVal = trim($_POST['context_info']);
    }

    // Handle Multiple Image Uploads
    if (!empty($_FILES['chat_images']['name'][0])) {
        $uploadDir = 'uploads/chat/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        
        $validExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxImages = 5;
        $imageCount = min(count($_FILES['chat_images']['name']), $maxImages);
        
        for ($i = 0; $i < $imageCount; $i++) {
            if (!empty($_FILES['chat_images']['name'][$i]) && $_FILES['chat_images']['error'][$i] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['chat_images']['name'][$i], PATHINFO_EXTENSION));
                
                if (in_array($ext, $validExt)) {
                    $filename = uniqid() . '_' . $i . '.' . $ext;
                    if (move_uploaded_file($_FILES['chat_images']['tmp_name'][$i], $uploadDir . $filename)) {
                        $imgPaths[] = $uploadDir . $filename;
                    }
                }
            }
        }
    }
    
    // Combine image paths as comma-separated string
    $imgPath = !empty($imgPaths) ? implode(',', $imgPaths) : null;

    if ($msg || $imgPath) {
        $ts = date('Y-m-d H:i:s');
        if ($hasContext) {
            $sql = "INSERT INTO message (senderID, recipientUserID, message, attachment_image, timestamp, is_read, context_info) VALUES (?, ?, ?, ?, ?, 0, ?)";
            $pdo->prepare($sql)->execute([$myID, $to, $msg, $imgPath, $ts, $ctxVal]);
        } else {
            $sql = "INSERT INTO message (senderID, recipientUserID, message, attachment_image, timestamp, is_read) VALUES (?, ?, ?, ?, ?, 0)";
            $pdo->prepare($sql)->execute([$myID, $to, $msg, $imgPath, $ts]);
        }
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'empty']);
    }
    exit;
}

// --- 2. FETCH MESSAGES (POLLING) ---
if ($action === 'fetch') {
    $partnerID = (int)$_POST['partner_id'];
    $lastID = (int)$_POST['last_id'];
    $ctxFilter = null; $ctxTitle = null;
    if ($hasContext && !empty($_POST['context_item'])) {
        $ctxFilter = (string)((int)$_POST['context_item']);
        try {
            $t = $pdo->prepare("SELECT title FROM item WHERE ItemID=?");
            $t->execute([(int)$ctxFilter]);
            $row = $t->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['title'])) $ctxTitle = $row['title'];
        } catch (Exception $e) {}
    }
    
    // Fetch messages newer than the last one we have
    if ($hasContext && $ctxFilter) {
        $sql = "SELECT * FROM message 
                WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) 
                AND MessageID > ? AND context_info IS NOT NULL AND context_info != ''
                AND (context_info = ?" . ($ctxTitle ? " OR context_info = ?" : "") . ")
                ORDER BY timestamp ASC";
        $stmt = $pdo->prepare($sql);
        $params = [$myID, $partnerID, $partnerID, $myID, $lastID, $ctxFilter];
        if ($ctxTitle) $params[] = $ctxTitle;
        $stmt->execute($params);
    } else {
        if ($hasContext) {
            // General chat only (no item context)
            $sql = "SELECT * FROM message 
                    WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) 
                    AND MessageID > ? AND (context_info IS NULL OR context_info = '')
                    ORDER BY timestamp ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$myID, $partnerID, $partnerID, $myID, $lastID]);
        } else {
            $sql = "SELECT * FROM message 
                    WHERE ((senderID = ? AND recipientUserID = ?) OR (senderID = ? AND recipientUserID = ?)) 
                    AND MessageID > ? 
                    ORDER BY timestamp ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$myID, $partnerID, $partnerID, $myID, $lastID]);
        }
    }
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark messages from partner as read, scoped to current context
    if(!empty($msgs)) {
        if ($hasContext && $ctxFilter) {
            $mrSql = "UPDATE message SET is_read=1 WHERE senderID=? AND recipientUserID=? AND context_info IS NOT NULL AND context_info != '' AND (context_info = ?" . ($ctxTitle ? " OR context_info = ?" : "") . ")";
            $mr = $pdo->prepare($mrSql);
            $mrParams = [$partnerID, $myID, $ctxFilter];
            if ($ctxTitle) $mrParams[] = $ctxTitle;
            $mr->execute($mrParams);
        } elseif ($hasContext) {
            $mr = $pdo->prepare("UPDATE message SET is_read=1 WHERE senderID=? AND recipientUserID=? AND (context_info IS NULL OR context_info = '')");
            $mr->execute([$partnerID, $myID]);
        } else {
            $pdo->prepare("UPDATE message SET is_read=1 WHERE senderID=? AND recipientUserID=?")->execute([$partnerID, $myID]);
        }
    }
    
    echo json_encode(['messages' => $msgs, 'myID' => $myID]);
    exit;
}
?>