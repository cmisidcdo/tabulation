<section class="section quiz">
    <div class="row">
        <div class="col-lg-12">
            <section class="section oratorical">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="col-lg-10 mx-auto">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Add Criteria</h5>
                                    <div class="toolbar mb-3 d-flex justify-content-end">
                                        <button type="button" class="btn btn-primary" wire:click="addCriteria">
                                            <div wire:loading.remove wire:target="addCriteria">
                                                <i class="bi bi-plus-circle"></i>
                                            </div>
                                            <div wire:loading wire:target="addCriteria">
                                                <div class="spinner-border spinner-border-sm" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                    <div class="table-responsive">
                                        <!-- Table with hoverable rows -->
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th scope="col">#</th>
                                                    <th scope="col">Name</th>
                                                    <th scope="col">Category</th>
                                                    <th scope="col">Perfect Score</th>
                                                    <th scope="col">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($criterias as $item)
                                                <tr>
                                                    <th scope="row">
                                                        {{$loop->iteration}}
                                                    </th>
                                                    <th scope="row">
                                                        {{$item->criteria}}
                                                    </th>
                                                    <th scope="row" class="text-capitalize">
                                                        {{$item->category}}
                                                    </th>
                                                    <td>
                                                        {{$item->perfect_score}}
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary" wire:click="editCriteria({{$item->id}})">
                                                            <div wire:loading.remove wire:target="editCriteria({{$item->id}})">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </div>
                                                            <div wire:loading wire:target="editCriteria({{$item->id}})">
                                                                <div class="spinner-border spinner-border-sm" role="status">
                                                                    <span class="visually-hidden">Loading...</span>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        <!-- End Table with hoverable rows -->
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
                    <h1 class="modal-title fs-5" id="exampleModalLabel">Add</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form wire:submit.prevent="saveCriteria">
                    <div class="modal-body">
                        @include('layouts.message')
                        <div class="mb-3">
                            <label for="criteria" class="form-label">Criteria Name</label>
                            <input type="text" class="form-control" wire:model="criteria" id="criteria" placeholder="Enter criteria name">
                        </div>
                        <div class="mb-3">
                            <label for="category">Category</label>
                            <select wire:model="category" id="category" class="form-select">
                                <option value="">--- SELECT ---</option>
                                <option value="oral">Oral</option>
                                <option value="poster">Poster</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="perfect_score" class="form-label">Perfect Score</label>
                            <input type="text" class="form-control" wire:model="perfect_score" id="perfect_score" placeholder="Enter criteria perfect score">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">
                            <div wire:loading.remove wire:target="saveCriteria">
                                Save changes
                            </div>
                            <div wire:loading wire:target="saveCriteria">
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
</section>
@script
<script>
    window.addEventListener('openModal', event => {
        var myModal = new bootstrap.Modal(document.getElementById('criteriaModal'));
        myModal.show();
    });

    window.addEventListener('hideModal', event => {
        var myModal = new bootstrap.Modal(document.getElementById('criteriaModal'));
        myModal.hide();
    });
</script>
@endscript