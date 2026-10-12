<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $fillable = [
        'report_id',
        'user_id',
        'hours',
        'description',
        'logged_date',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'logged_date' => 'date',
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
}
