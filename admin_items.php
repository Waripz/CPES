<?php
require_once 'config.php';
adminSecureSessionStart();

// Security Check
if (!isset($_SESSION['AdminID']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// Database Connection
$pdo = getDBConnection();

// Stats
$pendingCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'under_review'")->fetchColumn();
$availableCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'available'")->fetchColumn();
$soldCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'sold'")->fetchColumn();
$rejectedCount = $pdo->query("SELECT COUNT(*) FROM item WHERE status = 'rejected'")->fetchColumn();
$totalCount = $pdo->query("SELECT COUNT(*) FROM item")->fetchColumn();
$unopenedReportsCount = $pdo->query("SELECT COUNT(*) FROM report WHERE is_opened = 0 OR is_opened IS NULL")->fetchColumn();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'], $_POST['action'])) {
    $id = (int)$_POST['item_id'];
    $act = $_POST['action'];
    
    if ($act === 'approve') {
        $stmt = $pdo->prepare("UPDATE item SET status = 'available', rejection_reason = NULL WHERE ItemID = ?");
        $stmt->execute([$id]);
    } elseif ($act === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (empty($reason)) $reason = 'Your item does not meet our platform guidelines.';
        $stmt = $pdo->prepare("UPDATE item SET status = 'rejected', rejection_reason = ? WHERE ItemID = ?");
        $stmt->execute([$reason, $id]);
    } elseif ($act === 'blacklist') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (empty($reason)) $reason = 'This item has been removed from the platform.';
        $stmt = $pdo->prepare("UPDATE item SET status = 'blacklisted', rejection_reason = ? WHERE ItemID = ?");
        $stmt->execute([$reason, $id]);
    } elseif ($act === 'restore') {
        $stmt = $pdo->prepare("UPDATE item SET status = 'available', rejection_reason = NULL WHERE ItemID = ?");
        $stmt->execute([$id]);
    }
    header("Location: admin_items.php");
    exit;
}

// Fetch Pending Items
$sqlPending = "SELECT i.*, u.name as seller_name, u.matricNo 
               FROM item i JOIN users u ON i.UserID = u.UserID 
               WHERE i.status = 'under_review' ORDER BY i.postDate ASC";
$reviews = $pdo->query($sqlPending)->fetchAll(PDO::FETCH_ASSOC);

// Filters
$searchLive = $_GET['search_live'] ?? '';
$statusFilter = $_GET['status_filter'] ?? 'all';
$categoryFilter = $_GET['category_filter'] ?? 'all';
$sortBy = $_GET['sort_by'] ?? 'newest';

$sqlLive = "SELECT i.*, i.sold_price, i.sold_date, u.name as seller_name, u.matricNo 
            FROM item i JOIN users u ON i.UserID = u.UserID WHERE 1=1";

if ($statusFilter === 'available') $sqlLive .= " AND i.status = 'available'";
elseif ($statusFilter === 'sold') $sqlLive .= " AND i.status = 'sold'";
elseif ($statusFilter === 'rejected') $sqlLive .= " AND i.status = 'rejected'";
elseif ($statusFilter === 'blacklisted') $sqlLive .= " AND i.status = 'blacklisted'";
else $sqlLive .= " AND i.status IN ('available', 'sold', 'rejected', 'blacklisted')";

if ($categoryFilter !== 'all') $sqlLive .= " AND i.category = :cat";
if (!empty($searchLive)) $sqlLive .= " AND (i.title LIKE :s OR u.name LIKE :s OR i.description LIKE :s)";

switch ($sortBy) {
    case 'oldest': $sqlLive .= " ORDER BY i.postDate ASC"; break;
    case 'price_high': $sqlLive .= " ORDER BY i.price DESC"; break;
    case 'price_low': $sqlLive .= " ORDER BY i.price ASC"; break;
    case 'title': $sqlLive .= " ORDER BY i.title ASC"; break;
    default: $sqlLive .= " ORDER BY i.postDate DESC";
}
$sqlLive .= " LIMIT 100";

