<?php
header('Content-Type: application/json');

if (!isset($_GET['userid'])) {
    echo json_encode(['items' => []]);
    exit;
}

$userID = (int)$_GET['userid'];

require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['items' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT ItemID, title, price, category, postDate, description, image, status, sold_price, sold_date FROM item WHERE UserID = ? ORDER BY postDate DESC LIMIT 50");
    $stmt->execute([$userID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['items' => $items]);
} catch (Exception $e) {
    echo json_encode(['items' => []]);
}
?>
