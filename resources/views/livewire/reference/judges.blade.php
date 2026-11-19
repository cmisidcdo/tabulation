<section class="section judges">
    <div class="row">
        <div class="col-lg-12">
            <section class="section oratorical">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header toolbar mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        Judges
                                        <span class="badge text-bg-light border ms-1">{{ $judges->total() }}</span>
                                    </h5>
                                    <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-1" wire:click="addJudge" wire:loading.attr="disabled" wire:target="addJudge">
                                        <i class="bi bi-plus-lg" wire:loading.remove wire:target="addJudge"></i>
                                        <span class="spinner-border spinner-border-sm" role="status" wire:loading wire:target="addJudge">
                                            <span class="visually-hidden">Loading...</span>
                                        </span>
                                        Add Judge
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2 align-items-center mb-3">
                                        <div class="col-12 col-md">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                                <input type="search" wire:model.live.debounce.300ms="search" class="form-control" placeholder="Search name, judge no., or email" aria-label="Search judges">
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <select name="selectedCateg" wire:model.live="selectedCateg" class="form-select" id="selectedCateg" aria-label="Filter by event">
                                                <option value="">All Events</option>
                                                @foreach ($categories as $item)
                                                    <option value="{{ $item->category }}">{{ $item->description }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <select name="selectedStatus" wire:model.live="selectedStatus" class="form-select" id="selectedStatus" aria-label="Filter by status">
                                                <option value="">All Status</option>
                                                <option value="1">Active</option>
                                                <option value="0">Inactive</option>
                                            </select>
                                        </div>
                                        @if ($hasFilters)
                                            <div class="col-12 col-md-auto">
                                                <button type="button" class="btn btn-outline-secondary w-100" wire:click="clearFilters">
                                                    <i class="bi bi-x-lg"></i> Clear
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="table-responsive position-relative">
                                        <table class="table table-hover table-striped align-middle mb-2"
                                            wire:loading.class="opacity-50"
                                            wire:target="search,selectedCateg,selectedStatus,clearFilters,gotoPage,nextPage,previousPage,toggleStatus,executeDelete,saveJudge">
                                            <thead class="table-light">
                                                <tr>
                                                    <th scope="col" style="width:4%">#</th>
                                                    <th scope="col" style="width:10%">Judge No.</th>
                                                    <th scope="col">Judge Name</th>
                                                    <th scope="col">Events</th>
                                                    <th scope="col" style="width:10%" class="text-center">Status</th>
                                                    <th scope="col" style="width:14%" class="text-center text-nowrap">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($judges as $item)
                                                    <tr wire:key="judge-row-{{ $item->id }}" @class(['text-muted' => !$item->is_active])>
                                                        <td>{{ $judges->firstItem() + $loop->index }}</td>
                                                        <td>
                                                            @if (filled($item->nickname))
                                                                <span class="badge text-bg-secondary fs-6">#{{ $item->nickname }}</span>
                                                            @else
                                                                <span class="text-muted">&mdash;</span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            <span class="fw-semibold">{{ $item->judge }}</span>
                                                            @if ($item->user?->email)
                                                                <div class="small text-muted"><i class="bi bi-person-badge"></i> {{ $item->user->email }}</div>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @forelse ($item->category ?? [] as $cat)
                                                                <span class="badge text-bg-primary me-1 mb-1">{{ $categoryLabels[$cat] ?? ucfirst($cat) }}</span>
                                                            @empty
                                                                <span class="small text-muted">No events</span>
                                                            @endforelse
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="badge {{ $item->is_active ? 'text-bg-success' : 'text-bg-danger' }}">
                                                                {{ $item->is_active ? 'Active' : 'Inactive' }}
                                                            </span>
                                                        </td>
                                                        <td class="text-center text-nowrap">
                                                            <button wire:key="toggle-{{ $item->id }}" wire:target="toggleStatus({{ $item->id }})" wire:loading.attr="disabled" wire:click="toggleStatus({{ $item->id }})" class="btn btn-sm {{ $item->is_active ? 'btn-warning' : 'btn-success' }}" title="{{ $item->is_active ? 'Deactivate' : 'Activate' }}">
                                                                <div wire:loading.remove wire:target="toggleStatus({{ $item->id }})">
                                                                    <i class="bi {{ $item->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                                                </div>
                                                                <div wire:loading wire:target="toggleStatus({{ $item->id }})">
                                                                    <div class="spinner-border spinner-border-sm" role="status">
                                                                        <span class="visually-hidden">Loading...</span>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                            <button wire:key="edit-{{ $item->id }}" wire:target="editJudge({{ $item->id }})" wire:loading.attr="disabled" class="btn btn-sm btn-primary" wire:click="editJudge({{ $item->id }})">
                                                                <div wire:loading.remove wire:target="editJudge({{ $item->id }})">
                                                                    <i class="bi bi-pencil-square"></i>
                                                                </div>
                                                                <div wire:loading wire:target="editJudge({{ $item->id }})">
                                                                    <div class="spinner-border spinner-border-sm" role="status">
                                                                        <span class="visually-hidden">Loading...</span>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                            <button wire:key="delete-{{ $item->id }}" wire:target="deleteJudge({{ $item->id }})" wire:loading.attr="disabled" wire:click="deleteJudge({{ $item->id }})" class="btn btn-danger btn-sm">
                                                                <div wire:loading.remove wire:target="deleteJudge({{ $item->id }})">
                                                                    <i class="bi bi-trash"></i>
                                                                </div>
                                                                <div wire:loading wire:target="deleteJudge({{ $item->id }})">
                                                                    <div class="spinner-border spinner-border-sm" role="status">
                                                                        <span class="visually-hidden">Loading...</span>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr class="text-center">
                                                        <td colspan="6" class="text-muted py-4">
                                                            @if ($hasFilters)
                                                                No judges match your search or filters.
                                                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" wire:click="clearFilters">Clear filters</button>
                                                            @else
                                                                No judges yet. Click <strong>Add Judge</strong> to add one.
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                        @if ($judges->hasPages())
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                <small class="text-muted">Showing {{ $judges->firstItem() }}&ndash;{{ $judges->lastItem() }} of {{ $judges->total() }}</small>
                                                {{ $judges->links() }}
                                            </div>
                                        @endif
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
    <div class="modal fade" id="judgeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="exampleModalLabel">{{ $id ? 'Edit' : 'Add' }}</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="saveJudge">
                    <div class="modal-body">
                        @include('layouts.message')
                        <div class="mb-3">
                            <label for="NickName" class="form-label">Judge Number</label>
                            <input type="number" class="form-control" wire:model="nickname" id="NickName" placeholder="Enter judge's number">
                        </div>
                        <div class="mb-3">
                            <label for="judgeName" class="form-label">Judge Name</label>
                            <input type="text" class="form-control" wire:model.live.debounce.400ms="judge" id="judgeName" placeholder="e.g. June Sab Delacerna">
                            @if ($this->loginEmail)
                                <div class="form-text">
                                    <i class="bi bi-person-badge"></i> Login email: <strong>{{ $this->loginEmail }}</strong>
                                    @unless ($id)
                                        &middot; default password: <strong>password</strong>
                                    @endunless
                                </div>
                            @endif
                        </div>
                        <div class="mb-3">
                            <label for="category">Event Judge</label>
                            @foreach ($categories as $item)
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input w-5 h-5 text-blue-600 rounded focus:ring-blue-500" wire:model="selectedCategories" value="{{ $item->category }}">
                                    <label for="flexCheckDefault">
                                        {{ $item->description }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="mb-3">
                            <label for="is_active" class="form-label">Status</label>
                            <select wire:model="is_active" id="is_active" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            @error('is_active')
                                <span class="text-danger">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">
                            <div wire:loading.remove wire:target="saveJudge">
                                Save changes
                            </div>
                            <div wire:loading wire:target="saveJudge">
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
    <!-- Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="exampleModalLabel">Are you sure to delete this judge?</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('layouts.message')
                    <div class="mb-3">
                        <label for="password" class="col-form-label">Type Password</label>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" id="password" wire:model="password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger" wire:click="executeDelete()">Delete</button>
                </div>
            </div>
        </div>
    </div>
</section>
@script
    <script>
        window.addEventListener('openModal', event => {
            var myModal = new bootstrap.Modal(document.getElementById('judgeModal'));
            myModal.show();
        });
        window.addEventListener('hideModal', event => {
            var myModal = bootstrap.Modal.getInstance(document.getElementById('judgeModal'));
            if (myModal) myModal.hide();
        });
        window.addEventListener('openDeleteModal', event => {
            var myModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            myModal.show();
        });
        window.addEventListener('hideDeleteModal', event => {
            var myModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            myModal.hide();
        });
    </script>
@endscript
