<div>
    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Event</h5>
                        <p class="text-muted" style="font-size:0.875rem;">
                            The audience scans the QR code and votes once per device, either for a <strong>group</strong>
                            (from the <strong>Group / Team</strong> field in Reference &rarr; Participants) or for an
                            individual <strong>participant</strong>. Votes are separate from the judges' scores.
                        </p>
                        <select wire:model.live="selectedCategory" class="form-select">
                            <option value="">--- SELECT EVENT ---</option>
                            @foreach ($categories as $item)
                                <option value="{{ $item->category }}">{{ $item->description }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            @if ($slug)
                {{-- Controls + QR --}}
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title d-flex align-items-center gap-2">
                                Voting
                                @if ($isOpen)
                                    <span class="badge bg-success">OPEN</span>
                                @else
                                    <span class="badge bg-secondary">CLOSED</span>
                                @endif
                                @if ($isRevealed)
                                    <span class="badge bg-warning text-dark">WINNER REVEALED</span>
                                @endif
                            </h5>

                            <div class="mb-3">
                                <label class="form-label fw-semibold mb-1">Audience votes for</label>
                                <div class="btn-group w-100" role="group" aria-label="Vote target">
                                    <button type="button" wire:click="setMode('group')"
                                        class="btn {{ $mode === 'group' ? 'btn-primary' : 'btn-outline-primary' }}"
                                        @disabled($hasVotes && $mode !== 'group')>
                                        <i class="bi bi-people-fill"></i> Groups
                                    </button>
                                    <button type="button" wire:click="setMode('participant')"
                                        class="btn {{ $mode === 'participant' ? 'btn-primary' : 'btn-outline-primary' }}"
                                        @disabled($hasVotes && $mode !== 'participant')>
                                        <i class="bi bi-person-fill"></i> Participants
                                    </button>
                                </div>
                                @if ($hasVotes)
                                    <div class="form-text">Locked because votes exist. Reset votes to change it.</div>
                                @endif
                            </div>

                            <div class="d-grid gap-2 mb-3">
                                <button type="button" wire:click="toggleOpen"
                                    class="btn {{ $isOpen ? 'btn-outline-danger' : 'btn-success' }}">
                                    <i class="bi {{ $isOpen ? 'bi-stop-circle' : 'bi-play-circle' }}"></i>
                                    {{ $isOpen ? 'Close Voting' : 'Open Voting' }}
                                </button>
                                <button type="button" wire:click="toggleReveal"
                                    class="btn {{ $isRevealed ? 'btn-outline-secondary' : 'btn-warning' }}"
                                    @if (!$isRevealed) wire:confirm="Reveal the People's Choice winner on the LED display?" @endif>
                                    <i class="bi {{ $isRevealed ? 'bi-eye-slash' : 'bi-trophy' }}"></i>
                                    {{ $isRevealed ? 'Hide Winner on LED' : 'Reveal Winner on LED' }}
                                </button>
                            </div>

                            <div class="text-center">
                                <div class="pc-admin-qr mx-auto mb-2">{!! $qrSvg !!}</div>
                                <div class="small text-break mb-2">
                                    <a href="{{ $voteUrl }}" target="_blank">{{ $voteUrl }}</a>
                                </div>
                                <div class="d-flex flex-wrap justify-content-center gap-2">
                                    <a href="{{ $posterUrl }}" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="bi bi-printer"></i> Print Poster
                                    </a>
                                    <a href="{{ $qrPngUrl }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-download"></i> Download QR
                                    </a>
                                    <a href="{{ $displayUrl }}" target="_blank" class="btn btn-sm btn-outline-dark">
                                        <i class="bi bi-display"></i> LED Display
                                    </a>
                                </div>
                                @if (str_contains($voteUrl, 'localhost') || str_contains($voteUrl, '127.0.0.1'))
                                    <div class="alert alert-warning small mt-3 mb-0 text-start">
                                        This link points to <strong>localhost</strong>, so phones cannot open it.
                                        Open this page from the server's public address, or set <code>APP_URL</code> to it.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Live tally --}}
                <div class="col-lg-7">
                    <div class="card" wire:poll.5s>
                        <div class="card-body">
                            <h5 class="card-title d-flex justify-content-between align-items-center">
                                <span>Live Tally <small class="text-muted fw-normal">(admins only)</small></span>
                                <span class="badge bg-primary fs-6">{{ number_format($tally['total']) }} vote(s)</span>
                            </h5>

                            @if (session('pc_message'))
                                <div class="alert alert-info py-2">{{ session('pc_message') }}</div>
                            @endif

                            @if (empty($tally['rows']))
                                <div class="alert alert-warning mb-0">
                                    @if ($mode === 'group')
                                        No groups found for this event. Set a Group / Team on each participant in
                                        Reference &rarr; Participants, or switch to <strong>Participants</strong>.
                                    @else
                                        No participants found for this event. Add them in Reference &rarr; Participants.
                                    @endif
                                </div>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th width="10%">Rank</th>
                                                <th>{{ ucfirst($target) }}</th>
                                                <th width="14%" class="text-end">Votes</th>
                                                <th width="30%">Share</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($tally['rows'] as $row)
                                                <tr class="{{ $tally['total'] > 0 && $row['ordinal_rank'] == 1 ? 'table-warning fw-bold' : '' }}">
                                                    <td>{{ $tally['total'] > 0 ? $row['ordinal_rank'] : '-' }}</td>
                                                    <td>
                                                        <div class="text-uppercase">
                                                            @if (!empty($row['no']))<span class="text-primary">#{{ $row['no'] }}</span>@endif
                                                            {{ $row['name'] }}
                                                        </div>
                                                        @if ($row['removed'])
                                                            <div class="small text-danger fw-normal">No longer in the participant list</div>
                                                        @elseif (!empty($row['details']))
                                                            <div class="small text-muted fw-normal">{{ implode($mode === 'group' ? ', ' : ' • ', $row['details']) }}</div>
                                                        @endif
                                                    </td>
                                                    <td class="text-end">{{ number_format($row['votes']) }}</td>
                                                    <td>
                                                        <div class="progress" style="height:18px;">
                                                            <div class="progress-bar" role="progressbar" style="width: {{ $row['percent'] }}%;">
                                                                {{ $row['percent'] }}%
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if ($tally['shared_fingerprints'] > 0)
                                <div class="small text-muted mb-2">
                                    <i class="bi bi-info-circle"></i>
                                    {{ $tally['shared_fingerprints'] }} vote(s) share a browser fingerprint with another vote
                                    (possible repeat voter, or simply identical phone models).
                                </div>
                            @endif

                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="button" class="btn btn-primary" wire:click="generatePdf">
                                    <span wire:loading.remove wire:target="generatePdf"><i class="bi bi-filetype-pdf"></i> Generate PDF</span>
                                    <span wire:loading wire:target="generatePdf"><span class="spinner-border spinner-border-sm"></span></span>
                                </button>
                                <button type="button" class="btn btn-outline-danger ms-auto" wire:click="resetVotes"
                                    wire:confirm="Delete ALL People's Choice votes for this event? This cannot be undone.">
                                    <i class="bi bi-trash"></i> Reset Votes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <div class="modal fade" id="peoplesChoicePdfModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
        role="dialog" aria-labelledby="peoplesChoicePdfTitle" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="peoplesChoicePdfTitle">People's Choice Award Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if ($base64pdf)
                        <iframe src="data:application/pdf;base64,{{ $base64pdf }}" width="100%" height="600"
                            type="application/pdf" frameborder="0"></iframe>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .pc-admin-qr { width: 240px; max-width: 100%; background: #fff; padding: 8px; border: 1px solid #dee2e6; border-radius: 8px; }
        .pc-admin-qr svg { width: 100%; height: auto; display: block; }
        .pc-admin-qr svg .dark { fill: #000; }
    </style>
</div>
@script
    <script>
        window.addEventListener('openPeoplesChoicePdf', event => {
            var myModal = new bootstrap.Modal(document.getElementById('peoplesChoicePdfModal'));
            myModal.show();
        });
    </script>
@endscript
