<?php
session_start();
require_once "../classes/studentmanager.php";

$step = 1; 
$error = "";
$success = "";
$email = $_SESSION['reset_email'] ?? ""; 

$manager = new studentmanager();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    
    if (isset($_POST['action']) && $_POST['action'] === 'send_code') {
        $email = trim($_POST['email']);
        $result = $manager->sendPasswordResetEmail($email);
        
        if ($result === true) {
            $_SESSION['reset_email'] = $email; 
            $step = 2; 
        } else {
            $error = $result;
        }
    }
    
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'verify_code') {
        $code = trim($_POST['code']);
        $_SESSION['reset_code'] = $code;
        $step = 3; 
    }

   
    elseif (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $pass = $_POST['password'];
        $confirm = $_POST['confirm_password'];
        $code = $_SESSION['reset_code'] ?? "";
        $email = $_SESSION['reset_email'] ?? "";
        
        if ($pass !== $confirm) {
            $error = "Passwords do not match.";
            $step = 3;
        } elseif (strlen($pass) < 8) {
            $error = "Password must be at least 8 characters.";
            $step = 3;
        } else {
            $result = $manager->resetStudentPassword($email, $code, $pass);
            if ($result === true) {
                $step = 4; 
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_code']);
            } else {
                $error = $result;
                $step = 2; 
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Digital Class</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    
    <style>
        
        :root {
            --primary-accent: #cc0000;       
            --primary-accent-hover: #990000; 
            --text-dark: #333333;
            --text-muted: #666666;
            --bg-overlay: rgba(0, 0, 0, 0.65);
        }

       
        * { box-sizing: border-box; }

        body {
            font-family: 'Montserrat', sans-serif;
            background-image: url('../images/landingbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            position: relative;
        }

       
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(50, 0, 0, 0.4), var(--bg-overlay));
            z-index: 0;
        }

       
        .container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            z-index: 1;
            border-top: 6px solid var(--primary-accent);
            animation: fadeUp 0.6s ease-out;
        }

       
        .logo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid #f2f2f2;
            margin-bottom: 20px;
            object-fit: cover;
        }

        h2 {
            color: var(--primary-accent);
            margin: 0 0 15px;
            font-weight: 700;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 30px;
            line-height: 1.6;
            font-size: 0.95rem;
        }

      
        .form-group {
            text-align: left;
            margin-bottom: 20px;
            position: relative;
        }

        .input-wrapper {
            position: relative;
        }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 14px 20px;
            border: 2px solid #f0f0f0;
            border-radius: 50px; 
            background-color: #f9f9f9;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
            color: #333;
            transition: all 0.3s ease;
        }

        input:focus {
            border-color: var(--primary-accent);
            background-color: #fff;
            outline: none;
            box-shadow: 0 4px 15px rgba(204, 0, 0, 0.1);
        }

      
        .code-input {
            letter-spacing: 10px;
            font-size: 1.5rem !important;
            text-align: center;
            font-weight: 700;
            color: var(--primary-accent) !important;
        }

       
        button {
            width: 100%;
            padding: 15px;
            background-color: var(--primary-accent);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(204, 0, 0, 0.3);
        }

        button:hover {
            background-color: var(--primary-accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(204, 0, 0, 0.4);
        }

      
        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: var(--primary-accent);
        }

        .error {
            background-color: #fff2f2;
            color: var(--primary-accent);
            border: 1px solid #ffcccc;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

      
        .toggle-pw {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            cursor: pointer;
            font-size: 20px;
            transition: color 0.3s;
        }
        .toggle-pw:hover { color: var(--primary-accent); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="container">
    <img src="../images/logowmsu.jpg" alt="WMSU Logo" class="logo">
    
    <?php if ($step === 1): ?>
        <h2>Forgot Password?</h2>
        <p>No worries! Enter your email address below and we will send you a 6-digit reset code.</p>
        
        <?php if ($error): ?>
            <div class="error">
                <span class="material-icons" style="font-size:18px;">error_outline</span>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="send_code">
            <div class="form-group">
                <input type="email" name="email" placeholder="e.g. student@gmail.com" required>
            </div>
            <button type="submit">Send Code</button>
        </form>

    <?php elseif ($step === 2): ?>
        <h2>Verify Identity</h2>
        <p>We've sent a code to <strong><?= htmlspecialchars($email) ?></strong>.<br>Please enter it below.</p>
        
        <?php if ($error): ?>
            <div class="error">
                <span class="material-icons" style="font-size:18px;">error_outline</span>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify_code">
            <div class="form-group">
                <input type="text" name="code" class="code-input" maxlength="6" placeholder="000000" pattern="\d{6}" required autocomplete="off">
            </div>
            <button type="submit">Verify Code</button>
        </form>
        
        <a href="forgot_password.php" class="back-link">Entered wrong email? Try again</a>

    <?php elseif ($step === 3): ?>
        <h2>Reset Password</h2>
        <p>Please create a strong new password for your account.</p>
        
        <?php if ($error): ?>
            <div class="error">
                <span class="material-icons" style="font-size:18px;">error_outline</span>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            
            <div class="form-group">
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" placeholder="New Password (Min 8 chars)" required minlength="8">
                    <span class="material-icons toggle-pw" onclick="togglePw('password', this)">visibility_off</span>
                </div>
            </div>
            
            <div class="form-group">
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm New Password" required>
                    <span class="material-icons toggle-pw" onclick="togglePw('confirm_password', this)">visibility_off</span>
                </div>
            </div>
            
            <button type="submit">Update Password</button>
        </form>

    <?php elseif ($step === 4): ?>
        <div style="margin: 20px 0;">
            <i class="material-icons" style="font-size: 80px; color: #4CAF50;">check_circle</i>
        </div>
        <h2 style="color: #4CAF50;">Password Reset!</h2>
        <p>Your password has been successfully updated. You can now access your account with your new credentials.</p>
        
        <div style="margin-top: 30px;">
            <a href="login.php" style="text-decoration: none;">
                <button>Go to Login</button>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($step !== 4): ?>
        <br>
        <a href="login.php" class="back-link">
            <i class="material-icons" style="font-size:14px; vertical-align:middle;">arrow_back</i> 
            Back to Login
        </a>
    <?php endif; ?>
</div>

<script>
    // Password Toggle Script for Step 3
    function togglePw(id, icon) {
        const el = document.getElementById(id);
        if(el) {
            const type = el.type === 'password' ? 'text' : 'password';
            el.type = type;
            icon.textContent = (type === 'password') ? 'visibility_off' : 'visibility';
        }
    }
</script>

</body>
</html>