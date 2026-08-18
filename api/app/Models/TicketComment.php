<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activities()
    {
        return $this->hasMany(TicketActivity::class);
    }

    // The front end shows at most one file per comment (the comment box's
    // single file input), so this is a hasOne over the same attachments table.
    public function attachment()
    {
        return $this->hasOne(TicketAttachment::class);
    }
}
