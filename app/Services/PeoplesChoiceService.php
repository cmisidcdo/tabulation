<?php

namespace App\Services;

use App\Models\PublicVote;
use App\Models\RefParticipant;
use App\Models\Setting;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\QueryException;

/**
 * People's Choice Award: audience votes (one per device) for a group or an individual
 * participant of an event. Completely separate from the judges' tabulation.
 */
class PeoplesChoiceService
{
    public const OK            = 'ok';
    public const CLOSED        = 'closed';
    public const ALREADY_VOTED = 'already_voted';
    public const INVALID       = 'invalid';

    public const MODE_GROUP       = 'group';
    public const MODE_PARTICIPANT = 'participant';
    public const MODES            = [self::MODE_GROUP, self::MODE_PARTICIPANT];

    /* ------------------------------ Voting state ------------------------------ */

    public static function isOpen(string $slug): bool
    {
        return !empty(Setting::get('peoples_choice_open_' . $slug));
    }

    public static function isRevealed(string $slug): bool
    {
        return !empty(Setting::get('peoples_choice_revealed_' . $slug));
    }

    public static function setOpen(string $slug, bool $open): void
    {
        Setting::set('peoples_choice_open_' . $slug, $open ? '1' : '0', "People's Choice voting open for " . $slug);
    }

    public static function setRevealed(string $slug, bool $revealed): void
    {
        Setting::set('peoples_choice_revealed_' . $slug, $revealed ? '1' : '0', "People's Choice winner revealed for " . $slug);
    }

    /* ---------------------------------- Mode ---------------------------------- */

    /** What the audience votes for in this event: 'group' (default) or 'participant'. */
    public static function mode(string $slug): string
    {
        $mode = Setting::get('peoples_choice_mode_' . $slug);
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_GROUP;
    }

    /**
     * Change the mode. Refused (returns false) while votes exist, so the two kinds of votes never mix.
     */
    public static function setMode(string $slug, string $mode): bool
    {
        if (!in_array($mode, self::MODES, true)) {
            return false;
        }
        if ($mode !== self::mode($slug) && PublicVote::category($slug)->exists()) {
            return false;
        }
        Setting::set('peoples_choice_mode_' . $slug, $mode, "People's Choice vote target for " . $slug);
        return true;
    }

    /** Singular word for the vote target, for UI text. */
    public static function targetLabel(string $mode): string
    {
        return $mode === self::MODE_PARTICIPANT ? 'participant' : 'group';
    }

    /* -------------------------------- Choices --------------------------------- */

    /** Same matching rule as GroupRankingReport: names compared trimmed and case-insensitive. */
    public static function groupKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /**
     * What the audience can vote for, keyed by choice key, in participant-number order.
     * Each choice: key, name, no (participant number, participant mode only), details (lines shown under the name).
     *
     * @return array<string, array{key:string, name:string, no:?string, details:array<int,string>}>
     */
    public static function choices(string $slug, ?string $mode = null): array
    {
        $mode ??= self::mode($slug);

        return $mode === self::MODE_PARTICIPANT
            ? self::participantChoices($slug)
            : self::groupChoices($slug);
    }

    private static function participantChoices(string $slug): array
    {
        // Natural sort so "2" comes before "10" regardless of the database.
        $participants = RefParticipant::category($slug)->orderBy('id')->get()
            ->sortBy(fn($p) => (string) $p->participant_no, SORT_NATURAL)
            ->values();

        $choices = [];
        foreach ($participants as $p) {
            $key = 'p' . $p->id;
            $choices[$key] = [
                'key'     => $key,
                'name'    => trim((string) $p->participant),
                'no'      => $p->participant_no,
                'details' => array_values(array_filter([trim((string) $p->group_name), trim((string) $p->school)])),
            ];
        }

        return $choices;
    }

    private static function groupChoices(string $slug): array
    {
        $members = RefParticipant::category($slug)
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->orderBy('participant_no')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($members as $member) {
            $key = self::groupKey($member->group_name);
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= [
                'key'     => $key,
                'name'    => trim($member->group_name),
                'no'      => null,
                'details' => [],
            ];
            $groups[$key]['details'][] = $member->participant;
        }

        return $groups;
    }

    /* --------------------------------- Voting --------------------------------- */

    public static function voteForDevice(string $slug, ?string $token): ?PublicVote
    {
        if (!$token) {
            return null;
        }
        return PublicVote::category($slug)->where('device_token', $token)->first();
    }

