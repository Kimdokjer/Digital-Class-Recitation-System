<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once "../classes/studentmanager.php";
$manager = new studentmanager();

$student_form_data = [];
$add_student_errors = [];
$score_errors = [];
$score_success = false;
$maxScore = 10;
$totalPossibleRecitations = 10;

$sort_order = $_GET['sort'] ?? 'asc';
$sort_column = $_GET['sort_col'] ?? 'lastName';
$selected_class_filter = $_GET['class_filter'] ?? 'all';
$search_query = trim($_GET['search_query'] ?? '');

$dashboard_stats = [];
try {
    $conn = $manager->connect();
    $stmt_students = $conn->query("SELECT COUNT(student_id) AS totalStudents FROM students");
    $dashboard_stats['totalStudents'] = $stmt_students->fetchColumn();
    $stmt_recitations = $conn->query("SELECT COUNT(recit_id) AS totalRecitations FROM recits");
    $dashboard_stats['totalRecitations'] = $stmt_recitations->fetchColumn();
    $stmt_classes = $conn->query("SELECT COUNT(class_id) AS totalClasses FROM class_sections");
    $dashboard_stats['totalClasses'] = $stmt_classes->fetchColumn();
} catch (PDOException $e) {
    $dashboard_stats['error'] = "Failed to load dashboard stats. (DB Error: " . $e->getMessage() . ")";
}

$allClasses = $manager->fetchAllClasses();
$classes = $allClasses;
if (empty($classes)) {
    $add_student_errors['general_classes'] = "Warning: No classes/subjects found. Please add subjects and class sections.";
}

