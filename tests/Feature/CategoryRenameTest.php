<?php

namespace Tests\Feature;

use App\Livewire\Settings\Modules;
use App\Models\Category;
use App\Models\Higalaay;
use App\Models\HigalaayDeduction;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryRenameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Category $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->a = Category::create(['category' => 'old-dance', 'description' => 'Old Dance', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        Category::create(['category' => 'other', 'description' => 'Other', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
    }

    private function modules()
    {
        return Livewire::actingAs($this->admin)->test(Modules::class);
    }

    private function editAndSave(Category $category, array $changes)
    {
        $component = $this->modules()->call('editModule', $category->id);
        foreach ($changes as $field => $value) {
            $component->set($field, $value);
        }
        return $component->call('saveModule');
    }

    public function test_renaming_the_code_relinks_all_data(): void
    {
        $p = RefParticipant::create(['category' => ['old-dance', 'other'], 'participant_no' => '1', 'participant' => 'P']);
        $j = RefJudge::create(['category' => ['old-dance'], 'judge' => 'J', 'nickname' => '1', 'is_active' => 1]);
        $c = RefCriteria::create(['criteria' => 'C', 'perfect_score' => 10, 'category' => 'old-dance']);
        Higalaay::create(['participant_id' => $p->id, 'category' => 'old-dance', 'criteria_id' => $c->id, 'judge_id' => $j->id, 'score' => 7]);
        HigalaayDeduction::create(['participant_id' => $p->id, 'category' => 'old-dance', 'deduction' => 1]);
        Setting::set('scoring_locked_old-dance', 1);
        Setting::set('peoples_choice_open_old-dance', '1');
        // Unrelated data must be untouched
        $untouched = RefParticipant::create(['category' => ['other'], 'participant_no' => '2', 'participant' => 'Q']);

        $this->editAndSave($this->a, ['category' => 'New Dance'])->assertHasNoErrors();

        $this->assertSame('new-dance', $this->a->fresh()->category);
        $this->assertSame(['new-dance', 'other'], $p->fresh()->category);
        $this->assertSame(['new-dance'], $j->fresh()->category);
        $this->assertSame('new-dance', $c->fresh()->category);
        $this->assertSame(1, Higalaay::where('category', 'new-dance')->count());
        $this->assertSame(1, HigalaayDeduction::where('category', 'new-dance')->count());
        $this->assertEquals(1, Setting::get('scoring_locked_new-dance'));
        $this->assertSame('1', Setting::get('peoples_choice_open_new-dance'));
        $this->assertNull(Setting::get('scoring_locked_old-dance'));
        $this->assertSame(['other'], $untouched->fresh()->category);

        // The scoped lookups the app uses still find everything
        $this->assertSame(1, RefParticipant::category('new-dance')->count());
        $this->assertSame(1, RefJudge::category('new-dance')->count());
        $this->assertSame(0, RefParticipant::category('old-dance')->count());
    }

    public function test_editing_only_the_description_keeps_a_non_canonical_code(): void
    {
        $legacy = Category::create(['category' => 'Street_Dance', 'description' => 'Street', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        $p = RefParticipant::create(['category' => ['Street_Dance'], 'participant_no' => '1', 'participant' => 'P']);

        $this->editAndSave($legacy, ['description' => 'Street Dancing'])->assertHasNoErrors();

        $this->assertSame('Street_Dance', $legacy->fresh()->category);
        $this->assertSame('Street Dancing', $legacy->fresh()->description);
        $this->assertSame(['Street_Dance'], $p->fresh()->category);
    }

    public function test_add_after_edit_creates_a_new_category(): void
    {
        $this->modules()
            ->call('editModule', $this->a->id)   // leaves category_id set…
            ->call('addModule')                  // …which Add must clear
            ->set('category', 'Brand New')
            ->set('description', 'Brand New')
            ->set('winners', 1)
            ->set('is_active', 1)
            ->set('display_participant', 1)
            ->call('saveModule')
            ->assertHasNoErrors();

        $this->assertSame('old-dance', $this->a->fresh()->category);
        $this->assertTrue(Category::where('category', 'brand-new')->exists());
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->editAndSave($this->a, ['category' => 'Other'])->assertHasErrors(['category' => 'unique']);
        $this->assertSame('old-dance', $this->a->fresh()->category);

        $this->modules()->call('addModule')
            ->set('category', 'old dance')
            ->set('description', 'Dup')
            ->set('winners', 1)
            ->set('is_active', 1)
            ->set('display_participant', 1)
            ->call('saveModule')
            ->assertHasErrors(['category' => 'unique']);
        $this->assertSame(1, Category::where('category', 'old-dance')->count());
    }

    public function test_existing_duplicate_can_still_edit_its_description(): void
    {
        $dup = Category::create(['category' => 'other', 'description' => 'Other copy', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);

        $this->editAndSave($dup, ['description' => 'Other (renamed)'])->assertHasNoErrors();
        $this->assertSame('Other (renamed)', $dup->fresh()->description);
    }

    public function test_code_without_letters_or_numbers_is_rejected(): void
    {
        $this->editAndSave($this->a, ['category' => '!!!'])->assertHasErrors(['category' => 'required']);
        $this->assertSame('old-dance', $this->a->fresh()->category);
    }
}
