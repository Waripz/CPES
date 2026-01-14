<?php
require_once 'config.php';
$pdo = getDBConnection();

echo "<h2>Debug Memo Page</h2>";

// Check memo table structure
echo "<h3>1. Table memo columns:</h3>";
try {
    $cols = $pdo->query("DESCRIBE memo")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>"; print_r($cols); echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Check message table structure  
echo "<h3>2. Table message columns:</h3>";
try {
    $cols = $pdo->query("DESCRIBE message")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>"; print_r($cols); echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Check if is_admin_message column exists
echo "<h3>3. Check is_admin_message column:</h3>";
try {
    $result = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'message' AND column_name = 'is_admin_message'")->fetchColumn();
    echo $result > 0 ? "<p style='color:green'>✓ Column exists</p>" : "<p style='color:red'>✗ Column MISSING!</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test the queries used in admin_memo.php
echo "<h3>4. Test memo query:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM memo ORDER BY created_at DESC LIMIT 5");
    echo "<p style='color:green'>✓ Query OK</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "<h3>5. Test message query:</h3>";
try {
    $stmt = $pdo->prepare("SELECT m.MessageID, m.message, m.timestamp, u.name FROM message m JOIN users u ON m.recipientUserID = u.UserID WHERE m.is_admin_message = 1 LIMIT 5");
    $stmt->execute();
    echo "<p style='color:green'>✓ Query OK</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
