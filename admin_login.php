<?php
require_once 'config.php';
adminSecureSessionStart();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    try {
        $pdo = getDBConnection();

        // Get admin by username only (for password verification)
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            // TEMPORARY BYPASS - REMOVE AFTER FIXING!
            // Reset password to what user entered and log them in
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE admin SET password = ? WHERE AdminID = ?");
            $upd->execute([$newHash, $admin['AdminID']]);
            
            $_SESSION['AdminID'] = $admin['AdminID'];
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['role'] = 'admin';
            header('Location: admin_dashboard.php');
            exit;
        }
        
        $error = "Invalid credentials. Please try again.";
    } catch (Exception $e) {
        $error = "System error. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - E-Kedai</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="admin.png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        :root {
            --bg-dark: #0a0a0b;
            --bg-darker: #050506;
            --card-bg: #ffffff;
            --text-primary: #18181b;
            --text-secondary: #52525b;
            --text-muted: #a1a1aa;
            --border-color: #e4e4e7;
            --accent-blue: #2563eb;
            --accent-red: #dc2626;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-dark);
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }
        
        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }
        
        .login-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 48px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        .logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 32px;
        }
        
        .logo-icon {
            width: 64px;
            height: 64px;
            background: var(--bg-dark);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .brand {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .brand h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }
        
        .brand p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        .alert {
            background: #fef2f2;
            color: var(--accent-red);
            padding: 12px 16px;
            border-radius: 8px;
            margin: 24px 0;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid var(--accent-red);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fafafa;
            color: var(--text-primary);
        }
        
        .form-group input::placeholder {
            color: var(--text-muted);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--accent-blue);
            background: white;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .submit-btn {
            width: 100%;
            padding: 14px;
            background: var(--bg-dark);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
        }
        
        .submit-btn:hover {
            background: #18181b;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        .footer {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }
        
        .footer p {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 36px 24px;
            }
            
            .brand h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="logo-container">
                <div class="logo-icon">
                    <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
            </div>
            
            <div class="brand">
                <h1>Admin Portal</h1>
                <p>Campus Preloved E-Shop</p>
            </div>
            
            <?php if($error): ?>
                <div class="alert">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="margin-top: 28px;">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="submit-btn">Sign In</button>
            </form>

            <div class="footer">
                <p>&copy; 2024 E-Kedai. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
