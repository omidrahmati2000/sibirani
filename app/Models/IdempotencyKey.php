<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'key', 'request_hash', 'status', 'response_status', 'response_body', 'order_id'];

    protected function casts(): array
    {
        return ['response_body' => 'array'];
    }
}
