<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportComment extends Model
{
    protected $fillable = [
        'report_id',
        'user_id',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Images posted with the comment */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class);
    }

    /**
     * The comment's text, or what was posted when it is only files. Used in notifications.
     */
    public function summary(): string
    {
        if (trim($this->comment) !== '') {
            return $this->comment;
        }

        $attachments = $this->attachments()->get(['mime_type']);
        $count = $attachments->count();
        $noun = $attachments->every(fn ($attachment) => str_starts_with((string) $attachment->mime_type, 'image/')) ? 'image' : 'file';

        return 'Added ' . $count . ' ' . $noun . ($count === 1 ? '' : 's') . '.';
    }
}
