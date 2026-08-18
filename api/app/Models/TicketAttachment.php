<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TicketAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'ticket_comment_id',
        'uploaded_by',
        'original_name',
        'path',
        'size',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function comment()
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // Public URL for this attachment.
    // NOTE: this server does NOT use the standard `storage:link` symlink
    // (public/storage -> storage/app/public), so Storage::disk('public')
    // ->url() is the wrong builder here — it assumes that symlink exists
    // and produces a /storage/... url on the wrong domain besides. Files
    // are actually served straight off the real path under
    // ems.disagglobal.com/api/storage/app/public/{path}, so we build
    // that directly. $this->path is stored relative to that root, e.g.
    // "tickets/1/u8MLDAYrj8ub5a90eXOPx0JGg6SaGsBUdRJWVyh9.png".
    public function getUrlAttribute(): string
    {
        return 'https://ems.disagglobal.com/api/storage/app/public/' . ltrim($this->path, '/');
    }
}