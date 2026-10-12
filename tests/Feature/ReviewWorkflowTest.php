<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private User $client;
    private User $teammate;
    private User $outsider;
    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Alice Admin']);
        $this->otherAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->client = User::factory()->create(['role' => 'client', 'name' => 'Carl Client']);
        $this->teammate = User::factory()->create(['role' => 'client', 'name' => 'Tina Teammate']);
        $this->outsider = User::factory()->create(['role' => 'client']);

        $project = Project::create(['name' => 'Website', 'status' => 'active', 'created_by' => $this->admin->id]);
        $project->users()->attach([$this->client->id, $this->teammate->id], [
            'role' => 'member',
            'assigned_by' => $this->admin->id,
            'assigned_at' => now(),
        ]);

        $this->report = Report::create([
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $project->id,
            'priority' => 'high',
            'reported_by' => $this->client->id,
            'status' => 'in_progress',
        ]);
    }

    private function act(User $user, array $payload)
    {
        return $this->actingAs($user)->patchJson('/api/reports/' . $this->report->uuid, $payload);
    }

    private function resolve(): void
    {
        $this->act($this->admin, ['action' => 'resolve', 'resolution_summary' => 'Fixed the session cookie'])->assertOk();
    }

    public function test_resolving_does_not_close_the_issue_and_asks_clients_to_review()
    {
        $this->resolve();

        $this->report->refresh();
        $this->assertSame('resolved', $this->report->status);
        $this->assertNull($this->report->closed_at);

        foreach ([$this->client, $this->teammate] as $client) {
            $notification = $client->notifications()->first();
            $this->assertSame('Please review: Login is broken', $notification->data['title']);
            $this->assertStringContainsString(
                'This issue has been resolved. Please review and confirm whether the issue is fixed.',
                $notification->data['message']
            );
        }
    }

    public function test_other_admins_are_notified_but_the_admin_who_resolved_is_not()
    {
        $this->resolve();

        $notification = $this->otherAdmin->notifications()->first();
        $this->assertSame('Report resolved: Login is broken', $notification->data['title']);
        $this->assertStringContainsString('Waiting for the client to review', $notification->data['message']);

        $this->assertCount(0, $this->admin->notifications);
        $this->assertCount(0, $this->outsider->notifications);
    }

    public function test_the_review_email_tells_the_client_what_to_do()
    {
        $this->resolve();

        $bodies = app('mailer')->getSymfonyTransport()->messages()
            ->mapWithKeys(fn ($sent) => [$sent->getOriginalMessage()->getTo()[0]->getAddress() => $sent->getOriginalMessage()]);

        $toClient = $bodies[$this->client->email];
        $this->assertSame('Please Review: Login is broken', $toClient->getSubject());
        $this->assertStringContainsString('Please review and confirm whether the issue is fixed.', $toClient->getHtmlBody());
        $this->assertStringContainsString('Review Issue', $toClient->getHtmlBody());

        $toAdmin = $bodies[$this->otherAdmin->email];
        $this->assertSame('Report Resolved: Login is broken', $toAdmin->getSubject());
        $this->assertStringContainsString('waiting for the client to review', $toAdmin->getHtmlBody());
    }

    public function test_a_client_can_confirm_the_fix_which_closes_the_issue()
    {
        $this->resolve();

        $this->act($this->client, ['action' => 'close'])->assertOk();

        $this->report->refresh();
        $this->assertSame('closed', $this->report->status);
        $this->assertNotNull($this->report->closed_at);

        $notification = $this->admin->notifications()->get()->firstWhere('data.new_status', 'closed');
        $this->assertSame('Report closed: Login is broken', $notification->data['title']);
        $this->assertStringContainsString('Carl Client confirmed the fix', $notification->data['message']);
        $this->assertCount(1, $this->client->notifications);
    }

    public function test_a_client_can_reject_the_fix_which_reopens_the_issue()
    {
        $this->resolve();

        $this->act($this->client, ['action' => 'reopen', 'comment' => 'Still fails on Safari'])->assertOk();

        $this->assertSame('reopened', $this->report->fresh()->status);

        $notification = $this->otherAdmin->notifications()->get()->firstWhere('data.new_status', 'reopened');
        $this->assertSame('Report reopened: Login is broken', $notification->data['title']);
        $this->assertStringContainsString('rejected the fix and reopened the issue', $notification->data['message']);
        $this->assertStringContainsString('Reason: Still fails on Safari', $notification->data['message']);
    }

    public function test_another_client_on_the_project_can_also_review()
    {
        $this->resolve();

        $this->actingAs($this->teammate)->get('/reports/' . $this->report->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('canReview', true));

        $this->act($this->teammate, ['action' => 'close'])->assertOk();
        $this->assertSame('closed', $this->report->fresh()->status);
    }

    public function test_clients_outside_the_project_cannot_review()
    {
        $this->resolve();

        $this->act($this->outsider, ['action' => 'close'])->assertForbidden();
        $this->act($this->outsider, ['action' => 'reopen'])->assertForbidden();
        $this->assertSame('resolved', $this->report->fresh()->status);
    }

    public function test_a_project_client_who_did_not_report_the_issue_cannot_close_it_before_it_is_resolved()
    {
        $this->report->update(['status' => 'open']);

        $this->act($this->teammate, ['action' => 'close'])->assertForbidden();
        $this->act($this->teammate, ['action' => 'add_comment', 'comment' => 'Hello'])->assertForbidden();
        $this->assertSame('open', $this->report->fresh()->status);
    }

    public function test_admins_cannot_review_on_behalf_of_the_client_through_the_page_flag()
    {
        $this->resolve();

        $this->actingAs($this->admin)->get('/reports/' . $this->report->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('canReview', false));
    }

    public function test_team_members_can_move_an_issue_between_statuses_as_on_the_board()
    {
        $this->report->update(['status' => 'open']);

        $this->act($this->admin, ['action' => 'transition', 'new_status' => 'in_progress'])->assertOk();

        $this->report->refresh();
        $this->assertSame('in_progress', $this->report->status);
        $this->assertSame($this->admin->id, $this->report->assigned_to);

        $this->act($this->admin, ['action' => 'transition', 'new_status' => 'resolved'])->assertOk();
        $this->assertSame('resolved', $this->report->fresh()->status);
        $this->assertSame('Please review: Login is broken', $this->client->notifications()->get()->firstWhere('data.new_status', 'resolved')->data['title']);
    }

    public function test_an_issue_cannot_skip_steps_when_moved()
    {
        $this->report->update(['status' => 'open']);

        $this->act($this->admin, ['action' => 'transition', 'new_status' => 'resolved'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'An issue cannot move from Open to Resolved.');
        $this->assertSame('open', $this->report->fresh()->status);
    }

    public function test_clients_can_only_move_an_issue_as_part_of_their_review()
    {
        // A client cannot start or resolve work, even on their own issue
        $this->report->update(['status' => 'open']);
        $this->act($this->client, ['action' => 'transition', 'new_status' => 'in_progress'])->assertForbidden();

        $this->report->update(['status' => 'in_progress']);
        $this->act($this->client, ['action' => 'transition', 'new_status' => 'resolved'])->assertForbidden();

        // ...but can confirm or reject a resolved one
        $this->report->update(['status' => 'resolved']);
        $this->act($this->teammate, ['action' => 'transition', 'new_status' => 'reopened'])->assertOk();
        $this->assertSame('reopened', $this->report->fresh()->status);

        $this->act($this->outsider, ['action' => 'transition', 'new_status' => 'closed'])->assertForbidden();
    }
}