$students_for_enrollment = $manager->viewStudents('lastName', 'ASC', 'all', '');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'enroll_student') {
    $enroll_errors = [];
    $studentIdToEnroll = trim(htmlspecialchars($_POST["studentIdToEnroll"] ?? ""));
    $classIdToEnroll = trim(htmlspecialchars($_POST["classIdToEnroll"] ?? ""));
    if (empty($studentIdToEnroll)) { $enroll_errors["studentIdToEnroll"] = "Student ID is required."; }
    if (empty($classIdToEnroll)) { $enroll_errors["classIdToEnroll"] = "Class/Subject selection is required."; }

    if (empty(array_filter($enroll_errors))) {
        try {
            $conn = $manager->connect();
            $stmtCheckStudent = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
            $stmtCheckStudent->execute([$studentIdToEnroll]);
            
            if ($stmtCheckStudent->rowCount() === 0) {
                 $enroll_errors["general"] = "Error: Student ID not found in the database.";
            } else {
                $stmtCheckEnroll = $conn->prepare("SELECT * FROM student_enrollments WHERE student_id = ? AND class_id = ?");
                $stmtCheckEnroll->execute([$studentIdToEnroll, $classIdToEnroll]);
                
                if ($stmtCheckEnroll->rowCount() > 0) {
                    $enroll_errors["general"] = "Student is already enrolled in this class/subject.";
                } else {
                    $insert_sql = "INSERT INTO student_enrollments (student_id, class_id) VALUES (:studentId, :classId)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bindParam(':studentId', $studentIdToEnroll);
                    $insert_stmt->bindParam(':classId', $classIdToEnroll);
                    
                    if ($insert_stmt->execute()) {
                        header("Location: " . $_SERVER['PHP_SELF'] . "?section=view_students&enroll_success=true" . ($selected_class_filter !== 'all' ? '&class_filter=' . urlencode($selected_class_filter) : ''));
                        exit;
                    } else {
                        $enroll_errors["general"] = "Failed to enroll student due to a database error.";
                    }
                }
            }
        } catch (PDOException $e) {
             $enroll_errors["general"] = "Database error during enrollment: " . $e->getMessage();
        }
    }
    $add_student_errors = array_merge($add_student_errors, $enroll_errors);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'record_recitation') {
    $studentId = $_POST['studentId'] ?? null;
    $subjectCode = $_POST['subjectCode'] ?? null;
    $score = $_POST['score'] ?? null;
    $date = $_POST['date'] ?? date('Y-m-d');
    $mode = $_POST['mode'] ?? null;
    if (empty($studentId) || !$manager->fetchStudent($studentId)) { $score_errors['general'] = "Invalid student ID."; }
    elseif (empty($subjectCode)) { $score_errors['general'] = "Invalid subject code."; }
    elseif (!is_numeric($score) || $score < 0 || $score > $maxScore) { $score_errors['score'] = "Score must be between 0 and " . $maxScore . "."; }
    elseif (strtolower($mode) !== 'normal' && strtolower($mode) !== 'fair') { $score_errors['mode'] = "Invalid mode selected."; }
    if (empty($score_errors)) {
        $manager->recitationStudentId = $studentId;
        $manager->recitationSubjectCode = $subjectCode;
        $manager->recitationDate = $date;
        $manager->recitationScore = (float)$score;
        $manager->recitationMode = strtolower($mode);
        if ($manager->addRecitation()) {
            $score_success = true;
            $redirect_url = $_SERVER['PHP_SELF'] . "?section=view_students&rec_success=true";
            if ($selected_class_filter !== 'all') { $redirect_url .= "&class_filter=" . urlencode($selected_class_filter); }
            if (!empty($search_query)) { $redirect_url .= "&search_query=" . urlencode($search_query); }
            header("Location: " . $redirect_url);
            exit;
        } else {
            $score_errors['general'] = "Failed to save recitation due to a database error.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'confirm_delete_student') {
    $studentIdToDelete = $_POST['studentIdToDeleteConfirmed'] ?? null;
    if ($studentIdToDelete) {
        if ($manager->deleteStudent($studentIdToDelete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=delete_student&delete_success=true");
            exit;
        } else {
            $add_student_errors['general_delete'] = "Failed to delete student. (Database error or student not found)";
        }
    } else {
        $add_student_errors['general_delete'] = "No student ID provided for deletion.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'withdraw_student') {
    $studentIdToWithdraw = $_POST['student_id_to_withdraw'] ?? null;
    $classIdToWithdraw = $_POST['class_id_to_withdraw'] ?? null;
    $withdraw_reason = trim(htmlspecialchars($_POST['withdraw_reason'] ?? 'No reason provided')); 

    if ($studentIdToWithdraw && $classIdToWithdraw) {
        if ($manager->withdrawStudentFromClass($studentIdToWithdraw, $classIdToWithdraw)) {
            $redirect_url = $_SERVER['PHP_SELF'] . "?section=view_students&withdraw_success=true";
            if ($selected_class_filter !== 'all') { $redirect_url .= "&class_filter=" . urlencode($selected_class_filter); }
            if (!empty($search_query)) { $redirect_url .= "&search_query=" . urlencode($search_query); }
            header("Location: " . $redirect_url);
            exit;
        } else {
            $add_student_errors['general'] = "Failed to withdraw student from class.";
        }
    } else {
        $add_student_errors['general'] = "Invalid student or class ID for withdrawal.";
    }
}

$students = $manager->viewStudents($sort_column, $sort_order, $selected_class_filter, $search_query);
$students_json = json_encode(array_values($students));
$current_section = $_GET['section'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Digital Recitation</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f4f7f6; display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: #D32F2F; color: white; padding: 20px 0; display: flex; flex-direction: column; flex-shrink: 0; height: 100vh; position: sticky; top: 0; box-shadow: 2px 0 8px rgba(0,0,0,0.2); overflow-y: auto; }
        .sidebar-header { text-align: center; padding: 10px 15px; margin-bottom: 30px; }
        .sidebar-header img { width: 90px; height: 90px; border-radius: 50%; background-color: white; padding: 5px; object-fit: cover; border: 3px solid #FFCDD2; margin-bottom: 10px; }
        .sidebar-header h2 { color: #FFEBEE; margin: 0; font-size: 1.4em; font-weight: 600; }
        .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav ul li a { display: flex; align-items: center; padding: 12px 20px; color: white; text-decoration: none; font-size: 1.05em; transition: background-color 0.3s, border-left 0.3s; }
        .sidebar-nav ul li a .material-icons { margin-right: 15px; font-size: 24px; color: white; }
        .sidebar-nav ul li a:hover { background-color: #B71C1C; border-left: 5px solid #FFEBEE; padding-left: 15px; }
        .sidebar-nav ul li a.active { background-color: #B71C1C; border-left: 5px solid white; padding-left: 15px; font-weight: bold; }
        .sidebar-nav ul li a.delete-link:hover { background-color: #c62828; border-left-color: #FF8A80; }
        .main-wrapper { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }
        .top-navbar { background-color: #ffffff; padding: 15px 25px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; box-sizing: border-box; }
        .top-navbar h1 { margin: 0; color: #333; font-size: 1.9em; font-weight: 600; }
        .user-menu { display: flex; align-items: center; }
        .user-menu .material-icons { font-size: 28px; margin-right: 10px; color: #757575; }
        .user-menu span { font-weight: 600; color: #555; font-size: 1.1em; }
        .logout-btn { background-color: #D32F2F; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.9em; font-weight: bold; margin-left: 15px; text-decoration: none; transition: background-color 0.3s ease; }
        .logout-btn:hover { background-color: #C62828; }
        .main-content { padding: 30px; flex-grow: 1; background-color: #f8f8f8; box-sizing: border-box; }
        .container { max-width: 1200px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .form-container { background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); max-width: 650px; width: 100%; box-sizing: border-box; margin: 20px auto; }
        .content-header h2, h1.section-title { margin: 0 0 25px 0; font-size: 1.8em; color: #333; border-bottom: 2px solid #D32F2F; padding-bottom: 10px; text-align: center; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; }
        .card { background-color: #ffffff; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); padding: 25px; text-align: center; transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: default; border-top: 5px solid #D32F2F; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); }
        .card .material-icons { font-size: 50px; color: #D32F2F; margin-bottom: 10px; }
        .card h3 { margin-top: 0; margin-bottom: 10px; font-size: 1.1em; color: #555;}
        .card .card-number { font-size: 2.3em; font-weight: bold; color: #333; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 25px; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        th, td { border: 1px solid #e0e0e0; padding: 12px 15px; text-align: left; font-size: 0.95em; vertical-align: middle;}
        th { background-color: #D32F2F; color: white; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        td { color: #333; }
        th a { color: white; text-decoration: none; }
        th a:hover { text-decoration: underline; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; font-size: 1em; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 1em; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #D32F2F; outline: none; box-shadow: 0 0 0 2px rgba(211, 47, 47, 0.2); }
        .form-error { color: #D32F2F; font-size: 0.85em; margin-top: 5px; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 1em; font-weight: bold; transition: background-color 0.3s ease; text-align: center; display: inline-block; text-decoration: none;}
        .btn-small { padding: 5px 10px; font-size: 0.85em; font-weight: normal; }
        .btn-submit { background-color: #D32F2F; color: white; }
        .btn-submit:hover { background-color: #C62828; }
        .btn-delete { background-color: #D32F2F; color: white; }
        .btn-delete:hover { background-color: #C62828; }
        .btn-picker { background-color: #D32F2F; color: white; margin: 0 10px 15px; }
        .btn-picker:hover { background-color: #C62828; }
        .btn-record { background-color: #D32F2F; color: white; margin-top: 15px; }
        .btn-record:hover { background-color: #C62828; }
        .btn-cancel { background-color: #6c757d; color: white; margin-left: 10px; }
        .btn-cancel:hover { background-color: #5a6268; }
        .btn-search { background-color: #D32F2F; color: white; padding: 10px 15px; font-size: 0.9em; }
        .btn-search:hover { background-color: #C62828; }
        .btn-withdraw { background-color: #f44336; color: white; }
        .btn-withdraw:hover { background-color: #d32f2f; }
        button:disabled, .btn:disabled { background-color: #cccccc; cursor: not-allowed; opacity: 0.7; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .modal-content { background-color: #fefefe; padding: 30px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); width: 90%; max-width: 500px; position: relative; border-top: 5px solid #D32F2F; }
        .modal-content h2, .modal-content h3 { margin-top: 0; color: #D32F2F; text-align: center; margin-bottom: 20px;}
        .close-button, .modal-close-button { color: #aaa; position: absolute; top: 10px; right: 20px; font-size: 30px; font-weight: bold; cursor: pointer; line-height: 1; }
        .close-button:hover, .modal-close-button:hover { color: #333; }
        .modal-body { margin-bottom: 20px; text-align: center; line-height: 1.5; }
        .modal-body strong { color: #333; }
        .modal-footer { text-align: center; margin-top: 20px; }
        .modal-submit-button { width: 100%; margin-top: 20px;}
        .filter-control { margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;}
        .filter-control .filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap;}
        .filter-control label { font-weight: bold; color: #333; }
        .filter-control select { padding: 10px; border: 1px solid #ccc; border-radius: 4px; background-color: white; }
        .filter-control input[type="search"] { padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        #picker-toggle-button { background-color: #D32F2F; color: white; padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 1em; font-weight: bold; display: block; width: fit-content; margin: 0 auto 25px auto; }
        #picker-toggle-button:hover { background-color: #C62828; }
        #randomizer-control { border: 2px solid #FFCDD2; padding: 25px; border-radius: 10px; text-align: center; margin-bottom: 30px; background-color: #FFEBEE; display: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        #randomizer-control h2 { margin-top: 0; color: #D32F2F; }
        #result-display { margin: 20px 0; font-size: 1.1em; min-height: 80px; display: flex; align-items: center; justify-content: center; flex-direction: column;}
        .info-text { font-size: 0.9em; color: #555; margin-top: 15px; }
        .error-message, .error-general { color: #B71C1C; font-weight: bold; background-color: #FFEBEE; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 20px; border: 1px solid #EF9A9A; }
        .success-message { color: #2E7D32; font-weight: bold; background-color: #E8F5E9; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 20px; border: 1px solid #A5D6A7; }
        .no-records { text-align: center; color: #757575; font-style: italic; padding: 20px; background-color: #fff; border-radius: 8px; margin-top: 20px; border: 1px dashed #ccc; }
        hr.form-separator { border: 0; height: 1px; background-color: #eee; margin: 30px 0; }
        .status-badge { display: inline-block; padding: 0.3em 0.6em; font-size: 0.8em; font-weight: bold; border-radius: 4px; line-height: 1; }
        .status-enrolled { background-color: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .status-not-enrolled { background-color: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
        @media (max-width: 992px) {
             body { flex-direction: column; }
             .sidebar { width: 100%; height: auto; position: static; flex-direction: row; justify-content: space-around; padding: 5px 0; box-shadow: 0 2px 5px rgba(0,0,0,0.1); order: 2; overflow-y: hidden; }
             .sidebar-header { display: none; }
             .sidebar-nav { width: 100%; }
             .sidebar-nav ul { display: flex; justify-content: space-around; flex-wrap: wrap; }
             .sidebar-nav ul li { margin-bottom: 0; flex-basis: auto; text-align: center; }
             .sidebar-nav ul li a { padding: 8px 5px; font-size: 0.8em; flex-direction: column; align-items: center; border-left: none !important; }
              .sidebar-nav ul li a:hover, .sidebar-nav ul li a.active { background-color: #B71C1C; padding-left: 5px; }
             .sidebar-nav ul li a .material-icons { margin-right: 0; margin-bottom: 3px; font-size: 20px; }
             .top-navbar { width: 100%; order: 1; }
             .main-content { width: 100%; padding: 20px; order: 3; }
             .top-navbar h1 { font-size: 1.5em; }
        }
        @media (max-width: 768px) {
            .filter-control { flex-direction: column; align-items: stretch; }
            .filter-control .filter-group { justify-content: space-between; }
            .filter-control .search-group { width: 100%; margin-top: 10px; display: flex; }
            .filter-control input[type="search"] { flex-grow: 1; }
        }
        @media (max-width: 600px) {
             .top-navbar { flex-direction: column; gap: 10px; padding: 10px 15px; }
             .user-menu { margin-top: 10px; }
             .main-content { padding: 15px; }
             h1.section-title { font-size: 1.6em; }
             .card-grid { grid-template-columns: 1fr; }
             .form-container { padding: 20px; }
             .modal-content { padding: 20px; }
             th, td { padding: 8px 10px; font-size: 0.9em;}
             .modal-footer button, .modal-footer .btn { width: 100%; margin: 5px 0 !important; }
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
             <img src="../images/logowmsu.jpg" alt="WMSU Logo">
             <h2>Digital Class Recitation</h2>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="?section=dashboard" class="<?= ($current_section == 'dashboard' ? 'active' : '') ?>"><span class="material-icons">dashboard</span>DASHBOARD</a></li>
                <li><a href="?section=view_students" class="<?= ($current_section == 'view_students' ? 'active' : '') ?>"><span class="material-icons">people</span>VIEW STUDENTS</a></li>
                <li><a href="?section=add_student" class="<?= ($current_section == 'add_student' ? 'active' : '') ?>"><span class="material-icons">person_add</span>ENROLL STUDENT</a></li>
                <li><a href="?section=recitation_records" class="<?= ($current_section == 'recitation_records' ? 'active' : '') ?>"><span class="material-icons">assignment</span>RECITATION RECORDS</a></li>
                <li><a href="?section=delete_student" class="delete-link <?= ($current_section == 'delete_student' ? 'active' : '') ?>"><span class="material-icons">person_remove</span>DELETE STUDENT</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-wrapper">
         <nav class="top-navbar">
            <h1><?= ucfirst(str_replace('_', ' ', $current_section)) ?></h1>
            <div class="user-menu">
                <span class="material-icons">account_circle</span>
                <span>Professor: <?= htmlspecialchars($_SESSION['username'] ?? 'Prof') ?></span>
                 <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </nav>

        <main class="main-content">
             <?php if ($current_section === 'dashboard'): ?>
                 <div class="container">
                     <h1 class="section-title">System Overview</h1>
                     <?php if (isset($dashboard_stats['error'])): ?><p class="error-message"><?= $dashboard_stats['error'] ?></p><?php endif; ?>
                     <div class="card-grid">
                         <div class="card"><span class="material-icons">group</span><h3>Total Students</h3><p class="card-number"><?= htmlspecialchars($dashboard_stats['totalStudents'] ?? 'N/A') ?></p></div>
                         <div class="card"><span class="material-icons">assessment</span><h3>Total Recitations</h3><p class="card-number"><?= htmlspecialchars($dashboard_stats['totalRecitations'] ?? 'N/A') ?></p></div>
                         <div class="card"><span class="material-icons">class</span><h3>Active Classes</h3><p class="card-number"><?= htmlspecialchars($dashboard_stats['totalClasses'] ?? 'N/A') ?></p></div>
                         <div class="card"><span class="material-icons">score</span><h3>System Max Score</h3><p class="card-number"><?= htmlspecialchars($maxScore) ?></p></div>
                     </div>
                 </div>
            <?php elseif ($current_section === 'recitation_records'): ?>
                 <div class="container">
                     <h1 class="section-title">All Recitation Records</h1>
                     
                     <div class="filter-control">
                         <form method="GET" action="" style="width: 100%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                             <input type="hidden" name="section" value="recitation_records">
                             <div class="filter-group">
                                 <label for="rec_class_filter">Filter by Class:</label>
                                 <select name="class_filter" id="rec_class_filter" onchange="this.form.submit()">
                                     <option value="all">All Classes/Subjects</option>
                                     <?php
                                     $rec_selected_class_filter = $_GET['class_filter'] ?? 'all';
                                     $unique_classes_recs = [];
                                     foreach ($allClasses as $class) { $unique_classes_recs[$class['class_name']] = $class['subject_name_display'] . ' (' . $class['class_name'] . ')'; }
                                     foreach ($unique_classes_recs as $className => $subjectDisplay): ?>
                                         <option value="<?= htmlspecialchars($className) ?>" <?= ($rec_selected_class_filter === $className) ? 'selected' : '' ?>><?= htmlspecialchars($subjectDisplay) ?></option>
                                     <?php endforeach; ?>
                                 </select>
                             </div>
                             <div class="search-group" style="display: flex; gap: 5px;">
                                 <input type="search" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search ID or Name...">
                                 <button type="submit" class="btn btn-search"><span class="material-icons">search</span></button>
                             </div>
                         </form>
                     </div>

                    <?php
                    try {
                        $conn = $manager->connect();
                        $sql = "SELECT r.date, r.score, r.mode, s.student_id, s.firstname, s.lastname, cs.class_name, sub.subject_name 
                                FROM recits r 
                                JOIN students s ON r.student_id = s.student_id 
                                LEFT JOIN student_enrollments se ON s.student_id = se.student_id
                                LEFT JOIN class_sections cs ON se.class_id = cs.class_id
                                LEFT JOIN subjects sub ON r.subject_code = sub.subject_code";
                        $where = [];
                        $params = [];

                        if ($rec_selected_class_filter !== 'all') {
                            $where[] = "cs.class_name = :className";
                            $params[':className'] = $rec_selected_class_filter;
                        }
                        
                        if (!empty($search_query)) {
                            $where[] = "(s.student_id LIKE :search OR s.firstname LIKE :search OR s.lastname LIKE :search OR CONCAT(s.firstname, ' ', s.lastname) LIKE :search)";
                            $params[':search'] = "%" . $search_query . "%";
                        }
                        
                        if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
                         $sql .= " GROUP BY r.recit_id";
                        $sql .= " ORDER BY r.date DESC, s.lastname ASC";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->execute($params);
                        $allRecs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) { $allRecs = []; echo '<p class="error-message">Failed to fetch data: ' . $e->getMessage() . '</p>'; }
                    ?>
                     
                    <?php if (!empty($allRecs)): ?>
                        <table><thead><tr><th>Date</th><th>ID</th><th>Name</th><th>Class</th><th>Subject</th><th class="text-center">Score</th><th>Mode</th></tr></thead><tbody><?php foreach ($allRecs as $rec): ?><tr><td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($rec['date']))) ?></td><td><?= htmlspecialchars($rec['student_id']) ?></td><td><?= htmlspecialchars($rec['lastname'] . ', ' . $rec['firstname']) ?></td><td><?= htmlspecialchars($rec['class_name'] ?? 'N/A') ?></td><td><?= htmlspecialchars($rec['subject_name'] ?? $rec['subject_code'] ?? 'N/A') ?></td><td class="text-center"><?= htmlspecialchars($rec['score']) ?> / <?= $maxScore ?></td><td><?= htmlspecialchars(ucfirst($rec['mode'])) ?></td></tr><?php endforeach; ?></tbody></table>
                    <?php else: ?>
                        <p class="no-records">No recitation records found<?= (!empty($search_query) ? ' for "' . htmlspecialchars($search_query) . '"' : '') ?><?= ($rec_selected_class_filter !== 'all' ? ' in the selected class.' : '.') ?></p>
                    <?php endif; ?>
                 </div>
            <?php elseif ($current_section === 'view_students'): ?>
                 <div class="container">
                     <h1 class="section-title">View Students</h1>
                     <?php if (isset($_GET['rec_success'])): ?><p class="success-message">Recitation score recorded!</p><?php endif; ?>
                     <?php if (isset($_GET['enroll_success'])): ?><p class="success-message">Student enrolled!</p><?php endif; ?>
                     <?php if (isset($_GET['withdraw_success'])): ?><p class="success-message">Student withdrawn from class!</p><?php endif; ?>
                     <?php if (!empty($score_errors)): ?><p class="error-message">Score Submission Failed: <?= implode(" ", $score_errors) ?></p><?php endif; ?>
                     <?php if (isset($add_student_errors["general"])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors["general"]) ?></p><?php endif; ?>
                     
                     <div class="filter-control">
                         <form method="GET" action="" style="width: 100%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                             <input type="hidden" name="section" value="view_students">
                             <div class="filter-group">
                                 <label for="class_filter">Filter by Class:</label>
                                 <select name="class_filter" id="class_filter" onchange="this.form.submit()">
                                     <option value="all">All Classes/Subjects</option>
                                     <?php $unique_classes_view = []; foreach ($allClasses as $class) { $unique_classes_view[$class['class_name']] = $class['subject_name_display'] . ' (' . $class['class_name'] . ')'; } foreach ($unique_classes_view as $className => $subjectDisplay): ?>
                                     <option value="<?= htmlspecialchars($className) ?>" <?= ($selected_class_filter === $className) ? 'selected' : '' ?>><?= htmlspecialchars($subjectDisplay) ?></option>
                                     <?php endforeach; ?>
                                 </select>
                             </div>
                             <div class="search-group" style="display: flex; gap: 5px;">
                                 <input type="search" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search ID or Name...">
                                 <button type="submit" class="btn btn-search"><span class="material-icons">search</span></button>
                             </div>
                         </form>
                     </div>

                    <?php if ($selected_class_filter !== 'all'): ?>
                         <button id="picker-toggle-button"><span class="material-icons" style="vertical-align: middle; margin-right: 5px;">touch_app</span>Pick a Student for Recitation</button>
                         <div id="randomizer-control">
                             <h2>Student Recitation Picker</h2>
                             <button id="btn-fair" class="btn btn-picker" data-mode="Fair"><span class="material-icons" style="vertical-align: middle; font-size: 1.2em;">balance</span> Fair Mode</button>
                             <button id="btn-normal" class="btn btn-picker" data-mode="Normal"><span class="material-icons" style="vertical-align: middle; font-size: 1.2em;">shuffle</span> Normal Mode</button>
                             <div id="result-display">Click a button to pick a student.</div>
                             <p class="info-text"><strong>Fair Mode:</strong> Prioritizes 0 recitations.<br><strong>Normal Mode:</strong> Picks randomly from filtered, enrolled students who haven't reached max recitations.</p>
                         </div>
                    <?php else: ?>
                         <p class="no-records-message" style="background-color: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9;">Please select a specific Class/Subject from the filter above to use the Student Picker.</p>
                    <?php endif; ?>

                     <hr class="form-separator">
                    <?php if (empty($students)): ?>
                         <p class="no-records">No students found<?= (!empty($search_query) ? ' for "' . htmlspecialchars($search_query) . '"' : '') ?><?= ($selected_class_filter !== 'all' ? ' in the selected class.' : '.') ?></p>
                    <?php else: ?>
                         <table><thead><tr><th>#</th><th>ID</th><th><a href="?section=view_students&sort=<?= ($sort_order == 'asc' ? 'desc' : 'asc') ?>&sort_col=lastName&class_filter=<?= htmlspecialchars($selected_class_filter) ?>">Last Name <?= ($sort_column == 'lastName' ? ($sort_order == 'asc' ? '&#9660;' : '&#9650;') : '') ?></a></th><th>First Name</th><th>M.I.</th>
                        <?php if ($selected_class_filter === 'all'): ?>
                             <th class="text-center">Subjects Enrolled</th>
                        <?php else: ?>
                             <th>Class</th>
                        <?php endif; ?>
                         <th>Status</th><th class="text-center">Recits</th><th class="text-right">Avg Score</th>
                        <?php if ($selected_class_filter !== 'all'): ?>
                             <th class="text-center">Action</th>
                        <?php endif; ?>
                         </tr></thead>
                         <tbody><?php $no = 1; foreach ($students as $student): ?>
                             <tr>
                                 <td><?= $no++ ?></td><td><?= htmlspecialchars($student["student_id"]) ?></td>
                                 <td><?= htmlspecialchars($student["lastname"]) ?></td><td><?= htmlspecialchars($student["firstname"]) ?></td>
                                 <td><?= htmlspecialchars(substr($student["middlename"] ?? '', 0, 1)) ?></td>
                                 
                                <?php if ($selected_class_filter === 'all'): ?>
                                     <td class="text-center"><?= htmlspecialchars($student["total_subjects_enrolled"]) ?></td>
                                     <td><?php if($student["total_subjects_enrolled"] > 0): ?><span class="status-badge status-enrolled">Enrolled</span><?php else: ?><span class="status-badge status-not-enrolled">Not Enrolled</span><?php endif; ?></td>
                                <?php else: ?>
                                     <td><?= htmlspecialchars($student["class_name"] ?? '-') ?></td>
                                     <td><span class="status-badge status-enrolled">Enrolled</span></td>
                                <?php endif; ?>

                                 <td class="text-center"><?= htmlspecialchars($student["totalRecitations"]) ?></td>
                                 <td class="text-right"><?= number_format($student["averageScore"], 2) ?></td>
                                 
                                <?php if ($selected_class_filter !== 'all'): ?>
                                 <td class="text-center">
                                     <button type="button" class="btn btn-small btn-withdraw" 
                                             data-studentid="<?= htmlspecialchars($student['student_id']) ?>" 
                                             data-classid="<?= htmlspecialchars($student['class_id']) ?>" 
                                             data-studentname="<?= htmlspecialchars($student['lastname'] . ', ' . $student['firstname']) ?>"
                                             data-classname="<?= htmlspecialchars($student['class_name']) ?>">
                                         <span class="material-icons" style="font-size: 1.2em; vertical-align: middle;">person_remove</span>
                                     </button>
                                 </td>
                                <?php endif; ?>
                             </tr>
                        <?php endforeach; ?></tbody>
                         </table>
                    <?php endif; ?>
                     <div id="scoreModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2>Record Recitation Score</h2><p><strong>Student:</strong> <span id="modal-student-name"></span></p><p><strong>ID:</strong> <span id="modal-student-id-display"></span></p><p><strong>Mode:</strong> <span id="modal-mode"></span></p><form action="" method="post" id="scoreForm"><input type="hidden" name="form_action" value="record_recitation"><input type="hidden" name="studentId" id="modal-student-id"><input type="hidden" name="mode" id="modal-mode-input"><input type="hidden" name="subjectCode" id="modal-subject-code-input"><div class="form-group"><label for="modal-date">Date</label><input type="date" name="date" id="modal-date" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label for="modal-score">Score (0 - <?= $maxScore ?>)</label><input type="number" name="score" id="modal-score" min="0" max="<?= $maxScore ?>" step="0.5" required></div><button type="submit" class="btn btn-submit modal-submit-button">Save Score</button></form></div></div>
                 </div>
            <?php elseif ($current_section === 'add_student'): ?>
                 <div class="form-container"><h1 class="section-title">Enroll Student</h1><p class="no-records-message" style="background-color:#FFEBEE; color:#D32F2F; border: 1px solid #FFCDD2;">Assign a registered student to a specific class section and subject.</p><hr class="form-separator"><?php if (isset($add_student_errors["general"])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors["general"]) ?></p><?php endif; ?><?php if (isset($add_student_errors['general_classes'])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors['general_classes']) ?></p><?php endif; ?><form action="" method="post"><input type="hidden" name="form_action" value="enroll_student"><div class="form-group"><label for="studentIdToEnroll">Select Student</label><select name="studentIdToEnroll" id="studentIdToEnroll" required><option value="">-- Select Student ID --</option><?php foreach ($students_for_enrollment as $student): ?><option value="<?= htmlspecialchars($student["student_id"]) ?>" <?= (isset($student_form_data["studentIdToEnroll"]) && $student_form_data["studentIdToEnroll"] == $student["student_id"]) ? 'selected' : '' ?>><?= htmlspecialchars($student["student_id"] . ' - ' . $student["lastname"] . ', ' . $student["firstname"]) ?> (Enrolled: <?= $student['total_subjects_enrolled'] ?>)</option><?php endforeach; ?></select><p class="form-error"><?= $add_student_errors["studentIdToEnroll"] ?? "" ?></p></div><div class="form-group"><label for="classIdToEnroll">Enroll into Class/Subject</label><select name="classIdToEnroll" id="classIdToEnroll" <?= empty($classes) ? 'disabled' : '' ?> required><option value="">-- Select Class/Subject --</option><?php foreach ($classes as $class): ?><option value="<?= $class["class_id"] ?>" <?= (isset($student_form_data["classIdToEnroll"]) && $student_form_data["classIdToEnroll"] == $class["class_id"]) ? 'selected' : '' ?>><?= htmlspecialchars($class["subject_name_display"] . ' (' . $class["class_name"] . ')') ?></option><?php endforeach; ?></select><p class="form-error"><?= $add_student_errors["classIdToEnroll"] ?? "" ?></p></div><button type="submit" class="btn btn-submit" <?= empty($classes) ? 'disabled' : '' ?>>Enroll Student</button></form></div>
            <?php elseif ($current_section === 'delete_student'): ?>
                 <div class="form-container"><h1 class="section-title">Delete Student</h1><hr class="form-separator"><?php if (isset($_GET['delete_success'])): ?><p class="success-message">Student successfully deleted!</p><?php endif; ?><?php if (!empty($add_student_errors['general_delete'])): ?><p class="error-message">Error: <?= htmlspecialchars($add_student_errors['general_delete']) ?></p><?php endif; ?><form action="" method="post" id="deleteStudentInitialForm"><div class="form-group"><label for="studentToDelete">Select Student to Delete</label><select id="studentToDelete" name="studentIdToDelete" required><option value="">-- Select Student ID --</option><?php foreach ($students_for_enrollment as $student): ?><option value="<?= htmlspecialchars($student["student_id"]) ?>" data-name="<?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"]) ?>"><?= htmlspecialchars($student["student_id"] . " - " . $student["lastname"] . ", " . $student["firstname"]) ?> (Enrolled: <?= $student['total_subjects_enrolled'] ?>)</option><?php endforeach; ?></select></div><div class="form-group"><label for="deletion_reason">Reason for Removal (Optional)</label><textarea name="deletion_reason" id="deletion_reason" rows="3"></textarea><small>This reason is not currently stored.</small></div><button type="button" id="initiateDeleteButton" class="btn btn-delete">Delete Student</button></form><?php if (empty($students_for_enrollment)): ?><p class="no-records">No students available to delete.</p><?php endif; ?></div>
            <?php endif; ?>
        </main>
    </div>

     <div id="deleteConfirmModal" class="modal"><div class="modal-content"><span class="modal-close-button">&times;</span><h3>Confirm Deletion</h3><div class="modal-body"><p>Are you absolutely sure you want to permanently delete the following student?</p><p><strong id="deleteStudentName"></strong><br>(ID: <span id="deleteStudentId"></span>)</p><p style="color: #D32F2F; font-weight: bold;">This action cannot be undone.</p></div><div class="modal-footer"><form action="" method="post" id="deleteConfirmForm" style="display: inline;"><input type="hidden" name="form_action" value="confirm_delete_student"><input type="hidden" name="studentIdToDeleteConfirmed" id="studentIdToDeleteConfirmed"><button type="submit" class="btn btn-delete">Confirm Delete</button></form><button type="button" class="btn btn-cancel" id="cancelDeleteButton">Cancel</button></div></div></div>

    <div id="withdrawModal" class="modal">
        <div class="modal-content">
            <span class="modal-close-button">&times;</span>
            <h3>Withdraw Student</h3>
            <form action="" method="post" id="withdrawConfirmForm">
                <div class="modal-body">
                    <p>Are you sure you want to withdraw <strong id="withdrawStudentName"></strong> from the class <strong id="withdrawClassName"></strong>?</p>
                    <div class="form-group" style="text-align: left;">
                        <label for="withdraw_reason">Reason for Withdrawal (Required):</label>
                        <textarea name="withdraw_reason" id="withdraw_reason" rows="3" style="width: 100%; box-sizing: border-box;" required></textarea>
                         <span class="form-error" id="withdraw_reason_error"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="form_action" value="withdraw_student">
                    <input type="hidden" name="student_id_to_withdraw" id="student_id_to_withdraw">
                    <input type="hidden" name="class_id_to_withdraw" id="class_id_to_withdraw">
                    <button type="submit" class="btn btn-delete">Confirm Withdraw</button>
                    <button type="button" class="btn btn-cancel" id="cancelWithdrawButton">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const allStudents = JSON.parse('<?= $students_json ?>');
        const maxScore = <?= $maxScore ?>;
        const maxRecitations = <?= $totalPossibleRecitations ?? 10 ?>;
        const resultDisplay = document.getElementById('result-display');
        const scoreModal = document.getElementById('scoreModal');
        const randomizerControl = document.getElementById('randomizer-control');
        const pickerToggleButton = document.getElementById('picker-toggle-button');
        const closeButton = scoreModal ? scoreModal.querySelector('.close-button') : null;
        
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        const initiateDeleteButton = document.getElementById('initiateDeleteButton');
        const cancelDeleteButton = document.getElementById('cancelDeleteButton');
        const deleteModalCloseButton = deleteConfirmModal ? deleteConfirmModal.querySelector('.modal-close-button') : null;
        const studentToDeleteSelect = document.getElementById('studentToDelete');
        
        const withdrawModal = document.getElementById('withdrawModal');
        const cancelWithdrawButton = document.getElementById('cancelWithdrawButton');
        const withdrawModalCloseButton = withdrawModal ? withdrawModal.querySelector('.modal-close-button') : null;
        const withdrawConfirmForm = document.getElementById('withdrawConfirmForm');
        const withdrawReasonInput = document.getElementById('withdraw_reason');
        const withdrawReasonError = document.getElementById('withdraw_reason_error');

        function togglePicker() {
            if (!randomizerControl) return;
            const isHidden = randomizerControl.style.display === 'none' || randomizerControl.style.display === '';
            randomizerControl.style.display = isHidden ? 'block' : 'none';
             if(pickerToggleButton) {
                 pickerToggleButton.innerHTML = isHidden
                     ? '<span class="material-icons" style="vertical-align: middle; margin-right: 5px;">close</span>Hide Picker'
                     : '<span class="material-icons" style="vertical-align: middle; margin-right: 5px;">touch_app</span>Pick a Student for Recitation';
             }
            if (!isHidden && resultDisplay) {
                resultDisplay.innerHTML = "Click a button to pick a student.";
                resultDisplay.style.color = 'inherit';
            }
        }
        function getStudentFullName(student) {
            let fullName = student.firstname + ' ';
            if (student.middlename) { fullName += student.middlename.charAt(0) + '. '; }
            fullName += student.lastname;
            return fullName;
        }
        function openScoreModal(student, mode) {
            if (!scoreModal) return;
            document.getElementById('modal-student-name').textContent = getStudentFullName(student) + ' (' + (student.class_name || 'N/A') + ')';
            document.getElementById('modal-student-id-display').textContent = student.student_id;
            document.getElementById('modal-mode').textContent = mode;
            document.getElementById('modal-student-id').value = student.student_id;
            document.getElementById('modal-mode-input').value = mode;
            document.getElementById('modal-subject-code-input').value = student.subject_code || '';
            document.getElementById('modal-score').value = '';
            document.getElementById('modal-score').setAttribute('max', maxScore);
            scoreModal.style.display = 'flex';
        }
        window.handleScoreButtonClick = function(studentId, mode) {
            const student = allStudents.find(s => s.student_id == studentId);
             if (student && student.subject_code) {
                 openScoreModal(student, mode);
             } else if (student && !student.subject_code) {
                 alert("Error: Cannot record score. Student '" + getStudentFullName(student) + "' is not assigned to a class/subject.");
             } else {
                 alert("Error: Student data missing.");
             }
        }
        const pickerButtons = document.querySelectorAll('.btn-picker');
        if (pickerButtons) {
            pickerButtons.forEach(button => {
                button.addEventListener('click', (event) => {
                     if(resultDisplay) resultDisplay.innerHTML = '<span class="material-icons" style="font-size: 2em; animation: spin 1s linear infinite;">hourglass_empty</span> Picking...';
                    const mode = event.target.closest('button').dataset.mode;
                    setTimeout(() => pickStudent(mode), 50);
                });
            });
        }

        function pickStudent(mode) {
             if (!resultDisplay) return;
             const enrolledStudents = allStudents.filter(s => s.class_name && s.class_name.trim() !== '' && s.class_name.trim() !== '(Multiple)');
            if (enrolledStudents.length === 0) {
                 resultDisplay.textContent = "No enrolled students available in this class filter!";
                 resultDisplay.style.color = '#D32F2F';
                 return;
            }
            let potentialStudents = enrolledStudents.filter(s => parseInt(s.totalRecitations) < maxRecitations);
             if (potentialStudents.length === 0) {
                 resultDisplay.textContent = "All currently filtered and enrolled students have reached the maximum recitations (" + maxRecitations + ").";
                 resultDisplay.style.color = '#ff9800';
                 return;
            }
            let eligibleStudents = [];
            let pickingRationale = "";
            let rationaleColor = "#555";
            if (mode === 'Fair') {
                eligibleStudents = potentialStudents.filter(s => parseInt(s.totalRecitations) === 0);
                if (eligibleStudents.length === 0) {
                    eligibleStudents = potentialStudents;
                    pickingRationale = `FAIR MODE: All eligible students have recitations. Picking randomly from ${eligibleStudents.length} enrolled student(s) under max recits.`;
                    rationaleColor = '#ff9800';
                } else {
                    pickingRationale = `FAIR MODE: Picking from ${eligibleStudents.length} enrolled student(s) with 0 recitations.`;
                    rationaleColor = '#28a745';
                }
            } else {
                eligibleStudents = potentialStudents;
                pickingRationale = `NORMAL MODE: Picking randomly from ${eligibleStudents.length} enrolled student(s) under max recits.`;
                rationaleColor = '#555';
            }
             if (eligibleStudents.length === 0) { resultDisplay.textContent = "No eligible students found for this mode after filtering!"; resultDisplay.style.color = '#D32F2F'; return; }
            const randomIndex = Math.floor(Math.random() * eligibleStudents.length);
            const pickedStudent = eligibleStudents[randomIndex];
            resultDisplay.style.color = rationaleColor;
            resultDisplay.textContent = pickingRationale;
            setTimeout(() => {
                if (!pickedStudent || !resultDisplay) return;
                resultDisplay.style.color = '#333';
                resultDisplay.innerHTML = `
                    <div style="text-align: center;">
                        <strong>PICKED:</strong><br>
                         <span style="color: #D32F2F; font-size: 1.3em;"><strong>${getStudentFullName(pickedStudent)}</strong></span><br>
                        <span style="color: #555;">(${pickedStudent.class_name || 'N/A'}) - ID: ${pickedStudent.student_id}</span><br>
                        <small>Current Recitations: ${pickedStudent.totalRecitations} / ${maxRecitations}</small>
                        <br>
                        <button onclick="handleScoreButtonClick('${pickedStudent.student_id}', '${mode}')" class="btn btn-record" ${!pickedStudent.subject_code ? 'disabled title="Student not assigned to a class/subject"' : ''}>
                             <span class="material-icons" style="vertical-align: middle; font-size: 1.1em;">note_add</span> Record Score
                        </button>
                    </div>`;
            }, 700);
        }
        if (closeButton) { closeButton.addEventListener('click', () => scoreModal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == scoreModal) { scoreModal.style.display = 'none'; } });

        if (initiateDeleteButton && deleteConfirmModal && studentToDeleteSelect) {
            initiateDeleteButton.addEventListener('click', function() {
                const selectedOption = studentToDeleteSelect.options[studentToDeleteSelect.selectedIndex];
                const studentId = selectedOption.value;
                const studentName = selectedOption.dataset.name || studentId;
                if (!studentId) { alert("Please select a student to delete."); return; }
                document.getElementById('deleteStudentName').textContent = studentName;
                document.getElementById('deleteStudentId').textContent = studentId;
                document.getElementById('studentIdToDeleteConfirmed').value = studentId;
                deleteConfirmModal.style.display = 'flex';
            });
        }
        if (cancelDeleteButton) { cancelDeleteButton.addEventListener('click', () => { deleteConfirmModal.style.display = 'none'; }); }
        if (deleteModalCloseButton) { deleteModalCloseButton.addEventListener('click', () => { deleteConfirmModal.style.display = 'none'; }); }
        window.addEventListener('click', (event) => { if (event.target == deleteConfirmModal) { deleteConfirmModal.style.display = 'none'; } });

         if(pickerToggleButton && randomizerControl) {
             pickerToggleButton.addEventListener('click', togglePicker);
              randomizerControl.style.display = 'none';
              pickerToggleButton.innerHTML = '<span class="material-icons" style="vertical-align: middle; margin-right: 5px;">touch_app</span>Pick a Student for Recitation';
         }
         
         document.querySelectorAll('.btn-withdraw').forEach(button => {
            button.addEventListener('click', function() {
                const studentId = this.dataset.studentid;
                const classId = this.dataset.classid;
                const studentName = this.dataset.studentname;
                const className = this.dataset.classname;

                if (!studentId || !classId) {
                    alert("Error: Missing student or class information.");
                    return;
                }

                document.getElementById('withdrawStudentName').textContent = studentName;
                document.getElementById('withdrawClassName').textContent = className;
                document.getElementById('student_id_to_withdraw').value = studentId;
                document.getElementById('class_id_to_withdraw').value = classId;
                document.getElementById('withdraw_reason').value = '';
                document.getElementById('withdraw_reason_error').textContent = '';
                
                withdrawModal.style.display = 'flex';
            });
         });

         if (withdrawConfirmForm) {
             withdrawConfirmForm.addEventListener('submit', function(event) {
                 if (withdrawReasonInput.value.trim() === '') {
                     event.preventDefault(); 
                     withdrawReasonError.textContent = 'A reason for withdrawal is required.';
                 } else {
                     withdrawReasonError.textContent = '';
                 }
             });
         }
         if (cancelWithdrawButton) { cancelWithdrawButton.addEventListener('click', () => { withdrawModal.style.display = 'none'; }); }
         if (withdrawModalCloseButton) { withdrawModalCloseButton.addEventListener('click', () => { withdrawModal.style.display = 'none'; }); }
         window.addEventListener('click', (event) => { if (event.target == withdrawModal) { withdrawModal.style.display = 'none'; } });
    </script>
</body>
</html>