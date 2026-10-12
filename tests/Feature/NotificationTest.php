<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private Company $company;
    private User $manager;
    private User $reporter;
    private User $teammate;
    private User $offProject;
    private User $otherCompanyManager;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->otherAdmin = $this->makeAdmin('super_admin');

        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($this->company, 'Website');
        $this->reporter = $this->makeStaff($this->company, [$this->project], ['name' => 'Rita Reporter']);
        $this->teammate = $this->makeStaff($this->company, [$this->project]);
        // Same company, but not on this project
        $this->offProject = $this->makeStaff($this->company);

        [, $this->otherCompanyManager] = $this->makeCompany('Borneo');
    }

    private function issue(array $attributes = []): Report
    {
        return $this->makeIssue($this->project, $this->reporter, $attributes);
    }

    private function titlesFor(User $user): array
    {
        return $user->notifications()->get()->pluck('data.title')->all();
    }

    private function sentMailCount(): int
    {
        return app('mailer')->getSymfonyTransport()->messages()->count();
    }

    private function createIssueAsReporter(): void
    {
        $this->actingAs($this->reporter)->postJson('/api/reports', [
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ])->assertCreated();
    }

    public function test_creating_an_issue_notifies_admins_the_manager_and_project_staff_but_not_the_creator()
    {
        $this->createIssueAsReporter();
        $issue = Report::firstOrFail();

        foreach ([$this->admin, $this->otherAdmin, $this->manager, $this->teammate] as $recipient) {
            $notification = $recipient->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('New report: Login is broken', $notification->data['title']);
            $this->assertSame('/reports/' . $issue->uuid, $notification->data['url']);
        }

        $this->assertCount(0, $this->reporter->notifications);
        $this->assertSame(4, $this->sentMailCount());
    }

    public function test_people_outside_the_project_or_company_are_never_notified()
    {
        $this->createIssueAsReporter();
        $issue = Report::firstOrFail();
        $this->act($this->manager, $issue, ['action' => 'start_progress'])->assertOk();
        $this->act($this->manager, $issue, ['action' => 'add_comment', 'comment' => 'On it'])->assertOk();

        $this->assertCount(0, $this->offProject->notifications);
        $this->assertCount(0, $this->otherCompanyManager->notifications);
    }

    public function test_deactivated_people_are_not_notified()
    {
        $this->teammate->update(['deactivated_at' => now()]);

        $this->createIssueAsReporter();

        $this->assertCount(0, $this->teammate->notifications);
        $this->assertSame(3, $this->sentMailCount());
    }

    public function test_in_app_notifications_are_stored_immediately_while_email_is_queued()
    {
        config(['queue.default' => 'database']);

        $this->createIssueAsReporter();

        $this->assertCount(1, $this->admin->notifications);
        $this->assertSame(0, $this->sentMailCount());
        $this->assertSame(4, DB::table('jobs')->count());
    }

    public function test_a_status_change_notifies_everyone_involved_except_the_person_who_made_it()
    {
        $issue = $this->issue();

        $this->act($this->manager, $issue, ['action' => 'start_progress'])->assertOk();

        $this->assertSame(['Report in progress: Login is broken'], $this->titlesFor($this->reporter));
        $this->assertCount(1, $this->teammate->notifications);
        $this->assertCount(1, $this->admin->notifications);
        $this->assertCount(0, $this->manager->notifications);
    }

    public function test_assigning_an_issue_notifies_the_assignee()
    {
        $issue = $this->issue();

        $this->act($this->manager, $issue, ['action' => 'assign', 'assignee_id' => $this->teammate->id])->assertOk();

        $notification = $this->teammate->notifications()->first();
        $this->assertSame('Assigned to you: Login is broken', $notification->data['title']);
        $this->assertSame('/reports/' . $issue->uuid, $notification->data['url']);
        $this->assertCount(0, $this->manager->notifications);
    }

    public function test_editing_an_issue_sends_one_notification_listing_the_changes()
    {
        $issue = $this->issue();

        $this->act($this->manager, $issue, [
            'action' => 'update_report',
            'title' => 'Login is broken on Safari',
            'description' => 'Cannot log in',
            'priority' => 'urgent',
        ])->assertOk();

        $notifications = $this->reporter->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('Report updated: Login is broken on Safari', $notifications[0]->data['title']);
        $this->assertSame(['title', 'priority'], array_keys($notifications[0]->data['changes']));
    }

    public function test_saving_an_issue_without_changes_sends_nothing()
    {
        $issue = $this->issue();

        $this->act($this->manager, $issue, [
            'action' => 'update_report',
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'priority' => 'high',
        ])->assertOk();

        $this->assertCount(0, $this->reporter->notifications);
    }

    public function test_commenting_notifies_the_project()
    {
        $issue = $this->issue();

        $this->act($this->teammate, $issue, ['action' => 'add_comment', 'comment' => 'Looking into it'])->assertOk();

        $this->assertSame(['New comment: Login is broken'], $this->titlesFor($this->reporter));
        $this->assertCount(1, $this->manager->notifications);
        $this->assertCount(0, $this->teammate->notifications);
    }

    public function test_deleting_an_issue_notifies_the_project()
    {
        $issue = $this->issue();

        $this->actingAs($this->manager)->deleteJson('/api/reports/' . $issue->uuid)->assertOk();

        $this->assertSame(['Report deleted: Login is broken'], $this->titlesFor($this->reporter));
        $this->assertSame('/dashboard', $this->reporter->notifications()->first()->data['url']);
    }

    public function test_creating_a_project_notifies_admins_and_the_company_manager_but_not_the_creator()
    {
        $this->actingAs($this->admin)->post('/projects', [
            'name' => 'Mobile App',
            'status' => 'active',
            'company_id' => $this->company->id,
            'client_ids' => [$this->offProject->id],
        ])->assertRedirect();

        $project = \App\Models\Project::where('name', 'Mobile App')->firstOrFail();

        foreach ([$this->otherAdmin, $this->manager] as $recipient) {
            $this->assertSame(['New project: Mobile App'], $this->titlesFor($recipient));
            $this->assertSame('/projects/view/' . $project->uuid, $recipient->notifications()->first()->data['url']);
        }

        $this->assertCount(0, $this->admin->notifications);
        $this->assertCount(0, $this->otherCompanyManager->notifications);
        // Staff put on the project only hear that they were added
        $this->assertSame(['Added to project: Mobile App'], $this->titlesFor($this->offProject));
        $this->assertCount(0, $this->reporter->notifications);
    }

    public function test_staff_are_notified_when_added_to_a_project()
    {
        $this->actingAs($this->manager)->post('/projects', [
            'name' => 'Mobile App',
            'status' => 'active',
            'client_ids' => [$this->offProject->id],
        ])->assertRedirect();

        $this->assertSame(['Added to project: Mobile App'], $this->titlesFor($this->offProject));

        $this->actingAs($this->manager)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website',
            'status' => 'active',
            'client_ids' => [$this->reporter->id, $this->teammate->id, $this->offProject->id],
        ])->assertRedirect();

        $this->assertCount(2, $this->offProject->notifications);
        $this->assertCount(0, $this->reporter->notifications);
    }

    public function test_a_project_update_links_everyone_to_the_project()
    {
        $this->actingAs($this->manager)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website v2',
            'status' => 'active',
            'client_ids' => [$this->reporter->id, $this->teammate->id],
        ])->assertRedirect();

        $this->assertSame('/projects/view/' . $this->project->uuid, $this->reporter->notifications()->first()->data['url']);
        $this->assertSame('/projects/view/' . $this->project->uuid, $this->admin->notifications()->first()->data['url']);
    }

    public function test_users_can_list_and_read_only_their_own_notifications()
    {
        $issue = $this->issue();
        $this->act($this->manager, $issue, ['action' => 'start_progress'])->assertOk();

        $id = $this->reporter->notifications()->first()->id;

        $this->actingAs($this->reporter)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.id', $id)
            ->assertJsonPath('notifications.0.url', '/reports/' . $issue->uuid);

        $this->actingAs($this->offProject)->postJson("/api/notifications/{$id}/read")->assertNotFound();

        $this->actingAs($this->reporter)->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($this->teammate)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->teammate->unreadNotifications()->count());
    }
}
