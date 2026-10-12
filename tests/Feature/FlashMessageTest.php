<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlashMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private User $otherClient;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->otherClient = User::factory()->create(['role' => 'client']);

        $this->project = Project::create(['name' => 'Website', 'status' => 'active', 'created_by' => $this->admin->id]);
        $this->project->users()->attach($this->client->id, ['role' => 'member', 'assigned_by' => $this->admin->id, 'assigned_at' => now()]);
    }

    private function makeReport(string $status = 'open'): Report
    {
        return Report::create([
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $this->project->id,
            'priority' => 'high',
            'reported_by' => $this->client->id,
            'status' => $status,
        ]);
    }

    private function projectPayload(array $clientIds, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Website',
            'description' => null,
            'status' => 'active',
            'client_ids' => $clientIds,
        ], $overrides);
    }

    public function test_the_flash_message_reaches_the_next_page_once()
    {
        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post('/projects', $this->projectPayload([$this->client->id], ['name' => 'Mobile App']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.message', 'Project created successfully. Client added to project successfully.')
                ->where('flash.type', 'success')
                ->has('flash.id'));

        $this->actingAs($this->admin)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->where('flash', null));
    }

    public function test_project_updates_say_when_members_were_added_or_removed()
    {
        $url = '/projects/' . $this->project->uuid . '/update';

        $this->actingAs($this->admin)->patch($url, $this->projectPayload([$this->client->id, $this->otherClient->id]))
            ->assertSessionHas('flash.message', 'Project updated successfully. Client added to project successfully.');

        $this->actingAs($this->admin)->patch($url, $this->projectPayload([$this->otherClient->id]))
            ->assertSessionHas('flash.message', 'Project updated successfully. Client removed from project successfully.');

        $this->actingAs($this->admin)->patch($url, $this->projectPayload([$this->otherClient->id], ['name' => 'Website v2']))
            ->assertSessionHas('flash.message', 'Project updated successfully.');

        $this->actingAs($this->admin)->delete('/projects/' . $this->project->id)
            ->assertSessionHas('flash.message', 'Project deleted successfully.');
    }

    public function test_a_failed_update_sets_no_success_message()
    {
        $this->actingAs($this->admin)
            ->patch('/projects/' . $this->project->uuid . '/update', $this->projectPayload([$this->client->id], ['name' => '']))
            ->assertSessionHasErrors('name')
            ->assertSessionMissing('flash');
    }

    public function test_user_actions_set_a_success_message()
    {
        $this->actingAs($this->admin)->post('/users', ['email' => 'new.person@example.com', 'role' => 'client'])
            ->assertSessionHas('flash.message', 'Invitation sent to new.person@example.com.');

        $user = User::where('email', 'new.person@example.com')->firstOrFail();

        $this->actingAs($this->admin)->delete('/users/' . $user->uuid)
            ->assertSessionHas('flash.message', 'User deleted successfully.');
    }

    public function test_each_issue_action_returns_its_own_success_message()
    {
        $this->actingAs($this->client)->postJson('/api/reports', [
            'title' => 'New issue',
            'description' => 'Details',
            'project_id' => $this->project->id,
            'priority' => 'low',
        ])->assertCreated()->assertJsonPath('message', 'Issue created successfully.');

        $report = $this->makeReport();
        $url = '/api/reports/' . $report->uuid;
        $act = fn (array $payload) => $this->actingAs($this->admin)->patchJson($url, $payload);

        $act(['action' => 'assign', 'assignee_id' => $this->admin->id])->assertJsonPath('message', "Issue assigned to {$this->admin->name}.");
        $act(['action' => 'add_comment', 'comment' => 'On it'])->assertJsonPath('message', 'Comment added successfully.');
        $act(['action' => 'start_progress'])->assertJsonPath('message', 'Issue moved to In Progress.');
        $act(['action' => 'resolve', 'resolution_summary' => 'Fixed'])->assertJsonPath('message', 'Issue marked as resolved.');
        $act(['action' => 'reopen', 'comment' => 'Not fixed'])->assertJsonPath('message', 'Issue reopened successfully.');
        $act(['action' => 'transition', 'new_status' => 'in_progress'])->assertJsonPath('message', 'Issue moved to In Progress.');
        $act(['action' => 'transition', 'new_status' => 'resolved'])->assertJsonPath('message', 'Issue marked as resolved.');
        $act(['action' => 'close'])->assertJsonPath('message', 'Issue closed successfully.');
        $act([
            'action' => 'update_report',
            'title' => 'Login is broken on Safari',
            'description' => 'Cannot log in',
            'priority' => 'urgent',
            'status' => 'closed',
        ])->assertJsonPath('message', 'Issue updated successfully.');

        $this->actingAs($this->admin)->deleteJson($url)->assertJsonPath('message', 'Issue deleted successfully.');
    }

    public function test_a_rejected_issue_action_returns_no_success_message()
    {
        $report = $this->makeReport();

        $response = $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $report->uuid, ['action' => 'resolve'])
            ->assertStatus(400);

        $this->assertStringNotContainsString('successfully', $response->json('message'));
        $this->assertSame('open', $report->fresh()->status);
    }
}
