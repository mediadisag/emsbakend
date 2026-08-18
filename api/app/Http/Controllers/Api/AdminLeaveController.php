<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LeaveRequestReviewedMail;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/*
|==============================================================================
| ⚠️ CONFIRM BEFORE DEPLOYING: replace $adminTypeIds with your real
| company-admin / branch-admin user_types_id values.
|==============================================================================
*/

class AdminLeaveController extends Controller
{
    // ✅ Confirmed role mapping (from Dashboard.html's buildScopeParams):
    // 1 = Super Admin, 2 = Company Admin, 3 = Branch Admin,
    // 4 = Department Admin, 5 = Employee. All except Employees can review.
    private array $adminTypeIds = [1, 2, 3, 4];



    private function assertIsAdmin(Request $request)
{
    $user = $request->user();
    if (!in_array((int) $user->user_types_id, $this->adminTypeIds, true)) {
        return response()->json([
            'status'  => false,
            'message' => 'You are not authorized to review leave requests.',
        ], 403);
    }
    return null;
}

    /*
    |==========================================================================
    | GET /admin/leave-requests?status=Pending
    | GET /admin/leave-requests?status=Approved&user_id=123&from_date=2026-07-01&to_date=2026-07-31
    |
    | ✅ NEW — optional `user_id`, `from_date`, `to_date` filters. Used by
    | employees.html's Attendance calendar to pull one employee's approved
    | leave ranges for the month being viewed, so days that overlap an
    | approved leave request can be shown as "Leave" instead of "Absent".
    | Existing callers that only pass `status` are unaffected.
    |
    | ✅ FIX: also join + select the employee's department_id / department_name.
    | Dashboard.html's matchesMyDepartment() scopes the Leave/WFH list (and
    | its count pill) to the logged-in Department Admin's own department by
    | reading row.department_id / row.department_name off each row. Those
    | fields were never being returned by this endpoint before, so
    | matchesMyDepartment() always failed for non-super-admins and
    | Department Admins saw an empty list + a 0 count.
    |==========================================================================
    */
    public function index(Request $request)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $status   = $request->query('status', 'Pending');
        $userId   = $request->query('user_id');
        $fromDate = $request->query('from_date');
        $toDate   = $request->query('to_date');

        $validator = Validator::make(['status' => $status], [
            'status' => 'in:Pending,Approved,Rejected,All',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $query = LeaveRequest::query()
            ->join('users', 'users.id', '=', 'leave_requests.user_id')
            // ✅ FIX: bring in the employee's department too, so the
            // frontend can scope requests to the right Department Admin.
            ->leftJoin('department', 'department.id', '=', 'users.department_id')
            ->select([
                'leave_requests.id',
                'leave_requests.user_id',
                'users.username',
                'users.department_id',        // ✅ NEW
                'department.department_name', // ✅ NEW
                'leave_requests.type',
                'leave_requests.day_type',   // ✅ Full Day / Half Day
                'leave_requests.from_date',
                'leave_requests.to_date',
                'leave_requests.reason',
                'leave_requests.status',
                'leave_requests.admin_reason',
                'leave_requests.reviewed_by',
                'leave_requests.reviewed_at',
                'leave_requests.created_at',
            ]);

        if ($status !== 'All') {
            $query->where('leave_requests.status', $status);
        }

        // ✅ NEW: optional employee + date-range scoping
        if ($userId) {
            $query->where('leave_requests.user_id', $userId);
        }
        if ($fromDate && $toDate) {
            // overlap condition: request.from_date <= range.to AND request.to_date >= range.from
            $query->where('leave_requests.from_date', '<=', $toDate)
                  ->where('leave_requests.to_date', '>=', $fromDate);
        }

        $rows = $query->orderBy('leave_requests.created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /*
    |==========================================================================
    | POST /admin/leave-requests
    | Body: { user_id, type: 'Leave'|'WFH', day_type: 'Full Day'|'Half Day', from_date, to_date, reason }
    |
    | ✅ NEW — lets an admin (Super/Company/Branch/Department) submit a
    | Leave or WFH request directly on behalf of an employee, from the
    | "Leave / WFH Request" panel on Dashboard.html.
    |
    | ✅ NEW — `day_type` (Full Day / Half Day). When Half Day is selected,
    | from_date and to_date must be the same single day.
    |
    | Scope rules (non-super-admins can only submit for their own people):
    |   - Company Admin (2)    → employee.company_id    must match admin's
    |   - Branch Admin (3)     → employee.branch_id      must match admin's
    |   - Department Admin (4) → employee.department_id  must match admin's
    |   - Super Admin (1)      → no restriction
    |
    | Since an admin is the one submitting this (not the employee), the
    | request is recorded as 'Approved' immediately — no separate review
    | step. The employee gets an in-app notification AND an email, same
    | as a normal review() decision would send.
    |==========================================================================
    */
    public function store(Request $request)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'user_id'   => 'required|integer|exists:users,id',
            'type'      => 'required|in:Leave,WFH',
            'day_type'  => 'required|in:Full Day,Half Day',
            'from_date' => 'required|date',
            'to_date'   => 'required|date|after_or_equal:from_date',
            'reason'    => 'required|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        // ✅ Half Day must be a single day (from_date === to_date)
        if ($request->input('day_type') === 'Half Day'
            && $request->input('from_date') !== $request->input('to_date')) {
            return response()->json([
                'status'  => false,
                'message' => 'Half Day requests must have the same From Date and To Date.',
            ], 422);
        }

        $admin        = $request->user();
        $targetUserId = (int) $request->input('user_id');

        $employee = DB::table('users')->where('id', $targetUserId)->first();
        if (!$employee) {
            return response()->json(['status' => false, 'message' => 'Employee not found.'], 404);
        }

        // ✅ Scope check — non-super-admins restricted to their own company/branch/department.
        if ((int) $admin->user_types_id !== 1) {
            $scopeOk = match ((int) $admin->user_types_id) {
                2 => !empty($admin->company_id)    && (int) ($employee->company_id    ?? 0) === (int) $admin->company_id,
                3 => !empty($admin->branch_id)     && (int) ($employee->branch_id     ?? 0) === (int) $admin->branch_id,
                4 => !empty($admin->department_id) && (int) ($employee->department_id ?? 0) === (int) $admin->department_id,
                default => false,
            };

            if (!$scopeOk) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You are not authorized to submit a request for this employee.',
                ], 403);
            }
        }

