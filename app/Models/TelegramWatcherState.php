<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramWatcherState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['running' => 'boolean', 'started_at' => 'datetime'];
    }
}
