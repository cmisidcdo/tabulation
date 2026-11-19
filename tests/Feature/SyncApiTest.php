<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Higalaay;
use App\Models\RefCriteria;
use App\Models\RefJudge;
use App\Models\RefParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Legacy database sync API used by the venue laptop (Settings → Import/Export). Admins only. */
class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/tab-v1';

    private User $admin, $judgeUser;
    private RefJudge $judge;

    protected function setUp(): void
    {
        parent::setUp();
        Category::create(['category' => 'dance', 'description' => 'Street Dance', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
        $this->admin = User::factory()->create(['role' => 'admin', 'password' => 'secret123']);
        $this->judgeUser = User::factory()->create(['role' => 'user', 'password' => 'secret123', 'remember_token' => 'REMEMBER-ME-TOKEN']);
        $this->judge = RefJudge::create(['category' => ['dance'], 'judge' => 'Judge A', 'nickname' => '1', 'user_id' => $this->judgeUser->id, 'is_active' => 1]);
    }

    public function test_only_admins_can_log_in_to_sync(): void
    {
        $this->postJson(self::API . '/login', ['email' => $this->judgeUser->email, 'password' => 'secret123'])->assertStatus(403);

        $token = $this->postJson(self::API . '/login', ['email' => $this->admin->email, 'password' => 'secret123'])
            ->assertOk()->json('access_token');
        $this->withToken($token)->getJson(self::API . '/get-reference')->assertOk();
    }

    public function test_judge_and_judge_app_tokens_cannot_use_sync_endpoints(): void
    {
        foreach ([['*'], ['judge-app']] as $abilities) {
            Sanctum::actingAs($this->judgeUser, $abilities);
            $this->getJson(self::API . '/get-reference')->assertForbidden();
            $this->getJson(self::API . '/download-database')->assertForbidden();
            $this->postJson(self::API . '/upload-database', ['higalaay' => [[
                'participant_id' => 1, 'criteria_id' => 1, 'judge_id' => 99, 'category' => 'dance', 'score' => 1,
            ]]])->assertForbidden();
        }
        $this->assertSame(0, Higalaay::count());

        // An admin account with a judge-app-only token is not enough either
        Sanctum::actingAs($this->admin, ['judge-app']);
        $this->getJson(self::API . '/get-reference')->assertForbidden();
    }

    public function test_reference_sends_judge_logins_without_remember_tokens(): void
    {
        Sanctum::actingAs($this->admin, ['*']); // tokens issued before this change have "*"
        $res = $this->getJson(self::API . '/get-reference')->assertOk();

        $users = $res->json('users');
        $this->assertSame([$this->judgeUser->id], array_column($users, 'id')); // no admins
        $this->assertArrayHasKey('password', $users[0]); // the laptop needs it so judges can log in there
        $this->assertArrayNotHasKey('remember_token', $users[0]);
        $this->assertStringNotContainsString('REMEMBER-ME-TOKEN', $res->getContent());
    }

    public function test_admin_can_upload_and_download(): void
    {
        $p = RefParticipant::create(['category' => ['dance'], 'participant_no' => '1', 'participant' => 'P1']);
        $c = RefCriteria::create(['criteria' => 'C1', 'perfect_score' => 10, 'category' => 'dance']);

        Sanctum::actingAs($this->admin, ['sync']);
        $this->postJson(self::API . '/upload-database', ['higalaay' => [[
            'participant_id' => $p->id, 'criteria_id' => $c->id, 'judge_id' => $this->judge->id, 'category' => 'dance', 'score' => 7,
        ]]])->assertOk();
        $this->getJson(self::API . '/download-database')->assertOk()->assertJsonPath('higalaays.0.score', 7);
    }
}
