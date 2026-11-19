<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Higalaay as ModelsHigalaay;
use App\Models\HigalaayDeduction;
use App\Models\Log;
use App\Models\RefCriteria;
use App\Models\RefDeduction;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Higalaay extends Component
{
    #[Locked]
    public $type;
    #[Locked]
    public $winner;
    public $search, $selectedParticipant, $judge_id, $criteria_id, $base64pdf = '';
    public $showDropdown = false;
    public $suggestions = [];
    public $selectedGroup = '';
    public $deductionModel, $refDeductions = [];
    public $remarks, $duration, $oldDeductions, $totalDeductions = 0, $deductionSelected;

    /** Per-request memo of the scoring lock (not persisted between requests). */
    protected ?bool $lockedMemo = null;

    public function render()
    {
        $isAdmin = Auth::user()->role == 'admin';
        $selected = $this->selectedParticipant;
        $group = trim((string) $this->selectedGroup);

        // One query for every participant in the category: gives the group list and the
        // participant count used by the judge progress bars.
        $allParticipants = RefParticipant::category($this->type)->get(['id', 'group_name']);
        $groups = $allParticipants->pluck('group_name')
            ->map(fn($g) => trim((string) $g))
            ->filter(fn($g) => $g !== '')
            ->unique(fn($g) => mb_strtolower($g))
            ->sort(fn($a, $b) => strnatcasecmp($a, $b))
            ->values();

        $participants = RefParticipant::when(!empty($selected), function ($query) use ($selected) {
            $query->where('id', $selected);
        })->when($group !== '', function ($query) use ($group) {
            $query->whereRaw('LOWER(TRIM(group_name)) = ?', [mb_strtolower($group)]);
        })->category($this->type)->get()
            ->sortBy('participant_no', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // Group events (e.g. Storytelling): keep each group's members together.
        if ($groups->isNotEmpty()) {
            $participants = $participants->sort(function ($a, $b) {
                $ga = trim((string) $a->group_name);
                $gb = trim((string) $b->group_name);
                // Members without a group go last
                if (($ga === '') !== ($gb === '')) return $ga === '' ? 1 : -1;
                return strnatcasecmp($ga, $gb) ?: strnatcasecmp((string) $a->participant_no, (string) $b->participant_no);
            })->values();
        }

        $criterias = RefCriteria::where('category', $this->type)->get();

        // Active judges of the category; the visible columns are a subset of these.
        $jud = RefJudge::active()->category($this->type)->get();
        if ($isAdmin) {
            $judges = $this->judge_id ? $jud->where('id', (int) $this->judge_id)->values() : $jud;
        } else {
            $judges = $jud->where('user_id', Auth::id())->values();
        }

        // Preload every score and deduction shown on the page (replaces per-cell queries).
        // Ordered newest-first so keyBy() keeps the oldest row, matching ->first() in saveScore.
        $participantIds = $participants->pluck('id');
        $scoreRows = ModelsHigalaay::where('category', $this->type)
            ->whereIn('participant_id', $participantIds)
            ->orderByDesc('id')
            ->get(['id', 'participant_id', 'criteria_id', 'judge_id', 'score']);
        $scoreMap = $scoreRows->keyBy(fn($s) => $s->participant_id . '-' . $s->criteria_id . '-' . $s->judge_id);
        $scoresByParticipant = $scoreRows->groupBy('participant_id');
        $deductions = HigalaayDeduction::where('category', $this->type)
            ->whereIn('participant_id', $participantIds)
            ->orderByDesc('id')
            ->get()
            ->keyBy('participant_id');

        // Card totals and completion (scored cells over visible criteria x judge columns)
        $myJudgeId = $isAdmin ? null : Auth::user()->judge?->id;
        $cellsPerCard = $criterias->count() * $judges->count();
        $cards = [];
        foreach ($participants as $p) {
            $done = 0;
            foreach ($criterias as $c) {
                foreach ($judges as $j) {
                    if (isset($scoreMap[$p->id . '-' . $c->id . '-' . $j->id])) $done++;
                }
            }
            $rows = $scoresByParticipant->get($p->id, collect());
            $cards[$p->id] = [
                'done' => $done,
                'total_cells' => $cellsPerCard,
                'complete' => $cellsPerCard > 0 && $done >= $cellsPerCard,
                'total' => $isAdmin
                    ? RefParticipant::computeHigalaayTotal($rows, $criterias, $jud->count(), true, null, $deductions->get($p->id)?->deduction ?? 0)
                    : $rows->where('judge_id', $myJudgeId)->sum('score'),
            ];
        }

        // Judge progress: one grouped count instead of 3 queries per judge
        $possible = $allParticipants->count() * $criterias->count();
        $scoredByJudge = ModelsHigalaay::where('category', $this->type)
            ->whereNotNull('score')
            ->whereIn('judge_id', $judges->pluck('id'))
            ->groupBy('judge_id')
            ->selectRaw('judge_id, COUNT(*) as c')
            ->pluck('c', 'judge_id');
        $judgeProgress = $judges->mapWithKeys(fn($j) => [
            $j->id => $possible > 0 ? bong_format(($scoredByJudge[$j->id] ?? 0) / $possible * 100) : 0,
        ]);

        $categoryName = Category::where('category', $this->type)->select('description', 'display_participant')->first();
        $this->calculateTotals();
        $locked = $this->isLocked();
        return view('livewire.higalaay', compact('jud', 'judges', 'participants', 'criterias', 'categoryName', 'locked', 'groups', 'scoreMap', 'deductions', 'cards', 'judgeProgress', 'isAdmin'));
    }

    private function isLocked(): bool
    {
        return $this->lockedMemo ??= !empty(\App\Models\Setting::get('scoring_locked_' . $this->type));
    }

    private function isAdmin(): bool
    {
        return Auth::user()->role == 'admin';
    }

    public function updatedSearch()
    {
        if (strlen($this->search) > 2) {

            $term = '%' . $this->search . '%';
            $this->suggestions  = RefParticipant::where(function ($q) use ($term) {
                $q->where('participant', 'like', $term)->orWhere('group_name', 'like', $term);
            })->category($this->type)
                ->limit(5) // Limit suggestions
                ->get();
            $this->showDropdown = true;
        } else {
            $this->suggestions = [];
            $this->selectedParticipant = null;
            $this->showDropdown = false;
        }
    }
    public function selectSuggestion($id)
    {
        $selectedItem = RefParticipant::find($id);
        if ($selectedItem) {
            $this->search = $selectedItem->participant;
            $this->selectedParticipant = $id;
            $this->showDropdown = false;
        }
    }
    /**
     * Save one score cell. Returns ['ok' => bool, 'score' => saved value, 'error' => message]
     * so the page can show per-cell feedback.
     */
    public function saveScore($participant_id, $criteria_id, $judge_id, $score)
    {
        if (!$this->isAdmin() && $this->isLocked()) {
            return ['ok' => false, 'error' => 'Scoring is locked.'];
        }

        // The judge column must be an active judge of this category; a non-admin may only
        // write into their own column (judge_id comes from the browser and can't be trusted).
        $judge = RefJudge::whereKey($judge_id)->active()->category($this->type)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', Auth::id()))
            ->first();
        $criteria = RefCriteria::whereKey($criteria_id)->where('category', $this->type)->first();
        $participantOk = RefParticipant::whereKey($participant_id)->category($this->type)->exists();
        if (!$judge || !$criteria || !$participantOk) {
            return ['ok' => false, 'error' => 'You cannot score this cell.'];
        }

        // Blank saves 0 (intended); clamp to 0..perfect_score
        $value = is_numeric($score) ? (float) $score : 0;
        $value = max(0, $value);
        if ($criteria->perfect_score !== null && $value > $criteria->perfect_score) {
            $value = (float) $criteria->perfect_score;
        }

        $higalaay = ModelsHigalaay::firstOrNew([
            'participant_id' => $participant_id,
            'category' => $this->type,
            'criteria_id' => $criteria->id,
            'judge_id' => $judge->id,
        ]);
        $oldScore = $higalaay->score;
        $higalaay->score = $value;
        $higalaay->save();

        Log::create([
            'user_id' => Auth::user()->id,
            'activity' => $this->type . ' Higalaay id ' . $higalaay->id . ' Score has been updated from ' . $oldScore . ' to ' . $higalaay->score,
        ]);

        return ['ok' => true, 'score' => $value];
    }
    public function generateReport()
    {
        $paper = array(0, 0, 850, 1400);
        $category = $this->type;
        $winner =  $this->winner;

        $service = new ReportService($category, $this->criteria_id);
        $participants = $service->generateTopParticipants();
        $judges =  $service->judges;

        $criteria = RefCriteria::find($this->criteria_id);
        $categoryName = Category::where('category', $this->type)->pluck('description')->first();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $htmlContent = view('generated_pdf.higalaay', compact('participants', 'judges', 'category', 'criteria', 'categoryName', 'winner'))->render();
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('folio', 'landscape');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont("Arial", "normal");
        $size = 10;
        $canvas->page_text(
            830,                 // X position
            570,                 // Y position
            "Page {PAGE_NUM} of {PAGE_COUNT}",
            $font,
            $size,
            [0, 0, 0], // Color in RGB [0, 0, 0]
        );

        $this->base64pdf = base64_encode($dompdf->output());
        $this->dispatch('openModal');
    }
    public function saveDeduction($participant_id, $score)
    {
        // Deductions are an admin-only control (the inputs are only rendered for admins)
        if (!$this->isAdmin()) {
            return;
        }
        if (!RefParticipant::whereKey($participant_id)->category($this->type)->exists()) {
            return;
        }
        $deduction = HigalaayDeduction::where('category', $this->type)->where('participant_id', $participant_id)->first();
        if (!$deduction) {
            $deduction = new HigalaayDeduction();
            $deduction->participant_id = $participant_id;
        }
        $deduction->deduction = is_numeric($score) ? max(0, $score) : 0;
        $deduction->category = $this->type;
        $deduction->save();
    }
    public function showDeductionDetails($participant_id)
    {
        if (!$this->isAdmin()) {
            return;
        }
        $this->resetErrorBag();
        $this->deductionSelected = $participant_id;

        $deduction = HigalaayDeduction::where('category', $this->type)->firstOrNew(['participant_id' => $participant_id]);
        $existingDetails = $deduction->deduction_details ?? [];

        // Create a lookup map for existing details [id => checked]
        $detailMap = collect($existingDetails)->pluck('checked', 'id')->all();

        // Fetch reference deductions with checked status
        $this->refDeductions = RefDeduction::where('category', $this->type)
            ->get()
            ->map(function ($deduction) use ($detailMap) {
                return [
                    'id' => $deduction->id,
                    'deduction_name' => $deduction->deduction_name,
                    'deduction' => $deduction->deduction,
                    'checked' => $detailMap[$deduction->id] ?? false,
                    // Include other fields you need
                ];
            })
            ->toArray();

        $this->deductionModel = $deduction;
        $this->oldDeductions = $deduction->deduction;
        $this->remarks = $deduction->remarks;
        $this->duration = $deduction->duration;
        $this->dispatch('openDetailsModal');
    }

    public function updated()
    {
        $this->calculateTotals();
    }
    private function calculateTotals()
    {
        if ($this->refDeductions)
            $this->totalDeductions =  collect($this->refDeductions)
                ->where('checked', false)
                ->sum('deduction');
    }
    public function saveCustomDeductions()
    {
        if (!$this->isAdmin()) {
            return;
        }
        $this->calculateTotals();
        // Remarks/duration explain a deduction; not needed when nothing is deducted
        $needsReason = $this->totalDeductions > 0;
        $this->validate([
            'refDeductions' => 'array',
            'remarks' => $needsReason ? 'required' : 'nullable',
            'duration' => $needsReason ? 'required' : 'nullable',
        ]);

        $deduction = HigalaayDeduction::where('category', $this->type)->where('participant_id',  $this->deductionSelected)->first();
        if (!$deduction) {
            $deduction = new HigalaayDeduction();
            $deduction->participant_id =  $this->deductionSelected;
        }
        $deduction->deduction = $this->totalDeductions ? $this->totalDeductions : 0;
        $deduction->deduction_details = $this->refDeductions;
        $deduction->category = $this->type;
        $deduction->remarks = $this->remarks;
        $deduction->duration = $this->duration;
        $deduction->save();

        return session()->flash('status', 'Sucessfully updated!');
    }
}
