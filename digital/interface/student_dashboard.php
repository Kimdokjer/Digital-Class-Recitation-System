<?php
session_start();

require_once "../classes/studentmanager.php"; 


if (!isset($_SESSION['loggedin']) || $_SESSION['user_role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$manager = new studentmanager();
$current_student_id = $_SESSION['student_id']; 

try {
    $notif_conn = $manager->connect();
    
  
    if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $stmt_mark_all = $notif_conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE student_id = :student_id AND is_read = 0
        ");
        $stmt_mark_all->execute([':student_id' => $current_student_id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
  
    if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
        $notif_id_to_mark = $_GET['mark_read'];
        $stmt_mark_one = $notif_conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE notification_id = :notif_id AND student_id = :student_id
        ");
        $stmt_mark_one->execute([':notif_id' => $notif_id_to_mark, ':student_id' => $current_student_id]);
        
      
        $stmt_get_link = $notif_conn->prepare("SELECT link FROM notifications WHERE notification_id = ?");
        $stmt_get_link->execute([$notif_id_to_mark]);
        $notif_link = $stmt_get_link->fetchColumn();

        if ($notif_link) {
             header("Location: " . $notif_link); 
        } else {
             header("Location: student_dashboard.php"); 
        }
        exit;
    }
    
  
    $stmt_notif = $notif_conn->prepare("
        SELECT * FROM notifications 
        WHERE student_id = :studentId 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt_notif->execute([':studentId' => $current_student_id]);
    $notifications = $stmt_notif->fetchAll();

    $unread_count = 0;
    foreach ($notifications as $notif) {
        if ($notif['is_read'] == 0) {
            $unread_count++;
        }
    }
} catch (PDOException $e) {
    error_log("Notification Error: " . $e->getMessage());
    $notifications = [];
    $unread_count = 0;
}




$studentId = $_SESSION['username']; 
$studentData = $manager->fetchStudent($studentId);


$displayName = !empty($studentData['nickname']) ? $studentData['nickname'] : ($studentData['firstname'] ?? 'Student');

$enrolledSubjects = $manager->getStudentEnrolledSubjects($studentId); 

$view = $_GET['view'] ?? 'dashboard';
$subjectCodeToShow = null;
$recitations = [];
$averageScore = 0;
$subjectName = '';
$maxScore = 10;
$totalPossibleRecitations = 10;

if ($view === 'subject' && isset($_GET['code'])) {
    $subjectCodeToShow = $_GET['code'];
    
    $isEnrolled = false;
    foreach ($enrolledSubjects as $subject) {
        if ($subject['subject_code'] === $subjectCodeToShow) {
            $isEnrolled = true;
            $subjectName = $subject['subject_name'];
            break;
        }
    }

    if ($isEnrolled) {
        $recitations = $manager->getStudentRecitations($studentId, $subjectCodeToShow);
        $averageScore = $manager->getStudentAverageScore($studentId, $subjectCodeToShow);
    } else {
        header("Location: student_dashboard.php");
        exit;
    }
}

header('X-Frame-Options: SAMEORIGIN');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - <?= htmlspecialchars($displayName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    
    <style>
        
        :root {
            --primary: #cc0000;           
            --primary-dark: #8a0000;      
            --bg-body: #eef2f5;           
            --bg-sidebar: #cc0000;        
            --text-sidebar: #ffffff;      
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

        
        .sidebar { 
            width: 250px; 
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
            padding: 20px 15px; 
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .sidebar-header img { 
            width: 60px; height: 60px; 
            border-radius: 50%; 
            padding: 3px; 
            object-fit: cover; 
            border: 2px solid rgba(255,255,255,0.5);
            background: white;
            margin-bottom: 10px; 
        }
        .sidebar-header h2 { 
            color: white; 
            margin: 0; 
            font-size: 1.1em; 
            font-weight: 700; 
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar-nav { padding: 15px 0; flex-grow: 1; overflow-y: auto; }
        .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
        
        .sidebar-nav ul li a { 
            display: flex; align-items: center; 
            padding: 14px 20px; 
            color: rgba(255,255,255,0.9); 
            text-decoration: none; 
            font-size: 0.9em; 
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        
        .sidebar-nav ul li a .material-icons { 
            margin-right: 12px; font-size: 20px; color: rgba(255,255,255,0.8);
            transition: color 0.2s;
        }
        
        
        .sidebar-nav ul li a:hover { 
            background-color: rgba(255,255,255,0.1); 
            color: white; 
        }
        
        
        .sidebar-nav ul li a.active { 
            background-color: var(--bg-body); 
            color: var(--primary); 
            font-weight: 700; 
            border-left: 4px solid var(--primary-dark);
            border-radius: 30px 0 0 30px;
            margin-left: 10px;
        }
        .sidebar-nav ul li a.active .material-icons { color: var(--primary); }

       
        .logout-container { 
            padding: 10px 0; 
            border-top: 1px solid rgba(255,255,255,0.2); 
            margin-top: auto;
        }
        .logout-btn { 
            display: flex; 
            align-items: center;
            padding: 14px 20px; 
            width: 100%;
            background: none; 
            border: none;
            color: rgba(255,255,255,0.9); 
            font-size: 0.9em; 
            font-weight: 500; 
            text-decoration: none; 
            transition: background 0.3s ease; 
            justify-content: flex-start; 
            cursor: pointer;
            border-left: 4px solid transparent;
        }
        .logout-btn .material-icons {
            margin-right: 12px; 
            font-size: 20px; 
            color: rgba(255,255,255,0.8);
        }
        .logout-btn:hover { 
            background-color: rgba(255,255,255,0.1); 
            color: white; 
        }

      
        .main-wrapper { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }

       
        .top-navbar { 
            background-color: #ffffff; 
            padding: 12px 30px; 
            border-bottom: 1px solid var(--border-color);
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky; 
            top: 0; 
            z-index: 100; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .top-navbar h1 { margin: 0; color: var(--text-main); font-size: 1.3em; font-weight: 700; }
        
        .user-menu { display: flex; align-items: center; gap: 15px; }
        
        .user-details { text-align: right; line-height: 1.3; }
        .user-details span { display: block; font-weight: 600; font-size: 0.9em; color: var(--text-main); }
        .user-details small { display: block; font-size: 0.8em; color: var(--text-muted); }
        
        .avatar-circle {
            width: 35px; height: 35px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 1em;
        }

       
        .main-content { padding: 30px; flex-grow: 1; }

        .content-header { 
            margin-bottom: 25px; 
            display: flex; justify-content: space-between; align-items: center;
        }
        .content-header h2 { margin: 0; font-size: 1.5em; color: var(--text-main); }
        .breadcrumb { font-size: 0.9em; color: var(--text-muted); }

       
        .notification-bell { position: relative; cursor: pointer; }
        .notification-bell .material-icons { font-size: 24px; color: #757575; transition: color 0.2s; }
        .notification-bell:hover .material-icons { color: var(--primary); }
        
        .badge {
            position: absolute; top: -4px; right: -4px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            width: 16px; height: 16px;
            font-size: 10px;
            display: flex; justify-content: center; align-items: center;
            font-weight: bold;
            border: 2px solid #fff;
        }

        .notification-dropdown {
            display: none;
            position: absolute;
            top: 45px; right: -5px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            width: 300px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1001;
            border: 1px solid var(--border-color);
        }
        .notification-dropdown-header {
            padding: 12px;
            font-weight: 700;
            font-size: 0.9em;
            border-bottom: 1px solid var(--border-color);
            display: flex; justify-content: space-between; align-items: center;
            background-color: #fff;
            position: sticky; top: 0;
        }
        .mark-all-read { font-size: 0.8em; color: var(--primary); text-decoration: none; font-weight: 600; }
        .mark-all-read:hover { text-decoration: underline; }
        
        .notification-item { display: block; padding: 12px; border-bottom: 1px solid #f9f9f9; text-decoration: none; color: var(--text-main); transition: background 0.2s; }
        .notification-item:hover { background-color: #f9f9f9; }
        .notification-item.unread { background-color: #fff0f0; border-left: 3px solid var(--primary); }
        .notification-item p { margin: 0; font-size: 0.85em; line-height: 1.4; }
        .notification-item small { font-size: 0.7em; color: #999; margin-top: 4px; display: block; }
        .no-notifications { text-align: center; padding: 20px; color: #999; font-size: 0.9em; }

       
        .section-title { font-size: 1.1em; color: var(--text-muted); margin-bottom: 20px; font-weight: 600; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }

        .course-card { 
            background-color: #fff; 
            border-radius: 10px; 
            box-shadow: var(--shadow-subtle); 
            overflow: hidden; 
            text-decoration: none; 
            color: inherit; 
            transition: all 0.3s ease; 
            border: 1px solid var(--border-color);
            border-top: 4px solid var(--primary); 
            display: flex; flex-direction: column;
        }
        .course-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }

        .card-header { 
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .subject-code { 
            background-color: #fff0f0; color: var(--primary); 
            padding: 4px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8em;
        }
        
        .card-body { padding: 20px; flex-grow: 1; }
        .card-body h4 { margin: 0 0 5px 0; font-size: 1.05em; color: var(--text-main); font-weight: 700; }
        .card-body p { margin: 0 0 15px 0; font-size: 0.9em; color: var(--text-muted); }
        
        .stats-row { 
            display: flex; justify-content: space-between; 
            margin-top: 15px; padding-top: 15px; 
            border-top: 1px solid #f5f5f5; font-size: 0.85em; color: var(--text-muted);
        }
        .stats-row strong { color: var(--text-main); }

        .progress-container { margin-top: 10px; }
        .progress-bar-bg { background-color: #eee; height: 6px; border-radius: 3px; overflow: hidden; }
        .progress-bar-fill { background-color: var(--primary); height: 100%; transition: width 0.5s ease; }
        .progress-text { font-size: 0.75em; color: #999; margin-top: 4px; text-align: right; display: block; }

       
        .back-link { 
            display: inline-flex; align-items: center; 
            margin-bottom: 20px; color: var(--text-muted); 
            text-decoration: none; font-weight: 600; font-size: 0.9em;
            transition: color 0.2s;
        }
        .back-link:hover { color: var(--primary); }
        .back-link .material-icons { font-size: 18px; margin-right: 5px; }

        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .info-card { 
            background: #fff; padding: 20px; border-radius: 10px; 
            box-shadow: var(--shadow-subtle); text-align: center; border: 1px solid var(--border-color);
        }
        .info-card h5 { margin: 0 0 8px 0; font-size: 0.85em; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
        .info-card .value { font-size: 2em; color: var(--primary); font-weight: 700; line-height: 1; }

        table { 
            width: 100%; border-collapse: separate; border-spacing: 0; 
            background: #fff; border-radius: 10px; 
            box-shadow: var(--shadow-subtle); overflow: hidden;
            border: 1px solid var(--border-color);
        }
        th { 
            background-color: #fff; color: var(--text-muted); 
            font-weight: 700; text-transform: uppercase; font-size: 0.75em; 
            padding: 12px 20px; text-align: left; border-bottom: 2px solid #f0f0f0;
        }
        td { padding: 12px 20px; border-bottom: 1px solid #f0f0f0; color: var(--text-main); font-size: 0.9em; }
        tr:last-child td { border-bottom: none; }
        .score-val { font-weight: 700; color: var(--primary); }
        .mode-badge { 
            background: #f4f6f9; color: var(--text-muted); 
            padding: 4px 8px; border-radius: 20px; font-size: 0.75em; font-weight: 600; border: 1px solid #e0e0e0;
        }
        .no-records { 
            text-align: center; padding: 40px; background: #fff; 
            border-radius: 10px; color: var(--text-muted); box-shadow: var(--shadow-subtle); 
        }

      
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .sidebar { 
                width: 100%; height: auto; position: relative; 
                flex-direction: row; justify-content: space-around; 
                border-right: none; border-bottom: 1px solid var(--border-color);
                padding: 0;
            }
            .sidebar-header, .logout-container { display: none; }
            .sidebar-nav ul li a { padding: 10px; flex-direction: column; font-size: 0.75em; text-align: center; border-left: none; border-bottom: 3px solid transparent; }
            .sidebar-nav ul li a .material-icons { margin: 0 0 5px 0; font-size: 22px; }
            .sidebar-nav ul li a.active { background: none; border-left: none; border-bottom: 3px solid var(--primary); color: var(--primary); }
            
            .top-navbar { padding: 12px 15px; }
            .top-navbar h1 { font-size: 1.1em; }
            .user-details { display: none; }
            .main-content { padding: 15px; }
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
                <li>
                    <a href="student_dashboard.php" class="<?= ($view === 'dashboard' ? 'active' : '') ?>">
                        <span class="material-icons">dashboard</span>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="student_dashboard.php" class="<?= ($view !== 'dashboard' ? 'active' : '') ?>">
                        <span class="material-icons">school</span>
                        My Courses
                    </a>
                </li>
            </ul>
        </nav>
        <div class="logout-container">
            <a href="logout.php" class="logout-btn">
                <span class="material-icons">logout</span>
                Logout
            </a>
        </div>
    </div>

    <div class="main-wrapper">
        
        <nav class="top-navbar">
            <h1>Student Dashboard</h1>
            
            <div class="user-menu">
                <div style="position: relative;">
                    <div class="notification-bell" id="notificationBell">
                        <span class="material-icons">
                            <?= ($unread_count > 0) ? 'notifications_active' : 'notifications' ?>
                        </span>
                        <?php if ($unread_count > 0): ?>
                            <span class="badge" id="notificationBadge"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-dropdown-header">
                            <span>Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <a href="?mark_read=all" class="mark-all-read" id="markAllReadLink">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div id="notificationList">
                            <?php if (empty($notifications)): ?>
                                <p class="no-notifications">No new notifications.</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <a href="<?= htmlspecialchars($notif['link']) ?>&mark_read=<?= $notif['notification_id'] ?>" 
                                       class="notification-item <?= $notif['is_read'] == 0 ? 'unread' : '' ?>"
                                       data-id="<?= $notif['notification_id'] ?>">
                                        <p><?= $notif['message'] ?></p>
                                        <small><?= htmlspecialchars(date('M d, Y h:i A', strtotime($notif['created_at']))) ?></small>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="user-details">
                    <span><?= htmlspecialchars($displayName) ?></span>
                    <small><?= htmlspecialchars($studentData['email'] ?? '') ?></small>
                </div>
                <a href="edit_profile.php" title="Edit Profile" style="text-decoration: none;">
                    <div class="avatar-circle">
                        <?= strtoupper(substr($displayName, 0, 1)) ?>
                    </div>
                </a>
            </div>
        </nav>

        <main class="main-content">
            <?php if ($view === 'subject'): ?>
                <a href="student_dashboard.php" class="back-link">
                    <span class="material-icons">arrow_back</span> Back to Dashboard
                </a>
                
                <div class="content-header">
                    <h2><?= htmlspecialchars($subjectName) ?></h2>
                    <span class="breadcrumb">Recitation Records</span>
                </div>

                <div class="info-grid">
                    <div class="info-card">
                        <h5>Total Recitations</h5>
                        <div class="value"><?= count($recitations) ?></div>
                    </div>
                    <div class="info-card">
                        <h5>Average Score</h5>
                        <div class="value"><?= number_format($averageScore, 2) ?></div>
                    </div>
                </div>

                <?php if (!empty($recitations)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Score</th>
                                <th>Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recitations as $rec): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M d, Y • h:i A', strtotime($rec['date']))) ?></td>
                                    <td><span class="score-val"><?= htmlspecialchars($rec['score']) ?></span> / <?= $maxScore ?></td>
                                    <td><span class="mode-badge"><?= htmlspecialchars(ucfirst($rec['mode'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-records">
                        <span class="material-icons" style="font-size: 36px; color: #ddd; margin-bottom: 10px;">assignment_off</span>
                        <p>No recitation records found for this subject.</p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="content-header">
                     <h2>Overview</h2>
                     <span class="breadcrumb"><?= date('l, F j, Y') ?></span>
                </div>
                
                 <div class="section-title">Enrolled Courses</div>
                 
                 <?php if (!empty($enrolledSubjects)): ?>
                     <div class="course-grid">
                        <?php foreach ($enrolledSubjects as $subject): 
                            $subjectRecitations = $manager->getStudentRecitations($studentId, $subject['subject_code']);
                            $subjectAverage = $manager->getStudentAverageScore($studentId, $subject['subject_code']);
                            $recitCount = count($subjectRecitations);
                            $completionPercentage = 0;
                            if ($totalPossibleRecitations > 0) {
                                $completionPercentage = min(100, round(($recitCount / $totalPossibleRecitations) * 100));
                            }
                        ?>
                         <a href="student_dashboard.php?view=subject&code=<?= htmlspecialchars($subject['subject_code']) ?>" class="course-card">
                            <div class="card-header">
                                <span class="subject-code"><?= htmlspecialchars($subject['subject_code']) ?></span>
                                <span class="material-icons" style="color: #ccc;">more_horiz</span>
                            </div>
                             <div class="card-body">
                                 <h4><?= htmlspecialchars($subject['subject_name']) ?></h4>
                                 <p><?= htmlspecialchars($subject['class_name']) ?></p>
                                 
                                 <div class="progress-container">
                                     <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?= $completionPercentage ?>%;"></div>
                                     </div>
                                     <span class="progress-text"><?= $completionPercentage ?>% of target</span>
                                 </div>
                                 
                                 <div class="stats-row">
                                     <span>Recitations: <strong><?= $recitCount ?></strong></span>
                                     <span>Avg: <strong><?= number_format($subjectAverage, 2) ?></strong></span>
                                 </div>
                             </div>
                         </a>
                        <?php endforeach; ?>
                     </div>
                 <?php else: ?>
                     <div class="no-records">
                         <span class="material-icons" style="font-size: 36px; color: #ddd; margin-bottom: 10px;">class_off</span>
                         <p>You are not enrolled in any subjects yet.</p>
                     </div>
                 <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notificationBell');
    const dropdown = document.getElementById('notificationDropdown');
    const markAllRead = document.getElementById('markAllReadLink');

    if (bell) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation(); 
            const isHidden = dropdown.style.display === 'none' || dropdown.style.display === '';
            dropdown.style.display = isHidden ? 'block' : 'none';
        });
    }

    document.addEventListener('click', function(e) {
        if (dropdown && dropdown.style.display === 'block') {
            if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        }
    });

    if (markAllRead) {
        markAllRead.addEventListener('click', function(e) {
            e.preventDefault(); 
            
            fetch('?mark_read=all', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' } 
            })
            .then(response => response.json()) 
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                    });
                    const badge = document.getElementById('notificationBadge');
                    if (badge) {
                        badge.style.display = 'none';
                    }
                    const icon = bell.querySelector('.material-icons');
                    if (icon) {
                        icon.textContent = 'notifications';
                    }
                    markAllRead.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error marking all as read:', error);
                window.location.href = '?mark_read=all';
            });
        });
    }
});
</script>
</body>
</html>