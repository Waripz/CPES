<?php
require_once 'config.php';
adminSecureSessionStart();

if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') { 
    redirect('admin_login.php'); 
}

// CSRF Protection - validate on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isAjax()) {
    requireCSRF();
}

$pdo = getDBConnection();

// For sidebar badges
$pendingItemsCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
$unopenedReportsCount = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();

// Handle Live Search Request (AJAX)
if (isset($_GET['search_user'])) {
    $term = "%" . $_GET['search_user'] . "%";
    $stmt = $pdo->prepare("SELECT UserID, name, matricNo FROM users WHERE (name LIKE ? OR matricNo LIKE ?) AND matricNo != 'ADMIN' LIMIT 10");
    $stmt->execute([$term, $term]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Handle Get All Users (AJAX)
if (isset($_GET['get_all_users'])) {
    $stmt = $pdo->prepare("SELECT UserID, name, matricNo FROM users WHERE matricNo != 'ADMIN' ORDER BY name ASC");
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle individual message sending
    if (isset($_POST['send_individual_message'])) {
        $recipientID = (int)$_POST['recipient_user_id'];
        $messageText = trim($_POST['message_text'] ?? '');
        
        if ($recipientID > 0 && !empty($messageText)) {
            $check = $pdo->prepare("SELECT UserID, name FROM users WHERE UserID = ? AND matricNo != 'ADMIN'");
            $check->execute([$recipientID]);
            $recipient = $check->fetch();
            
            if ($recipient) {
                $stmt = $pdo->prepare("INSERT INTO message (senderID, recipientUserID, message, is_admin_message, timestamp) VALUES (?, ?, ?, 1, NOW())");
                $stmt->execute([$_SESSION['AdminID'], $recipientID, $messageText]);
                
                $_SESSION['flash_msg'] = 'Message sent to ' . htmlspecialchars($recipient['name']) . ' successfully!';
                $_SESSION['flash_type'] = 'success';
                header('Location: admin_memo.php');
                exit;
            } else {
                $msg = 'Invalid recipient selected.';
                $msgType = 'error';
            }
        } else {
            $msg = 'Please select a recipient and enter a message.';
            $msgType = 'error';
        }
    }
    // Handle broadcast memo
    else {
        $subject = trim($_POST['subject'] ?? '');
        $content = trim($_POST['content'] ?? '');
    
        $attachmentFile = null;
        $attachmentImage = null;
        
        $uploadDir = 'uploads/memos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        if (!empty($_FILES['attachment_image']['name'])) {
            $imageName = time() . '_' . basename($_FILES['attachment_image']['name']);
            $imagePath = $uploadDir . $imageName;
            if (move_uploaded_file($_FILES['attachment_image']['tmp_name'], $imagePath)) {
                $attachmentImage = $imagePath;
            }
        }
        
        if (!empty($_FILES['attachment_file']['name'])) {
            $fileName = time() . '_' . basename($_FILES['attachment_file']['name']);
            $filePath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $filePath)) {
                $attachmentFile = $filePath;
            }
        }
        
        if (!empty($subject) && !empty($content)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO memo (subject, content, attachment_file, attachment_image, audience) VALUES (?, ?, ?, ?, 'all')");
                $stmt->execute([$subject, $content, $attachmentFile, $attachmentImage]);
                $_SESSION['flash_msg'] = "Announcement broadcast to all users successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: admin_memo.php');
                exit;
            } catch (PDOException $e) {
                $msg = "Error sending announcement: " . $e->getMessage();
                $msgType = 'error';
            }
        } else if (!empty($subject) || !empty($content)) {
            $msg = "Please fill in both subject and content.";
            $msgType = 'error';
        }
    }
}

// Display flash messages
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'];
    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
}

