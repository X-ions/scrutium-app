<?php

use App\Enums\DeliverableStatus;
use App\Models\Campaign;
use App\Models\Deliverable;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliverableEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'manager',
        ]);
    }

    private function makeDeliverable(User $user, array $attributes = []): Deliverable
    {
        $campaign = Campaign::factory()->create(['tenant_id' => $user->tenant_id]);

        return Deliverable::factory()->create(array_merge([
            'tenant_id' => $user->tenant_id,
            'campaign_id' => $campaign->id,
        ], $attributes));
    }

    public function test_no_evidence_reports_nothing_to_show(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user);

        $this->assertSame(
            ['status' => 'none', 'url' => null, 'name' => null],
            $deliverable->evidenceForDisplay()
        );
    }

    public function test_an_external_evidence_url_is_recognised(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'evidence_path' => 'https://cdn.example.com/proof.jpg',
        ]);

        $evidence = $deliverable->evidenceForDisplay();

        $this->assertSame('external', $evidence['status']);
        $this->assertSame('https://cdn.example.com/proof.jpg', $evidence['url']);
    }

    public function test_a_stored_file_is_linked_when_it_exists_on_disk(): void
    {
        config(['filesystems.evidence_disk' => 'public']);
        Storage::fake('public');

        $user = $this->signIn();
        $path = 'deliverables/'.$user->tenant_id.'/proof.jpg';
        Storage::disk('public')->put($path, 'binary');

        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => $path,
        ]);

        $evidence = $deliverable->fresh()->evidenceForDisplay();

        $this->assertSame('file', $evidence['status']);
        $this->assertSame('proof.jpg', $evidence['name']);
        $this->assertStringContainsString('proof.jpg', (string) $evidence['url']);
    }

    public function test_a_missing_stored_file_is_reported_rather_than_linked(): void
    {
        Storage::fake('public');

        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'evidence_path' => 'deliverables/'.$user->tenant_id.'/gone.jpg',
        ]);

        $evidence = $deliverable->evidenceForDisplay();

        $this->assertSame('unavailable', $evidence['status']);
        $this->assertNull($evidence['url'], 'A dead link is worse than an honest "unavailable" message.');
        $this->assertSame('gone.jpg', $evidence['name']);
    }

    public function test_the_deliverable_page_shows_external_evidence(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => 'https://cdn.example.com/proof.jpg',
        ]);

        $this->actingAs($user)
            ->get(route('deliverables.show', $deliverable))
            ->assertOk()
            ->assertSee('Evidence')
            ->assertSee('https://cdn.example.com/proof.jpg', escape: false)
            ->assertSee('Open the evidence link');
    }

    public function test_the_deliverable_page_shows_a_missing_file_honestly(): void
    {
        Storage::fake('public');

        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => 'deliverables/'.$user->tenant_id.'/gone.jpg',
        ]);

        $this->actingAs($user)
            ->get(route('deliverables.show', $deliverable))
            ->assertOk()
            ->assertSee('no longer available');
    }

    public function test_the_rejection_reason_is_visible_on_the_page(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => 'https://cdn.example.com/proof.jpg',
        ]);

        $deliverable->reject('Disclosure missing from caption.', $user);

        $this->actingAs($user)
            ->get(route('deliverables.show', $deliverable))
            ->assertOk()
            ->assertSee('Rejection reason')
            ->assertSee('Disclosure missing from caption.');
    }

    public function test_the_rejection_reason_also_appears_in_the_audit_trail(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => 'https://cdn.example.com/proof.jpg',
        ]);

        $deliverable->reject('Captions were truncated.', $user);

        $this->actingAs($user)
            ->get(route('deliverables.show', $deliverable))
            ->assertOk()
            ->assertSee('Audit trail')
            ->assertSee('Captions were truncated.');
    }

    public function test_no_rejection_banner_on_a_healthy_deliverable(): void
    {
        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user, [
            'status' => DeliverableStatus::Submitted,
            'evidence_path' => 'https://cdn.example.com/proof.jpg',
        ]);

        $this->actingAs($user)
            ->get(route('deliverables.show', $deliverable))
            ->assertOk()
            ->assertDontSee('Rejection reason');
    }

    public function test_an_uploaded_file_is_stored_and_then_visible(): void
    {
        Storage::fake('public');
        config(['filesystems.evidence_disk' => 'public']);

        $user = $this->signIn();
        $deliverable = $this->makeDeliverable($user);

        $this->actingAs($user)
            ->post(route('deliverables.submit', $deliverable), [
                'delivered_units' => 1,
                'evidence' => UploadedFile::fake()->create('proof.jpg', 120, 'image/jpeg'),
            ])
            ->assertRedirect();

        $fresh = $deliverable->fresh();

        $this->assertSame(DeliverableStatus::Submitted, $fresh->statusEnum());
        $this->assertNotNull($fresh->evidence_path);

        Storage::disk('public')->assertExists($fresh->evidence_path);
        $this->assertSame('file', $fresh->evidenceForDisplay()['status']);
    }
}
