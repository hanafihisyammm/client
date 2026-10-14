<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * Who can see and work on which issues. Issues belong to projects, not to people:
 * staff see every issue in the projects they are members of, and nothing else;
 * admins and super admins see everything, without being members of anything.
 * Issues are never assigned to anyone; staff are assigned to projects.
 */
class IssueAccessTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private Company $acme;
    private User $manager;
    private User $staff;
    private User $otherStaff;
    private Project $website;
    private Project $mobile;
    private Project $borneoProject;
    private User $borneoManager;

    /** @var array<string, Report> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->acme, $this->manager] = $this->makeCompany('Acme');
        $this->website = $this->makeProject($this->acme, 'Website');
        $this->mobile = $this->makeProject($this->acme, 'Mobile');
        $this->staff = $this->makeStaff($this->acme, [$this->website], ['name' => 'Ben Staff']);
        $this->otherStaff = $this->makeStaff($this->acme, [$this->website, $this->mobile], ['name' => 'Chloe Staff']);

        [$borneo, $this->borneoManager] = $this->makeCompany('Borneo');
        $this->borneoProject = $this->makeProject($borneo, 'Ordering');
        $admin = $this->makeAdmin();

        // Issues in the staff member's project, raised by everyone but them
        $this->issues['by manager'] = $this->makeIssue($this->website, $this->manager, ['title' => 'Website: by manager']);
        $this->issues['by colleague'] = $this->makeIssue($this->website, $this->otherStaff, ['title' => 'Website: by colleague']);
        $this->issues['by admin'] = $this->makeIssue($this->website, $admin, ['title' => 'Website: by admin']);
        $this->issues['by themselves'] = $this->makeIssue($this->website, $this->staff, ['title' => 'Website: by themselves']);

        // Issues they must not see
        $this->issues['other project'] = $this->makeIssue($this->mobile, $this->otherStaff, ['title' => 'Mobile: other project']);
        $this->issues['other company'] = $this->makeIssue($this->borneoProject, $this->borneoManager, ['title' => 'Ordering: other company']);
    }

    // ------------------------------------------------------------------ Staff

    public function test_staff_see_every_issue_in_their_projects_whoever_raised_it()
    {
        $this->assertEqualsCanonicalizing(
            ['Website: by manager', 'Website: by colleague', 'Website: by admin', 'Website: by themselves'],
            $this->apiTitles($this->staff),
        );
        $this->assertEqualsCanonicalizing(
            ['Website: by manager', 'Website: by colleague', 'Website: by admin', 'Website: by themselves'],
            $this->dashboardTitles($this->staff),
        );

        foreach (['by manager', 'by colleague', 'by admin', 'by themselves'] as $key) {
            $this->actingAs($this->staff)->get('/reports/' . $this->issues[$key]->uuid)->assertOk();
            $this->actingAs($this->staff)->getJson('/api/reports/' . $this->issues[$key]->uuid)->assertOk();
        }
    }

    public function test_staff_can_work_on_any_issue_in_their_projects_without_being_assigned_it()
    {
        $issue = $this->issues['by colleague'];

        $this->act($this->staff, $issue, ['action' => 'add_comment', 'comment' => 'Taking a look'])->assertOk();
        $this->act($this->staff, $issue, ['action' => 'start_progress'])->assertOk();
        $this->act($this->staff, $issue->fresh(), ['action' => 'resolve', 'resolution_summary' => 'Fixed'])->assertOk();

        $this->assertSame('resolved', $issue->fresh()->status);
    }

    public function test_staff_cannot_open_issues_in_projects_they_do_not_belong_to()
    {
        foreach (['other project', 'other company'] as $key) {
            $issue = $this->issues[$key];

            $this->actingAs($this->staff)->get('/reports/' . $issue->uuid)->assertForbidden();
            $this->actingAs($this->staff)->getJson('/api/reports/' . $issue->uuid)->assertForbidden();
            $this->act($this->staff, $issue, ['action' => 'add_comment', 'comment' => 'Hello'])->assertForbidden();
            $this->act($this->staff, $issue, ['action' => 'start_progress'])->assertForbidden();
        }

        $this->assertNotContains('Mobile: other project', $this->apiTitles($this->staff));
        $this->assertNotContains('Ordering: other company', $this->apiTitles($this->staff));
    }

    public function test_an_old_issue_assignment_gives_no_access_to_another_projects_issue()
    {
        // Assigned to them before issue assignment was removed
        $issue = $this->issues['other project'];
        $issue->forceFill(['assigned_to' => $this->staff->id])->save();

        $this->actingAs($this->staff)->get('/reports/' . $issue->uuid)->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/reports/' . $issue->uuid)->assertForbidden();
        $this->assertNotContains('Mobile: other project', $this->apiTitles($this->staff));
        $this->assertNotContains('Mobile: other project', $this->dashboardTitles($this->staff));
    }

    public function test_project_membership_decides_access_as_staff_are_added_and_removed()
    {
        $url = '/clients/' . $this->acme->uuid . '/staff/' . $this->staff->uuid;
        $mobileIssue = $this->issues['other project'];

        // Added to Mobile by their manager: its issues open up
        $this->actingAs($this->manager)->patch($url, ['project_ids' => [$this->website->id, $this->mobile->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get('/reports/' . $mobileIssue->uuid)->assertOk();
        $this->assertContains('Mobile: other project', $this->apiTitles($this->staff));

        // Taken off Website: its issues close to them
        $this->actingAs($this->manager)->patch($url, ['project_ids' => [$this->mobile->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get('/reports/' . $this->issues['by colleague']->uuid)->assertForbidden();
        $this->assertNotContains('Website: by colleague', $this->apiTitles($this->staff));

        // ...except the issues they reported themselves, which reporters keep (unchanged rule)
        $this->actingAs($this->staff)->get('/reports/' . $this->issues['by themselves']->uuid)->assertOk();
    }

    // ------------------------------------------------------------------ Admins

    public function test_admins_and_super_admins_see_every_project_and_issue_without_being_members()
    {
        $everyIssue = collect($this->issues)->pluck('title')->all();

        foreach (['admin', 'super_admin'] as $role) {
            $admin = $this->makeAdmin($role);
            $this->assertSame([], $admin->projectIds()->all(), "the {$role} is not a member of any project");

            $this->assertEqualsCanonicalizing($everyIssue, $this->apiTitles($admin), "{$role}: API");
            $this->assertEqualsCanonicalizing($everyIssue, $this->dashboardTitles($admin), "{$role}: dashboard");

            foreach ($this->issues as $issue) {
                $this->actingAs($admin)->get('/reports/' . $issue->uuid)->assertOk();
            }

            $this->actingAs($admin)->get('/projects')
                ->assertInertia(fn (Assert $page) => $page->has('projects', 3));
            foreach ([$this->website, $this->mobile, $this->borneoProject] as $project) {
                $this->actingAs($admin)->get('/projects/view/' . $project->uuid)->assertOk();
            }
        }
    }

    public function test_admins_can_work_on_any_issue_without_being_members()
    {
        foreach (['admin', 'super_admin'] as $role) {
            $admin = $this->makeAdmin($role);
            $issue = $this->makeIssue($this->borneoProject, $this->borneoManager, ['title' => "For the {$role}"]);

            $this->act($admin, $issue, ['action' => 'add_comment', 'comment' => 'On it'])->assertOk();
            $this->act($admin, $issue, ['action' => 'start_progress'])->assertOk();
            $this->assertSame('in_progress', $issue->fresh()->status);
        }
    }

    // ------------------------------------------------------------------ Managers (unchanged)

    public function test_managers_still_see_every_issue_in_their_own_company_only()
    {
        $this->assertEqualsCanonicalizing(
            ['Website: by manager', 'Website: by colleague', 'Website: by admin', 'Website: by themselves', 'Mobile: other project'],
            $this->apiTitles($this->manager),
        );
        $this->actingAs($this->manager)->get('/reports/' . $this->issues['other company']->uuid)->assertForbidden();
    }

    // ------------------------------------------------------------------ No issue assignment

    public function test_issues_have_no_assignee_anywhere()
    {
        $issue = $this->issues['by colleague'];
        $issue->forceFill(['assigned_to' => $this->staff->id])->save();

        // Not offered on the page, not in the API, and the action is gone
        $this->actingAs($this->manager)->get('/reports/' . $issue->uuid)
            ->assertInertia(fn (Assert $page) => $page
                ->missing('report.assignee')
                ->missing('report.assigned_to')
                ->missing('abilities.assign'));

        $json = $this->actingAs($this->manager)->getJson('/api/reports/' . $issue->uuid)->assertOk()->json();
        $this->assertStringNotContainsString('assign', strtolower(json_encode(array_keys($json['data'] ?? $json))));

        $this->act($this->manager, $issue, ['action' => 'assign', 'assignee_id' => $this->staff->id])->assertStatus(400);
        $this->actingAs($this->manager)->getJson('/api/team-members?project_id=' . $this->website->id)->assertNotFound();
    }

    public function test_staff_can_still_be_assigned_to_projects()
    {
        $this->actingAs($this->manager)
            ->patch('/clients/' . $this->acme->uuid . '/staff/' . $this->staff->uuid, ['project_ids' => [$this->mobile->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$this->mobile->id], $this->staff->projectIds()->all());

        $this->actingAs($this->makeAdmin())
            ->patch('/projects/' . $this->website->uuid . '/update', [
                'name' => 'Website',
                'status' => 'active',
                'client_ids' => [$this->staff->id, $this->otherStaff->id],
            ])->assertSessionHasNoErrors();
        $this->assertContains($this->website->id, $this->staff->fresh()->projectIds()->all());
    }

    /**
     * @return array<int, string>
     */
    private function apiTitles(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/reports?per_page=200')->assertOk()->json('data'))
            ->pluck('title')->all();
    }

    /**
     * @return array<int, string>
     */
    private function dashboardTitles(User $user): array
    {
        $reports = $this->actingAs($user)->get('/dashboard')->assertOk()->viewData('page')['props']['reports'];

        return collect($reports['data'] ?? $reports)->pluck('title')->all();
    }
}
