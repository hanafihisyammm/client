<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class ReportStatsTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private User $admin;
    private User $acmeManager;
    private User $acmeStaff;
    private User $borneoManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();

        [$acme, $this->acmeManager] = $this->makeCompany('Acme');
        $website = $this->makeProject($acme, 'Website');
        $mobile = $this->makeProject($acme, 'Mobile');
        $this->acmeStaff = $this->makeStaff($acme, [$website]);

        [$borneo, $this->borneoManager] = $this->makeCompany('Borneo');
        $ordering = $this->makeProject($borneo, 'Ordering');

        $this->add($website, 'open');
        $this->add($website, 'open', now()->subDays(2));
        $this->add($website, 'in_progress', now()->subDay());
        $this->add($mobile, 'resolved', now()->subDay());
        $this->add($mobile, 'closed', now()->subDays(10));
        $this->add($ordering, 'reopened');
        $this->add($ordering, 'closed', now()->subDays(3));
    }

    private function add(Project $project, string $status, $dueDate = null): void
    {
        $this->makeIssue($project, User::find($project->created_by), [
            'title' => ucfirst($status) . ' issue',
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    private function statsFor(User $user, string $query = ''): array
    {
        $stats = null;
        $this->actingAs($user)->get('/dashboard' . $query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$stats) {
                $stats = $page->toArray()['props']['stats'];
            });

        return $stats;
    }

    public function test_admins_see_counts_for_every_company()
    {
        $this->assertSame(
            ['open' => 2, 'in_progress' => 1, 'resolved' => 1, 'reopened' => 1, 'overdue' => 2],
            $this->statsFor($this->admin)
        );
    }

    public function test_a_manager_sees_counts_for_their_own_company_only()
    {
        $this->assertSame(
            ['open' => 2, 'in_progress' => 1, 'resolved' => 1, 'reopened' => 0, 'overdue' => 2],
            $this->statsFor($this->acmeManager)
        );

        $this->assertSame(
            ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'reopened' => 1, 'overdue' => 0],
            $this->statsFor($this->borneoManager)
        );
    }

    public function test_staff_see_counts_for_their_own_projects_only()
    {
        // On Website but not Mobile, so the resolved Mobile issue is not counted
        $this->assertSame(
            ['open' => 2, 'in_progress' => 1, 'resolved' => 0, 'reopened' => 0, 'overdue' => 2],
            $this->statsFor($this->acmeStaff)
        );
    }

    public function test_counts_ignore_the_table_filters()
    {
        $this->assertSame(2, $this->statsFor($this->admin, '?status=closed')['open']);
    }

    public function test_the_issue_list_can_be_limited_to_overdue_issues()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/reports?overdue=1')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['open', 'in_progress'],
            collect($response->json('data'))->pluck('status')->all()
        );
    }

    public function test_only_the_due_date_makes_an_issue_overdue()
    {
        // Urgent and weeks old, but with no due date: there is no SLA clock to make it overdue
        $old = Report::where('status', 'open')->whereNull('due_date')->firstOrFail();
        $old->forceFill(['priority' => 'urgent', 'created_at' => now()->subWeeks(3)])->save();

        $this->assertSame(2, $this->statsFor($this->admin)['overdue']);

        $overdue = $this->actingAs($this->admin)->getJson('/api/reports?overdue=1')->assertOk();
        $this->assertNotContains($old->uuid, collect($overdue->json('data'))->pluck('uuid')->all());
    }

    public function test_issue_details_carry_no_sla_information()
    {
        $issue = Report::firstOrFail();

        $this->actingAs($this->admin)->getJson('/api/reports/' . $issue->uuid)
            ->assertOk()
            ->assertJsonMissingPath('report.sla');
    }
}