// Fetch recent memos
$recentMemos = [];
try {
    $stmt = $pdo->query("SELECT * FROM memo ORDER BY created_at DESC LIMIT 10");
    $recentMemos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch recent messages
$recentMessages = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.MessageID, m.message, m.timestamp, u.name as recipient_name, u.matricNo
        FROM message m
        JOIN users u ON m.recipientUserID = u.UserID
        WHERE m.senderID = ? AND m.is_admin_message = 1
        ORDER BY m.timestamp DESC LIMIT 10
    ");
    $stmt->execute([$_SESSION['AdminID']]);
    $recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// h() function is now in config.php, no need to redefine
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memos & Messages - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
        }
        
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            resize: vertical;
            min-height: 150px;
            background: var(--card-bg);
            color: var(--text-primary);
        }
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        
        /* File Upload */
        .file-upload-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .file-upload {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            position: relative;
        }
        .file-upload:hover {
            border-color: var(--accent-blue);
            background: var(--blue-subtle);
        }
        .file-upload input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .file-upload-icon {
            width: 40px;
            height: 40px;
            margin: 0 auto 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-page);
            border-radius: var(--radius-md);
            color: var(--text-muted);
        }
        .file-upload-text {
            font-size: 12px;
            color: var(--text-muted);
        }
        .file-name {
            font-size: 11px;
            color: var(--accent-blue);
            margin-top: 8px;
            word-break: break-all;
        }
        
        /* User Search */
        .user-search-box { position: relative; }
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 var(--radius-md) var(--radius-md);
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: var(--shadow-lg);
        }
        .search-results.active { display: block; }
        .search-result-item {
            padding: 10px 14px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-subtle);
            transition: background var(--transition-fast);
        }
        .search-result-item:hover { background: var(--bg-page); }
        .search-result-item:last-child { border-bottom: none; }
        
        /* Selected Users */
        .selected-users {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            min-height: 40px;
            padding: 10px;
            background: var(--bg-page);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        .selected-user-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--accent-blue);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .remove-user {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            opacity: 0.8;
        }
        .remove-user:hover { opacity: 1; }
        
        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success {
            background: var(--green-subtle);
            color: var(--accent-green);
            border: 1px solid rgba(5, 150, 105, 0.2);
        }
        .alert.error {
            background: var(--red-subtle);
            color: var(--accent-red);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        /* Memo List */
        .memo-list { max-height: 350px; overflow-y: auto; }
        .memo-item {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            transition: background var(--transition-fast);
        }
        .memo-item:hover { background: var(--bg-page); }
        .memo-item:last-child { border-bottom: none; }
        .memo-subject {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .memo-preview {
            font-size: 13px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 6px;
        }
        .memo-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        /* Message List */
        .message-list { max-height: 350px; overflow-y: auto; }
        .message-item {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-color);
        }
        .message-item:hover { background: var(--bg-page); }
        .message-item:last-child { border-bottom: none; }
        .message-recipient {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .message-text {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 4px;
            line-height: 1.4;
        }
        .message-date {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        /* Selected Message User */
        .selected-msg-user {
            display: none;
            margin-top: 8px;
            padding: 8px 12px;
            background: var(--blue-subtle);
            border-radius: var(--radius-sm);
            font-size: 13px;
            justify-content: space-between;
            align-items: center;
        }
        .selected-msg-user.active { display: flex; }
        
        @media (max-width: 1100px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
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
                    <a href="admin_users.php" class="nav-link">
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
                    <a href="admin_memo.php" class="nav-link active">
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
            <header class="page-header">
                <div class="header-content">
                    <h1>Memos & Messages</h1>
                    <p class="header-subtitle">Send announcements and communicate with users</p>
                </div>
            </header>

            <div class="content-grid">
                <!-- Left: Compose Form -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Compose New Memo</div>
                    </div>
                    <div class="card-body">
                        <?php if($msg): ?>
                            <div class="alert <?= $msgType ?>"><?= h($msg) ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" autocomplete="off">
                            <?= getCSRFTokenField() ?>
                            <input type="hidden" name="type" value="all">

                            <div class="form-group">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" class="form-input" placeholder="Enter memo subject..." required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Message Content</label>
                                <textarea name="content" class="form-textarea" placeholder="Write your announcement here..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Attachments (Optional)</label>
                                <div class="file-upload-group">
                                    <div class="file-upload">
                                        <div class="file-upload-icon">
                                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                        </div>
                                        <div class="file-upload-text">Upload Image</div>
                                        <div class="file-name" id="imageName"></div>
                                        <input type="file" name="attachment_image" accept="image/*" onchange="showFileName(this, 'imageName')">
                                    </div>
                                    <div class="file-upload">
                                        <div class="file-upload-icon">
                                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                        </div>
                                        <div class="file-upload-text">Upload File</div>
                                        <div class="file-name" id="fileName"></div>
                                        <input type="file" name="attachment_file" onchange="showFileName(this, 'fileName')">
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%;">Broadcast to All Users</button>
                        </form>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Recent Memos -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Recent Memos</div>
                        </div>
                        <?php if (empty($recentMemos)): ?>
                            <div class="empty-state">
                                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                <p>No memos sent yet</p>
                            </div>
                        <?php else: ?>
                            <div class="memo-list">
                                <?php foreach ($recentMemos as $memo): ?>
                                    <div class="memo-item">
                                        <div class="memo-subject"><?= h($memo['subject']) ?></div>
                                        <div class="memo-preview"><?= h(substr($memo['content'], 0, 80)) ?>...</div>
                                        <div class="memo-date"><?= date('M d, Y H:i', strtotime($memo['created_at'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Send Message to User -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Direct Message</div>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?= getCSRFTokenField() ?>
                                <div class="form-group">
                                    <label class="form-label">Search User</label>
                                    <div class="user-search-box">
                                        <input type="text" id="messageUserSearch" class="form-input" placeholder="Type name or matric..." autocomplete="off">
                                        <div id="messageSearchResults" class="search-results"></div>
                                    </div>
                                    <input type="hidden" name="recipient_user_id" id="messageRecipientId">
                                    <div class="selected-msg-user" id="selectedMessageUser">
                                        <span id="selectedMessageUserName"></span>
                                        <button type="button" onclick="clearMessageUser()" style="background: none; border: none; color: var(--accent-red); cursor: pointer; font-weight: bold;">&times;</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Message</label>
                                    <textarea name="message_text" class="form-textarea" placeholder="Type your message..." style="min-height: 80px;"></textarea>
                                </div>
                                <button type="submit" name="send_individual_message" class="btn btn-primary" style="width: 100%;">Send Message</button>
                            </form>
                        </div>
                    </div>

                    <!-- Recent Messages -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Sent Messages</div>
                        </div>
                        <?php if (empty($recentMessages)): ?>
                            <div class="empty-state">
                                <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 2L11 13"></path><path d="M22 2l-7 20-4-9-9-4 20-7z"></path></svg>
                                <p>No messages sent yet</p>
                            </div>
                        <?php else: ?>
                            <div class="message-list">
                                <?php foreach ($recentMessages as $m): ?>
                                    <div class="message-item">
                                        <div class="message-recipient"><?= h($m['recipient_name']) ?> <span style="font-weight: 400; color: var(--text-muted);">(<?= h($m['matricNo']) ?>)</span></div>
                                        <div class="message-text"><?= h(substr($m['message'], 0, 100)) ?><?= strlen($m['message']) > 100 ? '...' : '' ?></div>
                                        <div class="message-date"><?= date('M d, Y H:i', strtotime($m['timestamp'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function showFileName(input, targetId) {
            const target = document.getElementById(targetId);
            if (input.files && input.files[0]) target.textContent = input.files[0].name;
        }

        document.addEventListener('click', e => {
            if (!e.target.closest('.user-search-box')) searchResults.classList.remove('active');
        });

        // Direct Message
        const messageUserSearch = document.getElementById('messageUserSearch');
        const messageSearchResults = document.getElementById('messageSearchResults');
        let messageSearchTimeout;

        messageUserSearch.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(messageSearchTimeout);
            if (query.length < 2) { messageSearchResults.classList.remove('active'); return; }

            messageSearchTimeout = setTimeout(() => {
                fetch(`admin_memo.php?search_user=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(users => {
                        if (users.length === 0) {
                            messageSearchResults.innerHTML = '<div style="padding:10px;color:var(--text-muted);">No users found</div>';
                        } else {
                            messageSearchResults.innerHTML = users.map(user => `
                                <div class="search-result-item" onclick="selectMessageUser(${user.UserID}, '${user.name.replace(/'/g, "\\'")}', '${user.matricNo}')">
                                    <span style="font-weight:500;">${user.name}</span>
                                    <span style="font-size:12px;color:var(--text-muted);">${user.matricNo}</span>
                                </div>
                            `).join('');
                        }
                        messageSearchResults.classList.add('active');
                    });
            }, 300);
        });

        window.selectMessageUser = function(id, name, matric) {
            document.getElementById('messageRecipientId').value = id;
            document.getElementById('selectedMessageUserName').textContent = `${name} (${matric})`;
            document.getElementById('selectedMessageUser').classList.add('active');
            messageSearchResults.classList.remove('active');
            messageUserSearch.value = '';
        }

        window.clearMessageUser = function() {
            document.getElementById('messageRecipientId').value = '';
            document.getElementById('selectedMessageUser').classList.remove('active');
        }

        document.addEventListener('click', e => {
            if (!messageUserSearch.contains(e.target) && !messageSearchResults.contains(e.target)) {
                messageSearchResults.classList.remove('active');
            }
        });
    </script>
</body>
</html>
