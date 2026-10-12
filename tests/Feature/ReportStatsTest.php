<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private User $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->otherClient = User::factory()->create(['role' => 'client']);

        $mine = Project::create(['name' => 'Website', 'status' => 'active', 'created_by' => $this->admin->id]);
        $mine->users()->attach($this->client->id, ['role' => 'member', 'assigned_by' => $this->admin->id, 'assigned_at' => now()]);

        $theirs = Project::create(['name' => 'Mobile App', 'status' => 'active', 'created_by' => $this->admin->id]);
        $theirs->users()->attach($this->otherClient->id, ['role' => 'member', 'assigned_by' => $this->admin->id, 'assigned_at' => now()]);

        $this->makeReport($mine, $this->client, 'pending');
        $this->makeReport($mine, $this->client, 'pending', now()->subDays(2));
        $this->makeReport($mine, $this->client, 'in_progress', now()->addDays(5));
        $this->makeReport($mine, $this->client, 'submit_for_review', now()->subDay());
        $this->makeReport($mine, $this->client, 'approved', now()->subDays(10));
        $this->makeReport($theirs, $this->otherClient, 'rejected');
        $this->makeReport($theirs, $this->otherClient, 'closed', now()->subDays(3));
    }

    private function makeReport(Project $project, User $reporter, string $status, $dueDate = null): Report
    {
        return Report::create([
            'title' => ucfirst($status) . ' report',
            'description' => 'Details',
            'project_id' => $project->id,
            'priority' => 'medium',
            'reported_by' => $reporter->id,
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    public function test_admins_see_counts_for_every_report()
    {
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats', [
                'pending' => 2,
                'in_progress' => 1,
                'submit_for_review' => 1,
                'rejected' => 1,
                'overdue' => 2,
            ]));
    }

    public function test_clients_only_see_counts_for_their_own_projects()
    {
        $this->actingAs($this->otherClient)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('stats', [
                'pending' => 0,
                'in_progress' => 0,
                'submit_for_review' => 0,
                'rejected' => 1,
                'overdue' => 0,
            ]));
    }

    public function test_counts_ignore_the_table_filters()
    {
        $this->actingAs($this->admin)->get('/dashboard?status=approved')
            ->assertInertia(fn (Assert $page) => $page->where('stats.pending', 2));
    }

    public function test_the_report_list_can_be_limited_to_overdue_reports()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/reports?overdue=1')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['pending', 'submit_for_review'],
            collect($response->json('data'))->pluck('status')->all()
        );
    }
}
