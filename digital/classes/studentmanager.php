<?php

require_once "database.php";

class studentmanager extends Database {

    public $studentId = "";
    public $classId = "";
    public $courseName = "";
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


    public function addRecitation() {
        $sql = "INSERT INTO recits (student_id, subject_code, date, score, mode) VALUES (:studentId, :subjectCode, :date, :score, :mode)";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":studentId", $this->recitationStudentId);
        $query->bindParam(":subjectCode", $this->recitationSubjectCode);
        $query->bindParam(":date", $this->recitationDate);
        $query->bindParam(":score", $this->recitationScore);
        $query->bindParam(":mode", $this->recitationMode);
        return $query->execute();
    }


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


    public function addStudent() {
        if (empty($this->password)) {
            return false;
        }
        $conn = $this->connect();
        $conn->beginTransaction();
        try {
            $sql_course = "SELECT course_id FROM course WHERE course_name = :courseName";
            $query_course = $conn->prepare($sql_course);
            $query_course->bindParam(":courseName", $this->courseName);
            $query_course->execute();
            $course_row = $query_course->fetch();
            $course_id = $course_row ? $course_row['course_id'] : null;

            if ($course_id === null) {
                 error_log("Warning: Course name '{$this->courseName}' not found for student registration '{$this->studentId}'.");
            }

            $sql_student = "INSERT INTO students(student_id, course_id, lastname, firstname, middlename, gender, birthdate, date_added)
                            VALUES(:studentId, :courseId, :lastName, :firstName, :middleName, :gender, :birthDate, NOW())";
            $query_student = $conn->prepare($sql_student);
            $query_student->bindParam(":studentId", $this->studentId);
            $query_student->bindParam(":courseId", $course_id);
            $query_student->bindParam(":lastName", $this->lastName);
            $query_student->bindParam(":firstName", $this->firstName);
            $query_student->bindParam(":middleName", $this->middleName);
            $query_student->bindParam(":gender", $this->gender);
            $query_student->bindParam(":birthDate", $this->birthDate);
            $query_student->execute();

            $hashed_password = password_hash($this->password, PASSWORD_DEFAULT);
            $role = 'student';
            $sql_users = "INSERT INTO users (username, password_hash, role, student_id)
                          VALUES (:username, :password, :role, :studentId)";
            $query_users = $conn->prepare($sql_users);
            $query_users->bindParam(":username", $this->studentId);
            $query_users->bindParam(":password", $hashed_password);
            $query_users->bindParam(":role", $role);
            $query_users->bindParam(":studentId", $this->studentId);
            $query_users->execute();

            $conn->commit();
            return true;
        } catch (PDOException $e) {
            $conn->rollBack();
             error_log("Error adding student: " . $e->getMessage());
            return false;
        }
    }

    
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
                             COUNT(DISTINCT se.class_id) AS total_subjects_enrolled,
                             GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ') AS all_class_names";
        
        if ($classFilter !== 'all') {
            $sql_class_join = "INNER JOIN student_enrollments se ON s.student_id = se.student_id
                               INNER JOIN class_sections c ON se.class_id = c.class_id";
            
            $whereConditions[] = "c.class_name = :classFilter";
            $params[':classFilter'] = $classFilter;
            
            $sql_recitation_join = "LEFT JOIN recits r ON s.student_id = r.student_id AND r.subject_code = c.subject_code";
            
            // *** MODIFIED: Added c.class_id to the SELECT ***
            $sql_select_fields = "s.student_id, s.lastname, s.firstname, s.middlename, s.gender, s.birthdate,
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

        // *** MODIFIED: Added c.class_id to GROUP BY when filtering ***
        $sql = "SELECT
                    $sql_select_fields,
                    IFNULL(AVG(r.score), 0) AS averageScore,
                    COUNT(r.recit_id) AS totalRecitations
                FROM students s
                $sql_class_join
                $sql_recitation_join
                $sql_where
                GROUP BY s.student_id, s.lastname, s.firstname, s.middlename, s.gender, s.birthdate" .
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


    public function fetchStudent($studentId) {
        $sql = "SELECT * FROM students WHERE student_id = :studentId";
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
             error_log("Error getting recitations for student '{$studentId}'" . ($subjectCode ? " (Subject: {$subjectCode})" : "") . ": " . $e->getMessage());
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
            error_log("Error fetching student enrolled subjects for '{$studentId}': " . $e->getMessage());
        }
        return [];
    }
    
    // *** NEW FUNCTION ***
    public function withdrawStudentFromClass($studentId, $classId) {
        $sql = "DELETE FROM student_enrollments WHERE student_id = :studentId AND class_id = :classId";
        try {
            $query = $this->connect()->prepare($sql);
            $query->bindParam(":studentId", $studentId);
            $query->bindParam(":classId", $classId, PDO::PARAM_INT);
            $query->execute();
            
            return $query->rowCount() > 0; // Return true if a row was deleted
        } catch (PDOException $e) {
            error_log("Error withdrawing student '{$studentId}' from class '{$classId}': " . $e->getMessage());
            return false;
        }
    }

}
?>