$stmtLive = $pdo->prepare($sqlLive);
$params = [];
if (!empty($searchLive)) $params['s'] = "%$searchLive%";
if ($categoryFilter !== 'all') $params['cat'] = $categoryFilter;
$stmtLive->execute($params);
$liveItems = $stmtLive->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT DISTINCT category FROM item ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if (!function_exists('h')) { function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Items - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin_style.css">
    <style>
        /* Page-specific styles */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .mini-stat {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 20px;
            border: 1px solid var(--border-color);
        }
        
        .mini-stat-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .mini-stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .mini-stat-value.pending { color: var(--accent-amber); }
        .mini-stat-value.available { color: var(--accent-green); }
        .mini-stat-value.sold { color: var(--accent-blue); }
        .mini-stat-value.rejected { color: var(--accent-violet); }
        .mini-stat-value.blacklisted { color: var(--accent-red); }
        
        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .section-badge.warning {
            background: var(--amber-subtle);
            color: var(--accent-amber);
        }
        
        .section-badge.success {
            background: var(--green-subtle);
            color: var(--accent-green);
        }
        
        .item-thumb {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            background: var(--bg-page);
            border: 1px solid var(--border-color);
        }
        
        .item-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .item-title {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .item-meta {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .review-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 20px;
        }
        
        .review-img {
            width: 100%;
            height: 200px;
            object-fit: contain;
            background: var(--bg-page);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        
        .detail-row {
            margin-bottom: 16px;
        }
        
        .detail-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 15px;
            color: var(--text-primary);
            font-weight: 600;
        }
        
        .desc-box {
            background: var(--bg-page);
            padding: 14px;
            border-radius: var(--radius-md);
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            white-space: pre-line;
            max-height: 140px;
            overflow-y: auto;
        }
        
        .rejection-box {
            background: var(--red-subtle);
            padding: 14px;
            border-radius: var(--radius-md);
            font-size: 14px;
            line-height: 1.6;
            color: var(--accent-red);
            border: 1px solid rgba(220, 38, 38, 0.2);
            margin-top: 12px;
        }
        
        #reason-container textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            resize: vertical;
            background: var(--card-bg);
        }
        
        #reason-container textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        
        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 768px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .review-grid { grid-template-columns: 1fr; }
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
                    <a href="admin_items.php" class="nav-link active">
                        <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        Manage Items
                        <?php if ($pendingCount > 0): ?>
                        <span class="nav-badge"><?= $pendingCount ?></span>
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
                    <a href="admin_memo.php" class="nav-link">
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
            <!-- Header -->
            <header class="page-header">
                <div class="header-content">
                    <h1>Manage Items</h1>
                    <p class="header-subtitle">Review, approve, and manage marketplace listings</p>
                </div>
                <div class="header-actions">
                    <div class="admin-badge">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span><?= h($_SESSION['admin_name']) ?></span>
                    </div>
                </div>
            </header>

            <!-- Stats Row -->
            <div class="stats-row">
                <div class="mini-stat">
                    <div class="mini-stat-label">Pending Review</div>
                    <div class="mini-stat-value pending"><?= number_format($pendingCount) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-label">Available</div>
                    <div class="mini-stat-value available"><?= number_format($availableCount) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-label">Sold</div>
                    <div class="mini-stat-value sold"><?= number_format($soldCount) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-label">Rejected</div>
                    <div class="mini-stat-value rejected"><?= number_format($rejectedCount) ?></div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-label">Total Items</div>
                    <div class="mini-stat-value"><?= number_format($totalCount) ?></div>
                </div>
            </div>

            <!-- Pending Items Section -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="section-badge warning">Pending</span>
                        Items Awaiting Approval
                    </div>
                </div>
                <div class="table-container">
                    <?php if (empty($reviews)): ?>
                        <div class="empty-state">
                            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><path d="M9 22l2 2 4-4"></path><path d="M17 5h7"></path><path d="M17 10h7"></path><path d="M17 15h7"></path><path d="M17 20h7"></path></svg>
                            <p>No items pending review</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th width="60">Preview</th>
                                    <th>Item</th>
                                    <th>Seller</th>
                                    <th>Price</th>
                                    <th>Submitted</th>
                                    <th width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($reviews as $r): 
                                    $img = !empty($r['image']) ? explode(',', $r['image'])[0] : 'avatar.png';
                                    $r['display_img'] = $img;
                                    $jsonData = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td><img src="<?= h($img) ?>" class="item-thumb" alt="Item"></td>
                                        <td>
                                            <div class="item-info">
                                                <span class="item-title"><?= h($r['title']) ?></span>
                                                <span class="item-meta"><?= h($r['category']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="item-info">
                                                <span class="item-title"><?= h($r['seller_name']) ?></span>
                                                <span class="item-meta"><?= h($r['matricNo']) ?></span>
                                            </div>
                                        </td>
                                        <td><strong>RM<?= number_format((float)$r['price'], 2) ?></strong></td>
                                        <td><span class="item-meta"><?= date('d M Y', strtotime($r['postDate'])) ?></span></td>
                                        <td><button type="button" class="btn btn-outline btn-sm" onclick="openReviewModal(<?= $jsonData ?>, true)">Review</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- All Items Section -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="section-badge success">All</span>
                        Item Listings
                    </div>
                    <form method="GET" class="filter-bar">
                        <select name="status_filter" class="form-select">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="sold" <?= $statusFilter === 'sold' ? 'selected' : '' ?>>Sold</option>
                            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="blacklisted" <?= $statusFilter === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
                        </select>
                        <select name="category_filter" class="form-select">
                            <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="sort_by" class="form-select">
                            <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest First</option>
                            <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="price_high" <?= $sortBy === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                            <option value="price_low" <?= $sortBy === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                            <option value="title" <?= $sortBy === 'title' ? 'selected' : '' ?>>Title A-Z</option>
                        </select>
                        <input type="text" name="search_live" class="form-input" placeholder="Search items..." value="<?= h($searchLive) ?>" style="width: 180px;">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <?php if (!empty($searchLive) || $statusFilter !== 'all' || $categoryFilter !== 'all' || $sortBy !== 'newest'): ?>
                            <a href="admin_items.php" class="btn btn-outline btn-sm">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-container">
                    <?php if (empty($liveItems)): ?>
                        <div class="empty-state">
                            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <p>No items match your filters</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th width="60">Preview</th>
                                    <th>Item</th>
                                    <th>Seller</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Posted</th>
                                    <th width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($liveItems as $r): 
                                    $img = !empty($r['image']) ? explode(',', $r['image'])[0] : 'avatar.png';
                                    $r['display_img'] = $img;
                                    $jsonData = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td><img src="<?= h($img) ?>" class="item-thumb" alt="Item"></td>
                                        <td>
                                            <div class="item-info">
                                                <span class="item-title"><?= h($r['title']) ?></span>
                                                <span class="item-meta"><?= h($r['category']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="item-info">
                                                <span class="item-title"><?= h($r['seller_name']) ?></span>
                                                <span class="item-meta"><?= h($r['matricNo']) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($r['status'] === 'sold' && !empty($r['sold_price'])): ?>
                                                <div class="item-info">
                                                    <strong>RM<?= number_format((float)$r['sold_price'], 2) ?></strong>
                                                    <?php if ((float)$r['sold_price'] != (float)$r['price']): ?>
                                                        <span class="item-meta" style="text-decoration:line-through;">RM<?= number_format((float)$r['price'], 2) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <strong>RM<?= number_format((float)$r['price'], 2) ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></span></td>
                                        <td><span class="item-meta"><?= date('d M Y', strtotime($r['postDate'])) ?></span></td>
                                        <td><button type="button" class="btn btn-outline btn-sm" onclick="openReviewModal(<?= $jsonData ?>, false)">Manage</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Review Modal -->
    <div class="modal-overlay" id="reviewModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Manage Item</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="review-grid">
                    <img src="" id="m-img" class="review-img" alt="Item">
                    <div>
                        <div class="detail-row">
                            <span class="detail-label">Title</span>
                            <div class="detail-value" id="m-title"></div>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Category</span>
                            <div class="detail-value" id="m-cat"></div>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Price</span>
                            <div class="detail-value" id="m-price"></div>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Seller</span>
                            <div class="detail-value" id="m-seller"></div>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Status</span>
                            <div class="detail-value" id="m-status"></div>
                        </div>
                    </div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Description</span>
                    <div class="desc-box" id="m-desc"></div>
                </div>
                <div class="detail-row" id="rejection-info" style="display: none;">
                    <span class="detail-label">Rejection Reason</span>
                    <div class="rejection-box" id="m-rejection-reason"></div>
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="review-form" style="display: flex; flex-direction: column; gap: 12px; width: 100%;">
                    <input type="hidden" name="item_id" id="m-id">
                    <input type="hidden" name="action" id="form-action" value="">
                    
                    <div id="reason-container" style="display: none;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Reason *</label>
                        <textarea name="rejection_reason" id="rejection-reason" rows="3" placeholder="Provide a reason..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                        <button type="button" id="btn-restore" class="btn btn-success" onclick="submitRestore()" style="display: none;">Restore</button>
                        <button type="button" id="btn-reject" class="btn btn-outline" onclick="showReasonForm('reject')" style="color: var(--accent-violet); border-color: var(--accent-violet);">Reject</button>
                        <button type="button" id="btn-blacklist" class="btn btn-outline" onclick="showReasonForm('blacklist')" style="color: var(--accent-red); border-color: var(--accent-red);">Blacklist</button>
                        <button type="button" id="btn-confirm" class="btn btn-danger" onclick="submitWithReason()" style="display: none;">Confirm</button>
                        <button type="button" id="btn-approve" class="btn btn-success" onclick="submitApprove()">Approve</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('reviewModal');
        const btnApprove = document.getElementById('btn-approve');
        const btnReject = document.getElementById('btn-reject');
        const btnBlacklist = document.getElementById('btn-blacklist');
        const btnConfirm = document.getElementById('btn-confirm');
        const btnRestore = document.getElementById('btn-restore');
        const reasonContainer = document.getElementById('reason-container');
        const rejectionInfo = document.getElementById('rejection-info');
        
        let currentAction = '';
        
        function openReviewModal(item, isPending) {
            document.getElementById('m-id').value = item.ItemID;
            document.getElementById('m-title').innerText = item.title;
            document.getElementById('m-cat').innerText = item.category;
            document.getElementById('m-price').innerText = "RM " + parseFloat(item.price).toFixed(2);
            document.getElementById('m-seller').innerText = item.seller_name + ' (' + (item.matricNo || '-') + ')';
            document.getElementById('m-status').innerText = item.status.toUpperCase().replace('_', ' ');
            document.getElementById('m-desc').innerText = item.description || 'No description';
            document.getElementById('m-img').src = item.display_img;
            
            if (item.rejection_reason) {
                rejectionInfo.style.display = 'block';
                document.getElementById('m-rejection-reason').innerText = item.rejection_reason;
            } else {
                rejectionInfo.style.display = 'none';
            }
            
            reasonContainer.style.display = 'none';
            btnConfirm.style.display = 'none';
            document.getElementById('rejection-reason').value = '';
            currentAction = '';
            
            if (isPending || item.status === 'under_review') {
                document.getElementById('modal-title').innerText = 'Review Item';
                btnApprove.style.display = 'inline-flex';
                btnReject.style.display = 'inline-flex';
                btnBlacklist.style.display = 'inline-flex';
                btnRestore.style.display = 'none';
            } else if (item.status === 'available') {
                document.getElementById('modal-title').innerText = 'Manage Item';
                btnApprove.style.display = 'none';
                btnReject.style.display = 'none';
                btnBlacklist.style.display = 'inline-flex';
                btnRestore.style.display = 'none';
            } else if (item.status === 'sold') {
                // Sold items cannot be blacklisted - only view
                document.getElementById('modal-title').innerText = 'Manage Item';
                btnApprove.style.display = 'none';
                btnReject.style.display = 'none';
                btnBlacklist.style.display = 'none';
                btnRestore.style.display = 'none';
            } else {
                document.getElementById('modal-title').innerText = 'Manage Item';
                btnApprove.style.display = 'none';
                btnReject.style.display = 'none';
                btnBlacklist.style.display = 'none';
                btnRestore.style.display = 'inline-flex';
            }
            
            modal.classList.add('active');
        }
        
        function showReasonForm(action) {
            currentAction = action;
            reasonContainer.style.display = 'block';
            btnReject.style.display = 'none';
            btnBlacklist.style.display = 'none';
            btnApprove.style.display = 'none';
            btnConfirm.style.display = 'inline-flex';
            btnConfirm.textContent = action === 'reject' ? 'Confirm Reject' : 'Confirm Blacklist';
            document.getElementById('rejection-reason').focus();
        }
        
        function submitWithReason() {
            const reason = document.getElementById('rejection-reason').value.trim();
            if (!reason) { alert('Please provide a reason.'); return; }
            document.getElementById('form-action').value = currentAction;
            document.getElementById('review-form').submit();
        }
        
        function submitApprove() {
            document.getElementById('form-action').value = 'approve';
            document.getElementById('review-form').submit();
        }
        
        function submitRestore() {
            document.getElementById('form-action').value = 'restore';
            document.getElementById('review-form').submit();
        }

        function closeModal() { 
            modal.classList.remove('active');
        }
        
        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
    </script>
</body>
</html>
