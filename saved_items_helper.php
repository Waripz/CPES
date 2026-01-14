<?php
// Helper functions for saved items stored as JSON in users.saved_items
function saved_get_ids(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT saved_items FROM users WHERE UserID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = array_values(array_filter(array_map('intval', $decoded), fn($v) => $v > 0));
    return array_values(array_unique($ids));
}

function saved_set_ids(PDO $pdo, int $userId, array $ids): void {
    $unique = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    $json = empty($unique) ? null : json_encode($unique);
    $stmt = $pdo->prepare("UPDATE users SET saved_items = ? WHERE UserID = ?");
    $stmt->execute([$json, $userId]);
}

function saved_add(PDO $pdo, int $userId, int $itemId): void {
    $ids = saved_get_ids($pdo, $userId);
    if (!in_array($itemId, $ids, true)) {
        $ids[] = $itemId;
        saved_set_ids($pdo, $userId, $ids);
    }
}

function saved_remove(PDO $pdo, int $userId, int $itemId): void {
    $ids = saved_get_ids($pdo, $userId);
    $filtered = array_values(array_filter($ids, fn($v) => $v !== $itemId));
    saved_set_ids($pdo, $userId, $filtered);
}

function saved_fetch_items(PDO $pdo, int $userId, bool $includeStatus = false): array {
    $ids = saved_get_ids($pdo, $userId);
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fields = $includeStatus ? 'ItemID, title, price, image, status' : 'ItemID, title, price, image';
    $sql = "SELECT {$fields} FROM item WHERE ItemID IN ({$placeholders}) ORDER BY FIELD(ItemID," . implode(',', $ids) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
