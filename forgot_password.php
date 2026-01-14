<?php
session_start();

// Clear session if requested
if (isset($_GET['clear'])) {
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_password_hash']);
    header('Location: forgot_password.php');
    exit;
}

// Database connection
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database connection failed.");
}

$step = 1;
$error = '';
$success = '';
$email = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Step 1: Verify Email
    if (isset($_POST['verify_email'])) {
        $email = trim($_POST['email']);
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } else {
            $stmt = $pdo->prepare("SELECT UserID, email, password FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $_SESSION['reset_user_id'] = $user['UserID'];
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_password_hash'] = $user['password'];
                $step = 2;
            } else {
                $error = 'Email not found in our system.';
            }
        }
    }
    
    // Step 2: Reset Password
    if (isset($_POST['reset_password'])) {
        if (!isset($_SESSION['reset_user_id'])) {
            $error = 'Session expired. Please start again.';
            $step = 1;
        } else {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            $stored_hash = $_SESSION['reset_password_hash'];
            
            // Validate current password
            $password_valid = password_verify($current_password, $stored_hash);
            if (!$password_valid) {
                $password_valid = hash_equals($current_password, $stored_hash);
            }
            
            if (!$password_valid) {
                $error = 'Current password is incorrect.';
                $step = 2;
                $email = $_SESSION['reset_email'];
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match.';
                $step = 2;
                $email = $_SESSION['reset_email'];
            } elseif (strlen($new_password) < 6) {
                $error = 'New password must be at least 6 characters.';
                $step = 2;
                $email = $_SESSION['reset_email'];
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password = ? WHERE UserID = ?");
                $update->execute([$new_hash, $_SESSION['reset_user_id']]);
                
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_password_hash']);
                
                $success = 'Password updated successfully!';
                $step = 3;
            }
        }
    }
}

// If session has reset data, show step 2
if (isset($_SESSION['reset_user_id']) && $step === 1 && empty($error)) {
    $step = 2;
    $email = $_SESSION['reset_email'];
}

