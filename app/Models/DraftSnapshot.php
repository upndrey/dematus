<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\DraftSnapshotFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'captured_at',
        'match_id',
        'dltv_url',
        'tournament',
        'radiant_team',
        'dire_team',
        'radiant_heroes',
        'dire_heroes',
        'bookmaker_odds_radiant',
        'bookmaker_odds_dire',
        'draft_payload',
        'stratz_payload',
        'feature_payload',
        'sheet_payload',
        'book_payload',
        'result_payload',
        'model_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'match_id' => 'integer',
            'radiant_heroes' => 'array',
            'dire_heroes' => 'array',
            'bookmaker_odds_radiant' => 'decimal:3',
            'bookmaker_odds_dire' => 'decimal:3',
            'draft_payload' => 'array',
            'stratz_payload' => 'array',
            'feature_payload' => 'array',
            'sheet_payload' => 'array',
            'book_payload' => 'array',
            'result_payload' => 'array',
        ];
    }

    public function stratzDataBuckets(): HasMany
    {
        return $this->hasMany(StratzDataBucket::class);
    }
}
