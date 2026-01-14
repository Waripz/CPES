<?php
// Start the session to store user data
session_start();

// Set header to return JSON
header('Content-Type: application/json');

// Use centralized config for database credentials
require_once 'config.php';

// Create the response array
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

try {
    // Connect to the database using PDO (more secure)
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    
    if (isset($_POST['matricNo']) && isset($_POST['password'])) {
        
        $matricNo = $_POST['matricNo'];
        $password_form = $_POST['password'];

       
        $stmt = $pdo->prepare("SELECT * FROM users WHERE matricNo = ? OR email = ?");
        $stmt->execute([$matricNo, $matricNo]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        
        $isValid = false;
        if ($user) {
            
            if (password_verify($password_form, $user['password'])) {
                $isValid = true;
            }
            
            elseif (hash_equals($password_form, $user['password'])) {
                
                $newHash = password_hash($password_form, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE UserID = ?");
                $upd->execute([$newHash, $user['UserID']]);
                $isValid = true;
            }
        }

        if ($isValid) {
            // Password is correct! Store user data in session
            $_SESSION['UserID'] = $user['UserID'];
            $_SESSION['matricNo'] = $user['matricNo'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            // Set a successful response
            $response['success'] = true;
            $response['message'] = 'Login successful! Redirecting...';
            $response['redirect'] = 'loading.php'; // Show loading screen first

        } else {
            // Invalid matric number or password
            $response['message'] = 'Invalid Matric Number or Password.';
        }
    } else {
        // Form data not received
        $response['message'] = 'Please enter both matric number and password.';
    }

} catch (PDOException $e) {
    // Database connection error
    $response['message'] = 'Database error: ' . $e->getMessage();
}

// Send the JSON response back to the JavaScript
echo json_encode($response);
?>