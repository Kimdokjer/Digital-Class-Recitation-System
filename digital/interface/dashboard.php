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
$current_section = $_GET['section'] ?? 'dashboard';

// --- Variables for Report Generation ---
$report_students = [];
$report_class_details = null;
$report_student_details = null;
$report_student_subjects = [];
$report_class_name = $_GET['report_class_name'] ?? '';
$report_student_id = $_GET['report_student_id'] ?? '';


$dashboard_stats = [];
// Variables for Charts
$chart_engaged = 0;
$chart_total = 0;
$chart_labels_json = '[]';
$chart_data_json = '[]';

try {
    $conn = $manager->connect();
    
    if ($current_section === 'dashboard') {
        // 1. Basic Stats
        $stmt_students = $conn->query("SELECT COUNT(student_id) AS totalStudents FROM students");
        $dashboard_stats['totalStudents'] = $stmt_students->fetchColumn();
        $chart_total = $dashboard_stats['totalStudents'];
        
        $stmt_recitations = $conn->query("SELECT COUNT(recit_id) AS totalRecitations, AVG(score) AS avgScore FROM recits");
        $recit_data = $stmt_recitations->fetch(PDO::FETCH_ASSOC);
        $dashboard_stats['totalRecitations'] = $recit_data['totalRecitations'] ?? 0;
        $dashboard_stats['avgScore'] = $recit_data['avgScore'] ?? 0;

        $stmt_classes = $conn->query("SELECT COUNT(class_id) AS totalClasses FROM class_sections");
        $dashboard_stats['totalClasses'] = $stmt_classes->fetchColumn();
        
        $stmt_engaged = $conn->query("SELECT COUNT(DISTINCT student_id) AS engagedStudents FROM recits");
        $engagedStudents = $stmt_engaged->fetchColumn();
        $chart_engaged = $engagedStudents;
        $dashboard_stats['engagementRate'] = ($dashboard_stats['totalStudents'] > 0) ? round(($engagedStudents / $dashboard_stats['totalStudents']) * 100) : 0;

        // 2. Data for Bar Chart (Recitations per Subject)
        $stmt_chart = $conn->query("SELECT subject_code, COUNT(recit_id) as count FROM recits GROUP BY subject_code");
        $subject_stats = $stmt_chart->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = [];
        $data = [];
        foreach ($subject_stats as $stat) {
            $labels[] = $stat['subject_code'];
            $data[] = $stat['count'];
        }
        $chart_labels_json = json_encode($labels);
        $chart_data_json = json_encode($data);
    }
    
} catch (PDOException $e) {
    $dashboard_stats['error'] = "Failed to load dashboard stats. (DB Error: " . $e->getMessage() . ")";
}

$allClasses = $manager->fetchAllClasses();
$classes = $allClasses;
if (empty($classes)) {
    $add_student_errors['general_classes'] = "Warning: No classes/subjects found. Please add subjects and class sections.";
}

$students_for_enrollment = $manager->viewStudents('lastName', 'ASC', 'all', '');
$allSubjects = $manager->fetchAllSubjects();
$allCourses = $manager->fetchAllCourses();

// --- Data Fetching for Reports Page ---
if ($current_section === 'reports') {
    if (!empty($report_class_name)) {
        // Generate Class Report
        $report_students = $manager->viewStudents('lastName', 'ASC', $report_class_name, '');
        foreach ($allClasses as $class) {
            if ($class['class_name'] === $report_class_name) {
                $report_class_details = $class;
                break;
            }
        }
    } elseif (!empty($report_student_id)) {
        // Generate Individual Student Report
        $report_student_details = $manager->fetchStudent($report_student_id);
        if ($report_student_details) {
            $report_student_subjects = $manager->getStudentEnrolledSubjects($report_student_id);
            // Loop and attach recitation data to each subject
            foreach ($report_student_subjects as $key => $subject) {
                $recits = $manager->getStudentRecitations($report_student_id, $subject['subject_code']);
                $avg = $manager->getStudentAverageScore($report_student_id, $subject['subject_code']);
                $report_student_subjects[$key]['recitations'] = $recits;
                $report_student_subjects[$key]['averageScore'] = $avg;
            }
        }
    }
}


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
                        
                        // --- Send email and bell notification to the student ---
                        $manager->sendEnrollmentNotification($studentIdToEnroll, $classIdToEnroll);

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
    $reason = $_POST['deletion_reason_confirmed'] ?? ''; // Capture the reason
    
    if ($studentIdToDelete) {
        // PASS THE REASON HERE
        if ($manager->deleteStudent($studentIdToDelete, $reason)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=delete_student&delete_success=true");
            exit;
        } else {
            $add_student_errors['general_delete'] = "Failed to delete student. (Database error or student not found)";
        }
    } else {
        $add_student_errors['general_delete'] = "No student ID provided for deletion.";
    }
}

