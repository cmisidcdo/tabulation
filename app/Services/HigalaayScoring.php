<?php

namespace App\Services;

use App\Models\Higalaay;
use App\Models\Log;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single place for writing Higalaay judge scores, shared by the Livewire scoring page,
 * the offline judge app upload, and the admin import of judge-app backup files.
 */
class HigalaayScoring
{
    public const ERR_LOCKED = 'Scoring is locked.';
    public const ERR_CELL = 'You cannot score this cell.';

    public function isLocked(string $category): bool
    {
        return !empty(Setting::get('scoring_locked_' . $category));
    }

    /**
     * Validate and save one score. Returns ['ok' => true, 'score' => saved value]
     * or ['ok' => false, 'error' => message].
     */
    public function saveJudgeScore(User $user, string $category, $participantId, $criteriaId, $judgeId, $score, ?string $source = null): array
    {
        $isAdmin = $user->role === 'admin';
        if (!$isAdmin && $this->isLocked($category)) {
            return ['ok' => false, 'error' => self::ERR_LOCKED];
        }

        $judge = $this->allowedJudge($user, $category, $judgeId);
        $criteria = RefCriteria::whereKey($criteriaId)->where('category', $category)->first();
        $participantOk = RefParticipant::whereKey($participantId)->category($category)->exists();
        if (!$judge || !$criteria || !$participantOk) {
            return ['ok' => false, 'error' => self::ERR_CELL];
        }

        $value = $this->clamp($score, $criteria);
        $this->write($user, $category, (int) $participantId, $criteria->id, $judge->id, $value, $source);

        return ['ok' => true, 'score' => $value];
    }

    /**
     * Apply a batch of offline scores for one judge column.
     *
     * Each item: participant_id, criteria_id, score, base (server value the device last saw), force.
     * Conflict rule: the score is written only when the server value still equals `base`
     * (nobody changed it since the device downloaded it), unless `force` is set.
     *
     * @return array{results: array<int, array>, counts: array<string, int>}
     */
    public function applyBatch(User $user, string $category, RefJudge $judge, array $items, string $source): array
    {
        $counts = ['saved' => 0, 'same' => 0, 'conflict' => 0, 'rejected' => 0];
        $results = [];
        $add = function (array $item, string $status, array $extra = []) use (&$results, &$counts) {
            $counts[$status]++;
            $results[] = [
                'participant_id' => (int) ($item['participant_id'] ?? 0),
                'criteria_id' => (int) ($item['criteria_id'] ?? 0),
                'status' => $status,
            ] + $extra;
        };

        $isAdmin = $user->role === 'admin';
        $judgeOk = (bool) $this->allowedJudge($user, $category, $judge->id);
        $locked = !$isAdmin && $this->isLocked($category);

        if (!$judgeOk || $locked) {
            foreach ($items as $item) {
                $add($item, 'rejected', ['error' => $locked ? self::ERR_LOCKED : self::ERR_CELL]);
            }
            return compact('results', 'counts');
        }

        // Preload everything the batch needs (one query each instead of several per item)
        $criterias = RefCriteria::where('category', $category)->get()->keyBy('id');
        $participantIds = RefParticipant::category($category)->pluck('id')->flip();
        // Newest-first so keyBy() keeps the oldest row, matching firstOrNew() in write()
        $current = Higalaay::where('category', $category)->where('judge_id', $judge->id)
            ->orderByDesc('id')->get(['participant_id', 'criteria_id', 'score'])
            ->keyBy(fn($r) => $r->participant_id . '-' . $r->criteria_id)
            ->map(fn($r) => $r->score);

        DB::transaction(function () use ($items, $criterias, $participantIds, $current, $user, $category, $judge, $source, $add) {
            foreach ($items as $item) {
                $pid = (int) ($item['participant_id'] ?? 0);
                $criteria = $criterias->get((int) ($item['criteria_id'] ?? 0));
                if (!$criteria || !$participantIds->has($pid)) {
                    $add($item, 'rejected', ['error' => self::ERR_CELL]);
                    continue;
                }

                $value = $this->clamp($item['score'] ?? null, $criteria);
                $server = $current->get($pid . '-' . $criteria->id);
                $base = $item['base'] ?? null;

                if ($server !== null && $this->same($server, $value)) {
                    $add($item, 'same', ['score' => $value]);
                    continue;
                }
                if (empty($item['force']) && !$this->same($server, $base)) {
                    $add($item, 'conflict', ['score' => $value, 'server' => $server === null ? null : (float) $server]);
                    continue;
                }

                $this->write($user, $category, $pid, $criteria->id, $judge->id, $value, $source);
                $add($item, 'saved', ['score' => $value]);
            }
        });

        return compact('results', 'counts');
    }

    /** Active judge of this category that the user may write for (admins: any; judges: their own). */
    public function allowedJudge(User $user, string $category, $judgeId): ?RefJudge
    {
        return RefJudge::whereKey($judgeId)->active()->category($category)
            ->when($user->role !== 'admin', fn($q) => $q->where('user_id', $user->id))
            ->first();
    }

    /** Blank saves 0 (intended); clamp to 0..perfect_score. */
    public function clamp($score, RefCriteria $criteria): float
    {
        $value = is_numeric($score) ? (float) $score : 0;
        $value = max(0, $value);
        if ($criteria->perfect_score !== null && $value > $criteria->perfect_score) {
            $value = (float) $criteria->perfect_score;
        }
        return $value;
    }

    private function same($a, $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return ($a === null || $a === '') && ($b === null || $b === '');
        }
        return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.00001;
    }

    private function write(User $user, string $category, int $participantId, int $criteriaId, int $judgeId, float $value, ?string $source): void
    {
        $higalaay = Higalaay::firstOrNew([
            'participant_id' => $participantId,
            'category' => $category,
            'criteria_id' => $criteriaId,
            'judge_id' => $judgeId,
        ]);
        $oldScore = $higalaay->score;
        $higalaay->score = $value;
        $higalaay->save();

        Log::create([
            'user_id' => $user->id,
            'activity' => ($source ? '(' . $source . ') ' : '') . $category . ' Higalaay id ' . $higalaay->id . ' Score has been updated from ' . $oldScore . ' to ' . $higalaay->score,
        ]);
    }
}
