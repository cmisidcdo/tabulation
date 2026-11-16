<?php

namespace Tests\Feature;

use App\Livewire\PublicVote;
use App\Models\Category;
use App\Models\PublicVote as PublicVoteModel;
use App\Models\RefParticipant;
use App\Models\User;
use App\Services\PeoplesChoiceService as PC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PeoplesChoiceTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'storytelling';

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['category' => $this->slug, 'description' => 'Storytelling', 'winners' => 3, 'display_participant' => 1, 'is_active' => 1]);

        $members = [
            ['1', 'Ana', 'Group A'], ['2', 'Ben', 'group a '], // same group, different case/spacing
            ['3', 'Cara', 'Group B'], ['4', 'Dan', 'Group C'],
            ['5', 'Solo', null], // no group: not votable
        ];
        foreach ($members as [$no, $name, $group]) {
            RefParticipant::create(['category' => [$this->slug], 'participant_no' => $no, 'participant' => $name, 'group_name' => $group]);
        }
    }

    private function vote(string $group, string $token, ?string $fp = null, string $ip = '10.0.0.1'): string
    {
        return PC::castVote($this->slug, $group, $token, $fp, $ip, 'UA');
    }

    public function test_groups_are_merged_case_insensitively(): void
    {
        $groups = PC::choices($this->slug);

        $this->assertSame(['group a', 'group b', 'group c'], array_keys($groups));
        $this->assertSame(['Ana', 'Ben'], $groups['group a']['details']);
    }

    public function test_vote_rejected_while_closed(): void
    {
        $this->assertSame(PC::CLOSED, $this->vote('group a', 'token-aaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame(0, PublicVoteModel::count());
    }

    public function test_one_vote_per_device_token(): void
    {
        PC::setOpen($this->slug, true);

        $this->assertSame(PC::OK, $this->vote('group a', 'token-aaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame(PC::ALREADY_VOTED, $this->vote('group b', 'token-aaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame(PC::OK, $this->vote('group b', 'token-bbbbbbbbbbbbbbbbbbbb'));
        $this->assertSame(2, PublicVoteModel::count());
    }

    public function test_same_fingerprint_and_ip_with_new_token_is_rejected(): void
    {
        PC::setOpen($this->slug, true);

        $this->assertSame(PC::OK, $this->vote('group a', 'token-aaaaaaaaaaaaaaaaaaaa', 'abcdef0123456789'));
        $this->assertSame(PC::ALREADY_VOTED, $this->vote('group a', 'token-cccccccccccccccccccc', 'abcdef0123456789'));
        // Same fingerprint from another network (e.g. an identical phone model elsewhere) is still allowed.
        $this->assertSame(PC::OK, $this->vote('group a', 'token-dddddddddddddddddddd', 'abcdef0123456789', '10.0.0.2'));
    }

    public function test_unknown_group_is_invalid(): void
    {
        PC::setOpen($this->slug, true);

        $this->assertSame(PC::INVALID, $this->vote('no such group', 'token-aaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_tally_ranks_and_handles_ties(): void
    {
        PC::setOpen($this->slug, true);
        $this->vote('group a', 'token-1aaaaaaaaaaaaaaaaaaa');
        $this->vote('group b', 'token-2aaaaaaaaaaaaaaaaaaa');
        $this->vote('group a', 'token-3aaaaaaaaaaaaaaaaaaa');
        $this->vote('group b', 'token-4aaaaaaaaaaaaaaaaaaa');

        $tally = PC::tally($this->slug);

        $this->assertSame(4, $tally['total']);
        $byKey = collect($tally['rows'])->keyBy('key');
        $this->assertSame(1, $byKey['group a']['ordinal_rank']);
        $this->assertSame(1, $byKey['group b']['ordinal_rank']);
        $this->assertSame(3, $byKey['group c']['ordinal_rank']);
        $this->assertSame(0, $byKey['group c']['votes']);
        $this->assertEquals(50, $byKey['group a']['percent']);
        $this->assertCount(2, $tally['winners']);
    }

    public function test_public_page_flow_votes_once(): void
    {
        PC::setOpen($this->slug, true);

        $this->get('/vote/' . $this->slug)->assertOk()->assertSee('Storytelling');

        $component = Livewire::test(PublicVote::class, ['slug' => $this->slug]);
        $token = $component->call('initDevice', null, 'abcdef0123456789')->get('deviceToken');
        $this->assertNotEmpty($token);

        $component->call('vote', 'group b')->assertSet('votedFor', 'Group B')->assertSee('Thank you for voting');

        // Reloaded page whose localStorage still holds the token: recognised as already voted.
        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->call('initDevice', $token, null)
            ->assertSet('votedFor', 'Group B');

        $this->assertSame(1, PublicVoteModel::count());
    }

    public function test_vote_works_even_if_browser_script_never_ran(): void
    {
        PC::setOpen($this->slug, true);

        // No initDevice() call at all: the token issued in mount() must be enough.
        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->call('vote', 'group c', 'abcdef0123456789')
            ->assertSet('error', null)
            ->assertSet('votedFor', 'Group C');

        $this->assertSame('abcdef0123456789', PublicVoteModel::first()->fingerprint);
    }

    public function test_participant_mode_votes_for_individuals(): void
    {
        $this->assertTrue(PC::setMode($this->slug, PC::MODE_PARTICIPANT));
        PC::setOpen($this->slug, true);

        $choices = PC::choices($this->slug);
        $this->assertCount(5, $choices); // everyone, including members without a group
        $this->assertSame(['Ana', 'Ben', 'Cara', 'Dan', 'Solo'], array_column(array_values($choices), 'name'));

        $cara = RefParticipant::where('participant', 'Cara')->first();
        $this->assertSame(PC::INVALID, $this->vote('group b', 'token-aaaaaaaaaaaaaaaaaaaa')); // group keys not valid here
        $this->assertSame(PC::OK, $this->vote('p' . $cara->id, 'token-aaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame(PC::OK, $this->vote('p' . $cara->id, 'token-bbbbbbbbbbbbbbbbbbbb'));

        $tally = PC::tally($this->slug);
        $this->assertSame('participant', $tally['mode']);
        $this->assertSame(2, $tally['total']);
        $this->assertSame(['Cara'], array_column($tally['winners'], 'name'));
        $this->assertSame('participant', PublicVoteModel::first()->mode);

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->assertSee('Tap your favorite participant')
            ->assertSee('Solo');
    }

    public function test_participant_mode_admin_pdf_and_led_render(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(\App\Livewire\PeoplesChoice::class)
            ->set('selectedCategory', $this->slug)
            ->call('setMode', 'participant')
            ->assertSee('Participant')
            ->call('toggleOpen')
            ->call('generatePdf')
            ->assertNotSet('base64pdf', null);

        $dan = RefParticipant::where('participant', 'Dan')->first();
        $this->assertSame(PC::OK, $this->vote('p' . $dan->id, 'token-zzzzzzzzzzzzzzzzzzzz'));
        PC::setRevealed($this->slug, true);

        $this->get('/display_peoples_choice/' . $this->slug)->assertOk()->assertSee('Dan')->assertSee('Group C');
    }

    public function test_mode_cannot_change_once_votes_exist(): void
    {
        PC::setOpen($this->slug, true);
        $this->vote('group a', 'token-aaaaaaaaaaaaaaaaaaaa');

        $this->assertFalse(PC::setMode($this->slug, PC::MODE_PARTICIPANT));
        $this->assertSame(PC::MODE_GROUP, PC::mode($this->slug));

        PC::resetVotes($this->slug);
        $this->assertTrue(PC::setMode($this->slug, PC::MODE_PARTICIPANT));
        $this->assertSame(PC::MODE_PARTICIPANT, PC::mode($this->slug));
    }

    public function test_vote_page_404_for_unknown_event(): void
    {
        $this->get('/vote/nope')->assertNotFound();
    }

    public function test_admin_panel_poster_pdf_and_led_render(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/peoples-choice')->assertOk();
        $this->actingAs($admin)->get('/peoples-choice/' . $this->slug . '/poster')->assertOk()->assertSee('data:image/png;base64', false);

        Livewire::actingAs($admin)->test(\App\Livewire\PeoplesChoice::class)
            ->set('selectedCategory', $this->slug)
            ->assertSee('CLOSED')
            ->call('toggleOpen')
            ->assertSee('OPEN')
            ->call('generatePdf')
            ->assertNotSet('base64pdf', null)
            ->call('toggleReveal');

        $this->vote('group c', 'token-zzzzzzzzzzzzzzzzzzzz');
        $this->get('/display_peoples_choice/' . $this->slug)->assertOk()->assertSee('Group C');
    }

    public function test_admin_page_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get('/peoples-choice')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/peoples-choice/' . $this->slug . '/qr.png')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
