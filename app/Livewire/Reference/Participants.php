<?php

namespace App\Livewire\Reference;

use App\Models\Category;
use App\Models\PosterOutput;
use Livewire\Component;
use App\Models\RefParticipant;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Participants extends Component
{
    use WithFileUploads, WithPagination;

    public $poster_file;
    public $id, $participant, $school, $participant_no, $participant_id, $selectedCateg, $password, $gender, $group_name;
    public $selectedCategories = [];
    public $poster_photos;
    /** The participant number the form last filled in automatically (null once the user types their own). */
    public $suggestedNo;

    protected $rules = [
        'poster_file' => 'required|file|max:51200|mimes:jpg,png,jpeg,heic',
    ];

    protected $messages = [
        'poster_file.required' => 'Please select a file to upload',
        'poster_file.max' => 'File size must be less than 50MB',
        'mimes' => 'Invalid file type. Allowed: JPG, PNG, JPEG, HEIC',
    ];

    public function render()
    {
        $participants = RefParticipant::orderBy('category', 'asc')->orderBy('id', 'desc')->paginate(10,  pageName: 'participant-page');
        if ($this->selectedCateg) {
            $participants = RefParticipant::whereJsonContains('category', $this->selectedCateg)->orderBy('category', 'asc')->paginate(10,  pageName: 'participant-page');
        }
        $categories = Category::where('is_active', 1)->get();
        return view('livewire.reference.participants', [
            'participants' => $participants,
            'categories' => $categories
        ]);
    }

    public function saveParticipant()
    {
        $this->validate([
            'participant_no' => 'required',
            'participant' => 'required',
            'selectedCategories' => 'array',
            'selectedCategories.*' => 'exists:categories,category',
            'school' => 'required'
        ]);
        $isAdding = !$this->id;

        $participant = $this->id ? RefParticipant::find($this->id) : new RefParticipant();
        $participant->participant = $this->participant;
        $participant->category = $this->selectedCategories;
        $participant->participant_no = $this->participant_no;
        $participant->school = $this->school;
        $participant->gender = $this->gender ?: null;
        $participant->group_name = trim((string) $this->group_name) ?: null;
        $participant->save();

        if ($isAdding) {
            // Keep the modal open for the next participant: same events, school, group and
            // gender; blank name; participant number after the one just saved.
            $savedName = $this->participant;
            $savedNumber = is_numeric($this->participant_no) ? (int) $this->participant_no : null;

            $this->reset('id', 'participant');
            $this->resetValidation();
            $this->participant_no = $this->suggestedNo = $this->nextParticipantNumber($savedNumber);
            $this->dispatch('focusField', field: 'participantsName');

            return session()->flash("status", "Saved #{$participant->participant_no} {$savedName}. Ready for the next participant.");
        }

        return session()->flash("status", "Successfully saved");
    }
    public function addParticipant()
    {
        $this->resetExcept('selectedCateg');
        $this->resetValidation();
        $this->selectedCategories = $this->selectedCateg ? [$this->selectedCateg] : [];
        $this->participant_no = $this->suggestedNo = $this->nextParticipantNumber();
        $this->dispatch('openModal');
    }

    /** While adding, re-suggest the number for the chosen events unless the user typed their own. */
    public function updatedSelectedCategories()
    {
        if (!$this->id && (string) $this->participant_no === (string) $this->suggestedNo) {
            $this->participant_no = $this->suggestedNo = $this->nextParticipantNumber();
        }
    }

    /**
     * Next free participant number within the selected events (all participants when none
     * are selected): one after $after (the participant just saved) or, when opening the
     * form, one after the highest existing number. Numbers already taken are skipped.
     */
    private function nextParticipantNumber(?int $after = null): int
    {
        $categories = array_filter((array) $this->selectedCategories);

        $taken = RefParticipant::query()
            ->when($categories, function ($query) use ($categories) {
                $query->where(function ($q) use ($categories) {
                    foreach ($categories as $category) {
                        $q->orWhereJsonContains('category', $category);
                    }
                });
            })
            ->pluck('participant_no')
            ->filter(fn ($n) => is_numeric($n))
            ->map(fn ($n) => (int) $n)
            ->all();

        $next = $after !== null ? $after + 1 : (max($taken ?: [0]) + 1);
        while (in_array($next, $taken, true)) {
            $next++;
        }
        return $next;
    }
    public function editParticipant($id)
    {
        $this->resetValidation();
        $participant =  RefParticipant::find($id);
        $this->participant = $participant->participant;
        $this->selectedCategories = $participant->category;
        $this->participant_no = $participant->participant_no;
        $this->school = $participant->school;
        $this->gender = $participant->gender;
        $this->group_name = $participant->group_name;
        $this->id = $id;
        $this->dispatch('openModal');
    }
    public function uploadOutput()
    {
        $this->validate();
        $output =  PosterOutput::where('participant_id', $this->participant_id)->first();
        if (!$output) {
            $output = new PosterOutput();
            $output->participant_id = $this->participant_id;
        }
        $path = $this->poster_file->store('uploads', 'public');
        $output->output_file = $path;
        $output->save();
        $this->reset('poster_file');
        $this->dispatch('reset-poster-files');
        return session()->flash("status", 'File uploaded successfully! Path: ' . $path);
    }
    public function addPoster($id)
    {
        $this->participant_id = $id;
        $this->reset('poster_file');
        $this->reset('poster_photos');
        $this->getPosterFile($id);
        $this->dispatch('openPosterModal');
    }
    public function getPosterFile($id)
    {
        $output = PosterOutput::where('participant_id', $id)->first();
        // Loop

        if ($output) {
            $this->poster_photos = $output->output_file ? asset('storage/' . $output->output_file) : null;
        }

        return null;
    }
    public function deleteParticipant($id)
    {
        $this->id = $id;
        $this->dispatch('openDeleteModal');
    }
    public function executeDeleteParticipant()
    {
        $this->validate([
            'password' => 'required|current_password',
        ]);

        $participant = RefParticipant::find($this->id);
        if ($participant) {
            $participant->delete();
            return session()->flash("status", "Sucessfully deleted!");
        }
        return session()->flash("error", "Failed to delete");
        $this->dispatch('hideDeleteModal');
    }
}
