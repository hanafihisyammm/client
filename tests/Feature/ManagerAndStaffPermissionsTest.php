<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class ManagerAndStaffPermissionsTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private Company $company;
    private User $manager;
    private User $staff;
    private User $otherStaff;
    private Project $website;
    private Project $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->website = $this->makeProject($this->company, 'Website');
        $this->mobile = $this->makeProject($this->company, 'Mobile');
        $this->staff = $this->makeStaff($this->company, [$this->website], ['name' => 'Ben Staff']);
        $this->otherStaff = $this->makeStaff($this->company, [$this->mobile], ['name' => 'Chloe Staff']);
    }

    // ---------------------------------------------------------------- Manager

    public function test_a_manager_can_create_and_manage_projects_in_their_company()
    {
        $this->actingAs($this->manager)->post('/projects', [
            'name' => 'Intranet',
            'description' => 'Staff intranet',
            'status' => 'active',
            'client_ids' => [$this->staff->id, $this->otherStaff->id],
        ])->assertRedirect()->assertSessionHas('flash.message', 'Project created successfully. 2 staff members added to project successfully.');

        $project = Project::where('name', 'Intranet')->firstOrFail();
        $this->assertSame($this->company->id, $project->company_id);
        $this->assertCount(2, $project->users);

        $this->actingAs($this->manager)->patch('/projects/' . $project->uuid . '/update', [
            'name' => 'Intranet v2',
            'status' => 'active',
            'client_ids' => [$this->staff->id],
        ])->assertSessionHas('flash.message', 'Project updated successfully. Staff member removed from project successfully.');

        $this->actingAs($this->manager)->delete('/projects/' . $project->id)->assertRedirect();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_a_manager_can_only_assign_their_staff_to_projects()
    {
        $url = '/clients/' . $this->company->uuid . '/staff/' . $this->staff->uuid;

        $this->actingAs($this->manager)->patch($url, ['project_ids' => [$this->website->id, $this->mobile->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Projects updated for Ben Staff.');
        $this->assertEqualsCanonicalizing([$this->website->id, $this->mobile->id], $this->staff->projectIds()->all());

        // A name or email sent along with the projects is ignored
        $this->actingAs($this->manager)->patch($url, [
            'name' => 'Renamed By Manager',
            'email' => 'renamed@example.com',
            'project_ids' => [$this->mobile->id],
        ])->assertSessionHasNoErrors();

        $this->staff->refresh();
        $this->assertSame('Ben Staff', $this->staff->name);
        $this->assertNotSame('renamed@example.com', $this->staff->email);
        $this->assertSame([$this->mobile->id], $this->staff->projectIds()->all());
    }

    public function test_a_manager_cannot_add_deactivate_reactivate_or_reinvite_staff()
    {
        Notification::fake();
        $base = '/clients/' . $this->company->uuid . '/staff';

        $this->actingAs($this->manager)->post($base, ['email' => 'dana.new@example.com'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'dana.new@example.com']);

        $this->actingAs($this->manager)->post($base . '/' . $this->staff->uuid . '/deactivate')->assertForbidden();
        $this->assertNull($this->staff->fresh()->deactivated_at);

        $this->otherStaff->update(['deactivated_at' => now()]);
        $this->actingAs($this->manager)->post($base . '/' . $this->otherStaff->uuid . '/reactivate')->assertForbidden();
        $this->assertNotNull($this->otherStaff->fresh()->deactivated_at);

        $this->staff->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->manager)->post($base . '/' . $this->staff->uuid . '/invitation')->assertForbidden();
        Notification::assertNothingSent();

        // The Users page, where accounts and roles are created, is for admins only
        $this->actingAs($this->manager)->get('/users/create')->assertForbidden();
        $this->actingAs($this->manager)->post('/users', ['email' => 'boss@example.com', 'role' => 'admin'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);

        // ...and the manager's own account cannot be reached through the staff routes
        $this->actingAs($this->manager)
            ->patch($base . '/' . $this->manager->uuid, ['project_ids' => []])
            ->assertNotFound();
    }

    public function test_an_admin_can_add_edit_and_deactivate_staff_in_a_company()
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        [$other] = $this->makeCompany('Borneo');
        $base = '/clients/' . $this->company->uuid . '/staff';

        // The role and company come from the URL, never from the form
        $this->actingAs($admin)->post($base, [
            'email' => 'dana.new@example.com',
            'role' => 'admin',
            'company_id' => $other->id,
            'project_ids' => [$this->website->id, $this->mobile->id],
        ])->assertSessionHas('flash.message', 'Staff account created. Invitation sent to dana.new@example.com.');

        $dana = User::where('email', 'dana.new@example.com')->firstOrFail();
        $this->assertSame('staff', $dana->role);
        $this->assertSame($this->company->id, $dana->company_id);
        $this->assertSame('Dana New', $dana->name);
        $this->assertNull($dana->email_verified_at);
        $this->assertEqualsCanonicalizing([$this->website->id, $this->mobile->id], $dana->projectIds()->all());
        Notification::assertSentTo($dana, UserInvited::class);

        $this->actingAs($admin)->patch($base . '/' . $dana->uuid, [
            'name' => 'Dana Lee',
            'email' => 'dana.lee@example.com',
            'project_ids' => [$this->mobile->id],
        ])->assertSessionHas('flash.message', 'Staff member updated successfully.');

        $dana->refresh();
        $this->assertSame('Dana Lee', $dana->name);
        $this->assertSame([$this->mobile->id], $dana->projectIds()->all());

        $this->actingAs($admin)->post($base . '/' . $dana->uuid . '/deactivate')->assertSessionHas('flash');
        $this->assertNotNull($dana->fresh()->deactivated_at);

        $this->actingAs($admin)->post($base . '/' . $dana->uuid . '/reactivate')->assertSessionHas('flash');
        $this->assertNull($dana->fresh()->deactivated_at);
    }

    public function test_a_manager_can_assign_issues_only_to_staff_on_that_project()
    {
        $issue = $this->makeIssue($this->website, $this->staff);

        $this->act($this->manager, $issue, ['action' => 'assign', 'assignee_id' => $this->otherStaff->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Chloe Staff is not on this project. Add them to the project first.');

        $this->act($this->manager, $issue, ['action' => 'assign', 'assignee_id' => $this->staff->id])
            ->assertOk()
            ->assertJsonPath('message', 'Issue assigned to Ben Staff.');

        $names = collect($this->actingAs($this->manager)->getJson('/api/team-members?project_id=' . $this->website->id)->json())->pluck('name');
        $this->assertContains('Ben Staff', $names);
        $this->assertContains($this->manager->name, $names);
        $this->assertNotContains('Chloe Staff', $names);
    }

    public function test_a_deactivated_staff_member_cannot_be_assigned_or_added_to_projects()
    {
        $this->staff->update(['deactivated_at' => now()]);
        $issue = $this->makeIssue($this->website, $this->manager);

        $this->act($this->manager, $issue, ['action' => 'assign', 'assignee_id' => $this->staff->id])->assertStatus(422);

        $this->actingAs($this->manager)->patch('/projects/' . $this->mobile->uuid . '/update', [
            'name' => 'Mobile',
            'status' => 'active',
            'client_ids' => [$this->staff->id],
        ])->assertSessionHasErrors('client_ids');
    }

    public function test_a_manager_sees_their_own_company_page_and_everything_in_the_company()
    {
        $this->makeIssue($this->website, $this->staff);
        $this->makeIssue($this->mobile, $this->otherStaff);

        $this->actingAs($this->manager)->get('/my-company')->assertRedirect('/clients/' . $this->company->uuid);

        $this->actingAs($this->manager)->get('/clients/' . $this->company->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/show')
                ->where('counts.staff', 2)
                ->where('counts.projects', 2)
                ->where('counts.issues', 2)
                ->where('canManageStaff', false)
                ->where('canAssignStaff', true)
                ->where('canManageProjects', true)
                ->where('canManageCompany', false));

        $this->assertCount(2, $this->actingAs($this->manager)->getJson('/api/reports?per_page=200')->json('data'));
    }

    // ------------------------------------------------------------------ Staff

    public function test_staff_only_see_projects_and_issues_they_are_assigned_to()
    {
        $mine = $this->makeIssue($this->website, $this->otherStaff, ['title' => 'In my project']);
        $hidden = $this->makeIssue($this->mobile, $this->otherStaff, ['title' => 'Other project']);
        $assigned = $this->makeIssue($this->mobile, $this->otherStaff, ['title' => 'Assigned to me', 'assigned_to' => $this->staff->id]);

        $titles = collect($this->actingAs($this->staff)->getJson('/api/reports?per_page=200')->json('data'))->pluck('title');
        $this->assertEqualsCanonicalizing(['In my project', 'Assigned to me'], $titles->all());

        $this->actingAs($this->staff)->get('/reports/' . $mine->uuid)->assertOk();
        $this->actingAs($this->staff)->get('/reports/' . $assigned->uuid)->assertOk();
        $this->actingAs($this->staff)->get('/reports/' . $hidden->uuid)->assertForbidden();
        $this->act($this->staff, $hidden, ['action' => 'add_comment', 'comment' => 'Hello'])->assertForbidden();

        $this->actingAs($this->staff)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.name', 'Website')->where('canManage', false));
        $this->actingAs($this->staff)->get('/projects/view/' . $this->mobile->uuid)->assertForbidden();
    }

    public function test_staff_can_belong_to_several_projects_in_their_company()
    {
        $this->addToProject($this->mobile, $this->staff);
        $this->makeIssue($this->website, $this->manager, ['title' => 'Website issue']);
        $this->makeIssue($this->mobile, $this->manager, ['title' => 'Mobile issue']);

        $this->actingAs($this->staff)->get('/projects')->assertInertia(fn (Assert $page) => $page->has('projects', 2));

        $titles = collect($this->actingAs($this->staff)->getJson('/api/reports?per_page=200')->json('data'))->pluck('title');
        $this->assertEqualsCanonicalizing(['Website issue', 'Mobile issue'], $titles->all());

        foreach ([$this->website, $this->mobile] as $project) {
            $this->actingAs($this->staff)->postJson('/api/reports', [
                'title' => 'From staff',
                'description' => 'Details',
                'project_id' => $project->id,
                'priority' => 'low',
            ])->assertCreated();
        }
    }

    public function test_staff_create_issues_only_in_their_own_active_projects()
    {
        $payload = ['title' => 'New issue', 'description' => 'Details', 'priority' => 'low'];

        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['project_id' => $this->website->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Issue created successfully.');

        // A project in the same company that they are not assigned to
        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['project_id' => $this->mobile->id])->assertForbidden();

        // A project they are on, but which is no longer active
        $this->website->update(['status' => 'completed']);
        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['project_id' => $this->website->id])->assertStatus(422);

        $this->assertSame(1, Report::count());
    }

    public function test_staff_can_work_issues_but_cannot_manage_projects_users_or_assignments()
    {
        $issue = $this->makeIssue($this->website, $this->manager);

        $this->act($this->staff, $issue, ['action' => 'start_progress'])->assertOk();
        $this->act($this->staff, $issue, ['action' => 'add_comment', 'comment' => 'Working on it'])->assertOk();
        $this->act($this->staff, $issue, ['action' => 'resolve', 'resolution_summary' => 'Fixed'])->assertOk();

        $this->act($this->staff, $issue, ['action' => 'assign', 'assignee_id' => $this->staff->id])->assertForbidden();
        $this->act($this->staff, $issue, ['action' => 'update_report', 'title' => 'Renamed', 'description' => 'x', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($this->staff)->deleteJson('/api/reports/' . $issue->uuid)->assertForbidden();

        $this->actingAs($this->staff)->get('/projects/create')->assertForbidden();
        $this->actingAs($this->staff)->post('/projects', ['name' => 'Mine', 'status' => 'active'])->assertForbidden();
        $this->actingAs($this->staff)->get('/projects/' . $this->website->uuid . '/edit')->assertForbidden();
        $this->actingAs($this->staff)->delete('/projects/' . $this->website->id)->assertForbidden();

        $this->actingAs($this->staff)->get('/clients/' . $this->company->uuid)->assertForbidden();
        $this->actingAs($this->staff)->post('/clients/' . $this->company->uuid . '/staff', ['email' => 'friend@example.com'])->assertForbidden();
        $this->actingAs($this->staff)->get('/my-company')->assertForbidden();
        $this->actingAs($this->staff)->get('/users')->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'friend@example.com']);
        $this->assertDatabaseMissing('projects', ['name' => 'Mine']);
    }

    public function test_staff_can_edit_and_delete_only_their_own_issue_while_it_is_still_open()
    {
        $own = $this->makeIssue($this->website, $this->staff);
        $edit = ['action' => 'update_report', 'title' => 'Clearer title', 'description' => 'Details', 'priority' => 'low'];

        $this->act($this->staff, $own, $edit)->assertOk();
        $this->assertSame('Clearer title', $own->fresh()->title);

        $own->update(['status' => 'in_progress']);
        $this->act($this->staff, $own, $edit)->assertForbidden();
        $this->actingAs($this->staff)->deleteJson('/api/reports/' . $own->uuid)->assertForbidden();
    }

    // ---------------------------------------------------------- Deactivation

    public function test_a_deactivated_user_cannot_log_in()
    {
        $this->staff->update(['deactivated_at' => now()]);

        $this->post('/login', ['email' => $this->staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivating_someone_ends_the_session_they_already_have()
    {
        $this->actingAs($this->staff)->get('/dashboard')->assertOk();

        $this->staff->update(['deactivated_at' => now()]);

        $this->actingAs($this->staff->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($this->staff->fresh())->getJson('/api/reports')->assertForbidden();
    }

    public function test_a_feature_request_is_saved_as_a_feature_with_no_severity()
    {
        $payload = ['title' => 'Dark mode', 'description' => 'Please add it', 'project_id' => $this->website->id, 'priority' => 'low'];

        // Even if a severity is sent along, a feature does not keep one
        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['type' => 'feature', 'severity' => 'major'])
            ->assertCreated()
            ->assertJsonPath('report.type', 'feature')
            ->assertJsonPath('report.severity', null);

        $feature = Report::where('title', 'Dark mode')->firstOrFail();

        // Editing it does not give it a severity either
        $this->act($this->staff, $feature, [
            'action' => 'update_report', 'title' => 'Dark mode', 'description' => 'Please add it', 'priority' => 'medium', 'severity' => 'minor',
        ])->assertOk();
        $this->assertNull($feature->fresh()->severity);

        // The severity filter only finds bugs
        $minor = $this->actingAs($this->staff)->getJson('/api/reports?severity=minor')->assertOk()->json('data');
        $this->assertNotContains($feature->uuid, collect($minor)->pluck('uuid')->all());
    }

    public function test_a_bug_keeps_its_severity_and_defaults_to_minor()
    {
        $payload = ['description' => 'Broken', 'project_id' => $this->website->id, 'priority' => 'high'];

        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['title' => 'Crash', 'type' => 'bug', 'severity' => 'critical'])
            ->assertCreated()
            ->assertJsonPath('report.type', 'bug')
            ->assertJsonPath('report.severity', 'critical');

        // No type sent, as older clients do: treated as a bug
        $this->actingAs($this->staff)->postJson('/api/reports', $payload + ['title' => 'Typo'])
            ->assertCreated()
            ->assertJsonPath('report.type', 'bug')
            ->assertJsonPath('report.severity', 'minor');
    }

    /** The details form as the Edit Issue page sends it. */
    private function editIssue(Report $issue, array $changes)
    {
        return $this->act($this->manager, $issue, array_merge([
            'action' => 'update_report',
            'title' => $issue->title,
            'description' => $issue->description,
            'priority' => $issue->priority,
        ], $changes));
    }

    public function test_the_board_and_issue_list_are_told_each_issues_type_and_only_a_bugs_severity()
    {
        $bug = $this->makeIssue($this->website, $this->staff, ['title' => 'Crash', 'type' => 'bug', 'severity' => 'major']);
        $feature = $this->makeIssue($this->website, $this->staff, ['title' => 'Dark mode', 'type' => 'feature']);
        // Created before types existed: no type given, so it is a bug
        $legacy = $this->makeIssue($this->website, $this->staff, ['title' => 'Old one']);

        $rows = collect($this->actingAs($this->manager)->getJson('/api/reports')->assertOk()->json('data'))->keyBy('uuid');

        $shown = fn (Report $issue) => [$rows[(string) $issue->uuid]['type'], $rows[(string) $issue->uuid]['severity']];

        $this->assertSame(['bug', 'major'], $shown($bug));
        $this->assertSame(['feature', null], $shown($feature));
        $this->assertSame(['bug', 'minor'], $shown($legacy));

        $this->actingAs($this->manager)->getJson('/api/reports/' . $feature->uuid)
            ->assertOk()
            ->assertJsonPath('report.type', 'feature')
            ->assertJsonPath('report.severity', null);
    }

    public function test_a_bug_changed_to_a_feature_and_back_gets_its_severity_back()
    {
        $issue = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'critical']);

        $this->editIssue($issue, ['type' => 'feature'])
            ->assertOk()
            ->assertJsonPath('report.type', 'feature')
            ->assertJsonPath('report.severity', null);

        // While it is a feature it has no severity anywhere, including in the severity filter
        $this->assertNull($issue->fresh()->severity);
        $critical = $this->actingAs($this->manager)->getJson('/api/reports?severity=critical')->assertOk()->json('data');
        $this->assertNotContains($issue->uuid, collect($critical)->pluck('uuid')->all());

        $this->editIssue($issue->fresh(), ['type' => 'bug'])
            ->assertOk()
            ->assertJsonPath('report.type', 'bug')
            ->assertJsonPath('report.severity', 'critical');
    }

    public function test_a_feature_that_never_had_a_severity_becomes_a_minor_bug()
    {
        $this->actingAs($this->staff)->postJson('/api/reports', [
            'title' => 'Dark mode', 'description' => 'Please add it', 'project_id' => $this->website->id, 'priority' => 'low', 'type' => 'feature',
        ])->assertCreated();
        $feature = Report::where('title', 'Dark mode')->firstOrFail();

        $this->editIssue($feature, ['type' => 'bug'])
            ->assertOk()
            ->assertJsonPath('report.type', 'bug')
            ->assertJsonPath('report.severity', 'minor');
    }

    public function test_editing_other_details_leaves_the_type_and_severity_alone()
    {
        $bug = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'major']);
        $feature = $this->makeIssue($this->website, $this->staff, ['type' => 'feature']);

        $this->editIssue($bug, ['title' => 'Renamed bug'])->assertOk();
        $this->editIssue($feature, ['title' => 'Renamed feature'])->assertOk();

        $this->assertSame(['bug', 'major'], [$bug->fresh()->type, $bug->fresh()->severity]);
        $this->assertSame(['feature', null], [$feature->fresh()->type, $feature->fresh()->severity]);
    }

    public function test_changing_the_type_is_recorded_on_the_timeline_and_rejects_unknown_types()
    {
        $issue = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'major']);

        $this->editIssue($issue, ['type' => 'feature'])->assertOk();

        $log = $issue->activityLogs()->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['bug', 'major'], [$log->old_values['type'], $log->old_values['severity']]);
        $this->assertSame(['feature', null], [$log->new_values['type'], $log->new_values['severity']]);

        $this->editIssue($issue->fresh(), ['type' => 'task'])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_priority_severity_and_due_date_can_be_edited_and_show_up_everywhere()
    {
        $issue = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'minor', 'priority' => 'low']);
        $due = now(config('app.business_timezone'))->addDays(5)->toDateString();

        $this->editIssue($issue, ['priority' => 'urgent', 'severity' => 'critical', 'due_date' => $due])
            ->assertOk()
            ->assertJsonPath('report.priority', 'urgent')
            ->assertJsonPath('report.severity', 'critical');

        $issue->refresh();
        $this->assertSame(['urgent', 'critical', $due], [$issue->priority, $issue->severity, $issue->due_date->toDateString()]);

        // The Board and Issues list read the same saved values
        $row = collect($this->actingAs($this->manager)->getJson('/api/reports')->json('data'))->firstWhere('uuid', (string) $issue->uuid);
        $this->assertSame(['urgent', 'critical', $due], [$row['priority'], $row['severity'], substr($row['due_date'], 0, 10)]);

        // ...and the change is on the issue's timeline
        $log = $issue->activityLogs()->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['low', 'minor'], [$log->old_values['priority'], $log->old_values['severity']]);
        $this->assertSame(['urgent', 'critical', $due], [$log->new_values['priority'], $log->new_values['severity'], $log->new_values['due_date']]);
    }

    public function test_a_due_date_can_be_cleared_and_set_again()
    {
        $due = now(config('app.business_timezone'))->addDays(3)->toDateString();
        $issue = $this->makeIssue($this->website, $this->staff, ['due_date' => $due]);

        $this->editIssue($issue, ['due_date' => null])->assertOk()->assertJsonPath('report.due_date', null);
        $this->assertNull($issue->fresh()->due_date);

        $this->editIssue($issue->fresh(), ['due_date' => $due])->assertOk();
        $this->assertSame($due, $issue->fresh()->due_date->toDateString());
    }

    public function test_a_new_due_date_cannot_be_in_the_past_but_an_old_one_can_stay()
    {
        $today = now(config('app.business_timezone'));
        $past = $today->copy()->subDays(4)->toDateString();
        $issue = $this->makeIssue($this->website, $this->staff, ['due_date' => $past]);

        // Editing something else on an overdue issue keeps its date
        $this->editIssue($issue, ['due_date' => $past, 'priority' => 'urgent'])->assertOk();
        $this->assertSame($past, $issue->fresh()->due_date->toDateString());

        // Moving it to another past day is refused, today is fine
        $this->editIssue($issue->fresh(), ['due_date' => $today->copy()->subDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
        $this->editIssue($issue->fresh(), ['due_date' => $today->toDateString()])->assertOk();
    }

    public function test_invalid_priority_severity_and_due_date_are_rejected()
    {
        $issue = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'major']);

        $this->editIssue($issue, ['priority' => 'whenever'])->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->editIssue($issue, ['severity' => 'catastrophic'])->assertStatus(422)->assertJsonValidationErrors('severity');
        $this->editIssue($issue, ['due_date' => 'next week sometime'])->assertStatus(422)->assertJsonValidationErrors('due_date');

        $this->assertSame(['high', 'major'], [$issue->fresh()->priority, $issue->fresh()->severity]);
    }

    public function test_only_people_allowed_to_edit_an_issue_can_change_these_fields()
    {
        $issue = $this->makeIssue($this->website, $this->staff, ['type' => 'bug', 'severity' => 'major']);
        $payload = ['action' => 'update_report', 'title' => $issue->title, 'description' => $issue->description, 'priority' => 'urgent', 'severity' => 'critical'];

        // Staff on another project cannot see it; another company's manager cannot either
        $this->act($this->otherStaff, $issue, $payload)->assertForbidden();
        [, $outsideManager] = $this->makeCompany('Borneo');
        $this->act($outsideManager, $issue, $payload)->assertForbidden();

        // A teammate on the project who did not report it cannot edit its details
        $teammate = $this->makeStaff($this->company, [$this->website]);
        $this->act($teammate, $issue, $payload)->assertForbidden();

        $this->assertSame(['high', 'major'], [$issue->fresh()->priority, $issue->fresh()->severity]);

        // The reporter can while it is still open, but not once work has started
        $this->act($this->staff, $issue, $payload)->assertOk();
        $this->assertSame(['urgent', 'critical'], [$issue->fresh()->priority, $issue->fresh()->severity]);

        $this->act($this->manager, $issue->fresh(), ['action' => 'start_progress'])->assertOk();
        $this->act($this->staff, $issue->fresh(), ['priority' => 'low'] + $payload)->assertForbidden();
        $this->assertSame('urgent', $issue->fresh()->priority);
    }

    public function test_the_project_pages_are_given_the_company_manager_as_part_of_the_team()
    {
        $this->actingAs($this->manager)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.company.manager.id', $this->manager->id)
                ->has('projects.0.users', 1));

        $this->actingAs($this->staff)->get('/projects/view/' . $this->website->uuid)
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.company.manager.name', $this->manager->name)
                ->where('project.company.manager.role', 'manager')
                ->missing('project.company.manager.password')
                ->has('project.users', 1));
    }
}
