<?php

namespace App\Livewire;

use App\Models\Category;
use App\Services\PeoplesChoiceService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public (no login) People's Choice voting page. One vote per device:
 * a device token kept in a long-lived cookie AND localStorage (each restores the other),
 * plus a browser fingerprint checked together with the IP.
 */
class PublicVote extends Component
{
    public const COOKIE = 'pc_device';

    #[Locked]
    public string $slug;

    #[Locked]
    public ?string $deviceToken = null;

    #[Locked]
    public ?string $fingerprint = null;

    /** Name of what this device voted for; '' = voted but unknown (fingerprint block); null = not voted. */
    #[Locked]
    public ?string $votedFor = null;

    public ?string $error = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        // The device token is issued server-side on the first page load (cookie), so voting
        // never depends on the browser script having finished.
        $this->deviceToken = $this->validToken(request()->cookie(self::COOKIE)) ?: (string) Str::uuid();
        Cookie::queue(Cookie::forever(self::COOKIE, $this->deviceToken));

        if ($vote = PeoplesChoiceService::voteForDevice($slug, $this->deviceToken)) {
            $this->votedFor = $vote->choice_name;
        }
    }

    /**
     * Called from the browser once the page loads, with the localStorage token and fingerprint.
     * Returns the token the browser should store back into localStorage.
     */
    public function initDevice($localToken = null, $fingerprint = null): ?string
    {
        $this->setFingerprint($fingerprint);
        $localToken = $this->validToken($localToken);

        // Cookie was cleared but localStorage still remembers this device: restore the old identity.
        if ($localToken && $localToken !== $this->deviceToken && $this->votedFor === null) {
            $this->deviceToken = $localToken;
            Cookie::queue(Cookie::forever(self::COOKIE, $localToken));

            if ($vote = PeoplesChoiceService::voteForDevice($this->slug, $localToken)) {
                $this->votedFor = $vote->choice_name;
            }
        }

        return $this->deviceToken;
    }

    public function vote($choiceKey, $fingerprint = null): void
    {
        $this->error = null;

        // Fingerprint also arrives with the vote, in case initDevice hadn't completed yet.
        if (!$this->fingerprint) {
            $this->setFingerprint($fingerprint);
        }

        if (!$this->deviceToken) {
            $this->deviceToken = (string) Str::uuid();
            Cookie::queue(Cookie::forever(self::COOKIE, $this->deviceToken));
        }

        $limiterKey = 'peoples-choice:' . $this->slug . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 60)) {
            $this->error = 'Too many attempts. Please wait a minute and try again.';
            return;
        }
        RateLimiter::hit($limiterKey, 60);

        $choiceKey = is_string($choiceKey) ? mb_substr($choiceKey, 0, 255) : '';
        $status    = PeoplesChoiceService::castVote(
            $this->slug,
            $choiceKey,
            $this->deviceToken,
            $this->fingerprint,
            request()->ip(),
            request()->userAgent()
        );

        switch ($status) {
            case PeoplesChoiceService::OK:
                $this->votedFor = PeoplesChoiceService::voteForDevice($this->slug, $this->deviceToken)?->choice_name ?? '';
                break;
            case PeoplesChoiceService::ALREADY_VOTED:
                // '' = blocked by the fingerprint check, so we don't know (or reveal) the choice.
                $existing = PeoplesChoiceService::voteForDevice($this->slug, $this->deviceToken);
                $this->votedFor = $existing?->choice_name ?? '';
                break;
            case PeoplesChoiceService::CLOSED:
                $this->error = 'Voting is closed.';
                break;
            default:
                // Usually the admin switched group/participant mode while this page was open.
                $this->error = 'That choice is no longer available. Please choose again.';
        }
    }

    private function setFingerprint($fingerprint): void
    {
        $this->fingerprint = is_string($fingerprint) && preg_match('/^[a-f0-9]{16,64}$/', $fingerprint) ? $fingerprint : null;
    }

    private function validToken($token): ?string
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9\-]{20,64}$/', $token) ? $token : null;
    }

    public function render()
    {
        $category = Category::where('category', $this->slug)->first();
        $mode     = PeoplesChoiceService::mode($this->slug);

        return view('livewire.public-vote', [
            'category' => $category,
            'isOpen'   => PeoplesChoiceService::isOpen($this->slug),
            'mode'     => $mode,
            'target'   => PeoplesChoiceService::targetLabel($mode),
            'choices'  => PeoplesChoiceService::choices($this->slug, $mode),
        ]);
    }
}
