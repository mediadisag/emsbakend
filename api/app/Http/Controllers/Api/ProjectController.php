<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    private const SUPER_ADMIN_TYPE_ID = 1;
    private const COMPANY_ADMIN_TYPE_ID = 2;
    private const BRANCH_ADMIN_TYPE_ID = 3;
    private const DEPT_ADMIN_TYPE_ID = 4;
    private const EMPLOYEE_TYPE_ID = 5;

    private const ADMIN_TYPE_IDS = [
        self::SUPER_ADMIN_TYPE_ID,
        self::COMPANY_ADMIN_TYPE_ID,
        self::BRANCH_ADMIN_TYPE_ID,
    ];

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/projects
    // Returns { data: [ { id, name, description, department_id,
    // department_name, tickets_count, is_deleted }, ... ] }
    //
    // - Normal calls (no ?manage=1): only ACTIVE projects, i.e. Eloquent's
    //   default SoftDeletes scope already excludes deleted_at IS NOT NULL
    //   rows. Used everywhere tickets get created / the project strip.
    // - ?manage=1: includes soft-deleted projects too (via withTrashed()),
    //   for the "Manage Projects" screen. Employees may never use this
    //   flag — 403 if they try.
    //
    // Visibility (department scoping) is unchanged from before:
    //   - department_id === null  → company-wide, visible to everyone
    //   - department_id set       → visible only to Admins (1/2/3) and
    //     to Dept Admins/Employees (4/5) in THAT department
    // ══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $authUser = $request->user();
        $manage = $request->boolean('manage');

        if ($manage && (int) $authUser->user_types_id === self::EMPLOYEE_TYPE_ID) {
            abort(403, 'Not permitted to manage projects.');
        }

        $query = Project::withCount(['tickets' => function ($q) use ($authUser) {
            $this->applyTicketDepartmentScope($q, $authUser);
        }])->with('department:id,department_name as name'); // ✅ FIX — department table has no `name` column, only `department_name`

        if ($manage) {
            $query->withTrashed(); // ✅ include soft-deleted rows for the management screen only
        }

        $this->applyProjectDepartmentScope($query, $authUser);

        $projects = $query->orderBy('name')->get()->map(fn (Project $p) => $this->transform($p));

        return response()->json(['data' => $projects]);
    }

    // ══════════════════════════════════════════════════════════
    // POST /api/admin/projects
    //
    // department_id handling depends on who's creating it:
    //   - Admin (1/2/3): may pick any department, or leave it blank for
    //     a company-wide project. Sent by the front end as
    //     `department_id` when the New Project modal shows the
    //     dropdown (Admins only).
    //   - Dept Admin (4) / Employee (5): department_id is always forced
    //     to their OWN department server-side — any value the client
    //     sends is ignored, so a department-scoped user can never tag a
    //     project to a different department.
    // ══════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        $authUser = $request->user();
        $isAdmin = in_array((int) $authUser->user_types_id, self::ADMIN_TYPE_IDS, true);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('projects', 'name')],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
        ]);

        if ($isAdmin) {
            $departmentId = $data['department_id'] ?? null;
        } else {
            // Dept Admin / Employee: always their own department, never
            // client-supplied.
            $departmentId = $this->resolveDeptId($authUser);
        }

        $project = Project::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $authUser->id,
            'department_id' => $departmentId,
        ]);

        return response()->json($this->transform($project->loadCount('tickets')->load('department:id,department_name as name')), 201); // ✅ FIX
    }

    // GET /api/admin/projects/{project}
    public function show(Project $project)
    {
        return response()->json($this->transform($project->loadCount('tickets')->load('department:id,department_name as name'))); // ✅ FIX
    }

    // ══════════════════════════════════════════════════════════
    // PUT /api/admin/projects/{project}
    // Edit name / description / department. department_id is only
    // honored for Admins (1/2/3) — same rule as store(). Dept Admins
    // can rename/re-describe a project in their own department but
    // can't move it to a different department.
    // ══════════════════════════════════════════════════════════
    public function update(Request $request, Project $project)
    {
        $authUser = $request->user();
        $this->authorizeManage($authUser, $project);

        $isAdmin = in_array((int) $authUser->user_types_id, self::ADMIN_TYPE_IDS, true);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191', Rule::unique('projects', 'name')->ignore($project->id)],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
        ]);

        if (array_key_exists('name', $data)) {
            $project->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $project->description = $data['description'];
        }
        if ($isAdmin && array_key_exists('department_id', $data)) {
            $project->department_id = $data['department_id'];
        }
        $project->save();

        return response()->json($this->transform($project->loadCount('tickets')->load('department:id,department_name as name'))); // ✅ FIX
    }

    // ══════════════════════════════════════════════════════════
    // DELETE /api/admin/projects/{project}
    // Soft delete only — Project uses the SoftDeletes trait, so this
    // stamps `deleted_at` and the row is excluded from normal queries.
    // The row itself, and every ticket that references it, is left
    // completely untouched.
    // ══════════════════════════════════════════════════════════
    public function destroy(Request $request, Project $project)
    {
        $this->authorizeManage($request->user(), $project);
        $project->delete(); // soft delete (SoftDeletes trait)
        return response()->json(['message' => 'Project deleted.']);
    }

    // ══════════════════════════════════════════════════════════
    // PATCH /api/admin/projects/{project}/restore
    // Clears deleted_at. Must resolve the model WITH trashed rows
    // manually — implicit route-model-binding on {project} excludes
    // soft-deleted rows by default, so a plain type-hinted $project
    // param would 404 here.
    // ══════════════════════════════════════════════════════════
    public function restore(Request $request, $projectId)
    {
        $project = Project::withTrashed()->findOrFail($projectId);
        $this->authorizeManage($request->user(), $project);
        $project->restore();

        return response()->json($this->transform($project->loadCount('tickets')->load('department:id,department_name as name'))); // ✅ FIX
    }

    // ── Helpers ──────────────────────────────────────────────────

    // same department resolution order used across the app:
    // 1) users.department_id column, 2) department_users pivot table.
    private function resolveDeptId($authUser): ?int
    {
        if (!empty($authUser->department_id)) {
            return (int) $authUser->department_id;
        }

        $deptId = DB::table('department_users')
            ->where('users_id', $authUser->id)
            ->value('department_id');

        return $deptId ? (int) $deptId : null;
    }

    // Who's allowed to edit / delete / restore a given project:
    //   - Admin (1/2/3): any project.
    //   - Dept Admin (4): only projects in their own department.
    //   - Employee (5): never.
    private function authorizeManage($authUser, Project $project): void
    {
        if (in_array((int) $authUser->user_types_id, self::ADMIN_TYPE_IDS, true)) {
            return;
        }

        if ((int) $authUser->user_types_id === self::EMPLOYEE_TYPE_ID) {
            abort(403, 'Not permitted to manage projects.');
        }

        $deptId = $this->resolveDeptId($authUser);
        if (!$deptId || (int) $project->department_id !== $deptId) {
            abort(403, 'You can only manage projects in your own department.');
        }
    }

    // keys off projects.department_id directly instead of inferring
    // from the creator. Falls back to the OLD creator-based rule only
    // for legacy rows where department_id is still null AND the
    // creator is a Dept Admin/Employee, so projects created before
    // this migration don't suddenly disappear.
    private function applyProjectDepartmentScope(Builder $query, $authUser): void
    {
        if (in_array((int) $authUser->user_types_id, self::ADMIN_TYPE_IDS, true)) {
            return; // Super/Company/Branch Admin — see every project
        }

        $deptId = $this->resolveDeptId($authUser);

        if (!$deptId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $q) use ($deptId) {
            // Explicitly tagged to this department.
            $q->where('projects.department_id', $deptId)
                // Company-wide project (no department set at all).
                ->orWhereNull('projects.department_id')
                // Legacy fallback: no department_id column value yet,
                // but created by someone in this department.
                ->orWhereIn('projects.created_by', function ($sub) use ($deptId) {
                    $sub->select('id')->from('users')->where('department_id', $deptId);
                });
        });
    }

    // restricts the `tickets` used for withCount('tickets') the same
    // way TicketController::applyDepartmentScope restricts the ticket
    // LIST, so the number shown on each project card matches what a
    // department-scoped user would actually see if they opened that
    // project's tickets.
    private function applyTicketDepartmentScope(Builder $query, $authUser): void
    {
        if (in_array((int) $authUser->user_types_id, self::ADMIN_TYPE_IDS, true)) {
            return;
        }

        $deptId = $this->resolveDeptId($authUser);

        if (!$deptId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('assignedUser', function (Builder $q) use ($deptId) {
            $q->where('department_id', $deptId);
        });
    }

    private function transform(Project $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'department_id' => $p->department_id,
            'department_name' => $p->department->name ?? null,
            'tickets_count' => $p->tickets_count ?? 0,
            'is_deleted' => $p->deleted_at !== null, // ✅ derived straight from deleted_at
        ];
    }
}