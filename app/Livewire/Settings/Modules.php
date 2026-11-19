<?php

namespace App\Livewire\Settings;

use App\Models\Category;
use App\Models\Setting;
use App\Models\RefCriteria;
use App\Models\RefParticipant;
use App\Models\RefJudge;
use App\Services\CategoryRenameService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;

class Modules extends Component
{
    use WithPagination;
    # Filter
    public $selectedStatus;
    public $search;

    # Properties
    public $category_id;
    public $category,
        $description,
        $display_participant,
        $is_active,
        $winners,
        $tabulation_mode = 'average';

    # Score sheet PDF preview
    public $base64pdf;

    public function rules()
    {
        return [
            'category' => 'required',
            'description' => 'required',
            'winners' => 'required',
            'is_active' => 'required',
            'display_participant' => 'required',
        ];
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['selectedStatus', 'search'])) {
            $this->resetPage();
        }
    }

    public function clear()
    {
        // Form only — keeps the page's search/status filters
        $this->resetForm();
    }

    public function addModule()
    {
        // Start from a clean form: a leftover category_id from a previous Edit would make
        // "Add" overwrite (and rename) that category instead of creating a new one.
        $this->resetForm();
        $this->dispatch('openModal');
    }

    private function resetForm(): void
    {
        $this->reset(['category_id', 'category', 'description', 'display_participant', 'is_active', 'winners', 'tabulation_mode']);
        $this->resetValidation();
    }

    public function render()
    {
        $data = [
            'modules' => $this->getModules(),
        ];

        return view('livewire.settings.modules', $data);
    }

    public function getModules()
    {
        $modules = Category::when($this->selectedStatus !== '' && $this->selectedStatus !== null, function ($query) {
            $query->where('is_active', $this->selectedStatus);
        })->when($this->search, function ($query) {
            $query->where(function ($q) {
                $q->where('category', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%');
            });
        })
            ->orderBy('id', 'desc')->paginate(5);

        return $modules;
    }

    public function saveModule()
    {
        $this->validate();

        $existing = $this->category_id ? Category::find($this->category_id) : null;
        if ($this->category_id && !$existing) {
            return session()->flash('error', 'This category no longer exists.');
        }

        // The slug is the link to every participant, judge, criteria and score of the category.
        // Keep a stored slug exactly as-is unless the user actually changed it, so editing only
        // the description never re-normalises it (e.g. "Street_Dance" → "street-dance").
        $slug = Str::slug($this->category);
        if ($existing && Str::slug($existing->category) === $slug) {
            $slug = $existing->category;
        }

        // Only check uniqueness when the code is new/changing, so a pre-existing duplicate
        // can still have its description, winners, etc. edited.
        if (!$existing || $existing->category !== $slug) {
            $this->validateSlug($slug);
        }

        try {
            $relinked = DB::transaction(function () use ($existing, $slug) {
                $relinked = [];
                if ($existing && $existing->category !== $slug) {
                    $relinked = CategoryRenameService::rename($existing->category, $slug);
                }

                $category = $existing ?? new Category(['icon' => '<i class="bi bi-box-seam"></i>']);
                $category->fill([
                    'category' => $slug,
                    'description' => $this->description,
                    'winners' => $this->winners,
                    'is_active' => $this->is_active,
                    'display_participant' => $this->display_participant,
                    'tabulation_mode' => $this->tabulation_mode,
                ])->save();

                return $relinked;
            });
        } catch (\Throwable $th) {
            report($th);
            return session()->flash('error', 'Failed to save: ' . $th->getMessage());
        }

        $message = 'Successfully saved!';
        if ($relinked) {
            $parts = collect($relinked)->map(fn($n, $table) => $n . ' ' . str_replace('_', ' ', $table))->implode(', ');
            $message .= ' Code changed to "' . $slug . '"; re-linked ' . $parts . '.';
        }

        $this->resetForm();
        $this->dispatch('hideModal');
        session()->flash('status', $message);
    }

    private function validateSlug(string $slug): void
    {
        Validator::make(
            ['category' => $slug],
            ['category' => ['required', Rule::unique('categories', 'category')->ignore($this->category_id)]],
            [
                'category.required' => 'The category code must contain letters or numbers.',
                'category.unique' => 'Another category already uses the code "' . $slug . '".',
            ]
        )->validate();
    }

    public function editModule($id)
    {
        $module = Category::findOrFail($id);
        $this->category_id = $module->id;
        $this->category = $module->category;
        $this->description = $module->description;
        $this->winners = $module->winners;
        $this->is_active = $module->is_active;
        $this->display_participant = $module->display_participant;
        $this->tabulation_mode = $module->tabulation_mode ?? 'average';

        $this->dispatch('openModal');
    }

    public function deactivateModule($id)
    {
        Category::where('id', $id)->update(['is_active' => 0]);
    }

    public function activateModule($id)
    {
        Category::where('id', $id)->update(['is_active' => 1]);
    }

    public function generateScoreSheet($id)
    {
        $module = Category::findOrFail($id);
        $slug = $module->category;

        $criterias    = RefCriteria::where('category', $slug)->get();
        $participants = RefParticipant::category($slug)->get()
            ->sortBy('participant_no', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $judges       = RefJudge::active()->category($slug)->orderByNumber()->get();

        $options = new Options();
        $options->set('isRemoteEnabled', false);

        $html = view('generated_pdf.blank-scoresheet', [
            'category'     => $module,
            'categoryName' => $module->description,
            'criterias'    => $criterias,
            'participants' => $participants,
            'judges'       => $judges,
        ])->render();

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        $this->base64pdf = base64_encode($dompdf->output());
        $this->dispatch('openScoreSheetModal');
    }

    public function toggleLock($id)
    {
        $category = Category::findOrFail($id);
        $key = 'scoring_locked_' . $category->category;
        $current = Setting::get($key, false);
        Setting::set($key, !$current, 'Scoring lock for ' . $category->description);
    }
}