// --- WITHDRAW STUDENT ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'withdraw_student') {
    $studentIdToWithdraw = $_POST['student_id_to_withdraw'] ?? null;
    $classIdToWithdraw = $_POST['class_id_to_withdraw'] ?? null;
    $withdraw_reason = trim(htmlspecialchars($_POST['withdraw_reason'] ?? 'No reason provided'));

    if ($studentIdToWithdraw && $classIdToWithdraw) {
        // Pass the $withdraw_reason to the manager function
        if ($manager->withdrawStudentFromClass($studentIdToWithdraw, $classIdToWithdraw, $withdraw_reason)) {
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

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'add_class') {
    $class_name = trim(htmlspecialchars($_POST['class_name'] ?? ''));
    $subject_code = trim(htmlspecialchars($_POST['subject_code'] ?? ''));

    if (empty($class_name) || empty($subject_code)) {
        $add_student_errors['general_add_class'] = "Both Class Name and Subject are required.";
    } else {
        if ($manager->addClassSection($class_name, $subject_code)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&class_add_success=true");
            exit;
        } else {
            $add_student_errors['general_add_class'] = "Failed to add class. The class name might already exist.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'confirm_delete_class') {
    $class_id_to_delete = $_POST['class_id_to_delete_confirmed'] ?? null;
    
    if ($class_id_to_delete) {
        if ($manager->deleteClassSection($class_id_to_delete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&class_delete_success=true");
            exit;
        } else {
             $add_student_errors['general_delete_class'] = "Failed to delete class. Students might be enrolled in it.";
        }
    } else {
        $add_student_errors['general_delete_class'] = "No class section selected for deletion.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'add_subject') {
    $subject_code = trim(htmlspecialchars($_POST['subject_code'] ?? ''));
    $subject_name = trim(htmlspecialchars($_POST['subject_name'] ?? ''));
    
    if (empty($subject_code) || empty($subject_name)) {
        $add_student_errors['general_add_subject'] = "Both Subject Code and Subject Name are required.";
    } else {
        if ($manager->addSubject($subject_code, $subject_name)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&subject_add_success=true");
            exit;
        } else {
            $add_student_errors['general_add_subject'] = "Failed to add subject. The Subject Code might already exist.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'confirm_delete_subject') {
    $subject_code_to_delete = $_POST['subject_code_to_delete_confirmed'] ?? null;
    
    if ($subject_code_to_delete) {
        if ($manager->deleteSubject($subject_code_to_delete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&subject_delete_success=true");
            exit;
        } else {
             $add_student_errors['general_delete_subject'] = "Failed to delete subject. It might be linked to a class section.";
        }
    } else {
        $add_student_errors['general_delete_subject'] = "No subject selected for deletion.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'add_course') {
    $course_name = trim(htmlspecialchars($_POST['course_name'] ?? ''));
    
    if (empty($course_name)) {
        $add_student_errors['general_add_course'] = "Course Name is required.";
    } else {
        if ($manager->addCourse($course_name)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&course_add_success=true");
            exit;
        } else {
            $add_student_errors['general_add_course'] = "Failed to add course. The Course Name might already exist.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_action']) && $_POST['form_action'] === 'confirm_delete_course') {
    $course_id_to_delete = $_POST['course_id_to_delete_confirmed'] ?? null;
    
    if ($course_id_to_delete) {
        if ($manager->deleteCourse($course_id_to_delete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?section=manage_classes&course_delete_success=true");
            exit;
        } else {
             $add_student_errors['general_delete_course'] = "Failed to delete course. Students might be enrolled in it.";
        }
    } else {
        $add_student_errors['general_delete_course'] = "No course selected for deletion.";
    }
}


$students = $manager->viewStudents($sort_column, $sort_order, $selected_class_filter, $search_query);
$students_json = json_encode(array_values($students));

if(isset($_GET['class_add_success']) || isset($_GET['class_delete_success'])) { $allClasses = $manager->fetchAllClasses(); $classes = $allClasses; }
if(isset($_GET['subject_add_success']) || isset($_GET['subject_delete_success'])) { $allSubjects = $manager->fetchAllSubjects(); }
if(isset($_GET['course_add_success']) || isset($_GET['course_delete_success'])) { $allCourses = $manager->fetchAllCourses(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Digital Recitation</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* --- THEME VARIABLES --- */
        :root {
            --primary: #cc0000;           /* Crimson Red */
            --primary-dark: #8a0000;      /* Darker Red */
            --bg-body: #eef2f5;           /* Cool Light Gray Background */
            --bg-sidebar: #cc0000;        /* Red Sidebar */
            --text-sidebar: #ffffff;      /* White text for sidebar */
            --text-main: #333333;
            --text-muted: #666666;
            --border-color: #e0e0e0;
            --shadow-subtle: 0 4px 6px rgba(0,0,0,0.05);
            --shadow-hover: 0 10px 25px rgba(0,0,0,0.1);
        }

        body { 
            font-family: 'Montserrat', sans-serif; 
            margin: 0; 
            padding: 0; 
            background-color: var(--bg-body); 
            display: flex; 
            min-height: 100vh; 
            color: var(--text-main);
        }

        /* --- SIDEBAR --- */
        .sidebar { 
            width: 260px; 
            background-color: var(--bg-sidebar); 
            color: var(--text-sidebar);
            display: flex; 
            flex-direction: column; 
            flex-shrink: 0; 
            height: 100vh; 
            position: sticky; 
            top: 0; 
            z-index: 200;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header { 
            text-align: center; 
            padding: 30px 20px; 
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .sidebar-header img { 
            width: 80px; height: 80px; 
            border-radius: 50%; 
            padding: 3px; 
            object-fit: cover; 
            border: 3px solid rgba(255,255,255,0.3);
            background: white;
            margin-bottom: 15px; 
        }
        .sidebar-header h2 { 
            color: white; 
            margin: 0; 
            font-size: 1.2em; 
            font-weight: 700; 
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar-nav { padding: 20px 0; flex-grow: 1; overflow-y: auto; }
        .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
        
        .sidebar-nav ul li a { 
            display: flex; align-items: center; 
            padding: 16px 25px; 
            color: rgba(255,255,255,0.9); 
            text-decoration: none; 
            font-size: 0.95em; 
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        
        .sidebar-nav ul li a .material-icons { 
            margin-right: 15px; font-size: 22px; color: rgba(255,255,255,0.8);
            transition: color 0.2s;
        }
        
        /* Hover State */
        .sidebar-nav ul li a:hover { 
            background-color: rgba(255,255,255,0.1); 
            color: white; 
        }
        
        /* Active State - "Folder Tab" Effect */
        .sidebar-nav ul li a.active { 
            background-color: var(--bg-body); 
            color: var(--primary); 
            font-weight: 700; 
            border-left: 4px solid var(--primary-dark);
            border-radius: 30px 0 0 30px;
            margin-left: 10px;
        }
        .sidebar-nav ul li a.active .material-icons { color: var(--primary); }

        /* LOGOUT SECTION - NO BOX */
        .logout-container { 
            padding: 10px 0; 
            border-top: 1px solid rgba(255,255,255,0.2); 
            margin-top: auto;
        }
        .logout-btn { 
            display: flex; 
            align-items: center;
            padding: 16px 25px; /* Match sidebar nav padding */
            width: 100%;
            background: none; 
            border: none; 
            color: rgba(255,255,255,0.9); 
            font-size: 0.95em; 
            font-weight: 500; 
            text-decoration: none; 
            transition: all 0.2s ease; 
            justify-content: flex-start;
            cursor: pointer;
            border-left: 4px solid transparent;
        }
        .logout-btn .material-icons {
            margin-right: 15px; 
            font-size: 22px; 
            color: rgba(255,255,255,0.8);
        }
        .logout-btn:hover { 
            background-color: rgba(255,255,255,0.1); 
            color: white;
        }

        /* --- MAIN WRAPPER --- */
        .main-wrapper { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }

        /* --- TOP NAVBAR --- */
        .top-navbar { 
            background-color: #ffffff; 
            padding: 15px 30px; 
            border-bottom: 1px solid var(--border-color);
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky; 
            top: 0; 
            z-index: 100; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .top-navbar h1 { margin: 0; color: var(--text-main); font-size: 1.5em; font-weight: 700; }
        
        .user-menu { display: flex; align-items: center; gap: 15px; }
        .user-menu span { font-weight: 600; color: var(--text-main); }
        .user-menu .material-icons { color: var(--primary); font-size: 32px; }

        /* --- CONTENT AREA --- */
        .main-content { padding: 30px; flex-grow: 1; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Titles & Headers */
        h1.section-title {
            font-size: 1.8em; color: var(--text-main); margin-bottom: 25px;
            font-weight: 700; border-bottom: 3px solid var(--primary);
            padding-bottom: 5px; display: inline-block;
        }

        /* --- DASHBOARD CARDS --- */
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .card { 
            background-color: #ffffff; 
            border-radius: 12px; 
            box-shadow: var(--shadow-subtle); 
            padding: 25px; 
            text-align: center; 
            border-top: 5px solid var(--primary); 
            transition: transform 0.2s;
        }
        .card:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); }
        .card .material-icons { font-size: 40px; color: var(--primary); margin-bottom: 10px; opacity: 0.9; }
        .card h3 { margin: 0 0 10px 0; font-size: 0.95em; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .card .card-number { font-size: 2.2em; font-weight: 700; color: var(--text-main); margin: 0; }

        /* --- CHARTS --- */
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .chart-container { background-color: white; padding: 25px; border-radius: 12px; box-shadow: var(--shadow-subtle); border: 1px solid var(--border-color); }
        .chart-container h3 { margin-top: 0; text-align: center; color: var(--text-main); font-size: 1.1em; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }

        /* --- TABLES --- */
        table { 
            width: 100%; border-collapse: separate; border-spacing: 0; 
            background: #fff; border-radius: 12px; 
            box-shadow: var(--shadow-subtle); overflow: hidden;
            border: 1px solid var(--border-color);
            margin-top: 20px;
        }
        th { 
            background-color: #fff; color: var(--text-muted); 
            font-weight: 700; text-transform: uppercase; font-size: 0.8em; 
            padding: 15px 20px; text-align: left; border-bottom: 2px solid #f0f0f0;
        }
        td { padding: 15px 20px; border-bottom: 1px solid #f0f0f0; color: var(--text-main); font-size: 0.95em; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #fcfcfc; }
        th a { color: inherit; text-decoration: none; display: flex; align-items: center; gap: 5px; }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }

        /* --- BADGES --- */
        .status-badge { display: inline-block; padding: 5px 12px; font-size: 0.75em; font-weight: 700; border-radius: 50px; text-transform: uppercase; }
        .status-enrolled { background-color: #e8f5e9; color: #2e7d32; }
        .status-not-enrolled { background-color: #fff3e0; color: #e65100; }

        /* --- FORMS & INPUTS --- */
        .form-container { 
            background-color: #ffffff; padding: 40px; 
            border-radius: 12px; box-shadow: var(--shadow-subtle); 
            max-width: 700px; width: 100%; margin: 20px auto; 
            border: 1px solid var(--border-color);
            border-top: 5px solid var(--primary);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-main); font-size: 0.9em; }
        
        input[type="text"], input[type="search"], input[type="number"], input[type="date"], select, textarea { 
            width: 100%; padding: 12px 15px; 
            border: 1px solid #ddd; border-radius: 8px; 
            font-size: 0.95em; color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            background-color: #fcfcfc;
            box-sizing: border-box;
        }
        input:focus, select:focus, textarea:focus { 
            border-color: var(--primary); outline: none; background-color: #fff; 
            box-shadow: 0 0 0 3px rgba(204, 0, 0, 0.1);
        }

        /* --- BUTTONS --- */
        .btn { 
            padding: 10px 20px; border: none; border-radius: 50px; 
            cursor: pointer; font-size: 0.9em; font-weight: 600; 
            transition: all 0.3s ease; text-decoration: none; 
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        }
        .btn-submit, .btn-search, .btn-record, .btn-print { background-color: var(--primary); color: white; }
        .btn-submit:hover, .btn-search:hover, .btn-record:hover, .btn-print:hover { background-color: var(--primary-dark); transform: translateY(-2px); }
        
        .btn-delete, .btn-withdraw { background-color: #fff; color: var(--primary); border: 1px solid var(--primary); }
        .btn-delete:hover, .btn-withdraw:hover { background-color: var(--primary); color: white; }
        
        .btn-cancel { background-color: #eee; color: #555; }
        .btn-cancel:hover { background-color: #ddd; color: #333; }
        
        .btn-picker { background-color: var(--primary); color: white; margin-right: 10px; border-radius: 8px; }
        
        /* --- FILTERS --- */
        .filter-control { 
            background: #fff; padding: 20px; border-radius: 12px; 
            box-shadow: var(--shadow-subtle); margin-bottom: 25px; 
            border: 1px solid var(--border-color);
            display: flex; gap: 20px; align-items: center; flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 10px; flex-grow: 1; }
        .search-group { display: flex; gap: 10px; }

        /* --- PICKER --- */
        #randomizer-control { 
            background: #fff; border: 2px dashed var(--primary); 
            padding: 30px; border-radius: 12px; text-align: center; 
            margin-bottom: 30px; display: none; 
        }
        #randomizer-control h2 { color: var(--primary); margin-top: 0; }
        #result-display { 
            margin: 20px 0; font-size: 1.2em; min-height: 100px; 
            display: flex; align-items: center; justify-content: center; 
            background: #f9f9f9; border-radius: 8px; padding: 20px;
        }
        #picker-toggle-button { 
            background-color: var(--primary); color: white; 
            padding: 12px 25px; border: none; border-radius: 50px; 
            font-weight: 700; margin: 0 auto 25px; display: block; 
            box-shadow: 0 4px 10px rgba(204,0,0,0.2); cursor: pointer;
        }

        /* --- MODALS --- */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .modal-content { background-color: #fff; padding: 40px; border-radius: 15px; width: 90%; max-width: 500px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2); border-top: 5px solid var(--primary); }
        .modal-content h2, .modal-content h3 { margin-top: 0; color: var(--text-main); text-align: center; margin-bottom: 20px; }
        .close-button, .modal-close-button { position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: #999; }
        .modal-footer { margin-top: 25px; text-align: center; display: flex; gap: 10px; justify-content: center; }
        .modal-submit-button { width: 100%; }

        /* --- MANAGE CARDS --- */
        .manage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; }
        .manage-card { 
            background: #fff; border-radius: 12px; padding: 30px; 
            box-shadow: var(--shadow-subtle); border: 1px solid var(--border-color);
            border-top: 5px solid var(--primary); display: flex; flex-direction: column;
        }
        .manage-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; color: var(--text-main); }
        .manage-bottom-section { margin-top: auto; padding-top: 20px; border-top: 1px dashed #eee; }
        .section-label { font-size: 0.8em; font-weight: 700; color: var(--primary); text-transform: uppercase; display: block; margin-bottom: 10px; }
        .btn-card-add, .btn-card-delete { width: 100%; padding: 12px; border-radius: 8px; font-weight: 700; text-transform: uppercase; font-size: 0.85em; border: none; cursor: pointer; transition: background 0.3s; }
        .btn-card-add { background: var(--primary); color: white; }
        .btn-card-add:hover { background: var(--primary-dark); }
        .btn-card-delete { background: #fff0f0; color: var(--primary); }
        .btn-card-delete:hover { background: #ffe0e0; }

        /* --- REPORTS --- */
        .report-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .report-summary-box { background: #fff; padding: 20px; border-radius: 10px; box-shadow: var(--shadow-subtle); border-left: 4px solid var(--primary); }
        .report-summary-box h4 { margin: 0 0 5px 0; font-size: 0.9em; color: var(--text-muted); text-transform: uppercase; }
        .report-summary-box p { font-size: 1.8em; font-weight: 700; color: var(--primary); margin: 0; }
        .report-header { display: none; } /* Hidden on screen, visible on print */

        /* --- MESSAGES --- */
        .error-message { background: #fff5f5; color: var(--primary); padding: 15px; border-radius: 8px; border: 1px solid #ffcccc; margin-bottom: 20px; text-align: center; }
        .success-message { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 8px; border: 1px solid #c8e6c9; margin-bottom: 20px; text-align: center; }
        .no-records { text-align: center; padding: 40px; background: #fff; border-radius: 12px; color: var(--text-muted); border: 1px dashed #ccc; margin-top: 20px; }

        /* --- RESPONSIVE --- */
        @media (max-width: 992px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; flex-direction: row; border-right: none; border-bottom: 1px solid rgba(0,0,0,0.1); }
            .sidebar-header, .logout-container { display: none; }
            .sidebar-nav ul { display: flex; justify-content: space-around; width: 100%; }
            .sidebar-nav ul li a { flex-direction: column; padding: 10px; font-size: 0.8em; text-align: center; border-left: none; border-bottom: 3px solid transparent; }
            .sidebar-nav ul li a.active { border-left: none; border-bottom-color: white; background: rgba(255,255,255,0.2); border-radius: 8px; margin: 0; color: white; }
            .sidebar-nav ul li a.active .material-icons { color: white; }
            .sidebar-nav ul li a .material-icons { margin: 0 0 5px 0; }
            .top-navbar { padding: 15px 20px; }
            .main-content { padding: 20px; }
            .chart-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .filter-control { flex-direction: column; align-items: stretch; }
            .card-grid { grid-template-columns: 1fr; }
        }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* --- PRINT STYLES --- */
        @media print {
            .sidebar, .top-navbar, .filter-control, #picker-toggle-button, .modal, .form-container, .btn, .logout-btn, .action-cell, .action-header, #report-selector-forms, #printReportButton { display: none !important; }
            body { background: #fff; color: #000; display: block; }
            .main-wrapper { margin: 0; padding: 0; }
            .main-content { padding: 0; }
            .container { max-width: 100%; box-shadow: none; border: none; }
            
            .report-header { display: block; text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 15px; }
            .report-header img { width: 60px; }
            .report-header h2 { margin: 5px 0; color: #000; }
            
            table { box-shadow: none; border: 1px solid #000; margin-top: 10px; font-size: 10pt; }
            th { background: #eee !important; color: #000 !important; border: 1px solid #000; padding: 5px; }
            td { border: 1px solid #000; color: #000; padding: 5px; }
            
            .report-summary-grid { display: block; margin-bottom: 20px; }
            .report-summary-box { border: none; padding: 0; box-shadow: none; display: inline-block; margin-right: 30px; background: none; }
            .report-summary-box h4 { display: inline; color: #000; font-weight: normal; }
            .report-summary-box h4::after { content: ": "; }
            .report-summary-box p { display: inline; color: #000; font-size: 1em; }
            
            h1.section-title { border: none; text-align: center; margin-bottom: 10px; }
            .card-grid, .chart-grid { display: none; } /* Hide dashboard widgets on print */
        }

        /* --- SCROLLBAR REMOVAL --- */
        /* Hide scrollbar for Chrome, Safari and Opera */
        ::-webkit-scrollbar {
            display: none;
        }

        /* Hide scrollbar for IE, Edge and Firefox */
        html, body, .sidebar-nav {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
             <img src="../images/logowmsu.jpg" alt="Logo">
             <h2>Digital Class Recitation</h2>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="?section=dashboard" class="<?= ($current_section == 'dashboard' ? 'active' : '') ?>"><span class="material-icons">dashboard</span>Dashboard</a></li>
                <li><a href="?section=view_students" class="<?= ($current_section == 'view_students' ? 'active' : '') ?>"><span class="material-icons">people</span>Students</a></li>
                <li><a href="?section=add_student" class="<?= ($current_section == 'add_student' ? 'active' : '') ?>"><span class="material-icons">person_add</span>Enroll</a></li>
                <li><a href="?section=manage_classes" class="<?= ($current_section == 'manage_classes' ? 'active' : '') ?>"><span class="material-icons">class</span>Academics</a></li>
                <li><a href="?section=recitation_records" class="<?= ($current_section == 'recitation_records' ? 'active' : '') ?>"><span class="material-icons">assignment</span>Recitations</a></li>
                <li><a href="?section=reports" class="<?= ($current_section == 'reports' ? 'active' : '') ?>"><span class="material-icons">print</span>Reports</a></li>
                <li><a href="?section=delete_student" class="delete-link <?= ($current_section == 'delete_student' ? 'active' : '') ?>"><span class="material-icons">person_remove</span>Remove</a></li>
            </ul>
        </nav>
        <div class="logout-container">
            <a href="logout.php" class="logout-btn">
                <span class="material-icons">logout</span> Logout
            </a>
        </div>
    </div>

    <div class="main-wrapper">
         <nav class="top-navbar">
            <h1><?= ucfirst(str_replace('_', ' ', $current_section)) ?></h1>
            <div class="user-menu">
                <span style="font-size: 30px;" class="material-icons">account_circle</span>
                <span>Professor</span>
            </div>
        </nav>

        <main class="main-content">
             <?php if ($current_section === 'dashboard'): ?>
                <div class="container">
                    <?php if (isset($dashboard_stats['error'])): ?><p class="error-message"><?= $dashboard_stats['error'] ?></p><?php endif; ?>
                    
                    <div class="card-grid">
                        <div class="card">
                            <span class="material-icons">group</span>
                            <h3>Total Students</h3>
                            <p class="card-number"><?= htmlspecialchars($dashboard_stats['totalStudents'] ?? '0') ?></p>
                        </div>
                        <div class="card">
                            <span class="material-icons">assessment</span>
                            <h3>Total Recitations</h3>
                            <p class="card-number"><?= htmlspecialchars($dashboard_stats['totalRecitations'] ?? '0') ?></p>
                        </div>
                        <div class="card">
                            <span class="material-icons">class</span>
                            <h3>Active Classes</h3>
                            <p class="card-number"><?= htmlspecialchars($dashboard_stats['totalClasses'] ?? '0') ?></p>
                        </div>
                        <div class="card">
                            <span class="material-icons">bar_chart</span>
                            <h3>Overall Avg.</h3>
                            <p class="card-number"><?= number_format($dashboard_stats['avgScore'] ?? 0, 2) ?></p>
                        </div>
                        <div class="card">
                            <span class="material-icons">pie_chart</span>
                            <h3>Engagement</h3>
                            <p class="card-number"><?= htmlspecialchars($dashboard_stats['engagementRate'] ?? 0) ?>%</p>
                        </div>
                    </div>

                    <div class="chart-grid">
                        <div class="chart-container">
                            <h3>Engagement Breakdown</h3>
                            <canvas id="engagementChart"></canvas>
                        </div>
                        <div class="chart-container">
                            <h3>Recitations by Subject</h3>
                            <canvas id="subjectChart"></canvas>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_section === 'recitation_records'): ?>
                <div class="container">
                    <div class="filter-control">
                        <form method="GET" action="" style="width: 100%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <input type="hidden" name="section" value="recitation_records">
                            <div class="filter-group">
                                <label for="rec_class_filter">Class:</label>
                                <select name="class_filter" id="rec_class_filter" onchange="this.form.submit()">
                                    <option value="all">All Classes</option>
                                    <?php
                                    $rec_selected_class_filter = $_GET['class_filter'] ?? 'all';
                                    $unique_classes_recs = [];
                                    foreach ($allClasses as $class) { $unique_classes_recs[$class['class_name']] = $class['subject_name_display'] . ' (' . $class['class_name'] . ')'; }
                                    foreach ($unique_classes_recs as $className => $subjectDisplay): ?>
                                        <option value="<?= htmlspecialchars($className) ?>" <?= ($rec_selected_class_filter === $className) ? 'selected' : '' ?>><?= htmlspecialchars($subjectDisplay) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="search-group">
                                <input type="search" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search...">
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
                        <table>
                            <thead><tr><th>Date</th><th>ID</th><th>Name</th><th>Class</th><th>Subject</th><th class="text-center">Score</th><th>Mode</th></tr></thead>
                            <tbody>
                                <?php foreach ($allRecs as $rec): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($rec['date']))) ?></td>
                                    <td><?= htmlspecialchars($rec['student_id']) ?></td>
                                    <td><?= htmlspecialchars($rec['lastname'] . ', ' . $rec['firstname']) ?></td>
                                    <td><?= htmlspecialchars($rec['class_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($rec['subject_name'] ?? $rec['subject_code'] ?? 'N/A') ?></td>
                                    <td class="text-center"><strong><?= htmlspecialchars($rec['score']) ?></strong></td>
                                    <td><?= htmlspecialchars(ucfirst($rec['mode'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">
                            <span class="material-icons" style="font-size: 48px; margin-bottom: 10px;">folder_open</span>
                            <p>No recitation records found.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_section === 'view_students'): ?>
                <div class="container">
                    <?php if (isset($_GET['rec_success'])): ?><p class="success-message">Recitation score recorded!</p><?php endif; ?>
                    <?php if (isset($_GET['enroll_success'])): ?><p class="success-message">Student enrolled!</p><?php endif; ?>
                    <?php if (isset($_GET['withdraw_success'])): ?><p class="success-message">Student withdrawn from class!</p><?php endif; ?>
                    <?php if (!empty($score_errors)): ?><p class="error-message">Score Submission Failed: <?= implode(" ", $score_errors) ?></p><?php endif; ?>
                    <?php if (isset($add_student_errors["general"])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors["general"]) ?></p><?php endif; ?>
                    
                    <div class="filter-control">
                        <form method="GET" action="" style="width: 100%; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <input type="hidden" name="section" value="view_students">
                            <div class="filter-group">
                                <label for="class_filter">Class:</label>
                                <select name="class_filter" id="class_filter" onchange="this.form.submit()">
                                    <option value="all">All Classes</option>
                                    <?php $unique_classes_view = []; foreach ($allClasses as $class) { $unique_classes_view[$class['class_name']] = $class['subject_name_display'] . ' (' . $class['class_name'] . ')'; } foreach ($unique_classes_view as $className => $subjectDisplay): ?>
                                    <option value="<?= htmlspecialchars($className) ?>" <?= ($selected_class_filter === $className) ? 'selected' : '' ?>><?= htmlspecialchars($subjectDisplay) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="search-group">
                                <input type="search" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search...">
                                <button type="submit" class="btn btn-search"><span class="material-icons">search</span></button>
                            </div>
                        </form>
                    </div>

                    <?php if ($selected_class_filter !== 'all'): ?>
                        <button id="picker-toggle-button">Pick a Student</button>
                        <div id="randomizer-control">
                            <h2>Student Recitation Picker</h2>
                            <button id="btn-fair" class="btn btn-picker" data-mode="Fair"><span class="material-icons">balance</span> Fair Mode</button>
                            <button id="btn-normal" class="btn btn-picker" data-mode="Normal"><span class="material-icons">shuffle</span> Normal Mode</button>
                            <div id="result-display">Click a button to pick a student.</div>
                            <p style="font-size: 0.85em; color: #777;"><strong>Fair Mode:</strong> Prioritizes 0 recitations. <strong>Normal Mode:</strong> Random pick.</p>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #777; margin-bottom: 20px;"><em>Select a specific Class to use the Student Picker.</em></p>
                    <?php endif; ?>

                    <?php if (empty($students)): ?>
                        <div class="no-records">
                            <span class="material-icons" style="font-size: 48px; margin-bottom: 10px;">people_outline</span>
                            <p>No students found.</p>
                        </div>
                    <?php else: ?>
                        <table><thead><tr><th>#</th><th>ID</th><th><a href="?section=view_students&sort=<?= ($sort_order == 'asc' ? 'desc' : 'asc') ?>&sort_col=lastName&class_filter=<?= htmlspecialchars($selected_class_filter) ?>">Name <?= ($sort_column == 'lastName' ? ($sort_order == 'asc' ? '&#9660;' : '&#9650;') : '') ?></a></th>
                        <?php if ($selected_class_filter === 'all'): ?>
                            <th class="text-center">Subjects Enrolled</th>
                        <?php else: ?>
                            <th>Class</th>
                        <?php endif; ?>
                        <th>Status</th><th class="text-center">Recits</th><th class="text-right">Avg Score</th>
                        <?php if ($selected_class_filter !== 'all'): ?>
                            <th class="text-center action-header">Action</th>
                        <?php endif; ?>
                        </tr></thead>
                        <tbody><?php $no = 1; foreach ($students as $student): ?>
                            <tr>
                                <td><?= $no++ ?></td><td><?= htmlspecialchars($student["student_id"]) ?></td>
                                <td><?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"] . ' ' . substr($student["middlename"] ?? '', 0, 1)) ?></td>
                                
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
                                <td class="text-center action-cell">
                                    <button type="button" class="btn btn-withdraw" 
                                            data-studentid="<?= htmlspecialchars($student['student_id']) ?>" 
                                            data-classid="<?= htmlspecialchars($student['class_id']) ?>" 
                                            data-studentname="<?= htmlspecialchars($student['lastname'] . ', ' . $student['firstname']) ?>"
                                            data-classname="<?= htmlspecialchars($student['class_name']) ?>"
                                            title="Withdraw">
                                            <span class="material-icons" style="font-size: 1.2em;">person_remove</span>
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?></tbody>
                        </table>
                    <?php endif; ?>
                    
                    <div id="scoreModal" class="modal">
                        <div class="modal-content">
                            <span class="close-button">&times;</span>
                            <h2>Record Score</h2>
                            <p><strong>Student:</strong> <span id="modal-student-name"></span></p>
                            <p><strong>Mode:</strong> <span id="modal-mode"></span></p>
                            <form action="" method="post" id="scoreForm">
                                <input type="hidden" name="form_action" value="record_recitation">
                                <input type="hidden" name="studentId" id="modal-student-id">
                                <input type="hidden" name="mode" id="modal-mode-input">
                                <input type="hidden" name="subjectCode" id="modal-subject-code-input">
                                <div class="form-group">
                                    <label>Score (0 - <?= $maxScore ?>)</label>
                                    <input type="number" name="score" id="modal-score" min="0" max="<?= $maxScore ?>" step="0.5" required>
                                </div>
                                <div class="form-group">
                                    <label>Date</label>
                                    <input type="date" name="date" id="modal-date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <button type="submit" class="btn btn-submit modal-submit-button">Save Score</button>
                            </form>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_section === 'add_student'): ?>
                <div class="form-container">
                    <h1 class="section-title" style="border:none; margin-bottom: 20px;">Enroll Student</h1>
                    <?php if (isset($add_student_errors["general"])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors["general"]) ?></p><?php endif; ?>
                    <?php if (isset($add_student_errors['general_classes'])): ?><p class="error-message"><?= htmlspecialchars($add_student_errors['general_classes']) ?></p><?php endif; ?>
                    
                    <form action="" method="post">
                        <input type="hidden" name="form_action" value="enroll_student">
                        <div class="form-group">
                            <label for="studentSearchInput">Search Student</label>
                            <input type="text" id="studentSearchInput" list="studentDatalist" placeholder="Type name or ID..." autocomplete="off" required>
                            <datalist id="studentDatalist">
                                <?php foreach ($students_for_enrollment as $student): ?>
                                    <option value="<?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"] . ' (' . $student["student_id"] . ')') ?>" data-value="<?= htmlspecialchars($student["student_id"]) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="studentIdToEnroll" id="studentIdToEnroll">
                        </div>
                        <div class="form-group">
                            <label for="classIdToEnroll">Select Class</label>
                            <select name="classIdToEnroll" id="classIdToEnroll" <?= empty($classes) ? 'disabled' : '' ?> required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?= $class["class_id"] ?>"><?= htmlspecialchars($class["subject_name_display"] . ' (' . $class["class_name"] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-submit" style="width:100%;" <?= empty($classes) ? 'disabled' : '' ?>>Enroll Student</button>
                    </form>
                </div>
            
            <?php elseif ($current_section === 'manage_classes'): ?>
                <div class="container">
                    <?php if (isset($_GET['class_add_success'])): ?><p class="success-message">Class added!</p><?php endif; ?>
                    <?php if (isset($_GET['subject_add_success'])): ?><p class="success-message">Subject added!</p><?php endif; ?>
                    <?php if (isset($_GET['course_add_success'])): ?><p class="success-message">Course added!</p><?php endif; ?>

                    <div class="manage-grid">
                        <div class="manage-card">
                            <h3>Courses</h3>
                            <div>
                                <span class="section-label">Add New</span>
                                <form action="" method="post">
                                    <input type="hidden" name="form_action" value="add_course">
                                    <div class="form-group"><input type="text" name="course_name" placeholder="Course (e.g. BSCS)" required></div>
                                    <button type="submit" class="btn-card-add">Add</button>
                                </form>
                            </div>
                            <div class="manage-bottom-section">
                                <span class="section-label">Delete</span>
                                <form action="" method="post" id="deleteCourseInitialForm">
                                    <div class="form-group">
                                        <select id="courseIdToDelete" name="courseIdToDelete" required>
                                            <option value="">Select Course</option>
                                            <?php foreach ($allCourses as $c): ?><option value="<?= $c["course_id"] ?>" data-name="<?= $c["course_name"] ?>"><?= $c["course_name"] ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="button" id="initiateDeleteCourseButton" class="btn-card-delete">Delete</button>
                                </form>
                            </div>
                        </div>

                        <div class="manage-card">
                            <h3>Subjects</h3>
                            <div>
                                <span class="section-label">Add New</span>
                                <form action="" method="post">
                                    <input type="hidden" name="form_action" value="add_subject">
                                    <div class="form-group"><input type="text" name="subject_code" placeholder="Code (e.g. CS101)" required></div>
                                    <div class="form-group"><input type="text" name="subject_name" placeholder="Name" required></div>
                                    <button type="submit" class="btn-card-add">Add</button>
                                </form>
                            </div>
                            <div class="manage-bottom-section">
                                <span class="section-label">Delete</span>
                                <form action="" method="post" id="deleteSubjectInitialForm">
                                    <div class="form-group">
                                        <select id="subjectCodeToDelete" name="subjectCodeToDelete" required>
                                            <option value="">Select Subject</option>
                                            <?php foreach ($allSubjects as $s): ?><option value="<?= $s["subject_code"] ?>" data-name="<?= $s["subject_name"] ?>"><?= $s['subject_name'] ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="button" id="initiateDeleteSubjectButton" class="btn-card-delete">Delete</button>
                                </form>
                            </div>
                        </div>

                        <div class="manage-card">
                            <h3>Classes</h3>
                            <div>
                                <span class="section-label">Add New</span>
                                <form action="" method="post">
                                    <input type="hidden" name="form_action" value="add_class">
                                    <div class="form-group"><input type="text" name="class_name" placeholder="Class Name (e.g. BSCS-3A)" required></div>
                                    <div class="form-group">
                                        <select name="subject_code" required>
                                            <option value="">Select Subject</option>
                                            <?php foreach ($allSubjects as $s): ?><option value="<?= $s['subject_code'] ?>"><?= $s['subject_name'] ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn-card-add">Add</button>
                                </form>
                            </div>
                            <div class="manage-bottom-section">
                                <span class="section-label">Delete</span>
                                <form action="" method="post" id="deleteClassInitialForm">
                                    <div class="form-group">
                                        <select id="classIdToDelete" name="classIdToDelete" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($allClasses as $c): ?><option value="<?= $c["class_id"] ?>" data-name="<?= $c["class_name"] ?>"><?= $c["subject_name_display"] . ' (' . $c["class_name"] . ')' ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="button" id="initiateDeleteClassButton" class="btn-card-delete">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            
            <?php elseif ($current_section === 'reports'): ?>
                <div class="container">
                    <div id="report-selector-forms">
                        <div class="manage-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 30px;">
                            <div class="manage-card">
                                <h3>Class Report</h3>
                                <form method="GET" action="">
                                    <input type="hidden" name="section" value="reports">
                                    <div class="form-group">
                                        <select name="report_class_name" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($allClasses as $class): ?>
                                                <option value="<?= htmlspecialchars($class["class_name"]) ?>" <?= ($report_class_name === $class["class_name"]) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($class["subject_name_display"] . ' (' . $class["class_name"] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn-card-add">Generate</button>
                                </form>
                            </div>
                            <div class="manage-card">
                                <h3>Student Report</h3>
                                <form method="GET" action="">
                                    <input type="hidden" name="section" value="reports">
                                    <div class="form-group">
                                        <input type="text" id="studentReportSearch" list="studentReportDatalist" placeholder="Search Student..." autocomplete="off" required>
                                        <datalist id="studentReportDatalist">
                                            <?php foreach ($students_for_enrollment as $student): ?>
                                                <option value="<?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"] . ' (' . $student["student_id"] . ')') ?>" data-value="<?= htmlspecialchars($student["student_id"]) ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <input type="hidden" name="report_student_id" id="report_student_id_input">
                                    </div>
                                    <button type="submit" class="btn-card-add">Generate</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div id="report-content">
                        <div class="report-header">
                            <img src="../images/logowmsu.jpg" alt="Logo">
                            <h2>Class Recitation Report</h2>
                            <p>Western Mindanao State University</p>
                        </div>
                        
                        <?php if (!empty($report_class_name) && $report_class_details): ?>
                            <h3>Class: <?= htmlspecialchars($report_class_details['class_name']) ?></h3>
                            <p>Subject: <?= htmlspecialchars($report_class_details['subject_name_display']) ?></p>
                            
                            <?php
                                $total_students = count($report_students);
                                $class_avg_score = 0;
                                if ($total_students > 0) {
                                    $total_score = 0;
                                    foreach ($report_students as $student) { $total_score += $student['averageScore']; }
                                    $class_avg_score = $total_score / $total_students;
                                }
                            ?>
                            <div class="report-summary-grid">
                                <div class="report-summary-box"><h4>Total Students</h4><p><?= $total_students ?></p></div>
                                <div class="report-summary-box"><h4>Class Avg</h4><p><?= number_format($class_avg_score, 2) ?></p></div>
                            </div>
                            
                            <table>
                                <thead><tr><th>ID</th><th>Name</th><th class="text-center">Recitations</th><th class="text-right">Avg Score</th></tr></thead>
                                <tbody>
                                    <?php foreach ($report_students as $student): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($student["student_id"]) ?></td>
                                        <td><?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"]) ?></td>
                                        <td class="text-center"><?= htmlspecialchars($student["totalRecitations"]) ?></td>
                                        <td class="text-right"><?= number_format($student["averageScore"], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif (!empty($report_student_id) && $report_student_details): ?>
                            <h3>Student: <?= htmlspecialchars($report_student_details['lastname'] . ', ' . $report_student_details['firstname']) ?></h3>
                            <p>ID: <?= htmlspecialchars($report_student_details['student_id']) ?></p>
                            
                            <?php foreach($report_student_subjects as $subject): ?>
                                <div style="margin-top: 25px; background: #f9f9f9; padding: 15px; border-radius: 8px;">
                                    <strong><?= htmlspecialchars($subject['subject_name']) ?></strong> (<?= htmlspecialchars($subject['class_name']) ?>)
                                </div>
                                <?php if (!empty($subject['recitations'])): ?>
                                    <table>
                                        <thead><tr><th>Date</th><th class="text-center">Score</th><th>Mode</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($subject['recitations'] as $rec): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($rec['date']))) ?></td>
                                                    <td class="text-center"><?= htmlspecialchars($rec['score']) ?></td>
                                                    <td><?= htmlspecialchars(ucfirst($rec['mode'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p class="no-records" style="padding: 10px;">No records.</p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if ((!empty($report_class_name) && $report_class_details) || (!empty($report_student_id) && $report_student_details)): ?>
                        <div style="text-align: center; margin-top: 30px;" id="printReportButton">
                             <button type="button" class="btn btn-print" onclick="window.print()"><span class="material-icons">print</span> Print</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($current_section === 'delete_student'): ?>
                <div class="form-container">
                    <h1 class="section-title" style="border:none;">Delete Student</h1>
                    <?php if (isset($_GET['delete_success'])): ?><p class="success-message">Student deleted!</p><?php endif; ?>
                    <form action="" method="post">
                        <div class="form-group">
                            <label for="studentSearchDelete">Search Student (Name or ID)</label>
                            <input type="text" id="studentSearchDelete" list="studentDeleteList" placeholder="Type to search..." autocomplete="off" required>
                            <datalist id="studentDeleteList">
                                <?php foreach ($students_for_enrollment as $student): ?>
                                    <option value="<?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"] . ' (' . $student["student_id"] . ')') ?>" data-value="<?= htmlspecialchars($student["student_id"]) ?>" data-name="<?= htmlspecialchars($student["lastname"] . ', ' . $student["firstname"]) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="studentIdToDelete" id="studentIdToDelete">
                        </div>

                        <div class="form-group">
                            <label for="initial_deletion_reason">Reason for Deletion</label>
                            <textarea id="initial_deletion_reason" name="deletion_reason" rows="4" placeholder="Enter the reason why this student is being removed..." required></textarea>
                        </div>

                        <button type="button" id="initiateDeleteButton" class="btn btn-card-delete" style="background:#cc0000; color:white;">Delete Permanently</button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content">
            <span class="modal-close-button">&times;</span>
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete <strong id="deleteStudentName"></strong>?</p>
            <p class="error-message" style="background: #fff0f0; margin-top: 10px;">This action cannot be undone.</p>
            
            <form action="" method="post" id="deleteConfirmForm">
                <input type="hidden" name="form_action" value="confirm_delete_student">
                <input type="hidden" name="studentIdToDeleteConfirmed" id="studentIdToDeleteConfirmed">
                <input type="hidden" name="deletion_reason_confirmed" id="deletion_reason_confirmed">
                
                <div class="modal-footer">
                    <button type="submit" class="btn btn-card-delete" style="background:#cc0000; color:white;">Yes, Delete</button>
                    <button type="button" class="btn btn-cancel" id="cancelDeleteButton">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="withdrawModal" class="modal"><div class="modal-content"><span class="modal-close-button">&times;</span><h3>Withdraw Student</h3><p>Withdraw <strong id="withdrawStudentName"></strong> from <strong id="withdrawClassName"></strong>?</p><form action="" method="post" id="withdrawConfirmForm"><div class="form-group"><label>Reason</label><textarea name="withdraw_reason" id="withdraw_reason" required></textarea></div><input type="hidden" name="form_action" value="withdraw_student"><input type="hidden" name="student_id_to_withdraw" id="student_id_to_withdraw"><input type="hidden" name="class_id_to_withdraw" id="class_id_to_withdraw"><div class="modal-footer"><button type="submit" class="btn btn-card-delete" style="background:#cc0000; color:white;">Withdraw</button><button type="button" class="btn btn-cancel" id="cancelWithdrawButton">Cancel</button></div></form></div></div>

    <div id="deleteClassModal" class="modal"><div class="modal-content"><span class="modal-close-button">&times;</span><h3>Delete Class</h3><p>Delete <strong id="deleteClassName"></strong>?</p><form action="" method="post"><input type="hidden" name="form_action" value="confirm_delete_class"><input type="hidden" name="class_id_to_delete_confirmed" id="class_id_to_delete_confirmed"><div class="modal-footer"><button type="submit" class="btn btn-card-delete" style="background:#cc0000; color:white;">Delete</button><button type="button" class="btn btn-cancel" id="cancelDeleteClassButton">Cancel</button></div></form></div></div>
    
    <div id="deleteCourseModal" class="modal"><div class="modal-content"><span class="modal-close-button">&times;</span><h3>Delete Course</h3><p>Delete <strong id="deleteCourseName"></strong>?</p><form action="" method="post"><input type="hidden" name="form_action" value="confirm_delete_course"><input type="hidden" name="course_id_to_delete_confirmed" id="course_id_to_delete_confirmed"><div class="modal-footer"><button type="submit" class="btn btn-card-delete" style="background:#cc0000; color:white;">Delete</button><button type="button" class="btn btn-cancel" id="cancelDeleteCourseButton">Cancel</button></div></form></div></div>
    
    <div id="deleteSubjectModal" class="modal"><div class="modal-content"><span class="modal-close-button">&times;</span><h3>Delete Subject</h3><p>Delete <strong id="deleteSubjectName"></strong>?</p><form action="" method="post"><input type="hidden" name="form_action" value="confirm_delete_subject"><input type="hidden" name="subject_code_to_delete_confirmed" id="subject_code_to_delete_confirmed"><div class="modal-footer"><button type="submit" class="btn btn-card-delete" style="background:#cc0000; color:white;">Delete</button><button type="button" class="btn btn-cancel" id="cancelDeleteSubjectButton">Cancel</button></div></form></div></div>

    <script>
        // Data & Variables
        const allStudents = JSON.parse('<?= $students_json ?>');
        const maxScore = <?= $maxScore ?>;
        const maxRecitations = <?= $totalPossibleRecitations ?? 10 ?>;
        
        // Element References
        const resultDisplay = document.getElementById('result-display');
        const scoreModal = document.getElementById('scoreModal');
        const randomizerControl = document.getElementById('randomizer-control');
        const pickerToggleButton = document.getElementById('picker-toggle-button');
        const closeButton = scoreModal ? scoreModal.querySelector('.close-button') : null;

        // --- Logic: Student Picker ---
        function togglePicker() {
            if (!randomizerControl) return;
            const isHidden = randomizerControl.style.display === 'none' || randomizerControl.style.display === '';
            randomizerControl.style.display = isHidden ? 'block' : 'none';
             if(pickerToggleButton) {
                pickerToggleButton.innerHTML = isHidden
                    ? '<span class="material-icons" style="vertical-align: middle; margin-right: 5px;">close</span>Hide Picker'
                    : '<span class="material-icons" style="vertical-align: middle; margin-right: 5px;">touch_app</span>Pick a Student';
             }
            if (!isHidden && resultDisplay) {
                resultDisplay.innerHTML = "Click a button to pick a student.";
                resultDisplay.style.color = 'inherit';
            }
        }

        // --- Logic: Modal Openers ---
        function getStudentFullName(student) {
            let fullName = student.firstname + ' ';
            if (student.middlename) { fullName += student.middlename.charAt(0) + '. '; }
            fullName += student.lastname;
            return fullName;
        }

        function openScoreModal(student, mode) {
            if (!scoreModal) return;
            document.getElementById('modal-student-name').textContent = getStudentFullName(student);
            document.getElementById('modal-mode').textContent = mode;
            document.getElementById('modal-student-id').value = student.student_id;
            document.getElementById('modal-mode-input').value = mode;
            document.getElementById('modal-subject-code-input').value = student.subject_code || '';
            document.getElementById('modal-score').value = '';
            scoreModal.style.display = 'flex';
        }

        window.handleScoreButtonClick = function(studentId, mode) {
            const student = allStudents.find(s => s.student_id == studentId);
             if (student && student.subject_code) {
                openScoreModal(student, mode);
            } else {
                alert("Error: Student not assigned to a class/subject.");
            }
        }

        // --- Logic: Picker Buttons ---
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
                 resultDisplay.textContent = "All students have reached max recitations.";
                 resultDisplay.style.color = '#ff9800';
                 return;
             }
            let eligibleStudents = [];
            let rationaleColor = "#555";
            if (mode === 'Fair') {
                eligibleStudents = potentialStudents.filter(s => parseInt(s.totalRecitations) === 0);
                if (eligibleStudents.length === 0) {
                    eligibleStudents = potentialStudents;
                    rationaleColor = '#ff9800';
                } else {
                    rationaleColor = '#28a745';
                }
            } else {
                eligibleStudents = potentialStudents;
                rationaleColor = '#555';
            }
             if (eligibleStudents.length === 0) { resultDisplay.textContent = "No eligible students found!"; return; }
            const randomIndex = Math.floor(Math.random() * eligibleStudents.length);
            const pickedStudent = eligibleStudents[randomIndex];
            resultDisplay.style.color = rationaleColor;
            
            setTimeout(() => {
                if (!pickedStudent || !resultDisplay) return;
                resultDisplay.style.color = '#333';
                resultDisplay.innerHTML = `
                    <div style="text-align: center;">
                        <span style="color: #D32F2F; font-size: 1.3em;"><strong>${getStudentFullName(pickedStudent)}</strong></span><br>
                        <span style="color: #555;">ID: ${pickedStudent.student_id}</span><br>
                        <br>
                        <button onclick="handleScoreButtonClick('${pickedStudent.student_id}', '${mode}')" class="btn btn-record" ${!pickedStudent.subject_code ? 'disabled' : ''}>
                             <span class="material-icons">note_add</span> Record Score
                        </button>
                    </div>`;
            }, 500);
        }

        // --- Logic: Search Inputs ---
        const setupSearch = (inputId, datalistId, hiddenId) => {
            const input = document.getElementById(inputId);
            const hidden = document.getElementById(hiddenId);
            const list = document.getElementById(datalistId);
            if(input && hidden && list) {
                input.addEventListener('input', function() {
                    hidden.value = '';
                    for (let option of list.options) {
                        if (option.value === this.value) {
                            hidden.value = option.dataset.value;
                            break;
                        }
                    }
                });
            }
        };
        setupSearch('studentSearchInput', 'studentDatalist', 'studentIdToEnroll');
        setupSearch('studentReportSearch', 'studentReportDatalist', 'report_student_id_input');
        
        // --- Setup for the Delete Student Search ---
        const deleteSearchInput = document.getElementById('studentSearchDelete');
        const deleteHiddenInput = document.getElementById('studentIdToDelete');
        const deleteDatalist = document.getElementById('studentDeleteList');
        
        if (deleteSearchInput && deleteHiddenInput && deleteDatalist) {
            deleteSearchInput.addEventListener('input', function() {
                deleteHiddenInput.value = '';
                for (let option of deleteDatalist.options) {
                    if (option.value === this.value) {
                        deleteHiddenInput.value = option.dataset.value;
                        // Also store name for modal display later
                        deleteHiddenInput.dataset.studentName = option.dataset.name;
                        break;
                    }
                }
            });
        }

        // --- Logic: Delete/Withdraw Modals ---
        
        // Specific logic for Delete Student Modal to handle Reason transfer
        const initiateDeleteButton = document.getElementById('initiateDeleteButton');
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        
        if (initiateDeleteButton && deleteConfirmModal) {
            initiateDeleteButton.addEventListener('click', () => {
                const studentId = document.getElementById('studentIdToDelete').value;
                const studentName = document.getElementById('studentIdToDelete').dataset.studentName || 'this student';
                const reason = document.getElementById('initial_deletion_reason').value;
                
                if (!studentId) {
                    alert("Please select a student first.");
                    return;
                }
                
                // Populate Modal Data
                document.getElementById('deleteStudentName').textContent = studentName;
                document.getElementById('studentIdToDeleteConfirmed').value = studentId;
                document.getElementById('deletion_reason_confirmed').value = reason; // Copy reason to hidden input in modal
                
                deleteConfirmModal.style.display = 'flex';
            });
        }
        if (document.getElementById('cancelDeleteButton')) {
            document.getElementById('cancelDeleteButton').addEventListener('click', () => deleteConfirmModal.style.display = 'none');
        }

        const setupModal = (btnId, modalId, selectId, nameId, hiddenId, confirmBtnId, cancelBtnId) => {
            const btn = document.getElementById(btnId);
            const modal = document.getElementById(modalId);
            const select = document.getElementById(selectId);
            if(btn && modal && select) {
                btn.addEventListener('click', () => {
                    const option = select.options[select.selectedIndex];
                    if(!option.value) { alert("Please select an option."); return; }
                    document.getElementById(nameId).textContent = option.dataset.name || option.text;
                    document.getElementById(hiddenId).value = option.value;
                    modal.style.display = 'flex';
                });
            }
            if(document.getElementById(cancelBtnId)) {
                document.getElementById(cancelBtnId).addEventListener('click', () => modal.style.display = 'none');
            }
        };

        // Note: Removed the standard setupModal for delete_student because we implemented custom logic above for the search bar + reason.
        setupModal('initiateDeleteClassButton', 'deleteClassModal', 'classIdToDelete', 'deleteClassName', 'class_id_to_delete_confirmed', 'deleteClassConfirmForm', 'cancelDeleteClassButton');
        setupModal('initiateDeleteCourseButton', 'deleteCourseModal', 'courseIdToDelete', 'deleteCourseName', 'course_id_to_delete_confirmed', 'deleteCourseConfirmForm', 'cancelDeleteCourseButton');
        setupModal('initiateDeleteSubjectButton', 'deleteSubjectModal', 'subjectCodeToDelete', 'deleteSubjectName', 'subject_code_to_delete_confirmed', 'deleteSubjectConfirmForm', 'cancelDeleteSubjectButton');

        // Special case for Withdraw (button inside table)
        document.querySelectorAll('.btn-withdraw').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('withdrawStudentName').textContent = this.dataset.studentname;
                document.getElementById('withdrawClassName').textContent = this.dataset.classname;
                document.getElementById('student_id_to_withdraw').value = this.dataset.studentid;
                document.getElementById('class_id_to_withdraw').value = this.dataset.classid;
                document.getElementById('withdrawModal').style.display = 'flex';
            });
        });
        if(document.getElementById('cancelWithdrawButton')) {
            document.getElementById('cancelWithdrawButton').addEventListener('click', () => document.getElementById('withdrawModal').style.display = 'none');
        }

        // Global Modal Close (Click Outside or X)
        window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = "none"; }
        document.querySelectorAll('.close-button, .modal-close-button').forEach(btn => btn.onclick = function() { this.closest('.modal').style.display = 'none'; });
        if(pickerToggleButton) pickerToggleButton.addEventListener('click', togglePicker);

        // --- Charts ---
        if (document.getElementById('engagementChart')) {
            new Chart(document.getElementById('engagementChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Engaged', 'Not Engaged'],
                    datasets: [{
                        data: [<?= $chart_engaged ?>, <?= $chart_total - $chart_engaged ?>],
                        backgroundColor: ['#cc0000', '#eee'],
                        borderWidth: 0
                    }]
                }
            });
        }
        if (document.getElementById('subjectChart')) {
            new Chart(document.getElementById('subjectChart'), {
                type: 'bar',
                data: {
                    labels: <?= $chart_labels_json ?>,
                    datasets: [{
                        label: 'Recitations',
                        data: <?= $chart_data_json ?>,
                        backgroundColor: '#cc0000',
                        borderRadius: 4
                    }]
                },
                options: {
                    scales: { y: { beginAtZero: true, grid: { display: false } }, x: { grid: { display: false } } },
                    plugins: { legend: { display: false } }
                }
            });
        }
    </script>
</body>
</html>