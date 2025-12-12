<?php
require_once "../classes/studentmanager.php";

$message = "";
$messageType = ""; // 'success' or 'error'

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);

    if (!empty($email) && !empty($code)) {
        try {
            $db = new Database();
            $conn = $db->connect();

            // 1. Verify Code matches the Student via Email
            // We join user_verification with students table to match the email address
            $sql = "SELECT v.* FROM user_verification v
                    JOIN students s ON v.student_id = s.student_id
                    WHERE s.email = :email 
                    AND v.token = :code 
                    AND v.expires_at > NOW() 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([':email' => $email, ':code' => $code]);
            $verification = $stmt->fetch();

            if ($verification) {
                // 2. Code is valid. Verify the user.
                $sql_update = "UPDATE users SET is_verified = 1 WHERE student_id = :studentId";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([':studentId' => $verification['student_id']]);

                // 3. Delete used code
                $sql_delete = "DELETE FROM user_verification WHERE token = :code";
                $conn->prepare($sql_delete)->execute([':code' => $code]);

                $message = "Account verified successfully! You can now <a href='login.php'>Login</a>.";
                $messageType = "success";
            } else {
                $message = "Invalid code, email, or the code has expired.";
                $messageType = "error";
            }
        } catch (PDOException $e) {
            $message = "System error. Please try again later.";
            $messageType = "error";
        }
    } else {
        $message = "Please enter both your email and the code.";
        $messageType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f7f6; margin: 0; }
        .verify-container { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; border-top: 5px solid #D32F2F; }
        h2 { color: #333; margin-bottom: 20px; }
        input[type="text"], input[type="email"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 12px; background-color: #D32F2F; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s; margin-top: 10px; }
        button:hover { background-color: #b71c1c; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; font-size: 14px; }
        .message.error { background-color: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
        .message.success { background-color: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        a { color: #D32F2F; text-decoration: none; font-weight: bold; }
        .back-link { display: block; margin-top: 20px; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>

<div class="verify-container">
    <h2>Verify Account</h2>
    
    <?php if ($message): ?>
        <div class="message <?= $messageType ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($messageType !== 'success'): ?>
        <form method="POST" action="">
            <input type="email" name="email" placeholder="Enter your email address" required 
                   value="<?= isset($_GET['email']) ? htmlspecialchars($_GET['email']) : '' ?>">
            
            <input type="text" name="code" placeholder="Enter 6-digit Code" maxlength="6" required pattern="\d{6}" title="Please enter the 6-digit code">
            
            <button type="submit">Verify Code</button>
        </form>
    <?php endif; ?>

    <a href="login.php" class="back-link">Back to Login</a>
</div>

</body>
</html>
