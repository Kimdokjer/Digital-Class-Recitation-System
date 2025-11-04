<?php
session_start();

require_once "../classes/database.php";

$login_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_username = trim($_POST['username'] ?? '');
    $input_password = trim($_POST['password'] ?? '');

    if (empty($input_username) || empty($input_password)) {
        $login_error = "Username and password are required.";
    } else {
        try {
            $database = new Database();
            $conn = $database->connect();

            $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, student_id FROM users WHERE username = :username LIMIT 1");
            $stmt->bindParam(':username', $input_username);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($input_password, $user['password_hash'])) {
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
            } else {
                $login_error = "Invalid username or password.";
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
<title>Login Page</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f2f2f2;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        margin: 0;
        padding: 20px;
        box-sizing: border-box;
    }
    .login-container {
        background-color: #fff;
        padding: 40px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        width: 100%;
        max-width: 380px;
        text-align: center;
        box-sizing: border-box;
    }
    .login-container img {
        width: 150px;
        height: auto;
        margin-bottom: 20px;
    }
    h2 {
        color: #d9534f;
        margin-bottom: 30px;
    }
    .form-group {
         margin-bottom: 20px;
         text-align: left;
         position: relative;
    }
    label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: bold;
    }
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 10px;
        padding-right: 40px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 1em;
        color: #333;
        box-sizing: border-box;
    }
     input:focus {
         border-color: #d9534f;
         outline: none;
         box-shadow: 0 0 0 2px rgba(217, 83, 79, 0.2);
     }
    .toggle-password {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-10%);
        cursor: pointer;
        color: #757575;
        user-select: none;
    }
    .toggle-password:hover {
        color: #333;
    }
    button {
        background-color: #d9534f;
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 1.1em;
        width: 100%;
        transition: background-color 0.3s ease;
        box-sizing: border-box;
        margin-top: 10px;
    }
    button:hover {
        background-color: #c9302c;
    }
    .error-message {
        color: #d9534f;
        margin-bottom: 15px;
        font-weight: bold;
        text-align: center;
    }
    .register-link {
        margin-top: 20px;
    }
    .register-link a {
        color: #d9534f;
        text-decoration: none;
        font-weight: bold;
    }
    .register-link a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>

<div class="login-container">
    <img src="../images/logowmsu.jpg" alt="WMSU Logo">
    <h2>Login</h2>

    <?php if (!empty($login_error)): ?>
        <p class="error-message"><?php echo htmlspecialchars($login_error); ?></p>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" placeholder="Enter username or Student ID" required>
        </div>

        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" placeholder="Enter password" required>
            <span class="material-icons toggle-password" id="togglePassword">visibility_off</span>
        </div>

        <button type="submit">Login</button>
    </form>

    <p class="register-link">Don't have an account?
        <a href="register_student.php">Register here</a>
    </p>
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