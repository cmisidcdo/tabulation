<?php

namespace Tests\Feature;

use App\Livewire\Higalaay as HigalaayComponent;
use App\Models\Category;
use App\Models\Higalaay;
use App\Models\HigalaayDeduction;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HigalaayScoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin, $userA, $userB;
    private RefJudge $judgeA, $judgeB;
    private RefParticipant $p1, $p2;
    /** @var array<string, RefCriteria> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();

        // hw = weighted segments, hu = plain unweighted average
        Category::create(['category' => 'hw', 'description' => 'Weighted', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        Category::create(['category' => 'hu', 'description' => 'Unweighted', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->userA = User::factory()->create(['role' => 'user']);
        $this->userB = User::factory()->create(['role' => 'user']);

        $this->judgeA = RefJudge::create(['category' => ['hw', 'hu'], 'judge' => 'Judge A', 'nickname' => '1', 'user_id' => $this->userA->id, 'is_active' => 1]);
        $this->judgeB = RefJudge::create(['category' => ['hw', 'hu'], 'judge' => 'Judge B', 'nickname' => '2', 'user_id' => $this->userB->id, 'is_active' => 1]);
        // Inactive judge must not count toward the panel size
        RefJudge::create(['category' => ['hw', 'hu'], 'judge' => 'Judge C', 'nickname' => '3', 'user_id' => null, 'is_active' => 0]);

        $this->c['c1'] = RefCriteria::create(['criteria' => 'C1', 'perfect_score' => 10, 'category' => 'hw', 'segment' => 'A', 'segment_weight' => 60]);
        $this->c['c2'] = RefCriteria::create(['criteria' => 'C2', 'perfect_score' => 10, 'category' => 'hw', 'segment' => 'A', 'segment_weight' => 60]);
        $this->c['c3'] = RefCriteria::create(['criteria' => 'C3', 'perfect_score' => 20, 'category' => 'hw', 'segment' => 'B', 'segment_weight' => 40]);
        $this->c['c4'] = RefCriteria::create(['criteria' => 'C4', 'perfect_score' => 50, 'category' => 'hu']);
        $this->c['c5'] = RefCriteria::create(['criteria' => 'C5', 'perfect_score' => 50, 'category' => 'hu']);

        $this->p1 = RefParticipant::create(['category' => ['hw'], 'participant_no' => '1', 'participant' => 'P1']);
        $this->p2 = RefParticipant::create(['category' => ['hu'], 'participant_no' => '1', 'participant' => 'P2']);

        $this->score($this->p1, 'hw', 'c1', $this->judgeA, 8);
        $this->score($this->p1, 'hw', 'c2', $this->judgeA, 6);
        $this->score($this->p1, 'hw', 'c3', $this->judgeA, 15);
        $this->score($this->p1, 'hw', 'c1', $this->judgeB, 10);
        $this->score($this->p1, 'hw', 'c2', $this->judgeB, 4);
        $this->score($this->p1, 'hw', 'c3', $this->judgeB, 10);
        HigalaayDeduction::create(['participant_id' => $this->p1->id, 'category' => 'hw', 'deduction' => 2]);

        $this->score($this->p2, 'hu', 'c4', $this->judgeA, 40);
        $this->score($this->p2, 'hu', 'c5', $this->judgeA, 30);
        $this->score($this->p2, 'hu', 'c4', $this->judgeB, 20);
        $this->score($this->p2, 'hu', 'c5', $this->judgeB, 10);
        HigalaayDeduction::create(['participant_id' => $this->p2->id, 'category' => 'hu', 'deduction' => 5]);
    }

    private function score(RefParticipant $p, string $cat, string $c, RefJudge $j, float $score): void
    {
        Higalaay::create(['participant_id' => $p->id, 'category' => $cat, 'criteria_id' => $this->c[$c]->id, 'judge_id' => $j->id, 'score' => $score]);
    }

    private function scoreOf(RefParticipant $p, string $c, RefJudge $j): ?float
    {
        return Higalaay::where('participant_id', $p->id)->where('criteria_id', $this->c[$c]->id)->where('judge_id', $j->id)->value('score');
    }

    // ---- Totals: hand-computed expectations (must not change with refactors) ----

    public function test_weighted_totals_for_admin_and_judge(): void
    {
        // A: (28/2/20)*60 = 42, B: (25/2/20)*40 = 25 → 67 - 2
        $this->actingAs($this->admin);
        $this->assertEquals(65, $this->p1->averageHigalaay('hw'));

        // A: (14/20)*60 = 42, B: (15/20)*40 = 30 → 72 - 2
        $this->actingAs($this->userA);
        $this->assertEquals(70, $this->p1->averageHigalaay('hw'));
    }

    public function test_unweighted_totals_for_admin_and_judge(): void
    {
        $this->actingAs($this->admin);
        $this->assertEquals(45, $this->p2->averageHigalaay('hu'));          // 100/2 - 5
        $this->assertEquals(30, $this->p2->averageHigalaay('hu', $this->c['c4'])); // 60/2, no deduction

        $this->actingAs($this->userA);
        $this->assertEquals(65, $this->p2->averageHigalaay('hu'));          // 70 - 5
        $this->assertEquals(40, $this->p2->averageHigalaay('hu', $this->c['c4']));
    }

    public function test_page_shows_card_totals(): void
    {
        Livewire::actingAs($this->admin)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3])
            ->assertSee('65');
        Livewire::actingAs($this->userA)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3])
            ->assertSee('29'); // judge card shows own raw sum 8+6+15
    }

    // ---- saveScore hardening ----

    private function asJudgeA()
    {
        return Livewire::actingAs($this->userA)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3]);
    }

    public function test_judge_cannot_write_into_another_judges_column(): void
    {
        $this->asJudgeA()
            ->call('saveScore', $this->p1->id, $this->c['c1']->id, $this->judgeB->id, 1)
            ->assertReturned(['ok' => false, 'error' => 'You cannot score this cell.']);
        $this->assertEquals(10, $this->scoreOf($this->p1, 'c1', $this->judgeB));

        $this->asJudgeA()
            ->call('saveScore', $this->p1->id, $this->c['c1']->id, $this->judgeA->id, 9)
            ->assertReturned(['ok' => true, 'score' => 9.0]);
        $this->assertEquals(9, $this->scoreOf($this->p1, 'c1', $this->judgeA));
    }

    public function test_criteria_or_participant_from_another_category_is_rejected(): void
    {
        // c4 belongs to "hu", p2 is only in "hu"
        $this->asJudgeA()->call('saveScore', $this->p1->id, $this->c['c4']->id, $this->judgeA->id, 5)
            ->assertReturned(['ok' => false, 'error' => 'You cannot score this cell.']);
        $this->asJudgeA()->call('saveScore', $this->p2->id, $this->c['c1']->id, $this->judgeA->id, 5)
            ->assertReturned(['ok' => false, 'error' => 'You cannot score this cell.']);
        $this->assertSame(0, Higalaay::where('category', 'hw')->where('participant_id', $this->p2->id)->count());
    }

    public function test_scores_are_clamped_and_blank_saves_zero(): void
    {
        $this->asJudgeA()->call('saveScore', $this->p1->id, $this->c['c1']->id, $this->judgeA->id, 15)
            ->assertReturned(['ok' => true, 'score' => 10.0]);
        $this->asJudgeA()->call('saveScore', $this->p1->id, $this->c['c2']->id, $this->judgeA->id, -3)
            ->assertReturned(['ok' => true, 'score' => 0.0]);
        $this->asJudgeA()->call('saveScore', $this->p1->id, $this->c['c3']->id, $this->judgeA->id, '')
            ->assertReturned(['ok' => true, 'score' => 0.0]);

        $this->assertEquals(10, $this->scoreOf($this->p1, 'c1', $this->judgeA));
        $this->assertEquals(0, $this->scoreOf($this->p1, 'c2', $this->judgeA));
        $this->assertEquals(0, $this->scoreOf($this->p1, 'c3', $this->judgeA));
        // Updated in place, no duplicate rows
        $this->assertSame(6, Higalaay::where('category', 'hw')->count());
    }

    public function test_locked_scoring_blocks_judges_but_not_admin(): void
    {
        Setting::set('scoring_locked_hw', 1);

        $this->asJudgeA()->call('saveScore', $this->p1->id, $this->c['c1']->id, $this->judgeA->id, 1)
            ->assertReturned(['ok' => false, 'error' => 'Scoring is locked.']);
        $this->assertEquals(8, $this->scoreOf($this->p1, 'c1', $this->judgeA));

        Livewire::actingAs($this->admin)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3])
            ->call('saveScore', $this->p1->id, $this->c['c1']->id, $this->judgeA->id, 7)
            ->assertReturned(['ok' => true, 'score' => 7.0]);
    }

    public function test_deductions_are_admin_only(): void
    {
        $this->asJudgeA()->call('saveDeduction', $this->p1->id, 0);
        $this->assertEquals(2, HigalaayDeduction::where('participant_id', $this->p1->id)->value('deduction'));

        Livewire::actingAs($this->admin)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3])
            ->call('saveDeduction', $this->p1->id, 4);
        $this->assertEquals(4, HigalaayDeduction::where('participant_id', $this->p1->id)->value('deduction'));
    }

    public function test_type_cannot_be_changed_from_the_browser(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $this->asJudgeA()->set('type', 'hu');
    }

    public function test_admin_judge_filter_matches_exact_id(): void
    {
        // Create judges until one has an id that contains judgeA's id as a substring (e.g. 1 → 10)
        do {
            $extra = RefJudge::create(['category' => ['hw'], 'judge' => 'Extra', 'nickname' => 'x', 'is_active' => 1]);
        } while (!str_contains((string) $extra->id, (string) $this->judgeA->id));

        Livewire::actingAs($this->admin)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3])
            ->set('judge_id', $this->judgeA->id)
            ->assertViewHas('judges', fn($judges) => $judges->pluck('id')->all() === [$this->judgeA->id]);
    }

    // ---- Performance ----

    public function test_render_query_count_does_not_grow_with_participants(): void
    {
        for ($i = 2; $i <= 25; $i++) {
            RefParticipant::create(['category' => ['hw'], 'participant_no' => (string) $i, 'participant' => "P$i"]);
        }

        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(HigalaayComponent::class, ['type' => 'hw', 'winner' => 3]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[higalaay] admin render queries for 25 participants x 3 criteria x 2 judges: $count\n");
        $this->assertLessThan(25, $count);
    }
}
