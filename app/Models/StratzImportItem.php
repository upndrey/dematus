<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StratzImportItem extends Model
{
    use HasFactory;

    protected $fillable = ['match_id', 'status', 'attempts', 'draft_snapshot_id', 'error_message', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StratzImportBatch::class, 'stratz_import_batch_id');
    }

    public function draftSnapshot(): BelongsTo
    {
        return $this->belongsTo(DraftSnapshot::class);
    }
}
