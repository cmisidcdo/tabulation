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
 * People's Choice Award: audience votes (one per device) for a group in a group event
 * such as Storytelling. Completely separate from the judges' tabulation.
 */
class PeoplesChoiceService
{
    public const OK            = 'ok';
    public const CLOSED        = 'closed';
    public const ALREADY_VOTED = 'already_voted';
    public const INVALID       = 'invalid';

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

    /* --------------------------------- Groups --------------------------------- */

    /** Same matching rule as GroupRankingReport: names compared trimmed and case-insensitive. */
    public static function groupKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /**
     * Groups in the event, keyed by group_key, in participant-number order.
     *
     * @return array<string, array{group_key:string, group_name:string, members:array<int,string>}>
     */
    public static function groups(string $slug): array
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
                'group_key'  => $key,
                'group_name' => trim($member->group_name),
                'members'    => [],
            ];
            $groups[$key]['members'][] = $member->participant;
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
    public static function castVote(string $slug, string $groupKey, string $token, ?string $fingerprint, ?string $ip, ?string $userAgent): string
    {
        if (!self::isOpen($slug)) {
            return self::CLOSED;
        }

        $groups = self::groups($slug);
        if (!isset($groups[$groupKey]) || $token === '') {
            return self::INVALID;
        }

        if (self::voteForDevice($slug, $token)) {
            return self::ALREADY_VOTED;
        }

        // Second line of defence: same browser fingerprint from the same network already voted
        // (e.g. cookies + site data cleared, or a private tab on the same phone).
        if ($fingerprint && PublicVote::category($slug)->where('fingerprint', $fingerprint)->where('ip', $ip)->exists()) {
            return self::ALREADY_VOTED;
        }

        try {
            PublicVote::create([
                'category'     => $slug,
                'group_key'    => $groupKey,
                'group_name'   => $groups[$groupKey]['group_name'],
                'device_token' => $token,
                'fingerprint'  => $fingerprint,
                'ip'           => $ip,
                'user_agent'   => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
            ]);
        } catch (QueryException $e) {
            // Unique (category, device_token) hit by a double-submit race.
            return self::ALREADY_VOTED;
        }

        return self::OK;
    }

    /* --------------------------------- Results -------------------------------- */

    /**
     * Ranked tally. Every group is listed, including those with no votes.
     *
     * @return array{rows: array<int, array>, total: int, winners: array<int, array>, shared_fingerprints: int}
     */
    public static function tally(string $slug): array
    {
        $groups = self::groups($slug);
        $counts = PublicVote::category($slug)
            ->selectRaw('group_key, MAX(group_name) as group_name, COUNT(*) as votes')
            ->groupBy('group_key')
            ->get()
            ->keyBy('group_key');

        $total = (int) $counts->sum('votes');

        $rows = [];
        foreach ($groups as $key => $group) {
            $rows[] = $group + ['votes' => (int) ($counts[$key]->votes ?? 0)];
        }
        // Votes for a group that was later renamed/removed still count and stay visible.
        foreach ($counts as $key => $count) {
            if (!isset($groups[$key])) {
                $rows[] = ['group_key' => $key, 'group_name' => $count->group_name, 'members' => [], 'votes' => (int) $count->votes];
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
