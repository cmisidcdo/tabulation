<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Higalaay;
use App\Models\Log;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Livewire\Settings\DBImportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class JudgeAppApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/tab-v1/judge-app';

    private User $admin, $userA, $userB;
    private RefJudge $judgeA, $judgeB;
    private RefParticipant $p1, $p2, $pOther;
    private RefCriteria $c1, $c2, $cOther;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['category' => 'dance', 'description' => 'Street Dance', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        Category::create(['category' => 'song', 'description' => 'Song', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        Category::create(['category' => 'orals', 'description' => 'Oral', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1, 'tabulation_mode' => 'oral']);

        $this->admin = User::factory()->create(['role' => 'admin', 'password' => 'secret123']);
        $this->userA = User::factory()->create(['role' => 'user', 'password' => 'secret123']);
        $this->userB = User::factory()->create(['role' => 'user', 'password' => 'secret123']);

        $this->judgeA = RefJudge::create(['category' => ['dance', 'orals'], 'judge' => 'Judge A', 'nickname' => '1', 'user_id' => $this->userA->id, 'is_active' => 1]);
        $this->judgeB = RefJudge::create(['category' => ['dance', 'song'], 'judge' => 'Judge B', 'nickname' => '2', 'user_id' => $this->userB->id, 'is_active' => 1]);

        $this->c1 = RefCriteria::create(['criteria' => 'Choreography', 'perfect_score' => 10, 'category' => 'dance']);
        $this->c2 = RefCriteria::create(['criteria' => 'Costume', 'perfect_score' => 20, 'category' => 'dance']);
        $this->cOther = RefCriteria::create(['criteria' => 'Voice', 'perfect_score' => 10, 'category' => 'song']);

        $this->p1 = RefParticipant::create(['category' => ['dance'], 'participant_no' => '2', 'participant' => 'Team Two']);
        $this->p2 = RefParticipant::create(['category' => ['dance'], 'participant_no' => '10', 'participant' => 'Team Ten']);
        $this->pOther = RefParticipant::create(['category' => ['song'], 'participant_no' => '1', 'participant' => 'Singer']);

        // Judge B already has a score; judge A has one too
        Higalaay::create(['participant_id' => $this->p1->id, 'category' => 'dance', 'criteria_id' => $this->c1->id, 'judge_id' => $this->judgeB->id, 'score' => 9]);
        Higalaay::create(['participant_id' => $this->p1->id, 'category' => 'dance', 'criteria_id' => $this->c2->id, 'judge_id' => $this->judgeA->id, 'score' => 12]);
    }

    private function scoreOf(RefParticipant $p, RefCriteria $c, RefJudge $j): ?float
    {
        return Higalaay::where('category', $c->category)->where('participant_id', $p->id)
            ->where('criteria_id', $c->id)->where('judge_id', $j->id)->value('score');
    }

    private function asJudgeA(): void
    {
        Sanctum::actingAs($this->userA, ['judge-app']);
    }

    private function upload(array $scores, string $category = 'dance')
    {
        return $this->postJson(self::API . '/upload', ['category' => $category, 'scores' => $scores]);
    }

    // ---- Login ----

    public function test_judge_can_log_in_and_gets_a_token(): void
    {
        $res = $this->postJson(self::API . '/login', ['email' => $this->userA->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('judge.id', $this->judgeA->id);

        $this->assertNotEmpty($res->json('token'));
        $this->assertTrue($this->userA->tokens()->first()->can('judge-app'));
    }

    public function test_login_rejects_wrong_password_admins_and_unlinked_users(): void
    {
        $this->postJson(self::API . '/login', ['email' => $this->userA->email, 'password' => 'nope-nope'])->assertStatus(401);
        $this->postJson(self::API . '/login', ['email' => $this->admin->email, 'password' => 'secret123'])->assertStatus(403);

        $loner = User::factory()->create(['role' => 'user', 'password' => 'secret123']);
        $this->postJson(self::API . '/login', ['email' => $loner->email, 'password' => 'secret123'])->assertStatus(403);

        $this->judgeA->update(['is_active' => 0]);
        $this->postJson(self::API . '/login', ['email' => $this->userA->email, 'password' => 'secret123'])->assertStatus(403);
    }

    public function test_token_without_judge_app_ability_is_rejected(): void
    {
        Sanctum::actingAs($this->userA, ['something-else']);
        $this->getJson(self::API . '/events')->assertStatus(403);
    }

    // ---- Events and package ----

    public function test_events_lists_only_the_judges_higalaay_events(): void
    {
        $this->asJudgeA();
        $this->getJson(self::API . '/events')
            ->assertOk()
            ->assertJsonCount(1, 'events') // "orals" is oral mode, "song" is judge B's
            ->assertJsonPath('events.0.category', 'dance');
    }

    public function test_package_has_only_this_judges_scores_and_this_events_data(): void
    {
        $this->asJudgeA();
        $res = $this->getJson(self::API . '/package/dance')->assertOk();

        $this->assertSame([$this->c1->id, $this->c2->id], array_column($res->json('criteria'), 'id'));
        // Natural order: 2 before 10
        $this->assertSame([$this->p1->id, $this->p2->id], array_column($res->json('participants'), 'id'));
        $this->assertSame(
            [['participant_id' => $this->p1->id, 'criteria_id' => $this->c2->id, 'score' => 12]],
            $res->json('scores')
        );
    }

    public function test_package_hides_participant_names_when_the_event_hides_them(): void
    {
        $this->asJudgeA();
        $this->getJson(self::API . '/package/dance')->assertJsonPath('participants.0.participant', 'Team Two');

        Category::where('category', 'dance')->update(['display_participant' => 0]);
        $res = $this->getJson(self::API . '/package/dance')->assertOk();
        $this->assertSame([null, null], array_column($res->json('participants'), 'participant'));
        $this->assertStringNotContainsString('Team Two', $res->getContent());
    }

    public function test_package_for_an_event_the_judge_is_not_in_is_not_found(): void
    {
        $this->asJudgeA();
        $this->getJson(self::API . '/package/song')->assertNotFound();
        $this->getJson(self::API . '/package/orals')->assertNotFound();
        $this->postJson(self::API . '/upload', ['category' => 'song', 'scores' => []])->assertNotFound();
    }

    // ---- Upload ----

    public function test_upload_saves_clamps_and_logs(): void
    {
        $this->asJudgeA();
        $this->upload([
            ['participant_id' => $this->p1->id, 'criteria_id' => $this->c1->id, 'score' => 8.5, 'base' => null],
            ['participant_id' => $this->p2->id, 'criteria_id' => $this->c1->id, 'score' => 99, 'base' => null],
            ['participant_id' => $this->p2->id, 'criteria_id' => $this->c2->id, 'score' => -4, 'base' => null],
            ['participant_id' => $this->p1->id, 'criteria_id' => $this->c2->id, 'score' => 15, 'base' => 12],
        ])->assertOk()->assertJsonPath('counts.saved', 4);

        $this->assertEquals(8.5, $this->scoreOf($this->p1, $this->c1, $this->judgeA));
        $this->assertEquals(10, $this->scoreOf($this->p2, $this->c1, $this->judgeA));
        $this->assertEquals(0, $this->scoreOf($this->p2, $this->c2, $this->judgeA));
        $this->assertEquals(15, $this->scoreOf($this->p1, $this->c2, $this->judgeA));
        // Updated in place, judge B untouched
        $this->assertSame(1, Higalaay::where('judge_id', $this->judgeA->id)->where('participant_id', $this->p1->id)->where('criteria_id', $this->c2->id)->count());
        $this->assertEquals(9, $this->scoreOf($this->p1, $this->c1, $this->judgeB));
        $this->assertSame(4, Log::where('activity', 'like', '(Judge app)%')->count());
    }

    public function test_judge_id_in_the_body_is_ignored(): void
    {
        $this->asJudgeA();
        $this->postJson(self::API . '/upload', [
            'category' => 'dance',
            'judge_id' => $this->judgeB->id,
            'scores' => [['participant_id' => $this->p1->id, 'criteria_id' => $this->c1->id, 'score' => 1, 'base' => null]],
        ])->assertOk();

        $this->assertEquals(9, $this->scoreOf($this->p1, $this->c1, $this->judgeB));
        $this->assertEquals(1, $this->scoreOf($this->p1, $this->c1, $this->judgeA));
    }

    public function test_cells_outside_the_event_are_rejected(): void
    {
        $this->asJudgeA();
        $res = $this->upload([
            ['participant_id' => $this->pOther->id, 'criteria_id' => $this->c1->id, 'score' => 5],
            ['participant_id' => $this->p1->id, 'criteria_id' => $this->cOther->id, 'score' => 5],
        ])->assertOk();

        $this->assertSame(2, $res->json('counts.rejected'));
        $this->assertSame(0, Higalaay::where('judge_id', $this->judgeA->id)->where('category', 'dance')->where('criteria_id', '<>', $this->c2->id)->count());
    }

    public function test_conflict_when_server_changed_since_download_and_force_overwrites(): void
    {
        // Device downloaded 12; admin changed it to 14 on the main page meanwhile
        Higalaay::where('judge_id', $this->judgeA->id)->where('criteria_id', $this->c2->id)->update(['score' => 14]);

        $this->asJudgeA();
        $item = ['participant_id' => $this->p1->id, 'criteria_id' => $this->c2->id, 'score' => 16, 'base' => 12];
        $this->upload([$item])
            ->assertOk()
            ->assertJsonPath('counts.conflict', 1)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.server', 14);
        $this->assertEquals(14, $this->scoreOf($this->p1, $this->c2, $this->judgeA));

        $this->upload([$item + ['force' => true]])->assertJsonPath('results.0.status', 'saved');
        $this->assertEquals(16, $this->scoreOf($this->p1, $this->c2, $this->judgeA));
    }

    public function test_reupload_is_idempotent(): void
    {
        $this->asJudgeA();
        $items = [['participant_id' => $this->p2->id, 'criteria_id' => $this->c1->id, 'score' => 7, 'base' => null]];
        $this->upload($items)->assertJsonPath('counts.saved', 1);
        // Same batch again (e.g. the reply was lost): no conflict, nothing rewritten
        $this->upload($items)->assertJsonPath('counts.same', 1)->assertJsonPath('counts.conflict', 0);
        $this->assertSame(1, Log::where('activity', 'like', '(Judge app)%')->count());
    }

    public function test_locked_event_rejects_uploads(): void
    {
        Setting::set('scoring_locked_dance', 1);
        $this->asJudgeA();
        $this->upload([['participant_id' => $this->p2->id, 'criteria_id' => $this->c1->id, 'score' => 7]])
            ->assertOk()
            ->assertJsonPath('counts.rejected', 1)
            ->assertJsonPath('results.0.error', 'Scoring is locked.');
        $this->assertNull($this->scoreOf($this->p2, $this->c1, $this->judgeA));
    }

    // ---- Admin import of a backup file exported by the app ----

    private function backupFile(array $scores): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('judge-backup.json', json_encode([
            'app' => 'tabulation-judge-app',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'judge' => ['id' => $this->judgeA->id, 'name' => 'Judge A'],
            'events' => [['category' => 'dance', 'description' => 'Street Dance', 'scores' => $scores]],
        ]));
    }

    public function test_admin_imports_backup_with_conflict_check(): void
    {
        Higalaay::where('judge_id', $this->judgeA->id)->where('criteria_id', $this->c2->id)->update(['score' => 14]);
        $file = $this->backupFile([
            ['participant_id' => $this->p2->id, 'criteria_id' => $this->c1->id, 'score' => 6, 'base' => null],
            ['participant_id' => $this->p1->id, 'criteria_id' => $this->c2->id, 'score' => 16, 'base' => 12], // changed on server
        ]);

        Livewire::actingAs($this->admin)->test(DBImportExport::class)
            ->set('backup_file', $file)
            ->call('importJudgeBackup')
            ->assertHasNoErrors()
            ->assertSet('backupReport.events.0.counts.saved', 1)
            ->assertSet('backupReport.events.0.counts.conflict', 1);
        $this->assertEquals(6, $this->scoreOf($this->p2, $this->c1, $this->judgeA));
        $this->assertEquals(14, $this->scoreOf($this->p1, $this->c2, $this->judgeA));

        Livewire::actingAs($this->admin)->test(DBImportExport::class)
            ->set('backup_force', true)
            ->set('backup_file', $this->backupFile([['participant_id' => $this->p1->id, 'criteria_id' => $this->c2->id, 'score' => 16, 'base' => 12]]))
            ->call('importJudgeBackup')
            ->assertSet('backupReport.events.0.counts.saved', 1);
        $this->assertEquals(16, $this->scoreOf($this->p1, $this->c2, $this->judgeA));
    }

    public function test_backup_import_is_admin_only_and_checks_the_file(): void
    {
        Livewire::actingAs($this->userA)->test(DBImportExport::class)
            ->set('backup_file', $this->backupFile([['participant_id' => $this->p2->id, 'criteria_id' => $this->c1->id, 'score' => 6]]))
            ->call('importJudgeBackup')
            ->assertForbidden();
        $this->assertNull($this->scoreOf($this->p2, $this->c1, $this->judgeA));

        Livewire::actingAs($this->admin)->test(DBImportExport::class)
            ->set('backup_file', UploadedFile::fake()->createWithContent('other.json', '{"hello":"world"}'))
            ->call('importJudgeBackup')
            ->assertHasErrors('backup_file');
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson(self::API . '/login', ['email' => $this->userA->email, 'password' => 'secret123'])->json('token');
        $this->withToken($token)->postJson(self::API . '/logout')->assertOk();
        $this->assertSame(0, $this->userA->tokens()->count());
    }
}