    /**
     * Record one vote. Returns one of the status constants.
     */
    public static function castVote(string $slug, string $choiceKey, string $token, ?string $fingerprint, ?string $ip, ?string $userAgent): string
    {
        if (!self::isOpen($slug)) {
            return self::CLOSED;
        }

        $mode    = self::mode($slug);
        $choices = self::choices($slug, $mode);
        if (!isset($choices[$choiceKey]) || $token === '') {
            return self::INVALID;
        }

        if (self::voteForDevice($slug, $token)) {
            return self::ALREADY_VOTED;
        }

        // Second line of defence: same browser fingerprint from the same network already voted
        // (e.g. cookies + site data cleared, or a private tab on the same phone).
        if (self::fingerprintVoted($slug, $fingerprint, $ip)) {
            return self::ALREADY_VOTED;
        }

        try {
            PublicVote::create([
                'category'     => $slug,
                'mode'         => $mode,
                'choice_key'   => $choiceKey,
                'choice_name'  => $choices[$choiceKey]['name'],
                'device_token' => $token,
                'fingerprint'  => $fingerprint,
                'ip'           => $ip,
                'user_agent'   => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
            ]);
        } catch (QueryException $e) {
            // Unique (category, device_token) hit by a double-submit race. Anything else (e.g. a
            // migration not run on the server) is a real error and must not look like "already voted".
            if (str_starts_with((string) $e->getCode(), '23')) {
                return self::ALREADY_VOTED;
            }
            throw $e;
        }

        return self::OK;
    }

    /** Has this browser fingerprint already voted in this event from the same IP? */
    public static function fingerprintVoted(string $slug, ?string $fingerprint, ?string $ip): bool
    {
        return $fingerprint
            && PublicVote::category($slug)->where('fingerprint', $fingerprint)->where('ip', $ip)->exists();
    }

    /* --------------------------------- Results -------------------------------- */

    /**
     * Ranked tally for the event's current mode. Every choice is listed, including those with no votes.
     *
     * @return array{mode: string, rows: array<int, array>, total: int, winners: array<int, array>, shared_fingerprints: int}
     */
    public static function tally(string $slug): array
    {
        $mode    = self::mode($slug);
        $choices = self::choices($slug, $mode);
        $counts  = PublicVote::category($slug)
            ->where('mode', $mode)
            ->selectRaw('choice_key, MAX(choice_name) as choice_name, COUNT(*) as votes')
            ->groupBy('choice_key')
            ->get()
            ->keyBy('choice_key');

        $total = (int) $counts->sum('votes');

        $rows = [];
        foreach ($choices as $key => $choice) {
            $rows[] = $choice + ['votes' => (int) ($counts[$key]->votes ?? 0), 'removed' => false];
        }
        // Votes for a choice that was later renamed/removed still count and stay visible.
        foreach ($counts as $key => $count) {
            if (!isset($choices[$key])) {
                $rows[] = ['key' => $key, 'name' => $count->choice_name, 'no' => null, 'details' => [], 'votes' => (int) $count->votes, 'removed' => true];
            }
        }

        foreach ($rows as &$row) {
            $row['percent'] = $total > 0 ? round($row['votes'] / $total * 100, 2) : 0;
        }
        unset($row);

        $rows = $rows ? bong_rank_arranger($rows, 'votes', 'ordinal_rank', true, 'DESC') : [];

        $winners = $total > 0
            ? array_values(array_filter($rows, fn($r) => $r['ordinal_rank'] == 1 && $r['votes'] > 0))
            : [];

        // Votes whose fingerprint appears more than once (possible repeat voters, or identical phones).
        $sharedFingerprints = (int) PublicVote::category($slug)
            ->whereNotNull('fingerprint')
            ->whereIn('fingerprint', function ($q) use ($slug) {
                $q->select('fingerprint')->from('public_votes')
                    ->where('category', $slug)->whereNotNull('fingerprint')
                    ->groupBy('fingerprint')->havingRaw('COUNT(*) > 1');
            })
            ->count();

        return [
            'mode'                => $mode,
            'rows'                => $rows,
            'total'               => $total,
            'winners'             => $winners,
            'shared_fingerprints' => $sharedFingerprints,
        ];
    }

    public static function resetVotes(string $slug): int
    {
        return PublicVote::category($slug)->delete();
    }

    /* ----------------------------------- QR ----------------------------------- */

    public static function voteUrl(string $slug): string
    {
        return route('vote', $slug);
    }

    /** Inline SVG markup for on-screen display. */
    public static function qrSvg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64'    => false,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
            'connectPaths'    => true,
            'eccLevel'        => 'M',
            'addQuietzone'    => true,
        ]);
        return (new QRCode($options))->render($data);
    }

    /** PNG data URI (GD) for PDFs, posters and downloads. */
    public static function qrPngDataUri(string $data, int $scale = 12): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64'    => true,
            'eccLevel'        => 'M',
            'scale'           => $scale,
            'addQuietzone'    => true,
        ]);
        return (new QRCode($options))->render($data);
    }
}
