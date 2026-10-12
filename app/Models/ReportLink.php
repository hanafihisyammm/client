<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportLink extends Model
{
    protected $fillable = [
        'source_report_id',
        'target_report_id',
        'link_type',
        'created_by',
    ];

    public const LINK_TYPES = [
        'duplicates' => 'Duplicates',
        'is_duplicated_by' => 'Is duplicated by',
        'blocks' => 'Blocks',
        'is_blocked_by' => 'Is blocked by',
        'relates_to' => 'Relates to',
    ];

    public const INVERSE_LINK_TYPES = [
        'duplicates' => 'is_duplicated_by',
        'is_duplicated_by' => 'duplicates',
        'blocks' => 'is_blocked_by',
        'is_blocked_by' => 'blocks',
        'relates_to' => 'relates_to',
    ];

    public function sourceReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'source_report_id');
    }

    public function targetReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'target_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getLinkTypeLabel(): string
    {
        return self::LINK_TYPES[$this->link_type] ?? $this->link_type;
    }
}
