<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private User $admin;
    private Company $company;
    private User $manager;
    private User $staff;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin('admin', ['name' => 'Alice Admin']);
        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($this->company, 'Website');
        $this->staff = $this->makeStaff($this->company, [$this->project], ['name' => 'Sam Staff']);

        AuditLog::query()->delete();
    }

    private function actions(): array
    {
        return AuditLog::orderBy('id')->pluck('action')->all();
    }

    public function test_only_admins_can_view_the_audit_log()
    {
        $this->get('/audit-logs')->assertRedirect('/login');
        $this->actingAs($this->staff)->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->manager)->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->admin)->get('/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/audit-logs/index'));
    }

    public function test_issue_activity_is_recorded_with_the_actor_and_changes()
    {
        $this->actingAs($this->staff)->postJson('/api/reports', [
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'page_url' => 'https://example.com/login',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ])->assertCreated();

        $issue = Report::firstOrFail();

        $this->act($this->admin, $issue, ['action' => 'start_progress'])->assertOk();
        $this->act($this->admin, $issue, ['action' => 'add_comment', 'comment' => 'On it'])->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/reports/' . $issue->uuid)->assertOk();

        $actions = $this->actions();
        $this->assertSame('report.created', $actions[0]);
        $this->assertNotContains('project.updated', $actions);
        $this->assertContains('comment.created', $actions);
        $this->assertSame('report.deleted', end($actions));

        $created = AuditLog::where('action', 'report.created')->first();
        $this->assertSame('Sam Staff', $created->user_name);
        $this->assertSame('Created report "Login is broken"', $created->description);

        $updated = AuditLog::where('action', 'report.updated')->orderBy('id')->first();
        $this->assertSame('Alice Admin', $updated->user_name);
        $this->assertSame('Changed status of report "Login is broken" from Open to In progress', $updated->description);
        $this->assertSame(['old' => 'open', 'new' => 'in_progress'], $updated->changes['status']);
    }

    public function test_user_changes_are_recorded_without_exposing_passwords()
    {
        $superAdmin = $this->makeAdmin('super_admin');
        AuditLog::query()->delete();

        $this->actingAs($superAdmin)->post('/users', [
            'email' => 'new.staff@example.com',
            'role' => 'staff',
            'company_id' => $this->company->id,
        ])->assertRedirect();

        $newUser = User::where('email', 'new.staff@example.com')->firstOrFail();
        $newUser->update(['password' => 'An0ther-Passw0rd!', 'name' => 'Renamed Staff']);

        $this->assertSame(['user.created', 'user.updated'], $this->actions());

        $updated = AuditLog::where('action', 'user.updated')->first();
        $this->assertSame(['old' => '[hidden]', 'new' => '[hidden]'], $updated->changes['password']);
        $this->assertSame(['old' => 'New Staff', 'new' => 'Renamed Staff'], $updated->changes['name']);
        $this->assertStringNotContainsString('Passw0rd', AuditLog::all()->toJson());
    }

    public function test_company_project_and_membership_changes_are_recorded()
    {
        $newStaff = $this->makeStaff($this->company, [], ['name' => 'Nina New']);
        AuditLog::query()->delete();

        $this->actingAs($this->manager)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website v2',
            'status' => 'active',
            'client_ids' => [$newStaff->id],
        ])->assertRedirect();

        $this->assertSame(
            ['project.updated', 'project.members_added', 'project.members_removed'],
            $this->actions()
        );
        $this->assertSame(
            'Added Nina New to project "Website v2"',
            AuditLog::where('action', 'project.members_added')->first()->description
        );
        $this->assertSame(
            'Removed Sam Staff from project "Website v2"',
            AuditLog::where('action', 'project.members_removed')->first()->description
        );

        AuditLog::query()->delete();
        $this->actingAs($this->admin)->post('/clients', ['name' => 'Borneo Foods', 'manager_email' => 'farah@example.com'])->assertRedirect();

        $this->assertSame(
            'Created company "Borneo Foods"',
            AuditLog::where('action', 'company.created')->first()->description
        );

        AuditLog::query()->delete();
        $this->actingAs($this->admin)->post('/clients/' . $this->company->uuid . '/staff/' . $newStaff->uuid . '/deactivate');
        $this->assertArrayHasKey('deactivated_at', AuditLog::where('action', 'user.updated')->first()->changes);
    }

    public function test_logins_and_failed_logins_are_recorded()
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'wrong-password']);
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password']);

        $this->assertSame(['auth.failed', 'auth.login'], $this->actions());
        $this->assertSame(
            'Failed login attempt for ' . $this->admin->email,
            AuditLog::where('action', 'auth.failed')->first()->description
        );
        $this->assertSame('Alice Admin', AuditLog::where('action', 'auth.login')->first()->user_name);
    }

    public function test_the_log_can_be_filtered_and_searched()
    {
        AuditLog::record('report.created', 'Created report "Login is broken"', 'report', 1, 'Login is broken', null, $this->staff);
        AuditLog::record('project.updated', 'Updated project "Website"', 'project', 1, 'Website', null, $this->admin);
        AuditLog::record('auth.login', 'Logged in', 'user', $this->admin->id, $this->admin->email, null, $this->admin);

        $this->actingAs($this->admin)->get('/audit-logs')
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 3));

        $this->actingAs($this->admin)->get('/audit-logs?type=report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.action', 'report.created'));

        $this->actingAs($this->admin)->get('/audit-logs?user=' . $this->admin->id)
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 2));

        $this->actingAs($this->admin)->get('/audit-logs?search=LOGIN+IS')
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1));

        $this->actingAs($this->admin)->get('/audit-logs?from=' . now()->addDay()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
    }
}
