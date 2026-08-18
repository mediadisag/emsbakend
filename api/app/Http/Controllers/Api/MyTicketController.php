<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Employee-facing ticket endpoints — same self-service pattern already
 * used elsewhere (MyAttendanceController, MyDocumentsController): every
 * query is scoped by auth()->user()->id, no user_id param accepted.
 *
 * This file ADDS to whatever TicketController (admin/manager side)
 * already exists in the project — it does not replace it.
 * myTickets() (the list endpoint dashboard.html's tile/list already
 * call) is assumed to already exist per the existing
 * GET /api/my-tickets wiring. The three methods below are new:
 * ticket detail + comment + attachment, called by the new
 * "Ticket Detail" modal in dashboard.html.
 *
 * ── ASSUMPTIONS — please check these against your real schema/models
 *    and adjust field/relationship names before wiring the routes: ──
 *  - tickets table has: id, title, description, status, priority,
 *    project_id, assigned_to, reported_by, due_date
 *  - Ticket model has an assignedTo relation via `assigned_to` and a
 *    `project` relation
 *  - ticket_comments table: id, ticket_id, user_id, comment, timestamps
 *  - ticket_attachments table: id, ticket_id, user_id, file_name,
 *    file_path, timestamps
 *  - Both TicketComment and TicketAttachment belongTo `user` (with a
 *    `username` column) and belongTo `ticket`
 *  - Uploaded files are stored on the `public` disk
 *
 * If ticket_comments / ticket_attachments tables don't exist yet, see
 * the migration stub at the bottom of this file.
 *
 * Routes to add (routes/api.php), inside the same auth:sanctum group as
 * the existing /my-tickets route:
 *
 *   Route::get('/my-tickets/{id}', [MyTicketController::class, 'show']);
 *   Route::post('/my-tickets/{id}/comments', [MyTicketController::class, 'storeComment']);
 *   Route::post('/my-tickets/{id}/attachments', [MyTicketController::class, 'storeAttachment']);
 */
class MyTicketController extends Controller
{
    /**
     * GET /api/my-tickets/{id}
     * Full ticket detail — description, comments, attachments — scoped
     * to tickets assigned to the logged-in employee.
     */
    public function show(Request $request, $id)
    {
        $employee = Auth::user();

        $ticket = Ticket::with([
                'comments' => fn ($q) => $q->with('user:id,username')->orderBy('created_at'),
                'attachments' => fn ($q) => $q->with('user:id,username')->orderBy('created_at'),
                'project:id,name',
            ])
            ->where('assigned_to', $employee->id)
            ->find($id);

        if (!$ticket) {
            return response()->json([
                'status'  => false,
                'message' => 'Ticket not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'ticket' => [
                'id'           => $ticket->id,
                'title'        => $ticket->title,
                'description'  => $ticket->description,
                'status'       => $ticket->status,
                'priority'     => $ticket->priority,
                'project_name' => optional($ticket->project)->name,
                'due_date'     => $ticket->due_date ? date('Y-m-d', strtotime($ticket->due_date)) : null,
                'comments'     => $ticket->comments->map(fn ($c) => [
                    'id'         => $c->id,
                    'comment'    => $c->comment,
                    'user_name'  => optional($c->user)->username,
                    'created_at' => $c->created_at->toDateTimeString(),
                ]),
                'attachments'  => $ticket->attachments->map(fn ($a) => [
                    'id'         => $a->id,
                    'file_name'  => $a->file_name,
                    'file_url'   => Storage::disk('public')->url($a->file_path),
                    'user_name'  => optional($a->user)->username,
                    'created_at' => $a->created_at->toDateTimeString(),
                ]),
            ],
        ]);
    }

    /**
     * POST /api/my-tickets/{id}/comments
     * Body: { comment?: string, due_date?: Y-m-d }
     * ✅ NEW — due_date is now optional here so the ticket detail view's
     * "Due Date" field can be changed alongside (or instead of) a comment.
     */
    public function storeComment(Request $request, $id)
    {
        $employee = Auth::user();

        $ticket = Ticket::where('assigned_to', $employee->id)->find($id);
        if (!$ticket) {
            return response()->json(['status' => false, 'message' => 'Ticket not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'comment'  => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        if (!$request->filled('comment') && !$request->filled('due_date')) {
            return response()->json(['status' => false, 'message' => 'Add a comment or change the due date first.'], 422);
        }

        $comment = null;
        if ($request->filled('comment')) {
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id'   => $employee->id,
                'comment'   => $request->input('comment'),
            ]);
        }

        if ($request->filled('due_date')) {
            $ticket->due_date = $request->input('due_date');
            $ticket->save();
        }

        return response()->json([
            'status'  => true,
            'message' => 'Ticket updated.',
            'comment' => $comment ? [
                'id'         => $comment->id,
                'comment'    => $comment->comment,
                'user_name'  => $employee->username,
                'created_at' => $comment->created_at->toDateTimeString(),
            ] : null,
            'due_date' => $ticket->due_date ? date('Y-m-d', strtotime($ticket->due_date)) : null,
        ]);
    }

    /**
     * POST /api/my-tickets/{id}/attachments
     * multipart/form-data field: file
     */
    public function storeAttachment(Request $request, $id)
    {
        $employee = Auth::user();

        $ticket = Ticket::where('assigned_to', $employee->id)->find($id);
        if (!$ticket) {
            return response()->json(['status' => false, 'message' => 'Ticket not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240', // 10MB, in KB for Laravel's max rule
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
        $path = $file->store('ticket-attachments/' . $ticket->id, 'public');

        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'user_id'   => $employee->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
        ]);

        return response()->json([
            'status'     => true,
            'message'    => 'Attachment uploaded.',
            'attachment' => [
                'id'         => $attachment->id,
                'file_name'  => $attachment->file_name,
                'file_url'   => Storage::disk('public')->url($path),
                'user_name'  => $employee->username,
                'created_at' => $attachment->created_at->toDateTimeString(),
            ],
        ]);
    }
}

/*
─────────────────────────────────────────────────────────────────────
MIGRATION STUB — only needed if ticket_comments / ticket_attachments
don't already exist (skip this if the admin ticket system already has
comment/attachment tables; just point the model names above at those).
─────────────────────────────────────────────────────────────────────

Schema::create('ticket_comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->text('comment');
    $table->timestamps();
});

Schema::create('ticket_attachments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('file_name');
    $table->string('file_path');
    $table->timestamps();
});

// On the Ticket model:
public function comments() { return $this->hasMany(TicketComment::class); }
public function attachments() { return $this->hasMany(TicketAttachment::class); }

// On TicketComment / TicketAttachment models:
public function user() { return $this->belongsTo(User::class); }
public function ticket() { return $this->belongsTo(Ticket::class); }
*/