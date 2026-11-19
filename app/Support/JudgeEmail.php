<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds a judge's login email from their name, using the last name:
 *   "June sab Delacerna" → delacerna@, then jdelacerna@, jsdelacerna@, delacerna2@, …
 * whichever is the first one not already taken by another user.
 */
class JudgeEmail
{
    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'engr', 'atty', 'prof', 'hon', 'rev', 'fr', 'sir', 'maam', 'madam'];
    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];
    /** Words that belong to the surname that follows them: "Dela Cruz", "De los Santos", "San Jose". */
    private const PARTICLES = ['de', 'del', 'dela', 'la', 'delos', 'los', 'las', 'dels', 'san', 'santa', 'sta', 'sto', 'santo', 'van', 'von', 'da', 'di', 'du', 'le', 'dos', 'das'];

    public static function domain(): string
    {
        return config('app.judge_email_domain', 'tabulation.com');
    }

    /** First free login email for this name. */
    public static function generate(string $fullName, ?int $ignoreUserId = null): string
    {
        foreach (self::candidates($fullName) as $local) {
            $email = $local . '@' . self::domain();
            $taken = User::where('email', $email)
                ->when($ignoreUserId, fn($q) => $q->where('id', '!=', $ignoreUserId))
                ->exists();
            if (!$taken) {
                return $email;
            }
        }
        // candidates() is unbounded, so this is never reached
        return Str::lower(Str::random(10)) . '@' . self::domain();
    }

    /**
     * Local parts in order of preference.
     *
     * @return \Generator<string>
     */
    public static function candidates(string $fullName): \Generator
    {
        ['first' => $first, 'middle' => $middle, 'last' => $last] = self::parse($fullName);
        $base = $last !== '' ? $last : 'judge';

        $seen = [];
        $fixed = [$base];
        if ($first !== '') {
            $fixed[] = $first[0] . $base;
            if ($middle) {
                $fixed[] = $first[0] . implode('', array_map(fn($m) => $m[0], $middle)) . $base;
            }
        }
        foreach ($fixed as $c) {
            if (!isset($seen[$c])) {
                $seen[$c] = true;
                yield $c;
            }
        }
        for ($n = 2; ; $n++) {
            yield $base . $n;
        }
    }

    /**
     * Split a display name into first / middle / last (surname particles joined to the last).
     *
     * @return array{first: string, middle: string[], last: string}
     */
    public static function parse(string $fullName): array
    {
        $clean = Str::lower(Str::ascii($fullName));          // ñ → n, é → e
        $clean = str_replace('-', ' ', $clean);               // "Salvaña-Unchuan" → last part "unchuan"
        $clean = preg_replace('/[^a-z0-9\s]/', '', $clean);   // drop dots, commas, apostrophes
        $tokens = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

        while ($tokens && in_array($tokens[0], self::TITLES, true)) {
            array_shift($tokens);
        }
        while (count($tokens) > 1 && in_array(end($tokens), self::SUFFIXES, true)) {
            array_pop($tokens);
        }

        if (!$tokens) {
            return ['first' => '', 'middle' => [], 'last' => ''];
        }
        if (count($tokens) === 1) {
            return ['first' => '', 'middle' => [], 'last' => $tokens[0]];
        }

        // Last name = final word plus any particles directly before it (never the first name)
        $lastStart = count($tokens) - 1;
        while ($lastStart > 1 && in_array($tokens[$lastStart - 1], self::PARTICLES, true)) {
            $lastStart--;
        }

        return [
            'first' => $tokens[0],
            'middle' => array_slice($tokens, 1, $lastStart - 1),
            'last' => implode('', array_slice($tokens, $lastStart)),
        ];
    }
}
