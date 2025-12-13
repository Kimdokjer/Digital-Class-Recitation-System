<?php
require_once '../classes/studentmanager.php';

$manager = new studentmanager();
$student_form_data = [];
$register_errors = [];
$courses = $manager->fetchAllCourses();

// State variables
$showVerifyModal = false;
$verificationSuccess = false;
$verificationError = "";
$emailForVerification = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- HANDLE VERIFICATION ---
    if (isset($_POST['action']) && $_POST['action'] === 'verify') {
        $email = trim($_POST['verify_email']);
        $code = trim($_POST['verify_code']);
        $emailForVerification = $email;

        if (!empty($email) && !empty($code)) {
            $result = $manager->verifyRegistration($email, $code);

            if ($result === true) {
                $verificationSuccess = true;
            } else {
                $verificationError = $result; 
                $showVerifyModal = true;
            }
        } else {
            $verificationError = "Please enter the code.";
            $showVerifyModal = true;
        }
    }
    
    // --- HANDLE REGISTRATION ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'register') {
        $student_form_data = $_POST;
        
        // Validation
        if (empty($student_form_data["studentId"])) $register_errors["studentId"] = "Required";
        if (empty($student_form_data["email"])) $register_errors["email"] = "Required";
        if ($student_form_data["password"] !== $student_form_data["confirmPassword"]) $register_errors["confirmPassword"] = "Passwords mismatch";

        if (empty($register_errors)) {
            $manager->studentId = $student_form_data["studentId"];
            $manager->email = $student_form_data["email"];
            $manager->password = $student_form_data["password"];
            $manager->lastName = $student_form_data["lastName"];
            $manager->firstName = $student_form_data["firstName"];
            // Middle name is optional, defaults to empty string if not set
            $manager->middleName = $student_form_data["middleName"] ?? "";
            $manager->gender = ($student_form_data["gender"] === 'Other') ? $student_form_data["genderSpecify"] : $student_form_data["gender"];
            $manager->birthDate = $student_form_data["birthDate"];
            $manager->courseName = $student_form_data["course"];

            if ($manager->addStudent()) {
                $manager->sendVerificationEmail($manager->studentId, $manager->email, $manager->firstName);
                $showVerifyModal = true;
                $emailForVerification = $manager->email;
                $student_form_data = []; 
            } else {
                $register_errors["general"] = "Registration failed. ID or Email may exist.";
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
    <title>Student Registration | Digital Class</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    
    <style>
        /* --- THEME VARIABLES --- */
        :root {
            --primary-accent: #cc0000;       /* Crimson Red */
            --primary-accent-hover: #990000; /* Darker Red */
            --text-dark: #333333;
            --text-muted: #666666;
            --bg-overlay: rgba(0, 0, 0, 0.65);
        }

        /* --- BASE STYLES --- */
        * { box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', sans-serif;
            /* Background Image setup */
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

        /* Dark Overlay */
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to bottom, rgba(50, 0, 0, 0.4), var(--bg-overlay));
            z-index: 0;
        }

        /* --- CONTAINER CARD --- */
        .container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            z-index: 1; /* Above overlay */
            border-top: 6px solid var(--primary-accent);
            animation: fadeUp 0.6s ease-out;
        }

        /* --- HEADER & LOGO --- */
        .logo {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 3px solid #f2f2f2;
            margin-bottom: 15px;
            object-fit: cover;
        }

        h2 {
            color: var(--primary-accent);
            margin: 10px 0 20px;
            font-weight: 700;
        }

        /* --- PROGRESS BAR --- */
        .progress-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
            max-width: 300px;
            margin-left: auto;
            margin-right: auto;
        }

        .progress-bg {
            position: absolute;
            top: 50%; left: 0; width: 100%; height: 4px;
            background: #eee;
            z-index: 1;
            transform: translateY(-50%);
            border-radius: 2px;
        }

        .progress-bar {
            position: absolute;
            top: 50%; left: 0; width: 0%; height: 4px;
            background: var(--primary-accent);
            z-index: 2;
            transform: translateY(-50%);
            transition: width 0.4s ease;
            border-radius: 2px;
        }

        .step-dot {
            width: 14px; height: 14px;
            background: #e0e0e0;
            border-radius: 50%;
            z-index: 3;
            position: relative;
            transition: 0.3s;
            border: 2px solid #fff;
        }

        .step-dot.active {
            background: var(--primary-accent);
            transform: scale(1.3);
            box-shadow: 0 0 0 3px rgba(204,0,0,0.2);
        }

        /* --- FORM STEPS --- */
        .step { display: none; animation: slideIn 0.4s ease-out; }
        .step.active { display: block; }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- INPUTS --- */
        .form-group {
            text-align: left;
            margin-bottom: 15px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-dark);
            margin-bottom: 6px;
            font-weight: 600;
            margin-left: 10px;
        }

        .form-group input, 
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #f0f0f0;
            border-radius: 50px; /* Pill shape */
            background-color: #f9f9f9;
            font-size: 0.95rem;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.3s ease;
            color: #333;
        }

        .form-group input:focus, 
        .form-group select:focus {
            border-color: var(--primary-accent);
            background-color: #fff;
            outline: none;
            box-shadow: 0 4px 15px rgba(204, 0, 0, 0.1);
        }

        /* Toggle Password Eye */
        .toggle-pw {
            position: absolute;
            right: 15px;
            bottom: 12px; /* Adjusted for input height */
            color: #999;
            cursor: pointer;
            font-size: 20px;
            transition: color 0.3s;
        }
        .toggle-pw:hover { color: var(--primary-accent); }

        /* --- BUTTONS --- */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            align-items: center;
        }

        /* Circle Arrow Button */
        .btn-arrow {
            background: var(--primary-accent);
            color: white;
            border: none;
            width: 50px; height: 50px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(204, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .btn-arrow:hover {
            background: var(--primary-accent-hover);
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(204, 0, 0, 0.4);
        }

        /* Back Button */
        .btn-back {
            background: #f0f0f0;
            color: #555;
            box-shadow: none;
        }
        .btn-back:hover {
            background: #e0e0e0;
            color: #333;
            transform: translateY(-3px);
        }

        /* Full Width Submit */
        .btn-submit {
            background: var(--primary-accent);
            color: white;
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(204, 0, 0, 0.3);
        }
        .btn-submit:hover {
            background: var(--primary-accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(204, 0, 0, 0.4);
        }

        .error-msg {
            color: var(--primary-accent);
            background: #fff0f0;
            border: 1px solid #ffcccc;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85em;
            display: none;
            text-align: center;
            margin-top: 15px;
            font-weight: 600;
        }

        /* --- MODALS --- */
        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); /* Darker backdrop */
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            border-top: 6px solid var(--primary-accent);
            animation: fadeUp 0.4s ease-out;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }

        .modal-content h3 { color: var(--primary-accent); margin-top: 0; }

        /* Scrollbar for terms */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #999; }
        
        /* Links */
        a { color: var(--primary-accent); text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            font-size: 0.9rem;
        }
        
        /* Specific tweaks for date input */
        input[type="date"] {
            padding: 10px 15px; /* Adjust slightly for date picker icon */
        }
    </style>
</head>
<body>

<div class="container">
    <img src="../images/logowmsu.jpg" alt="Logo" class="logo">
    
    <?php if ($verificationSuccess): ?>
        <h2>Success!</h2>
        <div style="margin: 20px 0; color: #555;">
            <i class="material-icons" style="font-size: 60px; color: #4CAF50;">check_circle</i>
            <p style="margin-top: 10px;">Your account has been successfully verified.</p>
        </div>
        <a href="login.php" class="btn-submit" style="display:block; text-decoration:none; line-height: 1.5;">Go to Login</a>
    <?php else: ?>

        <div class="progress-container">
            <div class="progress-bg"></div>
            <div class="progress-bar" id="progressBar"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>

        <form method="POST" id="regForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="register">

            <div class="step active" id="step1">
                <h2>Personal Details</h2>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="lastName" id="lastName" required>
                </div>
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="firstName" id="firstName" required>
                </div>
                <div class="form-group">
                    <label>Middle Name <span style="font-weight:normal; color:#888; font-size:0.8em;">(Optional)</span></label>
                    <input type="text" name="middleName" id="middleName" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" id="gender" onchange="toggleGender()" required>
                        <option value="">Select Gender</option>
                        <option value="Man">Man</option>
                        <option value="Woman">Woman</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group" id="genderSpecifyBox" style="display:none;">
                    <input type="text" name="genderSpecify" id="genderSpecify" placeholder="Please specify">
                </div>
                <div class="form-group">
                    <label>Birth Date</label>
                    <input type="date" name="birthDate" id="birthDate" required max="<?= date('Y-m-d') ?>">
                </div>
                <p class="error-msg" id="error1"></p>

                <div class="nav-buttons" style="justify-content: flex-end;">
                    <button type="button" class="btn-arrow" onclick="nextStep(1)">
                        <i class="material-icons">arrow_forward</i>
                    </button>
                </div>
                <p style="margin-top:20px; font-size:0.9em; color:#666;">
                    Already registered? <a href="login.php">Login here</a>
                </p>
            </div>

            <div class="step" id="step2">
                <h2>Security</h2>
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="studentId" id="studentId" required placeholder="e.g. 2024001">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="email" required placeholder="email@gmail.com">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="password" required placeholder="Min 8 characters">
                    <i class="material-icons toggle-pw" onclick="togglePw('password', this)">visibility_off</i>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirmPassword" id="confirmPassword" required placeholder="Re-enter Password">
                    <i class="material-icons toggle-pw" onclick="togglePw('confirmPassword', this)">visibility_off</i>
                </div>
                <p class="error-msg" id="error2"></p>
                
                <div class="nav-buttons">
                    <button type="button" class="btn-arrow btn-back" onclick="prevStep(2)">
                        <i class="material-icons">arrow_back</i>
                    </button>
                    <button type="button" class="btn-arrow" onclick="nextStep(2)">
                        <i class="material-icons">arrow_forward</i>
                    </button>
                </div>
            </div>

            <div class="step" id="step3">
                <h2>Academic Info</h2>
                <div class="form-group">
                    <label>Course</label>
                    <select name="course" id="course" required>
                        <option value="">Select Course</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['course_name']) ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="checkbox-container">
                    <input type="checkbox" name="terms" id="terms" required style="width: auto; margin: 0;">
                    <label for="terms" style="display:inline; margin:0; font-weight:normal;">
                        I agree to the <span onclick="document.getElementById('termsModal').style.display='flex'" style="color:var(--primary-accent); cursor:pointer; text-decoration:underline;">Terms & Conditions</span>
                    </label>
                </div>
                <p class="error-msg" id="error3"></p>

                <button type="submit" class="btn-submit">Complete Registration</button>
                
                <div class="nav-buttons" style="justify-content: flex-start;">
                    <button type="button" class="btn-arrow btn-back" onclick="prevStep(3)">
                        <i class="material-icons">arrow_back</i>
                    </button>
                </div>
            </div>

        </form>
    <?php endif; ?>
