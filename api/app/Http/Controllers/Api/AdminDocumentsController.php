<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDocumentsController extends Controller
{
    // ────────────────────────────────────────────────────────────────────────
    // HELPER: same resolvers as ManageUserController — keep in sync if those
    // change. (Consider extracting to a shared trait if this duplication grows.)
    // ────────────────────────────────────────────────────────────────────────
    private function resolveDeptIdForDeptAdmin($authUser): ?int
    {
        if (!empty($authUser->department_id)) {
            return (int) $authUser->department_id;
        }
        $deptId = DB::table('department_users')
            ->where('users_id', $authUser->id)
            ->value('department_id');
        return $deptId ? (int) $deptId : null;
    }

    private function resolveBranchIdForBranchManager($authUser): ?int
    {
        if (!empty($authUser->branch_id)) {
            return (int) $authUser->branch_id;
        }
        $branchId = DB::table('branch_users')
            ->where('users_id', $authUser->id)
            ->value('branch_id');
        return $branchId ? (int) $branchId : null;
    }

    // ✅ NEW: employee's department can live on users.department_id OR
    // (only) in the department_users pivot table. Any query scoping
    // documents/etc by department must match BOTH, or employees who are
    // only pivot-assigned silently vanish from every dept-admin view.
    private function userIdsInDepartment(int $deptId): array
    {
        $direct = DB::table('users')->where('department_id', $deptId)->pluck('id');
        $pivot  = DB::table('department_users')->where('department_id', $deptId)->pluck('users_id');
        return $direct->merge($pivot)->unique()->values()->all();
    }

    // ✅ NEW: same idea for branch_id.
    private function userIdsInBranch(int $branchId): array
    {
        $direct = DB::table('users')->where('branch_id', $branchId)->pluck('id');
        $pivot  = DB::table('branch_users')->where('branch_id', $branchId)->pluck('users_id');
        return $direct->merge($pivot)->unique()->values()->all();
    }

    // GET /api/admin/documents?status=Pending
    public function index(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = EmployeeDocument::with(['user:id,username,department_id,branch_id,company_id'])
            ->orderByDesc('created_at');

        switch ($authUser->user_types_id) {
            case 1:
                // Super Admin — sees everything
                break;

            case 2:
                $companyIds = DB::table('company_users')
                    ->where('users_id', $authUser->id)
                    ->pluck('company_id');
                $query->whereHas('user', function ($q) use ($companyIds) {
                    $q->whereIn('company_id', $companyIds);
                });
                break;

            case 3:
                $branchId = $this->resolveBranchIdForBranchManager($authUser);
                if (!$branchId) {
                    return response()->json(['status' => true, 'rows' => [], 'message' => 'Could not determine branch for this user.']);
                }
                // ✅ FIX: match users.branch_id OR branch_users pivot, not just the column
                $userIds = $this->userIdsInBranch($branchId);
                $query->whereIn('user_id', $userIds);
                break;

            case 4:
                $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                if (!$deptId) {
                    return response()->json(['status' => true, 'rows' => [], 'message' => 'Could not determine department for this user.']);
                }
                // ✅ FIX: match users.department_id OR department_users pivot, not just the column
                $userIds = $this->userIdsInDepartment($deptId);
                $query->whereIn('user_id', $userIds);
                break;

            default:
                return response()->json(['message' => 'Permission denied.'], 403);
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        $docs = $query->get();

        // ✅ FIX: look up department names for every department_id that
        // shows up among these employees, so we can return department_id
        // AND department_name on each row — Dashboard.html's
        // matchesMyDepartment() needs at least one of these to scope the
        // "Documents Pending Review" tile to the logged-in Department
        // Admin. Without them the frontend's client-side re-filter always
        // failed for non-super-admins, even though this endpoint had
        // already scoped the query correctly server-side.
        $deptIds = $docs->pluck('user.department_id')->filter()->unique()->values();
        $deptNames = $deptIds->isEmpty()
            ? collect()
            : DB::table('department')->whereIn('id', $deptIds)->pluck('department_name', 'id');

        $rows = $docs->map(function ($doc) use ($deptNames) {
            $deptId = $doc->user->department_id ?? null;
            return [
                'id'              => $doc->id,
                'user_id'         => $doc->user_id,
                'employee_name'   => $doc->user->username ?? '—',
                'department_id'   => $deptId,                                   // ✅ NEW
                'department_name' => $deptId ? ($deptNames[$deptId] ?? null) : null, // ✅ NEW
                'type'            => $doc->type,
                'file_name'       => $doc->file_name,
                'file_url'        => $doc->file_path ? asset($doc->file_path) : null,
                'uploaded_at'     => $doc->created_at->format('d M Y, h:i A'),
                'status'          => $doc->status,
                'admin_reason'    => $doc->admin_reason,
            ];
        });

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    // PUT /api/admin/documents/{id}/review  { status: 'Approved'|'Rejected', admin_reason?: string }
    public function review(Request $request, $id)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'status'       => 'required|in:Approved,Rejected',
            'admin_reason' => 'required_if:status,Rejected|nullable|string|max:255',
        ]);

        $doc = EmployeeDocument::with('user')->find($id);
        if (!$doc) {
            return response()->json(['message' => 'Document not found.'], 404);
        }
        $docUser = $doc->user;
        if (!$docUser) {
            return response()->json(['message' => 'Document owner not found.'], 404);
        }

        // Scope check — same permission boundaries as index()
        switch ($authUser->user_types_id) {
            case 1:
                break;

            case 2:
                $companyIds = DB::table('company_users')
                    ->where('users_id', $authUser->id)
                    ->pluck('company_id')->toArray();
                if (!in_array($docUser->company_id, $companyIds)) {
                    return response()->json(['message' => 'Permission denied.'], 403);
                }
                break;

            case 3:
                $branchId = $this->resolveBranchIdForBranchManager($authUser);
                // ✅ FIX: allow if docUser matches branch_id column OR the
                // branch_users pivot (was column-only before, which 403'd
                // pivot-assigned employees even though index() listed them)
                if (!$branchId || !in_array($docUser->id, $this->userIdsInBranch($branchId))) {
                    return response()->json(['message' => 'Permission denied.'], 403);
                }
                break;

            case 4:
                $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                // ✅ FIX: same pivot fallback as above, for department
                if (!$deptId || !in_array($docUser->id, $this->userIdsInDepartment($deptId))) {
                    return response()->json(['message' => 'Permission denied.'], 403);
                }
                break;

            default:
                return response()->json(['message' => 'Permission denied.'], 403);
        }

        $doc->update([
            'status'       => $request->status,
            'admin_reason' => $request->status === 'Rejected' ? $request->admin_reason : null,
        ]);

        // ✅ Notify the employee of the outcome.
        DB::table('notifications')->insert([
            'user_id'        => $doc->user_id,
            'type'           => 'document',
            'title'          => 'Document ' . $request->status,
            'message'        => $request->status === 'Approved'
                ? "Your \"{$doc->type}\" document was approved."
                : "Your \"{$doc->type}\" document was rejected: {$request->admin_reason}",
            'reference_type' => 'employee_document',
            'reference_id'   => $doc->id,
            'is_read'        => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Document ' . strtolower($request->status) . ' successfully.',
        ]);
    }
}