// h() function is already defined in config.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CPES - Reset Password</title>
    <link rel="icon" type="image/png" href="letter-w.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        
        .header { background-color: #e0e0e0; padding: 15px 0; display: flex; justify-content: center; align-items: center; width: 100%; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 10; }
        .header img { height: 50px; margin-right: 10px; }
        .header span { color: #000; font-size: 19px; font-weight: normal; }
        
        .main-content {
            flex: 1; position: relative; width: 100%; display: flex; align-items: center; justify-content: center; padding: 40px 5%; overflow: hidden;
            background: radial-gradient(ellipse at 0% 0%, rgba(120, 0, 255, 0.4) 0%, transparent 50%),
                        radial-gradient(ellipse at 100% 0%, rgba(0, 150, 255, 0.3) 0%, transparent 50%),
                        radial-gradient(ellipse at 100% 100%, rgba(200, 0, 255, 0.35) 0%, transparent 50%),
                        radial-gradient(ellipse at 0% 100%, rgba(0, 100, 200, 0.3) 0%, transparent 50%),
                        linear-gradient(160deg, #0f0c29 0%, #1a1a3e 25%, #24243e 50%, #1a1a3e 75%, #0f0c29 100%);
            background-size: 200% 200%, 200% 200%, 200% 200%, 200% 200%, 100% 100%;
            animation: auroraShift 15s ease-in-out infinite;
        }
        @keyframes auroraShift {
            0%, 100% { background-position: 0% 0%, 100% 0%, 100% 100%, 0% 100%, 0% 0%; }
            50% { background-position: 100% 100%, 0% 100%, 0% 0%, 100% 0%, 0% 0%; }
        }
        
        .reset-container { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 80px; max-width: 1100px; width: 100%; }
        .brand-section { flex: 1; color: white; }
        .brand-title .main-text { font-size: 4.5rem; font-weight: 900; line-height: 1; background: linear-gradient(135deg, #ffffff 0%, #a78bfa 50%, #818cf8 100%); -webkit-background-clip: text; background-clip: text; color: transparent; display: block; }
        .brand-title .sub-text { font-size: 2.8rem; font-weight: 700; line-height: 1.1; background: linear-gradient(135deg, #c4b5fd 0%, #a5b4fc 100%); -webkit-background-clip: text; background-clip: text; color: transparent; display: block; margin-top: 5px; }
        .tagline { font-size: 1.25rem; color: rgba(255, 255, 255, 0.7); margin-top: 20px; }
        
        .reset-card { width: 420px; background: rgba(255, 255, 255, 0.12); backdrop-filter: blur(20px); border-radius: 24px; padding: 45px 40px; border: 1px solid rgba(255, 255, 255, 0.2); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3); }
        .reset-card h2 { font-size: 1.75rem; font-weight: 700; color: white; margin-bottom: 8px; }
        .reset-card .subtitle { font-size: 0.95rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 30px; }
        
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; font-size: 0.875rem; font-weight: 500; color: rgba(255, 255, 255, 0.85); margin-bottom: 8px; }
        .form-group input { width: 100%; padding: 16px 18px; font-size: 1rem; font-family: 'Poppins', sans-serif; color: white; background: rgba(255, 255, 255, 0.08); border: 2px solid rgba(255, 255, 255, 0.15); border-radius: 14px; outline: none; transition: all 0.3s ease; }
        .form-group input::placeholder { color: rgba(255, 255, 255, 0.4); }
        .form-group input:focus { border-color: #818cf8; background: rgba(255, 255, 255, 0.12); box-shadow: 0 0 0 4px rgba(129, 140, 248, 0.2); }
        
        .btn-submit { width: 100%; padding: 16px; font-size: 1.05rem; font-weight: 600; font-family: 'Poppins', sans-serif; color: white; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none; border-radius: 14px; cursor: pointer; margin-top: 10px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4); }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.5); }
        
        .back-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9rem; color: rgba(255, 255, 255, 0.6); text-decoration: none; }
        .back-link:hover { color: #a78bfa; }
        
        .error-msg { background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.5); color: #fca5a5; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem; }
        .success-msg { background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.5); color: #6ee7b7; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem; text-align: center; }
        
        .step-indicator { display: flex; justify-content: center; gap: 12px; margin-bottom: 24px; }
        .step-dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(255, 255, 255, 0.3); }
        .step-dot.active { background: #818cf8; box-shadow: 0 0 10px rgba(129, 140, 248, 0.5); }
        
        .footer { background-color: #e0e0e0; padding: 15px 0; text-align: center; width: 100%; color: #555; font-size: 0.9em; }
        
        /* ===== SUCCESS OVERLAY ===== */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #0f0c29 0%, #1a1a3e 40%, #302b63 70%, #24243e 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .success-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .success-overlay::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulseGlow 2s ease-in-out infinite;
        }

        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .success-content {
            position: relative;
            z-index: 1;
            text-align: center;
            animation: slideUp 0.6s ease-out 0.2s both;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.4);
            animation: scaleIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.3s both;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .success-icon svg {
            width: 50px;
            height: 50px;
            stroke: white;
            stroke-width: 3;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .success-icon svg .checkmark {
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: drawCheck 0.5s ease-out 0.6s forwards;
        }

        @keyframes drawCheck {
            to {
                stroke-dashoffset: 0;
            }
        }

        .success-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #ffffff 0%, #a78bfa 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .success-message {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 30px;
        }

        .loading-dots {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .loading-dots span {
            width: 10px;
            height: 10px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .loading-dots span:nth-child(1) {
            animation-delay: -0.32s;
        }

        .loading-dots span:nth-child(2) {
            animation-delay: -0.16s;
        }

        @keyframes bounce {
            0%, 80%, 100% {
                transform: scale(0.6);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        @media (max-width: 900px) {
            .reset-container { flex-direction: column; gap: 40px; text-align: center; }
            .brand-title .main-text { font-size: 3rem; }
            .brand-title .sub-text { font-size: 2rem; }
            .reset-card { width: 100%; max-width: 400px; }
        }
        @media (max-width: 480px) {
            .header { padding: 10px 15px; }
            .header img { height: 35px; }
            .header span { font-size: 11px; }
            .main-content { padding: 20px 16px; }
            .brand-title .main-text { font-size: 2.2rem; }
            .brand-title .sub-text { font-size: 1.4rem; }
            .reset-card { padding: 28px 20px; }
            .form-group input { padding: 12px 14px; font-size: 0.9rem; }
            .btn-submit { padding: 12px; font-size: 0.95rem; }
            
            /* Success Overlay Mobile */
            .success-overlay::before {
                width: 300px;
                height: 300px;
            }

            .success-icon {
                width: 80px;
                height: 80px;
                margin-bottom: 24px;
            }

            .success-icon svg {
                width: 40px;
                height: 40px;
            }

            .success-title {
                font-size: 1.6rem;
            }

            .success-message {
                font-size: 0.95rem;
                padding: 0 20px;
                margin-bottom: 24px;
            }

            .loading-dots span {
                width: 8px;
                height: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo uthmm.png" alt="UTHM Logo"> 
        <span>Universiti Tun Hussein Onn Malaysia</span>
    </div>

    <div class="main-content">
        <div class="reset-container">
            <div class="brand-section">
                <div class="brand-title">
                    <span class="main-text">CAMPUS</span>
                    <span class="sub-text">PRELOVED</span>
                    <span class="sub-text">E-SHOP</span>
                </div>
                <p class="tagline">Reset Your Password</p>
            </div>

            <div class="reset-card">
                <div class="step-indicator">
                    <div class="step-dot <?php echo $step >= 1 ? 'active' : ''; ?>"></div>
                    <div class="step-dot <?php echo $step >= 2 ? 'active' : ''; ?>"></div>
                </div>

                <?php if ($step === 3): ?>
                    <!-- Show Success Overlay for step 3 -->
                    <h2>Success!</h2>
                    <p class="subtitle">Your password has been updated</p>
                    <a href="index.html" class="btn-submit" style="display: block; text-align: center; text-decoration: none;">Go to Login</a>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const overlay = document.getElementById('successOverlay');
                            overlay.classList.add('show');
                            setTimeout(function() { window.location.href = 'index.html'; }, 2500);
                        });
                    </script>

                <?php elseif ($step === 2): ?>
                    <h2>Reset Password</h2>
                    <p class="subtitle">Enter your current and new password</p>
                    <?php if ($error): ?><div class="error-msg"><?php echo h($error); ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" placeholder="Enter your current password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" placeholder="Enter new password (min 6 chars)" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required minlength="6">
                        </div>
                        <button type="submit" name="reset_password" class="btn-submit">Update Password</button>
                        <a href="forgot_password.php?clear=1" class="back-link">← Back</a>
                    </form>

                <?php else: ?>
                    <h2>Reset Password</h2>
                    <p class="subtitle">Enter your email to verify your account</p>
                    <?php if ($error): ?><div class="error-msg"><?php echo h($error); ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" placeholder="e.g. student@uthm.edu.my" required value="<?php echo h($email); ?>">
                        </div>
                        <button type="submit" name="verify_email" class="btn-submit">Verify Email</button>
                        <a href="index.html" class="back-link">← Back to Login</a>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        © 2025 Campus Preloved E-shop. All rights reserved.
    </div>

    <!-- Success Overlay -->
    <div id="successOverlay" class="success-overlay">
        <div class="success-content">
            <div class="success-icon">
                <svg viewBox="0 0 24 24">
                    <polyline class="checkmark" points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h2 class="success-title">Password Updated!</h2>
            <p class="success-message">Redirecting you to login...</p>
            <div class="loading-dots">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </div>
</body>
</html>
