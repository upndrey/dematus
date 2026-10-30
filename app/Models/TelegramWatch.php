<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramWatch extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramWatchFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'expires_at' => 'datetime', 'notification_messages' => 'array', 'ignore_update_pending' => 'boolean'];
    }
}
