<?php
/**
 * RYN School Management System
 * Live MySQL Synchronization & Diagnostic API
 * SECURED: Requires authenticated staff/admin PHP session or configured ADMIN_API_KEY.
 */

require_once __DIR__ . '/../config/AuthHelper.php';
require_once __DIR__ . '/../config/Database.php';

sendApiHeaders('GET, POST, OPTIONS');

$action = $_GET['action'] ?? 'ping';
$staffRoles = ['superadmin', 'admin', 'headteacher', 'deputy_headteacher', 'senior_teacher', 'teacher'];
$adminRoles = ['superadmin', 'admin', 'headteacher', 'deputy_headteacher'];

function ensureSnapshotTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS school_state_snapshots (
        id TINYINT UNSIGNED PRIMARY KEY,
        state_json LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function normalizePullRows(string $tableKey, array $rows): array {
    foreach ($rows as &$r) {
        if (!is_array($r)) continue;
        switch ($tableKey) {
            case 'users':
                if (isset($r['id'])) $r['id'] = (int)$r['id'];
                if (!empty($r['privileges']) && is_string($r['privileges'])) {
                    $decoded = json_decode($r['privileges'], true);
                    $r['privileges'] = is_array($decoded) ? $decoded : null;
                }
                break;
            case 'campuses':
            case 'hostel_blocks':
                if (isset($r['total_capacity'])) $r['total_capacity'] = (int)$r['total_capacity'];
                if (isset($r['total_rooms'])) $r['total_rooms'] = (int)$r['total_rooms'];
                break;
            case 'classes':
                if (isset($r['class_id'])) $r['class_id'] = (int)$r['class_id'];
                if (isset($r['capacity'])) $r['capacity'] = (int)$r['capacity'];
                if (isset($r['class_teacher_id']) && $r['class_teacher_id'] !== null && $r['class_teacher_id'] !== '') {
                    $r['class_teacher_id'] = (int)$r['class_teacher_id'];
                }
                break;
            case 'teachers':
                if (isset($r['teacher_id'])) $r['teacher_id'] = (int)$r['teacher_id'];
                if (isset($r['user_id'])) $r['user_id'] = (int)$r['user_id'];
                break;
            case 'students':
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                if (isset($r['user_id'])) $r['user_id'] = (int)$r['user_id'];
                $r['class_id'] = (isset($r['class_id']) && $r['class_id'] !== '' && $r['class_id'] !== null) ? (int)$r['class_id'] : null;
                $r['parent_id'] = (isset($r['parent_id']) && $r['parent_id'] !== '' && $r['parent_id'] !== null) ? (int)$r['parent_id'] : null;
                break;
            case 'attendance':
                if (isset($r['attendance_id'])) $r['attendance_id'] = (int)$r['attendance_id'];
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                break;
            case 'marks':
                if (isset($r['mark_id'])) $r['mark_id'] = (int)$r['mark_id'];
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                if (isset($r['score'])) $r['score'] = (float)$r['score'];
                if (isset($r['max_score'])) $r['max_score'] = (float)$r['max_score'];
                break;
            case 'subject_scores':
                if (!empty($r['assessment_scores']) && is_string($r['assessment_scores'])) {
                    $r['assessment_scores'] = json_decode($r['assessment_scores'], true) ?: [];
                }
                if (isset($r['score_id']) && !isset($r['id'])) {
                    $r['id'] = $r['score_id'];
                }
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                $r['did_not_sit'] = (bool)($r['did_not_sit'] ?? false);
                if (isset($r['final_score'])) $r['final_score'] = (float)$r['final_score'];
                if (isset($r['class_average']) && $r['class_average'] !== null && $r['class_average'] !== '') {
                    $r['class_average'] = (float)$r['class_average'];
                }
                if (isset($r['points']) && $r['points'] !== null && $r['points'] !== '') {
                    $r['points'] = (int)$r['points'];
                }
                break;
            case 'fees':
                if (isset($r['fee_id'])) $r['fee_id'] = (int)$r['fee_id'];
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                $r['total_amount'] = isset($r['total_amount']) ? (float)$r['total_amount'] : 0.0;
                $r['paid_amount'] = isset($r['paid_amount']) ? (float)$r['paid_amount'] : 0.0;
                break;
            case 'exams':
                if (isset($r['exam_id'])) $r['exam_id'] = (int)$r['exam_id'];
                if (isset($r['class_id'])) $r['class_id'] = (int)$r['class_id'];
                if (isset($r['total_marks'])) $r['total_marks'] = (float)$r['total_marks'];
                if (isset($r['passing_marks'])) $r['passing_marks'] = (float)$r['passing_marks'];
                break;
            case 'exam_marks':
                if (isset($r['exam_mark_id'])) $r['exam_mark_id'] = (int)$r['exam_mark_id'];
                if (isset($r['exam_id'])) $r['exam_id'] = (int)$r['exam_id'];
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                if (isset($r['marks_obtained'])) $r['marks_obtained'] = (float)$r['marks_obtained'];
                if (isset($r['points']) && $r['points'] !== null && $r['points'] !== '') {
                    $r['points'] = (int)$r['points'];
                }
                break;
            case 'timetable':
                if (isset($r['timetable_id'])) $r['timetable_id'] = (int)$r['timetable_id'];
                if (isset($r['class_id'])) $r['class_id'] = (int)$r['class_id'];
                if (isset($r['period_number'])) $r['period_number'] = (int)$r['period_number'];
                if (isset($r['teacher_id']) && $r['teacher_id'] !== null && $r['teacher_id'] !== '') {
                    $r['teacher_id'] = (int)$r['teacher_id'];
                }
                break;
            case 'learning_materials':
                if (isset($r['class_id'])) $r['class_id'] = (int)$r['class_id'];
                if (isset($r['teacher_id'])) $r['teacher_id'] = (int)$r['teacher_id'];
                if (isset($r['max_points']) && $r['max_points'] !== null && $r['max_points'] !== '') {
                    $r['max_points'] = (float)$r['max_points'];
                }
                break;
            case 'homework_submissions':
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                if (isset($r['score']) && $r['score'] !== null && $r['score'] !== '') {
                    $r['score'] = (float)$r['score'];
                }
                break;
            case 'meal_cards':
                if (isset($r['card_id'])) $r['card_id'] = (string)$r['card_id'];
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                break;
            case 'hostel_allocations':
                if (isset($r['student_id'])) $r['student_id'] = (int)$r['student_id'];
                break;
        }
    }
    unset($r);
    return $rows;
}

// Minimal public branding query (no PII/users) so login screen displays school name across devices
if ($action === 'branding') {
    try {
        $database = new Database();
        $db = $database->connect(false);
        $sysInfo = null;
        try {
            $snapStmt = $db->query("SELECT state_json FROM school_state_snapshots WHERE id = 1 LIMIT 1");
            $snapRow = $snapStmt ? $snapStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($snapRow && !empty($snapRow['state_json'])) {
                $snapData = json_decode($snapRow['state_json'], true);
                if (is_array($snapData) && !empty($snapData['system_info']) && is_array($snapData['system_info'])) {
                    $si = $snapData['system_info'];
                    $sysInfo = [
                        'school_name' => $si['school_name'] ?? null,
                        'motto' => $si['motto'] ?? null,
                        'logo_url' => $si['logo_url'] ?? null,
                        'academic_year' => $si['academic_year'] ?? null,
                        'center_no' => $si['center_no'] ?? null,
                    ];
                }
            }
        } catch (Exception $e) {
            // Snapshot table may not exist yet on fresh DB
        }
        if (!$sysInfo) {
            try {
                $cStmt = $db->query("SELECT name, motto FROM campuses ORDER BY name ASC LIMIT 1");
                $campus = $cStmt ? $cStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($campus) {
                    $sysInfo = [
                        'school_name' => $campus['name'],
                        'motto' => $campus['motto'],
                    ];
                }
            } catch (Exception $e) {
                // Campuses table may not exist yet before schema.sql import
            }
        }
        echo json_encode([
            'success' => true,
            'db_connected' => true,
            'system_info' => $sysInfo,
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => true,
            'db_connected' => false,
            'system_info' => null,
        ]);
    }
    exit;
}

// Enforce authentication before touching the database
$authUser = null;
if ($action === 'query') {
    $authUser = requireRoles(['superadmin', 'admin']);
} elseif ($action === 'pull') {
    $authUser = requireAuthenticatedUser();
} elseif ($action === 'student_activity') {
    $authUser = requireRoles(['student']);
} else {
    $authUser = requireRoles($staffRoles);
}

$database = new Database();
try {
    $db = $database->connect(false);
} catch (DatabaseConnectionException $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'connected' => false,
        'is_mysql' => true,
        'server' => 'MySQL (PHP PDO)',
        'database_name' => $e->getDbName(),
        'message' => $e->getMessage(),
        'error' => $e->getMessage(),
        'hint' => $e->getHint(),
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'connected' => false,
        'is_mysql' => true,
        'server' => 'MySQL (PHP PDO)',
        'database_name' => $database->getDatabaseName(),
        'message' => 'Database connection failed.',
        'error' => 'Database connection failed.',
    ]);
    exit;
}

