<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class GetAttendanceController extends Controller
{
    /**
     * Get paginated attendance records for a specific date
     * 
     * Query Parameters:
     * - date: YYYY-MM-DD (required)
     * - page: page number (default: 1)
     * - limit: records per page (default: 15)
     * - search: search by employee name or department (optional)
     * - device_uid: filter by device (optional, for role-based access)
     */
    public function index(Request $request)
    {
        try {
            // ── Validation ──
            $request->validate([
                'date'       => 'required|date_format:Y-m-d',
                'page'       => 'integer|min:1',
                'limit'      => 'integer|min:1|max:100',
                'search'     => 'nullable|string|max:100',
                'device_uid' => 'nullable|string',
            ]);

            $authUser = Auth::user();
            if (!$authUser) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $date    = $request->input('date');
            $page    = $request->integer('page', 1);
            $limit   = $request->integer('limit', 15);
            $search  = $request->input('search', '');
            $deviceUid = $request->input('device_uid');

            // ── Parse date ──
            $selectedDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

            // ── Base query for attendance logs ──
            // NOTE: removed the pointless self-join (`users as u2`) that existed
            // in the original code — it joined on users.id = u2.id and nothing
            // was ever selected or filtered from it.
            $query = DB::table('users_logs')
                ->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber')
                ->leftJoin('department', 'users.department_id', '=', 'department.id')
                ->whereDate('users_logs.checkindate', $date)
                ->where('users.del_fingerid', 0);

            // ── Role-based access control ──
            $this->applyRoleBasedFilters($query, $authUser, $deviceUid);

            // ── Search filter ──
            // NOTE: `users.name` does not exist in the `users` table (only
            // `users.username` does), so it has been removed from the search
            // and from every select/groupBy/map below.
            if (!empty($search)) {
                $searchTerm = "%{$search}%";
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('users.username', 'like', $searchTerm)
                      ->orWhere('department.department_name', 'like', $searchTerm);
                });
            }

            // ── Group by user and date ──
            // Get first IN (MIN) and last OUT (MAX) for each user on the date
            $logsData = $query
                ->select(
                    'users.id as employee_id',
                    'users.username',
                    'users.fingerprint_id',
                    'users_logs.checkindate as check_date',
                    'department.department_name as department',
                    DB::raw('MIN(users_logs.timein) as first_in'),
                    DB::raw('MAX(users_logs.timeout) as last_out'),
                    DB::raw('COUNT(*) as punch_count')
                )
                ->groupBy(
                    'users.id',
                    'users.username',
                    'users.fingerprint_id',
                    'users_logs.checkindate',
                    'department.department_name'
                )
                ->orderBy(DB::raw('MIN(users_logs.timein)'), 'asc');

            // ── Get total count before pagination ──
            $totalRecords = $logsData->count();

            // ── Paginate ──
            $logsData = $logsData
                ->forPage($page, $limit)
                ->get();

            // ── Calculate derived fields ──
            $rows = $logsData->map(function ($log) {
                $hasFirstIn = !empty($log->first_in) && $log->first_in !== '00:00:00';
                $hasLastOut = !empty($log->last_out) && $log->last_out !== '00:00:00';

                return [
                    'employee_id'    => $log->employee_id,
                    'fingerprint_id' => $log->fingerprint_id,
                    'name'           => $log->username,
                    'username'       => $log->username,
                    'department'     => $log->department,
                    'check_date'     => $log->check_date,
                    'first_in'       => $log->first_in,
                    'last_out'       => $log->last_out,
                    'punch_count'    => (int) $log->punch_count,
                    'is_complete'    => $hasFirstIn && $hasLastOut
                ];
            });

            // ── Calculate stats ──
            $stats = $this->calculateStats($selectedDate, $authUser, $deviceUid);

            // ── Pagination info ──
            $hasMore  = ($page * $limit) < $totalRecords;
            $remaining = max(0, $totalRecords - ($page * $limit));

            return response()->json([
                'status'    => true,
                'rows'      => $rows,
                'stats'     => $stats,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $limit,
                    'total'        => $totalRecords,
                    'has_more'     => $hasMore,
                    'remaining'    => $remaining
                ],
                'has_more'  => $hasMore,
                'remaining' => $remaining,
                'message'   => 'Attendance records retrieved successfully'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('GetAttendanceController index error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply role-based filters to attendance query.
     *
     * Works against any query builder that has a `users` table joined in
     * with the alias `users` (e.g. `users.id`, `users.device_uid`,
     * `users.department_id`).
     */
    private function applyRoleBasedFilters($query, $authUser, $deviceUid = null)
    {
        if ($authUser->user_types_id == 1) {
            // ── Super Admin: sees all ──
            return;
        }

        if ($authUser->user_types_id == 2) {
            // ── Company Admin: see employees in their company's devices ──
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                $query->whereRaw('1=0'); // Return empty
                return;
            }

            $deviceUids = DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('device_uid');

            if ($deviceUids->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUids);
            } else {
                $query->whereRaw('1=0');
            }
            return;
        }

        if ($authUser->user_types_id == 3) {
            // ── Branch Admin: see employees in their branch's devices ──
            // NOTE: original code queried a non-existent `branch_user` table
            // with a `user_id` column. The actual table is `branch_users`
            // with a `users_id` column.
            $branchIds = DB::table('branch_users')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                $query->whereRaw('1=0');
                return;
            }

            $deviceUids = DB::table('branch_devices')
                ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                ->whereIn('branch_devices.branch_id', $branchIds)
                ->pluck('devices.device_uid');

            if ($deviceUids->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUids);
            } else {
                $query->whereRaw('1=0');
            }
            return;
        }

        if ($authUser->user_types_id == 4) {
            // ── Department Admin: see employees in their departments ──
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                $query->whereRaw('1=0');
                return;
            }

            $query->whereIn('users.department_id', $departmentIds);
            return;
        }

        if ($authUser->user_types_id == 5) {
            // ── Employee: see only own records ──
            $query->where('users.id', $authUser->id);
            return;
        }
    }

    /**
     * Calculate attendance statistics for a given date.
     *
     * `total_employees` here is the count of active employees *in scope*
     * for this admin (regardless of whether they punched in today), so that
     * `absent` reflects employees with zero logs for the date — not just
     * 0 by construction.
     */
    private function calculateStats($date, $authUser, $deviceUid = null)
    {
        $dateStr = $date->toDateString();

        // ── Logs-based query (only employees who punched in today) ──
        $baseQuery = DB::table('users_logs')
            ->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber')
            ->whereDate('users_logs.checkindate', $dateStr)
            ->where('users.del_fingerid', 0);

        $this->applyRoleBasedFilters($baseQuery, $authUser, $deviceUid);

        // Count total punches (all logs for this date)
        $totalPunches = (clone $baseQuery)->count();

        // Group by user to get distinct employees who have at least one
        // punch today, with their first-in / last-out times.
        $employeeStats = (clone $baseQuery)
            ->select(
                'users.id',
                DB::raw('MIN(users_logs.timein) as first_in'),
                DB::raw('MAX(users_logs.timeout) as last_out')
            )
            ->groupBy('users.id')
            ->get();

        $completeCount = 0;
        $stillInsideCount = 0;

        foreach ($employeeStats as $emp) {
            $hasFirstIn = !empty($emp->first_in) && $emp->first_in !== '00:00:00';
            $hasLastOut = !empty($emp->last_out) && $emp->last_out !== '00:00:00';

            if ($hasFirstIn && $hasLastOut) {
                $completeCount++;
            } elseif ($hasFirstIn && !$hasLastOut) {
                $stillInsideCount++;
            }
        }

        $presentCount = $employeeStats->count();

        // ── Roster-based query: total active employees in scope, ──
        // ── independent of whether they have logs for this date.   ──
        $rosterQuery = DB::table('users')
            ->where('users.del_fingerid', 0)
            ->where('users.del_status', 0);

        $this->applyRoleBasedFilters($rosterQuery, $authUser, $deviceUid);

        $totalEmployees = $rosterQuery->count();

        return [
            'total_punches'   => $totalPunches,
            'total_employees' => $totalEmployees,
            'complete'        => $completeCount,
            'still_inside'    => $stillInsideCount,
            'absent'          => max(0, $totalEmployees - $presentCount)
        ];
    }
}