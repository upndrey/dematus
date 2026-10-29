<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramDraft extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramDraftFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['players' => 'array', 'source_payload' => 'array', 'calculation' => 'array', 'sheet_result' => 'array', 'seen_at' => 'datetime', 'confirmed_at' => 'datetime', 'revision' => 'integer', 'source_changed' => 'boolean'];
    }
}
