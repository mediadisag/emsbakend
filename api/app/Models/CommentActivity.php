<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentActivity extends Model
{
    protected $fillable = [
        'comment_id', 'field_name', 'field_label', 'old_value', 'new_value',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}