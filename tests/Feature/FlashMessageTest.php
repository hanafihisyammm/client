<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class FlashMessageTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private User $admin;
    private Company $company;
    private User $manager;
    private User $staff;
    private User $otherStaff;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin('super_admin');
        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($this->company, 'Website');
        $this->staff = $this->makeStaff($this->company, [$this->project], ['name' => 'Sam Staff']);
        $this->otherStaff = $this->makeStaff($this->company);
    }

    private function projectPayload(array $staffIds, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Website',
            'description' => null,
            'status' => 'active',
            'client_ids' => $staffIds,
        ], $overrides);
    }

    public function test_the_flash_message_reaches_the_next_page_once()
    {
        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post('/projects', $this->projectPayload([$this->staff->id], ['name' => 'Mobile App', 'company_id' => $this->company->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.message', 'Project created successfully. Staff member added to project successfully.')
                ->where('flash.type', 'success')
                ->has('flash.id'));

        $this->actingAs($this->admin)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->where('flash', null));
    }

    public function test_project_updates_say_when_staff_were_added_or_removed()
    {
        $url = '/projects/' . $this->project->uuid . '/update';

        $this->actingAs($this->manager)->patch($url, $this->projectPayload([$this->staff->id, $this->otherStaff->id]))
            ->assertSessionHas('flash.message', 'Project updated successfully. Staff member added to project successfully.');

        $this->actingAs($this->manager)->patch($url, $this->projectPayload([$this->otherStaff->id]))
            ->assertSessionHas('flash.message', 'Project updated successfully. Staff member removed from project successfully.');

        $this->actingAs($this->manager)->patch($url, $this->projectPayload([$this->otherStaff->id], ['name' => 'Website v2']))
            ->assertSessionHas('flash.message', 'Project updated successfully.');

        $this->actingAs($this->manager)->delete('/projects/' . $this->project->id)
            ->assertSessionHas('flash.message', 'Project deleted successfully.');
    }

    public function test_deleting_a_project_from_the_client_page_returns_to_its_projects_tab()
    {
        $company = $this->project->company;

        $this->actingAs($this->manager)->delete('/projects/' . $this->project->id . '?from=client')
            ->assertRedirect("/clients/{$company->uuid}?tab=projects")
            ->assertSessionHas('flash.message', 'Project deleted successfully.');
    }

    public function test_a_failed_update_sets_no_success_message()
    {
        $this->actingAs($this->manager)
            ->patch('/projects/' . $this->project->uuid . '/update', $this->projectPayload([$this->staff->id], ['name' => '']))
            ->assertSessionHasErrors('name')
            ->assertSessionMissing('flash');
    }

    public function test_user_and_company_actions_set_a_success_message()
    {
        $this->actingAs($this->admin)->post('/users', ['email' => 'new.person@example.com', 'role' => 'staff', 'company_id' => $this->company->id])
            ->assertSessionHas('flash.message', 'Invitation sent to new.person@example.com.');

        $user = User::where('email', 'new.person@example.com')->firstOrFail();

        $this->actingAs($this->admin)->post('/users/' . $user->uuid . '/deactivate')
            ->assertSessionHas('flash.message', 'New Person has been deactivated and can no longer log in.');
        $this->actingAs($this->admin)->post('/users/' . $user->uuid . '/reactivate')
            ->assertSessionHas('flash.message', 'New Person has been reactivated.');
        $this->actingAs($this->admin)->delete('/users/' . $user->uuid)
            ->assertSessionHas('flash.message', 'User deleted successfully.');

        $this->actingAs($this->admin)->post('/clients', ['name' => 'Borneo Foods', 'manager_email' => 'farah@example.com'])
            ->assertSessionHas('flash.message', "Client company 'Borneo Foods' created successfully.");
        $this->actingAs($this->admin)->patch('/clients/' . $this->company->uuid, ['name' => 'Acme Trading', 'status' => 'active'])
            ->assertSessionHas('flash.message', 'Client company updated successfully.');
    }

    public function test_someone_with_work_in_the_system_is_not_deleted()
    {
        $this->makeIssue($this->project, $this->staff);

        $this->actingAs($this->admin)->delete('/users/' . $this->staff->uuid)
            ->assertSessionHas('flash.type', 'error')
            ->assertSessionHas('flash.message', 'Sam Staff has issues or activity in the system and cannot be deleted. Deactivate them instead.');

        $this->assertDatabaseHas('users', ['id' => $this->staff->id]);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_each_issue_action_returns_its_own_success_message()
    {
        $this->actingAs($this->staff)->postJson('/api/reports', [
            'title' => 'New issue',
            'description' => 'Details',
            'project_id' => $this->project->id,
            'priority' => 'low',
        ])->assertCreated()->assertJsonPath('message', 'Issue created successfully.');

        $issue = $this->makeIssue($this->project, $this->staff);
        $url = '/api/reports/' . $issue->uuid;
        $asAdmin = fn (array $payload) => $this->actingAs($this->admin)->patchJson($url, $payload);
        $asManager = fn (array $payload) => $this->actingAs($this->manager)->patchJson($url, $payload);

        $asAdmin(['action' => 'assign', 'assignee_id' => $this->staff->id])->assertJsonPath('message', 'Issue assigned to Sam Staff.');
        $asAdmin(['action' => 'add_comment', 'comment' => 'On it'])->assertJsonPath('message', 'Comment added successfully.');
        $asAdmin(['action' => 'start_progress'])->assertJsonPath('message', 'Issue moved to In Progress.');
        $asAdmin(['action' => 'resolve', 'resolution_summary' => 'Fixed'])->assertJsonPath('message', 'Issue marked as resolved.');
        $asManager(['action' => 'reopen', 'comment' => 'Not fixed'])->assertJsonPath('message', 'Issue reopened successfully.');
        $asAdmin(['action' => 'transition', 'new_status' => 'in_progress'])->assertJsonPath('message', 'Issue moved to In Progress.');
        $asAdmin(['action' => 'transition', 'new_status' => 'resolved'])->assertJsonPath('message', 'Issue marked as resolved.');
        $asManager(['action' => 'close'])->assertJsonPath('message', 'Issue closed successfully.');
        $asAdmin([
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
        $issue = $this->makeIssue($this->project, $this->staff);

        $response = $this->actingAs($this->admin)
            ->patchJson('/api/reports/' . $issue->uuid, ['action' => 'resolve'])
            ->assertStatus(400);

        $this->assertStringNotContainsString('successfully', $response->json('message'));
        $this->assertSame('open', $issue->fresh()->status);
    }
}
