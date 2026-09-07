<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\System\AuthController;
use App\Http\Controllers\Api\PPDB\PublicRegistrationController;
use App\Http\Controllers\Api\Academic\ClassController;
use App\Http\Controllers\Api\Academic\ClassSubjectController;
use App\Http\Controllers\Api\Academic\GradeController;
use App\Http\Controllers\Api\Facilities\RoomController;
use App\Http\Controllers\Api\Staff\TeacherController;
use App\Http\Controllers\Api\Students\StudentController;
use App\Http\Controllers\Api\Communication\AnnouncementController;
use App\Http\Controllers\Api\Students\AttendanceController;
use App\Http\Controllers\Api\Academic\SubjectController;

use App\Http\Controllers\Api\Students\StudentProfileController;
use App\Http\Controllers\Api\Students\StudentAssignmentController;
use App\Http\Controllers\Api\Students\StudentGradeController;
use App\Http\Controllers\Api\Students\StudentScheduleController;
use App\Http\Controllers\Api\Students\StudentAttendanceController;
use App\Http\Controllers\Api\Students\Finance\StudentBillingController;
use App\Http\Controllers\Api\Students\StudentAchievementController;
use App\Http\Controllers\Api\Students\StudentExtracurricularController;
use App\Http\Controllers\Api\Students\StudentViolationController;
use App\Http\Controllers\Api\Students\StudentExaminationController;
use App\Http\Controllers\Api\Students\StudentExamAttemptController;
use App\Http\Controllers\Api\Teachers\TeacherClassController;
use App\Http\Controllers\Api\Teachers\TeacherScheduleController;
use App\Http\Controllers\Api\Teachers\TeacherAttendanceController as TeacherStudentAttendanceController;
use App\Http\Controllers\Api\Teachers\TeacherGradeController;
use App\Http\Controllers\Api\Teachers\TeacherAssignmentController as TeacherAssignmentSelfController;
use App\Http\Controllers\Api\Teachers\TeacherExamController;
use App\Http\Controllers\Api\Teachers\TeacherExamScheduleController;
use App\Http\Controllers\Api\Teachers\TeacherExamResultController;
use App\Http\Controllers\Api\Teachers\TeacherExamMonitoringController;
use App\Http\Controllers\Api\Students\Finance\StudentFinanceSummaryController;
use App\Http\Controllers\Api\Students\Finance\StudentPaymentController;
use App\Http\Controllers\Api\Students\Finance\StudentScholarshipController;
use App\Http\Controllers\Api\Students\Finance\StudentTransactionController;
use App\Http\Controllers\Api\System\RoleController;
use App\Http\Controllers\Api\System\PermissionController;
use App\Http\Controllers\Api\System\UserController;
use App\Http\Controllers\Api\System\ProfileController;


use App\Http\Controllers\Api\Examination\ExamController;
use App\Http\Controllers\Api\Examination\ExamSessionController;
use App\Http\Controllers\Api\Examination\ExamScheduleController;
use App\Http\Controllers\Api\Examination\ExamInstructionController;
use App\Http\Controllers\Api\Examination\ExamParticipantController;
use App\Http\Controllers\Api\Examination\ExamResultController;
use App\Http\Controllers\Api\Examination\ExamAnswerController;
use App\Http\Controllers\Api\Examination\QuestionController;
use App\Http\Controllers\Api\Academic\AcademicYearController;
use App\Http\Controllers\Api\Academic\SemesterController;
use App\Http\Controllers\Api\Academic\CurriculumController;
use App\Http\Controllers\Api\Academic\ClassStudentController;
use App\Http\Controllers\Api\Staff\TeacherAssignmentController;
use App\Http\Controllers\Api\Academic\ScheduleController;
use App\Http\Controllers\Api\Academic\PeriodController;
use App\Http\Controllers\Api\Academic\AssignmentController;
use App\Http\Controllers\Api\Academic\ReportCardController;
use App\Http\Controllers\Api\System\AuditLogController;
use App\Http\Controllers\Api\System\SettingController;
use App\Http\Controllers\Api\Students\StudentParentController;
use App\Http\Controllers\Api\Students\GuardianController;
use App\Http\Controllers\Api\Students\StudentHistoryController;
use App\Http\Controllers\Api\Development\AchievementController;
use App\Http\Controllers\Api\Development\ViolationController;
use App\Http\Controllers\Api\Finance\ScholarshipController;
use App\Http\Controllers\Api\Students\TransferController;
use App\Http\Controllers\Api\Students\AlumniController;
use App\Http\Controllers\Api\Students\StudentIdCardController;
use App\Http\Controllers\Api\Staff\StaffController;
use App\Http\Controllers\Api\Staff\TeacherAttendanceController;
use App\Http\Controllers\Api\Staff\TeacherLeaveController;
use App\Http\Controllers\Api\Staff\TeacherDocumentController;
use App\Http\Controllers\Api\Facilities\AssetController;
use App\Http\Controllers\Api\Facilities\MaintenanceController;
use App\Http\Controllers\Api\Communication\UserNotificationController;
use App\Http\Controllers\Api\Communication\CalendarController;
use App\Http\Controllers\Api\Development\CounselingController;
use App\Http\Controllers\Api\Development\ExtracurricularController;
use App\Http\Controllers\Api\Finance\FeeTypeController;
use App\Http\Controllers\Api\Finance\BillingController;
use App\Http\Controllers\Api\Finance\PaymentController;
use App\Http\Controllers\Api\Finance\PaymentTransactionController;
use App\Http\Controllers\Api\Finance\FinancialReportController;
use App\Http\Controllers\Api\Reports\AcademicReportController;
use App\Http\Controllers\Api\Reports\StudentReportController;
use App\Http\Controllers\Api\Reports\TeacherReportController;
use App\Http\Controllers\Api\Reports\FinanceReportController;
use App\Http\Controllers\Api\Reports\AttendanceReportController;
use App\Http\Controllers\Api\Reports\InventoryReportController;
use App\Http\Controllers\Api\Administration\IncomingLetterController;
use App\Http\Controllers\Api\Administration\OutgoingLetterController;
use App\Http\Controllers\Api\Administration\DocumentController;
use App\Http\Controllers\Api\Administration\AdministrationDocumentController;
use App\Http\Controllers\Api\Administration\DispositionController;
use App\Http\Controllers\Api\Facilities\InventoryController;
use App\Http\Controllers\Api\Facilities\StockMovementController;
use App\Http\Controllers\Api\PPDB\RegistrationController;
use App\Http\Controllers\Api\PPDB\VerificationController;
use App\Http\Controllers\Api\PPDB\SelectionController;
use App\Http\Controllers\Api\PPDB\ReRegistrationController;

