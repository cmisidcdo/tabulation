<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefJudge extends Model
{
    protected $fillable = [
        'category',
        'judge',
        'nickname',
        'user_id',
        'is_active',
    ];
    protected $casts = [
        'category' => 'array', // Casts the 'details' column to a PHP array
    ];
    public function scopeCategory($query, $category)
    {
        return $query->whereJsonContains('ref_judges.category', $category);
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    /**
     * Order by judge number (nickname, a string column) numerically: 1, 2, … 10, 11.
     * Judges without a number go last. Portable across MySQL and SQLite.
     */
    public function scopeOrderByNumber($query)
    {
        return $query
            ->orderByRaw("CASE WHEN ref_judges.nickname IS NULL OR ref_judges.nickname = '' THEN 1 ELSE 0 END")
            ->orderByRaw('LENGTH(ref_judges.nickname)')
            ->orderBy('ref_judges.nickname')
            ->orderBy('ref_judges.id');
    }
    public function scopeActive($query)
    {
        return $query->where('ref_judges.is_active', 1);
    }
    public function getPercent()
    {
        $criterias = RefCriteria::where('category', 'poster')->count();
        $participant = RefParticipant::where('category', 'poster')->count();
        $total = $participant * $criterias;
        if ($total == 0) {
            return 0;
        }
        return bong_format($this->hasMany(Poster::class, 'judge_id')->whereNotNull('score')->count() / $total * 100);
    }
    public function getOralPercent()
    {
        $criterias = RefCriteria::where('category', 'oral')->count();
        $participant = RefParticipant::where('category', 'oral')->count();
        $total = $participant * $criterias;
        if ($total == 0) {
            return 0;
        }
        return bong_format($this->hasMany(Oral::class, 'judge_id')->whereNotNull('score')->count() / $total * 100);
    }
    public function getHigalaayPercent($category)
    {
        $criterias = RefCriteria::where('category', $category)->count();
        $participant = RefParticipant::category($category)->count();
        $total = $participant * $criterias;
        if ($total == 0) {
            return 0;
        }
        return bong_format($this->hasMany(Higalaay::class, 'judge_id')->where('category', $category)->whereNotNull('score')->count() / $total * 100);
    }
}
