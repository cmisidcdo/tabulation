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

    public function test_reload_without_cookie_or_local_storage_is_recognised_via_session(): void
    {
        PC::setOpen($this->slug, true);

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->call('vote', 'group a')
            ->assertSet('votedFor', 'Group A');

        // Reload where the pc_device cookie was dropped and localStorage is unavailable:
        // the session still knows the device, so the list is not shown again.
        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->assertSet('votedFor', 'Group A')
            ->assertDontSee('Submit Vote')
            ->call('vote', 'group b');

        $this->assertSame(1, PublicVoteModel::count());
    }

    public function test_reload_with_all_storage_cleared_shows_already_voted_by_fingerprint(): void
    {
        PC::setOpen($this->slug, true);

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->call('vote', 'group a', 'abcdef0123456789')
            ->assertSet('votedFor', 'Group A');

        session()->flush(); // cookie, session and localStorage all gone

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->assertSet('votedFor', null)
            ->call('initDevice', null, 'abcdef0123456789')
            ->assertSet('votedFor', '')
            ->assertSee('already been recorded');

        $this->assertSame(1, PublicVoteModel::count());
    }

    public function test_local_storage_token_that_voted_wins_over_a_fresh_cookie(): void
    {
        PC::setOpen($this->slug, true);
        $this->vote('group c', 'token-local-aaaaaaaaaaaaaa');

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->call('initDevice', 'token-local-aaaaaaaaaaaaaa', null)
            ->assertSet('deviceToken', 'token-local-aaaaaaaaaaaaaa')
            ->assertSet('votedFor', 'Group C')
            ->assertReturned('token-local-aaaaaaaaaaaaaa');
    }

    /* -------------------------------- Geofence -------------------------------- */

    private const VENUE = ['lat' => 8.4822, 'lng' => 124.6472];

    /** A location $metersNorth of the venue (1° latitude ≈ 111,195 m). */
    private function near(float $metersNorth, ?float $accuracy = 10): array
    {
        return ['lat' => self::VENUE['lat'] + $metersNorth / 111195, 'lng' => self::VENUE['lng'], 'accuracy' => $accuracy];
    }

    private function geoVote(string $token, $location): string
    {
        return PC::castVote($this->slug, 'group a', $token, null, '10.0.0.9', 'UA', $location);
    }

    public function test_distance_is_haversine(): void
    {
        $this->assertEqualsWithDelta(111195, PC::distanceMeters(0, 0, 1, 0), 50);
        $far = $this->near(1000);
        $this->assertEqualsWithDelta(1000, PC::distanceMeters(self::VENUE['lat'], self::VENUE['lng'], $far['lat'], $far['lng']), 1);
    }

    public function test_geofence_off_ignores_location(): void
    {
        PC::setOpen($this->slug, true);

        $this->assertNull(PC::geofence($this->slug));
        $this->assertSame(PC::OK, $this->geoVote('token-geo-off-aaaaaaaaaa', null));
        $this->assertNull(PublicVoteModel::first()->latitude);
    }

    public function test_geofence_blocks_missing_and_far_locations_and_accepts_inside(): void
    {
        PC::setOpen($this->slug, true);
        $this->assertNull(PC::setGeofence($this->slug, true, self::VENUE['lat'], self::VENUE['lng'], 150));

        $this->assertSame(PC::NEED_LOCATION, $this->geoVote('token-geo-aaaaaaaaaaaaaa', null));
        $this->assertSame(PC::NEED_LOCATION, $this->geoVote('token-geo-aaaaaaaaaaaaaa', ['lat' => 'x', 'lng' => 1]));
        $this->assertSame(PC::OUTSIDE_AREA, $this->geoVote('token-geo-aaaaaaaaaaaaaa', $this->near(1000)));
        // Huge accuracy only buys 100 m of tolerance: 278 m > 150 + 100.
        $this->assertSame(PC::OUTSIDE_AREA, $this->geoVote('token-geo-aaaaaaaaaaaaaa', $this->near(278, 5000)));
        $this->assertSame(0, PublicVoteModel::count());

        // 167 m away with ±30 m accuracy: within 150 + 30.
        $this->assertSame(PC::OK, $this->geoVote('token-geo-bbbbbbbbbbbbbb', $this->near(167, 30)));
        $this->assertSame(PC::OK, $this->geoVote('token-geo-cccccccccccccc', $this->near(55)));

        $vote = PublicVoteModel::where('device_token', 'token-geo-cccccccccccccc')->first();
        $this->assertEqualsWithDelta(55, $vote->distance_m, 1);
        $this->assertEqualsWithDelta(self::VENUE['lat'], (float) $vote->latitude, 0.001);
        $this->assertNotNull(PC::tally($this->slug)['geofence']);
    }

    public function test_set_geofence_validation(): void
    {
        $this->assertNotNull(PC::setGeofence($this->slug, true, null, null, 150));      // enable without a center
        $this->assertNotNull(PC::setGeofence($this->slug, false, 95, 124, 150));        // bad latitude
        $this->assertNotNull(PC::setGeofence($this->slug, false, 8.48, null, 150));     // only one coordinate
        $this->assertNotNull(PC::setGeofence($this->slug, true, 8.48, 124.64, 5));      // radius too small
        $this->assertNull(PC::geofence($this->slug));

        $this->assertNull(PC::setGeofence($this->slug, false, 8.48, 124.64, 200));     // saved but off
        $this->assertNull(PC::geofence($this->slug));
        $this->assertSame(200, PC::geofenceSettings($this->slug)['radius']);
    }

    public function test_public_page_shows_distance_error_when_outside(): void
    {
        PC::setOpen($this->slug, true);
        PC::setGeofence($this->slug, true, self::VENUE['lat'], self::VENUE['lng'], 150);

        Livewire::test(PublicVote::class, ['slug' => $this->slug])
            ->assertSee('only allowed')
            ->call('vote', 'group a', null, $this->near(2400))
            ->assertSet('votedFor', null)
            ->assertSee('about 2.4 km away')
            ->call('vote', 'group a', null, $this->near(20))
            ->assertSet('votedFor', 'Group A');

        $this->assertSame(1, PublicVoteModel::count());
    }

    public function test_admin_saves_geofence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(\App\Livewire\PeoplesChoice::class)
            ->set('selectedCategory', $this->slug)
            ->set('geoEnabled', true)
            ->call('saveGeofence')
            ->assertSee('Set the venue location first')
            ->set('geoLat', '8.4822000')
            ->set('geoLng', '124.6472000')
            ->set('geoRadius', 120)
            ->call('saveGeofence')
            ->assertSee('within 120 m')
            ->assertSee('ON &middot; 120 m', false)
            ->call('generatePdf')
            ->assertNotSet('base64pdf', null);

        $this->assertSame(120, PC::geofence($this->slug)['radius']);

        // Reloading the page / switching events loads the saved values.
        Livewire::actingAs($admin)->test(\App\Livewire\PeoplesChoice::class)
            ->set('selectedCategory', $this->slug)
            ->assertSet('geoEnabled', true)
            ->assertSet('geoRadius', 120);

        PC::setOpen($this->slug, true);
        $this->actingAs($admin)->get('/peoples-choice/' . $this->slug . '/poster')->assertSee('Vote here at the venue');
        $this->get('/display_peoples_choice/' . $this->slug)->assertSee('Vote here at the venue');
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
