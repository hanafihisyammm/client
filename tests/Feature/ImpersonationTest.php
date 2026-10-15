<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private Company $acme;
    private User $acmeManager;
    private User $acmeStaff;
    private Project $acmeProject;
    private Project $acmeOtherProject;
    private Report $acmeIssue;
    private User $borneoManager;
    private Project $borneoProject;
    private Report $borneoIssue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->makeAdmin('super_admin', ['name' => 'Sam Super']);
        $this->admin = $this->makeAdmin('admin');

        [$this->acme, $this->acmeManager] = $this->makeCompany('Acme');
        $this->acmeProject = $this->makeProject($this->acme, 'Website');
        $this->acmeOtherProject = $this->makeProject($this->acme, 'Mobile');
        $this->acmeStaff = $this->makeStaff($this->acme, [$this->acmeProject], ['name' => 'Ben Staff']);
        $this->acmeIssue = $this->makeIssue($this->acmeProject, $this->acmeStaff);

        [$borneo, $this->borneoManager] = $this->makeCompany('Borneo');
        $this->borneoProject = $this->makeProject($borneo, 'Ordering');
        $this->borneoIssue = $this->makeIssue($this->borneoProject, $this->borneoManager);
    }

    private function signInAs(User $target, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->superAdmin)->post('/users/' . $target->uuid . '/impersonate');
    }

    // ------------------------------------------------------------------ Who may use it

    public function test_only_a_super_admin_can_sign_in_as_someone()
    {
        foreach ([$this->admin, $this->acmeManager, $this->acmeStaff] as $actor) {
            $this->signInAs($this->borneoManager, $actor)->assertForbidden();

            $this->assertAuthenticatedAs($actor);
            $this->assertFalse(session()->has(Impersonation::SESSION_KEY));
        }

        $this->assertSame(0, AuditLog::where('action', 'auth.impersonation_started')->count());
    }

    public function test_a_guest_cannot_sign_in_as_someone_or_use_the_way_back()
    {
        $this->post('/users/' . $this->acmeStaff->uuid . '/impersonate')->assertRedirect('/login');
        $this->post('/impersonation/stop')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_super_admin_cannot_sign_in_as_themselves_another_super_admin_or_an_unusable_account()
    {
        $otherSuperAdmin = $this->makeAdmin('super_admin');
        $deactivated = $this->makeStaff($this->acme, [], ['deactivated_at' => now()]);
        $pending = $this->makeStaff($this->acme, [], ['email_verified_at' => null]);

        foreach ([$this->superAdmin, $otherSuperAdmin, $deactivated, $pending] as $target) {
            $this->signInAs($target)->assertForbidden();
            $this->assertAuthenticatedAs($this->superAdmin);
        }

        $this->assertSame(0, AuditLog::where('action', 'auth.impersonation_started')->count());
    }

    // ------------------------------------------------------------------ Starting

    public function test_a_super_admin_can_sign_in_as_a_user_without_their_password()
    {
        $this->signInAs($this->acmeStaff)
            ->assertRedirect('/dashboard')
            ->assertSessionHas('flash.message', 'You are now signed in as Ben Staff.')
            ->assertSessionHas(Impersonation::SESSION_KEY, $this->superAdmin->id);

        $this->assertAuthenticatedAs($this->acmeStaff);

        // Every page is told who they are signed in as, and who is really behind it
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->acmeStaff->id)
            ->where('auth.user.role', 'staff')
            ->where('impersonation.superAdmin', 'Sam Super'));
    }

    public function test_the_impersonated_account_is_not_changed_in_any_way()
    {
        $this->acmeStaff->forceFill(['password' => Hash::make('their-own-secret'), 'remember_token' => 'keep-this-token'])->save();
        $before = $this->acmeStaff->fresh()->getAttributes();

        $this->signInAs($this->acmeStaff);
        $this->get('/dashboard')->assertOk();
        $this->post('/impersonation/stop')->assertRedirect('/users');

        $this->assertSame($before, $this->acmeStaff->fresh()->getAttributes());
        $this->assertTrue(Hash::check('their-own-secret', $this->acmeStaff->fresh()->password));
    }

    public function test_their_profile_and_password_cannot_be_changed_while_signed_in_as_them()
    {
        $this->signInAs($this->acmeStaff);
        $before = $this->acmeStaff->fresh()->getAttributes();

        $this->patch('/settings/profile', ['name' => 'Renamed', 'email' => 'renamed@example.com'])
            ->assertSessionHas('flash.type', 'error');
        $this->put('/settings/password', ['current_password' => 'password', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])
            ->assertSessionHas('flash.type', 'error');
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHas('flash.type', 'error');

        $this->assertSame($before, $this->acmeStaff->fresh()->getAttributes());
        $this->assertAuthenticatedAs($this->acmeStaff);
    }

    public function test_a_normal_user_can_still_change_their_own_profile()
    {
        $this->actingAs($this->acmeStaff)
            ->patch('/settings/profile', ['name' => 'Ben Renamed', 'email' => $this->acmeStaff->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ben Renamed', $this->acmeStaff->fresh()->name);
    }

    // ------------------------------------------------------------------ Acting as the user

    public function test_while_signed_in_as_staff_the_app_gives_exactly_that_staff_members_access()
    {
        $hiddenIssue = $this->makeIssue($this->acmeOtherProject, $this->acmeManager, ['title' => 'Not my project']);

        $this->signInAs($this->acmeStaff);

        // Their own project and issues only
        $titles = collect($this->getJson('/api/reports?per_page=200')->assertOk()->json('data'))->pluck('uuid')->all();
        $this->assertSame([(string) $this->acmeIssue->uuid], $titles);

        $this->get('/reports/' . $this->acmeIssue->uuid)->assertOk();
        $this->get('/reports/' . $hiddenIssue->uuid)->assertForbidden();
        $this->get('/reports/' . $this->borneoIssue->uuid)->assertForbidden();
        $this->get('/projects/view/' . $this->borneoProject->uuid)->assertForbidden();

        // None of the Super Admin's own powers come along
        $this->get('/users')->assertForbidden();
        $this->get('/clients')->assertForbidden();
        $this->get('/audit-logs')->assertForbidden();
        $this->post('/users/bulk', ['invites' => [['email' => 'x@example.com', 'role' => 'manager']]])->assertForbidden();
        $this->delete('/projects/' . $this->acmeProject->id)->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $this->acmeProject->id]);
    }

    public function test_while_signed_in_as_a_manager_only_their_own_company_is_reachable()
    {
        $this->signInAs($this->acmeManager);

        $this->get('/clients/' . $this->acme->uuid)->assertOk();
        $this->get('/projects/view/' . $this->acmeProject->uuid)->assertOk();

        $this->get('/projects/view/' . $this->borneoProject->uuid)->assertForbidden();
        $this->get('/reports/' . $this->borneoIssue->uuid)->assertForbidden();
        $this->patchJson('/api/reports/' . $this->borneoIssue->uuid, ['action' => 'start_progress'])->assertForbidden();
        $this->get('/users')->assertForbidden();
    }

    /**
     * The app's pages call the API with the browser session, and the API ends a session whose
     * remembered password hash no longer matches its user. Switching user must not trip that.
     */
    public function test_the_api_keeps_working_after_switching_to_a_user_and_back()
    {
        $this->superAdmin->forceFill(['password' => Hash::make('super-admin-password')])->save();
        $this->acmeStaff->forceFill(['password' => Hash::make('staff-password')])->save();
        $browser = ['Origin' => 'http://localhost'];

        // As on any page load: the API has seen this session as the Super Admin
        $this->actingAs($this->superAdmin)->withHeaders($browser)->getJson('/api/notifications')->assertOk();
        $this->assertSame($this->superAdmin->fresh()->password, session('password_hash_web'));

        $this->withHeaders($browser)->post('/users/' . $this->acmeStaff->uuid . '/impersonate')->assertRedirect('/dashboard');

        // Still signed in as the staff member after the API has checked the session
        $this->withHeaders($browser)->getJson('/api/notifications')->assertOk();
        $this->withHeaders($browser)->getJson('/api/reports')->assertOk();
        $this->assertAuthenticatedAs($this->acmeStaff);
        $this->assertSame($this->superAdmin->id, session(Impersonation::SESSION_KEY));

        $this->withHeaders($browser)->post('/impersonation/stop')->assertRedirect('/users');

        $this->withHeaders($browser)->getJson('/api/notifications')->assertOk();
        $this->assertAuthenticatedAs($this->superAdmin);
    }

    public function test_someone_already_signed_in_as_a_user_cannot_sign_in_as_another()
    {
        $this->signInAs($this->acmeStaff);

        $this->post('/users/' . $this->acmeManager->uuid . '/impersonate')->assertForbidden();

        $this->assertAuthenticatedAs($this->acmeStaff);
        $this->assertSame($this->superAdmin->id, session(Impersonation::SESSION_KEY));
    }

    // ------------------------------------------------------------------ Returning

    public function test_returning_restores_the_super_admin()
    {
        $this->signInAs($this->acmeStaff);

        $this->post('/impersonation/stop')
            ->assertRedirect('/users')
            ->assertSessionHas('flash.message', 'You are back in your Super Admin account.')
            ->assertSessionMissing(Impersonation::SESSION_KEY);

        $this->assertAuthenticatedAs($this->superAdmin);

        // Full Super Admin access again, and no banner
        $this->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->superAdmin->id)
            ->where('impersonation', null));
        $this->get('/reports/' . $this->borneoIssue->uuid)->assertOk();
    }

    public function test_the_way_back_does_nothing_for_someone_who_is_not_signed_in_as_another_user()
    {
        foreach ([$this->acmeStaff, $this->acmeManager, $this->admin, $this->superAdmin] as $user) {
            $this->actingAs($user)->post('/impersonation/stop')->assertForbidden();
            $this->assertAuthenticatedAs($user);
        }

        $this->assertSame(0, AuditLog::where('action', 'auth.impersonation_ended')->count());
    }

    public function test_returning_fails_safely_if_the_super_admin_lost_that_role_meanwhile()
    {
        $this->signInAs($this->acmeStaff);
        $this->superAdmin->forceFill(['role' => 'staff'])->save();

        $this->post('/impersonation/stop')->assertRedirect('/login');

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ Audit

    public function test_starting_and_ending_are_recorded_against_the_super_admin()
    {
        AuditLog::query()->delete();

        $this->signInAs($this->acmeStaff);
        $this->post('/impersonation/stop');

        $entries = AuditLog::orderBy('id')->get();

        // No ordinary "Logged in" entries for either account
        $this->assertSame(['auth.impersonation_started', 'auth.impersonation_ended'], $entries->pluck('action')->all());

        foreach ($entries as $entry) {
            $this->assertSame($this->superAdmin->id, $entry->user_id);
            $this->assertSame('Sam Super', $entry->user_name);
            $this->assertSame(['user', $this->acmeStaff->id, $this->acmeStaff->email], [$entry->subject_type, $entry->subject_id, $entry->subject_label]);
            $this->assertNotNull($entry->created_at);
        }

        $this->assertSame("Signed in as Ben Staff ({$this->acmeStaff->email})", $entries[0]->description);
    }

    public function test_what_is_done_while_signed_in_as_a_user_names_the_super_admin_behind_it()
    {
        $this->signInAs($this->acmeStaff);
        AuditLog::query()->delete();

        // The browser calls the API with its session, as the app's own pages do
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/reports', [
            'title' => 'Raised while impersonating', 'description' => 'Test', 'page_url' => 'https://example.com/login', 'project_id' => $this->acmeProject->id, 'priority' => 'low',
        ])->assertCreated();

        $entry = AuditLog::where('action', 'report.created')->firstOrFail();
        $this->assertSame($this->acmeStaff->id, $entry->user_id);
        $this->assertStringContainsString('(by Sam Super, signed in as this user)', $entry->description);
    }

    public function test_logging_out_while_signed_in_as_a_user_ends_it_and_is_recorded()
    {
        $this->signInAs($this->acmeStaff);
        AuditLog::query()->delete();

        $this->post('/logout');

        $this->assertGuest();
        $entry = AuditLog::firstOrFail();
        $this->assertSame(['auth.impersonation_ended', $this->superAdmin->id, $this->acmeStaff->id], [$entry->action, $entry->user_id, $entry->subject_id]);
        $this->assertSame(0, AuditLog::where('action', 'auth.logout')->count());
    }
}
