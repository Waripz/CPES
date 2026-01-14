<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['UserID'])) {
    echo json_encode(['items' => []]);
    exit;
}

$userid = (int)$_SESSION['UserID'];

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/saved_items_helper.php';

    $items = saved_fetch_items($pdo, $userid);
    echo json_encode(['items' => $items]);
} catch (Exception $e) {
    echo json_encode(['items' => [], 'error' => $e->getMessage()]);
}
