<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // ✅ NEW
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;
    use SoftDeletes; // ✅ NEW — enables $project->delete() / ->restore() /
                     // ->withTrashed() / ->onlyTrashed(), backed by the
                     // existing `deleted_at` column. No migration needed
                     // since the column is already on the table.

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'department_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The department this project is scoped to. Null = company-wide,
     * visible to every department (see ProjectController::
     * applyProjectDepartmentScope()).
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * The user who created this project.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tickets that belong to this project.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'project_id');
    }

    /**
     * Milestones that belong to this project.
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'project_id');
    }
}