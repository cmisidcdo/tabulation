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
    public const NEED_LOCATION = 'need_location';
    public const OUTSIDE_AREA  = 'outside_area';

    /** Extra meters allowed for GPS inaccuracy, at most (so a vague fix can't stretch the area much). */
    public const MAX_ACCURACY_TOLERANCE = 100;
    public const MIN_RADIUS = 20;
    public const MAX_RADIUS = 5000;

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

    /* -------------------------------- Geofence -------------------------------- */

    /** Saved geofence settings (even when disabled), for the admin form. */
    public static function geofenceSettings(string $slug): array
    {
        $saved = json_decode((string) Setting::get('peoples_choice_geo_' . $slug, ''), true);
        $saved = is_array($saved) ? $saved : [];

        return [
            'enabled' => !empty($saved['enabled']),
            'lat'     => isset($saved['lat']) && is_numeric($saved['lat']) ? (float) $saved['lat'] : null,
            'lng'     => isset($saved['lng']) && is_numeric($saved['lng']) ? (float) $saved['lng'] : null,
            'radius'  => isset($saved['radius']) && is_numeric($saved['radius']) ? (int) $saved['radius'] : 150,
        ];
    }

    /** The active geofence ({lat, lng, radius}), or null when voting is not location-restricted. */
    public static function geofence(string $slug): ?array
    {
        $geo = self::geofenceSettings($slug);
        return $geo['enabled'] && $geo['lat'] !== null && $geo['lng'] !== null ? $geo : null;
    }

    /**
     * Validate and save the geofence. Returns an error message, or null on success.
     */
    public static function setGeofence(string $slug, bool $enabled, $lat, $lng, $radius): ?string
    {
        $lat    = is_numeric($lat) ? (float) $lat : null;
        $lng    = is_numeric($lng) ? (float) $lng : null;
        $radius = is_numeric($radius) ? (int) round((float) $radius) : null;

        if (($lat === null) !== ($lng === null)) {
            return 'Enter both latitude and longitude.';
        }
        if ($lat !== null && ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)) {
            return 'Latitude must be between -90 and 90, and longitude between -180 and 180.';
        }
        if ($radius === null || $radius < self::MIN_RADIUS || $radius > self::MAX_RADIUS) {
            return 'Radius must be between ' . self::MIN_RADIUS . ' and ' . number_format(self::MAX_RADIUS) . ' meters.';
        }
        if ($enabled && $lat === null) {
            return 'Set the venue location first (tap "Use my current location" at the venue).';
        }

        Setting::set(
            'peoples_choice_geo_' . $slug,
            json_encode(['enabled' => $enabled, 'lat' => $lat, 'lng' => $lng, 'radius' => $radius]),
            "People's Choice voting area (geofence) for " . $slug
        );

        return null;
    }

    /** Great-circle distance in meters (haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** A location sent by the browser, cleaned up: {lat, lng, accuracy}, or null if unusable. */
    public static function cleanLocation($location): ?array
    {
        if (!is_array($location) || !isset($location['lat'], $location['lng'])
            || !is_numeric($location['lat']) || !is_numeric($location['lng'])) {
            return null;
        }

        $lat = (float) $location['lat'];
        $lng = (float) $location['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        $accuracy = isset($location['accuracy']) && is_numeric($location['accuracy']) ? max(0.0, (float) $location['accuracy']) : null;

        return ['lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy];
    }

    /**
     * Check a location against the event's geofence.
     * Inside when distance <= radius + min(accuracy, MAX_ACCURACY_TOLERANCE).
     *
     * @return array{status: string, distance: ?int}
     */
    public static function checkLocation(string $slug, $location): array
    {
        $geo = self::geofence($slug);
        if (!$geo) {
            return ['status' => self::OK, 'distance' => null];
        }

        $location = self::cleanLocation($location);
        if (!$location) {
            return ['status' => self::NEED_LOCATION, 'distance' => null];
        }

        $distance  = self::distanceMeters($geo['lat'], $geo['lng'], $location['lat'], $location['lng']);
        $tolerance = min($location['accuracy'] ?? 0, self::MAX_ACCURACY_TOLERANCE);

        return [
            'status'   => $distance <= $geo['radius'] + $tolerance ? self::OK : self::OUTSIDE_AREA,
            'distance' => (int) round($distance),
        ];
    }

    /** "850 m" / "2.4 km" */
    public static function formatDistance(int $meters): string
    {
        return $meters < 1000 ? $meters . ' m' : rtrim(rtrim(number_format($meters / 1000, 1), '0'), '.') . ' km';
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
    public static function castVote(string $slug, string $choiceKey, string $token, ?string $fingerprint, ?string $ip, ?string $userAgent, $location = null): string
    {
        return self::castVoteDetailed($slug, $choiceKey, $token, $fingerprint, $ip, $userAgent, $location)['status'];
    }

    /**
     * Same as castVote(), also returning the distance from the venue when a geofence is on.
     *
     * @return array{status: string, distance: ?int}
     */
    public static function castVoteDetailed(string $slug, string $choiceKey, string $token, ?string $fingerprint, ?string $ip, ?string $userAgent, $location = null): array
    {
        $status = self::recordVote($slug, $choiceKey, $token, $fingerprint, $ip, $userAgent, $location, $distance);
        return ['status' => $status, 'distance' => $distance];
    }

    private static function recordVote(string $slug, string $choiceKey, string $token, ?string $fingerprint, ?string $ip, ?string $userAgent, $location, ?int &$distance): string
    {
        $distance = null;

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

        // Geofence: only phones at the venue may vote (checked after the "already voted" checks,
        // so a device that voted still gets its thank-you screen).
        $geoCheck = self::checkLocation($slug, $location);
        $distance = $geoCheck['distance'];
        if ($geoCheck['status'] !== self::OK) {
            return $geoCheck['status'];
        }
        $location = self::geofence($slug) ? self::cleanLocation($location) : null;

        try {
            PublicVote::create([
                'latitude'          => $location['lat'] ?? null,
                'longitude'         => $location['lng'] ?? null,
                'location_accuracy' => $location['accuracy'] ?? null,
                'distance_m'        => $location ? $distance : null,
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
            'geofence'            => self::geofence($slug),
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
