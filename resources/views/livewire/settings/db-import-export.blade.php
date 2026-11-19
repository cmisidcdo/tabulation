<div>
    <div>
        <div class="row">
            <div class="col-xl-6 col-lg-8">
                <div class="card shadow-lg border-0 rounded-4">
                    <div class="card-header bg-primary text-white text-center">
                        <h4 class="mb-0">API Database Download & Upload</h4>
                    </div>
                    <div class="card-body p-4">
                        @include('layouts.message')
                        <div class="mb-4">
                            <label for="api_url" class="form-label fw-bold">API Endpoint URL</label>
                            <div class="input-group">
                                <input type="url" class="form-control @error('api_url') is-invalid @enderror" wire:model="api_url" id="api_url" placeholder="https://example.com/api">
                                <button class="btn btn-primary" type="button" wire:click="saveApiUrl">
                                    <span wire:loading.remove wire:target="saveApiUrl">Save</span>
                                    <span wire:loading wire:target="saveApiUrl">
                                        <span class="spinner-border spinner-border-sm"></span>
                                    </span>
                                </button>
                            </div>
                            @error('api_url')
                                <span class="text-danger small">{{ $message }}</span>
                            @enderror
                        </div>
                        <hr />
                        @if (!session('token'))
                            <form wire:submit.prevent="login">
                                <div class="mb-4 text-center">
                                    <label for="" class="form-label">API Username</label>
                                    <input type="email" class="form-control" wire:model="email" id="email" placeholder="Enter email">
                                    @error('username')
                                        <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="mb-4 text-center">
                                    <label for="" class="form-label">API Password</label>
                                    <input type="password" class="form-control" wire:model="password" id="password" placeholder="Enter password">
                                    @error('username')
                                        <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="mb-4 text-center">
                                    <button type="submit" class="btn btn-primary px-4">Login</button>
                                </div>
                            </form>
                        @else
                            <div class="mb-4 text-center">
                                <button class="btn btn-danger px-4" wire:click="logout">Re-Login Api</button>
                            </div>
                            <hr />
                        @endif
                        @if (session('token'))
                            <div class="mb-4 text-center">
                                <div class="mb-4 text-center">
                                    <h6>Download Reference</h6>
                                    <button class="btn btn-success px-4" type="button" wire:click="getReference">
                                        <i class="bi bi-download me-1"></i> Download
                                    </button>
                                    <div class="text-danger small">Download all reference data, not the tabulation data.</div>
                                </div>
                                <hr />
                                <div class="mb-4 text-center">
                                    <h6>Upload Database</h6>
                                    <button class="btn btn-warning px-4" type="button" wire:click="uploadDatabase">
                                        <i class="bi bi-upload me-1"></i> Upload
                                    </button>
                                    <div class="text-danger small">Upload all tabulation data to the online database.</div>
                                </div>
                                <hr />
                                {{-- <div class="mb-4 text-center">
                                    <h6>Download Database</h6>
                                    <button class="btn btn-primary px-4" type="button" wire:click="downloadDatabase">
                                        <i class="bi bi-download me-1"></i> Download
                                    </button>
                                    <div class="text-danger small">Download online tabulation data to the local database.</div>
                                </div> --}}
                            </div>
                        @endif
                    </div>
                </div>
                <!-- Card -->
                <div class="card shadow-lg border-0 rounded-4">
                    <div class="card-header bg-primary text-white text-center rounded-top-4">
                        <h4 class="mb-0">Database Import & Export</h4>
                    </div>
                    <div class="card-body p-4">
                        @if (session()->has('message'))
                            <div class="alert alert-success">
                                {{ session('message') }}
                            </div>
                        @endif
                        <div class="mb-4 text-center">
                            <h6>Export Database</h6>
                        </div>
                        <div class="mb-4 text-center">
                            <h6>CATEGORY</h6>
                            <select name="category_name" id="category_name" wire:model.live="category_name" class="form-select @error('category_name') is-invalid @enderror">
                                <option value=""> --- SELECT CATEGORY ---</option>
                                @foreach ($categories as $item)
                                    <option value="{{ $item->category }}">{{ $loop->iteration }} - {{ $item->description }}</option>
                                @endforeach
                            </select>
                            @error('category_name')
                                <span class="text-danger">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="mb-4 text-center">
                            <h6>JUDGE</h6>
                            <select name="judge_id" id="judge_id" wire:model="judge_id" wire:change="updatedJudge" class="form-select @error('judge_id') is-invalid @enderror">
                                <option value=""> --- SELECT JUDGE ---</option>
                                @foreach ($judges as $item)
                                    <option value="{{ $item->id }}">{{ $loop->iteration }} - {{ $item->judge }}</option>
                                @endforeach
                            </select>
                            @error('judge_id')
                                <span class="text-danger">{{ $message }}</span>
                            @enderror
                        </div>
                        {{-- <div class="mb-4 text-center">
                            data found : {{ $count }}
                        </div> --}}
                        <!-- Export Section -->
                        <div class="mb-4 text-center">
                            <button class="btn btn-success px-4" type="button" wire:click="exportDatabase">
                                <i class="bi bi-download me-1"></i> Export Now
                            </button>
                        </div>
                        <hr>
                        <!-- Import Section -->
                        <div class="text-center">
                            <h6>Import Database</h6>
                            <form method="POST" wire:submit.prevent="importDatabase" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <input type="file" class="form-control @error('excel_file') is-invalid @enderror" wire:model="excel_file">
                                    @error('excel_file')
                                        <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                                <button class="btn btn-primary px-4" type="submit">
                                    <i class="bi bi-upload me-1"></i> Import
                                </button>
                            </form>
                        </div>

                        @if (auth()->user()->role === 'admin')
                            <hr>
                            <!-- Offline judge app backup -->
                            <div>
                                <h6 class="text-center">Import offline judge app backup</h6>
                                <p class="text-muted small text-center">
                                    For a judge whose device couldn't upload: in the judge app, Menu → <em>Export backup file</em>, then import the <code>.json</code> file here.
                                </p>
                                <form wire:submit.prevent="importJudgeBackup">
                                    <div class="mb-2">
                                        <input type="file" accept=".json,application/json" class="form-control @error('backup_file') is-invalid @enderror" wire:model="backup_file">
                                        @error('backup_file')
                                            <span class="text-danger small">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="backup_force" wire:model="backup_force">
                                        <label class="form-check-label small" for="backup_force">
                                            Overwrite scores that were changed on the server after the judge downloaded the event
                                        </label>
                                    </div>
                                    <div class="text-center">
                                        <button class="btn btn-primary px-4" type="submit" wire:loading.attr="disabled" wire:target="backup_file,importJudgeBackup">
                                            <i class="bi bi-upload me-1"></i> Import backup
                                        </button>
                                    </div>
                                </form>

                                @if ($backupReport)
                                    <div class="alert alert-info mt-3 mb-0 small">
                                        <div class="fw-semibold mb-1">
                                            {{ $backupReport['judge'] }}
                                            @if ($backupReport['exported_at'])
                                                · exported {{ \Illuminate\Support\Carbon::parse($backupReport['exported_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}
                                            @endif
                                        </div>
                                        @foreach ($backupReport['events'] as $ev)
                                            <div>
                                                <strong>{{ $ev['event'] }}:</strong>
                                                {{ $ev['counts']['saved'] }} saved, {{ $ev['counts']['same'] }} already up to date
                                                @if ($ev['counts']['conflict'])
                                                    , <span class="text-danger">{{ $ev['counts']['conflict'] }} changed on the server (not overwritten; tick the box above to overwrite)</span>
                                                @endif
                                                @if ($ev['counts']['rejected'])
                                                    , <span class="text-danger">{{ $ev['counts']['rejected'] }} rejected: {{ implode(', ', $ev['errors']) }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