Route::post('/login', [AuthController::class, 'login']);

// PUBLIC PPDB REGISTRATION (CodeIgniter form submit) — no auth
Route::post('/ppdb/register', [PublicRegistrationController::class, 'store']);

// PUBLIC SETTINGS (unauthenticated)
Route::get('/public-settings', [SettingController::class, 'public']);

Route::middleware('auth:sanctum')->group(function () {

    // =========================
    // AUTH
    // =========================
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile (self-service, any authenticated user)
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);


    // =========================
    // CLASSES
    // =========================
    Route::get('/classes', [ClassController::class, 'index']);
    Route::get('/classes/{class}', [ClassController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/classes', [ClassController::class, 'store']);
        Route::put('/classes/{class}', [ClassController::class, 'update']);
        Route::patch('/classes/{class}', [ClassController::class, 'update']);
        Route::delete('/classes/{class}', [ClassController::class, 'destroy']);
    });


    // =========================
    // TEACHERS
    // =========================
    Route::get('/teachers', [TeacherController::class, 'index']);
    Route::get('/teachers/export', [TeacherController::class, 'export']);
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/teachers/import', [TeacherController::class, 'import']);
        Route::post('/teachers', [TeacherController::class, 'store']);
        Route::put('/teachers/{teacher}', [TeacherController::class, 'update']);
        Route::patch('/teachers/{teacher}', [TeacherController::class, 'update']);
        Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy']);
    });


    // =========================
    // STUDENTS
    // =========================
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{student}', [StudentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/students', [StudentController::class, 'store']);
        Route::put('/students/{student}', [StudentController::class, 'update']);
        Route::patch('/students/{student}', [StudentController::class, 'update']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);
    });


    // =========================
    // ATTENDANCE (kehadiran siswa)
    // =========================
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/{attendance}', [AttendanceController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::put('/attendance/{attendance}', [AttendanceController::class, 'update']);
        Route::patch('/attendance/{attendance}', [AttendanceController::class, 'update']);
        Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy']);
    });


    // =========================
    // SUBJECTS
    // =========================
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/subjects/{subject}', [SubjectController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::patch('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);
    });


    // =========================
    // CLASS SUBJECTS
    // =========================
    Route::get('/class-subjects', [ClassSubjectController::class, 'index']);
    Route::get('/class-subjects/{class_subject}', [ClassSubjectController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/class-subjects', [ClassSubjectController::class, 'store']);
        Route::put('/class-subjects/{class_subject}', [ClassSubjectController::class, 'update']);
        Route::patch('/class-subjects/{class_subject}', [ClassSubjectController::class, 'update']);
        Route::delete('/class-subjects/{class_subject}', [ClassSubjectController::class, 'destroy']);
    });


    // =========================
    // GRADES
    // =========================
    Route::get('/grades', [GradeController::class, 'index']);
    Route::get('/grades/{grade}', [GradeController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/grades', [GradeController::class, 'store']);
        Route::put('/grades/{grade}', [GradeController::class, 'update']);
        Route::patch('/grades/{grade}', [GradeController::class, 'update']);
        Route::delete('/grades/{grade}', [GradeController::class, 'destroy']);
    });

        // =========================
    // ANNOUNCEMENTS
    // =========================
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    });

    // Rooms API
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{room}', [RoomController::class, 'update']);
        Route::patch('/rooms/{room}', [RoomController::class, 'update']);
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
    });


    // =========================
    // ASSETS
    // =========================
    Route::get('/assets', [AssetController::class, 'index']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/assets', [AssetController::class, 'store']);
        Route::put('/assets/{asset}', [AssetController::class, 'update']);
        Route::patch('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);
    });


    // =========================
    // MAINTENANCE
    // =========================
    Route::get('/maintenance', [MaintenanceController::class, 'index']);
    Route::get('/maintenance/{maintenance}', [MaintenanceController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/maintenance', [MaintenanceController::class, 'store']);
        Route::put('/maintenance/{maintenance}', [MaintenanceController::class, 'update']);
        Route::patch('/maintenance/{maintenance}', [MaintenanceController::class, 'update']);
        Route::delete('/maintenance/{maintenance}', [MaintenanceController::class, 'destroy']);
    });


    // =========================
    // INVENTORY
    // =========================
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    Route::get('/inventory/{inventory}/movements', [StockMovementController::class, 'movements']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/inventory', [InventoryController::class, 'store']);
        Route::put('/inventory/{inventory}', [InventoryController::class, 'update']);
        Route::patch('/inventory/{inventory}', [InventoryController::class, 'update']);
        Route::delete('/inventory/{inventory}', [InventoryController::class, 'destroy']);
        Route::post('/inventory/{inventory}/stock-in', [StockMovementController::class, 'stockIn']);
        Route::post('/inventory/{inventory}/stock-out', [StockMovementController::class, 'stockOut']);
        Route::post('/inventory/{inventory}/adjustment', [StockMovementController::class, 'adjustment']);
    });


    // =========================

    // ROLES
    // =========================
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::post('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });


    // =========================
    // PERMISSIONS (read-only — system-defined catalog)
    // =========================
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/{permission}', [PermissionController::class, 'show']);


    // =========================
    // USERS MANAGEMENT
    // =========================
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::post('/users/{user}/permissions', [UserController::class, 'syncPermissions']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });


    // =========================
    // EXAMS
    // =========================
    Route::get('/exams', [ExamController::class, 'index']);
    Route::get('/exams/{exam}', [ExamController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exams', [ExamController::class, 'store']);
        Route::put('/exams/{exam}', [ExamController::class, 'update']);
        Route::patch('/exams/{exam}', [ExamController::class, 'update']);
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);
    });


    // =========================
    // QUESTION BANKS
    // =========================
    Route::get('/questions', [QuestionController::class, 'index']);
    Route::get('/questions/{id}', [QuestionController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/questions', [QuestionController::class, 'store']);
        Route::put('/questions/{id}', [QuestionController::class, 'update']);
        Route::patch('/questions/{id}', [QuestionController::class, 'update']);
        Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);
    });


    // =========================
    // EXAM SESSIONS
    // =========================
    Route::get('/exam-sessions', [ExamSessionController::class, 'index']);
    Route::get('/exam-sessions/{exam_session}', [ExamSessionController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-sessions', [ExamSessionController::class, 'store']);
        Route::put('/exam-sessions/{exam_session}', [ExamSessionController::class, 'update']);
        Route::patch('/exam-sessions/{exam_session}', [ExamSessionController::class, 'update']);
        Route::delete('/exam-sessions/{exam_session}', [ExamSessionController::class, 'destroy']);
    });


    // =========================
    // EXAM SCHEDULES
    // =========================
    Route::get('/exam-schedules', [ExamScheduleController::class, 'index']);
    Route::get('/exam-schedules/{exam_schedule}', [ExamScheduleController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-schedules', [ExamScheduleController::class, 'store']);
        Route::put('/exam-schedules/{exam_schedule}', [ExamScheduleController::class, 'update']);
        Route::patch('/exam-schedules/{exam_schedule}', [ExamScheduleController::class, 'update']);
        Route::delete('/exam-schedules/{exam_schedule}', [ExamScheduleController::class, 'destroy']);
    });


    // =========================
    // EXAM INSTRUCTIONS
    // =========================
    Route::get('/exam-instructions', [ExamInstructionController::class, 'index']);
    Route::get('/exam-instructions/{exam_instruction}', [ExamInstructionController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-instructions', [ExamInstructionController::class, 'store']);
        Route::put('/exam-instructions/{exam_instruction}', [ExamInstructionController::class, 'update']);
        Route::patch('/exam-instructions/{exam_instruction}', [ExamInstructionController::class, 'update']);
        Route::delete('/exam-instructions/{exam_instruction}', [ExamInstructionController::class, 'destroy']);
    });


    // =========================
    // EXAM PARTICIPANTS
    // =========================
    Route::get('/exam-participants', [ExamParticipantController::class, 'index']);
    Route::get('/exam-participants/{exam_participant}', [ExamParticipantController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-participants', [ExamParticipantController::class, 'store']);
        Route::put('/exam-participants/{exam_participant}', [ExamParticipantController::class, 'update']);
        Route::patch('/exam-participants/{exam_participant}', [ExamParticipantController::class, 'update']);
        Route::delete('/exam-participants/{exam_participant}', [ExamParticipantController::class, 'destroy']);
    });


    // =========================
    // EXAM RESULTS
    // =========================
    Route::get('/exam-results', [ExamResultController::class, 'index']);
    Route::get('/exam-results/{exam_result}', [ExamResultController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-results', [ExamResultController::class, 'store']);
        Route::put('/exam-results/{exam_result}', [ExamResultController::class, 'update']);
        Route::patch('/exam-results/{exam_result}', [ExamResultController::class, 'update']);
        Route::delete('/exam-results/{exam_result}', [ExamResultController::class, 'destroy']);
    });


    // =========================
    // EXAM ANSWERS
    // =========================
    Route::get('/exam-answers', [ExamAnswerController::class, 'index']);
    Route::get('/exam-answers/{exam_answer}', [ExamAnswerController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/exam-answers', [ExamAnswerController::class, 'store']);
        Route::put('/exam-answers/{exam_answer}', [ExamAnswerController::class, 'update']);
        Route::patch('/exam-answers/{exam_answer}', [ExamAnswerController::class, 'update']);
        Route::delete('/exam-answers/{exam_answer}', [ExamAnswerController::class, 'destroy']);
    });


    // =========================
    // ACADEMIC YEARS
    // =========================
    Route::get('/academic-years', [AcademicYearController::class, 'index']);
    Route::get('/academic-years/{academic_year}', [AcademicYearController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/academic-years', [AcademicYearController::class, 'store']);
        Route::put('/academic-years/{academic_year}', [AcademicYearController::class, 'update']);
        Route::patch('/academic-years/{academic_year}', [AcademicYearController::class, 'update']);
        Route::delete('/academic-years/{academic_year}', [AcademicYearController::class, 'destroy']);
    });


    // =========================
    // PPDB REGISTRATIONS
    // =========================
    Route::middleware('throttle:60,1')->group(function () {
      Route::get('/registrations', [RegistrationController::class, 'index']);
      Route::get('/registrations/export-dapodik-list', [ReRegistrationController::class, 'exportList']);
      Route::get('/registrations/export-dapodik/download', [ReRegistrationController::class, 'exportDapodik']);
      Route::get('/registrations/{registration:int}', [RegistrationController::class, 'show']);
    });

    Route::middleware(['role:Admin,Administrator', 'throttle:30,1'])->group(function () {
      Route::post('/registrations', [RegistrationController::class, 'store']);
      Route::put('/registrations/{registration}', [RegistrationController::class, 'update']);
      Route::patch('/registrations/{registration}', [RegistrationController::class, 'update']);
      Route::delete('/registrations/{registration}', [RegistrationController::class, 'destroy']);
    });


    // =========================
    // PPDB WORKFLOW
    // =========================
    Route::middleware(['role:Admin,Administrator', 'throttle:30,1'])->group(function () {
      Route::post('/registrations/{registration}/verify', [VerificationController::class, 'verify']);
      Route::post('/registrations/{registration}/reject', [VerificationController::class, 'reject']);
      Route::post('/registrations/{registration}/verify-re-registration', [ReRegistrationController::class, 'verifyReRegistration']);
    });

    // Siswa menandai data dirinya sudah lengkap (untuk requirement sebelum verifikasi daftar ulang).
    Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
      Route::post('/registrations/{registration}/complete-data', [ReRegistrationController::class, 'completeData']);
    });

    // =========================
    // RE-REGISTRANTS (data terverifikasi dari Daftar Ulang)
    // =========================
    Route::middleware('role:Admin,Administrator')->group(function () {
      Route::get('/re-registrants', [ReRegistrationController::class, 'index']);
    });

    Route::middleware(['role:Admin,Administrator', 'throttle:30,1'])->group(function () {
      Route::post('/re-registrants/{id}/complete-data', [ReRegistrationController::class, 'completeData']);
      Route::post('/re-registrants/{id}/verify-re-registration', [ReRegistrationController::class, 'verifyReRegistration']);
      Route::get('/re-registrants/export-list', [ReRegistrationController::class, 'exportList']);
      Route::get('/re-registrants/export-dapodik', [ReRegistrationController::class, 'exportDapodik']);
    });

    Route::middleware(['role:Admin,Administrator', 'throttle:10,1'])->group(function () {
      Route::post('/registrations/{registration}/documents', [DocumentController::class, 'store']);
      Route::delete('/registrations/{registration}/documents/{type}', [DocumentController::class, 'destroy']);
    });

    Route::middleware('throttle:30,1')->group(function () {
      Route::get('/registrations/{registration}/documents', [DocumentController::class, 'index']);
      Route::get('/registrations/{registration}/documents/{type}', [DocumentController::class, 'show']);
    });


    // =========================

    // SEMESTERS
    // =========================
    Route::get('/semesters', [SemesterController::class, 'index']);
    Route::get('/semesters/{semester}', [SemesterController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/semesters', [SemesterController::class, 'store']);
        Route::put('/semesters/{semester}', [SemesterController::class, 'update']);
        Route::patch('/semesters/{semester}', [SemesterController::class, 'update']);
        Route::delete('/semesters/{semester}', [SemesterController::class, 'destroy']);
    });


    // =========================
    // CURRICULUMS
    // =========================
    Route::get('/curriculums', [CurriculumController::class, 'index']);
    Route::get('/curriculums/{curriculum}', [CurriculumController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/curriculums', [CurriculumController::class, 'store']);
        Route::put('/curriculums/{curriculum}', [CurriculumController::class, 'update']);
        Route::patch('/curriculums/{curriculum}', [CurriculumController::class, 'update']);
        Route::delete('/curriculums/{curriculum}', [CurriculumController::class, 'destroy']);
    });


    // =========================
    // CLASS STUDENTS
    // =========================
    Route::get('/class-students', [ClassStudentController::class, 'index']);
    Route::get('/class-students/{class_student}', [ClassStudentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/class-students', [ClassStudentController::class, 'store']);
        Route::put('/class-students/{class_student}', [ClassStudentController::class, 'update']);
        Route::patch('/class-students/{class_student}', [ClassStudentController::class, 'update']);
        Route::delete('/class-students/{class_student}', [ClassStudentController::class, 'destroy']);
    });


    // =========================
    // TEACHER ASSIGNMENTS
    // =========================
    Route::get('/teacher-assignments', [TeacherAssignmentController::class, 'index']);
    Route::get('/teacher-assignments/{teacher_assignment}', [TeacherAssignmentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/teacher-assignments', [TeacherAssignmentController::class, 'store']);
        Route::put('/teacher-assignments/{teacher_assignment}', [TeacherAssignmentController::class, 'update']);
        Route::patch('/teacher-assignments/{teacher_assignment}', [TeacherAssignmentController::class, 'update']);
        Route::delete('/teacher-assignments/{teacher_assignment}', [TeacherAssignmentController::class, 'destroy']);
    });


    // =========================
    // SCHEDULES
    // =========================
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/schedules', [ScheduleController::class, 'store']);
        Route::put('/schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::patch('/schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy']);
    });


    // =========================
    // PERIODS
    // =========================
    Route::get('/periods', [PeriodController::class, 'index']);
    Route::get('/periods/{period}', [PeriodController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/periods', [PeriodController::class, 'store']);
        Route::put('/periods/{period}', [PeriodController::class, 'update']);
        Route::patch('/periods/{period}', [PeriodController::class, 'update']);
        Route::delete('/periods/{period}', [PeriodController::class, 'destroy']);
    });


    // =========================
    // ASSIGNMENTS
    // =========================
    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/assignments', [AssignmentController::class, 'store']);
        Route::put('/assignments/{assignment}', [AssignmentController::class, 'update']);
        Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
        Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);
    });


    // =========================
    // REPORT CARDS
    // =========================
    Route::get('/report-cards', [ReportCardController::class, 'index']);
    Route::get('/report-cards/{report_card}', [ReportCardController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/report-cards', [ReportCardController::class, 'store']);
        Route::put('/report-cards/{report_card}', [ReportCardController::class, 'update']);
        Route::patch('/report-cards/{report_card}', [ReportCardController::class, 'update']);
        Route::delete('/report-cards/{report_card}', [ReportCardController::class, 'destroy']);
    });


    // =========================
    // AUDIT LOGS (read-only, khusus admin)
    // =========================
    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{audit_log}', [AuditLogController::class, 'show']);
    });


    // =========================
    // SETTINGS (khusus admin)
    // =========================
    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::get('/settings', [SettingController::class, 'index']);
        Route::get('/settings/{setting}', [SettingController::class, 'show']);
        Route::post('/settings', [SettingController::class, 'store']);
        Route::put('/settings/{setting}', [SettingController::class, 'update']);
        Route::patch('/settings/{setting}', [SettingController::class, 'update']);
        Route::delete('/settings/{setting}', [SettingController::class, 'destroy']);
        Route::post('/settings/upload', [SettingController::class, 'upload']);
    });


    // =========================
    // PARENTS (data orang tua)
    // =========================
    Route::get('/parents', [StudentParentController::class, 'index']);
    Route::get('/parents/{parent}', [StudentParentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/parents', [StudentParentController::class, 'store']);
        Route::put('/parents/{parent}', [StudentParentController::class, 'update']);
        Route::patch('/parents/{parent}', [StudentParentController::class, 'update']);
        Route::delete('/parents/{parent}', [StudentParentController::class, 'destroy']);
    });


    // =========================
    // GUARDIANS (wali)
    // =========================
    Route::get('/guardians', [GuardianController::class, 'index']);
    Route::get('/guardians/{guardian}', [GuardianController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/guardians', [GuardianController::class, 'store']);
        Route::put('/guardians/{guardian}', [GuardianController::class, 'update']);
        Route::patch('/guardians/{guardian}', [GuardianController::class, 'update']);
        Route::delete('/guardians/{guardian}', [GuardianController::class, 'destroy']);
    });


    // =========================
    // STUDENT HISTORIES
    // =========================
    Route::get('/student-histories', [StudentHistoryController::class, 'index']);
    Route::get('/student-histories/{student_history}', [StudentHistoryController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/student-histories', [StudentHistoryController::class, 'store']);
        Route::put('/student-histories/{student_history}', [StudentHistoryController::class, 'update']);
        Route::patch('/student-histories/{student_history}', [StudentHistoryController::class, 'update']);
        Route::delete('/student-histories/{student_history}', [StudentHistoryController::class, 'destroy']);
    });


    // =========================
    // ACHIEVEMENTS (prestasi)
    // =========================
    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::get('/achievements/{achievement}', [AchievementController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/achievements', [AchievementController::class, 'store']);
        Route::put('/achievements/{achievement}', [AchievementController::class, 'update']);
        Route::patch('/achievements/{achievement}', [AchievementController::class, 'update']);
        Route::delete('/achievements/{achievement}', [AchievementController::class, 'destroy']);
    });


    // =========================
    // VIOLATIONS (pelanggaran)
    // =========================
    Route::get('/violations', [ViolationController::class, 'index']);
    Route::get('/violations/{violation}', [ViolationController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/violations', [ViolationController::class, 'store']);
        Route::put('/violations/{violation}', [ViolationController::class, 'update']);
        Route::patch('/violations/{violation}', [ViolationController::class, 'update']);
        Route::delete('/violations/{violation}', [ViolationController::class, 'destroy']);
    });


    // =========================
    // SCHOLARSHIPS (beasiswa)
    // =========================
    Route::get('/scholarships', [ScholarshipController::class, 'index']);
    Route::get('/scholarships/{scholarship}', [ScholarshipController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/scholarships', [ScholarshipController::class, 'store']);
        Route::put('/scholarships/{scholarship}', [ScholarshipController::class, 'update']);
        Route::patch('/scholarships/{scholarship}', [ScholarshipController::class, 'update']);
        Route::delete('/scholarships/{scholarship}', [ScholarshipController::class, 'destroy']);
    });


    // =========================
    // TRANSFERS (mutasi)
    // =========================
    Route::get('/transfers', [TransferController::class, 'index']);
    Route::get('/transfers/{transfer}', [TransferController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::put('/transfers/{transfer}', [TransferController::class, 'update']);
        Route::patch('/transfers/{transfer}', [TransferController::class, 'update']);
        Route::delete('/transfers/{transfer}', [TransferController::class, 'destroy']);
    });


    // =========================
    // ALUMNI
    // =========================
    Route::get('/alumni', [AlumniController::class, 'index']);
    Route::get('/alumni/{alumni}', [AlumniController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/alumni', [AlumniController::class, 'store']);
        Route::put('/alumni/{alumni}', [AlumniController::class, 'update']);
        Route::patch('/alumni/{alumni}', [AlumniController::class, 'update']);
        Route::delete('/alumni/{alumni}', [AlumniController::class, 'destroy']);
    });


    // =========================
    // STUDENT ID CARDS (kartu pelajar)
    // =========================
    Route::get('/student-id-cards', [StudentIdCardController::class, 'index']);
    Route::get('/student-id-cards/{student_id_card}', [StudentIdCardController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/student-id-cards', [StudentIdCardController::class, 'store']);
        Route::put('/student-id-cards/{student_id_card}', [StudentIdCardController::class, 'update']);
        Route::patch('/student-id-cards/{student_id_card}', [StudentIdCardController::class, 'update']);
        Route::delete('/student-id-cards/{student_id_card}', [StudentIdCardController::class, 'destroy']);
    });


    // =========================
    // STAFF (tenaga kependidikan)
    // =========================
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/{staff}', [StaffController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
        Route::patch('/staff/{staff}', [StaffController::class, 'update']);
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
    });


    // =========================
    // TEACHER ATTENDANCE (kehadiran guru)
    // =========================
    Route::get('/teacher-attendances', [TeacherAttendanceController::class, 'index']);
    Route::get('/teacher-attendances/{teacher_attendance}', [TeacherAttendanceController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/teacher-attendances', [TeacherAttendanceController::class, 'store']);
        Route::put('/teacher-attendances/{teacher_attendance}', [TeacherAttendanceController::class, 'update']);
        Route::patch('/teacher-attendances/{teacher_attendance}', [TeacherAttendanceController::class, 'update']);
        Route::delete('/teacher-attendances/{teacher_attendance}', [TeacherAttendanceController::class, 'destroy']);
    });


    // =========================
    // TEACHER LEAVE (cuti guru)
    // =========================
    Route::get('/teacher-leaves', [TeacherLeaveController::class, 'index']);
    Route::get('/teacher-leaves/{teacher_leave}', [TeacherLeaveController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/teacher-leaves', [TeacherLeaveController::class, 'store']);
        Route::put('/teacher-leaves/{teacher_leave}', [TeacherLeaveController::class, 'update']);
        Route::patch('/teacher-leaves/{teacher_leave}', [TeacherLeaveController::class, 'update']);
        Route::delete('/teacher-leaves/{teacher_leave}', [TeacherLeaveController::class, 'destroy']);
    });


    // =========================
    // TEACHER DOCUMENTS (dokumen guru)
    // =========================
    Route::get('/teacher-documents', [TeacherDocumentController::class, 'index']);
    Route::get('/teacher-documents/{teacher_document}', [TeacherDocumentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/teacher-documents', [TeacherDocumentController::class, 'store']);
        Route::put('/teacher-documents/{teacher_document}', [TeacherDocumentController::class, 'update']);
        Route::patch('/teacher-documents/{teacher_document}', [TeacherDocumentController::class, 'update']);
        Route::delete('/teacher-documents/{teacher_document}', [TeacherDocumentController::class, 'destroy']);
    });


    // =========================
    // NOTIFICATIONS
    // =========================
    Route::get('/notifications/my', [UserNotificationController::class, 'my']);
    Route::put('/notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
    Route::get('/notifications/unread-count', [UserNotificationController::class, 'unreadCount']);
    Route::put('/notifications/{notification}/read', [UserNotificationController::class, 'markAsRead']);
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::get('/notifications/{notification}', [UserNotificationController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/notifications', [UserNotificationController::class, 'store']);
        Route::put('/notifications/{notification}', [UserNotificationController::class, 'update']);
        Route::patch('/notifications/{notification}', [UserNotificationController::class, 'update']);
        Route::delete('/notifications/{notification}', [UserNotificationController::class, 'destroy']);
    });


    // =========================
    // CALENDARS (kalender akademik)
    // =========================
    Route::get('/calendars', [CalendarController::class, 'index']);
    Route::get('/calendars/{calendar}', [CalendarController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/calendars', [CalendarController::class, 'store']);
        Route::put('/calendars/{calendar}', [CalendarController::class, 'update']);
        Route::patch('/calendars/{calendar}', [CalendarController::class, 'update']);
        Route::delete('/calendars/{calendar}', [CalendarController::class, 'destroy']);
    });


    // =========================
    // COUNSELINGS (bimbingan konseling)
    // =========================
    Route::get('/counselings', [CounselingController::class, 'index']);
    Route::get('/counselings/{counseling}', [CounselingController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/counselings', [CounselingController::class, 'store']);
        Route::put('/counselings/{counseling}', [CounselingController::class, 'update']);
        Route::patch('/counselings/{counseling}', [CounselingController::class, 'update']);
        Route::delete('/counselings/{counseling}', [CounselingController::class, 'destroy']);
    });


    // =========================
    // EXTRACURRICULARS
    // =========================
    Route::get('/extracurriculums', [ExtracurricularController::class, 'index']);
    Route::get('/extracurriculums/{extracurricular}', [ExtracurricularController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/extracurriculums', [ExtracurricularController::class, 'store']);
        Route::put('/extracurriculums/{extracurricular}', [ExtracurricularController::class, 'update']);
        Route::patch('/extracurriculums/{extracurricular}', [ExtracurricularController::class, 'update']);
        Route::delete('/extracurriculums/{extracurricular}', [ExtracurricularController::class, 'destroy']);
    });


    // =========================
    // FEE TYPES (jenis biaya)
    // =========================
    Route::get('/fee-types', [FeeTypeController::class, 'index']);
    Route::get('/fee-types/{fee_type}', [FeeTypeController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/fee-types', [FeeTypeController::class, 'store']);
        Route::put('/fee-types/{fee_type}', [FeeTypeController::class, 'update']);
        Route::patch('/fee-types/{fee_type}', [FeeTypeController::class, 'update']);
        Route::delete('/fee-types/{fee_type}', [FeeTypeController::class, 'destroy']);
    });


    // =========================
    // BILLINGS (tagihan)
    // =========================
    Route::get('/billings', [BillingController::class, 'index']);
    Route::get('/billings/{billing}', [BillingController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/billings', [BillingController::class, 'store']);
        Route::put('/billings/{billing}', [BillingController::class, 'update']);
        Route::patch('/billings/{billing}', [BillingController::class, 'update']);
        Route::delete('/billings/{billing}', [BillingController::class, 'destroy']);
    });


    // =========================
    // PAYMENTS (pembayaran)
    // =========================
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::put('/payments/{payment}', [PaymentController::class, 'update']);
        Route::patch('/payments/{payment}', [PaymentController::class, 'update']);
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
    });


    // =========================
    // PAYMENT TRANSACTIONS (mutasi transaksi)
    // =========================
    Route::get('/payment-transactions', [PaymentTransactionController::class, 'index']);
    Route::get('/payment-transactions/{payment_transaction}', [PaymentTransactionController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/payment-transactions', [PaymentTransactionController::class, 'store']);
        Route::put('/payment-transactions/{payment_transaction}', [PaymentTransactionController::class, 'update']);
        Route::patch('/payment-transactions/{payment_transaction}', [PaymentTransactionController::class, 'update']);
        Route::delete('/payment-transactions/{payment_transaction}', [PaymentTransactionController::class, 'destroy']);
    });


    // =========================
    // FINANCIAL REPORTS (laporan keuangan)
    // =========================
    Route::get('/financial-reports', [FinancialReportController::class, 'index']);
    Route::get('/financial-reports/{financial_report}', [FinancialReportController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/financial-reports', [FinancialReportController::class, 'store']);
        Route::put('/financial-reports/{financial_report}', [FinancialReportController::class, 'update']);
        Route::patch('/financial-reports/{financial_report}', [FinancialReportController::class, 'update']);
        Route::delete('/financial-reports/{financial_report}', [FinancialReportController::class, 'destroy']);
    });


    // =========================
    // REPORTS (read-only agregasi)
    // =========================
    Route::prefix('reports')->group(function () {
        Route::get('/academic/grades-summary', [AcademicReportController::class, 'gradesSummary']);
        Route::get('/students/summary', [StudentReportController::class, 'summary']);
        Route::get('/teachers/summary', [TeacherReportController::class, 'summary']);
        Route::get('/teachers/attendance-summary', [TeacherReportController::class, 'attendanceSummary']);
        Route::get('/finance/summary', [FinanceReportController::class, 'summary']);
        Route::get('/attendance/daily', [AttendanceReportController::class, 'daily']);
        Route::get('/attendance/student-summary', [AttendanceReportController::class, 'studentSummary']);
        Route::get('/inventory/stock-summary', [InventoryReportController::class, 'stockSummary']);
        Route::get('/inventory/movement-summary', [InventoryReportController::class, 'movementSummary']);
    });


    // =========================
    // INCOMING LETTERS (surat masuk)
    // =========================
    Route::get('/incoming-letters', [IncomingLetterController::class, 'index']);
    Route::get('/incoming-letters/{incoming_letter}', [IncomingLetterController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/incoming-letters', [IncomingLetterController::class, 'store']);
        Route::put('/incoming-letters/{incoming_letter}', [IncomingLetterController::class, 'update']);
        Route::patch('/incoming-letters/{incoming_letter}', [IncomingLetterController::class, 'update']);
        Route::delete('/incoming-letters/{incoming_letter}', [IncomingLetterController::class, 'destroy']);
    });


    // =========================
    // OUTGOING LETTERS (surat keluar)
    // =========================
    Route::get('/outgoing-letters', [OutgoingLetterController::class, 'index']);
    Route::get('/outgoing-letters/{outgoing_letter}', [OutgoingLetterController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/outgoing-letters', [OutgoingLetterController::class, 'store']);
        Route::put('/outgoing-letters/{outgoing_letter}', [OutgoingLetterController::class, 'update']);
        Route::patch('/outgoing-letters/{outgoing_letter}', [OutgoingLetterController::class, 'update']);
        Route::delete('/outgoing-letters/{outgoing_letter}', [OutgoingLetterController::class, 'destroy']);
    });


    // =========================
    // DISPOSITIONS (disposisi surat masuk)
    // =========================
    Route::get('/dispositions', [DispositionController::class, 'index']);
    Route::get('/dispositions/{disposition}', [DispositionController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/dispositions', [DispositionController::class, 'store']);
        Route::put('/dispositions/{disposition}', [DispositionController::class, 'update']);
        Route::patch('/dispositions/{disposition}', [DispositionController::class, 'update']);
        Route::delete('/dispositions/{disposition}', [DispositionController::class, 'destroy']);
    });


    // =========================
    // DOCUMENTS (dokumen administrasi)
    // =========================
    Route::get('/documents', [AdministrationDocumentController::class, 'index']);
    Route::get('/documents/{document}', [AdministrationDocumentController::class, 'show']);

    Route::middleware('role:Admin,Administrator')->group(function () {
        Route::post('/documents', [AdministrationDocumentController::class, 'store']);
        Route::put('/documents/{document}', [AdministrationDocumentController::class, 'update']);
        Route::patch('/documents/{document}', [AdministrationDocumentController::class, 'update']);
        Route::delete('/documents/{document}', [AdministrationDocumentController::class, 'destroy']);
    });


    // =========================
    // STUDENT PORTAL — Academic, Finance, and Activity modules.
    // Authorization is forced from the authenticated, linked Student.
    // =========================
    Route::prefix('student')->middleware('student')->group(function () {
        Route::get('/profile', [StudentProfileController::class, 'show']);
        Route::put('/profile', [StudentProfileController::class, 'update']);
        Route::post('/profile/photo', [StudentProfileController::class, 'updatePhoto']);

        // Phase 9 — Student Academic API (read-only, identity scoped).
        Route::get('/grades', [StudentGradeController::class, 'index']);
        Route::get('/grades/summary', [StudentGradeController::class, 'summary']);
        Route::get('/schedules', [StudentScheduleController::class, 'index']);
        Route::prefix('attendance')->group(function () {
            Route::get('/', [StudentAttendanceController::class, 'index']);
            Route::get('/summary', [StudentAttendanceController::class, 'summary']);
        });

        // Phase 9.1 — Student Assignments (read-only, identity scoped).
        Route::get('/assignments', [StudentAssignmentController::class, 'index']);
        Route::get('/assignments/{assignment}', [StudentAssignmentController::class, 'show']);

        // Phase 9 — Student Finance API (read-only, identity scoped).
        Route::prefix('finance')->group(function () {
            Route::get('/summary', [StudentFinanceSummaryController::class, 'summary']);
            Route::get('/billings', [StudentBillingController::class, 'index']);
            Route::get('/billings/{billing}', [StudentBillingController::class, 'show']);
            Route::get('/payments', [StudentPaymentController::class, 'index']);
            Route::get('/payments/{payment}', [StudentPaymentController::class, 'show']);
            Route::get('/transactions', [StudentTransactionController::class, 'index']);
            Route::get('/transactions/{transaction}', [StudentTransactionController::class, 'show']);
            Route::get('/scholarships', [StudentScholarshipController::class, 'index']);
            Route::get('/scholarships/{scholarship}', [StudentScholarshipController::class, 'show']);
        });

        // Student Examination API (identity scoped).
        Route::get('/exams', [StudentExaminationController::class, 'exams']);
        Route::get('/exam-schedules', [StudentExaminationController::class, 'examSchedules']);
        Route::get('/exam-sessions', [StudentExaminationController::class, 'examSessions']);
        Route::get('/exam-instructions', [StudentExaminationController::class, 'examInstructions']);
        Route::get('/exam-participants', [StudentExaminationController::class, 'examParticipants']);
        Route::get('/exam-results', [StudentExaminationController::class, 'examResults']);
        Route::get('/exam-answers', [StudentExaminationController::class, 'examAnswers']);

        // Phase 10 — Secure Web Exam (write, identity scoped, server-authoritative).
        Route::post('/exam-attempts/start', [StudentExamAttemptController::class, 'start']);
        Route::get('/exam-attempts/{exam_attempt}', [StudentExamAttemptController::class, 'show']);
        Route::get('/exam-attempts/{exam_attempt}/questions', [StudentExamAttemptController::class, 'questions']);
        Route::put('/exam-attempts/{exam_attempt}/answers/{question}', [StudentExamAttemptController::class, 'answer']);
        Route::post('/exam-attempts/{exam_attempt}/submit', [StudentExamAttemptController::class, 'submit']);
        Route::post('/exam-attempts/{exam_attempt}/events', [StudentExamAttemptController::class, 'event']);

        // Student Achievement API (identity scoped).
        Route::get('/achievements', [StudentAchievementController::class, 'index']);

        // Student Violation API (identity scoped).
        Route::get('/violations', [StudentViolationController::class, 'index']);

        // Student Extracurricular API (identity scoped).
        Route::get('/extracurriculars', [StudentExtracurricularController::class, 'index']);
    });

    // =========================
    // TEACHER SELF-SERVICE — Kelas & Siswa (Phase 4).
    // Data scope resolved server-side from the authenticated user ->
    // linked Teacher -> teacher_assignments (client teacher_id is never trusted).
    // =========================
    Route::prefix('teacher')->middleware('auth:sanctum')->group(function () {
        Route::get('/classes', [TeacherClassController::class, 'myClasses'])
            ->middleware('permission:view-classes');
        Route::get('/classes/{class}/students', [TeacherClassController::class, 'myClassStudents'])
            ->middleware('permission:view-students');
        Route::get('/schedules', [TeacherScheduleController::class, 'mySchedules'])
            ->middleware('permission:view-schedules');
        Route::get('/attendance', [TeacherStudentAttendanceController::class, 'roster'])
            ->middleware('permission:view-attendance');
        Route::post('/attendance', [TeacherStudentAttendanceController::class, 'store'])
            ->middleware('permission:manage-attendance');
        Route::get('/assignments', [TeacherAssignmentSelfController::class, 'index'])
            ->middleware('permission:view-assignments');
        Route::post('/assignments', [TeacherAssignmentSelfController::class, 'store'])
            ->middleware('permission:manage-assignments');
        Route::get('/assignments/{assignment}', [TeacherAssignmentSelfController::class, 'show'])
            ->middleware('permission:view-assignments');
        Route::put('/assignments/{assignment}', [TeacherAssignmentSelfController::class, 'update'])
            ->middleware('permission:manage-assignments');
        Route::patch('/assignments/{assignment}', [TeacherAssignmentSelfController::class, 'update'])
            ->middleware('permission:manage-assignments');
        Route::delete('/assignments/{assignment}', [TeacherAssignmentSelfController::class, 'destroy'])
            ->middleware('permission:manage-assignments');
        Route::get('/grades', [TeacherGradeController::class, 'roster'])
            ->middleware('permission:view-grades');
        Route::post('/grades/bulk', [TeacherGradeController::class, 'bulkStore'])
            ->middleware('permission:manage-grades');
        Route::get('/exams', [TeacherExamController::class, 'index'])
            ->middleware('permission:view-exams');
        Route::get('/exams/{exam}', [TeacherExamController::class, 'show'])
            ->middleware('permission:view-exams');
        Route::get('/exam-schedules', [TeacherExamScheduleController::class, 'index'])
            ->middleware('permission:view-exam-schedules');
        Route::get('/exam-schedules/{exam_schedule}', [TeacherExamScheduleController::class, 'show'])
            ->middleware('permission:view-exam-schedules');
        Route::get('/exam-results', [TeacherExamResultController::class, 'index'])
            ->middleware('permission:view-exam-results');
        Route::get('/exam-results/{exam_result}', [TeacherExamResultController::class, 'show'])
            ->middleware('permission:view-exam-results');
        Route::get('/exam-monitoring', [TeacherExamMonitoringController::class, 'index'])
            ->middleware('permission:view-exam-monitoring');
        Route::get('/exam-monitoring/{attempt}', [TeacherExamMonitoringController::class, 'show'])
            ->middleware('permission:view-exam-monitoring');
    });
});
