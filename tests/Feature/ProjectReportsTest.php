<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class ProjectReportsTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    public function test_an_issue_listed_on_the_project_page_can_be_opened()
    {
        $admin = $this->makeAdmin();
        [$company] = $this->makeCompany('Acme');
        $project = $this->makeProject($company, 'Website');
        $staff = $this->makeStaff($company, [$project]);
        $issue = $this->makeIssue($project, $staff);

        foreach ([$admin, $staff] as $user) {
            $this->actingAs($user)->get('/projects/view/' . $project->uuid)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('admin/projects/show')
                    ->where('project.reports.0.uuid', (string) $issue->uuid)
                    ->where('project.company.name', 'Acme'));

            $this->actingAs($user)->get('/reports/' . $issue->uuid)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('reports/show')
                    ->where('report.title', 'Login is broken')
                    ->where('abilities.view', true));
        }
    }
}
