<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Alice Admin']);
        $this->client = User::factory()->create(['role' => 'client', 'name' => 'Carl Client']);
        $this->project = Project::create([
            'name' => 'Website',
            'description' => 'Company website',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        $this->project->users()->attach($this->client->id, [
            'role' => 'member',
            'assigned_by' => $this->admin->id,
            'assigned_at' => now(),
        ]);

        AuditLog::query()->delete();
    }

    private function actions(): array
    {
        return AuditLog::orderBy('id')->pluck('action')->all();
    }

    public function test_only_admins_can_view_the_audit_log()
    {
        $this->get('/audit-logs')->assertRedirect('/login');
        $this->actingAs($this->client)->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->admin)->get('/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/audit-logs/index'));
    }

    public function test_report_activity_is_recorded_with_the_actor_and_changes()
    {
        $this->actingAs($this->client)->postJson('/api/reports', [
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ])->assertCreated();

        $report = Report::firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'acknowledge'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'add_comment', 'comment' => 'On it'])
            ->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/reports/' . $report->uuid)->assertOk();

        $this->assertSame(
            ['report.created', 'report.updated', 'comment.created', 'report.deleted'],
            $this->actions()
        );

        $created = AuditLog::where('action', 'report.created')->first();
        $this->assertSame('Carl Client', $created->user_name);
        $this->assertSame('Created report "Login is broken"', $created->description);

        $updated = AuditLog::where('action', 'report.updated')->first();
        $this->assertSame('Alice Admin', $updated->user_name);
        $this->assertSame('Changed status of report "Login is broken" from Pending to In progress', $updated->description);
        $this->assertSame(['old' => 'pending', 'new' => 'in_progress'], $updated->changes['status']);
    }

    public function test_user_changes_are_recorded_without_exposing_passwords()
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        AuditLog::query()->delete();

        $this->actingAs($superAdmin)->post('/users', [
            'name' => 'New Client',
            'email' => 'new.client@example.com',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
            'role' => 'client',
        ])->assertRedirect();

        $newUser = User::where('email', 'new.client@example.com')->firstOrFail();
        $newUser->update(['password' => 'An0ther-Passw0rd!', 'name' => 'Renamed Client']);

        $this->assertSame(['user.created', 'user.updated'], $this->actions());

        $updated = AuditLog::where('action', 'user.updated')->first();
        $this->assertSame(['old' => '[hidden]', 'new' => '[hidden]'], $updated->changes['password']);
        $this->assertSame(['old' => 'New Client', 'new' => 'Renamed Client'], $updated->changes['name']);
        $this->assertStringNotContainsString('Passw0rd', AuditLog::all()->toJson());
    }

    public function test_project_updates_and_membership_changes_are_recorded()
    {
        $newClient = User::factory()->create(['role' => 'client', 'name' => 'Nina New']);
        AuditLog::query()->delete();

        $this->actingAs($this->admin)->patch('/projects/' . $this->project->uuid . '/update', [
            'name' => 'Website v2',
            'description' => 'Company website',
            'status' => 'active',
            'client_ids' => [$newClient->id],
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
            'Removed Carl Client from project "Website v2"',
            AuditLog::where('action', 'project.members_removed')->first()->description
        );
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
        AuditLog::record('report.created', 'Created report "Login is broken"', 'report', 1, 'Login is broken', null, $this->client);
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
