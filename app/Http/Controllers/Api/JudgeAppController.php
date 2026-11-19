<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Higalaay;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\User;
use App\Services\HigalaayScoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * API for the offline judge app (public/judge-app). A judge downloads an event while online,
 * scores it on the device with no connection, and uploads the scores afterwards.
 * Only Higalaay (criteria) events, and only the judge's own column.
 */
class JudgeAppController extends Controller
{
    public const ABILITY = 'judge-app';

    /** tabulation_mode values that are NOT Higalaay-style scoring (see resources/views/category.blade.php) */
    private const OTHER_MODES = ['technical', 'oral', 'poster', 'quiz'];

    public function __construct(private HigalaayScoring $scoring)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Wrong email or password.'], 401);
        }
        if ($user->role === 'admin') {
            return response()->json(['message' => 'The offline judge app is for judge accounts. Admins use the main system.'], 403);
        }
        $judge = $this->activeJudge($user);
        if (!$judge) {
            return response()->json(['message' => 'This account is not linked to an active judge.'], 403);
        }

        $token = $user->createToken('judge-app' . (!empty($data['device']) ? ' · ' . $data['device'] : ''), [self::ABILITY]);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'judge' => ['id' => $judge->id, 'name' => $judge->judge],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    /** Higalaay events this judge is an active judge of. */
    public function events(Request $request): JsonResponse
    {
        [, $judge] = $this->judgeOrFail($request);

        $events = Category::where('is_active', 1)
            ->whereIn('category', (array) $judge->category)
            ->where(fn($q) => $q->whereNull('tabulation_mode')->orWhereNotIn('tabulation_mode', self::OTHER_MODES))
            ->orderBy('description')
            ->get()
            ->map(fn($c) => [
                'category' => $c->category,
                'description' => $c->description,
                'locked' => $this->scoring->isLocked($c->category),
            ])
            ->values();

        return response()->json(['judge' => ['id' => $judge->id, 'name' => $judge->judge], 'events' => $events]);
    }

    /** Everything needed to score one event offline. */
    public function package(Request $request, string $category): JsonResponse
    {
        [, $judge] = $this->judgeOrFail($request);
        $event = $this->eventOrFail($judge, $category);

        $criteria = RefCriteria::where('category', $category)->orderBy('id')
            ->get(['id', 'criteria', 'perfect_score', 'segment', 'segment_weight']);

        // Blind judging: when the event hides participant names from judges, they never leave the server
        $showNames = (bool) $event->display_participant;
        $participants = RefParticipant::category($category)
            ->get(['id', 'participant_no', 'participant', 'group_name'])
            ->sortBy('participant_no', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn($p) => [
                'id' => $p->id,
                'participant_no' => $p->participant_no,
                'participant' => $showNames ? $p->participant : null,
                'group_name' => $p->group_name,
            ])
            ->values();

        // Newest-first so keyBy() keeps the oldest row, matching firstOrNew() when saving
        $scores = Higalaay::where('category', $category)->where('judge_id', $judge->id)
            ->whereNotNull('score')
            ->orderByDesc('id')
            ->get(['participant_id', 'criteria_id', 'score'])
            ->keyBy(fn($r) => $r->participant_id . '-' . $r->criteria_id)
            ->map(fn($r) => ['participant_id' => $r->participant_id, 'criteria_id' => $r->criteria_id, 'score' => (float) $r->score])
            ->values();

        return response()->json([
            'event' => [
                'category' => $event->category,
                'description' => $event->description,
                'display_participant' => (bool) $event->display_participant,
                'locked' => $this->scoring->isLocked($category),
            ],
            'judge' => ['id' => $judge->id, 'name' => $judge->judge],
            'criteria' => $criteria,
            'participants' => $participants,
            'scores' => $scores,
            'downloaded_at' => now()->toIso8601String(),
        ]);
    }

    /** Upload the scores entered offline (idempotent: safe to retry). */
    public function upload(Request $request): JsonResponse
    {
        [$user, $judge] = $this->judgeOrFail($request);

        $data = $request->validate([
            'category' => 'required|string|max:100',
            'scores' => 'present|array|max:10000',
            'scores.*.participant_id' => 'required|integer',
            'scores.*.criteria_id' => 'required|integer',
            'scores.*.score' => 'nullable|numeric',
            'scores.*.base' => 'nullable|numeric',
            'scores.*.force' => 'sometimes|boolean',
        ]);
        $this->eventOrFail($judge, $data['category']);

        // The judge column always comes from the token's user, never from the request
        $result = $this->scoring->applyBatch($user, $data['category'], $judge, $data['scores'], 'Judge app');

        return response()->json($result + ['uploaded_at' => now()->toIso8601String()]);
    }

    /** @return array{0: User, 1: RefJudge} */
    private function judgeOrFail(Request $request): array
    {
        $user = $request->user();
        abort_unless($user && $user->tokenCan(self::ABILITY) && $user->role !== 'admin', 403, 'Not allowed.');
        $judge = $this->activeJudge($user);
        abort_unless($judge, 403, 'This account is not linked to an active judge.');
        return [$user, $judge];
    }

    private function activeJudge(User $user): ?RefJudge
    {
        return RefJudge::where('user_id', $user->id)->active()->orderBy('id')->first();
    }

    private function eventOrFail(RefJudge $judge, string $category): Category
    {
        $event = Category::where('category', $category)->where('is_active', 1)->first();
        abort_unless(
            $event
                && in_array($category, (array) $judge->category, true)
                && !in_array($event->tabulation_mode, self::OTHER_MODES, true),
            404,
            'Event not found for this judge.'
        );
        return $event;
    }
}
