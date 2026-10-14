<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ReportAttachment extends Model
{
    protected $fillable = [
        'report_id',
        'report_comment_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'storage_path',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ReportComment::class, 'report_comment_id');
    }

    public function getUrl(): string
    {
        return Storage::url($this->storage_path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
