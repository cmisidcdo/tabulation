<section class="section">
    <div class="row">
        <div class="col-lg-12">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between">
                            <h5 class="text-uppercase">{{ $categoryName?->description }} SCORE TABLE</h5>
                        </div>
                    </div>
                    <div class="card-body">
                        @php $isDisabled = $locked && !$isAdmin; @endphp
                        @if ($isDisabled)
                            <div class="alert alert-warning text-center fw-bold mb-3">
                                <i class="bi bi-lock-fill me-1"></i> Scoring is locked. You can view results but cannot modify scores.
                            </div>
                        @endif
                        <div class="row d-flex justify-content-center my-3">
                            <div class="col-md-4 mb-3">
                                <label for="search">PARTICIPANT</label>
                                <input type="search" wire:model.live.debounce.300ms="search" name="search" id="search" class="form-control" placeholder="Search participant...." autocomplete="off">
                                @if ($showDropdown && count($suggestions) > 0)
                                    <ul class="list-group">
                                        @foreach ($suggestions as $item)
                                            <li wire:click="selectSuggestion({{ $item->id }})" class="list-group-item list-group-item-action" style="cursor: pointer">
                                                {{ $item->participant_no }} - {{ $item->participant }}@if ($item->group_name) &mdash; <span class="text-muted">{{ $item->group_name }}</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            @if ($groups->isNotEmpty())
                                <div class="col-md-4 mb-3">
                                    <label for="selectedGroup">GROUP</label>
                                    <select wire:model.live="selectedGroup" id="selectedGroup" class="form-select">
                                        <option value="">ALL GROUPS</option>
                                        @foreach ($groups as $g)
                                            <option value="{{ $g }}">{{ $g }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if ($isAdmin)
                                <div class="col-md-4 mb-3">
                                    <label for="judge_id">JUDGES</label>
                                    <select name="judge_id" wire:model.live="judge_id" class="form-select" id="judge_id">
                                        <option value="">ALL</option>
                                        @foreach ($jud as $item)
                                            <option value="{{ $item->id }}">{{ $item->judge }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                        {{-- Judge progress bar strip --}}
                        <div class="d-flex gap-3 flex-wrap mb-3 px-1">
                            @foreach ($judges as $item)
                                @php $percent = $judgeProgress[$item->id] ?? 0; @endphp
                                <div class="flex-grow-1" style="min-width:160px;">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="fw-semibold">{{ $item->judge }}</small>
                                        <small class="text-muted">{{ $percent }}%</small>
                                    </div>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-success" style="width:{{ $percent }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @php
                            $hasSeg = $criterias->whereNotNull('segment')->isNotEmpty();
                            $segments = $hasSeg
                                ? $criterias->whereNotNull('segment')->pluck('segment')->unique()->values()
                                : collect();
                        @endphp

                        <div x-data="{ activeSeg: '', allRevealed: true, unfinishedOnly: false }">
                        {{-- Toolbar: segment filter tabs (only when segments exist) + view toggles --}}
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                            <div class="d-flex gap-2 flex-wrap">
                                @if ($hasSeg)
                                    <button class="btn btn-sm"
                                        :class="activeSeg === '' ? 'btn-primary' : 'btn-outline-secondary'"
                                        @click="activeSeg = ''">
                                        ALL SEGMENTS
                                    </button>
                                    @foreach ($segments as $seg)
                                        @php $sw = $criterias->firstWhere('segment', $seg)?->segment_weight; @endphp
                                        <button class="btn btn-sm"
                                            :class="activeSeg === @js($seg) ? 'btn-primary' : 'btn-outline-secondary'"
                                            @click="activeSeg = @js($seg)">
                                            {{ $seg }}
                                            @if ($sw)
                                                <span class="badge bg-warning text-dark ms-1">{{ $sw }}%</span>
                                            @endif
                                        </button>
                                    @endforeach
                                @endif
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm"
                                    :class="unfinishedOnly ? 'btn-warning' : 'btn-outline-secondary'"
                                    @click="unfinishedOnly = !unfinishedOnly"
                                    title="Hide participants whose scores are complete">
                                    <i class="bi bi-funnel"></i>
                                    <span x-text="unfinishedOnly ? 'Showing unfinished only' : 'Show unfinished only'">Show unfinished only</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                    @click="allRevealed = !allRevealed">
                                    <i class="bi" :class="allRevealed ? 'bi-eye-slash' : 'bi-eye'"></i>
                                    <span x-text="allRevealed ? 'Hide all scores' : 'Show all scores'">Hide all scores</span>
                                </button>
                            </div>
                        </div>
                        @unless ($isDisabled)
                            <div class="small text-muted mb-3">
                                <i class="bi bi-keyboard"></i> Press <kbd>Enter</kbd> or <kbd>&darr;</kbd> for the next criteria, <kbd>&uarr;</kbd> to go back. Scores save automatically.
                            </div>
                        @endunless

                        {{-- Participant scoring cards --}}
                        <div class="scoring-cards" :class="{ 'unfinished-only': unfinishedOnly }"
                            wire:loading.class="opacity-50 pe-none"
                            wire:target="selectedGroup,judge_id,selectSuggestion,search">
                            @php
                                $groupKey = fn($p) => mb_strtolower(trim((string) $p->group_name));
                                $groupCounts = $groups->isNotEmpty() ? $participants->countBy($groupKey) : collect();
                                $groupComplete = $groups->isNotEmpty()
                                    ? $participants->groupBy($groupKey)->map(fn($ms) => $ms->every(fn($m) => $cards[$m->id]['complete']))
                                    : collect();
                                $prevGroup = null;
                                $judgeCount = count($judges);
                                $criteriaWidth = match(true) {
                                    $judgeCount <= 1 => '60%',
                                    $judgeCount <= 2 => '40%',
                                    $judgeCount <= 3 => '30%',
                                    default          => '220px',
                                };
                                $grouped = $hasSeg
                                    ? $criterias->groupBy(fn($c) => $c->segment ?? '')
                                    : collect(['' => $criterias]);
                            @endphp
                            @forelse ($participants as $participant)
                                @php $card = $cards[$participant->id]; @endphp
                                @if ($groups->isNotEmpty() && $groupKey($participant) !== $prevGroup)
                                    @php $prevGroup = $groupKey($participant); @endphp
                                    <div class="group-section-header d-flex align-items-center gap-2 mb-2 mt-3 px-3 py-2 rounded"
                                        wire:key="hg-{{ md5($prevGroup) }}"
                                        data-complete="{{ $groupComplete[$prevGroup] ? 1 : 0 }}">
                                        <i class="bi bi-people-fill"></i>
                                        <span class="fw-bold text-uppercase">{{ trim((string) $participant->group_name) ?: 'No group' }}</span>
                                        <span class="small">&middot; {{ $groupCounts[$prevGroup] }} {{ $groupCounts[$prevGroup] == 1 ? 'member' : 'members' }}</span>
                                    </div>
                                @endif
                                <div class="card mb-3 shadow-sm border-0 participant-card"
                                    wire:key="hp-{{ $participant->id }}"
                                    data-complete="{{ $card['complete'] ? 1 : 0 }}"
                                    x-data="{ revealed: true }"
                                    x-effect="revealed = allRevealed"
                                    :class="{ 'scores-hidden': !revealed }">

                                    {{-- Card header: participant identity + progress + totals --}}
                                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 bg-white border-bottom">
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="badge bg-primary fs-6 px-3 py-2">#{{ $participant->participant_no }}</span>
                                            @if (trim((string) $participant->group_name) !== '')
                                                <span class="badge bg-info text-dark text-uppercase">{{ trim($participant->group_name) }}</span>
                                            @endif
                                            @if ($categoryName?->display_participant || $isAdmin)
                                                <span class="fw-bold fs-6">{{ $participant->participant }}</span>
                                            @endif
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            @if ($card['total_cells'] > 0)
                                                @php
                                                    $progressCls = $card['complete'] ? 'bg-success' : ($card['done'] == 0 ? 'bg-secondary' : 'bg-warning text-dark');
                                                @endphp
                                                <span class="badge {{ $progressCls }}" title="Scored cells">
                                                    @if ($card['complete'])<i class="bi bi-check-lg"></i>@endif
                                                    {{ $card['done'] }}/{{ $card['total_cells'] }}
                                                </span>
                                            @endif
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                @click="revealed = !revealed"
                                                :title="revealed ? 'Hide scores' : 'Show scores'">
                                                <i class="bi" :class="revealed ? 'bi-eye-slash' : 'bi-eye'"></i>
                                            </button>
                                            <span class="total-mask-wrap position-relative">
                                                <span class="text-success fw-bold fs-5" x-show="revealed">{{ bong_format($card['total']) }}</span>
                                                <span class="text-success fw-bold fs-5 total-mask" x-show="!revealed" aria-hidden="true">••••</span>
                                            </span>
                                            @if ($isAdmin)
                                                @php
                                                    $deduction = $deductions->get($participant->id);
                                                    $fromChecklist = !empty($deduction?->deduction_details);
                                                @endphp
                                                <div class="d-flex align-items-center gap-1">
                                                    <small class="text-muted me-1">Deduction</small>
                                                    <div class="input-group input-group-sm" style="width:140px;">
                                                        <input type="number" min="0" inputmode="numeric"
                                                            wire:change="saveDeduction({{ $participant->id }},$event.target.value)"
                                                            value="{{ $deduction ? $deduction->deduction : '' }}"
                                                            class="form-control"
                                                            @if ($fromChecklist) title="Set from the deduction checklist; typing here overrides it" @endif>
                                                        <button class="btn btn-sm {{ $fromChecklist ? 'btn-outline-primary' : 'btn-outline-secondary' }}"
                                                            wire:click="showDeductionDetails({{ $participant->id }})"
                                                            title="Deduction checklist">
                                                            <i class="bi {{ $fromChecklist ? 'bi-list-check' : 'bi-three-dots' }}"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Score grid: criteria rows × judge columns --}}
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm mb-0 scoring-table">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="criteria-col px-3 py-2 text-muted fw-semibold" style="width:{{ $criteriaWidth }};">CRITERIA</th>
                                                        @foreach ($judges as $judge)
                                                            <th class="text-center py-2">
                                                                <div class="fw-semibold">{{ $judge->judge }}</div>
                                                                <small class="text-muted">Judge #{{ $judge->nickname }}</small>
                                                            </th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($grouped as $segmentName => $segCriterias)
                                                        @if ($hasSeg && $segmentName !== '')
                                                            @php $segWeight = $segCriterias->first()->segment_weight; @endphp
                                                            <tr class="segment-header-row"
                                                                x-show="activeSeg === '' || activeSeg === @js($segmentName)">
                                                                <td colspan="{{ $judgeCount + 1 }}" class="px-3 py-2 fw-bold text-uppercase text-white bg-secondary small">
                                                                    {{ $segmentName }}
                                                                    @if ($segWeight)
                                                                        <span class="badge bg-warning text-dark ms-2">{{ $segWeight }}%</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endif
                                                        @foreach ($segCriterias as $criteria)
                                                            <tr x-show="@js($criteria->segment ?? '') === '' || activeSeg === '' || activeSeg === @js($criteria->segment ?? '')">
                                                                <td class="px-3 py-2 align-middle bg-light">
                                                                    <div class="fw-semibold criteria-label">{{ $criteria->criteria }}</div>
                                                                    <small class="text-muted">Max: {{ $criteria->perfect_score }}</small>
                                                                </td>
                                                                @foreach ($judges as $judge)
                                                                    @php $score = $scoreMap[$participant->id . '-' . $criteria->id . '-' . $judge->id] ?? null; @endphp
                                                                    <td class="text-center align-middle p-2">
                                                                        <div class="score-input-wrap position-relative">
                                                                            <input type="number"
                                                                                wire:key="hs-{{ $participant->id }}-{{ $criteria->id }}-{{ $judge->id }}"
                                                                                class="form-control form-control-lg text-center fw-bold score-input {{ $score ? 'scored' : '' }}"
                                                                                data-p="{{ $participant->id }}"
                                                                                data-c="{{ $criteria->id }}"
                                                                                data-j="{{ $judge->id }}"
                                                                                data-score-args="[{{ $participant->id }},{{ $criteria->id }},{{ $judge->id }}]"
                                                                                data-max="{{ $criteria->perfect_score }}"
                                                                                data-label="{{ $criteria->criteria }}"
                                                                                aria-label="{{ $criteria->criteria }} — {{ $judge->judge }}"
                                                                                value="{{ $score ? $score->score : '' }}"
                                                                                placeholder="—"
                                                                                inputmode="decimal"
                                                                                step="any"
                                                                                min="0"
                                                                                max="{{ $criteria->perfect_score }}"
                                                                                autocomplete="off"
                                                                                {{ $isDisabled ? 'disabled' : '' }} />
                                                                            @if ($score)
                                                                                <div class="score-mask" x-show="!revealed" aria-hidden="true">••••</div>
                                                                            @endif
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                        @endforeach
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center text-muted py-5">No participants found.</div>
                            @endforelse
                            @if ($participants->isNotEmpty())
                                <div class="all-finished-note text-center text-success py-4">
                                    <i class="bi bi-check-circle-fill"></i> Every participant shown here is fully scored.
                                </div>
                            @endif
                        </div>
                        </div>{{-- end x-data toolbar state --}}
                        <div class="score-toast" wire:ignore role="status" aria-live="polite"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="paticipantDetails" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" role="dialog" aria-labelledby="modalTitleId" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleId">
                        Deduction Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('layouts.message')
                    <div class="mb-3">
                        <label for="deduction-participant" class="form-label">Participant</label>
                        <input type="text" readonly class="form-control-plaintext fs-3" id="deduction-participant" value="{{ $deductionModel?->participant?->participant }}">
                    </div>
                    @if ($refDeductions && count($refDeductions) > 0)
                        <div class="alert alert-info py-2 small mb-2">
                            <i class="bi bi-info-circle"></i> Check each item the performance <strong>complied with</strong>. Unchecked items are deducted.
                        </div>
                        <div class="mb-3 card">
                            <div class="card-body">
                                <div class="row p-3">
                                    <div class="col-md-8">
                                        @foreach ($refDeductions as $index => $item)
                                            <div class="form-check">
                                                <input type="checkbox" id="deduction-{{ $item['id'] }}" wire:model.live="refDeductions.{{ $index }}.checked" class="form-check-input w-5 h-5 text-blue-600 rounded focus:ring-blue-500 mb-3">
                                                <label for="deduction-{{ $item['id'] }}" class="ml-3 font-medium">{{ $item['deduction_name'] }}
                                                    <span class="badge {{ $item['checked'] ? 'bg-light text-muted' : 'bg-danger' }} ms-1">-{{ $item['deduction'] }}</span>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <span>DEDUCTION</span>
                                        @if ($totalDeductions == 0)
                                            <h1 class="text-success">{{ $totalDeductions }}</h1>
                                        @else
                                            <h1 class="text-danger">-{{ $totalDeductions }}</h1>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="mb-3">
                        <label for="deduction-duration" class="form-label">Duration in minutes @if ($totalDeductions > 0)<small class="text-muted">(required when there is a deduction)</small>@endif</label>
                        <input type="text" wire:model="duration" class="form-control @error('duration') is-invalid @enderror" id="deduction-duration">
                        @error('duration')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="deduction-remarks" class="form-label">Remarks @if ($totalDeductions > 0)<small class="text-muted">(required when there is a deduction)</small>@endif</label>
                        <textarea id="deduction-remarks" wire:model="remarks" class="form-control mt-2 @error('remarks') is-invalid @enderror" rows="2"></textarea>
                        @error('remarks')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Close
                    </button>
                    <button class="btn btn-primary" wire:click="saveCustomDeductions">
                        <div wire:loading.remove wire:target="saveCustomDeductions">
                            Update Deduction
                        </div>
                        <div wire:loading wire:target="saveCustomDeductions">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <style>
        /* Scoring card */
        .participant-card {
            border-radius: 10px;
            overflow: hidden;
        }
        .group-section-header {
            background: #e7f1fb;
            color: #1d4e7a;
            border-left: 4px solid #266da7;
        }
        .participant-card .card-header {
            background: #f8f9fa;
        }

        /* Score input */
        .score-input {
            border-radius: 8px;
            font-size: 1.2rem;
            min-width: 80px;
            max-width: 120px;
            margin: 0 auto;
            border-color: #dee2e6;
            transition: border-color .15s, background .15s;
        }
        .score-input:focus {
            border-color: var(--app-accent, #1d4ed8);
            box-shadow: 0 0 0 3px rgba(29,78,216,.15);
        }
        .score-input.scored {
            background: #f0fff4;
            border-color: #28a745;
            color: #155724;
        }

        /* Criteria column */
        .scoring-table .criteria-col {
            min-width: 180px;
        }

        /* Sticky criteria header inside each card */
        .scoring-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
        }

        /* Score masking */
        .score-input-wrap {
            display: inline-block;
        }
        .score-mask {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            letter-spacing: .15rem;
            color: #155724;
            background: #f0fff4;       /* match .score-input.scored background so it reads as a filled cell */
            border: 1px solid #28a745;
            border-radius: 8px;
            pointer-events: none;       /* clicks pass through to the input so the judge can still focus/edit */
            user-select: none;
        }
        /* When card is hidden, blank the real digits as a fallback (covers focus state) */
        .scores-hidden .score-input {
            color: transparent;
            text-shadow: none;
        }
        .scores-hidden .score-input:focus {
            color: inherit;            /* reveal real value only while actively editing */
        }

        /* Per-cell save feedback (classes toggled by the script below) */
        .score-input.is-saving {
            border-color: var(--app-accent, #1d4ed8);
            background-image: linear-gradient(90deg, transparent, rgba(29,78,216,.12), transparent);
            background-size: 200% 100%;
            animation: score-saving 1s linear infinite;
        }
        .score-input.is-saved {
            border-color: #198754;
            box-shadow: 0 0 0 3px rgba(25,135,84,.25);
        }
        .score-input.is-error {
            border-color: #dc3545;
            background: #fff5f5;
            box-shadow: 0 0 0 3px rgba(220,53,69,.2);
        }
        /* Saved on this device only, waiting for the connection (score-outbox.js) */
        .score-input.is-pending,
        .score-input.is-pending:focus {
            border-color: #d97706;
            border-style: dashed;
            background: #fffbeb;
            box-shadow: 0 0 0 3px rgba(217,119,6,.22);
        }
        @keyframes score-saving { from { background-position: 100% 0; } to { background-position: -100% 0; } }
        /* Out-of-range while typing */
        .score-input:invalid {
            border-color: #dc3545;
        }

        /* "Show unfinished only" filter: hide fully scored cards/groups */
        .all-finished-note { display: none; }
        .unfinished-only .participant-card[data-complete="1"],
        .unfinished-only .group-section-header[data-complete="1"] {
            display: none !important;
        }
        .unfinished-only:not(:has(.participant-card[data-complete="0"])) .all-finished-note {
            display: block;
        }

        /* Toast for clamp / error messages */
        .score-toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translate(-50%, 20px);
            z-index: 1080;
            max-width: 90vw;
            padding: .6rem 1rem;
            border-radius: 8px;
            color: #fff;
            background: #343a40;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(0,0,0,.2);
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s, transform .2s;
        }
        .score-toast.show { opacity: 1; transform: translate(-50%, 0); }
        .score-toast.warning { background: #b8860b; }
        .score-toast.danger { background: #dc3545; }
        .score-toast.success { background: #198754; }
    </style>
</section>
@assets
    <link rel="stylesheet" href="{{ asset('css/responsive-table.css') }}" />
@endassets
@script
    <script>
        window.addEventListener('openDetailsModal', event => {
            var myDetailsModal = new bootstrap.Modal(document.getElementById('paticipantDetails'));
            myDetailsModal.show();
        });

        // ---- Score cells: save, feedback and keyboard navigation (event delegation) ----
        const root = $wire.$el;
        const toastEl = root.querySelector('.score-toast');
        let toastTimer;
        const isScoreInput = (el) => el && el.classList && el.classList.contains('score-input');

        function toast(msg, kind = 'warning') {
            if (!toastEl) return;
            toastEl.textContent = msg;
            toastEl.className = 'score-toast show ' + kind;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2800);
        }

        // Save on change (blur / Enter-navigation after an edit). The outbox keeps the score on
        // this device until the server confirms it, so a Wi-Fi drop doesn't lose it.
        ScoreOutbox.bind({
            root,
            wire: $wire,
            scope: @js('higalaay:' . $type),
            toast,
            beforeSave(el) {
                const max = parseFloat(el.dataset.max);
                const v = parseFloat(el.value);
                if (isNaN(v)) return;
                if (v < 0) {
                    el.value = 0;
                    toast('Scores cannot be negative. Saved as 0.');
                } else if (!isNaN(max) && v > max) {
                    el.value = max;
                    toast(`${el.dataset.label}: the maximum is ${max}. Saved as ${max}.`);
                }
            },
        });

        // Enter / ArrowDown → next criteria for the same judge (continues into the next participant),
        // Shift+Enter / ArrowUp → previous. Also stops arrows from nudging the number.
        root.addEventListener('keydown', (e) => {
            const el = e.target;
            if (!isScoreInput(el)) return;
            let dir = 0;
            if (e.key === 'Enter') dir = e.shiftKey ? -1 : 1;
            else if (e.key === 'ArrowDown') dir = 1;
            else if (e.key === 'ArrowUp') dir = -1;
            else return;
            e.preventDefault();

            const column = [...root.querySelectorAll(`.score-input[data-j="${el.dataset.j}"]`)]
                .filter((i) => !i.disabled && i.offsetParent !== null); // skip hidden segments / filtered cards
            const next = column[column.indexOf(el) + dir];
            if (next) {
                next.focus();
                next.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                el.blur(); // last cell: commit the value
            }
        });

        // Select the whole value on focus so typing replaces it
        root.addEventListener('focusin', (e) => {
            if (isScoreInput(e.target)) setTimeout(() => e.target.select(), 0);
        });

        // Scrolling the page over a focused cell must not change its value
        root.addEventListener('wheel', (e) => {
            if (isScoreInput(e.target) && document.activeElement === e.target) e.target.blur();
        }, { passive: true });
    </script>
@endscript
