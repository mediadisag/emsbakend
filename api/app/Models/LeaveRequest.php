<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'leave_requests';

    protected $fillable = [
        'user_id',
        'type',
        'day_type',      // ✅ NEW — 'Full Day' or 'Half Day'
        'from_date',
        'to_date',
        'reason',
        'status',
        'admin_reason',
        'reviewed_by',
        'reviewed_at',
    ];
}