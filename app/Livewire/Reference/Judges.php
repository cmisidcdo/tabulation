<?php

namespace App\Livewire\Reference;

use App\Models\Category;
use Livewire\Component;
use App\Models\RefJudge;
use App\Models\User;
use App\Support\JudgeEmail;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Hash;
use Livewire\WithPagination;

class Judges extends Component
{
    use WithPagination;
    public $id, $judge, $nickname, $selectedCategories = [], $selectedCateg, $password;
    public $is_active = 1, $selectedStatus, $search = '';
    public function updated($propertyName)
    {
        if (in_array($propertyName, ['selectedCateg', 'selectedStatus', 'search'])) {
            $this->resetPage();
        }
    }
    public function clearFilters()
    {
        $this->reset('search', 'selectedCateg', 'selectedStatus');
        $this->resetPage();
    }

    /** Active categories, cached for the request (used by the filter, the badges and the modal). */
    #[Computed]
    public function categories()
    {
        return Category::where('is_active', 1)->get(['category', 'description']);
    }

    public function render()
    {
        $search = trim((string) $this->search);
        $judges = RefJudge::query()
            ->select('id', 'judge', 'nickname', 'category', 'user_id', 'is_active')
            ->with('user:id,email')
            ->when($search !== '', function ($query) use ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('judge', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%"));
            });
        })->when($this->selectedCateg, function ($query) {
            $query->whereJsonContains('category', $this->selectedCateg);
        })->when($this->selectedStatus !== '' && $this->selectedStatus !== null, function ($query) {
            $query->where('is_active', $this->selectedStatus);
        })->orderByNumber()->paginate(10);
        $categories = $this->categories;
        $categoryLabels = $categories->pluck('description', 'category');
        $hasFilters = $search !== '' || $this->selectedCateg || ($this->selectedStatus !== '' && $this->selectedStatus !== null);
        return view('livewire.reference.judges', compact('judges', 'categories', 'categoryLabels', 'hasFilters'));
    }
    public function addJudge()
    {
        $this->resetExcept('search', 'selectedCateg', 'selectedStatus');
        $this->is_active = 1;
        $this->dispatch('openModal');
    }
    public function editJudge($id)
    {
        $this->id = $id;
        $judge = RefJudge::find($id);

        $this->judge = $judge->judge;
        $this->nickname = $judge->nickname;
        $this->selectedCategories = $judge->category;
        $this->is_active = $judge->is_active;
        $this->dispatch('openModal');
    }
    public function toggleStatus($id)
    {
        $judge = RefJudge::find($id);
        if (!$judge) {
            return session()->flash('error', 'Judge not found!');
        }

        DB::transaction(function () use ($judge) {
            $judge->is_active = $judge->is_active ? 0 : 1;
            $judge->save();

            $user = User::find($judge->user_id);
            if ($user) {
                $user->is_active = $judge->is_active;
                $user->save();
            }
        });

        return session()->flash('status', 'Sucessfully updated status!');
    }
    public function saveJudge()
    {
        $this->validate([
            'judge' => 'required',
            'nickname' => 'required',
            'selectedCategories' => 'array',
            'selectedCategories.*' => 'exists:categories,category',
            'is_active' => 'required|boolean',
        ]);

        [$email, $isNewLogin] = DB::transaction(function () {
            $judge = RefJudge::findOrNew($this->id);
            $user = $judge->user_id ? User::find($judge->user_id) : null;

            // The login (email + password) is created once. Editing a judge — even renaming
            // them — keeps it, so the judge's username and password never change unexpectedly.
            $isNewLogin = !$user;
            if ($isNewLogin) {
                $user = new User();
                $user->email = JudgeEmail::generate($this->judge);
                $user->role = 'user';
                $user->password = Hash::make('password');
            }
            $user->name = $this->judge;
            $user->is_active = (int) $this->is_active;
            $user->save();

            $judge->judge = $this->judge;
            $judge->nickname = $this->nickname;
            $judge->category = $this->selectedCategories;
            $judge->user_id = $user->id;
            $judge->is_active = (int) $this->is_active;
            $judge->save();
            $this->id = $judge->id;

            return [$user->email, $isNewLogin];
        });

        return session()->flash('status', $isNewLogin
            ? "Successfully saved! Login: {$email} / password"
            : "Successfully saved! Login: {$email}");
    }

    /** Login email shown in the modal: the generated one while adding, the existing one while editing. */
    #[Computed]
    public function loginEmail(): ?string
    {
        if ($this->id) {
            $existing = RefJudge::find($this->id)?->user?->email;
            if ($existing) return $existing;
        }
        return trim((string) $this->judge) !== '' ? JudgeEmail::generate($this->judge) : null;
    }
    public function deleteJudge($id)
    {
        $this->resetValidation();
        $this->id = $id;
        $this->dispatch('openDeleteModal');
    }
    public function executeDelete()
    {
        $this->validate([
            'password' => 'required|current_password',
        ]);

        $judge = RefJudge::find($this->id);
        if ($judge) {
            User::find($judge->user_id)->delete();
            $judge->delete();
            $this->password = null;
            return session()->flash("status", "Sucessfully deleted!");
        }
        return session()->flash("error", "Failed to delete");
    }
}
