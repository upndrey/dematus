<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StratzDataBucket extends Model
{
    /** @use HasFactory<\Database\Factories\StratzDataBucketFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'draft_snapshot_id',
        'bucket_key',
        'data_type',
        'window_weeks',
        'anchor_week',
        'week_timestamps',
        'bracket_basic_id',
        'hero_ids',
        'raw_payload',
        'normalized_payload',
        'payload_hash',
        'model_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_weeks' => 'integer',
            'anchor_week' => 'integer',
            'week_timestamps' => 'array',
            'hero_ids' => 'array',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
        ];
    }

    public function draftSnapshot(): BelongsTo
    {
        return $this->belongsTo(DraftSnapshot::class);
    }
}
