<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSubscriber extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
