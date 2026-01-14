<?php
session_start();
// Redirect ke login jika tidak login
if (!isset($_SESSION['UserID'])) {
    header('Location: index.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loading - Campus Preloved E-Shop</title>
    <link rel="icon" type="image/png" href="letter-w.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            
            /* Dark Indigo Aurora Background */
            background: 
                radial-gradient(ellipse at 0% 0%, rgba(120, 0, 255, 0.35) 0%, transparent 50%),
                radial-gradient(ellipse at 100% 0%, rgba(0, 150, 255, 0.25) 0%, transparent 50%),
                radial-gradient(ellipse at 100% 100%, rgba(200, 0, 255, 0.3) 0%, transparent 50%),
                radial-gradient(ellipse at 0% 100%, rgba(0, 100, 200, 0.25) 0%, transparent 50%),
                linear-gradient(160deg, #0f0c29 0%, #1a1a3e 25%, #24243e 50%, #1a1a3e 75%, #0f0c29 100%);
            background-size: 200% 200%, 200% 200%, 200% 200%, 200% 200%, 100% 100%;
            animation: auroraShift 12s ease-in-out infinite;
        }

        @keyframes auroraShift {
            0%, 100% {
                background-position: 0% 0%, 100% 0%, 100% 100%, 0% 100%, 0% 0%;
            }
            25% {
                background-position: 50% 50%, 50% 0%, 100% 50%, 0% 50%, 0% 0%;
            }
            50% {
                background-position: 100% 100%, 0% 100%, 0% 0%, 100% 0%, 0% 0%;
            }
            75% {
                background-position: 50% 0%, 100% 50%, 50% 100%, 50% 50%, 0% 0%;
            }
        }

        /* Floating orbs animation */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.4;
            z-index: 0;
            animation: floatOrb 15s ease-in-out infinite;
        }

        body::before {
            width: 600px;
            height: 600px;
            background: rgba(138, 43, 226, 0.5);
            top: -200px;
            left: -200px;
        }

        body::after {
            width: 500px;
            height: 500px;
            background: rgba(0, 150, 255, 0.4);
            bottom: -150px;
            right: -150px;
            animation-delay: -7s;
        }

        @keyframes floatOrb {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(40px, -40px) scale(1.1); }
            66% { transform: translate(-30px, 30px) scale(0.9); }
        }

        /* Loading Container */
        .loading-container {
            text-align: center;
            z-index: 10;
            opacity: 0;
            animation: fadeInUp 1s ease-out 0.3s forwards;
        }

        @keyframes fadeInUp {
            0% {
                opacity: 0;
            }
            100% {
                opacity: 1;
            }
        }

        /* Brand Title - Same as login page */
        .brand-title {
            margin-bottom: 30px;
        }

        .brand-title .main-text {
            font-size: 5rem;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -2px;
            background: linear-gradient(135deg, #ffffff 0%, #a78bfa 50%, #818cf8 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: block;
            animation: textGlow 3s ease-in-out infinite;
        }

        .brand-title .sub-text {
            font-size: 3.2rem;
            font-weight: 700;
            line-height: 1.1;
            background: linear-gradient(135deg, #c4b5fd 0%, #a5b4fc 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: block;
            margin-top: 8px;
        }

        @keyframes textGlow {
            0%, 100% {
                filter: drop-shadow(0 0 20px rgba(167, 139, 250, 0.3));
            }
            50% {
                filter: drop-shadow(0 0 40px rgba(167, 139, 250, 0.6));
            }
        }

        /* Loading Dots */
        .loading-dots {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 40px;
        }

        .loading-dots span {
            width: 12px;
            height: 12px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            animation: bounce 1.4s ease-in-out infinite;
        }

        .loading-dots span:nth-child(1) { animation-delay: 0s; }
        .loading-dots span:nth-child(2) { animation-delay: 0.15s; }
        .loading-dots span:nth-child(3) { animation-delay: 0.3s; }

        @keyframes bounce {
            0%, 80%, 100% {
                transform: scale(0.6);
                opacity: 0.4;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Tagline */
        .tagline {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 30px;
            letter-spacing: 0.5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .brand-title .main-text {
                font-size: 3rem;
            }
            .brand-title .sub-text {
                font-size: 2rem;
            }
            .tagline {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .brand-title .main-text {
                font-size: 2.2rem;
                letter-spacing: -1px;
            }
            .brand-title .sub-text {
                font-size: 1.4rem;
            }
            .loading-dots span {
                width: 10px;
                height: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="brand-title">
            <span class="main-text">CAMPUS</span>
            <span class="sub-text">PRELOVED</span>
            <span class="sub-text">E-SHOP</span>
        </div>
        <p class="tagline">UTHM Community's Marketplace</p>
    </div>

    <script>
        // Redirect ke home.php selepas 2.5 saat
        setTimeout(function() {
            window.location.href = 'home.php';
        }, 2500);
    </script>
</body>
</html>
