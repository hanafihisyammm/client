<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Label;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * A manager or staff member of one company must never reach another company's data,
 * whether through lists, counts, direct URLs or API calls.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private Company $acme;
    private Company $borneo;
    private User $acmeManager;
    private User $borneoManager;
    private User $acmeStaff;
    private User $borneoStaff;
    private Project $acmeProject;
    private Project $borneoProject;
    private Report $acmeIssue;
    private Report $borneoIssue;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->acme, $this->acmeManager] = $this->makeCompany('Acme');
        [$this->borneo, $this->borneoManager] = $this->makeCompany('Borneo');

        $this->acmeProject = $this->makeProject($this->acme, 'Acme Website');
        $this->borneoProject = $this->makeProject($this->borneo, 'Borneo Ordering');

        $this->acmeStaff = $this->makeStaff($this->acme, [$this->acmeProject]);
        $this->borneoStaff = $this->makeStaff($this->borneo, [$this->borneoProject]);

        $this->acmeIssue = $this->makeIssue($this->acmeProject, $this->acmeStaff, ['title' => 'Acme issue']);
        $this->borneoIssue = $this->makeIssue($this->borneoProject, $this->borneoStaff, ['title' => 'Borneo issue']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function outsiders(): array
    {
        return ['manager of another company' => ['borneoManager'], 'staff of another company' => ['borneoStaff']];
    }

    /**
     * @dataProvider outsiders
     */
    public function test_issue_lists_and_counts_only_cover_the_users_own_company(string $who)
    {
        $user = $this->{$who};

        $titles = collect($this->actingAs($user)->getJson('/api/reports?per_page=200')->assertOk()->json('data'))->pluck('title');
        $this->assertSame(['Borneo issue'], $titles->all());

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.open', 1)
                ->has('projects', 1)
                ->where('projects.0.name', 'Borneo Ordering'));

        $this->assertSame(['Borneo Ordering'], $this->actingAs($user)->getJson('/api/projects')->json());
    }

    /**
     * @dataProvider outsiders
     */
    public function test_another_companys_issue_cannot_be_opened_or_changed(string $who)
    {
        $user = $this->{$who};
        $url = '/api/reports/' . $this->acmeIssue->uuid;

        $this->actingAs($user)->get('/reports/' . $this->acmeIssue->uuid)->assertForbidden();
        $this->actingAs($user)->getJson($url)->assertForbidden();

        foreach ([
            ['action' => 'start_progress'],
            ['action' => 'add_comment', 'comment' => 'Hello'],
            ['action' => 'assign', 'assignee_id' => $user->id],
            ['action' => 'transition', 'new_status' => 'closed'],
            ['action' => 'update_report', 'title' => 'Hacked', 'description' => 'x', 'priority' => 'low'],
        ] as $payload) {
            $this->actingAs($user)->patchJson($url, $payload)->assertForbidden();
        }

        $this->actingAs($user)->deleteJson($url)->assertForbidden();

        $this->assertSame('Acme issue', $this->acmeIssue->fresh()->title);
        $this->assertSame('open', $this->acmeIssue->fresh()->status);
        $this->assertSame(0, $this->acmeIssue->comments()->count());
    }

    /**
     * @dataProvider outsiders
     */
    public function test_another_companys_project_cannot_be_seen_or_changed(string $who)
    {
        $user = $this->{$who};

        $this->actingAs($user)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.name', 'Borneo Ordering'));

        $this->actingAs($user)->get('/projects/view/' . $this->acmeProject->uuid)->assertForbidden();
        $this->actingAs($user)->get('/projects/' . $this->acmeProject->uuid . '/edit')->assertForbidden();
        $this->actingAs($user)->patch('/projects/' . $this->acmeProject->uuid . '/update', [
            'name' => 'Hacked',
            'status' => 'active',
            'client_ids' => [],
        ])->assertForbidden();
        $this->actingAs($user)->delete('/projects/' . $this->acmeProject->id)->assertForbidden();

        $this->assertSame('Acme Website', $this->acmeProject->fresh()->name);
    }

    /**
     * @dataProvider outsiders
     */
    public function test_issues_cannot_be_created_in_another_companys_project(string $who)
    {
        $this->actingAs($this->{$who})->postJson('/api/reports', [
            'title' => 'Sneaky',
            'description' => 'x',
            'page_urls' => ['https://example.com/login'],
            'project_id' => $this->acmeProject->id,
            'priority' => 'low',
        ])->assertForbidden();

        $this->assertSame(1, Report::where('project_id', $this->acmeProject->id)->count());
    }

    /**
     * @dataProvider outsiders
     */
    public function test_labels_time_entries_and_links_are_also_isolated(string $who)
    {
        $user = $this->{$who};
        $label = Label::create(['project_id' => $this->acmeProject->id, 'name' => 'Bug', 'color' => '#ff0000']);

        $this->actingAs($user)->getJson('/api/projects/' . $this->acmeProject->id . '/labels')->assertForbidden();
        $this->actingAs($user)->postJson('/api/projects/' . $this->acmeProject->id . '/labels', ['name' => 'X', 'color' => '#000000'])->assertForbidden();
        $this->actingAs($user)->postJson('/api/reports/' . $this->acmeIssue->uuid . '/labels', ['label_ids' => [$label->id]])->assertForbidden();

        $this->actingAs($user)->getJson('/api/reports/' . $this->acmeIssue->uuid . '/time-entries')->assertForbidden();
        $this->actingAs($user)->postJson('/api/reports/' . $this->acmeIssue->uuid . '/time-entries', [
            'hours' => 1,
            'logged_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($user)->getJson('/api/reports/' . $this->acmeIssue->uuid . '/links')->assertForbidden();

        // Linking one of their own issues to another company's issue must fail too
        $this->actingAs($user)->postJson('/api/reports/' . $this->borneoIssue->uuid . '/links', [
            'target_report_uuid' => $this->acmeIssue->uuid,
            'link_type' => 'relates_to',
        ])->assertStatus(422);

        // A label from another company's project cannot be attached to their own issue
        $this->actingAs($user)->postJson('/api/reports/' . $this->borneoIssue->uuid . '/labels', ['label_ids' => [$label->id]])->assertOk();
        $this->assertSame(0, $this->borneoIssue->labels()->count());
    }

    /**
     * @dataProvider outsiders
     */
    public function test_another_companys_page_and_staff_are_off_limits(string $who)
    {
        $user = $this->{$who};
        $staffUrl = '/clients/' . $this->acme->uuid . '/staff';

        $this->actingAs($user)->get('/clients/' . $this->acme->uuid)->assertForbidden();
        $this->actingAs($user)->get('/clients')->assertForbidden();
        $this->actingAs($user)->post('/clients', ['name' => 'Mine', 'manager_email' => 'x@example.com'])->assertForbidden();
        $this->actingAs($user)->patch('/clients/' . $this->acme->uuid, ['name' => 'Hacked', 'status' => 'active'])->assertForbidden();

        $this->actingAs($user)->post($staffUrl, ['email' => 'sneaky@example.com'])->assertForbidden();
        $this->actingAs($user)->patch($staffUrl . '/' . $this->acmeStaff->uuid, ['name' => 'Hacked', 'email' => 'h@example.com'])->assertForbidden();
        $this->actingAs($user)->post($staffUrl . '/' . $this->acmeStaff->uuid . '/deactivate')->assertForbidden();

        $this->actingAs($user)->get('/users')->assertForbidden();
        $this->actingAs($user)->get('/audit-logs')->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
        $this->assertNull($this->acmeStaff->fresh()->deactivated_at);
        $this->assertSame('Acme', $this->acme->fresh()->name);
    }

    public function test_a_manager_cannot_reach_another_companys_staff_through_their_own_company_url()
    {
        $url = '/clients/' . $this->borneo->uuid . '/staff/' . $this->acmeStaff->uuid;

        $this->actingAs($this->borneoManager)->patch($url, ['name' => 'Hacked', 'email' => 'h@example.com'])->assertNotFound();
        // Managers cannot deactivate anyone; an admin using the wrong company's URL gets nowhere either
        $this->actingAs($this->borneoManager)->post($url . '/deactivate')->assertForbidden();
        $this->actingAs($this->makeAdmin())->post($url . '/deactivate')->assertNotFound();

        $this->assertNull($this->acmeStaff->fresh()->deactivated_at);
        $this->assertSame([], $this->acmeStaff->projects()->where('company_id', $this->borneo->id)->pluck('projects.id')->all());
    }

    public function test_people_and_projects_cannot_be_mixed_across_companies()
    {
        // Another company's staff cannot be put on a project
        $this->actingAs($this->borneoManager)->patch('/projects/' . $this->borneoProject->uuid . '/update', [
            'name' => 'Borneo Ordering',
            'status' => 'active',
            'client_ids' => [$this->borneoStaff->id, $this->acmeStaff->id],
        ])->assertSessionHasErrors('client_ids');

        // Staff cannot be assigned to another company's project
        $this->actingAs($this->borneoManager)
            ->patch('/clients/' . $this->borneo->uuid . '/staff/' . $this->borneoStaff->uuid, [
                'name' => $this->borneoStaff->name,
                'email' => $this->borneoStaff->email,
                'project_ids' => [$this->acmeProject->id],
            ])->assertSessionHasErrors('project_ids');

        // Issues are not assigned to anyone, so not to another company's staff either
        $this->act($this->borneoManager, $this->borneoIssue, ['action' => 'assign', 'assignee_id' => $this->acmeStaff->id])
            ->assertStatus(400);

        // A manager's new project always lands in their own company, whatever the request says
        $this->actingAs($this->borneoManager)->post('/projects', [
            'name' => 'Sneaky Project',
            'status' => 'active',
            'company_id' => $this->acme->id,
            'client_ids' => [],
        ])->assertRedirect();

        $this->assertSame($this->borneo->id, Project::where('name', 'Sneaky Project')->firstOrFail()->company_id);
        $this->assertFalse($this->acmeProject->users->contains('id', $this->borneoStaff->id));
        $this->assertNull($this->borneoIssue->fresh()->assigned_to);
    }

    public function test_a_manager_or_staff_member_without_a_company_sees_nothing()
    {
        $looseManager = User::factory()->create(['role' => 'manager', 'company_id' => null]);
        $looseStaff = User::factory()->create(['role' => 'staff', 'company_id' => null]);

        foreach ([$looseManager, $looseStaff] as $user) {
            $this->assertSame([], $this->actingAs($user)->getJson('/api/reports?per_page=200')->json('data'));
            $this->actingAs($user)->get('/projects')->assertInertia(fn (Assert $page) => $page->has('projects', 0));
            $this->actingAs($user)->get('/reports/' . $this->acmeIssue->uuid)->assertForbidden();
            $this->actingAs($user)->get('/projects/create')->assertForbidden();
        }
    }

    public function test_admins_see_every_company()
    {
        $admin = $this->makeAdmin();

        $titles = collect($this->actingAs($admin)->getJson('/api/reports?per_page=200')->json('data'))->pluck('title');
        $this->assertEqualsCanonicalizing(['Acme issue', 'Borneo issue'], $titles->all());

        $this->actingAs($admin)->get('/reports/' . $this->acmeIssue->uuid)->assertOk();
        $this->actingAs($admin)->get('/projects/view/' . $this->borneoProject->uuid)->assertOk();
        $this->actingAs($admin)->get('/clients')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('companies', 2));
        $this->actingAs($admin)->get('/clients/' . $this->acme->uuid)->assertOk();
    }
}
