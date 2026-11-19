<div>
    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Report Header — Text</h5>
                        @if (session('textSaved'))
                            <div class="alert alert-success py-2">{{ session('textSaved') }}</div>
                        @endif
                        <div class="row g-3">
                            <div class="col-sm-12">
                                <label class="form-label fw-bold">Event Title Prefix</label>
                                <input type="text" class="form-control" wire:model="headerTitle"
                                    placeholder="e.g. PASKO DE ORO 2025">
                                <small class="text-muted">Appears as the first bold line. Category/criteria name is
                                    appended automatically per report.</small>
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label fw-bold">Default Venue</label>
                                <input type="text" class="form-control" wire:model="headerVenue"
                                    placeholder="e.g. Amphitheater - Capistrano - Gaerlan Streets">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-bold">Default Time</label>
                                <input type="text" class="form-control" wire:model="headerTime"
                                    placeholder="e.g. 6:30 AM – 10:00 AM">
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label fw-bold">Alternate Venue <small class="text-muted">(for
                                        bangga-sa-daygon)</small></label>
                                <input type="text" class="form-control" wire:model="headerVenueAlt"
                                    placeholder="e.g. Cagayan de Oro City Hall Building - Tourism Hall">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-bold">Alternate Time</label>
                                <input type="text" class="form-control" wire:model="headerTimeAlt"
                                    placeholder="e.g. 4:00 PM">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" wire:click="saveText" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveText">Save Text</span>
                                <span wire:loading wire:target="saveText">
                                    <span class="spinner-border spinner-border-sm"></span> Saving...
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Report Header — Images</h5>
                        @if (session('imagesSaved'))
                            <div class="alert alert-success py-2">{{ session('imagesSaved') }}</div>
                        @endif
                        @foreach ($slots as $slot)
                            @error($slot['prop'] . 'Upload')
                                <div class="alert alert-danger py-1">{{ $message }}</div>
                            @enderror
                        @endforeach

                        <div class="row g-3">
                            @foreach ($slots as $settingKey => $slot)
                                @php
                                    $current = $this->{$slot['prop']};
                                    $pending = $this->{$slot['prop'] . 'Upload'};
                                @endphp
                                <div class="col-sm-4" wire:key="slot-{{ $settingKey }}">
                                    <label class="form-label fw-bold">{{ $slot['label'] }}</label>
                                    @if ($current)
                                        <div class="mb-2 d-flex align-items-center gap-2">
                                            <img src="{{ asset('storage/report-header/' . $current) }}" height="50"
                                                style="object-fit:contain;">
                                            <button class="btn btn-sm btn-outline-danger"
                                                wire:click="removeImage('{{ $settingKey }}')">Remove</button>
                                        </div>
                                    @endif
                                    @if ($pending)
                                        <img src="{{ $pending->temporaryUrl() }}" height="50" class="mb-2"
                                            style="object-fit:contain;">
                                    @endif
                                    <input type="file" class="form-control" wire:model="{{ $slot['prop'] }}Upload"
                                        accept="image/*">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
                                        wire:click="openPicker('{{ $settingKey }}')">
                                        <i class="bi bi-images"></i> Choose from library
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3">
                            <button class="btn btn-primary" wire:click="saveImages" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="saveImages">Save Images</span>
                                <span wire:loading wire:target="saveImages">
                                    <span class="spinner-border spinner-border-sm"></span> Uploading...
                                </span>
                            </button>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- Image library picker --}}
    @if ($pickerFor)
        <div class="modal d-block" tabindex="-1" style="background: rgba(0, 0, 0, .5);" wire:key="image-picker">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Choose image for {{ $slots[$pickerFor]['label'] }}</h5>
                        <button type="button" class="btn-close" wire:click="closePicker"></button>
                    </div>
                    <div class="modal-body">
                        @php $selected = $this->{$slots[$pickerFor]['prop']}; @endphp
                        <div class="row g-3">
                            @forelse ($this->libraryImages as $img)
                                @php
                                    $usedBy = collect($slots)
                                        ->filter(fn ($s) => $this->{$s['prop']} === $img['name'])
                                        ->pluck('label');
                                @endphp
                                <div class="col-6 col-md-3" wire:key="lib-{{ $img['name'] }}">
                                    <div class="card h-100 mb-0 {{ $selected === $img['name'] ? 'border border-primary border-2' : '' }}">
                                        <div class="p-2 text-center bg-light" role="button"
                                            wire:click="selectExisting(@js($img['name']))" title="Use this image">
                                            <img src="{{ asset('storage/report-header/' . $img['name']) }}"
                                                style="height:90px; max-width:100%; object-fit:contain;">
                                        </div>
                                        <div class="card-body p-2">
                                            <small class="d-block text-truncate text-muted" title="{{ $img['name'] }}">
                                                {{ $img['name'] }}
                                            </small>
                                            @foreach ($usedBy as $label)
                                                <span class="badge bg-info text-dark">{{ $label }}</span>
                                            @endforeach
                                            <div class="d-flex gap-1 mt-2">
                                                <button type="button" class="btn btn-sm btn-primary flex-fill"
                                                    wire:click="selectExisting(@js($img['name']))">Use</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    title="Delete from library"
                                                    wire:click="deleteFromLibrary(@js($img['name']))"
                                                    wire:confirm="Permanently delete this image?{{ $usedBy->isNotEmpty() ? ' It will also be removed from: ' . $usedBy->implode(', ') . '.' : '' }}">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="col-12 text-center text-muted py-4">
                                    No images uploaded yet. Upload one first and it will appear here.
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closePicker">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>