</div>

<div id="verifyModal" class="modal" style="display: <?= $showVerifyModal ? 'flex' : 'none' ?>;">
    <div class="modal-content">
        <h3>Verify Your Email</h3>
        <p style="color:#666; margin-bottom:20px;">
            Enter the 6-digit code sent to<br><strong><?= htmlspecialchars($emailForVerification) ?></strong>
        </p>
        
        <?php if($verificationError): ?>
            <div class="error-msg" style="display:block; margin-bottom:15px;"><?= $verificationError ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="verify_email" value="<?= htmlspecialchars($emailForVerification) ?>">
            
            <input type="text" name="verify_code" maxlength="6" 
                   style="font-size: 1.5em; letter-spacing: 8px; text-align: center; width: 100%; padding: 10px; border: 2px solid #ddd; border-radius:10px; margin-bottom: 20px;" 
                   placeholder="000000" required>
            
            <button type="submit" class="btn-submit">Verify Account</button>
        </form>
    </div>
</div>

<div id="termsModal" class="modal">
    <div class="modal-content" style="text-align: left; max-width: 500px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Terms & Conditions</h3>
            <span onclick="document.getElementById('termsModal').style.display='none'" 
                  style="cursor:pointer; font-size:2em; line-height:0.5; color:#999;">&times;</span>
        </div>
        
        <div style="max-height: 300px; overflow-y: auto; font-size: 0.9em; line-height: 1.6; color: #444; padding-right: 10px;">
            <p>By registering for and using the Digital Class Recitation system ("the System"), you agree to comply with and be bound by these Terms and Conditions. If you do not agree to these terms, please do not use the System.</p>
            
            <h4 style="margin: 15px 0 5px; color:var(--primary-accent);">2. Use of the System</h4>
            <p>The System is intended for educational purposes related to class recitation tracking within Western Mindanao State University (WMSU). You agree to use the System only for its intended purpose and in a manner that complies with all applicable laws and WMSU policies.</p>
            
            <h4 style="margin: 15px 0 5px; color:var(--primary-accent);">3. Account Responsibility</h4>
            <p>You are responsible for maintaining the confidentiality of your account login information (Student ID and password). You are responsible for all activities that occur under your account. Notify administrators immediately of any unauthorized use.</p>
            
            <h4 style="margin: 15px 0 5px; color:var(--primary-accent);">4. Data Privacy</h4>
            <p>Your personal information (name, student ID, course, etc.) and recitation scores will be stored in the System. This data will be used by authorized instructors and administrators for academic purposes only. WMSU is committed to protecting your data in accordance with relevant data privacy laws. Your data will not be shared with third parties without your consent, except as required by law or university policy.</p>
            
            <h4 style="margin: 15px 0 5px; color:var(--primary-accent);">5. Prohibited Conduct</h4>
            <p>You agree not to misuse the System. Misuse includes, but is not limited to: attempting unauthorized access, disrupting the system, uploading malicious content, sharing your account, or using the system for non-educational purposes.</p>
            
            <h4 style="margin: 15px 0 5px; color:var(--primary-accent);">6. Disclaimer</h4>
            <p>The System is provided "as is". While efforts are made to ensure accuracy and availability, WMSU makes no warranties regarding the system's performance, reliability, or suitability for any specific purpose.</p>
            
            <p style="margin-top: 20px; font-style: italic; color: #888; border-top:1px solid #eee; padding-top:10px;">
                By checking the box during registration, you acknowledge that you have read, understood, and agree to these Terms and Conditions.
            </p>
        </div>
    </div>
