<div>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Group Ranking &amp; Best Speaker</h5>
            <p class="text-muted" style="font-size:0.875rem;">
                For group events (e.g. Storytelling). Each member is scored individually; the group score is the
                average of its members' scores, and the top member of each group is the Best Speaker.
                Only participants with a Group set are included.
            </p>
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-group">
                        <label for="groupCategory" class="form-label">Category</label>
                        <select wire:model="selectedCategory" id="groupCategory" class="form-select">
                            <option value="">--- SELECT ---</option>
                            @foreach ($categories as $item)
                                <option value="{{ $item->category }}">{{ $loop->iteration }} - {{ $item->description }}</option>
                            @endforeach
                        </select>
                        @error('selectedCategory')
                            <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="text-center pt-4">
                        <button type="button" class="btn btn-primary" wire:click="generateReport">
                            <div wire:loading.remove wire:target="generateReport">
                                <i class="bi bi-filetype-pdf"></i>
                                Generate Report
                            </div>
                            <div wire:loading wire:target="generateReport">
                                <div class="spinner-border spinner-border-sm" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="groupRankingModal" tabindex="-1" data-bs-backdrop="static"
        data-bs-keyboard="false" role="dialog" aria-labelledby="groupRankingModalTitle"
        aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupRankingModalTitle">Group Ranking Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if ($base64pdf)
                        <iframe src="data:application/pdf;base64,{{ $base64pdf }}"
                            width="100%" height="600" type="application/pdf" frameborder="0"></iframe>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>
@script
    <script>
        window.addEventListener('openGroupRankingModal', event => {
            var myModal = new bootstrap.Modal(document.getElementById('groupRankingModal'));
            myModal.show();
        });
    </script>
@endscript
