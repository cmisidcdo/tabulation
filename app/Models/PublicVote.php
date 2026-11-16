<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One audience vote for the People's Choice Award (one row per device per event).
 * mode = 'group' (choice_key = lower-cased group name) or 'participant' (choice_key = 'p{id}').
 */
class PublicVote extends Model
{
    protected $fillable = [
        'category',
        'mode',
        'choice_key',
        'choice_name',
        'device_token',
        'fingerprint',
        'ip',
        'user_agent',
        'latitude',
        'longitude',
        'location_accuracy',
        'distance_m',
    ];

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
