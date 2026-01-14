<?php
session_start();

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    exit;
}

$memoId = isset($_GET['memo_id']) ? (int)$_GET['memo_id'] : 0;

// Broadcast memos no longer track per-recipient reads; acknowledge request.
if ($memoId > 0) {
    echo json_encode(['success' => true, 'memo_id' => $memoId]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid memo ID']);
}
