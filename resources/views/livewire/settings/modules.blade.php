<section class="section quiz">
    <div class="row">
        <div class="col-lg-12">
            <section class="section oratorical">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header toolbar mb-3  d-flex justify-content-between align-items-center">
                                    <h5 class="card-title">Add Module</h5>
                                    <div class="d-flex">
                                        <div class="mx-2">
                                            <input type="search" wire:model.live="search" class="form-control" placeholder="Search...">
                                        </div>
                                        <div class="mx-2">
                                            <select name="selectedStatus" wire:model.live="selectedStatus" class="form-select" id="selectedStatus">
                                                <option value="">--- Status (All) ---</option>
                                                <option value="1">Active</option>
                                                <option value="0">Inactive</option>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-primary" wire:click="addModule">
                                            <div wire:loading.remove wire:target="addModule">
                                                <i class="bi bi-plus-circle"></i>
                                            </div>
                                            <div wire:loading wire:target="addModule">
                                                <div class="spinner-border spinner-border-sm" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    @if (session('status'))
                                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                                            {{ session('status') }}
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    @endif
                                    <div class="table-responsive">
                                        <!-- Table with hoverable rows -->
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th scope="col">#</th>
                                                    <th scope="col">Category</th>
                                                    <th scope="col">Name</th>
                                                    <th scope="col" class="text-center">Winners</th>
                                                    <th scope="col">Show Participant?</th>
                                                    <th scope="col">Tabulation</th>
                                                    <th scope="col">Status</th>
                                                    <th scope="col">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($modules as $item)
                                                    <tr>
                                                        <td>{{ ($modules->currentPage() - 1) * $modules->perPage() + $loop->iteration }}</td>
                                                        <td class="text-capitalize">
                                                            {{ $item->category }}
                                                        </td>
                                                        <td>
                                                            {{ $item->description }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $item->winners }}
                                                        </td>
                                                        <td>
                                                            <span class="badge {{ $item->display_participant == 1 ? 'text-bg-success' : 'text-bg-danger' }}">
                                                                {{ $item->display_participant == 1 ? 'Shown' : 'Hidden' }}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            @php
                                                                $mode = $item->tabulation_mode ?? 'average';
                                                                $modeLabels = ['technical' => 'Technical', 'oral' => 'Oral', 'poster' => 'Poster', 'quiz' => 'Quizbowl', 'average' => 'Average'];
                                                                $modeColors = ['technical' => 'text-bg-primary', 'oral' => 'text-bg-info', 'poster' => 'text-bg-warning', 'quiz' => 'text-bg-success'];
                                                            @endphp
                                                            <span class="badge {{ $modeColors[$mode] ?? 'text-bg-secondary' }}">
                                                                {{ $modeLabels[$mode] ?? 'Average' }}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge {{ $item->is_active == 1 ? 'text-bg-success' : 'text-bg-danger' }}">
                                                                {{ $item->is_active == 1 ? 'Active' : 'Inactive' }}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group" aria-label="Basic example">
                                                                <button type="button" class="btn btn-primary" wire:click="editModule({{ $item->id }})">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button type="button" class="btn {{ $item->is_active == 1 ? 'btn-danger' : 'btn-success' }}" wire:click="{{ $item->is_active == 1 ? 'deactivateModule(' . $item->id . ')' : 'activateModule(' . $item->id . ')' }}">
                                                                    <i class="bi {{ $item->is_active == 1 ? 'bi-trash' : 'bi bi-arrow-counterclockwise' }} "></i>
                                                                </button>
                                                                @php $isLocked = \App\Models\Setting::get('scoring_locked_' . $item->category, false); @endphp
                                                                <button type="button" class="btn {{ $isLocked ? 'btn-warning' : 'btn-outline-secondary' }}" wire:click="toggleLock({{ $item->id }})" title="{{ $isLocked ? 'Unlock Scoring' : 'Lock Scoring' }}">
                                                                    <i class="bi {{ $isLocked ? 'bi-lock-fill' : 'bi-unlock' }}"></i>
                                                                </button>
                                                                <button wire:key="order-{{ $item->id }}" type="button" class="btn btn-outline-success" wire:click="openOrder({{ $item->id }})" title="Participant order / numbers (bunot)">
                                                                    <div wire:loading.remove wire:target="openOrder({{ $item->id }})">
                                                                        <i class="bi bi-sort-numeric-down"></i>
                                                                    </div>
                                                                    <div wire:loading wire:target="openOrder({{ $item->id }})">
                                                                        <span class="spinner-border spinner-border-sm" role="status"></span>
                                                                    </div>
                                                                </button>
                                                                <button wire:key="generate-{{ $item->id }}" type="button" class="btn btn-outline-primary" wire:click="generateScoreSheet({{ $item->id }})" title="Generate blank score sheet">
                                                                    <div wire:loading.remove wire:target="generateScoreSheet({{ $item->id }})">
                                                                        <i class="bi bi-file-earmark-pdf"></i>
                                                                    </div>
                                                                    <div wire:loading wire:target="generateScoreSheet({{ $item->id }})">
                                                                        <span class="spinner-border spinner-border-sm" role="status">
                                                                            <span class="visually-hidden">Loading...</span>
                                                                        </span>
                                                                    </div>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        <!-- End Table with hoverable rows -->
                                        <div>
                                            {{ $modules->links() }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="criteriaModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="exampleModalLabel">{{ $category_id ? 'Edit' : 'Add' }}</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="saveModule">
                    <div class="modal-body">
                        @include('layouts.message')
                        <div class="mb-3">
                            <label for="category" class="form-label">Category Code (URL)</label>
                            <input type="text" class="form-control" wire:model="category" id="category" placeholder="e.g. street-dancing">
                            <div class="form-text">
                                Used in links and to connect participants, judges, criteria and scores.
                                @if ($category_id)
                                    Changing it re-links all of them to the new code.
                                @endif
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description Name</label>
                            <input type="text" class="form-control" wire:model="description" id="description" placeholder="Enter description name">
                        </div>
                        <div class="mb-3">
                            <label for="winners" class="form-label">Winner/s</label>
                            <input type="number" class="form-control" wire:model="winners" id="winners" placeholder="Enter winners count">
                        </div>
                        <div class="mb-3">
                            <label for="display_participant">Show Participant?</label>
                            <select wire:model="display_participant" id="display_participant" class="form-select">
                                <option value="">--- SELECT ---</option>
                                <option value="1">Shown</option>
                                <option value="0">Hidden</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="tabulation_mode">Tabulation Mode</label>
                            <select wire:model="tabulation_mode" id="tabulation_mode" class="form-select">
                                <option value="average">Average (standard multi-judge scoring)</option>
                                <option value="technical">Technical (each judge scores assigned criteria, scores are totalled)</option>
                                <option value="oral">Oral Presentation</option>
                                <option value="poster">Poster Making Contest</option>
                                <option value="quiz">Quizbowl</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="is_active">Is Active?</label>
                            <select wire:model="is_active" id="is_active" class="form-select">
                                <option value="">--- SELECT ---</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">
                            <div wire:loading.remove wire:target="saveModule">
                                Save changes
                            </div>
                            <div wire:loading wire:target="saveModule">
                                <div class="spinner-border spinner-border-sm" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Score Sheet PDF Modal -->
    <div class="modal fade" id="scoreSheetModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5">Blank Score Sheet</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if ($base64pdf)
                        <iframe src="data:application/pdf;base64,{{ $base64pdf }}" width="100%" height="600" type="application/pdf" frameborder="0"></iframe>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Participant Order (bunot) Modal -->
    @if ($orderCategoryId)
        @php $orderModule = \App\Models\Category::find($orderCategoryId); @endphp
        <div class="modal d-block" tabindex="-1" style="background: rgba(0, 0, 0, .5);" wire:key="order-modal">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5">
                            Participant Order — {{ $orderModule?->description }}
                        </h1>
                        <button type="button" class="btn-close" wire:click="closeOrder"></button>
                    </div>
                    <div class="modal-body">
                        @if (empty($orderRows))
                            <div class="text-center text-muted py-4">No participants in this category.</div>
                        @else
                            <p class="text-muted small mb-2">
                                Type the drawn number (1 to {{ count($orderRows) }}) for each participant, or use the arrows to move a participant
                                up/down (each position keeps its number). Nothing is saved until you click
                                <strong>Save Order</strong>.
                            </p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="sortByNumber">
                                    <i class="bi bi-sort-numeric-down"></i> Sort by number
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="renumberSequential"
                                    wire:confirm="Renumber everyone 1, 2, 3… in the order shown?">
                                    <i class="bi bi-123"></i> Renumber 1…{{ count($orderRows) }}
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" wire:click="shuffleOrder"
                                    wire:confirm="Randomly reassign the current numbers to the participants?">
                                    <i class="bi bi-shuffle"></i> Random draw
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" wire:click="resetOrder">
                                    <i class="bi bi-arrow-counterclockwise"></i> Undo changes
                                </button>
                            </div>

                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="width: 130px;">Participant No.</th>
                                        <th>Participant</th>
                                        <th class="text-end" style="width: 90px;">Move</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($orderRows as $i => $row)
                                        @php $isChanged = (string) $row['no'] !== $row['original']; @endphp
                                        <tr wire:key="order-row-{{ $i }}" class="{{ $isChanged ? 'table-warning' : '' }}">
                                            <td class="text-muted">{{ $i + 1 }}</td>
                                            <td>
                                                <input type="number" min="1" max="{{ count($orderRows) }}" step="1"
                                                    wire:model.blur="orderRows.{{ $i }}.no"
                                                    class="form-control form-control-sm @error('orderRows.' . $i . '.no') is-invalid @enderror">
                                                @error('orderRows.' . $i . '.no')
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                                @if ($isChanged)
                                                    <small class="text-muted">was {{ $row['original'] !== '' ? $row['original'] : '—' }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="fw-semibold">{{ $row['name'] }}</div>
                                                @if ($row['school'])
                                                    <small class="text-muted">{{ $row['school'] }}</small>
                                                @endif
                                                @if ($row['categories'] > 1)
                                                    <div>
                                                        <small class="text-warning-emphasis" title="Participant numbers are shared across categories">
                                                            <i class="bi bi-exclamation-triangle"></i>
                                                            Also in {{ $row['categories'] - 1 }} other categor{{ $row['categories'] - 1 === 1 ? 'y' : 'ies' }} — number changes there too.
                                                        </small>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary" title="Move up"
                                                        wire:click="moveParticipant({{ $i }}, -1)" @disabled($loop->first)>
                                                        <i class="bi bi-arrow-up"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary" title="Move down"
                                                        wire:click="moveParticipant({{ $i }}, 1)" @disabled($loop->last)>
                                                        <i class="bi bi-arrow-down"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeOrder">Cancel</button>
                        @if (!empty($orderRows))
                            <button type="button" class="btn btn-primary" wire:click="saveOrder">
                                <span wire:loading.remove wire:target="saveOrder">Save Order</span>
                                <span wire:loading wire:target="saveOrder">
                                    <span class="spinner-border spinner-border-sm"></span> Saving...
                                </span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
@script
    <script>
        window.addEventListener('openModal', event => {
            var myModal = new bootstrap.Modal(document.getElementById('criteriaModal'));
            myModal.show();
        });

        window.addEventListener('hideModal', event => {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('criteriaModal')).hide();
        });

        // Closing the modal any way (Esc, backdrop, buttons) must clear the form; otherwise a
        // leftover category_id turns the next "Add" into an edit that renames that category.
        document.getElementById('criteriaModal').addEventListener('hidden.bs.modal', () => {
            $wire.clear();
        });

        window.addEventListener('openScoreSheetModal', event => {
            var scoreSheetModal = new bootstrap.Modal(document.getElementById('scoreSheetModal'));
            scoreSheetModal.show();
        });
    </script>
@endscript