</div>

<script>
    let currentStep = 1;
    const dots = document.querySelectorAll('.step-dot');
    const bar = document.getElementById('progressBar');

    function updateUI() {
        document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
        document.getElementById('step' + currentStep).classList.add('active');
        dots.forEach((d, index) => { d.classList.toggle('active', index < currentStep); });
        bar.style.width = ((currentStep - 1) * 50) + '%';
    }

    function nextStep(step) {
        const inputs = document.getElementById('step' + step).querySelectorAll('input, select');
        let valid = true;
        
        inputs.forEach(input => {
            if (input.hasAttribute('required') && !input.value) {
                input.style.borderColor = '#cc0000'; // Red border on error
                valid = false;
            } else {
                input.style.borderColor = '#f0f0f0'; // Reset
            }
        });

        // Validation for Step 1 (Personal Details)
        if (step === 1 && valid) {
            const bdateInput = document.getElementById('birthDate');
            if(bdateInput.value) {
                const selectedDate = new Date(bdateInput.value);
                const today = new Date();
                today.setHours(0,0,0,0); // normalize today for comparison
                
                if(selectedDate > today) {
                    showError(1, "Birth date cannot be in the future.");
                    bdateInput.style.borderColor = '#cc0000';
                    return; // Stop here
                }
            }
        }

        // Validation for Step 2 (Account Details)
        if (step === 2) {
            const pw = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;
            
            // Allow if inputs are valid so far
            if (valid) {
                 if (pw.length < 8) { 
                     showError(2, "Password must be at least 8 characters."); 
                     document.getElementById('password').style.borderColor = '#cc0000';
                     return; 
                 }
                 if (pw !== confirm) { 
                     showError(2, "Passwords do not match."); 
                     document.getElementById('confirmPassword').style.borderColor = '#cc0000';
                     return; 
                 }
            }
        }

        if (valid) {
            document.getElementById('error' + step).style.display = 'none';
            currentStep++;
            updateUI();
        } else {
            showError(step, "Please fill in all required fields.");
        }
    }

    function prevStep(step) {
        currentStep--;
        updateUI();
    }
    
    function showError(step, msg) {
        const el = document.getElementById('error' + step);
        el.innerText = msg;
        el.style.display = 'block';
    }

    function toggleGender() {
        const val = document.getElementById('gender').value;
        const box = document.getElementById('genderSpecifyBox');
        const input = document.getElementById('genderSpecify');
        if (val === 'Other') {
            box.style.display = 'block';
            input.setAttribute('required', 'required');
        } else {
            box.style.display = 'none';
            input.removeAttribute('required');
        }
    }
    
    function togglePw(id, icon) {
        const el = document.getElementById(id);
        const type = el.type === 'password' ? 'text' : 'password';
        el.type = type;
        icon.textContent = (type === 'password') ? 'visibility_off' : 'visibility';
    }
</script>

</body>
</html>