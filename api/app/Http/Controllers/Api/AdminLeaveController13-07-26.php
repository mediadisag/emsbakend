<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->select([
                'leave_requests.id',
                'leave_requests.user_id',
                'users.username',
                'leave_requests.type',
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
    | PUT /admin/leave-requests/{id}/review
    | Body: { decision: 'Approved'|'Rejected', reason?: string }
    |
    | ✅ NEW — `reason` is REQUIRED when decision = 'Rejected' (so the
    | employee always knows why), and OPTIONAL when Approved (e.g. "approved,
    | please share your WFH plan for the day").
    | Stores into leave_requests.admin_reason, and also fires a
    | notification back to the employee about the decision.
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
            // ⚠️ reviewed_by is varchar(100) in your DB — storing the
            // reviewer's username/name (a display string), not their
            // numeric user_id. Adjust the field name below if your users
            // table uses something other than ->username.
            $leave->update([
                'status'       => $decision,
                'admin_reason' => $reason,
                'reviewed_by'  => $request->user()->username ?? $request->user()->name ?? (string) $request->user()->id,
                'reviewed_at'  => now(),
            ]);

            // ✅ Notify the employee about the decision.
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
    |                       (WFH requests are NOT counted against leave balance)
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
            ->select(['from_date', 'to_date'])
            ->get();

        $leavesTaken = 0;
        foreach ($leaveRows as $row) {
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
}