<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_a_report_listed_on_the_project_page()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $project = Project::create([
            'name' => 'Website',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $report = Report::create([
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $project->id,
            'priority' => 'high',
            'reported_by' => $client->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->get('/projects/view/' . $project->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/projects/show')
                ->where('project.reports.0.uuid', (string) $report->uuid));

        $this->actingAs($admin)->get('/reports/' . $report->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/show')
                ->where('report.title', 'Login is broken'));
    }
}
