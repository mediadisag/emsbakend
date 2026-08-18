<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    // GET /api/admin/milestones?project_id=1
    public function index(Request $request)
    {
        $request->validate(['project_id' => ['required', 'exists:projects,id']]);

        $milestones = Milestone::where('project_id', $request->integer('project_id'))
            ->withCount('tickets')
            ->orderBy('name')
            ->get();

        return response()->json($milestones);
    }

    // POST /api/admin/milestones
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id'  => ['required', 'exists:projects,id'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $milestone = Milestone::create($data);

        return response()->json($milestone, 201);
    }

    // PUT /api/admin/milestones/{milestone}
    public function update(Request $request, Milestone $milestone)
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $milestone->update($data);

        return response()->json($milestone);
    }

    // DELETE /api/admin/milestones/{milestone}
    // Tickets pointing at this milestone fall back to milestone_id = null
    // (nullOnDelete on the FK) — they don't get deleted.
    public function destroy(Milestone $milestone)
    {
        $milestone->delete();
        return response()->json(null, 204);
    }
}
