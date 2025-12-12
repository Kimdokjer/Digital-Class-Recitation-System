<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; 
require_once __DIR__ . '/../config/mail_config.php'; 
require_once "database.php";

class studentmanager extends Database {

    public $studentId = "";
    public $classId = "";
    public $courseName = "";
    public $email = ""; 
    public $password = "";
    public $lastName = "";
    public $firstName = "";
    public $middleName = "";
    public $gender = "";
    public $birthDate = "";

    public $recitationStudentId;
    public $recitationSubjectCode;
    public $recitationDate;
    public $recitationScore;
    public $recitationMode;

    // --- HELPER: SEND EMAIL VIA PHPMAILER ---
    private function sendEmail($toEmail, $toName, $subject, $htmlBody) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody); 

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    // --- FEATURE: REGISTRATION (SEND CODE) ---
    public function sendVerificationEmail($studentId, $email, $firstName) {
        try {
            $conn = $this->connect();
            
            // 1. Generate 6-digit code
            $token = random_int(100000, 999999);
            
            // 2. Set Expiry (Manila Time)
            $expires = new DateTime('now', new DateTimeZone('Asia/Manila')); 
            $expires->add(new DateInterval('PT1H')); 
            $expiresStr = $expires->format('Y-m-d H:i:s');

            // 3. Delete old codes for this student
            $conn->prepare("DELETE FROM user_verification WHERE student_id = ?")->execute([$studentId]);

            // 4. Insert new code
            $sql = "INSERT INTO user_verification (token, student_id, expires_at) VALUES (:token, :studentId, :expires)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':token' => $token, ':studentId' => $studentId, ':expires' => $expiresStr]);

            // 5. Create Email Body
            $subject = "Your Verification Code - Recitation System";
            $body = "
                <html><body>
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #D32F2F; text-align: center;'>Verification Code</h2>
                    <p style='font-size: 1.1em; text-align: center;'>Hello " . htmlspecialchars($firstName) . ",</p>
                    <p style='text-align: center;'>Please enter the code below to verify your account. This code is valid for 1 hour.</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <span style='display: inline-block; padding: 15px 30px; background-color: #f4f4f4; color: #333; border: 2px dashed #D32F2F; font-size: 2em; font-weight: bold; letter-spacing: 5px;'>
                            " . $token . "
                        </span>
                    </div>
                    
                    <p style='font-size: 0.9em; color: #777; text-align: center;'>If you did not register for this account, please ignore this email.</p>
                </div>
                </body></html>
            ";

            return $this->sendEmail($email, $firstName, $subject, $body);

        } catch (Exception $e) {
            error_log("Verification Email Error: " . $e->getMessage());
            return false;
        }
    }

    // --- FEATURE: REGISTRATION (VERIFY CODE) ---
    public function verifyRegistration($email, $code) {
        try {
            $conn = $this->connect();
            
            // Get current time in Manila
            $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
            $nowStr = $now->format('Y-m-d H:i:s');

            // Fetch the code linked to the email
            $sql = "SELECT v.id, v.student_id, v.expires_at 
                    FROM user_verification v
                    JOIN students s ON v.student_id = s.student_id
                    WHERE s.email = :email AND v.token = :code";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':email' => $email, ':code' => $code]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                return "Invalid verification code.";
            }

            // Check expiration
            if ($nowStr > $record['expires_at']) {
                return "This code has expired. Please request a new one.";
            }

            // Success: Verify User & Delete Code
            $conn->beginTransaction();
            $conn->prepare("UPDATE users SET is_verified = 1 WHERE student_id = ?")->execute([$record['student_id']]);
            $conn->prepare("DELETE FROM user_verification WHERE id = ?")->execute([$record['id']]);
            $conn->commit();

            return true;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return "System error: " . $e->getMessage();
        }
    }

    // --- FEATURE: FORGOT PASSWORD (SEND CODE) ---
    public function sendPasswordResetEmail($email) {
        $conn = $this->connect();
        
        // 1. Check if email exists
        $stmt = $conn->prepare("SELECT student_id, firstname FROM students WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $student = $stmt->fetch();

        if (!$student) {
            return "Email not found in our records.";
        }

        // 2. Generate Code
        $code = random_int(100000, 999999);
        $expires = new DateTime('now', new DateTimeZone('Asia/Manila')); 
        $expires->add(new DateInterval('PT1H')); 
        $expiresStr = $expires->format('Y-m-d H:i:s');

        // 3. Store Code (Delete old codes first)
        $conn->prepare("DELETE FROM user_verification WHERE student_id = ?")->execute([$student['student_id']]);
        
        $sql = "INSERT INTO user_verification (token, student_id, expires_at) VALUES (:token, :sid, :exp)";
        $conn->prepare($sql)->execute([':token' => $code, ':sid' => $student['student_id'], ':exp' => $expiresStr]);

        // 4. Send Email
        $subject = "Reset Your Password - Recitation System";
        $body = "
            <html><body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <h2 style='color: #D32F2F; text-align: center;'>Password Reset Request</h2>
                <p style='text-align: center;'>We received a request to reset your password. Use the code below to proceed.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <span style='display: inline-block; padding: 15px 30px; background-color: #f4f4f4; color: #333; border: 2px dashed #D32F2F; font-size: 2em; font-weight: bold; letter-spacing: 5px;'>
                        " . $code . "
                    </span>
                </div>
                <p style='font-size: 0.9em; color: #777; text-align: center;'>If you did not request a password reset, please ignore this email.</p>
            </div>
            </body></html>
        ";

        if ($this->sendEmail($email, $student['firstname'], $subject, $body)) {
            return true;
        }
        return "Failed to send email. Please check your mail server settings.";
    }

    // --- FEATURE: FORGOT PASSWORD (RESET) ---
    public function resetStudentPassword($email, $code, $newPassword) {
        $conn = $this->connect();
        
        // Timezone Check
        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $nowStr = $now->format('Y-m-d H:i:s');

        // 1. Validate Code
        $sql = "SELECT v.id, s.student_id, v.expires_at FROM user_verification v 
                JOIN students s ON v.student_id = s.student_id 
                WHERE s.email = :email AND v.token = :code";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':email' => $email, ':code' => $code]);
        $verify = $stmt->fetch();

        if (!$verify) {
            return "Invalid verification code.";
        }
        if ($nowStr > $verify['expires_at']) {
            return "Code has expired.";
        }

        // 2. Update Password
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password_hash = :hash WHERE student_id = :sid");
        $upd->execute([':hash' => $hash, ':sid' => $verify['student_id']]);

        // 3. Consume Token
        $conn->prepare("DELETE FROM user_verification WHERE id = ?")->execute([$verify['id']]);

        return true;
    }

    // --- NOTIFICATION: ENROLLMENT ---
    public function sendEnrollmentNotification($studentId, $classId) {
        try {
            $conn = $this->connect();
            
            $studentData = $this->fetchStudent($studentId);
            if (!$studentData) { return false; }

            $sql_class = "SELECT c.class_name, s.subject_name, s.subject_code
                          FROM class_sections c
                          JOIN subjects s ON c.subject_code = s.subject_code
                          WHERE c.class_id = :classId LIMIT 1";
            $stmt_class = $conn->prepare($sql_class);
            $stmt_class->execute([':classId' => $classId]);
            $classData = $stmt_class->fetch();
            
            if (!$classData) { return false; }

            $studentName = htmlspecialchars($studentData['firstname']);
            $subjectName = htmlspecialchars($classData['subject_name']);
            $className = htmlspecialchars($classData['class_name']);
            $subjectCode = htmlspecialchars($classData['subject_code']);
            
            $subject_link = "http://localhost/DIGITAL/interface/student_dashboard.php?view=subject&code=" . $subjectCode;

            $subject = "You've been enrolled in a new subject!";
            $body = "
                <html><body>
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #D32F2F; text-align: center;'>Hi, " . $studentName . "!</h2>
                    <p style='font-size: 1.1em; text-align: center;'>You have been enrolled in a new subject by your professor.</p>
                    <ul style='list-style-type: none; padding-left: 0; background-color: #f9f9f9; border-radius: 5px; padding: 15px;'>
                        <li style='margin-bottom: 10px;'><strong>Subject:</strong> " . $subjectName . "</li>
                        <li style='margin-bottom: 10px;'><strong>Class Section:</strong> " . $className . "</li>
                    </ul>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='" . $subject_link . "' style='display: inline-block; padding: 14px 24px; background-color: #D32F2F; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 1.1em;'>
                            View Subject
                        </a>
                    </div>
                </div>
                </body></html>
            ";

            $this->sendEmail($studentData['email'], $studentName, $subject, $body);

            $message = "You have been enrolled in <strong>" . $subjectName . " (" . $className . ")</strong>.";
            $link = "student_dashboard.php?view=subject&code=" . $subjectCode;
            $sql_notif = "INSERT INTO notifications (student_id, message, link) VALUES (?, ?, ?)";
            $conn->prepare($sql_notif)->execute([$studentId, $message, $link]);
            
            return true;

        } catch (Exception $e) {
            error_log("Enrollment Notification Error: " . $e->getMessage());
            return false;
        }
    }

    // --- NOTIFICATION: WITHDRAWAL ---
    public function sendWithdrawalNotification($studentId, $classId, $reason) {
        try {
            $conn = $this->connect();
            
            $studentData = $this->fetchStudent($studentId);
            if (!$studentData) { return false; }

            $sql_class = "SELECT c.class_name, s.subject_name
                          FROM class_sections c
                          JOIN subjects s ON c.subject_code = s.subject_code
                          WHERE c.class_id = :classId LIMIT 1";
            $stmt_class = $conn->prepare($sql_class);
            $stmt_class->execute([':classId' => $classId]);
            $classData = $stmt_class->fetch();
            
            if (!$classData) {
                $subjectName = "a subject";
                $className = "a class";
            } else {
                $subjectName = htmlspecialchars($classData['subject_name']);
                $className = htmlspecialchars($classData['class_name']);
            }

            $studentName = htmlspecialchars($studentData['firstname']);
            $login_link = "http://localhost/DIGITAL/interface/login.php";

            $subject = "You have been withdrawn from a subject";
            $body = "
                <html><body>
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #D32F2F; text-align: center;'>Hi, " . $studentName . "!</h2>
                    <p style='font-size: 1.1em; text-align: center;'>You have been withdrawn from a subject by your professor.</p>
                    <ul style='list-style-type: none; padding-left: 0; background-color: #f9f9f9; border-radius: 5px; padding: 15px;'>
                        <li style='margin-bottom: 10px;'><strong>Subject:</strong> " . $subjectName . "</li>
                        <li style='margin-bottom: 10px;'><strong>Class Section:</strong> " . $className . "</li>
                        <li style='margin-bottom: 10px;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</li>
                    </ul>
                    <p style='text-align: center; margin-top: 20px;'>Please contact your professor if you have any questions. You can log into the <a href='" . $login_link . "' style='color: #D32F2F; text-decoration: none; font-weight: bold;'>Digital Recitation System</a> to view your other subjects.</p>
                </div>
                </body></html>
            ";
            $this->sendEmail($studentData['email'], $studentName, $subject, $body);

            $message = "You were withdrawn from <strong>" . $subjectName . "</strong>. Reason: " . htmlspecialchars($reason);
            $link = "student_dashboard.php"; 
            $sql_notif = "INSERT INTO notifications (student_id, message, link) VALUES (?, ?, ?)";
            $conn->prepare($sql_notif)->execute([$studentId, $message, $link]);
            
            return true;

        } catch (Exception $e) {
            error_log("Withdrawal Notification Error: " . $e->getMessage());
            return false;
        }
    }

    // --- RECITATION: ADD GRADE ---
    public function addRecitation() {
        $sql = "INSERT INTO recits (student_id, subject_code, date, score, mode) VALUES (:studentId, :subjectCode, :date, :score, :mode)";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":studentId", $this->recitationStudentId);
        $query->bindParam(":subjectCode", $this->recitationSubjectCode);
        $query->bindParam(":date", $this->recitationDate);
        $query->bindParam(":score", $this->recitationScore);
        $query->bindParam(":mode", $this->recitationMode);
        
        if ($query->execute()) {
            try {
                $studentData = $this->fetchStudent($this->recitationStudentId);
                
                if ($studentData) {
                    $subjectName = $this->recitationSubjectCode; 
                    try {
                        $stmt_sub = $this->connect()->prepare("SELECT subject_name FROM subjects WHERE subject_code = ?");
                        $stmt_sub->execute([$this->recitationSubjectCode]);
                        $subject = $stmt_sub->fetch();
                        if ($subject) $subjectName = $subject['subject_name'];
                    } catch (PDOException $e) { /* Fails silently */ }
                    
                    $score_link = "http://localhost/DIGITAL/interface/student_dashboard.php?view=subject&code=" . htmlspecialchars($this->recitationSubjectCode);

                    $subject = "Recitation Graded for " . htmlspecialchars($subjectName);
                    $body = "
                        <html><body>
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                            <h2 style='color: #D32F2F; text-align: center;'>Hi, " . htmlspecialchars($studentData['firstname']) . "!</h2>
                            <p style='font-size: 1.1em; text-align: center;'>Your professor has recorded a new recitation score.</p>
                            <ul style='list-style-type: none; padding-left: 0; background-color: #f9f9f9; border-radius: 5px; padding: 15px;'>
                                <li style='margin-bottom: 10px;'><strong>Subject:</strong> " . htmlspecialchars($subjectName) . "</li>
                                <li style='margin-bottom: 10px;'><strong>Score:</strong> " . htmlspecialchars($this->recitationScore) . " / 10</li>
                                <li style='margin-bottom: 10px;'><strong>Mode:</strong> " . htmlspecialchars(ucfirst($this->recitationMode)) . "</li>
                                <li><strong>Date:</strong> " . htmlspecialchars(date('M d, Y', strtotime($this->recitationDate))) . "</li>
                            </ul>
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='" . $score_link . "' style='display: inline-block; padding: 14px 24px; background-color: #D32F2F; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 1.1em;'>
                                    View Score
                                </a>
                            </div>
                        </div>
                        </body></html>
                    ";
                    
                    $this->sendEmail($studentData['email'], $studentData['firstname'], $subject, $body);

                    $message = "You received a score of <strong>" . htmlspecialchars($this->recitationScore) . "</strong> in <strong>" . htmlspecialchars($subjectName) . "</strong>.";
                    $link = "student_dashboard.php?view=subject&code=" . htmlspecialchars($this->recitationSubjectCode);
                    $sql_notif = "INSERT INTO notifications (student_id, message, link) VALUES (?, ?, ?)";
                    $this->connect()->prepare($sql_notif)->execute([$this->recitationStudentId, $message, $link]);
                }
            } catch (Exception $e) {
                error_log("Failed to send notification/email: " . $e->getMessage());
            }
         
            return true;
        }
        return false;
    }

    // --- GETTERS: CLASSES & COURSES ---
    public function fetchAllClasses() {
        $sql = "SELECT
                    c.class_id,
                    c.class_name,
                    s.subject_name AS subject_name_display,
                    c.subject_code
                FROM class_sections c
                INNER JOIN subjects s ON c.subject_code = s.subject_code
                ORDER BY c.class_name ASC";
        try {
            $query = $this->connect()->prepare($sql);
            if ($query->execute()) {
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Error fetching all classes: " . $e->getMessage());
        }
        return [];
    }

    public function fetchAllCourses() {
        $sql = "SELECT * FROM course ORDER BY course_name ASC";
        try {
            $query = $this->connect()->prepare($sql);
            if ($query->execute()) {
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
             error_log("Error fetching all courses: " . $e->getMessage());
        }
        return [];
    }

    // --- MAIN: ADD STUDENT (WITH NORMALIZATION) ---
    public function addStudent() {
        if (empty($this->password)) {
            return false;
        }
        $conn = $this->connect();
        $conn->beginTransaction();
        try {
            // 1. Find Course ID based on Name
            $sql_course = "SELECT course_id FROM course WHERE course_name = :courseName";
            $query_course = $conn->prepare($sql_course);
            $query_course->bindParam(":courseName", $this->courseName);
            $query_course->execute();
            $course_row = $query_course->fetch();
            $course_id = $course_row ? $course_row['course_id'] : null;

            if ($course_id === null) {
                 error_log("Warning: Course name '{$this->courseName}' not found.");
            }

            // 2. Insert into Students table
            $sql_student = "INSERT INTO students(student_id, course_id, email, lastname, firstname, middlename, gender, birthdate, date_added)
                            VALUES(:studentId, :courseId, :email, :lastName, :firstName, :middleName, :gender, :birthDate, NOW())";
            $query_student = $conn->prepare($sql_student);
            $query_student->execute([
                ":studentId" => $this->studentId,
                ":courseId" => $course_id,
                ":email" => $this->email,
                ":lastName" => $this->lastName,
                ":firstName" => $this->firstName,
                ":middleName" => $this->middleName,
                ":gender" => $this->gender,
                ":birthDate" => $this->birthDate
            ]);

            // 3. Insert into Users table
            $hashed_password = password_hash($this->password, PASSWORD_DEFAULT);
            $role = 'student';
            $sql_users = "INSERT INTO users (username, password_hash, role, student_id, is_verified)
                          VALUES (:username, :password, :role, :studentId, 0)";
            $query_users = $conn->prepare($sql_users);
            $query_users->execute([
                ":username" => $this->studentId,
                ":password" => $hashed_password,
                ":role" => $role,
                ":studentId" => $this->studentId
            ]);

            $conn->commit();
            return true;
        } catch (PDOException $e) {
            $conn->rollBack();
             error_log("Error adding student: " . $e->getMessage());
            return false;
        }
    }

    // --- MAIN: VIEW STUDENTS (WITH JOIN) ---
    public function viewStudents($sortColumn = 'lastname', $sortOrder = 'ASC', $classFilter = 'all', $searchQuery = '') {
        $validSortColumns = [
            'lastName' => 's.lastname',
            'studentId' => 's.student_id',
            'firstName' => 's.firstname'
        ];
        $dbSortColumn = $validSortColumns[$sortColumn] ?? 's.lastname';
        $dbSortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
        $params = [];
        $whereConditions = [];
    
        $sql_recitation_join = "LEFT JOIN recits r ON s.student_id = r.student_id";
        $sql_class_join = "LEFT JOIN student_enrollments se ON s.student_id = se.student_id LEFT JOIN class_sections c ON se.class_id = c.class_id";
        
        $sql_select_fields = "s.student_id, s.lastname, s.firstname, s.middlename, s.gender, s.birthdate,
                             co.course_name, 
                             COUNT(DISTINCT se.class_id) AS total_subjects_enrolled,
                             GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') AS all_class_names";
        
        if ($classFilter !== 'all') {
            $sql_class_join = "INNER JOIN student_enrollments se ON s.student_id = se.student_id
                               INNER JOIN class_sections c ON se.class_id = c.class_id";
            
            $whereConditions[] = "c.class_name = :classFilter";
            $params[':classFilter'] = $classFilter;
            
            $sql_recitation_join = "LEFT JOIN recits r ON s.student_id = r.student_id AND r.subject_code = c.subject_code";
            
            $sql_select_fields = "s.student_id, s.lastname, s.firstname, s.middlename, s.gender, s.birthdate,
                                  co.course_name,
                                  c.class_name, c.subject_code, c.class_id,
                                  1 AS total_subjects_enrolled";
        }

        if (!empty($searchQuery)) {
             $whereConditions[] = "(s.student_id LIKE :search OR s.firstname LIKE :search OR s.lastname LIKE :search OR CONCAT(s.firstname, ' ', s.lastname) LIKE :search)";
             $params[':search'] = "%" . $searchQuery . "%";
        }
        
        $sql_where = "";
        if (!empty($whereConditions)) {
            $sql_where = " WHERE " . implode(' AND ', $whereConditions);
        }

        $sql = "SELECT
                    $sql_select_fields,
                    IFNULL(AVG(r.score), 0) AS averageScore,
                    COUNT(r.recit_id) AS totalRecitations
                FROM students s
                LEFT JOIN course co ON s.course_id = co.course_id
                $sql_class_join
                $sql_recitation_join
                $sql_where
                GROUP BY s.student_id, s.lastname, s.firstname, s.middlename, s.gender, s.birthdate, co.course_name" .
                ($classFilter !== 'all' ? ", c.class_name, c.subject_code, c.class_id" : "") .
                " ORDER BY {$dbSortColumn} {$dbSortOrder}";

        try {
             $query = $this->connect()->prepare($sql);
             if ($query->execute($params)) {
                 return $query->fetchAll(PDO::FETCH_ASSOC);
             }
         } catch (PDOException $e) {
             error_log("Error viewing students: " . $e->getMessage());
         }
        return [];
    }

    // --- HELPER: FETCH SINGLE STUDENT ---
    public function fetchStudent($studentId) {
        $sql = "SELECT s.*, c.course_name FROM students s LEFT JOIN course c ON s.course_id = c.course_id WHERE s.student_id = :studentId";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":studentId", $studentId);
            if ($query->execute()) {
                return $query->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Error fetching student '{$studentId}': " . $e->getMessage());
        }
        return false;
    }

    public function getStudentAverageScore($studentId, $subjectCode = null) {
        $sql = "SELECT AVG(score) AS average FROM recits WHERE student_id = :studentId";
        $params = [':studentId' => $studentId];
        if ($subjectCode !== null) {
            $sql .= " AND subject_code = :subjectCode";
            $params[':subjectCode'] = $subjectCode;
        }
        try {
            $query = $this->connect()->prepare($sql);
            if ($query->execute($params)) {
                $result = $query->fetch(PDO::FETCH_ASSOC);
                return $result['average'] ?? 0;
            }
        } catch (PDOException $e) {
             error_log("Error getting average score for student '{$studentId}': " . $e->getMessage());
        }
        return 0;
    }

    public function deleteStudent($studentId) {
        $conn = $this->connect();
        $conn->beginTransaction();
        try {
            $sql_student = "DELETE FROM students WHERE student_id = :studentId";
            $query_student = $conn->prepare($sql_student);
            $query_student->bindParam(":studentId", $studentId);
            $query_student->execute();

             if ($query_student->rowCount() > 0) {
                 $conn->commit();
                 return true;
             } else {
                 $conn->rollBack();
                 return false;
             }
        } catch (PDOException $e) {
            $conn->rollBack();
             error_log("Error deleting student '{$studentId}': " . $e->getMessage());
            return false;
        }
    }

    public function getStudentRecitations($studentId, $subjectCode = null) {
        $sql = "SELECT * FROM recits WHERE student_id = :studentId";
        $params = [':studentId' => $studentId];
        if ($subjectCode !== null) {
            $sql .= " AND subject_code = :subjectCode";
            $params[':subjectCode'] = $subjectCode;
        }
        $sql .= " ORDER BY date DESC";
        try {
            $query = $this->connect()->prepare($sql);
            if ($query->execute($params)) {
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
             error_log("Error getting recitations: " . $e->getMessage());
        }
        return [];
    }

    public function getStudentEnrolledSubjects($studentId) {
        $sql = "SELECT
                    cs.class_id,
                    cs.class_name,
                    s.subject_code,
                    s.subject_name
                FROM student_enrollments se
                JOIN class_sections cs ON se.class_id = cs.class_id
                JOIN subjects s ON cs.subject_code = s.subject_code
                WHERE se.student_id = :studentId
                ORDER BY s.subject_name ASC";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":studentId", $studentId);
            if ($query->execute()) {
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Error fetching student enrolled subjects: " . $e->getMessage());
        }
        return [];
    }
    
    public function withdrawStudentFromClass($studentId, $classId, $reason) {
        $sql = "DELETE FROM student_enrollments WHERE student_id = :studentId AND class_id = :classId";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":studentId", $studentId);
            $query->bindParam(":classId", $classId, PDO::PARAM_INT);
            $query->execute();

            $wasSuccessful = $query->rowCount() > 0;

            if ($wasSuccessful) {
                // Send notification
                $this->sendWithdrawalNotification($studentId, $classId, $reason);
            }
            
            return $wasSuccessful;

        } catch (PDOException $e) {
            error_log("Error withdrawing student: " . $e->getMessage());
            return false;
        }
    }

    public function fetchAllSubjects() {
        $sql = "SELECT * FROM subjects ORDER BY subject_name ASC";
        try {
            $query = $this->connect()->prepare($sql);
            if ($query->execute()) {
                return $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
             error_log("Error fetching all subjects: " . $e->getMessage());
        }
        return [];
    }

    public function addClassSection($className, $subjectCode) {
        $sql = "INSERT INTO class_sections (class_name, subject_code) VALUES (:className, :subjectCode)";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":className", $className);
            $query->bindParam(":subjectCode", $subjectCode);
            return $query->execute();
        } catch (PDOException $e) {
            error_log("Error adding class section: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteClassSection($classId) {
        $sql = "DELETE FROM class_sections WHERE class_id = :classId";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":classId", $classId, PDO::PARAM_INT);
            $query->execute();
            return $query->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error deleting class section: " . $e->getMessage());
            return false;
        }
    }
    
    public function addCourse($courseName) {
        $sql = "INSERT INTO course (course_name) VALUES (:courseName)";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":courseName", $courseName);
            return $query->execute();
        } catch (PDOException $e) {
            error_log("Error adding course: " . $e->getMessage());
            return false;
        }
    }

    public function deleteCourse($courseId) {
        $sql = "DELETE FROM course WHERE course_id = :courseId";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":courseId", $courseId, PDO::PARAM_INT);
            $query->execute();
            return $query->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error deleting course: " . $e->getMessage());
            return false;
        }
    }

    public function addSubject($subjectCode, $subjectName) {
        $sql = "INSERT INTO subjects (subject_code, subject_name) VALUES (:subjectCode, :subjectName)";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":subjectCode", $subjectCode);
            $query->bindParam(":subjectName", $subjectName);
            return $query->execute();
        } catch (PDOException $e) {
            error_log("Error adding subject: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteSubject($subjectCode) {
        $sql = "DELETE FROM subjects WHERE subject_code = :subjectCode";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":subjectCode", $subjectCode);
            $query->execute();
            return $query->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error deleting subject: " . $e->getMessage());
            return false;
        }
    }
    
    public function connect() { return parent::connect(); }
}
?>