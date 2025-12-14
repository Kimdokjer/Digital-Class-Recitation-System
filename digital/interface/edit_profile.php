<?php
session_start();
require_once "../classes/studentmanager.php";


if (!isset($_SESSION['loggedin']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$manager = new studentmanager();
$studentId = $_SESSION['student_id'];
$studentData = $manager->fetchStudent($studentId);


$successMsg = "";
$errorMsg = "";
$showVerifyModal = false;


if ($_SERVER["REQUEST_METHOD"] == "POST") {

 
    if (isset($_POST['action']) && $_POST['action'] === 'verify_change') {
        $code = trim($_POST['verify_code']);
        
        $result = $manager->verifyChangeCode($studentId, $code);
        if ($result === true) {

            $pending = $_SESSION['pending_profile_update'] ?? [];
            $newEmail = $pending['email'] ?? null;
            $newPass = $pending['password'] ?? null;

            if ($manager->updateStudentCredentials($studentId, $newEmail, $newPass)) {
                $successMsg = "Security credentials updated successfully.";
        
                unset($_SESSION['pending_profile_update']);
   
                $studentData = $manager->fetchStudent($studentId);
            } else {
                $errorMsg = "Failed to update credentials in database.";
            }
        } else {
            $errorMsg = $result; 
            $showVerifyModal = true; 
        }
    }
   
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        
 
        $lname = trim($_POST['lastName']);
        $fname = trim($_POST['firstName']);
        $mname = trim($_POST['middleName']);
        $nname = trim($_POST['nickname']);
        
       
        $genderSelection = $_POST['gender'];
        $gender = $genderSelection;
        if ($genderSelection === 'Other' && !empty($_POST['genderOther'])) {
            $gender = trim($_POST['genderOther']);
        }

        if ($manager->updateStudentBasicInfo($studentId, $lname, $fname, $mname, $nname, $gender)) {
            $successMsg = "Profile updated successfully.";
            $studentData = $manager->fetchStudent($studentId); 
        } else {
            $errorMsg = "Failed to update profile information.";
        }

      
        $newEmail = trim($_POST['email']);
        $newPass = $_POST['password'];
        $confirmPass = $_POST['confirmPassword'];
        
        $hasEmailChange = ($newEmail !== $studentData['email']);
        $hasPassChange = !empty($newPass);

        if ($hasEmailChange || $hasPassChange) {
           
            if ($hasPassChange && $newPass !== $confirmPass) {
                $errorMsg = "New passwords do not match. Security changes cancelled.";
            } else {
              
                $_SESSION['pending_profile_update'] = [
                    'email' => $hasEmailChange ? $newEmail : null,
                    'password' => $hasPassChange ? $newPass : null
                ];

                
                if ($manager->sendVerificationEmail($studentId, $studentData['email'], $studentData['firstname'])) {
                    $showVerifyModal = true;
                } else {
                    $errorMsg = "Failed to send verification email. Security changes cancelled.";
                }
            }
        }
    }
}


$currentGender = $studentData['gender'];
$isStandardGender = ($currentGender === 'Man' || $currentGender === 'Woman');
$selectValue = $isStandardGender ? $currentGender : 'Other';
$otherInputValue = $isStandardGender ? '' : $currentGender;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | Digital Class</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Montserrat', sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px; display: flex; justify-content: center; }
        .container { background-color: white; width: 100%; max-width: 700px; padding: 40px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); border-top: 5px solid #cc0000; }
        
        h2 { color: #cc0000; margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 15px; display: flex; align-items: center; }
        h2 .material-icons { margin-right: 10px; font-size: 32px; }
        
        .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9em; }
        .alert.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .form-section { margin-bottom: 30px; }
        .form-section h3 { font-size: 1.1em; color: #555; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85em; font-weight: 600; color: #333; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.95em; box-sizing: border-box; font-family: inherit; }
        .form-group input:focus, .form-group select:focus { border-color: #cc0000; outline: none; }
        .form-group input[disabled] { background-color: #f0f0f0; color: #777; cursor: not-allowed; }

        .row { display: flex; gap: 15px; }
        .col { flex: 1; }

        .btn-submit { background-color: #cc0000; color: white; border: none; padding: 15px 30px; border-radius: 50px; font-weight: bold; cursor: pointer; font-size: 1em; width: 100%; transition: background 0.3s; }
        .btn-submit:hover { background-color: #990000; }

        .btn-back { display: inline-flex; align-items: center; color: #666; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-back:hover { color: #cc0000; }
        .btn-back .material-icons { font-size: 18px; margin-right: 5px; }

    
        .modal { display: <?= $showVerifyModal ? 'flex' : 'none' ?>; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; text-align: center; max-width: 400px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-content h3 { color: #cc0000; margin-top: 0; }
        .code-input { font-size: 1.5em; letter-spacing: 5px; text-align: center; width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>

    <div class="container">
        <a href="student_dashboard.php" class="btn-back"><span class="material-icons">arrow_back</span> Back to Dashboard</a>
        
        <h2><span class="material-icons">edit</span> Edit Profile</h2>

        <?php if($successMsg): ?><div class="alert success"><?= $successMsg ?></div><?php endif; ?>
        <?php if($errorMsg): ?><div class="alert error"><?= $errorMsg ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-section">
                <h3>Personal Details</h3>
                
                <div class="form-group">
                    <label>Student ID (Cannot change)</label>
                    <input type="text" value="<?= htmlspecialchars($studentData['student_id']) ?>" disabled>
                </div>

                <div class="row">
                    <div class="col form-group">
                        <label>First Name</label>
                        <input type="text" name="firstName" value="<?= htmlspecialchars($studentData['firstname']) ?>" required>
                    </div>
                    <div class="col form-group">
                        <label>Last Name</label>
                        <input type="text" name="lastName" value="<?= htmlspecialchars($studentData['lastname']) ?>" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middleName" value="<?= htmlspecialchars($studentData['middlename'] ?? '') ?>">
                    </div>
                    <div class="col form-group">
                        <label>Nickname (Display Name)</label>
                        <input type="text" name="nickname" value="<?= htmlspecialchars($studentData['nickname'] ?? '') ?>" placeholder="e.g. John">
                    </div>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" id="genderSelect" onchange="toggleGender()">
                        <option value="Man" <?= $selectValue == 'Man' ? 'selected' : '' ?>>Man</option>
                        <option value="Woman" <?= $selectValue == 'Woman' ? 'selected' : '' ?>>Woman</option>
                        <option value="Other" <?= $selectValue == 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                    
                    <div id="otherGenderDiv" style="display: none; margin-top: 10px;">
                        <input type="text" name="genderOther" id="genderOther" 
                               placeholder="Please specify your gender" 
                               value="<?= htmlspecialchars($otherInputValue) ?>">
                    </div>
                </div>
            </div>

            <div class="form-section" style="border-top: 1px solid #eee; padding-top: 20px;">
                <h3>Security Settings</h3>
                <p style="font-size: 0.85em; color: #777; margin-bottom: 15px;">
                    <span class="material-icons" style="font-size:14px; vertical-align:middle;">lock</span> 
                    Changing email or password requires email verification.
                </p>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($studentData['email']) ?>" required>
                </div>

                <div class="row">
                    <div class="col form-group">
                        <label>New Password (Optional)</label>
                        <input type="password" name="password" placeholder="Leave empty to keep current">
                    </div>
                    <div class="col form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirmPassword" placeholder="Confirm new password">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">Save Changes</button>
        </form>
    </div>

    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <h3>Verify Changes</h3>
            <p>To secure your account, we sent a code to your current email: <br><strong><?= htmlspecialchars($studentData['email']) ?></strong></p>
            
            <form method="POST">
                <input type="hidden" name="action" value="verify_change">
                <input type="text" name="verify_code" class="code-input" placeholder="000000" maxlength="6" required>
                <button type="submit" class="btn-submit">Verify & Save</button>
            </form>
            <br>
            <a href="edit_profile.php" style="color:#777; font-size:0.9em;">Cancel</a>
        </div>
    </div>

    <script>
        function toggleGender() {
            const select = document.getElementById('genderSelect');
            const otherDiv = document.getElementById('otherGenderDiv');
            const otherInput = document.getElementById('genderOther');
            
            if (select.value === 'Other') {
                otherDiv.style.display = 'block';
                otherInput.setAttribute('required', 'required');
            } else {
                otherDiv.style.display = 'none';
                otherInput.removeAttribute('required');
            }
        }

       
        window.onload = function() {
            toggleGender();
        };
    </script>

</body>
</html>