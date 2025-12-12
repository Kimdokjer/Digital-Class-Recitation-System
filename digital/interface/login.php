<?php
session_start();

require_once "../classes/database.php"; 

$login_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_login = trim($_POST['username'] ?? ''); 
    $input_password = trim($_POST['password'] ?? '');

    if (empty($input_login) || empty($input_password)) {
        $login_error = "Email/Student ID and password are required.";
    } else {
        try {
            $database = new Database();
            $conn = $database->connect();

            $stmt = $conn->prepare("
                SELECT u.user_id, u.username, u.password_hash, u.role, u.student_id, u.is_verified 
                FROM users u 
                LEFT JOIN students s ON u.student_id = s.student_id 
                WHERE (u.username = :login_identifier OR s.email = :login_identifier)
                LIMIT 1
            ");
            
            $stmt->bindParam(':login_identifier', $input_login);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($input_password, $user['password_hash'])) {
                
                if ($user['role'] === 'student' && $user['is_verified'] == 0) {
                    $login_error = "Your account is not verified. Please check your email for a verification code.";
                } else {
                    session_regenerate_id(true);

                    $_SESSION['loggedin'] = true;
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['student_id'] = $user['student_id'];

                    if ($_SESSION['user_role'] === 'admin') {
                        header("Location: dashboard.php");
                        exit;
                    } else {
                        header("Location: student_dashboard.php");
                        exit;
                    }
                } 
            } else {
                $login_error = "Invalid credentials.";
            }
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            $login_error = "An error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Digital Class</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

<style>
    /* --- THEME VARIABLES --- */
    :root {
        --primary-accent: #cc0000;       /* Crimson Red */
        --primary-accent-hover: #990000; /* Darker Red */
        --text-dark: #333333;
        --text-muted: #666666;
        --border-color: #dddddd;
        --bg-overlay: rgba(0, 0, 0, 0.65);
    }

    /* --- BASE RESET --- */
    * { box-sizing: border-box; margin: 0; padding: 0; }

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
        position: relative;
    }

    /* Dark Overlay for readability */
    body::before {
        content: "";
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(to bottom, rgba(50, 0, 0, 0.4), var(--bg-overlay));
        z-index: 0;
    }

    /* --- HOME BUTTON STYLING --- */
    .home-btn {
        position: absolute;
        top: 30px;
        left: 40px;
        z-index: 10; /* Ensures it sits above the overlay */
        color: #ffffff;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        font-size: 1rem;
        transition: all 0.3s ease;
        text-shadow: 0 2px 4px rgba(0,0,0,0.5); /* Improves readability on bg image */
    }

    .home-btn:hover {
        color: var(--primary-accent); /* Turns red on hover */
        transform: translateX(-5px); /* Slight movement effect */
    }

    /* --- LOGIN CARD CONTAINER --- */
    .login-wrapper {
        position: relative;
        z-index: 1; /* Above overlay */
        width: 100%;
        max-width: 420px;
        padding: 20px;
        animation: fadeUp 0.8s ease-out;
    }

    .login-container {
        background-color: #ffffff;
        padding: 40px;
        border-radius: 20px; 
        box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        text-align: center;
        border-top: 6px solid var(--primary-accent);
    }

    /* --- LOGO & HEADER --- */
    .logo-img {
        width: 100px;
        height: 100px;
        object-fit: cover;
        border-radius: 50%;
        margin-bottom: 20px;
        border: 4px solid #f2f2f2;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    h2 {
        color: var(--primary-accent);
        margin-bottom: 10px;
        font-weight: 700;
        font-size: 1.8rem;
    }

    .subtitle {
        color: var(--text-muted);
        margin-bottom: 30px;
        font-size: 0.9rem;
        font-weight: 500;
    }

    /* --- FORM ELEMENTS --- */
    .form-group {
        margin-bottom: 20px;
        text-align: left;
        position: relative;
    }

    label {
        display: block;
        margin-bottom: 8px;
        color: var(--text-dark);
        font-weight: 600;
        font-size: 0.9rem;
        margin-left: 5px;
    }

    .input-wrapper {
        position: relative;
    }

    .input-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 20px;
    }

    /* Modern Input Styling */
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 14px 14px 14px 45px; /* Left padding space for icon */
        border: 2px solid #f0f0f0;
        background-color: #f9f9f9;
        border-radius: 50px; /* Pill shape inputs */
        font-size: 0.95rem;
        color: #333;
        font-family: 'Montserrat', sans-serif;
        transition: all 0.3s ease;
    }

    input:focus {
        border-color: var(--primary-accent);
        background-color: #fff;
        outline: none;
        box-shadow: 0 4px 15px rgba(204, 0, 0, 0.1);
    }

    /* Toggle Password Eye */
    .toggle-password {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #999;
        font-size: 20px;
        transition: color 0.3s;
    }

    .toggle-password:hover {
        color: var(--primary-accent);
    }

    /* --- BUTTONS --- */
    button {
        background-color: var(--primary-accent);
        color: white;
        padding: 14px;
        border: none;
        border-radius: 50px; 
        cursor: pointer;
        font-size: 1rem;
        font-weight: 700;
        width: 100%;
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: all 0.3s ease;
        margin-top: 10px;
        box-shadow: 0 4px 15px rgba(204, 0, 0, 0.3);
    }

    button:hover {
        background-color: var(--primary-accent-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(204, 0, 0, 0.5);
    }

    /* --- LINKS & FOOTER --- */
    .forgot-wrapper {
        text-align: right;
        margin-top: 5px;
        margin-bottom: 25px;
        padding-right: 10px;
    }

    .forgot-link {
        font-size: 0.85rem;
        color: var(--text-muted);
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s;
    }

    .forgot-link:hover {
        color: var(--primary-accent);
        text-decoration: underline;
    }

    .register-link {
        margin-top: 30px;
        font-size: 0.9rem;
        color: var(--text-muted);
    }

    .register-link a {
        color: var(--primary-accent);
        text-decoration: none;
        font-weight: 700;
        transition: color 0.3s;
    }

    .register-link a:hover {
        text-decoration: underline;
        color: var(--primary-accent-hover);
    }

    /* --- ALERTS --- */
    .error-message {
        background-color: #fff2f2;
        color: var(--primary-accent);
        border: 1px solid #ffcccc;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    /* Animation */
    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Responsive adjustments */
    @media (max-width: 480px) {
        .login-container { padding: 30px 20px; }
        h2 { font-size: 1.5rem; }
        .home-btn { top: 20px; left: 20px; font-size: 0.9rem; }
    }
</style>
</head>
<body>

<a href="index.html" class="home-btn">
    <span class="material-icons">arrow_back</span>
    Back to Home
</a>

<div class="login-wrapper">
    <div class="login-container">
        <img src="../images/logowmsu.jpg" alt="WMSU Logo" class="logo-img">
        
        <h2>Welcome Back</h2>
        <p class="subtitle">Sign in to access your digital classroom</p>

        <?php if (!empty($login_error)): ?>
            <div class="error-message">
                <span class="material-icons" style="font-size:18px;">error_outline</span>
                <?php echo htmlspecialchars($login_error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            
            <div class="form-group">
                <label for="username">Student ID / Email</label>
                <div class="input-wrapper">
                    <span class="material-icons input-icon">person_outline</span>
                    <input type="text" id="username" name="username" placeholder="e.g. 2024001" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <span class="material-icons input-icon">lock_outline</span>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <span class="material-icons toggle-password" id="togglePassword">visibility_off</span>
                </div>
            </div>
            
            <div class="forgot-wrapper">
                <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit">Sign In</button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register_student.php">Register Now</a>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const passwordInput = document.getElementById('password');
        const togglePasswordButton = document.getElementById('togglePassword');

        if (togglePasswordButton && passwordInput) {
            togglePasswordButton.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.textContent = type === 'password' ? 'visibility_off' : 'visibility';
            });
        }
    });
</script>

</body>
</html>