<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsersLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceLogsController extends Controller
{
    /*
    |==========================================================================
    | GET /attendance-logs
    | Role-scoped + device-scoped attendance, grouped by fingerprint + date.
    |==========================================================================
    */
   public function getLogs(Request $request)
{
    $date   = $request->query('date');
    $page   = max(1, (int) $request->query('page', 1));
    $limit  = max(1, (int) $request->query('limit', 15));
    $search = trim((string) $request->query('search', ''));
    $offset = ($page - 1) * $limit;

    $fromDate = $request->query('from_date');
    $toDate   = $request->query('to_date');

    if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return response()->json(['status' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 422);
    }

    $scopeCompany   = $request->query('company_id')     !== null ? (int) $request->query('company_id')     : null;
    $scopeBranch    = $request->query('branch_id')      !== null ? (int) $request->query('branch_id')      : null;
    $scopeDept      = $request->query('department_id')  !== null ? (int) $request->query('department_id')  : null;
    $scopeFpId      = $request->query('fingerprint_id') !== null ? (int) $request->query('fingerprint_id') : null;
    $scopeDeviceUid = $request->query('device_uid')     !== null ? trim($request->query('device_uid'))     : null;

    $needJoin = ($scopeCompany !== null || $scopeBranch !== null || $scopeDept !== null);

    $query = DB::table('users_logs as ul');

    if ($needJoin) {
        $query->join('users as u', function ($join) {
            $join->on('u.fingerprint_id', '=', 'ul.fingerprint_id')
                 ->where('u.del_fingerid', '=', 0);
        });
    }

    if ($date) {
        $query->where('ul.checkindate', $date);
    }
    if ($fromDate) {
        $query->where('ul.checkindate', '>=', $fromDate);
    }
    if ($toDate) {
        $query->where('ul.checkindate', '<=', $toDate);
    }
    if ($search !== '') {
        $query->where('ul.username', 'LIKE', '%' . $search . '%');
    }
    if ($scopeDeviceUid) {
        $query->where('ul.device_uid', $scopeDeviceUid);
    }

    if ($scopeFpId !== null) {
        $query->where('ul.fingerprint_id', $scopeFpId);
    } elseif ($scopeDept !== null) {
        $query->where('u.department_id', $scopeDept);
    } elseif ($scopeBranch !== null) {
        $query->where('u.branch_id', $scopeBranch);
    } elseif ($scopeCompany !== null) {
        $query->where('u.company_id', $scopeCompany);
    }

    $totalEmployees = (clone $query)
        ->select(DB::raw('COUNT(DISTINCT ul.fingerprint_id) as total'))
        ->value('total') ?? 0;

    $totalPunches = (clone $query)->count('ul.id');

    $selects = [
    'ul.fingerprint_id',
    DB::raw('MIN(ul.username) as username'),
    DB::raw('MIN(ul.serialnumber) as serialnumber'),
    DB::raw('MIN(ul.device_uid) as device_uid'),
    DB::raw('MIN(ul.device_branch) as device_branch'),
    DB::raw('MIN(ul.device_dep) as device_dep'),
    'ul.checkindate',
    DB::raw('MIN(ul.timein) as first_in'),
    DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END) as last_out"),
    DB::raw('COUNT(*) as punch_count'),
    DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
    DB::raw('MAX(ul.is_edited) as is_edited'),
    DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.edited_by ORDER BY ul.edited_at DESC), ",", 1) as edited_by'),
    DB::raw('MAX(ul.edited_at) as edited_at'),
    DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY ul.timein ASC), ",", 1) as first_in_id'),
    DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END DESC), ',', 1) as last_out_id"),
];

