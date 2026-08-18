<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ManageUserController;
use App\Http\Controllers\Api\UserLogController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\GetdataController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\AttendanceReportController;
use App\Http\Controllers\Api\AttendanceLogsController;
use App\Http\Controllers\Api\MyAttendanceController;
use App\Http\Controllers\Api\MyLeaveController;
use App\Http\Controllers\Api\MyProfileController;
use App\Http\Controllers\Api\MyDocumentsController;
use App\Http\Controllers\Api\AdminLeaveController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\UserTypeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\AdminDocumentsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\MyTaskController;
use App\Http\Controllers\Api\TicketController; 
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\MyTicketController;
use App\Http\Controllers\Api\EnergyDeviceController;
use App\Http\Controllers\Api\MilestoneController;



Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/getdata', [GetdataController::class, 'getdata']);
Route::get('/usertypes', [UserTypeController::class, 'index']);
Route::prefix('energy-devices')->group(function () {
    Route::get('/', [EnergyDeviceController::class, 'index']);
    Route::post('/', [EnergyDeviceController::class, 'store']);
    Route::put('/{energyDevice}', [EnergyDeviceController::class, 'update']);
    Route::delete('/{energyDevice}', [EnergyDeviceController::class, 'destroy']);
 
    Route::get('/{uid}/readings', [EnergyDeviceController::class, 'readings']);
    Route::post('/{uid}/readings', [EnergyDeviceController::class, 'storeReading']);
    Route::post('/{uid}/readings/bulk', [EnergyDeviceController::class, 'storeReadingsBulk']);
});
 

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', [AuthController::class, 'user'])->name('user.profile');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Users
    Route::get('/users', [UserController::class, 'listUsersByAccessAndDepartmentOrDevice']);
    Route::get('/company-admins', [UserController::class, 'companyAdmins'])->name('company-admins.index');
    Route::get('/branch-admins', [UserController::class, 'branchAdmins'])->name('branch-admins.index');

    // Company
    Route::post('/companies', [CompanyController::class, 'createCompanyWithAdmin'])->name('company.detail');
    Route::get('/company', [CompanyController::class, 'index'])->name('company.index');
    Route::get('/company/{id}', [CompanyController::class, 'show'])->name('company.show');
    Route::put('/company/{id}', [CompanyController::class, 'update'])->name('company.update');
    Route::delete('/company/{id}', [CompanyController::class, 'destroy'])->name('company.destroy');

    // Branch
    Route::get('/branch', [BranchController::class, 'index'])->name('branch.index');
    Route::get('/branch/{id}', [BranchController::class, 'show'])->name('branch.show');
    Route::post('/branch', [BranchController::class, 'store'])->name('branch.store');
    Route::put('/branch/{id}', [BranchController::class, 'update'])->name('branch.update');
    Route::delete('/branch/{id}', [BranchController::class, 'destroy'])->name('branch.destroy');

    // ManageUsers — specific routes FIRST (before {id} wildcard)
    Route::get('/manageusers/fingerprint-ids', [ManageUserController::class, 'availableFingerprintIds']);
    Route::get('/manageusers/check-scan-status', [ManageUserController::class, 'checkScanStatus']);
    Route::post('/manageusers/assign-fingerprint', [ManageUserController::class, 'assignUserToFingerprint']);
    Route::post('/manageusers/deselect-fingerprint', [ManageUserController::class, 'deselectUserForFingerprint']);
    Route::post('/manageusers/cancel-enrollment', [ManageUserController::class, 'cancelEnrollment']);

    // ManageUsers — generic routes AFTER
    Route::get('/manageusers', [ManageUserController::class, 'index']);
    Route::post('/manageusers', [ManageUserController::class, 'store'])->name('manageusers.store');
    Route::put('/manageusers/{id}', [ManageUserController::class, 'update'])->name('manageusers.update');
    Route::delete('/manageusers/{id}', [ManageUserController::class, 'destroy'])->name('manageusers.destroy');

    // Logs
    Route::get('/logs', [UserLogController::class, 'index'])->name('logs.index');
    Route::get('/logs/today', [UserLogController::class, 'todayUsers'])->name('logs.today');
    Route::get('/logs/today/active-count', [UserLogController::class, 'countTodayActiveUsers']);

    // Attendance Logs — NEW ENDPOINT with server-side role-based scoping
    Route::get('/attendance-logs', [AttendanceLogsController::class, 'getLogs'])->name('attendance.logs');

    // Attendance
    Route::get('/attendance-status', [AttendanceReportController::class, 'attendanceStatus']);

        // Attendance Logs — role-based scoping
    Route::get('/attendance-logs', [AttendanceLogsController::class, 'getLogs'])->name('attendance.logs');

    // ✅ NEW — Admin-only attendance edit
    Route::put('/attendance-logs/edit', [AttendanceLogsController::class, 'updateAttendance'])->name('attendance.logs.edit');

    // ✅ NEW — Employee self-service attendance (My Dashboard), strictly
    // scoped to auth()->user()->id on the backend — no user_id param.
    Route::get('/my-attendance/today',   [MyAttendanceController::class, 'today']);
    Route::get('/my-attendance/history', [MyAttendanceController::class, 'history']);
    Route::post('/my-attendance/mark',   [MyAttendanceController::class, 'mark']);

    // ✅ NEW — Employee self-service Leave/WFH requests (My Dashboard)
    Route::get('/my-leave-requests',  [MyLeaveController::class, 'index']);
    Route::post('/my-leave-requests', [MyLeaveController::class, 'store']);

    // ✅ NEW — Employee self-service profile update (My Dashboard), strictly
    // scoped to auth()->user()->id on the backend — no user_id param.
    Route::get('/my-profile', [MyProfileController::class, 'show']);
    Route::put('/my-profile', [MyProfileController::class, 'update']);

    // ✅ NEW — Employee self-service document uploads (My Dashboard), strictly
    // scoped to auth()->user()->id on the backend — no user_id param.
    Route::get('/my-documents', [MyDocumentsController::class, 'index']);
    Route::post('/my-documents', [MyDocumentsController::class, 'store']);
    Route::delete('/my-documents/{id}', [MyDocumentsController::class, 'destroy']);

    // ✅ NEW — Employee self-service Assigned Tasks (My Dashboard), strictly
    // scoped to auth()->user()->id on the backend — no user_id param.
    Route::get('/my-tasks', [MyTaskController::class, 'index']);
    Route::post('/my-tasks/{id}/complete', [MyTaskController::class, 'complete']);

    // ✅ NEW — Admin review of Leave/WFH requests (approve/reject + reason)
    Route::get('/admin/leave-requests',             [AdminLeaveController::class, 'index']);
    Route::put('/admin/leave-requests/{id}/review', [AdminLeaveController::class, 'review']);

    // ✅ NEW — Notifications (bell dropdown on admin dashboard)
    Route::get('/notifications',                [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read',      [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all',       [NotificationController::class, 'markAllRead']);

    // Devices
    Route::get('/devices/unassigned', [DeviceController::class, 'unassignedDevices']);
    Route::get('/devices/list', [DeviceController::class, 'list'])->name('list');
    Route::post('/devices/store', [DeviceController::class, 'store'])->name('store');
    Route::delete('/devices/destroy', [DeviceController::class, 'destroy'])->name('destroy');
    Route::put('/devices/update-mode', [DeviceController::class, 'updateMode']);
    Route::put('/devices/update', [DeviceController::class, 'updateDetails']);

    // Departments
    Route::get('/departments', [DepartmentController::class, 'listDepartments'])->name('listDeviceDeps');
    Route::post('/departments', [DepartmentController::class, 'createDepartmentWithAdmin']);
    Route::put('/departments/{id}', [DepartmentController::class, 'update']);

    Route::post('/attendance-logs/add', [AttendanceLogsController::class, 'addAttendance']);

    // Holidays — specific routes FIRST (before {id} wildcard)
    Route::post('/holidays/generate-sundays', [HolidayController::class, 'generateSundays']);

    // Holidays — generic routes AFTER
    Route::get('/holidays',        [HolidayController::class, 'index']);
    Route::post('/holidays',       [HolidayController::class, 'store']);
    Route::put('/holidays/{id}',   [HolidayController::class, 'update']);
    Route::delete('/holidays/{id}',[HolidayController::class, 'destroy']);


    Route::get('/leave-summary', [\App\Http\Controllers\Api\AdminLeaveController::class, 'summary']);
    // New route
    Route::post('/admin/leave-requests', [AdminLeaveController::class, 'store']);
    // ✅ Admin review of employee documents (approve/reject + reason)
    Route::get('/admin/documents',              [AdminDocumentsController::class, 'index']);
    Route::put('/admin/documents/{id}/review',  [AdminDocumentsController::class, 'review']);

    // ✅ NEW — Task Assignment (admin assigns a task to an employee,
    // employee gets notified, admin later reviews completed tasks)
    Route::post('/admin/tasks',               [TaskController::class, 'store']);
    Route::get('/admin/tasks',                [TaskController::class, 'index']);
    Route::put('/admin/tasks/{id}/review',    [TaskController::class, 'review']);

    // ✅ NEW — Projects (tickets always belong to one; create these
    // FIRST so a project exists to file tickets against). Specific
    // routes aren't needed here since there's no {project}/child path
    // that collides with the {ticket} wildcard below.
    Route::get('/admin/projects',            [ProjectController::class, 'index']);
    Route::post('/admin/projects',           [ProjectController::class, 'store']);
    Route::get('/admin/projects/{project}',  [ProjectController::class, 'show']);

    // ✅ NEW — Ticket Management (list/filter, create, detail, update, comments, attachments)
    Route::get('/admin/tickets',                       [TicketController::class, 'index']);
    Route::post('/admin/tickets',                       [TicketController::class, 'store']);
    Route::get('/admin/tickets/{ticket}',               [TicketController::class, 'show']);
    Route::put('/admin/tickets/{ticket}',               [TicketController::class, 'update']);
    Route::delete('/admin/tickets/{ticket}',            [TicketController::class, 'destroy']);
    Route::post('/admin/tickets/{ticket}/comments',     [TicketController::class, 'addComment']);
    Route::put('/admin/tickets/{ticket}/comments/{comment}', [TicketController::class, 'updateComment']);
    Route::post('/admin/tickets/{ticket}/attachments',  [TicketController::class, 'uploadAttachment']);
    Route::delete('/admin/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'deleteAttachment']);
    Route::get('/my-tickets', [TicketController::class, 'myTickets']);


    Route::get('/my-tickets/{id}', [MyTicketController::class, 'show']);
    Route::post('/my-tickets/{id}/comments', [MyTicketController::class, 'storeComment']);
    Route::post('/my-tickets/{id}/attachments', [MyTicketController::class, 'storeAttachment']);

    Route::get('/admin/milestones', [MilestoneController::class, 'index']);
    Route::post('/admin/milestones', [MilestoneController::class, 'store']);
    Route::put('/admin/milestones/{milestone}', [MilestoneController::class, 'update']);
    Route::delete('/admin/milestones/{milestone}', [MilestoneController::class, 'destroy']);

    Route::put('/admin/projects/{project}', [ProjectController::class, 'update']);    
    Route::delete('/admin/projects/{project}', [ProjectController::class, 'destroy']);       
    Route::patch('/admin/projects/{project}/restore', [ProjectController::class, 'restore']); 

    Route::put('/admin/leave-requests/{id}', [\App\Http\Controllers\Api\AdminLeaveController::class, 'update']);
    Route::delete('/admin/leave-requests/{id}', [\App\Http\Controllers\Api\AdminLeaveController::class, 'destroy']);
    Route::get('/admin/activity-report', [TicketController::class, 'activityReport']);
    });