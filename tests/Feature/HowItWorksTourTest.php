<?php

use App\Enums\DeliverableStatus;
use App\Enums\VettingStatus;
use App\Models\Campaign;
use App\Models\Deliverable;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HowItWorksTourTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'owner'): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
        ]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stepsFor(string $role = 'owner'): array
    {
        $tenant = Tenant::factory()->create();

        return Tour::for(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,
        ]))['steps'];
    }

    public function test_guests_are_redirected_away_from_the_guide(): void
    {
        $this->get('/how-it-works')->assertRedirect('/signin');
    }

    public function test_the_guide_renders_for_an_authenticated_user(): void
    {
        $this->signIn();

        $this->get('/how-it-works')
            ->assertOk()
            ->assertViewIs('pages.scrutium.how-it-works')
            ->assertSee('How it works')
            ->assertSee('A deliverable is one contracted unit');
    }

    public function test_the_guide_lists_steps_in_configured_order(): void
    {
        $this->signIn();

        $response = $this->get('/how-it-works')->assertOk();
        $steps = $response->viewData('steps');

        $this->assertNotEmpty($steps);
        $this->assertSame('welcome', $steps[0]['id']);
        $this->assertSame('finish', $steps[array_key_last($steps)]['id']);

        // The first and last step copy must both reach the rendered page.
        $response->assertSee($steps[0]['title']);
        $response->assertSee($steps[array_key_last($steps)]['title']);
    }

    public function test_steps_resolve_their_route_to_a_usable_link(): void
    {
        foreach ($this->stepsFor() as $step) {
            if ($step['route'] === null) {
                $this->assertNull($step['href'], "Step {$step['id']} has no route and should have no href.");

                continue;
            }

            $this->assertNotEmpty(
                $step['href'],
                "Step {$step['id']} declares route {$step['route']} but resolved no URL."
            );
        }
    }

    public function test_read_only_roles_never_see_operator_only_steps(): void
    {
        $viewerIds = array_column($this->stepsFor('viewer'), 'id');
        $ownerIds = array_column($this->stepsFor('owner'), 'id');

        foreach (['evidence', 'verification', 'scoring', 'reports'] as $operatorOnly) {
            $this->assertContains($operatorOnly, $ownerIds, "Owner should see the {$operatorOnly} step.");
            $this->assertNotContains($operatorOnly, $viewerIds, "Viewer must not see the {$operatorOnly} step.");
        }

        $this->assertLessThan(count($ownerIds), count($viewerIds));
    }

    public function test_only_workspace_admins_see_the_settings_step(): void
    {
        $managerIds = array_column($this->stepsFor('manager'), 'id');
        $adminIds = array_column($this->stepsFor('admin'), 'id');

        $this->assertNotContains('settings', $managerIds, 'Manager cannot open Settings.');
        $this->assertContains('settings', $adminIds, 'Admin should be shown the Settings step.');
    }

    public function test_every_audience_value_in_the_config_is_known(): void
    {
        $known = ['any', 'operator', 'admin'];

        foreach (config('tour.steps') as $step) {
            $this->assertContains(
                $step['audience'] ?? 'any',
                $known,
                "Step {$step['id']} has an unrecognised audience value."
            );
        }
    }

    public function test_tour_claims_agree_with_the_domain_enums(): void
    {
        // The tour tells users that only Vetted creators can be booked and that
        // Approved/Rejected are the final deliverable states. If those enums
        // change, the tour is lying and this should fail.
        $vetting = VettingStatus::options();
        $deliverable = DeliverableStatus::options();

        $this->assertArrayHasKey('vetted', $vetting);
        $this->assertArrayHasKey('pending', $vetting);
        $this->assertSame('Vetted', $vetting['vetted']);
        $this->assertSame('Pending review', $vetting['pending']);

        foreach (['pending', 'submitted', 'approved', 'rejected'] as $state) {
            $this->assertArrayHasKey($state, $deliverable, "DeliverableStatus is missing {$state}.");
        }

        // The lifecycle the tour describes: only Vetted is bookable, and
        // approved/rejected are the two outcomes.
        $this->assertTrue(VettingStatus::Vetted->isBookable());
        $this->assertFalse(VettingStatus::Sourced->isBookable());
        $this->assertFalse(VettingStatus::Pending->isBookable());
    }

    public function test_the_tour_overlay_is_mounted_on_every_authenticated_page(): void
    {
        $this->signIn();

        $this->get('/')
            ->assertOk()
            ->assertSee('scrutiumTour', escape: false);
    }

    public function test_the_header_exposes_a_how_it_works_entry_point(): void
    {
        $this->signIn();

        $this->get('/')
            ->assertOk()
            ->assertSee(route('how-it-works'), escape: false);
    }

    public function test_a_viewer_is_never_told_to_perform_a_write_action(): void
    {
        $this->signIn('viewer');

        $this->get('/how-it-works')
            ->assertOk()
            // Operator-only steps must be absent...
            ->assertDontSee('Evidence is not optional')
            ->assertDontSee('Approving moves real numbers')
            ->assertDontSee('Workspace settings')
            // ...while read-only guidance is still present.
            ->assertSee('Only vetted creators can be booked');
    }

    public function test_the_auto_start_flag_only_fires_on_the_dashboard(): void
    {
        $this->signIn();

        // The flag is passed straight into the Alpine x-data expression, so the
        // rendered attribute is the thing worth asserting on.
        $this->get('/')
            ->assertOk()
            ->assertSee('autoStart: true', escape: false);

        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('autoStart: false', escape: false);
    }

    public function test_a_rejected_deliverable_cannot_be_resubmitted(): void
    {
        // The view hides the submit form for rejected rows, but the controller
        // only guarded Approved, so a crafted POST could resurrect a rejected
        // deliverable and clear its rejection reason. The tour tells users
        // Rejected is final, so the code has to agree.
        $user = $this->signIn('manager');
        $campaign = Campaign::factory()->create(['tenant_id' => $user->tenant_id]);
        $deliverable = Deliverable::factory()->create([
            'tenant_id' => $user->tenant_id,
            'campaign_id' => $campaign->id,
            'status' => DeliverableStatus::Rejected,
        ]);

        $this->actingAs($user)
            ->post(route('deliverables.submit', $deliverable), [
                'delivered_units' => 1,
                'evidence_url' => 'https://example.com/proof.jpg',
            ])
            ->assertStatus(422);

        $this->assertSame(DeliverableStatus::Rejected, $deliverable->fresh()->statusEnum());
    }

    public function test_seeded_submitted_deliverables_carry_evidence(): void
    {
        // approve() refuses rows with no evidence, so seeded Submitted rows
        // without it made the demo worklist impossible to work through.
        $this->seed(\ScrutiumDemoSeeder::class);

        $submitted = Deliverable::query()
            ->where('status', DeliverableStatus::Submitted)
            ->get();

        $this->assertNotEmpty($submitted, 'Seeder should produce Submitted deliverables.');

        foreach ($submitted as $deliverable) {
            $this->assertNotNull(
                $deliverable->evidence_path,
                "Seeded deliverable {$deliverable->id} is Submitted with no evidence and cannot be approved."
            );
        }
    }

    public function test_the_spotlight_ring_class_matches_its_css_utility(): void
    {
        // The ring element is created in JS and only styled if Tailwind emitted
        // the matching utility, which it does by seeing the literal class in a
        // scanned file. Rename one side without the other and the spotlight
        // silently renders as an unstyled div, so pin them together.
        $js = (string) file_get_contents(resource_path('js/components/tour.js'));
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertSame(
            1,
            preg_match("/const HIGHLIGHT_CLASS = '([^']+)'/", $js, $matches),
            'Could not read HIGHLIGHT_CLASS from tour.js.'
        );

        $class = $matches[1];

        $this->assertStringContainsString(
            "@utility {$class}",
            $css,
            "app.css has no `{$class}` utility to style the spotlight ring."
        );
    }

    public function test_the_tour_data_survives_the_html_attribute(): void
    {
        $this->signIn();

        $html = $this->get('/')->assertOk()->getContent();

        // Laravel's @js() emits JSON.parse('...') inside a double-quoted x-data
        // attribute, so the payload must be free of BOTH quote characters. A raw
        // " would terminate the HTML attribute early; a raw ' would terminate the
        // JS string. Either one silently breaks Alpine on every page.
        $this->assertStringContainsString('scrutiumTour(JSON.parse(', $html, 'Tour steps were not inlined.');
        $this->assertStringContainsString('currentRoute:', $html, 'Tour options were truncated.');

        $payload = Str::between($html, "scrutiumTour(JSON.parse('", "'), { storageKey:");

        $this->assertNotSame('', $payload, 'Could not isolate the tour payload.');
        $this->assertStringNotContainsString('"', $payload, 'Unescaped double quote inside the tour payload.');
        $this->assertStringNotContainsString("'", $payload, 'Unescaped single quote inside the tour payload.');

        // Copy legitimately contains apostrophes ("a campaign's roster"); they must
        // arrive escaped rather than terminating the JS string.
        $this->assertStringContainsString('\u0027s', $payload, 'Apostrophes should be unicode-escaped.');
        $this->assertStringContainsString('welcome', $payload);
        $this->assertStringContainsString('finish', $payload);
    }
}
