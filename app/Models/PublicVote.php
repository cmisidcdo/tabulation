<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One audience vote for the People's Choice Award (one row per device per event).
 */
class PublicVote extends Model
{
    protected $fillable = [
        'category',
        'group_key',
        'group_name',
        'device_token',
        'fingerprint',
        'ip',
        'user_agent',
    ];

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
