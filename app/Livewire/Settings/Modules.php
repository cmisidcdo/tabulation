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

    # Participant order (bunot / draw) editor
    public ?int $orderCategoryId = null;
    public array $orderRows = [];

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

    /* ------------------------------------------------------------------
     | Participant order (bunot / draw)
     |
     | Rows are kept in participation order. Numbers belong to positions:
     | moving or shuffling a participant changes who holds each number,
     | while typing in a box changes that participant's number directly.
     * ------------------------------------------------------------------ */

    public function openOrder($id)
    {
        $module = Category::findOrFail($id);

        $this->orderCategoryId = $module->id;
        $this->orderRows = RefParticipant::category($module->category)->get()
            ->sortBy('participant_no', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn ($p) => [
                'id'         => $p->id,
                'no'         => (string) $p->participant_no,
                'original'   => (string) $p->participant_no,
                'typed'      => false, // true when the number was entered by hand
                'name'       => $p->participant,
                'school'     => $p->school,
                'categories' => count((array) $p->category),
            ])
            ->all();

        $this->resetValidation();
    }

    public function closeOrder()
    {
        $this->reset(['orderCategoryId', 'orderRows']);
        $this->resetValidation();
    }

    public function moveParticipant(int $index, int $direction)
    {
        $target = $index + ($direction < 0 ? -1 : 1);
        if (!isset($this->orderRows[$index], $this->orderRows[$target])) {
            return;
        }

        // Swap the participants but leave each position's number in place
        $numbers = $this->positionalNumbers();
        [$this->orderRows[$index], $this->orderRows[$target]] = [$this->orderRows[$target], $this->orderRows[$index]];
        $this->applyNumbers($numbers);
    }

    /** Random draw: shuffle who gets which of the current numbers. */
    public function shuffleOrder()
    {
        $numbers = $this->positionalNumbers();
        shuffle($this->orderRows);
        $this->applyNumbers($numbers);
    }

    /** Re-sort the rows by the numbers currently typed in. */
    public function sortByNumber()
    {
        $this->orderRows = collect($this->orderRows)
            ->sortBy('no', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** Number the rows 1..N in their current order (keeps zero-padding like "01" if used). */
    public function renumberSequential()
    {
        $width = collect($this->orderRows)
            ->pluck('original')
            ->filter(fn ($no) => preg_match('/^0\d+$/', $no))
            ->map(fn ($no) => strlen($no))
            ->max() ?? 0;

        foreach ($this->orderRows as $i => $row) {
            $this->orderRows[$i]['no'] = str_pad((string) ($i + 1), $width, '0', STR_PAD_LEFT);
            $this->orderRows[$i]['typed'] = false;
        }
        $this->resetValidation();
    }

    public function resetOrder()
    {
        if ($this->orderCategoryId) {
            $this->openOrder($this->orderCategoryId);
        }
    }

    public function saveOrder()
    {
        $module = Category::find($this->orderCategoryId);
        if (!$module) {
            return $this->closeOrder();
        }

        foreach ($this->orderRows as $i => $row) {
            $this->orderRows[$i]['no'] = trim((string) $row['no']);
        }

        $errors = [];
        $seen = [];
        foreach ($this->orderRows as $i => $row) {
            $key = mb_strtolower($row['no']);
            if ($error = $this->orderNumberError($i)) {
                $errors["orderRows.$i.no"] = $error;
            } elseif (isset($seen[$key])) {
                $errors["orderRows.$i.no"] = 'Duplicate number.';
                $errors["orderRows.{$seen[$key]}.no"] = 'Duplicate number.';
            } else {
                $seen[$key] = $i;
            }
        }
        if ($errors) {
            $this->resetValidation();
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }
            return;
        }

        $changed = collect($this->orderRows)->filter(fn ($row) => $row['no'] !== $row['original']);

        DB::transaction(function () use ($changed, $module) {
            foreach ($changed as $row) {
                RefParticipant::category($module->category)
                    ->whereKey($row['id'])
                    ->update(['participant_no' => $row['no']]);
            }
        });

        $this->closeOrder();
        session()->flash('status', $changed->isEmpty()
            ? 'No participant numbers changed.'
            : 'Updated ' . $changed->count() . ' participant number(s) for ' . $module->description . '.');
    }

    /** Check a typed number as soon as the field loses focus. */
    public function updatedOrderRows($value, $key)
    {
        if (!preg_match('/^(\d+)\.no$/', (string) $key, $m)) {
            return;
        }

        $i = (int) $m[1];
        if (!isset($this->orderRows[$i])) {
            return;
        }

        $no = trim((string) $value);
        $this->orderRows[$i]['no'] = $no;
        // Retyping the participant's own saved number isn't a new entry
        $this->orderRows[$i]['typed'] = $no !== $this->orderRows[$i]['original'];

        $this->resetValidation("orderRows.$i.no");
        if ($error = $this->orderNumberError($i)) {
            $this->addError("orderRows.$i.no", $error);
        }
    }

    /**
     * A number typed by hand must be a whole number from 1 to the participant count.
     * Saved numbers that are only moved around (arrows / random draw) are left alone,
     * so a category whose current numbers exceed the count can still be reordered.
     */
    private function orderNumberError(int $i): ?string
    {
        $row = $this->orderRows[$i] ?? null;
        $no = trim((string) ($row['no'] ?? ''));
        $max = count($this->orderRows);

        if ($no === '') {
            return 'Required.';
        }
        if (empty($row['typed'])) {
            return null;
        }
        if (!ctype_digit($no) || (int) $no < 1 || (int) $no > $max) {
            return "Must be 1 to $max.";
        }

        return null;
    }

    /** Numbers (and whether each was typed) by position, so they stay put when participants move. */
    private function positionalNumbers(): array
    {
        return array_map(fn ($row) => ['no' => $row['no'], 'typed' => $row['typed'] ?? false], $this->orderRows);
    }

    private function applyNumbers(array $numbers): void
    {
        foreach ($numbers as $i => $n) {
            $this->orderRows[$i]['no'] = $n['no'];
            $this->orderRows[$i]['typed'] = $n['typed'];
        }
        $this->resetValidation();
    }
}
