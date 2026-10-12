<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private User $client;
    private User $teammate;
    private User $outsider;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->teammate = User::factory()->create(['role' => 'client']);
        $this->outsider = User::factory()->create(['role' => 'client']);

        $this->project = Project::create([
            'name' => 'Website',
            'description' => 'Company website',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        $this->project->users()->attach([$this->client->id, $this->teammate->id], [
            'role' => 'member',
            'assigned_by' => $this->admin->id,
            'assigned_at' => now(),
        ]);
    }

    private function makeReport(array $attributes = []): Report
    {
        return Report::create(array_merge([
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
            'reported_by' => $this->client->id,
            'status' => 'open',
        ], $attributes));
    }

    private function titlesFor(User $user): array
    {
        return $user->notifications()->get()->pluck('data.title')->all();
    }

    private function sentMailCount(): int
    {
        return app('mailer')->getSymfonyTransport()->messages()->count();
    }

    public function test_creating_a_report_notifies_everyone_on_the_project_except_the_creator()
    {
        $this->actingAs($this->client)->postJson('/api/reports', [
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ])->assertCreated();

        $report = Report::firstOrFail();

        foreach ([$this->admin, $this->otherAdmin, $this->teammate] as $recipient) {
            $notification = $recipient->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('New report: Login is broken', $notification->data['title']);
            $this->assertSame('/reports/' . $report->uuid, $notification->data['url']);
        }

        $this->assertCount(0, $this->client->notifications);
        $this->assertCount(0, $this->outsider->notifications);
        $this->assertSame(3, $this->sentMailCount());
    }

    public function test_in_app_notifications_are_stored_immediately_while_email_is_queued()
    {
        config(['queue.default' => 'database']);

        $this->actingAs($this->client)->postJson('/api/reports', [
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ])->assertCreated();

        $this->assertCount(1, $this->admin->notifications);
        $this->assertSame(0, $this->sentMailCount());
        $this->assertSame(3, DB::table('jobs')->count());
    }

    public function test_status_change_by_an_admin_notifies_clients_and_other_admins()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'start_progress'])
            ->assertOk();

        $this->assertSame(['Report in progress: Login is broken'], $this->titlesFor($this->client));
        $this->assertCount(1, $this->teammate->notifications);
        $this->assertCount(1, $this->otherAdmin->notifications);
        $this->assertCount(0, $this->admin->notifications);
    }

    public function test_reopening_with_a_reason_saves_and_sends_it()
    {
        $report = $this->makeReport(['status' => 'resolved']);

        $this->actingAs($this->client)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'reopen', 'comment' => 'Still fails on Safari'])
            ->assertOk();

        $this->assertSame('reopened', $report->fresh()->status);
        $this->assertSame('Still fails on Safari', $report->comments()->first()->comment);

        $log = $report->activityLogs()->where('action', 'status_changed')->first();
        $this->assertSame('resolved', $log->old_values['status']);
        $this->assertSame('reopened', $log->new_values['status']);
        $this->assertSame('Still fails on Safari', $log->new_values['reason']);

        $notification = $this->admin->notifications()->first();
        $this->assertSame('Report reopened: Login is broken', $notification->data['title']);
        $this->assertSame('Still fails on Safari', $notification->data['reason']);
        $this->assertStringContainsString('Reason: Still fails on Safari', $notification->data['message']);
    }

    public function test_resolving_sends_the_resolution_summary()
    {
        $report = $this->makeReport(['status' => 'in_progress']);

        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'resolve', 'resolution_summary' => 'Fixed the session cookie'])
            ->assertOk();

        $notification = $this->client->notifications()->first();
        $this->assertSame('Report resolved: Login is broken', $notification->data['title']);
        $this->assertStringContainsString('Resolution: Fixed the session cookie', $notification->data['message']);
    }

    public function test_assigning_a_report_notifies_the_assignee()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'assign', 'assignee_id' => $this->otherAdmin->id])
            ->assertOk();

        $notification = $this->otherAdmin->notifications()->first();
        $this->assertSame('Assigned to you: Login is broken', $notification->data['title']);
        $this->assertSame('/reports/' . $report->uuid, $notification->data['url']);
        $this->assertCount(0, $this->admin->notifications);
    }

    public function test_editing_a_report_sends_one_notification_listing_the_changes()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)->patchJson('/api/reports/' . $report->uuid, [
            'action' => 'update_report',
            'title' => 'Login is broken on Safari',
            'description' => 'Cannot log in',
            'priority' => 'urgent',
            'status' => 'open',
        ])->assertOk();

        $notifications = $this->client->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('Report updated: Login is broken on Safari', $notifications[0]->data['title']);
        $this->assertSame(['title', 'priority'], array_keys($notifications[0]->data['changes']));
    }

    public function test_saving_a_report_without_changes_sends_nothing()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)->patchJson('/api/reports/' . $report->uuid, [
            'action' => 'update_report',
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'priority' => 'high',
            'status' => 'open',
        ])->assertOk();

        $this->assertCount(0, $this->client->notifications);
    }

    public function test_commenting_notifies_the_project()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'add_comment', 'comment' => 'Looking into it'])
            ->assertOk();

        $this->assertSame(['New comment: Login is broken'], $this->titlesFor($this->client));
        $this->assertCount(0, $this->admin->notifications);
    }

    public function test_deleting_a_report_notifies_the_project()
    {
        $report = $this->makeReport();

        $this->actingAs($this->admin)->deleteJson('/api/reports/' . $report->uuid)->assertOk();

        $this->assertSame(['Report deleted: Login is broken'], $this->titlesFor($this->client));
        $this->assertSame('/dashboard', $this->client->notifications()->first()->data['url']);
    }

    public function test_clients_are_notified_when_added_to_a_project()
    {
        $this->actingAs($this->admin)->post('/projects', [
            'name' => 'Mobile App',
            'description' => null,
            'status' => 'active',
            'client_ids' => [$this->outsider->id],
        ])->assertRedirect();

        $this->assertSame(['Added to project: Mobile App'], $this->titlesFor($this->outsider));

        $this->actingAs($this->admin)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website',
            'description' => 'Company website',
            'status' => 'active',
            'client_ids' => [$this->client->id, $this->teammate->id, $this->outsider->id],
        ])->assertRedirect();

        $this->assertCount(2, $this->outsider->notifications);
        $this->assertCount(0, $this->client->notifications);
    }

    public function test_project_update_links_admins_to_the_project_and_clients_to_the_dashboard()
    {
        $this->actingAs($this->admin)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website v2',
            'description' => 'Company website',
            'status' => 'active',
            'client_ids' => [$this->client->id, $this->teammate->id],
        ])->assertRedirect();

        $this->assertSame('/dashboard', $this->client->notifications()->first()->data['url']);
        $this->assertSame(
            '/projects/view/' . $this->project->uuid,
            $this->otherAdmin->notifications()->first()->data['url']
        );
    }

    public function test_new_users_get_a_welcome_notification()
    {
        $this->actingAs($this->otherAdmin)->post('/users', [
            'name' => 'New Client',
            'email' => 'new.client@example.com',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
            'role' => 'client',
        ])->assertRedirect();

        $newUser = User::where('email', 'new.client@example.com')->firstOrFail();

        $this->assertSame(['Welcome, New Client!'], $this->titlesFor($newUser));
        $this->assertSame(1, $this->sentMailCount());
    }

    public function test_users_can_list_and_read_only_their_own_notifications()
    {
        $report = $this->makeReport();
        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'start_progress'])
            ->assertOk();

        $id = $this->client->notifications()->first()->id;

        $this->actingAs($this->client)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.id', $id)
            ->assertJsonPath('notifications.0.url', '/reports/' . $report->uuid);

        $this->actingAs($this->outsider)->postJson("/api/notifications/{$id}/read")->assertNotFound();

        $this->actingAs($this->client)->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($this->teammate)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->teammate->unreadNotifications()->count());
    }
}