        DB::beginTransaction();
        try {
            $adminName = $admin->username ?? $admin->name ?? (string) $admin->id;

            $leave = LeaveRequest::create([
                'user_id'      => $targetUserId,
                'type'         => $request->input('type'),
                'day_type'     => $request->input('day_type'),
                'from_date'    => $request->input('from_date'),
                'to_date'      => $request->input('to_date'),
                'reason'       => $request->input('reason'),
                'status'       => 'Approved',
                'admin_reason' => 'Submitted and approved by admin on behalf of employee.',
                'reviewed_by'  => $adminName,
                'reviewed_at'  => now(),
            ]);

            // ✅ Let the employee know a request was logged for them.
            DB::table('notifications')->insert([
                'user_id'        => $targetUserId,
                'type'           => 'leave_request_reviewed',
                'title'          => "{$leave->type} recorded for you",
                'message'        => "A {$leave->type} ({$leave->day_type}) request ({$leave->from_date} to {$leave->to_date}) was submitted and approved for you by {$adminName}.",
                'reference_type' => 'leave_request',
                'reference_id'   => $leave->id,
                'is_read'        => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::commit();

            // ✅ Email the employee too — same mailable used by review().
            if (!empty($employee->email)) {
                try {
                    Mail::to($employee->email)->send(
                        new LeaveRequestReviewedMail(
                            $leave,
                            'Approved',
                            'Submitted and approved by admin on behalf of employee.',
                            $adminName
                        )
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send leave-request-reviewed email (admin-submitted)', [
                        'leave_id' => $leave->id,
                        'to'       => $employee->email,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'status'  => true,
                'message' => "{$leave->type} request submitted and approved.",
                'data'    => $leave,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /*
    |==========================================================================
    | PUT /admin/leave-requests/{id}/review
    | Body: { decision: 'Approved'|'Rejected', reason?: string }
    |
    | ✅ NEW — `reason` is REQUIRED when decision = 'Rejected' (so the
    | employee always knows why), and OPTIONAL when Approved (e.g. "approved,
    | please share your WFH plan for the day").
    | Stores into leave_requests.admin_reason, fires an in-app notification
    | back to the employee about the decision, AND emails them.
    |==========================================================================
    */
    public function review(Request $request, $id)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:Approved,Rejected',
            'reason'   => $request->input('decision') === 'Rejected'
                ? 'required|string|max:500'
                : 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $leave = LeaveRequest::find($id);
        if (!$leave) {
            return response()->json(['status' => false, 'message' => 'Leave request not found.'], 404);
        }

        if ($leave->status !== 'Pending') {
            return response()->json([
                'status'  => false,
                'message' => "This request has already been {$leave->status}.",
            ], 409);
        }

        $decision = $request->input('decision');
        $reason   = $request->input('reason');

        DB::beginTransaction();
        try {
            $reviewerName = $request->user()->username ?? $request->user()->name ?? (string) $request->user()->id;

            // ⚠️ reviewed_by is varchar(100) in your DB — storing the
            // reviewer's username/name (a display string), not their
            // numeric user_id. Adjust the field name below if your users
            // table uses something other than ->username.
            $leave->update([
                'status'       => $decision,
                'admin_reason' => $reason,
                'reviewed_by'  => $reviewerName,
                'reviewed_at'  => now(),
            ]);

            // ✅ Notify the employee about the decision (in-app).
            $message = $decision === 'Approved'
                ? "Your {$leave->type} request ({$leave->from_date} to {$leave->to_date}) was approved." . ($reason ? " Note: {$reason}" : '')
                : "Your {$leave->type} request ({$leave->from_date} to {$leave->to_date}) was rejected. Reason: {$reason}";

            DB::table('notifications')->insert([
                'user_id'        => $leave->user_id,
                'type'           => 'leave_request_reviewed',
                'title'          => "{$leave->type} request {$decision}",
                'message'        => $message,
                'reference_type' => 'leave_request',
                'reference_id'   => $leave->id,
                'is_read'        => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::commit();

            // ✅ Email the employee — sent after commit so a mail failure
            // never rolls back the already-saved approve/reject decision.
            $employeeEmail = DB::table('users')->where('id', $leave->user_id)->value('email');
            if (!empty($employeeEmail)) {
                try {
                    Mail::to($employeeEmail)->send(
                        new LeaveRequestReviewedMail($leave->fresh(), $decision, $reason, $reviewerName)
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send leave-request-reviewed email', [
                        'leave_id' => $leave->id,
                        'to'       => $employeeEmail,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'status'  => true,
                'message' => "Request {$decision}.",
                'data'    => $leave->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /*
    |==========================================================================
    | GET /leave-summary?user_id=123
    |
    | ✅ NEW — returns a single employee's leave balance for the CURRENT
    | financial year (Apr 1 – Mar 31):
    |   - allowed_leaves : from department.allowed_leaves (that employee's dept)
    |   - leaves_taken   : total days across all APPROVED, type='Leave'
    |                       requests that overlap the current financial year
    |                       (WFH requests are NOT counted against leave balance;
    |                       Half Day requests count as 0.5 days)
    |   - remaining       : allowed_leaves - leaves_taken (floor at 0)
    |
    | Access: any authenticated user can view their OWN summary
    | (user_id === their own id). Admin roles (adminTypeIds) can view
    | any employee's summary — used by the Attendance modal in
    | employees.html when an admin opens an employee's calendar.
    |==========================================================================
    */
    public function summary(Request $request)
    {
        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $targetUserId = (int) $request->query('user_id');

        $isSelf  = ((int) $authUser->id) === $targetUserId;
        $isAdmin = in_array((int) $authUser->user_types_id, $this->adminTypeIds, true);

        if (!$isSelf && !$isAdmin) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to view this leave summary.',
            ], 403);
        }

        $emp = DB::table('users')->where('id', $targetUserId)->first();
        if (!$emp) {
            return response()->json(['status' => false, 'message' => 'Employee not found.'], 404);
        }

        // --- Allowed leaves from the employee's department ---
        $allowedLeaves = 0;
        if (!empty($emp->department_id)) {
            $allowedLeaves = (int) (DB::table('department')
                ->where('id', $emp->department_id)
                ->value('allowed_leaves') ?? 0);
        }

        // --- Current financial year: Apr 1 – Mar 31 ---
        $today = Carbon::now();
        if ($today->month >= 4) {
            $fyStart = Carbon::create($today->year, 4, 1)->startOfDay();
            $fyEnd   = Carbon::create($today->year + 1, 3, 31)->endOfDay();
        } else {
            $fyStart = Carbon::create($today->year - 1, 4, 1)->startOfDay();
            $fyEnd   = Carbon::create($today->year, 3, 31)->endOfDay();
        }

        // --- Approved 'Leave' requests overlapping the current FY ---
        $leaveRows = DB::table('leave_requests')
            ->where('user_id', $targetUserId)
            ->where('status', 'Approved')
            ->where('type', 'Leave') // WFH is excluded from leave balance
            ->where('from_date', '<=', $fyEnd->format('Y-m-d'))
            ->where('to_date', '>=', $fyStart->format('Y-m-d'))
            ->select(['from_date', 'to_date', 'day_type'])
            ->get();

        $leavesTaken = 0;
        foreach ($leaveRows as $row) {
            // ✅ Half Day requests always span a single day and count as 0.5
            if (($row->day_type ?? 'Full Day') === 'Half Day') {
                $leavesTaken += 0.5;
                continue;
            }

            $rowStart = Carbon::parse($row->from_date)->startOfDay();
            $rowEnd   = Carbon::parse($row->to_date)->startOfDay();

            // Clip to the financial-year boundary in case a request spans across it
            $clippedStart = $rowStart->lt($fyStart) ? $fyStart->copy() : $rowStart;
            $clippedEnd   = $rowEnd->gt($fyEnd) ? $fyEnd->copy()->startOfDay() : $rowEnd;

            if ($clippedEnd->gte($clippedStart)) {
                $leavesTaken += $clippedStart->diffInDays($clippedEnd) + 1;
            }
        }

        $remaining = max(0, $allowedLeaves - $leavesTaken);

        return response()->json([
            'status' => true,
            'data'   => [
                'user_id'          => $targetUserId,
                'allowed_leaves'   => $allowedLeaves,
                'leaves_taken'     => $leavesTaken,
                'remaining'        => $remaining,
                'financial_year'   => $fyStart->format('Y') . '-' . $fyEnd->format('Y'),
                'fy_start'         => $fyStart->format('Y-m-d'),
                'fy_end'           => $fyEnd->format('Y-m-d'),
            ],
        ]);
    }

    /*
    |==========================================================================
    | PUT /admin/leave-requests/{id}
    | Body: { type, day_type, from_date, to_date, reason }
    |
    | ✅ NEW — lets an admin correct a leave/WFH request that was already
    | marked for an employee (e.g. it should have been "WFH" not "Leave",
    | or the reason was wrong). Works regardless of the request's current
    | status since this is an admin-driven correction, not the original
    | employee-submitted approval flow. Scope rules mirror store().
    |==========================================================================
    */
    public function update(Request $request, $id)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $leave = LeaveRequest::find($id);
        if (!$leave) {
            return response()->json(['status' => false, 'message' => 'Leave request not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'type'      => 'required|in:Leave,WFH',
            'day_type'  => 'required|in:Full Day,Half Day',
            'from_date' => 'required|date',
            'to_date'   => 'required|date|after_or_equal:from_date',
            'reason'    => 'required|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        if ($request->input('day_type') === 'Half Day'
            && $request->input('from_date') !== $request->input('to_date')) {
            return response()->json([
                'status'  => false,
                'message' => 'Half Day requests must have the same From Date and To Date.',
            ], 422);
        }

        $admin    = $request->user();
        $employee = DB::table('users')->where('id', $leave->user_id)->first();

        if ((int) $admin->user_types_id !== 1) {
            $scopeOk = match ((int) $admin->user_types_id) {
                2 => !empty($admin->company_id)    && (int) ($employee->company_id    ?? 0) === (int) $admin->company_id,
                3 => !empty($admin->branch_id)     && (int) ($employee->branch_id     ?? 0) === (int) $admin->branch_id,
                4 => !empty($admin->department_id) && (int) ($employee->department_id ?? 0) === (int) $admin->department_id,
                default => false,
            };
            if (!$scopeOk) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You are not authorized to edit this request.',
                ], 403);
            }
        }

        $adminName = $admin->username ?? $admin->name ?? (string) $admin->id;

        $leave->update([
            'type'         => $request->input('type'),
            'day_type'     => $request->input('day_type'),
            'from_date'    => $request->input('from_date'),
            'to_date'      => $request->input('to_date'),
            'reason'       => $request->input('reason'),
            'status'       => 'Approved',
            'admin_reason' => 'Updated by admin.',
            'reviewed_by'  => $adminName,
            'reviewed_at'  => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Leave request updated.',
            'data'    => $leave->fresh(),
        ]);
    }

    /*
    |==========================================================================
    | DELETE /admin/leave-requests/{id}
    |
    | ✅ NEW — lets an admin remove a leave/WFH request entirely (e.g. it
    | was marked by mistake). Once removed, the day reverts to whatever the
    | raw attendance logs show — Present if the employee punched, Absent
    | otherwise. Scope rules mirror store()/update().
    |==========================================================================
    */
    public function destroy(Request $request, $id)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $leave = LeaveRequest::find($id);
        if (!$leave) {
            return response()->json(['status' => false, 'message' => 'Leave request not found.'], 404);
        }

        $admin    = $request->user();
        $employee = DB::table('users')->where('id', $leave->user_id)->first();

        if ((int) $admin->user_types_id !== 1) {
            $scopeOk = match ((int) $admin->user_types_id) {
                2 => !empty($admin->company_id)    && (int) ($employee->company_id    ?? 0) === (int) $admin->company_id,
                3 => !empty($admin->branch_id)     && (int) ($employee->branch_id     ?? 0) === (int) $admin->branch_id,
                4 => !empty($admin->department_id) && (int) ($employee->department_id ?? 0) === (int) $admin->department_id,
                default => false,
            };
            if (!$scopeOk) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You are not authorized to remove this request.',
                ], 403);
            }
        }

        $leave->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Leave request removed.',
        ]);
    }
}