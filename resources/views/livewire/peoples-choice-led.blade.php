<div class="pcl-screen" wire:poll.3s>
    <div class="pcl-kicker">People's Choice Award</div>
    <div class="pcl-event">{{ $category?->description }}</div>

    @if ($isRevealed)
        @if (count($winners))
            <div class="pcl-trophy"><i class="bi bi-trophy-fill"></i></div>
            @foreach ($winners as $winner)
                <div class="pcl-winner">{{ $winner['group_name'] }}</div>
                @if (!empty($winner['members']))
                    <div class="pcl-members">{{ implode(' • ', $winner['members']) }}</div>
                @endif
            @endforeach
            @if (count($winners) > 1)
                <div class="pcl-note">Tied for People's Choice</div>
            @endif
        @else
            <div class="pcl-wait">No votes were cast.</div>
        @endif
    @elseif ($isOpen && $qrSvg)
        <div class="pcl-vote">
            <div class="pcl-qr">{!! $qrSvg !!}</div>
            <div class="pcl-vote-text">
                <div class="pcl-cta">Scan to vote!</div>
                <div class="pcl-steps">Open your phone camera, scan the code,<br>and pick your favorite group.</div>
                <div class="pcl-steps pcl-small">One vote per device.</div>
            </div>
        </div>
    @else
        <div class="pcl-wait">Coming soon...</div>
    @endif
</div>