if ($needJoin) {
    $selects[] = DB::raw('MIN(u.department_id) as department_id');
    $selects[] = DB::raw('MIN(u.branch_id) as branch_id');
    $selects[] = DB::raw('MIN(u.company_id) as company_id');
}

    $rowsRaw = (clone $query)
        ->select($selects)
        ->groupBy('ul.fingerprint_id', 'ul.checkindate')
        ->orderBy('ul.checkindate', 'desc')
        ->orderByRaw('MIN(ul.timein) ASC')
        ->limit($limit)
        ->offset($offset)
        ->get();

    $rows = [];
    foreach ($rowsRaw as $row) {
        $rows[] = [
            'fingerprint_id' => $row->fingerprint_id,
            'name'           => $row->username,
            'company'        => $row->device_branch ?? '',
            'department'     => $row->device_dep    ?? '',
            'serial'         => $row->serialnumber  ?? '',
            'device_uid'     => $row->device_uid    ?? '',
            'date'           => $row->checkindate,
            'first_in'       => $row->first_in,
            'last_out'       => $row->last_out,
            'punch_count'    => (int) $row->punch_count,
            'is_complete'    => ((int) $row->has_out) === 1,
            'department_id'  => $needJoin ? (int) $row->department_id : null,
            'branch_id'      => $needJoin ? (int) $row->branch_id     : null,
            'company_id'     => $needJoin ? (int) $row->company_id    : null,
            'is_edited'      => (int) $row->is_edited,
            'edited_by'      => $row->edited_by ?: null,
            'edited_at'      => $row->edited_at ?: null,
            'first_in_id'    => $row->first_in_id ? (int) $row->first_in_id : null,
            'last_out_id'    => $row->last_out_id ? (int) $row->last_out_id : null,
        ];
    }

    $empFirstIn = $empLastOut = $empDuration = null;
    if ($scopeFpId !== null && count($rows) === 1) {
        $empFirstIn = $rows[0]['first_in'];
        $empLastOut = $rows[0]['last_out'];
        if ($empFirstIn && $empLastOut) {
            $ts = strtotime(date('Y-m-d') . ' ' . $empFirstIn);
            $te = strtotime(date('Y-m-d') . ' ' . $empLastOut);
            $sec = $te - $ts;
            if ($sec > 0) {
                $h = floor($sec / 3600);
                $m = floor(($sec % 3600) / 60);
                $empDuration = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
            }
        }
    }

    $complete  = array_filter($rows, fn ($r) => $r['is_complete']);
    $stillIn   = array_filter($rows, fn ($r) => !$r['is_complete']);
    $hasMore   = ($offset + count($rows)) < $totalEmployees;
    $remaining = max(0, $totalEmployees - ($offset + count($rows)));

    return response()->json([
        'status'    => true,
        'date'      => $date,
        'page'      => $page,
        'limit'     => $limit,
        'rows'      => $rows,
        'has_more'  => $hasMore,
        'remaining' => $remaining,
        'stats'     => [
            'total_punches'   => (int) $totalPunches,
            'total_employees' => (int) $totalEmployees,
            'complete'        => count($complete),
            'still_inside'    => count($stillIn),
            'first_in'        => $empFirstIn,
            'last_out'        => $empLastOut,
            'duration'        => $empDuration,
        ],
        '_scope' => [
            'company_id'     => $scopeCompany,
            'branch_id'      => $scopeBranch,
            'department_id'  => $scopeDept,
            'fingerprint_id' => $scopeFpId,
            'device_uid'     => $scopeDeviceUid,
        ],
    ]);
}

    /*
    |==========================================================================
    | POST /attendance-logs/add
    | Admin-only — manually add an attendance entry for an employee.
    | Frontend sends company_id/branch_id/department_id only to filter the
    | employee dropdown; the actual payload just needs the selected user_id.
    |==========================================================================
    */
    public function addAttendance(Request $request)
    {
        $user = $request->user();

        // ✅ Only Super Admin (1) and Company Admin (2) can add attendance manually.
        if (!in_array((int) $user->user_types_id, [1, 2])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to add attendance records.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'date'    => 'required|date_format:Y-m-d',
            'timein'  => 'required|date_format:H:i:s',
            'timeout' => 'nullable|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        // ✅ employee-യുടെ fingerprint_id / device_uid / branch / department
        // users table-ൽ നിന്ന് നേരിട്ട് എടുക്കുന്നു — dropdown-ൽ user_id തിരഞ്ഞെടുത്താൽ മതി.
        $emp = DB::table('users')
            ->where('id', $data['user_id'])
            ->where('del_fingerid', 0)
            ->first();

        if (!$emp) {
            return response()->json([
                'status'  => false,
                'message' => 'Employee not found or fingerprint has been removed.',
            ], 404);
        }

        // ✅ Duplicate check — അതേ employee + date + timein നിലവിൽ ഉണ്ടോ എന്ന് നോക്കുന്നു.
        $duplicate = DB::table('users_logs')
            ->where('fingerprint_id', $emp->fingerprint_id)
            ->where('device_uid', $emp->device_uid)
            ->where('checkindate', $data['date'])
            ->where('timein', $data['timein'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status'  => false,
                'message' => 'An attendance entry already exists for this employee at this time.',
            ], 409);
        }

        $editedBy = $user->username ?? $user->name ?? 'Admin';

        DB::beginTransaction();
        try {
            UsersLog::create([
                'username'       => $emp->username,
                'user_id'        => $emp->id,
                'serialnumber'   => $emp->serialnumber ?? 0,
                'fingerprint_id' => $emp->fingerprint_id,
                'device_uid'     => $emp->device_uid,
                'device_branch'  => $emp->device_branch,
                'device_dep'     => $emp->device_dep,
                'checkindate'    => $data['date'],
                'timein'         => $data['timein'],
                'timeout'        => $data['timeout'] ?? '00:00:00',
                'fingerout'      => !empty($data['timeout']) ? 1 : 0,
                'is_edited'      => 1,
                'edited_by'      => $editedBy,
                'edited_at'      => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Attendance added successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Add failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /*
    |==========================================================================
    | PUT /attendance-logs/edit
    | Admin-only — edit check-in / check-out for a grouped attendance row.
    |==========================================================================
    */
    public function updateAttendance(Request $request)
    {
        $user = $request->user();

        // ✅ Only Super Admin (1) and Company Admin (2) can edit.
        // Adjust role IDs to match your user_types table.
        if (!in_array((int) $user->user_types_id, [1, 2])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to edit attendance records.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fingerprint_id' => 'required|integer',
            'date'           => 'required|date_format:Y-m-d',
            'first_in_id'    => 'nullable|integer',
            'last_out_id'    => 'nullable|integer',
            'timein'         => 'nullable|date_format:H:i:s',
            'timeout'        => 'nullable|date_format:H:i:s',
            'device_uid'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $editedBy = $user->username ?? $user->name ?? 'Admin';

        if (empty($data['timein']) && empty($data['timeout'])) {
            return response()->json([
                'status'  => false,
                'message' => 'Nothing to update — provide timein and/or timeout.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updatedAny = false;

            if (!empty($data['timein'])) {
                $row = $this->resolveRow(
                    $data['first_in_id'] ?? null,
                    $data['fingerprint_id'],
                    $data['date'],
                    $data['device_uid'] ?? null,
                    'first_in'
                );
                if ($row) {
                    $row->update([
                        'timein'    => $data['timein'],
                        'is_edited' => 1,
                        'edited_by' => $editedBy,
                        'edited_at' => now(),
                    ]);
                    $updatedAny = true;
                }
            }

            if (!empty($data['timeout'])) {
                $row = $this->resolveRow(
                    $data['last_out_id'] ?? null,
                    $data['fingerprint_id'],
                    $data['date'],
                    $data['device_uid'] ?? null,
                    'last_out'
                );
                if ($row) {
                    $row->update([
                        'timeout'   => $data['timeout'],
                        'is_edited' => 1,
                        'edited_by' => $editedBy,
                        'edited_at' => now(),
                    ]);
                    $updatedAny = true;
                }
            }

            if (!$updatedAny) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching attendance row found to update.',
                ], 404);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Attendance updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve which users_logs row to update.
     * Prefers explicit row id sent by frontend; falls back to
     * earliest/latest punch lookup for that fingerprint + date.
     */
    private function resolveRow(?int $explicitId, int $fingerprintId, string $date, ?string $deviceUid, string $type)
    {
        if ($explicitId) {
            return UsersLog::find($explicitId);
        }

        $query = UsersLog::where('fingerprint_id', $fingerprintId)
            ->where('checkindate', $date);

        if ($deviceUid) {
            $query->where('device_uid', $deviceUid);
        }

        if ($type === 'first_in') {
            return $query->orderBy('timein', 'asc')->first();
        }

        return $query->orderByRaw("timeout != '00:00:00' DESC")
                      ->orderBy('timeout', 'desc')
                      ->first();
    }
}