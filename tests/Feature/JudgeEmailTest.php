<?php

namespace Tests\Feature;

use App\Livewire\Reference\Judges;
use App\Models\Category;
use App\Models\RefJudge;
use App\Models\User;
use App\Support\JudgeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JudgeEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@tabulation.com']);
        Category::create(['category' => 'dance', 'description' => 'Dance', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);
    }

    private function createJudge(string $name, string $no = '1')
    {
        return Livewire::actingAs($this->admin)->test(Judges::class)
            ->call('addJudge')
            ->set('judge', $name)
            ->set('nickname', $no)
            ->set('selectedCategories', ['dance'])
            ->call('saveJudge')
            ->assertHasNoErrors();
    }

    private function emailOf(string $name): array
    {
        return RefJudge::where('judge', $name)->with('user')->get()->pluck('user.email')->all();
    }

    public function test_uses_last_name_then_initials_then_number(): void
    {
        foreach (range(1, 4) as $i) {
            $this->createJudge('June sab Delacerna', (string) $i);
        }

        $this->assertSame([
            'delacerna@tabulation.com',
            'jdelacerna@tabulation.com',
            'jsdelacerna@tabulation.com',
            'delacerna2@tabulation.com',
        ], $this->emailOf('June sab Delacerna'));
    }

    public function test_name_parsing(): void
    {
        $cases = [
            'June sab Delacerna' => 'delacerna',
            'Mr. Lesther Jan Madelo' => 'madelo',
            'Juan Dela Cruz Jr.' => 'delacruz',
            'Maria De Los Santos III' => 'delossantos',
            'Cinderella Faye Obeñita' => 'obenita',
            'Mary Chris Velez- Suico' => 'suico',
            'Marie Elaine Salvaña-Unchuan' => 'unchuan',
            "Dr. Sean O'Brien" => 'obrien',
            'Madonna' => 'madonna',
            '!!!' => 'judge',
        ];
        foreach ($cases as $name => $local) {
            $this->assertSame($local . '@tabulation.com', JudgeEmail::generate($name), $name);
        }
    }

    public function test_editing_keeps_email_and_password(): void
    {
        $this->createJudge('June sab Delacerna');
        $judge = RefJudge::where('judge', 'June sab Delacerna')->first();
        $user = $judge->user;
        $user->forceFill(['email' => 'custom@tabulation.com', 'password' => bcrypt('secret123')])->save();
        $hash = $user->fresh()->password;

        Livewire::actingAs($this->admin)->test(Judges::class)
            ->call('editJudge', $judge->id)
            ->set('judge', 'June Sab Villarin')   // even a name change keeps the login
            ->call('saveJudge')
            ->assertHasNoErrors();

        $user = $user->fresh();
        $this->assertSame('custom@tabulation.com', $user->email);
        $this->assertSame($hash, $user->password);
        $this->assertSame('June Sab Villarin', $user->name);
        $this->assertSame(1, User::where('role', 'user')->count());
    }

    public function test_judges_are_ordered_by_judge_number_numerically(): void
    {
        foreach (['10', '2', '', '1', '3'] as $no) {
            RefJudge::create(['category' => ['dance'], 'judge' => "Judge {$no}", 'nickname' => $no, 'is_active' => 1]);
        }

        $this->assertSame(['1', '2', '3', '10', ''], RefJudge::orderByNumber()->pluck('nickname')->all());

        Livewire::actingAs($this->admin)->test(Judges::class)
            ->assertSeeInOrder(['Judge 1', 'Judge 2', 'Judge 3', 'Judge 10']);
    }

    public function test_preview_shows_the_email_that_will_be_used(): void
    {
        User::factory()->create(['role' => 'user', 'email' => 'delacerna@tabulation.com']);

        Livewire::actingAs($this->admin)->test(Judges::class)
            ->call('addJudge')
            ->set('judge', 'June sab Delacerna')
            ->assertSee('jdelacerna@tabulation.com');
    }
}
