<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TicketAssignedMail;
use App\Mail\TicketReplyMail;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    private const STATUSES = [
        'New', 'Accepted', 'Test', 'OnHold',
        'Ready for Rollout', 'Waiting Information', 'Invalid', 'Fixed',
    ];

    private const PRIORITIES = ['Highest', 'High', 'Normal', 'Low', 'Lowest'];

    private const DEPT_ADMIN_TYPE_ID = 4;
    private const EMPLOYEE_TYPE_ID = 5;

    // roles allowed to edit/delete content they didn't create themselves
    // (used by updateComment(), destroyComment() and deleteAttachment() below)
    private const PRIVILEGED_TYPE_IDS = [1, 2, 3]; // Super/Company/Branch Admin

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/tickets
    // Supports: status, priority, assigned_to ("unassigned" or a user
    // id), project_id, milestone_id, search, view. Returns
    // { tickets: [...], counts: {...} }.
    // `counts` is computed off every filter EXCEPT status (view IS
    // included), so the pill numbers stay meaningful (and summable)
    // while you flip status tabs within the current Active/Fixed view.
    //
    // `view` (active|fixed): the front end defaults to view=active,
    // which excludes 'Fixed' tickets from the list and from the pill
    // counts entirely. Switching the toggle to Fixed shows only 'Fixed'
    // tickets. Any other/omitted value is treated as "no extra
    // restriction" (kept for forward compatibility, e.g. a future "All"
    // option).
    //
    // ticket VISIBILITY is department-scoped for Dept Admins and
    // Employees (user_types_id 4 or 5): they only see tickets whose
    // ASSIGNEE belongs to their own department. Super Admin (and, for
    // now, Company/Branch Admin) still see everything. This is separate
    // from — and in addition to — the WHO-can-a-ticket-be-ASSIGNED-TO
    // restriction in canAssignTicketTo() below; that one guards writes,
    // this one guards reads. See applyDepartmentScope() for the actual
    // filter.
    // ══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $base = Ticket::query();
        $this->applyCommonFilters($base, $request);
        $this->applyDepartmentScope($base, $request->user());

        $counts = [
            'All'      => (clone $base)->count(),
            'New'      => (clone $base)->where('status', 'New')->count(),
            'Accepted' => (clone $base)->where('status', 'Accepted')->count(),
            'Test'     => (clone $base)->where('status', 'Test')->count(),
            'Fixed'    => (clone $base)->where('status', 'Fixed')->count(),
            'OnHold'   => (clone $base)->where('status', 'OnHold')->count(),
        ];

        if ($request->filled('status')) {
            $base->where('status', $request->string('status'));
        }

        $tickets = $base->with(['project', 'creator', 'assignedUser', 'milestoneRelation'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Ticket $t) => $this->transformListItem($t))
            ->values();

        return response()->json(['tickets' => $tickets, 'counts' => $counts]);
    }

    // POST /api/admin/tickets
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id'      => ['required', 'exists:projects,id'],
            'heading'         => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'priority'        => ['nullable', 'in:Highest,High,Normal,Low,Lowest'],
            'permission_type' => ['nullable', 'in:Private,Public'],
            'milestone'       => ['nullable', 'string', 'max:255'],
            'milestone_id'    => ['nullable', 'exists:milestones,id'],
            'estimated_time'  => ['nullable', 'string', 'max:50'],
            'assigned_to'     => ['nullable', 'exists:users,id'],
            'responsible_2'   => ['nullable', 'exists:users,id'],
            'due_date'        => ['nullable', 'date'],
        ]);

        $authUser = $request->user();

        // a plain Employee (user_types_id 5) always raises tickets
        // assigned to themselves. The front end pre-selects and locks
        // the "Assigned To" field to their own id, but that's just UI —
        // enforce it here too so a crafted request can't assign a
        // self-raised ticket to someone else. Missing/blank assigned_to
        // from an Employee is defaulted to self as well.
        if ((int) $authUser->user_types_id === self::EMPLOYEE_TYPE_ID) {
            $data['assigned_to'] = $authUser->id;
        }

        // server-side enforcement of department scoping. The front
        // end's "Assigned To" dropdown already only *offers* employees
        // from the Dept Admin's own department, but that's just UI —
        // without this check, a Dept Admin could still POST any user id
        // directly (devtools/Postman/etc.) and assign a ticket outside
        // their department. Mirrors the same scoping
        // UserController::listUsersByAccessAndDepartmentOrDevice
        // already applies when *listing* users.
        if (!empty($data['assigned_to']) && !$this->canAssignTicketTo($authUser, (int) $data['assigned_to'])) {
            return response()->json([
                'message' => 'You can only assign tickets to employees in your own department.',
            ], 403);
        }

        // mirrors the assigned_to guard above: when an Employee raises a
        // ticket, Responsible 2 may only be a Dept Admin ("department
        // head") within the employee's own department — matching what
        // the front end's Responsible 2 dropdown now offers. Other
        // roles (Dept Admin and above) are unrestricted here, same as
        // before.
        if (!empty($data['responsible_2']) && !$this->canSetResponsible2($authUser, (int) $data['responsible_2'])) {
            return response()->json([
                'message' => 'Responsible 2 must be a department head in your own department.',
            ], 403);
        }

        $ticket = Ticket::create([
            'project_id'      => $data['project_id'],
            'heading'         => $data['heading'],
            'description'     => $data['description'] ?? null,
            'priority'        => $data['priority'] ?? 'Normal',
            'permission_type' => $data['permission_type'] ?? 'Private',
            'milestone'       => $data['milestone'] ?? null,
            'milestone_id'    => $data['milestone_id'] ?? null,
            'estimated_time'  => $data['estimated_time'] ?? null,
            'assigned_to'     => $data['assigned_to'] ?? null,
            'responsible_2'   => $data['responsible_2'] ?? null,
            'due_date'        => $data['due_date'] ?? null,
            'status'          => 'New',
            'created_by'      => $authUser->id,
        ]);

        // mail the assignee, if the ticket was created already assigned
        // to someone. Silently skipped if mail fails so ticket creation
        // never breaks because of an SMTP hiccup.
        if ($ticket->assigned_to) {
            $this->sendAssignedMail($ticket);
        }

        // { id } is enough — the front end immediately calls
        // openTicketModal(created.id) to fetch the full detail.
        return response()->json(['id' => $ticket->code], 201);
    }

    // GET /api/admin/tickets/{ticket}
    public function show(Ticket $ticket)
    {
        return response()->json($this->transformDetail($ticket));
    }

    // PUT /api/admin/tickets/{ticket} — full-field edit (not wired up in
    // the current front end yet, but kept symmetrical with the other
    // admin resources and ready for an "Edit ticket" form later).
    public function update(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'project_id'      => ['sometimes', 'exists:projects,id'],
            'heading'         => ['sometimes', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'status'          => ['sometimes', 'in:' . implode(',', self::STATUSES)],
            'priority'        => ['sometimes', 'in:' . implode(',', self::PRIORITIES)],
            'permission_type' => ['sometimes', 'in:Private,Public'],
            'milestone'       => ['nullable', 'string', 'max:255'],
            'milestone_id'    => ['nullable', 'exists:milestones,id'],
            'estimated_time'  => ['nullable', 'string', 'max:50'],
            'assigned_to'     => ['nullable', 'exists:users,id'],
            'responsible_2'   => ['nullable', 'exists:users,id'],
            'due_date'        => ['nullable', 'date'],
        ]);

        // same server-side department check as store(); this is the
        // general-purpose "Edit ticket" endpoint, so it needs the same
        // guard even though the current front end doesn't call it for
        // reassignment yet (it uses addComment() instead).
        if (array_key_exists('assigned_to', $data) && !empty($data['assigned_to'])
            && !$this->canAssignTicketTo($request->user(), (int) $data['assigned_to'])) {
            return response()->json([
                'message' => 'You can only assign tickets to employees in your own department.',
            ], 403);
        }

        if (array_key_exists('responsible_2', $data) && !empty($data['responsible_2'])
            && !$this->canSetResponsible2($request->user(), (int) $data['responsible_2'])) {
            return response()->json([
                'message' => 'Responsible 2 must be a department head in your own department.',
            ], 403);
        }

        // mail the assignee on reassignment through this endpoint too,
        // same as addComment()'s reassignment path below.
        $wasAssignedTo = $ticket->assigned_to;

        $ticket->update($data);

        if (array_key_exists('assigned_to', $data) && !empty($data['assigned_to'])
            && (int) $data['assigned_to'] !== (int) $wasAssignedTo) {
            $this->sendAssignedMail($ticket->fresh());
        }

        return response()->json($this->transformDetail($ticket));
    }

    // DELETE /api/admin/tickets/{ticket}
    // Deleting a ticket also cleans up its uploaded files from disk
    // (previously only the DB rows were cascade-deleted, leaving
    // orphaned files in storage/app/public/tickets/{id}).
    public function destroy(Ticket $ticket)
    {
        foreach ($ticket->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->path);
        }

        $ticket->delete(); // cascades to comments/activities/attachments rows
        return response()->json(null, 204);
    }

    // ══════════════════════════════════════════════════════════
    // POST /api/admin/tickets/{ticket}/comments   (multipart/form-data)
    // Bundles a message, an optional file, and any of
    // status/priority/assigned_to/responsible_2/total_worked_hrs into
    // ONE comment + a set of TicketActivity rows, so the front end can
    // render them as a single grouped timeline entry.
    //
    // `responsible_2` joins status/priority/assigned_to as an editable
    // field here, following the exact same
    // detect-change → log activity → save pattern.
    // ══════════════════════════════════════════════════════════
    public function addComment(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'message'          => ['nullable', 'string'],
            'file'             => ['nullable', 'file', 'max:10240'], // 10 MB
            'status'           => ['nullable', 'in:' . implode(',', self::STATUSES)],
            'priority'         => ['nullable', 'in:' . implode(',', self::PRIORITIES)],
            'assigned_to'      => ['nullable', 'exists:users,id'],
            'responsible_2'    => ['nullable', 'exists:users,id'], // NEW
            'total_worked_hrs' => ['nullable', 'numeric', 'min:0'],
        ]);

        $hasMessage = filled($data['message'] ?? null);
        $hasFile = $request->hasFile('file');
        $statusChanged = filled($data['status'] ?? null) && $data['status'] !== $ticket->status;
        $priorityChanged = filled($data['priority'] ?? null) && $data['priority'] !== $ticket->priority;
        $assignChanged = filled($data['assigned_to'] ?? null) && (int) $data['assigned_to'] !== (int) $ticket->assigned_to;
        // NEW
        $responsible2Changed = filled($data['responsible_2'] ?? null) && (int) $data['responsible_2'] !== (int) $ticket->responsible_2;
        $hasHours = array_key_exists('total_worked_hrs', $data) && $data['total_worked_hrs'] !== null;

        if (!$hasMessage && !$hasFile && !$statusChanged && !$priorityChanged && !$assignChanged && !$responsible2Changed && !$hasHours) {
            return response()->json(['message' => 'Add a comment, a file, or change a field first.'], 422);
        }

        // reassignment via the "Reassign…" dropdown in the ticket detail
        // modal goes through THIS endpoint, not store()/update(). Without
        // this check, a Dept Admin could reassign any ticket to any user
        // outside their own department despite the dropdown only listing
        // their own department's employees client-side.
        if ($assignChanged && !$this->canAssignTicketTo($request->user(), (int) $data['assigned_to'])) {
            return response()->json([
                'message' => 'You can only assign tickets to employees in your own department.',
            ], 403);
        }

        // NEW — same guard as store()/update() for changing Responsible 2
        // through the "Change Responsible 2…" dropdown.
        if ($responsible2Changed && !$this->canSetResponsible2($request->user(), (int) $data['responsible_2'])) {
            return response()->json([
                'message' => 'Responsible 2 must be a department head in your own department.',
            ], 403);
        }

        $comment = DB::transaction(function () use ($request, $ticket, $data, $hasMessage, $hasFile, $statusChanged, $priorityChanged, $assignChanged, $responsible2Changed, $hasHours) {
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id'   => $request->user()->id,
                'message'   => $hasMessage ? $data['message'] : null,
            ]);

            if ($statusChanged) {
                TicketActivity::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'field'             => 'status',
                    'label'             => 'Status',
                    'old_value'         => $ticket->status,
                    'new_value'         => $data['status'],
                ]);
                $ticket->status = $data['status'];
            }

            if ($priorityChanged) {
                TicketActivity::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'field'             => 'priority',
                    'label'             => 'Priority',
                    'old_value'         => $ticket->priority,
                    'new_value'         => $data['priority'],
                ]);
                $ticket->priority = $data['priority'];
            }

            if ($assignChanged) {
                // NOTE: `users` table has no `name` column — the display
                // name lives in `username`. Using ->name here silently
                // returned null, so "Assigned to changed from — to —"
                // showed blank on both sides in the activity log.
                $oldName = optional($ticket->assignedUser)->username;
                $newUser = User::find($data['assigned_to']);
                TicketActivity::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'field'             => 'assigned_to',
                    'label'             => 'Assigned to',
                    'old_value'         => $oldName,
                    'new_value'         => optional($newUser)->username,
                ]);
                $ticket->assigned_to = $data['assigned_to'];
            }

            // NEW — Responsible 2 change, logged the same way as
            // assigned_to.
            if ($responsible2Changed) {
                $oldName = optional($ticket->responsible2User)->username;
                $newUser = User::find($data['responsible_2']);
                TicketActivity::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'field'             => 'responsible_2',
                    'label'             => 'Responsible 2',
                    'old_value'         => $oldName,
                    'new_value'         => optional($newUser)->username,
                ]);
                $ticket->responsible_2 = $data['responsible_2'];
            }

            if ($hasHours) {
                $old = (float) $ticket->total_worked_hrs;
                $new = $old + (float) $data['total_worked_hrs']; // hours are LOGGED (added), not overwritten
                TicketActivity::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'field'             => 'total_worked_hrs',
                    'label'             => 'Hours logged',
                    'old_value'         => (string) $old,
                    'new_value'         => (string) $new,
                ]);
                $ticket->total_worked_hrs = $new;
            }

            if ($statusChanged || $priorityChanged || $assignChanged || $responsible2Changed || $hasHours) {
                $ticket->save();
            }

            if ($hasFile) {
                $file = $request->file('file');
                $path = $file->store("tickets/{$ticket->id}", 'public');
                TicketAttachment::create([
                    'ticket_id'         => $ticket->id,
                    'ticket_comment_id' => $comment->id,
                    'uploaded_by'       => $request->user()->id,
                    'original_name'     => $file->getClientOriginalName(),
                    'path'              => $path,
                    'size'              => $file->getSize(),
                ]);
            }

            return $comment;
        });

        // email notifications. Reassignment mail goes to the new
        // assignee only. A reply (message and/or file, no matter the
        // other changes) mails the ticket creator and the current
        // assignee — but never the person who just posted the comment,
        // so you don't get a mail for your own reply.
        if ($assignChanged) {
            $this->sendAssignedMail($ticket->fresh());
        }

        if ($hasMessage || $hasFile) {
            $this->sendReplyMail($ticket->fresh(), $comment, $request->user()->id);
        }

        return response()->json(['id' => $comment->id], 201);
    }

    // ══════════════════════════════════════════════════════════
    // PUT /api/admin/tickets/{ticket}/comments/{comment}
    // Lets the comment author (or a Super/Company/Branch Admin) edit
    // the message/caption text of an existing comment. Does NOT touch
    // activities or attachments on the comment — those are handled
    // separately (see deleteAttachment() below). Route param name must
    // be {comment} to match Laravel's implicit model binding for the
    // second argument.
    // ══════════════════════════════════════════════════════════
    public function updateComment(Request $request, Ticket $ticket, TicketComment $comment)
    {
        if ((int) $comment->ticket_id !== (int) $ticket->id) {
            abort(404);
        }

        $authUser = $request->user();
        $isOwner = (int) $comment->user_id === (int) $authUser->id;
        $isPrivileged = in_array((int) $authUser->user_types_id, self::PRIVILEGED_TYPE_IDS, true);

        if (!$isOwner && !$isPrivileged) {
            return response()->json(['message' => 'You can only edit your own comments.'], 403);
        }

        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $comment->update(['message' => $data['message']]);

        return response()->json([
            'id'      => $comment->id,
            'message' => $comment->message,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // DELETE /api/admin/tickets/{ticket}/comments/{comment}
    // NEW — lets the comment author (or a Super/Company/Branch Admin)
    // delete a whole comment: its message, any activity log rows tied
    // to it (status/priority/assignee/responsible2/hours changes
    // recorded alongside it), and its attachment (both the DB row and
    // the file on disk). Same permission rule as updateComment().
    // ══════════════════════════════════════════════════════════
    public function destroyComment(Request $request, Ticket $ticket, TicketComment $comment)
    {
        if ((int) $comment->ticket_id !== (int) $ticket->id) {
            abort(404);
        }

        $authUser = $request->user();
        $isPrivileged = in_array((int) $authUser->user_types_id, self::PRIVILEGED_TYPE_IDS, true);

        // Delete is Admin/SuperAdmin-only now (Dept Admins and
        // Employees can never delete a comment, even their own —
        // matches the front end hiding the delete icon for them).
        if (!$isPrivileged) {
            return response()->json(['message' => 'Only an admin can delete comments.'], 403);
        }

        DB::transaction(function () use ($comment) {
            if ($comment->attachment) {
                Storage::disk('public')->delete($comment->attachment->path);
                $comment->attachment->delete();
            }
            $comment->activities()->delete();
            $comment->delete();
        });

        return response()->json(null, 204);
    }

    // ══════════════════════════════════════════════════════════
    // DELETE /api/admin/tickets/{ticket}/attachments/{attachment}
    // Covers both standalone ticket attachments and attachments that
    // were uploaded as part of a comment (including voice notes, which
    // are just a regular `file` upload under the hood — see
    // addComment() above). Only the uploader or a Super/Company/Branch
    // Admin may delete. Removes the file from disk as well as the DB
    // row.
    // ══════════════════════════════════════════════════════════
    public function deleteAttachment(Request $request, Ticket $ticket, TicketAttachment $attachment)
    {
        if ((int) $attachment->ticket_id !== (int) $ticket->id) {
            abort(404);
        }

        $authUser = $request->user();
        $isOwner = (int) $attachment->uploaded_by === (int) $authUser->id;
        $isPrivileged = in_array((int) $authUser->user_types_id, self::PRIVILEGED_TYPE_IDS, true);

        if (!$isOwner && !$isPrivileged) {
            return response()->json(['message' => 'You can only delete attachments you uploaded.'], 403);
        }

        Storage::disk('public')->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    // ══════════════════════════════════════════════════════════
    // POST /api/admin/tickets/{ticket}/attachments
    // Standalone attachment upload, not tied to a specific comment —
    // kept for parity with the route already registered; the current
    // UI only uploads files through addComment() above (this is also
    // where a recorded voice note lands, since the front end just sends
    // it as a regular `file` on the comment — no dedicated voice-note
    // endpoint needed; see the Voice Notes feasibility notes returned in
    // chat).
    // ══════════════════════════════════════════════════════════
    public function uploadAttachment(Request $request, Ticket $ticket)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store("tickets/{$ticket->id}", 'public');

        $attachment = TicketAttachment::create([
            'ticket_id'     => $ticket->id,
            'uploaded_by'   => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size'          => $file->getSize(),
        ]);

        return response()->json([
            'id'   => $attachment->id,
            'name' => $attachment->original_name,
            'url'  => $attachment->url,
            'size' => $attachment->size,
        ], 201);
    }

    // GET /api/my-tickets — tickets assigned to the logged-in employee
    public function myTickets(Request $request)
    {
        $tickets = Ticket::with(['project', 'creator', 'assignedUser', 'milestoneRelation'])
            ->where('assigned_to', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Ticket $t) => $this->transformListItem($t))
            ->values();

        return response()->json(['tickets' => $tickets]);
    }

 
    public function activityReport(Request $request)
    {
        $request->validate([
            'from_date' => ['required', 'date'],
            'to_date'   => ['required', 'date'],
            'user_id'   => ['nullable', 'exists:users,id'],
        ]);

        $from = $request->date('from_date')->startOfDay();
        $to   = $request->date('to_date')->endOfDay();

        $commentsQuery = TicketComment::query()
            ->with(['user', 'ticket', 'activities'])
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('ticket', function (Builder $q) use ($request) {
                $this->applyDepartmentScope($q, $request->user());
            });

        if ($request->filled('user_id')) {
            $commentsQuery->where('user_id', $request->integer('user_id'));
        }

        $comments = $commentsQuery->orderByDesc('created_at')->get();

        $typeForField = [
            'status'           => 'status',
            'priority'         => 'priority',
            'assigned_to'      => 'assign',
            'responsible_2'    => 'responsible2',
            'total_worked_hrs' => 'hours',
        ];

        $rows = collect();

        foreach ($comments as $comment) {
            $ticket = $comment->ticket;
            if (!$ticket) {
                continue;
            }

            $userName = optional($comment->user)->username;
            $time = $comment->created_at->format('d M Y, h:i A');

            if (filled($comment->message)) {
                // decode entities (e.g. &nbsp;) before stripping tags, so a
                // comment that's only whitespace-in-disguise is correctly
                // detected as empty rather than leaving stray "&nbsp;" text.
                $plainMessage = trim(html_entity_decode(strip_tags($comment->message), ENT_QUOTES));
                $hasImage = (bool) preg_match('/<img\b/i', $comment->message);

                if ($plainMessage !== '') {
                    $summary = Str::limit($plainMessage, 160);
                } elseif ($hasImage) {
                    // pasted directly into the rich-text box (not a file
                    // attachment) — strip_tags removes the <img> entirely,
                    // so without this the comment silently disappears from
                    // the report even though the user did post something.
                    $summary = '📷 Sent an image';
                } else {
                    $summary = null;
                }

                $rows->push([
                    'user'         => $userName,
                    'type'         => 'comment',
                    'ticket_id'    => $ticket->code,
                    'ticket_title' => $ticket->heading,
                    'summary'      => $summary,
                    'old_value'    => null,
                    'new_value'    => null,
                    'time'         => $time,
                    'sort'         => $comment->created_at,
                ]);
            }

            if ($comment->attachment) {
                $rows->push([
                    'user'         => $userName,
                    'type'         => 'attachment',
                    'ticket_id'    => $ticket->code,
                    'ticket_title' => $ticket->heading,
                    'summary'      => 'Attached ' . $comment->attachment->original_name,
                    'old_value'    => null,
                    'new_value'    => null,
                    'time'         => $time,
                    'sort'         => $comment->created_at,
                ]);
            }

            foreach ($comment->activities as $activity) {
                $rows->push([
                    'user'         => $userName,
                    'type'         => $typeForField[$activity->field] ?? 'other',
                    'ticket_id'    => $ticket->code,
                    'ticket_title' => $ticket->heading,
                    'summary'      => null,
                    'old_value'    => $activity->old_value,
                    'new_value'    => $activity->new_value,
                    'time'         => $time,
                    'sort'         => $comment->created_at,
                ]);
            }
        }

        $rows = $rows->sortByDesc('sort')->map(function ($r) {
            unset($r['sort']);
            return $r;
        })->values();

        return response()->json(['rows' => $rows]);
    }


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

    // the single source of truth for "is $authUser allowed to assign a
    // ticket to user #$assignedToId". Called from store(), update(), and
    // addComment() (reassignment) so all three paths stay consistent,
    // even as new callers are added later.
    //
    // - Dept Admins (4) AND Employees (5) may assign within their own
    //   department (anyone in it, not just themselves) — matches the
    //   department-scoped "Assigned To"/reassign dropdown, which for an
    //   Employee now lists every user in their own department (see
    //   UserController::listUsersByAccessAndDepartmentOrDevice). Ticket
    //   *creation* still defaults an Employee's own ticket to themselves
    //   (see the ✅ NEW block in store()) — that's a creation-time
    //   default, separate from this reassignment check.
    // - Every other role (Super Admin 1, Company Admin 2, Branch Admin
    //   3) may assign to anyone.
    private function canAssignTicketTo($authUser, int $assignedToId): bool
    {
        $typeId = (int) $authUser->user_types_id;

        if ($typeId !== self::DEPT_ADMIN_TYPE_ID && $typeId !== self::EMPLOYEE_TYPE_ID) {
            return true;
        }

        $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
        if (!$deptId) {
            // No resolvable department — fail closed rather than
            // silently allowing a cross-department assignment.
            return false;
        }

        $assignedDeptId = DB::table('users')->where('id', $assignedToId)->value('department_id');

        return (int) $assignedDeptId === $deptId;
    }

    // "is $authUser allowed to set user #$responsibleId as Responsible
    // 2". Only Employees are restricted: Responsible 2 must be a Dept
    // Admin ("department head") in the employee's own department,
    // matching what the front end offers in that dropdown. Every other
    // role is unrestricted, same as the previous (unscoped) behaviour.
    private function canSetResponsible2($authUser, int $responsibleId): bool
    {
        if ((int) $authUser->user_types_id !== self::EMPLOYEE_TYPE_ID) {
            return true;
        }

        $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
        if (!$deptId) {
            return false;
        }

        $candidate = DB::table('users')->where('id', $responsibleId)->first(['user_types_id', 'department_id']);

        return $candidate
            && (int) $candidate->user_types_id === self::DEPT_ADMIN_TYPE_ID
            && (int) $candidate->department_id === $deptId;
    }

    // restricts which TICKETS are visible on GET /api/admin/tickets.
    // Only Dept Admins (4) and Employees (5) are scoped: they only see
    // tickets whose ASSIGNED EMPLOYEE belongs to their own department.
    // Super Admin (1) always sees everything. Company/Branch Admin (2/3)
    // are intentionally left unscoped for now — extend this the same way
    // (company_id / branch_id) if that's needed later.
    //
    // NOTE: a ticket with no assignee (assigned_to is null) has no
    // assignedUser to match against, so it will NOT show up for a
    // department-scoped viewer. If unassigned tickets should still be
    // visible to everyone regardless of department, add an
    // `->orWhereNull('assigned_to')` branch below.
    private function applyDepartmentScope(Builder $query, $authUser): void
    {
        if (!in_array((int) $authUser->user_types_id, [4, 5], true)) {
            return; // Super/Company/Branch Admin — no restriction
        }

        $deptId = $this->resolveDeptIdForDeptAdmin($authUser);

        if (!$deptId) {
            // Can't resolve a department for this user — fail closed
            // (show nothing) rather than accidentally exposing every
            // ticket company-wide.
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereHas('assignedUser', function (Builder $q) use ($deptId) {
            $q->where('department_id', $deptId);
        });
    }

    private function applyCommonFilters(Builder $query, Request $request): void
    {
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('milestone_id')) {
            $query->where('milestone_id', $request->integer('milestone_id'));
        }

        if ($request->filled('assigned_to')) {
            if ($request->string('assigned_to') === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->integer('assigned_to'));
            }
        }

        // Active/Fixed master toggle. 'active' (the front end's
        // default) excludes Fixed tickets; 'fixed' shows only Fixed
        // tickets. Any other value (missing, 'all', etc.) applies no
        // extra restriction, so this stays backwards compatible with
        // any other caller of this endpoint (e.g. a future report).
        if ($request->filled('view')) {
            // NOTE: $request->string('view') returns a Stringable
            // object, not a plain string — comparing it to 'active'/
            // 'fixed' with === was always false (object !== string),
            // so this filter silently did nothing. Use ->query()/
            // ->input() to get the raw string value instead.
            $view = (string) $request->query('view');
            if ($view === 'active') {
                $query->where('status', '!=', 'Fixed');
            } elseif ($view === 'fixed') {
                $query->where('status', 'Fixed');
            }
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                // NOTE: `users` table column is `username`, not `name` —
                // searching creator.name here would silently match
                // nothing and just fall back to the heading search.
                $q->where('heading', 'like', "%{$search}%")
                    ->orWhereHas('creator', fn (Builder $q2) => $q2->where('username', 'like', "%{$search}%"));
            });
        }
    }

    // mail the assignee that a ticket was assigned to them. Wrapped in
    // try/catch so a broken mail server never blocks the actual ticket
    // create/reassign/comment flow.
    private function sendAssignedMail(Ticket $ticket): void
    {
        if (!$ticket->assigned_to) {
            return;
        }

        $assignee = $ticket->assignedUser ?? User::find($ticket->assigned_to);

        if (!$assignee || !$assignee->email) {
            return;
        }

        try {
            Mail::to($assignee->email)->send(new TicketAssignedMail($ticket->load('project', 'assignedUser')));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // mail the ticket creator + current assignee about a new reply,
    // skipping whoever just posted it so you don't get mailed your own
    // comment. Sends to up to two distinct addresses.
    private function sendReplyMail(Ticket $ticket, TicketComment $comment, int $commenterId): void
    {
        $recipients = collect([$ticket->creator, $ticket->assignedUser])
            ->filter()
            ->reject(fn (User $u) => (int) $u->id === $commenterId)
            ->unique('id')
            ->pluck('email')
            ->filter()
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients->all())->send(new TicketReplyMail($ticket->load('project'), $comment->load('user')));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function transformListItem(Ticket $t): array
    {
        return [
            'id'           => $t->code,
            'title'        => $t->heading,
            'reported_by'  => optional($t->creator)->username,
            'project_id'   => $t->project_id,
            'project_name' => optional($t->project)->name,
            'status'       => $t->status,
            'priority'     => $t->priority,
            'assigned_to'  => optional($t->assignedUser)->username,
            'due_date'     => optional($t->due_date)->toDateString(),
            // falls back to the legacy free-text `milestone` column when
            // there's no milestone_id — old tickets keep showing fine.
            'milestone'    => optional($t->milestoneRelation)->name ?? $t->milestone,
            'milestone_id' => $t->milestone_id,
        ];
    }

    private function transformDetail(Ticket $t): array
    {
        $t->load([
            'project', 'creator', 'assignedUser', 'responsible2User', 'milestoneRelation',
            'comments.user', 'comments.activities', 'comments.attachment',
            'attachments',
        ]);

        $comments = $t->comments->map(function (TicketComment $c) {
            return [
                'id'      => $c->id, // needed by the front end to edit/delete this comment
                'user'    => optional($c->user)->username,
                'user_id' => $c->user_id, // front end uses this to show/hide the Edit/Delete icons
                'time'    => $c->created_at->format('d M Y, h:i A'),
                'message' => $c->message,
                'activities' => $c->activities->map(fn (TicketActivity $a) => [
                    'label'     => $a->label,
                    'old_value' => $a->old_value,
                    'new_value' => $a->new_value,
                ])->values(),
                'attachment' => $c->attachment ? [
                    'id'   => $c->attachment->id, // needed to delete this attachment
                    'name' => $c->attachment->original_name,
                    'url'  => $c->attachment->url,
                    'size' => $c->attachment->size,
                ] : null,
            ];
        })->values();

        $attachments = $t->attachments->map(fn (TicketAttachment $a) => [
            'id'   => $a->id, // needed to delete this attachment
            'name' => $a->original_name,
            'url'  => $a->url,
            'size' => $a->size,
        ])->values();

        return [
            'id'               => $t->code,
            'title'            => $t->heading,
            // Split on blank lines into paragraphs for the front end's
            // description.map(p => `<p>...`) rendering.
            'description'      => $t->description ? preg_split('/\n\s*\n/', trim($t->description)) : [],
            'created_on'       => $t->created_at->format('d M Y'),
            'reported_by'      => optional($t->creator)->username,
            'status'           => $t->status,
            'priority'         => $t->priority,
            'assigned_to'      => optional($t->assignedUser)->username,
            'assigned_to_id'   => $t->assigned_to,
            'project_id'       => $t->project_id,
            'project_name'     => optional($t->project)->name,
            'milestone'        => optional($t->milestoneRelation)->name ?? $t->milestone,
            'milestone_id'     => $t->milestone_id,
            'permission_type'  => $t->permission_type,
            'due_date'         => optional($t->due_date)->toDateString(),
            'estimated_time'   => $t->estimated_time,
            'responsible_2'    => optional($t->responsible2User)->username,
            'responsible_2_id' => $t->responsible_2, // needed by the Responsible 2 dropdown
            'total_worked_hrs' => (string) $t->total_worked_hrs,
            'comments'         => $comments,
            'attachments'      => $attachments,
        ];
    }
}