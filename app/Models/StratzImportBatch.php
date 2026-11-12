<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StratzImportBatch extends Model
{
    use HasFactory;

    protected $fillable = ['status', 'tournament', 'total_items', 'processed_items', 'succeeded_items', 'skipped_items', 'failed_items'];

    public function items(): HasMany
    {
        return $this->hasMany(StratzImportItem::class);
    }

    /** @return array<string, int|string> */
    public function statusPayload(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'total_items' => $this->total_items, 'processed_items' => $this->processed_items, 'succeeded_items' => $this->succeeded_items, 'skipped_items' => $this->skipped_items, 'failed_items' => $this->failed_items];
    }
}
