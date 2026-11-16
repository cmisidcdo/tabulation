<div class="pc-wrap" x-data="{ selected: null, selectedName: '', q: '' }">
    <header class="pc-header text-center">
        <img src="{{ asset('img/cdo-seal.png') }}" alt="" class="pc-logo">
        <div class="pc-kicker">People's Choice Award</div>
        <h1 class="pc-title">{{ $category?->description }}</h1>
    </header>

    @if ($votedFor !== null)
        {{-- Already voted / thank you --}}
        <div class="pc-card pc-state text-center">
            <div class="pc-state-icon text-success"><i class="bi bi-check-circle-fill"></i></div>
            <h2 class="h4 fw-bold mb-2">Thank you for voting!</h2>
            @if ($votedFor !== '')
                <p class="mb-1 text-muted">Your vote went to</p>
                <p class="h5 fw-bold text-uppercase mb-3">{{ $votedFor }}</p>
            @else
                <p class="text-muted mb-3">A vote has already been recorded from this device.</p>
            @endif
            <p class="small text-muted mb-0">Only one vote per device is allowed. Watch for the winner announcement!</p>
        </div>
    @elseif (!$isOpen)
        {{-- Closed: poll so the page opens by itself when the admin opens voting --}}
        <div class="pc-card pc-state text-center" wire:poll.15s>
            <div class="pc-state-icon text-secondary"><i class="bi bi-hourglass-split"></i></div>
            <h2 class="h4 fw-bold mb-2">Voting is not open</h2>
            <p class="text-muted mb-0">Keep this page open. It will refresh automatically once voting starts.</p>
        </div>
    @elseif (empty($choices))
        <div class="pc-card pc-state text-center">
            <p class="text-muted mb-0">No {{ $target }}s to vote for yet.</p>
        </div>
    @else
        <p class="text-center pc-instructions">Tap your favorite {{ $target }}, then press <strong>Submit Vote</strong>.<br>You can vote <strong>once</strong> on this device.</p>

        @if ($error)
            <div class="alert alert-danger py-2 text-center">{{ $error }}</div>
        @endif

        @if (count($choices) > 8)
            <div class="pc-search">
                <i class="bi bi-search"></i>
                <input type="search" x-model="q" class="form-control" placeholder="Search {{ $target }} name or number">
            </div>
        @endif

        <div class="pc-list" wire:key="choices-{{ $mode }}">
            @foreach ($choices as $choice)
                @php
                    $haystack = mb_strtolower(trim(($choice['no'] ?? '') . ' ' . $choice['name'] . ' ' . implode(' ', $choice['details'])));
                @endphp
                <button type="button" class="pc-option" wire:key="choice-{{ $choice['key'] }}"
                    x-show="!q || @js($haystack).includes(q.trim().toLowerCase())"
                    :class="{ 'is-selected': selected === @js($choice['key']) }"
                    x-on:click="selected = @js($choice['key']); selectedName = @js($choice['name'])">
                    <span class="pc-radio"><i class="bi bi-check-lg"></i></span>
                    @if (!empty($choice['no']))
                        <span class="pc-no">#{{ $choice['no'] }}</span>
                    @endif
                    <span class="pc-option-body">
                        <span class="pc-option-name">{{ $choice['name'] }}</span>
                        @if (!empty($choice['details']))
                            <span class="pc-option-members">{{ implode($mode === 'group' ? ', ' : ' • ', $choice['details']) }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>

        <div class="pc-submit-bar">
            <button type="button" class="btn btn-lg w-100 pc-submit"
                :disabled="!selected"
                wire:loading.attr="disabled" wire:target="vote"
                x-on:click="if (selected && confirm('Submit your vote for ' + selectedName + '?\nYou cannot change it later.')) { $wire.vote(selected, window.pcFp || null) }">
                <span wire:loading.remove wire:target="vote">
                    <span x-show="!selected">Select a {{ $target }}</span>
                    <span x-show="selected" x-cloak>Submit Vote</span>
                </span>
                <span wire:loading wire:target="vote">
                    <span class="spinner-border spinner-border-sm"></span> Submitting...
                </span>
            </button>
        </div>
    @endif
</div>

@script
    <script>
        {{-- NOTE: this script must START with "const" — Livewire evaluates @script as an expression
             unless it begins with let/const (then it is wrapped in an async function). --}}
        const pcFingerprint = async () => {
            let canvasData = '';
            try {
                const c = document.createElement('canvas');
                c.width = 220; c.height = 40;
                const ctx = c.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '16px Arial';
                ctx.fillStyle = '#f60'; ctx.fillRect(100, 1, 62, 20);
                ctx.fillStyle = '#069'; ctx.fillText('PeoplesChoice☆', 2, 15);
                ctx.fillStyle = 'rgba(102,204,0,0.7)'; ctx.fillText('PeoplesChoice☆', 4, 17);
                canvasData = c.toDataURL();
            } catch (e) {}

            const parts = [
                navigator.userAgent, navigator.language, (navigator.languages || []).join(','),
                Intl.DateTimeFormat().resolvedOptions().timeZone,
                screen.width + 'x' + screen.height + 'x' + screen.colorDepth, window.devicePixelRatio,
                navigator.hardwareConcurrency || '', navigator.maxTouchPoints || '', navigator.platform || '',
                canvasData,
            ].join('||');

            try {
                const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(parts));
                return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
            } catch (e) {
                // Non-HTTPS fallback: two 32-bit FNV-1a style hashes.
                let h1 = 0x811c9dc5, h2 = 0x01000193;
                for (let i = 0; i < parts.length; i++) {
                    const ch = parts.charCodeAt(i);
                    h1 = Math.imul(h1 ^ ch, 16777619) >>> 0;
                    h2 = Math.imul(h2 ^ ch, 2246822519) >>> 0;
                }
                return h1.toString(16).padStart(8, '0') + h2.toString(16).padStart(8, '0');
            }
        };

        let pcLocal = null;
        try { pcLocal = localStorage.getItem('pc_device'); } catch (e) {}

        try {
            window.pcFp = await pcFingerprint();
        } catch (e) {
            window.pcFp = null;
        }

        // Sync the server-issued token with localStorage (restores the cookie if it was cleared, and vice versa).
        const pcToken = await $wire.initDevice(pcLocal, window.pcFp);
        try { if (pcToken) localStorage.setItem('pc_device', pcToken); } catch (e) {}
    </script>
@endscript
