<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'heading',
        'description',
        'status',
        'priority',
        'permission_type',
        'milestone',      // legacy free-text, kept for old tickets
        'milestone_id',   // FK to milestones table
        'estimated_time',
        'total_worked_hrs',
        'due_date',
        'created_by',
        'assigned_to',
        'responsible_2',
    ];

    protected $casts = [
        'due_date' => 'date',
        'total_worked_hrs' => 'decimal:2',
    ];

    // ── Relationships ────────────────────────────────────────────
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function responsible2User()
    {
        return $this->belongsTo(User::class, 'responsible_2');
    }

    public function milestoneRelation()
    {
        return $this->belongsTo(Milestone::class, 'milestone_id');
    }

    public function comments()
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at');
    }

    public function activities()
    {
        return $this->hasMany(TicketActivity::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    // ── Display code, e.g. "TKT-0007" — this is what the front end
    // shows as the ticket ID and uses in URLs (/admin/tickets/TKT-0007).
    public function getCodeAttribute(): string
    {
        return 'TKT-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    // Lets Route::get('/admin/tickets/{ticket}', ...) resolve either the
    // plain numeric id or the "TKT-####" display code transparently.
    public function resolveRouteBinding($value, $field = null)
    {
        if (preg_match('/^TKT-(\d+)$/i', (string) $value, $m)) {
            return $this->where('id', (int) $m[1])->firstOrFail();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
