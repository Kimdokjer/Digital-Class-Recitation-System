<?php
require_once '../classes/studentmanager.php';

$manager = new studentmanager();
$student_form_data = [];
$register_errors = [];
$register_success = false;
$courses = $manager->fetchAllCourses();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $student_form_data["studentId"] = trim(htmlspecialchars($_POST["studentId"] ?? ""));
    $student_form_data["password"] = $_POST["password"] ?? "";
    $student_form_data["confirmPassword"] = $_POST["confirmPassword"] ?? "";
    $student_form_data["lastName"] = trim(htmlspecialchars($_POST["lastName"] ?? ""));
    $student_form_data["firstName"] = trim(htmlspecialchars($_POST["firstName"] ?? ""));
    $student_form_data["middleName"] = trim(htmlspecialchars($_POST["middleName"] ?? ""));
    $student_form_data["classId"] = null;
    $student_form_data["gender"] = trim(htmlspecialchars($_POST["gender"] ?? ""));
    $student_form_data["genderSpecify"] = trim(htmlspecialchars($_POST["genderSpecify"] ?? ""));
    $student_form_data["birthDate"] = trim(htmlspecialchars($_POST["birthDate"] ?? ""));
    $student_form_data["course"] = trim(htmlspecialchars($_POST["course"] ?? ""));
    $student_form_data["terms"] = isset($_POST["terms"]);

    // --- Start Validation ---
    
    // UPDATED: Added is_numeric check
    if (empty($student_form_data["studentId"])) { 
        $register_errors["studentId"] = "Student ID is required (will be your Username)."; 
    } elseif (!is_numeric($student_form_data["studentId"])) {
        $register_errors["studentId"] = "Student ID must be numeric (e.g., 2024001).";
    }

    $password = $student_form_data["password"];
    if (empty($password)) {
        $register_errors["password"] = "A login password is required.";
    } else {
        if (strlen($password) < 8) {
            $register_errors["password"] = "Password must be at least 8 characters long.";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $register_errors["password"] = ($register_errors["password"] ?? "") . "<br>- Must contain at least one uppercase letter.";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $register_errors["password"] = ($register_errors["password"] ?? "") . "<br>- Must contain at least one lowercase letter.";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $register_errors["password"] = ($register_errors["password"] ?? "") . "<br>- Must contain at least one number.";
        }
        if (!preg_match('/^[A-Z]/', $password)) {
             $register_errors["password"] = ($register_errors["password"] ?? "") . "<br>- Must start with a capital letter.";
        }
        if(isset($register_errors["password"])) {
            $register_errors["password"] = ltrim($register_errors["password"], "<br>");
             if(strpos($register_errors["password"], "<br>") !== false || strlen($password) < 8) {
                  $register_errors["password"] = "Password does not meet requirements:" . "<br>" . $register_errors["password"];
             }
        }
    }
    
    if (empty($student_form_data["confirmPassword"])) { $register_errors["confirmPassword"] = "Please confirm your password."; }
    elseif ($password !== $student_form_data["confirmPassword"]) { $register_errors["confirmPassword"] = "Passwords do not match."; }
    if (empty($student_form_data["lastName"])) { $register_errors["lastName"] = "Last name is required."; }
    if (empty($student_form_data["firstName"])) { $register_errors["firstName"] = "First name is required."; }
    if (empty($student_form_data["gender"])) { $register_errors["gender"] = "Gender is required."; }
    if ($student_form_data["gender"] === 'Other' && empty($student_form_data["genderSpecify"])) { $register_errors["genderSpecify"] = "Please specify your gender."; }
    
    if (empty($student_form_data["birthDate"])) { 
        $register_errors["birthDate"] = "Birth date is required."; 
    } else {
        try {
            $birthDateObj = new DateTime($student_form_data["birthDate"]);
            $today = new DateTime('today');
            if ($birthDateObj > $today) {
                $register_errors["birthDate"] = "Birth date cannot be in the future.";
            }
        } catch (Exception $e) {
            $register_errors["birthDate"] = "Invalid date format.";
        }
    }

    if (empty($student_form_data["course"])) { $register_errors["course"] = "Course selection is required."; }
    if (!$student_form_data["terms"]) { $register_errors["terms"] = "You must agree to the Terms and Conditions."; }


    if (empty(array_filter($register_errors)) && !isset($register_errors['general'])) {

        $manager->studentId = $student_form_data["studentId"];
        $manager->password = $password;
        $manager->lastName = $student_form_data["lastName"];
        $manager->firstName = $student_form_data["firstName"];
        $manager->middleName = $student_form_data["middleName"];
        $manager->classId = null;

        $genderToSave = $student_form_data["gender"];
        if ($genderToSave === 'Other' && !empty($student_form_data["genderSpecify"])) {
            $genderToSave = $student_form_data["genderSpecify"];
        }
        $manager->gender = $genderToSave;

        $manager->birthDate = $student_form_data["birthDate"];
        $manager->courseName = $student_form_data["course"];

        if ($manager->addStudent()) {
            $register_success = true;
            $student_form_data = [];
        } else {
            $register_errors["general"] = "Registration failed. The Student ID might already be registered or a database error occurred.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f2f2f2; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px 0;}
        .container { background-color: #fff; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); width: 100%; max-width: 500px; box-sizing: border-box; text-align: center; }
        .container img.logo { width: 100px; height: auto; margin-bottom: 15px; }
        h2 { color: #d9534f; margin-top: 0; margin-bottom: 25px; text-align: center; }
        fieldset { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 4px; text-align: left; }
        legend { font-weight: bold; color: #d9534f; padding: 0 5px; }
        .form-group { margin-bottom: 15px; text-align: left; position: relative; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 1em; }
        .form-group input[type="password"] { padding-right: 40px; }
        .form-group input[type="text"], .form-group input[type="date"], .form-group select { padding-right: 10px; }
        .form-group input:focus, .form-group select:focus { border-color: #d9534f; outline: none; box-shadow: 0 0 0 2px rgba(217, 83, 79, 0.2); }
        .toggle-password { position: absolute; right: 10px; top: 38px; cursor: pointer; color: #757575; user-select: none; }
        .toggle-password:hover { color: #333; }
        .password-requirements { font-size: 0.8em; color: #666; margin-top: 5px; margin-bottom: 10px; text-align: left; padding-left: 5px;}
        .terms-group { margin-bottom: 5px; text-align: left; font-size: 0.9em; display: flex; align-items: flex-start; }
        .terms-group input[type="checkbox"] { margin-right: 8px; margin-top: 3px; flex-shrink: 0; }
        .terms-group label { font-weight: normal; color: #555; cursor: pointer; }
        .terms-group span.terms-link { color: #d9534f; text-decoration: underline; cursor: pointer; }
        .terms-group span.terms-link:hover { color: #c9302c; }
        button { background-color: #d9534f; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; width: 100%; transition: background-color 0.3s ease; margin-top: 10px; }
        button:hover { background-color: #c9302c; }
        .error-message { color: #d9534f; font-size: 0.85em; margin-top: 3px; display: block; text-align: left; min-height: 1.2em; line-height: 1.3;}
        .success { background-color: #D1FAE5; color: #10b981; padding: 12px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .error-general { background-color: #FFEBEE; color: #D32F2F; padding: 12px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        #genderSpecifyContainer { display: none; }
        .login-link { text-align: center; margin-top: 20px; }
        .login-link a { color: #d9534f; text-decoration: none; font-weight: bold;}
        .login-link a:hover { text-decoration: underline; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .modal-content { background-color: #fefefe; padding: 25px 30px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); width: 90%; max-width: 600px; position: relative; border-top: 5px solid #d9534f; max-height: 80vh; overflow-y: auto; }
        .modal-content h3 { margin-top: 0; color: #d9534f; text-align: center; margin-bottom: 20px;}
        .modal-close-button { color: #aaa; position: absolute; top: 10px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1; }
        .modal-close-button:hover { color: #333; }
        .modal-body { font-size: 0.9em; line-height: 1.6; color: #333; }
        .modal-body p { margin-bottom: 1em;}
        .modal-body h4 { color: #555; margin-top: 1.5em; margin-bottom: 0.5em; }
    </style>
</head>
<body>

<div class="container">
    <img src="../images/logowmsu.jpg" alt="WMSU Logo" class="logo">
    <h2>Student Account Registration</h2>

    <?php if ($register_success): ?>
        <p class="success">Registration successful! You can now <a href="login.php" style="color: #047857; text-decoration: none;">log in</a> with your Student ID.</p>
    <?php elseif (!empty($register_errors['general'])): ?>
        <p class="error-general"><?= htmlspecialchars($register_errors['general']) ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <fieldset>
            <legend>Login Details</legend>
            <div class="form-group">
                <label for="studentId">Student ID (Your Username)</label>
                <input type="text" name="studentId" id="studentId" value="<?= htmlspecialchars($student_form_data["studentId"] ?? "")?>" required>
                <span class="error-message"><?= $register_errors["studentId"] ?? ""?></span>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
                <span class="material-icons toggle-password" id="togglePassword">visibility_off</span>
                 <div class="password-requirements">
                     Must be at least 8 characters, start with a capital letter, and include lowercase & numbers.
                 </div>
                <span class="error-message"><?= $register_errors["password"] ?? ""?></span>
            </div>
            <div class="form-group">
                <label for="confirmPassword">Confirm Password</label>
                <input type="password" name="confirmPassword" id="confirmPassword" required>
                <span class="material-icons toggle-password" id="toggleConfirmPassword">visibility_off</span>
                <span class="error-message"><?= $register_errors["confirmPassword"] ?? ""?></span>
            </div>
        </fieldset>

        <fieldset>
            <legend>Personal Details</legend>
            <div class="form-group">
                <label for="lastName">Last Name</label>
                <input type="text" name="lastName" id="lastName" value="<?= htmlspecialchars($student_form_data["lastName"] ?? "")?>" required>
                <span class="error-message"><?= $register_errors["lastName"] ?? ""?></span>
            </div>
            <div class="form-group">
                <label for="firstName">First Name</label>
                <input type="text" name="firstName" id="firstName" value="<?= htmlspecialchars($student_form_data["firstName"] ?? "")?>" required>
                <span class="error-message"><?= $register_errors["firstName"] ?? ""?></span>
            </div>
            <div class="form-group">
                <label for="middleName">Middle Name (Optional)</label>
                <input type="text" name="middleName" id="middleName" value="<?= htmlspecialchars($student_form_data["middleName"] ?? "")?>">
                 <span class="error-message"></span>
            </div>
            <div class="form-group">
                <label for="gender">Gender</label>
                <select name="gender" id="gender" required>
                    <option value="">--Select Gender--</option>
                    <?php $genders = ['Male', 'Female', 'Other']; foreach ($genders as $g): ?>
                        <option value="<?= $g ?>" <?= (isset($student_form_data["gender"]) && $student_form_data["gender"] == $g) ? "selected" : "" ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="error-message"><?= $register_errors["gender"] ?? ""?></span>
            </div>
            <div class="form-group" id="genderSpecifyContainer">
                <label for="genderSpecify">Please Specify Gender</label>
                <input type="text" name="genderSpecify" id="genderSpecify" value="<?= htmlspecialchars($student_form_data["genderSpecify"] ?? "")?>">
                <span class="error-message"><?= $register_errors["genderSpecify"] ?? ""?></span>
            </div>
            <div class="form-group">
                <label for="birthDate">Birth Date</label>
                <input type="date" name="birthDate" id="birthDate" value="<?= htmlspecialchars($student_form_data["birthDate"] ?? "") ?>" required>
                <span class="error-message"><?= $register_errors["birthDate"] ?? "" ?></span>
            </div>
        </fieldset>

        <fieldset>
            <legend>Academic Details</legend>
            <div class="form-group">
                <label for="course">Course</label>
                <select name="course" id="course" required>
                    <option value="">--Select Course--</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['course_name']) ?>" <?= (isset($student_form_data["course"]) && $student_form_data["course"] == $c['course_name']) ? "selected" : "" ?>><?= htmlspecialchars($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="error-message"><?= $register_errors["course"] ?? ""?></span>
            </div>
        </fieldset>

        <div class="terms-group">
             <input type="checkbox" name="terms" id="terms" value="agree" <?= (isset($student_form_data["terms"]) && $student_form_data["terms"]) ? "checked" : "" ?> required>
             <label for="terms">I agree to the <span class="terms-link" id="termsLink">Terms and Conditions</span></label>
        </div>
         <span class="error-message"><?= $register_errors["terms"] ?? ""?></span>

        <button type="submit">Register Account</button>
        <p class="login-link">
            Already have an account? <a href="login.php">Log in here</a>
        </p>
    </form>
</div>

<div id="termsModal" class="modal">
    <div class="modal-content">
        <span class="modal-close-button">&times;</span>
        <h3>Terms and Conditions</h3>
        <div class="modal-body">
            <h4>1. Acceptance of Terms</h4>
            <p>By registering for and using the Digital Class Recitation system ("the System"), you agree to comply with and be bound by these Terms and Conditions. If you do not agree to these terms, please do not use the System.</p>
            <h4>2. Use of the System</h4>
            <p>The System is intended for educational purposes related to class recitation tracking within Western Mindanao State University (WMSU). You agree to use the System only for its intended purpose and in a manner that complies with all applicable laws and WMSU policies.</p>
            <h4>3. Account Responsibility</h4>
            <p>You are responsible for maintaining the confidentiality of your account login information (Student ID and password). You are responsible for all activities that occur under your account. Notify administrators immediately of any unauthorized use.</p>
            <h4>4. Data Privacy</h4>
            <p>Your personal information (name, student ID, course, etc.) and recitation scores will be stored in the System. This data will be used by authorized instructors and administrators for academic purposes only. WMSU is committed to protecting your data in accordance with relevant data privacy laws. Your data will not be shared with third parties without your consent, except as required by law or university policy.</p>
            <h4>5. Prohibited Conduct</h4>
            <p>You agree not to misuse the System. Misuse includes, but is not limited to: attempting unauthorized access, disrupting the system, uploading malicious content, sharing your account, or using the system for non-educational purposes.</p>
            <h4>6. Disclaimer</h4>
            <p>The System is provided "as is". While efforts are made to ensure accuracy and availability, WMSU makes no warranties regarding the system's performance, reliability, or suitability for any specific purpose.</p>
            <h4>7. Changes to Terms</h4>
            <p>WMSU reserves the right to modify these Terms and Conditions at any time. Continued use of the System after changes constitutes acceptance of the new terms.</p>
            <h4>8. Governing Law</h4>
            <p>These terms shall be governed by the laws of the Republic of the Philippines.</p>
            <p><em>By checking the box during registration, you acknowledge that you have read, understood, and agree to these Terms and Conditions.</em></p>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const genderSelect = document.getElementById('gender');
        const specifyContainer = document.getElementById('genderSpecifyContainer');
        const specifyInput = document.getElementById('genderSpecify');
        function toggleSpecifyField() {
            if (genderSelect.value === 'Other') { specifyContainer.style.display = 'block'; }
            else { specifyContainer.style.display = 'none'; specifyInput.value = ''; }
        }
        genderSelect.addEventListener('change', toggleSpecifyField);
        toggleSpecifyField();

        const modal = document.getElementById('termsModal');
        const link = document.getElementById('termsLink');
        const closeButton = modal.querySelector('.modal-close-button');
        const openModal = () => { modal.style.display = 'flex'; };
        const closeModal = () => { modal.style.display = 'none'; };
        if(link) { link.addEventListener('click', openModal); }
        if(closeButton) { closeButton.addEventListener('click', closeModal); }
        window.addEventListener('click', (event) => { if (event.target == modal) { closeModal(); } });
        window.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.style.display === 'flex') { closeModal(); } });

        const togglePassword = (inputId, toggleId) => {
            const inputField = document.getElementById(inputId);
            const toggleIcon = document.getElementById(toggleId);
            if (!inputField || !toggleIcon) return;
            
            toggleIcon.addEventListener('click', function () {
                const type = inputField.getAttribute('type') === 'password' ? 'text' : 'password';
                inputField.setAttribute('type', type);
                this.textContent = type === 'password' ? 'visibility_off' : 'visibility';
            });
        };
        
        togglePassword('password', 'togglePassword');
        togglePassword('confirmPassword', 'toggleConfirmPassword');
    });
</script>

</body>
</html>