// ----------------------------------------------------
// 1. PING: Test live MySQL connection & table counts
// ----------------------------------------------------
if ($action === 'ping') {
    try {
        $verStmt = $db->query("SELECT VERSION() AS ver, DATABASE() AS db_name");
        $verRow = $verStmt->fetch(PDO::FETCH_ASSOC);

        $tables = [
            'users', 'campuses', 'classes', 'teachers', 'students',
            'attendance', 'marks', 'subject_scores', 'fees', 'exams', 'exam_marks',
            'timetable', 'hostel_blocks', 'hostel_allocations', 'meal_cards',
            'transport_vehicles', 'transport_routes', 'notices', 'calendar_events',
            'learning_materials', 'homework_submissions'
        ];
        $counts = [];

        foreach ($tables as $tbl) {
            try {
                $cStmt = $db->query("SELECT COUNT(*) AS total FROM `{$tbl}`");
                $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                $counts[$tbl] = (int)($cRow['total'] ?? 0);
            } catch (Exception $ex) {
                $counts[$tbl] = 'Table not created yet';
            }
        }

        echo json_encode([
            'success' => true,
            'connected' => true,
            'is_mysql' => true,
            'server' => 'MySQL (PHP PDO)',
            'mysql_version' => $verRow['ver'] ?? 'Unknown',
            'database_name' => $verRow['db_name'] ?? 'Unknown',
            'table_counts' => $counts,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'connected' => false,
            'message' => 'Failed to query database health.'
        ]);
    }
    exit;
}

