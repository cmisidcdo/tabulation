<?php

namespace App\Models;

use App\Services\ReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RefParticipant extends Model
{
    protected $fillable = [
        'category',
        'participant_no',
        'participant',
        'school',
        'gender',
        'group_name',
    ];
    protected $casts = [
        'category' => 'array', // Casts the 'details' column to a PHP array
    ];

    public function scopeCategory($query, $category)
    {
        return $query->whereJsonContains('ref_participants.category', $category);
    }
    public function sumRound1()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->where('round_id', 1)->sum('score');
    }

    public function sumRound2()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->where('round_id', 2)->sum('score');
    }

    public function sumRound3()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->where('round_id', 3)->sum('score');
    }
    public function sumRound4()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->where('round_id', 4)->sum('score');
    }

    public function sumAll()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->sum('score');
    }
    public function getPercent()
    {
        return $this->hasMany(QuizBee::class, 'participant_id')->where('round_id', '!=', 4)->whereNotNull('score')->count() / 25 * 100;
    }
    public function convert($path): String
    {
        if ($path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $image = base64_encode(file_get_contents($path));
            return "data:image/" . $ext . ";base64," . $image;
        } else {
            return "";
        }
    }
    public function getScore($judge_id)
    {
        return $this->hasMany(Oral::class, 'participant_id', 'id')->where('judge_id', $judge_id)->sum('score');
    }
    public function judgeTotalScore()
    {
        return $this->hasMany(Oral::class, 'participant_id', 'id')->sum('score');
    }
    public function deductions()
    {
        return $this->hasOne(OralDeduction::class, 'participant_id', 'id');
    }
    public function getPosterScore($judge_id)
    {
        return $this->hasMany(Poster::class, 'participant_id', 'id')->where('judge_id', $judge_id)->sum('score');
    }
    public function judgePosterTotalScore()
    {
        return $this->hasMany(Poster::class, 'participant_id', 'id')->sum('score');
    }
    public function posterOutput()
    {
        return $this->hasOne(PosterOutput::class, 'participant_id', 'id');
    }
    public function averagePoster()
    {
        return $this->hasMany(Poster::class, 'participant_id', 'id')->sum('score') / 3;
    }
    public function averageOral()
    {
        $deduction = $this->deductions?->deduction;
        return ($this->hasOne(Oral::class, 'participant_id', 'id')->sum('score') / 3) - $deduction;
    }
    public function averageHigalaay($category, $criteria = null)
    {
        $isAdmin = Auth::user()->role == 'admin';
        $judgeId = $isAdmin
            ? null
            : RefJudge::where('user_id', Auth::user()->id)->category($category)->value('id');

        return self::computeHigalaayTotal(
            $this->hasMany(Higalaay::class, 'participant_id', 'id')->where('category', $category)->get(),
            RefCriteria::where('category', $category)->get(),
            RefJudge::active()->category($category)->count(),
            $isAdmin,
            $judgeId,
            $this->higalaayDeduction($category)?->deduction ?? 0,
            $criteria,
        );
    }

    /**
     * Higalaay total computed from already-loaded rows (runs no queries), so the
     * scoring page can total every participant from a single preloaded score set.
     *
     * @param  \Illuminate\Support\Collection  $scores      this participant's Higalaay rows for the category
     * @param  \Illuminate\Support\Collection  $criterias   all RefCriteria of the category
     * @param  int        $activeJudgeCount  active judges in the category (the panel size)
     * @param  bool       $isAdmin           admin sees the panel average; a judge sees only their own scores
     * @param  int|null   $judgeId           the judge's id (non-admin); null → no judge record → 0
     * @param  float      $deduction         participant deduction (not applied for a single criteria)
     * @param  RefCriteria|null $criteria    single-criteria view (per-criteria PDF) — no weighting
     */
    public static function computeHigalaayTotal($scores, $criterias, int $activeJudgeCount, bool $isAdmin, ?int $judgeId, $deduction, $criteria = null): float
    {
        $deduction = (float) $deduction;
        if (!$isAdmin) {
            if (!$judgeId) return 0;
            $scores = $scores->where('judge_id', $judgeId);
        }

        // Single-criteria filter (used for per-criteria PDF views) — no weighting
        if ($criteria) {
            $sum = $scores->where('criteria_id', $criteria->id)->sum('score');
            if (!$isAdmin) return $sum;
            return $activeJudgeCount > 0 ? $sum / $activeJudgeCount : 0;
        }

        // Weighted segments
        $weighted = $criterias->whereNotNull('segment')->whereNotNull('segment_weight');
        if ($weighted->isNotEmpty()) {
            if ($activeJudgeCount == 0) return 0;
            $judgeCount = $isAdmin ? $activeJudgeCount : 1;

            $total = 0;
            foreach ($weighted->groupBy('segment') as $segCriterias) {
                $segWeight = $segCriterias->first()->segment_weight; // e.g. 20
                $segMax    = $segCriterias->sum('perfect_score');    // e.g. 100
                if ($segMax == 0) continue;

                $segScore = $scores->whereIn('criteria_id', $segCriterias->pluck('id'))->sum('score');
                // (segScore / judgeCount) = avg judge score for segment (max = segMax)
                // normalize to weight: (avg / segMax) * segWeight
                $total += ($segScore / $judgeCount / $segMax) * $segWeight;
            }

            return round($total - $deduction, 4);
        }

        // Unweighted average
        if (!$isAdmin) return $scores->sum('score') - $deduction;
        return $activeJudgeCount > 0 ? ($scores->sum('score') / $activeJudgeCount) - $deduction : 0;
    }
    public function getHigalaayScoreByJudge($judge_id, $category,  $criteria =  null)
    {
        $relation = $this->hasMany(Higalaay::class, 'participant_id', 'id');
        if ($criteria) {
            $relation->where('criteria_id', $criteria->id);
        }
        return $relation->where('category', $category)->where('judge_id', $judge_id)->sum('score');
    }
    public function getRankingsByJudge($judge_id, $category, $participant_id, $applyJudgeDeduction = false)
    {
        $service = new ReportService($category, null, $judge_id, $applyJudgeDeduction);
        $participants = $service->generateTopParticipants();
        return $participants->where('id', $participant_id)->first()?->current_rank;
    }
    public function higalaayDeduction($category)
    {
        return $this->hasOne(HigalaayDeduction::class, 'participant_id', 'id')->where('category', $category)->first();
    }
    public function higalaayJudgesTotalScore($category)
    {
        return $this->hasMany(Higalaay::class, 'participant_id', 'id')->where('category', $category)->sum('score');
    }
    public function geTheHighestPoints($category)
    {
        return $this->hasMany(Higalaay::class, 'participant_id', 'id')->where('category', $category)->max('score');
    }
}