// ----------------------------------------------------
// 2. PULL: Retrieve role-scoped dataset from MySQL
// ----------------------------------------------------
if ($action === 'pull') {
    try {
        $sinceParam = trim((string)($_GET['since'] ?? ''));
        $snapRowCached = null;
        try {
            $snapCheckStmt = $db->query("SELECT state_json, updated_at FROM school_state_snapshots WHERE id = 1 LIMIT 1");
            $snapRowCached = $snapCheckStmt ? $snapCheckStmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Exception $e) {
            $snapRowCached = null;
        }

        if ($sinceParam !== '' && $snapRowCached && !empty($snapRowCached['updated_at']) && $snapRowCached['updated_at'] === $sinceParam) {
            echo json_encode([
                'success' => true,
                'initialized' => true,
                'unchanged' => true,
                'source' => 'mysql',
                'updated_at' => $snapRowCached['updated_at'],
                'pulled_at' => date('Y-m-d H:i:s'),
            ]);
            exit;
        }

        $result = [];
        $userRole = $authUser['role'] ?? '';
        $isStaff = in_array($userRole, $staffRoles, true);

        if ($isStaff) {
            $stmt = $db->query("SELECT id, username, role, email, campus_id, privileges, created_at FROM users ORDER BY id ASC");
            $result['users'] = normalizePullRows('users', $stmt->fetchAll(PDO::FETCH_ASSOC));

            $queries = [
                'campuses' => "SELECT * FROM campuses ORDER BY name ASC",
                'classes' => "SELECT * FROM classes ORDER BY class_id ASC",
                'teachers' => "SELECT * FROM teachers ORDER BY teacher_id ASC",
                'students' => "SELECT * FROM students ORDER BY student_id ASC",
                'attendance' => "SELECT * FROM attendance ORDER BY date DESC, attendance_id DESC LIMIT 20000",
                'marks' => "SELECT * FROM marks ORDER BY mark_id ASC LIMIT 20000",
                'subject_scores' => "SELECT * FROM subject_scores LIMIT 20000",
                'fees' => "SELECT * FROM fees ORDER BY fee_id ASC LIMIT 20000",
                'exams' => "SELECT * FROM exams ORDER BY exam_id ASC",
                'exam_marks' => "SELECT * FROM exam_marks ORDER BY exam_mark_id ASC LIMIT 20000",
                'timetable' => "SELECT * FROM timetable ORDER BY timetable_id ASC",
                'hostel_blocks' => "SELECT * FROM hostel_blocks ORDER BY name ASC",
                'hostel_allocations' => "SELECT * FROM hostel_allocations ORDER BY allocated_date DESC",
                'meal_cards' => "SELECT * FROM meal_cards ORDER BY card_id ASC",
                'notices' => "SELECT * FROM notices ORDER BY is_pinned DESC, created_at DESC LIMIT 100",
                'calendar_events' => "SELECT * FROM calendar_events ORDER BY start_date ASC",
                'learning_materials' => "SELECT * FROM learning_materials ORDER BY created_at DESC",
                'homework_submissions' => "SELECT * FROM homework_submissions ORDER BY submitted_at DESC",
            ];

            foreach ($queries as $key => $sql) {
                try {
                    $qStmt = $db->query($sql);
                    $rows = $qStmt->fetchAll(PDO::FETCH_ASSOC);
                    $result[$key] = normalizePullRows($key, $rows);
                } catch (Exception $e) {
                    $result[$key] = [];
                }
            }
        } else {
            // Strictly scope student & parent pulls to their own / ward records + shared schedule/notices
            $selfId = (int)($authUser['id'] ?? 0);
            $uStmt = $db->prepare("SELECT id, username, role, email, campus_id, privileges, created_at FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $selfId]);
            $result['users'] = normalizePullRows('users', $uStmt->fetchAll(PDO::FETCH_ASSOC));

            if ($userRole === 'student') {
                $stStmt = $db->prepare("SELECT * FROM students WHERE user_id = :uid ORDER BY student_id ASC");
                $stStmt->execute([':uid' => $selfId]);
                $myStudents = normalizePullRows('students', $stStmt->fetchAll(PDO::FETCH_ASSOC));
            } else {
                $stStmt = $db->prepare("SELECT * FROM students WHERE parent_id = :pid ORDER BY student_id ASC");
                $stStmt->execute([':pid' => $selfId]);
                $myStudents = normalizePullRows('students', $stStmt->fetchAll(PDO::FETCH_ASSOC));
            }
            $result['students'] = $myStudents;

            $studentIds = array_values(array_unique(array_map(fn($s) => (int)$s['student_id'], $myStudents)));
            $classIds = array_values(array_unique(array_filter(array_map(fn($s) => isset($s['class_id']) ? (int)$s['class_id'] : 0, $myStudents))));

            $sharedQueries = [
                'campuses' => "SELECT * FROM campuses ORDER BY name ASC",
                'classes' => "SELECT * FROM classes ORDER BY class_id ASC",
                'teachers' => "SELECT teacher_id, user_id, first_name, last_name, subject, qualification FROM teachers ORDER BY teacher_id ASC",
                'notices' => "SELECT * FROM notices ORDER BY is_pinned DESC, created_at DESC LIMIT 100",
                'calendar_events' => "SELECT * FROM calendar_events ORDER BY start_date ASC",
            ];
            foreach ($sharedQueries as $key => $sql) {
                try {
                    $result[$key] = normalizePullRows($key, $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
                } catch (Exception $e) {
                    $result[$key] = [];
                }
            }

            if (!empty($studentIds)) {
                $inStu = implode(',', array_map('intval', $studentIds));
                $stuQueries = [
                    'attendance' => "SELECT * FROM attendance WHERE student_id IN ({$inStu}) ORDER BY date DESC",
                    'marks' => "SELECT * FROM marks WHERE student_id IN ({$inStu}) ORDER BY mark_id ASC",
                    'subject_scores' => "SELECT * FROM subject_scores WHERE student_id IN ({$inStu})",
                    'exam_marks' => "SELECT * FROM exam_marks WHERE student_id IN ({$inStu})",
                    'homework_submissions' => "SELECT * FROM homework_submissions WHERE student_id IN ({$inStu}) ORDER BY submitted_at DESC",
                    'fees' => ($userRole === 'parent') ? "SELECT * FROM fees WHERE student_id IN ({$inStu}) ORDER BY fee_id ASC" : null,
                ];
                foreach ($stuQueries as $key => $sql) {
                    if ($sql === null) { $result[$key] = []; continue; }
                    try {
                        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                        $result[$key] = normalizePullRows($key, $rows);
                    } catch (Exception $e) {
                        $result[$key] = [];
                    }
                }
            } else {
                $result['attendance'] = [];
                $result['marks'] = [];
                $result['subject_scores'] = [];
                $result['exam_marks'] = [];
                $result['homework_submissions'] = [];
                $result['fees'] = [];
            }

            if (!empty($classIds)) {
                $inCls = implode(',', array_map('intval', $classIds));
                $clsQueries = [
                    'exams' => "SELECT * FROM exams WHERE class_id IN ({$inCls}) ORDER BY exam_id ASC",
                    'timetable' => "SELECT * FROM timetable WHERE class_id IN ({$inCls}) ORDER BY timetable_id ASC",
                    'learning_materials' => "SELECT * FROM learning_materials WHERE class_id IN ({$inCls}) OR class_id IS NULL ORDER BY created_at DESC",
                ];
                foreach ($clsQueries as $key => $sql) {
                    try {
                        $result[$key] = normalizePullRows($key, $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
                    } catch (Exception $e) {
                        $result[$key] = [];
                    }
                }
            } else {
                $result['exams'] = [];
                $result['timetable'] = [];
                $result['learning_materials'] = [];
            }
        }

        // Merge full snapshot state (system_info, assessment_columns, grading_scales, subjects, online_exams, syllabus_topics, etc.)
        $snapUpdatedAt = date('Y-m-d H:i:s');
        $hasSnapshot = false;
        try {
            $snapRow = $snapRowCached;
            if (!$snapRow) {
                $snapStmt = $db->query("SELECT state_json, updated_at FROM school_state_snapshots WHERE id = 1 LIMIT 1");
                $snapRow = $snapStmt ? $snapStmt->fetch(PDO::FETCH_ASSOC) : null;
            }
            if ($snapRow && !empty($snapRow['state_json'])) {
                $snapData = json_decode($snapRow['state_json'], true);
                if (!empty($snapRow['updated_at'])) {
                    $snapUpdatedAt = $snapRow['updated_at'];
                }
                if (is_array($snapData)) {
                    $hasSnapshot = true;
                    $sharedSnapKeys = [
                        'system_info', 'campuses', 'active_campus_id', 'academic_years', 'batches',
                        'classes', 'subjects', 'grading_scales', 'assessment_columns', 'assessment_columns_customized',
                        'result_lock', 'school_sections', 'role_permissions', 'teachers', 'notices',
                        'calendar_events', 'zambia_publications'
                    ];
                    foreach ($sharedSnapKeys as $k) {
                        if (array_key_exists($k, $snapData) && (!isset($result[$k]) || (is_array($result[$k]) && count($result[$k]) === 0))) {
                            $result[$k] = is_array($snapData[$k]) ? normalizePullRows($k, $snapData[$k]) : $snapData[$k];
                        }
                    }
                    // Always prefer authoritative JSON snapshot for rich/non-relational config fields
                    foreach ([
                        'system_info', 'active_campus_id', 'academic_years', 'batches', 'subjects',
                        'grading_scales', 'assessment_columns', 'assessment_columns_customized',
                        'result_lock', 'school_sections', 'role_permissions', 'zambia_publications'
                    ] as $cfgKey) {
                        if (array_key_exists($cfgKey, $snapData)) {
                            $result[$cfgKey] = $snapData[$cfgKey];
                        }
                    }

                    if ($isStaff) {
                        $staffExtraKeys = [
                            'subject_assignments', 'student_electives', 'subject_combinations',
                            'vehicles', 'routes', 'transport_assignments', 'graduation_records',
                            'meal_logs', 'weekly_meal_marking', 'hostel_rooms', 'exeat_permits',
                            'online_exams', 'online_exam_submissions', 'syllabus_topics',
                            'student_topic_progress', 'student_remarks'
                        ];
                        foreach ($staffExtraKeys as $k) {
                            if (array_key_exists($k, $snapData)) {
                                $result[$k] = $snapData[$k];
                            }
                        }
                        // Snapshot-authoritative non-relational tables (sync push stores these in snapshot)
                        foreach (['notices', 'calendar_events', 'hostel_blocks', 'hostel_allocations', 'meal_cards', 'homework_submissions'] as $snapOnlyRelKey) {
                            if (array_key_exists($snapOnlyRelKey, $snapData) && is_array($snapData[$snapOnlyRelKey])) {
                                $result[$snapOnlyRelKey] = normalizePullRows($snapOnlyRelKey, $snapData[$snapOnlyRelKey]);
                            }
                        }
                        foreach (['students', 'attendance', 'marks', 'subject_scores', 'fees', 'exams', 'exam_marks', 'timetable', 'learning_materials'] as $relKey) {
                            if ((!isset($result[$relKey]) || count($result[$relKey]) === 0) && array_key_exists($relKey, $snapData) && is_array($snapData[$relKey])) {
                                $result[$relKey] = normalizePullRows($relKey, $snapData[$relKey]);
                            }
                        }
                    } else {
                        // Scope snapshot-only educational fields strictly to this student/parent's class or student IDs
                        $stuIdSet = array_flip($studentIds);
                        $clsIdSet = array_flip($classIds);
                        if (empty($result['students']) && !empty($snapData['students']) && is_array($snapData['students'])) {
                            $filteredStudents = array_values(array_filter($snapData['students'], function ($s) use ($userRole, $selfId) {
                                return $userRole === 'student'
                                    ? ((int)($s['user_id'] ?? 0) === $selfId)
                                    : ((int)($s['parent_id'] ?? 0) === $selfId);
                            }));
                            $result['students'] = normalizePullRows('students', $filteredStudents);
                            $studentIds = array_values(array_unique(array_map(fn($s) => (int)$s['student_id'], $result['students'])));
                            $classIds = array_values(array_unique(array_filter(array_map(fn($s) => isset($s['class_id']) ? (int)$s['class_id'] : 0, $result['students']))));
                            $stuIdSet = array_flip($studentIds);
                            $clsIdSet = array_flip($classIds);
                        }
                        if (!empty($snapData['notices']) && is_array($snapData['notices']) && empty($result['notices'])) {
                            $result['notices'] = $snapData['notices'];
                        }
                        if (!empty($snapData['calendar_events']) && is_array($snapData['calendar_events']) && empty($result['calendar_events'])) {
                            $result['calendar_events'] = $snapData['calendar_events'];
                        }
                        if (array_key_exists('homework_submissions', $snapData) && is_array($snapData['homework_submissions'])) {
                            $snapSubs = array_values(array_filter($snapData['homework_submissions'], fn($hs) => isset($stuIdSet[(int)($hs['student_id'] ?? 0)])));
                            if (!empty($snapSubs)) {
                                $result['homework_submissions'] = normalizePullRows('homework_submissions', $snapSubs);
                            }
                        }
                        if (array_key_exists('meal_cards', $snapData) && is_array($snapData['meal_cards'])) {
                            $snapMeal = array_values(array_filter($snapData['meal_cards'], fn($mc) => isset($stuIdSet[(int)($mc['student_id'] ?? 0)])));
                            $result['meal_cards'] = normalizePullRows('meal_cards', $snapMeal);
                        }
                        if (array_key_exists('hostel_allocations', $snapData) && is_array($snapData['hostel_allocations'])) {
                            $snapAlloc = array_values(array_filter($snapData['hostel_allocations'], fn($ha) => isset($stuIdSet[(int)($ha['student_id'] ?? 0)])));
                            $result['hostel_allocations'] = normalizePullRows('hostel_allocations', $snapAlloc);
                        }
                        if ($userRole === 'parent' && empty($result['fees']) && !empty($snapData['fees']) && is_array($snapData['fees'])) {
                            $snapFees = array_values(array_filter($snapData['fees'], fn($f) => isset($stuIdSet[(int)($f['student_id'] ?? 0)])));
                            $result['fees'] = normalizePullRows('fees', $snapFees);
                        }
                        if (!empty($snapData['subject_assignments']) && is_array($snapData['subject_assignments'])) {
                            $result['subject_assignments'] = array_values(array_filter($snapData['subject_assignments'], fn($sa) => isset($clsIdSet[(int)($sa['class_id'] ?? 0)])));
                        }
                        if (!empty($snapData['online_exams']) && is_array($snapData['online_exams'])) {
                            $result['online_exams'] = array_values(array_filter($snapData['online_exams'], fn($oe) => empty($oe['class_id']) || isset($clsIdSet[(int)$oe['class_id']])));
                        }
                        if (!empty($snapData['online_exam_submissions']) && is_array($snapData['online_exam_submissions'])) {
                            $result['online_exam_submissions'] = array_values(array_filter($snapData['online_exam_submissions'], fn($os) => isset($stuIdSet[(int)($os['student_id'] ?? 0)])));
                        }
                        if (!empty($snapData['syllabus_topics']) && is_array($snapData['syllabus_topics'])) {
                            $result['syllabus_topics'] = array_values(array_filter($snapData['syllabus_topics'], fn($stItem) => empty($stItem['class_id']) || isset($clsIdSet[(int)$stItem['class_id']])));
                        }
                        if (!empty($snapData['student_topic_progress']) && is_array($snapData['student_topic_progress'])) {
                            $result['student_topic_progress'] = array_values(array_filter($snapData['student_topic_progress'], fn($tp) => isset($stuIdSet[(int)($tp['student_id'] ?? 0)])));
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Snapshot table optional on initial setup
        }

        $isInitialized = $hasSnapshot || (!empty($result['students']) && count($result['students']) > 0);
        echo json_encode([
            'success' => true,
            'initialized' => $isInitialized,
            'source' => 'mysql',
            'data' => $result,
            'updated_at' => $snapUpdatedAt,
            'pulled_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to pull data from MySQL.'
        ]);
    }
    exit;
}

// ----------------------------------------------------
// 3. PUSH: Seed or Sync client state to MySQL (FK-Safe Order)
// ----------------------------------------------------
if ($action === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!$payload || !is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload received.']);
        exit;
    }

    $updatedAt = date('Y-m-d H:i:s');
    $snapshotSaved = false;
    try {
        ensureSnapshotTable($db);
        $snapshotPayload = $payload;
        if (!empty($snapshotPayload['users']) && is_array($snapshotPayload['users'])) {
            foreach ($snapshotPayload['users'] as &$su) {
                unset($su['password_hash']);
            }
        }
        $snapJson = json_encode($snapshotPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($snapJson !== false) {
            $snapUpsert = $db->prepare("INSERT INTO school_state_snapshots (id, state_json, updated_at)
                                        VALUES (1, :json, :upd)
                                        ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = VALUES(updated_at)");
            $snapUpsert->execute([':json' => $snapJson, ':upd' => $updatedAt]);
            $snapshotSaved = true;
        }
    } catch (Exception $snapEx) {
        // Continue to relational sync if snapshot DDL/write is unavailable
    }

    try {
        $isFullSync = array_key_exists('users', $payload) || array_key_exists('system_info', $payload);
        $supportsRetainedStatus = false;
        try {
            $colStmt = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status' LIMIT 1");
            $colType = $colStmt ? (string)$colStmt->fetchColumn() : '';
            $supportsRetainedStatus = stripos($colType, "'retained'") !== false;
        } catch (Exception $ignore) {}

        $reconcileDeletions = function (PDO $db, string $table, string $pkCol, array $keepIds, bool $isInt = true, array $protectedIds = []): void {
            try {
                $existing = $db->query("SELECT `{$pkCol}` FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                if (!$existing) return;
                $keepSet = [];
                foreach ($keepIds as $id) {
                    if ($isInt) {
                        $val = (int)$id;
                        if ($val > 0) $keepSet[(string)$val] = true;
                    } else {
                        $val = trim((string)$id);
                        if ($val !== '') $keepSet[$val] = true;
                    }
                }
                foreach ($protectedIds as $pid) {
                    $keepSet[(string)$pid] = true;
                }
                $toDelete = [];
                foreach ($existing as $exId) {
                    $k = $isInt ? (string)((int)$exId) : (string)$exId;
                    if (!isset($keepSet[$k])) {
                        $toDelete[] = $isInt ? (int)$exId : (string)$exId;
                    }
                }
                if (empty($toDelete)) return;
                foreach (array_chunk($toDelete, 500) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $delStmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$pkCol}` IN ({$placeholders})");
                    $delStmt->execute(array_values($chunk));
                }
            } catch (Exception $ignore) {
                // Skip if optional table is absent
            }
        };

        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $db->beginTransaction();

        // 0. Campuses (referenced by classes, teachers, students)
        if (array_key_exists('campuses', $payload) && is_array($payload['campuses'])) {
            $campIds = [];
            if (!empty($payload['campuses'])) {
                $campStmt = $db->prepare("INSERT INTO campuses (campus_id, name, code, campus_type, curriculum_system, motto, center_no, address, phone, email, principal_name, principal_title, total_capacity)
                                          VALUES (:cid, :nm, :cd, :ct, :cs, :mt, :cno, :addr, :ph, :em, :pn, :pt, :cap)
                                          ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), campus_type = VALUES(campus_type), curriculum_system = VALUES(curriculum_system), motto = VALUES(motto), center_no = VALUES(center_no), address = VALUES(address), phone = VALUES(phone), email = VALUES(email), principal_name = VALUES(principal_name), principal_title = VALUES(principal_title), total_capacity = VALUES(total_capacity)");
                foreach ($payload['campuses'] as $c) {
                    if (empty($c['campus_id'])) continue;
                    $campIds[] = (string)$c['campus_id'];
                    $campType = in_array($c['campus_type'] ?? '', ['Secondary', 'Primary', 'Combined', 'ECE', 'Tertiary']) ? $c['campus_type'] : 'Secondary';
                    $campStmt->execute([
                        ':cid' => $c['campus_id'],
                        ':nm' => $c['name'] ?? 'Main Campus',
                        ':cd' => $c['code'] ?? 'MAIN',
                        ':ct' => $campType,
                        ':cs' => $c['curriculum_system'] ?? 'National_9_Point',
                        ':mt' => $c['motto'] ?? null,
                        ':cno' => $c['center_no'] ?? null,
                        ':addr' => $c['address'] ?? null,
                        ':ph' => $c['phone'] ?? null,
                        ':em' => $c['email'] ?? null,
                        ':pn' => $c['principal_name'] ?? null,
                        ':pt' => $c['principal_title'] ?? null,
                        ':cap' => (int)($c['total_capacity'] ?? 500),
                    ]);
                }
            }
            if ($isFullSync && !empty($campIds)) {
                $reconcileDeletions($db, 'campuses', 'campus_id', $campIds, false);
            }
        }

        // 1. Users FIRST so teachers & students foreign keys always resolve cleanly
        if (array_key_exists('users', $payload) && is_array($payload['users'])) {
            $keptUserIds = [];
            if (!empty($payload['users'])) {
                $uFindStmt = $db->prepare("SELECT id, role, password_hash FROM users WHERE id = :id OR LOWER(username) = LOWER(:un) OR LOWER(email) = LOWER(:em) LIMIT 1");
                $uUpdateStmt = $db->prepare("UPDATE users SET username = :un, role = :rl, email = :em, campus_id = :cid, privileges = :priv WHERE id = :id");
                $uInsertStmt = $db->prepare("INSERT INTO users (id, username, password_hash, role, email, campus_id, privileges)
                                             VALUES (:id, :un, :ph, :rl, :em, :cid, :priv)
                                             ON DUPLICATE KEY UPDATE username = VALUES(username), role = VALUES(role), email = VALUES(email), campus_id = VALUES(campus_id), privileges = VALUES(privileges)");
                $validRoles = ['superadmin', 'admin', 'headteacher', 'deputy_headteacher', 'senior_teacher', 'teacher', 'student', 'parent'];
                foreach ($payload['users'] as $u) {
                    if (empty($u['username'])) continue;
                    $uid = (int)($u['id'] ?? 0);
                    if ($uid <= 0) continue;
                    $uname = trim((string)$u['username']);
                    $uemail = trim((string)($u['email'] ?? ($uname . '@school.edu')));
                    $role = in_array($u['role'] ?? '', $validRoles, true) ? $u['role'] : 'student';
                    $privJson = isset($u['privileges']) ? json_encode($u['privileges']) : null;
                    $cid = $u['campus_id'] ?? 'all';

                    $uFindStmt->execute([':id' => $uid, ':un' => $uname, ':em' => $uemail]);
                    $existingUser = $uFindStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingUser) {
                        $keptUserIds[] = (int)$existingUser['id'];
                        $effectiveRole = ($existingUser['role'] === 'superadmin' && $role === 'admin') ? 'superadmin' : $role;
                        try {
                            $uUpdateStmt->execute([
                                ':id' => (int)$existingUser['id'],
                                ':un' => $uname,
                                ':rl' => $effectiveRole,
                                ':em' => $uemail,
                                ':cid' => $cid,
                                ':priv' => $privJson,
                            ]);
                        } catch (PDOException $ue) {
                            // Skip conflicting email/username update rather than aborting entire sync transaction
                        }
                    } else {
                        $keptUserIds[] = $uid;
                        $rawPass = (string)($u['password_hash'] ?? '');
                        if ($rawPass !== '' && strpos($rawPass, '$2y$') !== 0 && strpos($rawPass, '$2a$') !== 0) {
                            $passHash = password_hash($rawPass, PASSWORD_BCRYPT);
                        } elseif ($rawPass !== '') {
                            $passHash = $rawPass;
                        } else {
                            $passHash = password_hash('password123', PASSWORD_BCRYPT);
                        }
                        try {
                            $uInsertStmt->execute([
                                ':id' => $uid,
                                ':un' => $uname,
                                ':ph' => $passHash,
                                ':rl' => $role,
                                ':em' => $uemail,
                                ':cid' => $cid,
                                ':priv' => $privJson,
                            ]);
                        } catch (PDOException $ie) {
                            // Ignore duplicate username/email collisions cleanly
                        }
                    }
                }
            }
            if ($isFullSync && !empty($keptUserIds)) {
                $reconcileDeletions($db, 'users', 'id', $keptUserIds, true, [(int)($authUser['id'] ?? 0)]);
            }
        }

        // 2. Classes
        if (array_key_exists('classes', $payload) && is_array($payload['classes'])) {
            $classIdsKept = [];
            if (!empty($payload['classes'])) {
                $cStmt = $db->prepare("INSERT INTO classes (class_id, campus_id, class_name, section, room_number, capacity, class_teacher_id, section_division)
                                       VALUES (:cid, :camp, :cn, :sec, :room, :cap, :ctid, :div)
                                       ON DUPLICATE KEY UPDATE campus_id = VALUES(campus_id), class_name = VALUES(class_name), section = VALUES(section), room_number = VALUES(room_number), capacity = VALUES(capacity), class_teacher_id = VALUES(class_teacher_id), section_division = VALUES(section_division)");
                foreach ($payload['classes'] as $c) {
                    $cid = (int)$c['class_id'];
                    $classIdsKept[] = $cid;
                    $cStmt->execute([
                        ':cid' => $cid,
                        ':camp' => $c['campus_id'] ?? null,
                        ':cn' => $c['class_name'],
                        ':sec' => $c['section'] ?? 'A',
                        ':room' => $c['room_number'] ?? 'Room 101',
                        ':cap' => (int)($c['capacity'] ?? 35),
                        ':ctid' => !empty($c['class_teacher_id']) ? (int)$c['class_teacher_id'] : null,
                        ':div' => $c['section_division'] ?? 'SECONDARY',
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'classes', 'class_id', $classIdsKept, true);
            }
        }

        // 3. Teachers
        if (array_key_exists('teachers', $payload) && is_array($payload['teachers'])) {
            $teacherIdsKept = [];
            if (!empty($payload['teachers'])) {
                $tStmt = $db->prepare("INSERT INTO teachers (teacher_id, user_id, campus_id, first_name, last_name, phone, subject, qualification)
                                       VALUES (:tid, :uid, :camp, :fn, :ln, :ph, :sub, :qual)
                                       ON DUPLICATE KEY UPDATE campus_id = VALUES(campus_id), first_name = VALUES(first_name), last_name = VALUES(last_name), phone = VALUES(phone), subject = VALUES(subject), qualification = VALUES(qualification)");
                foreach ($payload['teachers'] as $t) {
                    $tid = (int)$t['teacher_id'];
                    $teacherIdsKept[] = $tid;
                    $tStmt->execute([
                        ':tid' => $tid,
                        ':uid' => (int)($t['user_id'] ?? 2),
                        ':camp' => $t['campus_id'] ?? null,
                        ':fn' => $t['first_name'],
                        ':ln' => $t['last_name'],
                        ':ph' => $t['phone'] ?? '+260 97 0000000',
                        ':sub' => $t['subject'] ?? 'General',
                        ':qual' => $t['qualification'] ?? 'B.Ed'
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'teachers', 'teacher_id', $teacherIdsKept, true);
            }
        }

        // 4. Students
        if (array_key_exists('students', $payload) && is_array($payload['students'])) {
            $studentIdsKept = [];
            if (!empty($payload['students'])) {
                $sStmt = $db->prepare("INSERT INTO students (student_id, user_id, campus_id, first_name, last_name, gender, dob, class_id, parent_id, admission_no, blood_group, address, status)
                                       VALUES (:sid, :uid, :camp, :fn, :ln, :gender, :dob, :cid, :pid, :adm, :bg, :addr, :st)
                                       ON DUPLICATE KEY UPDATE campus_id = VALUES(campus_id), first_name = VALUES(first_name), last_name = VALUES(last_name), gender = VALUES(gender), dob = VALUES(dob), class_id = VALUES(class_id), parent_id = VALUES(parent_id), admission_no = VALUES(admission_no), blood_group = VALUES(blood_group), address = VALUES(address), status = VALUES(status)");
                foreach ($payload['students'] as $s) {
                    $sid = (int)$s['student_id'];
                    $studentIdsKept[] = $sid;
                    $rawStatus = (string)($s['status'] ?? 'active');
                    if ($rawStatus === 'retained' && !$supportsRetainedStatus) {
                        $safeStatus = 'transferred';
                    } elseif (in_array($rawStatus, ['active', 'graduated', 'transferred', 'promoted', 'retained'], true)) {
                        $safeStatus = $rawStatus;
                    } else {
                        $safeStatus = 'active';
                    }
                    $sStmt->execute([
                        ':sid' => $sid,
                        ':uid' => (int)($s['user_id'] ?? 4),
                        ':camp' => $s['campus_id'] ?? null,
                        ':fn' => $s['first_name'],
                        ':ln' => $s['last_name'],
                        ':gender' => in_array($s['gender'] ?? '', ['Male', 'Female', 'Other']) ? $s['gender'] : 'Other',
                        ':dob' => !empty($s['dob']) ? $s['dob'] : '2010-01-01',
                        ':cid' => !empty($s['class_id']) ? (int)$s['class_id'] : null,
                        ':pid' => !empty($s['parent_id']) ? (int)$s['parent_id'] : null,
                        ':adm' => $s['admission_no'] ?? ('KBSS-' . $s['student_id']),
                        ':bg' => $s['blood_group'] ?? 'O+',
                        ':addr' => $s['address'] ?? 'Katete, Eastern Province, Zambia',
                        ':st' => $safeStatus,
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'students', 'student_id', $studentIdsKept, true);
            }
        }

        // 5. Attendance
        if (array_key_exists('attendance', $payload) && is_array($payload['attendance'])) {
            $attIdsKept = [];
            if (!empty($payload['attendance'])) {
                $aStmt = $db->prepare("INSERT INTO attendance (attendance_id, student_id, date, status, remarks)
                                       VALUES (:aid, :sid, :date, :status, :rem)
                                       ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks)");
                foreach ($payload['attendance'] as $a) {
                    $aid = (int)$a['attendance_id'];
                    $attIdsKept[] = $aid;
                    $aStmt->execute([
                        ':aid' => $aid,
                        ':sid' => (int)$a['student_id'],
                        ':date' => $a['date'],
                        ':status' => in_array($a['status'] ?? '', ['present', 'absent', 'late', 'excused']) ? $a['status'] : 'present',
                        ':rem' => $a['remarks'] ?? null
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'attendance', 'attendance_id', $attIdsKept, true);
            }
        }

        // 6. Marks
        if (array_key_exists('marks', $payload) && is_array($payload['marks'])) {
            $markIdsKept = [];
            if (!empty($payload['marks'])) {
                $mStmt = $db->prepare("INSERT INTO marks (mark_id, student_id, subject, score, term, max_score, grade, teacher_comment)
                                       VALUES (:mid, :sid, :sub, :sc, :trm, :maxs, :gr, :com)
                                       ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score), grade = VALUES(grade), teacher_comment = VALUES(teacher_comment)");
                foreach ($payload['marks'] as $m) {
                    $mid = (int)$m['mark_id'];
                    $markIdsKept[] = $mid;
                    $mStmt->execute([
                        ':mid' => $mid,
                        ':sid' => (int)$m['student_id'],
                        ':sub' => $m['subject'],
                        ':sc' => (float)$m['score'],
                        ':trm' => $m['term'] ?? 'TERM 2 2026',
                        ':maxs' => (float)($m['max_score'] ?? 100),
                        ':gr' => $m['grade'] ?? '9',
                        ':com' => $m['teacher_comment'] ?? null,
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'marks', 'mark_id', $markIdsKept, true);
            }
        }

        // 7. Subject Scores (Continuous Assessment)
        if (array_key_exists('subject_scores', $payload) && is_array($payload['subject_scores'])) {
            $ssIdsKept = [];
            if (!empty($payload['subject_scores'])) {
                $ssStmt = $db->prepare("INSERT INTO subject_scores (score_id, student_id, subject_name, term, academic_year, did_not_sit, assessment_scores, final_score, class_average, grade, points, standard, teacher_comment)
                                        VALUES (:scid, :sid, :sub, :trm, :ay, :dns, :ascores, :fscore, :cavg, :gr, :pts, :std, :com)
                                        ON DUPLICATE KEY UPDATE academic_year = VALUES(academic_year), did_not_sit = VALUES(did_not_sit), assessment_scores = VALUES(assessment_scores), final_score = VALUES(final_score), class_average = VALUES(class_average), grade = VALUES(grade), points = VALUES(points), standard = VALUES(standard), teacher_comment = VALUES(teacher_comment)");
                foreach ($payload['subject_scores'] as $ss) {
                    $scid = (string)($ss['id'] ?? ($ss['score_id'] ?? uniqid('score_', true)));
                    $ssIdsKept[] = $scid;
                    $ssStmt->execute([
                        ':scid' => $scid,
                        ':sid' => (int)$ss['student_id'],
                        ':sub' => $ss['subject_name'],
                        ':trm' => $ss['term'] ?? 'TERM 2 2026',
                        ':ay' => $ss['academic_year'] ?? '2026',
                        ':dns' => !empty($ss['did_not_sit']) ? 1 : 0,
                        ':ascores' => json_encode($ss['assessment_scores'] ?? []),
                        ':fscore' => (float)($ss['final_score'] ?? 0),
                        ':cavg' => (float)($ss['class_average'] ?? 45),
                        ':gr' => $ss['grade'] ?? '9',
                        ':pts' => (int)($ss['points'] ?? 9),
                        ':std' => $ss['standard'] ?? 'UNSATISFACTORY',
                        ':com' => $ss['teacher_comment'] ?? null,
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'subject_scores', 'score_id', $ssIdsKept, false);
            }
        }

        // 8. Exams & Exam Marks
        if (array_key_exists('exams', $payload) && is_array($payload['exams'])) {
            $examIdsKept = [];
            if (!empty($payload['exams'])) {
                $exStmt = $db->prepare("INSERT INTO exams (exam_id, title, exam_type, class_id, subject, total_marks, passing_marks, exam_date, start_time, end_time, term, academic_year)
                                        VALUES (:eid, :ttl, :et, :cid, :sub, :tm, :pm, :ed, :st, :etm, :trm, :ay)
                                        ON DUPLICATE KEY UPDATE title = VALUES(title), exam_type = VALUES(exam_type), class_id = VALUES(class_id), subject = VALUES(subject), total_marks = VALUES(total_marks), passing_marks = VALUES(passing_marks), exam_date = VALUES(exam_date), start_time = VALUES(start_time), end_time = VALUES(end_time), term = VALUES(term), academic_year = VALUES(academic_year)");
                $validTypes = ['Midterm', 'Final', 'Quiz', 'Unit Assessment', 'Mock', 'Continuous Assessment'];
                foreach ($payload['exams'] as $ex) {
                    $eid = (int)$ex['exam_id'];
                    $examIdsKept[] = $eid;
                    $exType = in_array($ex['exam_type'] ?? '', $validTypes, true) ? $ex['exam_type'] : 'Midterm';
                    $exStmt->execute([
                        ':eid' => $eid,
                        ':ttl' => $ex['title'] ?? 'Exam',
                        ':et' => $exType,
                        ':cid' => (int)($ex['class_id'] ?? 1),
                        ':sub' => $ex['subject'] ?? 'MATHEMATICS',
                        ':tm' => (float)($ex['total_marks'] ?? 100),
                        ':pm' => (float)($ex['passing_marks'] ?? 40),
                        ':ed' => $ex['exam_date'] ?? date('Y-m-d'),
                        ':st' => $ex['start_time'] ?? '08:00:00',
                        ':etm' => $ex['end_time'] ?? '10:00:00',
                        ':trm' => $ex['term'] ?? 'TERM 2 2026',
                        ':ay' => $ex['academic_year'] ?? '2026',
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'exams', 'exam_id', $examIdsKept, true);
            }
        }

        if (array_key_exists('exam_marks', $payload) && is_array($payload['exam_marks'])) {
            $emIdsKept = [];
            if (!empty($payload['exam_marks'])) {
                $emStmt = $db->prepare("INSERT INTO exam_marks (exam_mark_id, exam_id, student_id, marks_obtained, grade, points, standard, remarks)
                                        VALUES (:emid, :eid, :sid, :mo, :gr, :pts, :std, :rem)
                                        ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained), grade = VALUES(grade), points = VALUES(points), standard = VALUES(standard), remarks = VALUES(remarks)");
                foreach ($payload['exam_marks'] as $em) {
                    $emid = (int)$em['exam_mark_id'];
                    $emIdsKept[] = $emid;
                    $emStmt->execute([
                        ':emid' => $emid,
                        ':eid' => (int)$em['exam_id'],
                        ':sid' => (int)$em['student_id'],
                        ':mo' => (float)($em['marks_obtained'] ?? 0),
                        ':gr' => $em['grade'] ?? '9',
                        ':pts' => (int)($em['points'] ?? 9),
                        ':std' => $em['standard'] ?? 'UNSATISFACTORY',
                        ':rem' => $em['remarks'] ?? null,
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'exam_marks', 'exam_mark_id', $emIdsKept, true);
            }
        }

        // 9. Fees
        if (array_key_exists('fees', $payload) && is_array($payload['fees'])) {
            $feeIdsKept = [];
            if (!empty($payload['fees'])) {
                $fStmt = $db->prepare("INSERT INTO fees (fee_id, student_id, title, total_amount, paid_amount, due_date, status, receipt_no)
                                       VALUES (:fid, :sid, :title, :total, :paid, :due, :st, :rec)
                                       ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), title = VALUES(title), total_amount = VALUES(total_amount), paid_amount = VALUES(paid_amount), due_date = VALUES(due_date), status = VALUES(status), receipt_no = VALUES(receipt_no)");
                foreach ($payload['fees'] as $f) {
                    $fid = (int)$f['fee_id'];
                    $feeIdsKept[] = $fid;
                    $fStmt->execute([
                        ':fid' => $fid,
                        ':sid' => (int)$f['student_id'],
                        ':title' => $f['title'] ?? 'Tuition Fee',
                        ':total' => (float)($f['total_amount'] ?? 0),
                        ':paid' => (float)($f['paid_amount'] ?? 0),
                        ':due' => $f['due_date'] ?? date('Y-m-d'),
                        ':st' => in_array($f['status'] ?? '', ['paid', 'partial', 'unpaid']) ? $f['status'] : 'unpaid',
                        ':rec' => $f['receipt_no'] ?? null
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'fees', 'fee_id', $feeIdsKept, true);
            }
        }

        // 10. Timetable
        if (array_key_exists('timetable', $payload) && is_array($payload['timetable'])) {
            $ttIdsKept = [];
            if (!empty($payload['timetable'])) {
                $ttStmt = $db->prepare("INSERT INTO timetable (timetable_id, class_id, day_of_week, period_number, start_time, end_time, subject, room, teacher_id)
                                        VALUES (:tid, :cid, :dow, :pn, :st, :et, :sub, :rm, :tch)
                                        ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), day_of_week = VALUES(day_of_week), period_number = VALUES(period_number), start_time = VALUES(start_time), end_time = VALUES(end_time), subject = VALUES(subject), room = VALUES(room), teacher_id = VALUES(teacher_id)");
                $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                foreach ($payload['timetable'] as $tt) {
                    $tid = (int)$tt['timetable_id'];
                    $ttIdsKept[] = $tid;
                    $dow = in_array($tt['day_of_week'] ?? '', $validDays, true) ? $tt['day_of_week'] : 'Monday';
                    $ttStmt->execute([
                        ':tid' => $tid,
                        ':cid' => (int)$tt['class_id'],
                        ':dow' => $dow,
                        ':pn' => (int)($tt['period_number'] ?? 1),
                        ':st' => $tt['start_time'] ?? '08:00:00',
                        ':et' => $tt['end_time'] ?? '08:40:00',
                        ':sub' => $tt['subject'] ?? 'MATHEMATICS',
                        ':rm' => $tt['room'] ?? null,
                        ':tch' => !empty($tt['teacher_id']) ? (int)$tt['teacher_id'] : null,
                    ]);
                }
            }
            if ($isFullSync) {
                $reconcileDeletions($db, 'timetable', 'timetable_id', $ttIdsKept, true);
            }
        }

        // 11. Learning Materials & Homework
        if (array_key_exists('learning_materials', $payload) && is_array($payload['learning_materials'])) {
            $lmIdsKept = [];
            try {
                if (!empty($payload['learning_materials'])) {
                    $lmStmt = $db->prepare("INSERT INTO learning_materials 
                        (material_id, title, description, type, subject, class_id, class_name, teacher_id, teacher_name, due_date, max_points, attachment_name, attachment_size, attachment_type, academic_year, term, created_at)
                        VALUES (:mid, :title, :desc, :type, :sub, :cid, :cname, :tid, :tname, :due, :pts, :att_name, :att_sz, :att_typ, :ay, :trm, :created)
                        ON DUPLICATE KEY UPDATE 
                        title = VALUES(title), description = VALUES(description), type = VALUES(type), subject = VALUES(subject), class_id = VALUES(class_id), class_name = VALUES(class_name), due_date = VALUES(due_date), max_points = VALUES(max_points), attachment_name = VALUES(attachment_name), attachment_size = VALUES(attachment_size), attachment_type = VALUES(attachment_type), academic_year = VALUES(academic_year), term = VALUES(term)");
                    foreach ($payload['learning_materials'] as $lm) {
                        $mid = (string)$lm['material_id'];
                        $lmIdsKept[] = $mid;
                        $lmStmt->execute([
                            ':mid' => $mid,
                            ':title' => $lm['title'] ?? '',
                            ':desc' => $lm['description'] ?? '',
                            ':type' => in_array($lm['type'] ?? '', ['homework', 'notes', 'document', 'past_paper']) ? $lm['type'] : 'homework',
                            ':sub' => $lm['subject'] ?? 'General',
                            ':cid' => (int)($lm['class_id'] ?? 1),
                            ':cname' => $lm['class_name'] ?? null,
                            ':tid' => (int)($lm['teacher_id'] ?? 1),
                            ':tname' => $lm['teacher_name'] ?? 'Instructor',
                            ':due' => $lm['due_date'] ?? null,
                            ':pts' => isset($lm['max_points']) ? (float)$lm['max_points'] : null,
                            ':att_name' => $lm['attachment_name'] ?? null,
                            ':att_sz' => $lm['attachment_size'] ?? null,
                            ':att_typ' => $lm['attachment_type'] ?? 'application/pdf',
                            ':ay' => $lm['academic_year'] ?? '2026',
                            ':trm' => $lm['term'] ?? 'TERM 2 2026',
                            ':created' => $lm['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                    }
                }
                if ($isFullSync) {
                    $reconcileDeletions($db, 'learning_materials', 'material_id', $lmIdsKept, false);
                }
            } catch (Exception $e) { /* ignore if optional table absent */ }
        }

        // 12. Homework Submissions
        if (!empty($payload['homework_submissions']) && is_array($payload['homework_submissions'])) {
            try {
                $subStmt = $db->prepare("INSERT INTO homework_submissions 
                    (submission_id, material_id, student_id, student_name, admission_no, submitted_at, submission_text, attachment_name, attachment_size, status, grade, score, teacher_feedback)
                    VALUES (:sid, :mid, :stuid, :sname, :adm, :subat, :stext, :aname, :asize, :status, :grade, :score, :feed)
                    ON DUPLICATE KEY UPDATE 
                    submission_text = VALUES(submission_text), status = VALUES(status), grade = VALUES(grade), score = VALUES(score), teacher_feedback = VALUES(teacher_feedback)");
                foreach ($payload['homework_submissions'] as $sub) {
                    $subStmt->execute([
                        ':sid' => $sub['submission_id'],
                        ':mid' => $sub['material_id'],
                        ':stuid' => (int)$sub['student_id'],
                        ':sname' => $sub['student_name'] ?? '',
                        ':adm' => $sub['admission_no'] ?? '',
                        ':subat' => $sub['submitted_at'] ?? date('Y-m-d H:i:s'),
                        ':stext' => $sub['submission_text'] ?? null,
                        ':aname' => $sub['attachment_name'] ?? null,
                        ':asize' => $sub['attachment_size'] ?? null,
                        ':status' => in_array($sub['status'] ?? '', ['submitted', 'graded']) ? $sub['status'] : 'submitted',
                        ':grade' => $sub['grade'] ?? null,
                        ':score' => isset($sub['score']) ? (float)$sub['score'] : null,
                        ':feed' => $sub['teacher_feedback'] ?? null
                    ]);
                }
            } catch (Exception $e) { /* ignore if optional table absent */ }
        }

        $db->commit();
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");

        echo json_encode([
            'success' => true,
            'message' => 'Client state successfully synchronized into MySQL database.',
            'updated_at' => $updatedAt,
            'synced_at' => $updatedAt
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        try { $db->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ignore) {}
        if ($snapshotSaved) {
            echo json_encode([
                'success' => true,
                'message' => 'School state snapshot synchronized across devices (relational notice: ' . $e->getMessage() . ')',
                'updated_at' => $updatedAt,
                'synced_at' => $updatedAt
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Transaction failed while syncing to MySQL: ' . $e->getMessage()
            ]);
        }
    }
    exit;
}

// ----------------------------------------------------
// 3B. STUDENT_ACTIVITY: Save student homework, CBT exam, & study progress
// ----------------------------------------------------
if ($action === 'student_activity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
        exit;
    }

    try {
        ensureSnapshotTable($db);
        $snapStmt = $db->query("SELECT state_json FROM school_state_snapshots WHERE id = 1 LIMIT 1");
        $snapRow = $snapStmt ? $snapStmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$snapRow || empty($snapRow['state_json'])) {
            echo json_encode(['success' => true]);
            exit;
        }
        $snapData = json_decode($snapRow['state_json'], true);
        if (!is_array($snapData)) {
            echo json_encode(['success' => true]);
            exit;
        }

        $selfId = (int)($authUser['id'] ?? 0);
        $mySid = 0;
        foreach (($snapData['students'] ?? []) as $stu) {
            if ((int)($stu['user_id'] ?? 0) === $selfId) {
                $mySid = (int)($stu['student_id'] ?? 0);
                break;
            }
        }
        if ($mySid <= 0) {
            $stStmt = $db->prepare("SELECT student_id FROM students WHERE user_id = :uid LIMIT 1");
            $stStmt->execute([':uid' => $selfId]);
            $stRow = $stStmt->fetch(PDO::FETCH_ASSOC);
            $mySid = (int)($stRow['student_id'] ?? 0);
        }
        if ($mySid <= 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Student profile not linked.']);
            exit;
        }

        $mergeById = function (array $existing, array $incoming, string $idKey) use ($mySid): array {
            $others = array_values(array_filter($existing, fn($r) => (int)($r['student_id'] ?? 0) !== $mySid));
            $mine = [];
            foreach ($existing as $r) {
                if ((int)($r['student_id'] ?? 0) === $mySid && isset($r[$idKey])) {
                    $mine[(string)$r[$idKey]] = $r;
                }
            }
            foreach ($incoming as $r) {
                if (is_array($r) && (int)($r['student_id'] ?? 0) === $mySid && isset($r[$idKey])) {
                    $mine[(string)$r[$idKey]] = $r;
                }
            }
            return array_merge($others, array_values($mine));
        };

        if (isset($input['homework_submissions']) && is_array($input['homework_submissions'])) {
            $snapData['homework_submissions'] = $mergeById($snapData['homework_submissions'] ?? [], $input['homework_submissions'], 'submission_id');
        }
        if (isset($input['online_exam_submissions']) && is_array($input['online_exam_submissions'])) {
            $snapData['online_exam_submissions'] = $mergeById($snapData['online_exam_submissions'] ?? [], $input['online_exam_submissions'], 'submission_id');
        }
        if (isset($input['student_topic_progress']) && is_array($input['student_topic_progress'])) {
            $snapData['student_topic_progress'] = $mergeById($snapData['student_topic_progress'] ?? [], $input['student_topic_progress'], 'progress_id');
        }

        $updatedAt = date('Y-m-d H:i:s');
        $snapUpsert = $db->prepare("INSERT INTO school_state_snapshots (id, state_json, updated_at)
                                    VALUES (1, :json, :upd)
                                    ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = VALUES(updated_at)");
        $snapUpsert->execute([':json' => json_encode($snapData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ':upd' => $updatedAt]);

        echo json_encode(['success' => true, 'updated_at' => $updatedAt]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save student activity: ' . $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------
// 4. QUERY: Run interactive SQL query (Admin Sandbox)
// ----------------------------------------------------
if ($action === 'query' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sql = trim($input['query'] ?? '');

    if (empty($sql)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Query string is required.']);
        exit;
    }

    try {
        $stmt = $db->query($sql);
        if (stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0 || stripos($sql, 'DESCRIBE') === 0 || stripos($sql, 'EXPLAIN') === 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = count($rows) > 0 ? array_keys($rows[0]) : [];
            echo json_encode([
                'success' => true,
                'is_select' => true,
                'row_count' => count($rows),
                'columns' => $columns,
                'rows' => $rows
            ]);
        } else {
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => true,
                'is_select' => false,
                'affected_rows' => $affected,
                'message' => "Query executed successfully. Affected rows: {$affected}"
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'SQL Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action parameter.']);
