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
 * Resolved is not the end: the reporter or the company's manager confirms the fix
 * (Closed) or rejects it (Reopened). Whoever resolved it cannot confirm their own fix.
 */
class ReviewWorkflowTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private Company $company;
    private User $admin;
    private User $manager;
    private User $reporter;
    private User $worker;
    private User $bystander;
    private Project $project;
    private Report $issue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin('admin', ['name' => 'Adam Admin']);
        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($this->company, 'Website');
        $this->reporter = $this->makeStaff($this->company, [$this->project], ['name' => 'Rita Reporter']);
        $this->worker = $this->makeStaff($this->company, [$this->project], ['name' => 'Will Worker']);
        $this->bystander = $this->makeStaff($this->company, [$this->project], ['name' => 'Bea Bystander']);

        $this->issue = $this->makeIssue($this->project, $this->reporter, ['status' => 'in_progress', 'assigned_to' => $this->worker->id]);
    }

    private function resolveAs(User $user): void
    {
        $this->act($user, $this->issue, ['action' => 'resolve', 'resolution_summary' => 'Fixed the session cookie'])
            ->assertOk()
            ->assertJsonPath('message', 'Issue marked as resolved.');
    }

    public function test_resolving_does_not_close_the_issue_and_records_who_resolved_it()
    {
        $this->resolveAs($this->worker);

        $this->issue->refresh();
        $this->assertSame('resolved', $this->issue->status);
        $this->assertNull($this->issue->closed_at);
        $this->assertSame($this->worker->id, $this->issue->resolved_by);
    }

    public function test_the_reporter_and_the_manager_are_asked_to_review_and_others_are_just_told()
    {
        $this->resolveAs($this->worker);

        foreach ([$this->reporter, $this->manager] as $reviewer) {
            $notification = $reviewer->notifications()->first();
            $this->assertSame('Please review: Login is broken', $notification->data['title']);
            $this->assertStringContainsString(
                'This issue has been resolved. Please review and confirm whether the issue is fixed.',
                $notification->data['message']
            );
        }

        foreach ([$this->bystander, $this->admin] as $other) {
            $notification = $other->notifications()->first();
            $this->assertSame('Report resolved: Login is broken', $notification->data['title']);
            $this->assertStringContainsString('Waiting for the reporter or manager to review', $notification->data['message']);
        }

        // The person who resolved it is not notified about their own action
        $this->assertCount(0, $this->worker->notifications);
    }

    public function test_the_reporter_can_confirm_the_fix_which_closes_the_issue()
    {
        $this->resolveAs($this->worker);

        $this->act($this->reporter, $this->issue, ['action' => 'close'])
            ->assertOk()
            ->assertJsonPath('message', 'Issue closed successfully.');

        $this->issue->refresh();
        $this->assertSame('closed', $this->issue->status);
        $this->assertNotNull($this->issue->closed_at);

        $notification = $this->worker->notifications()->get()->firstWhere('data.new_status', 'closed');
        $this->assertStringContainsString('Rita Reporter confirmed the fix', $notification->data['message']);
    }

    public function test_the_manager_can_confirm_the_fix()
    {
        $this->resolveAs($this->worker);

        $this->act($this->manager, $this->issue, ['action' => 'close'])->assertOk();
        $this->assertSame('closed', $this->issue->fresh()->status);
    }

    public function test_the_reporter_or_manager_can_reject_the_fix_which_reopens_the_issue()
    {
        $this->resolveAs($this->worker);

        $this->act($this->reporter, $this->issue, ['action' => 'reopen', 'comment' => 'Still fails on Safari'])
            ->assertOk()
            ->assertJsonPath('message', 'Issue reopened successfully.');

        $this->assertSame('reopened', $this->issue->fresh()->status);
        $this->assertSame('Still fails on Safari', $this->issue->comments()->first()->comment);

        $log = $this->issue->activityLogs()->get()->firstWhere('new_values.status', 'reopened');
        $this->assertSame('Still fails on Safari', $log->new_values['reason']);

        $notification = $this->worker->notifications()->get()->firstWhere('data.new_status', 'reopened');
        $this->assertStringContainsString('rejected the fix and reopened the issue', $notification->data['message']);
        $this->assertStringContainsString('Reason: Still fails on Safari', $notification->data['message']);
    }

    public function test_the_person_who_resolved_an_issue_cannot_confirm_their_own_fix()
    {
        // Even the reporter, the manager or an admin, when they are the one who resolved it
        foreach ([$this->reporter, $this->manager, $this->admin] as $resolver) {
            Report::whereKey($this->issue->id)->update(['status' => 'in_progress', 'resolved_by' => null, 'closed_at' => null]);
            $this->resolveAs($resolver);

            $this->act($resolver, $this->issue->fresh(), ['action' => 'close'])
                ->assertForbidden()
                ->assertJsonPath('message', 'You resolved this issue, so someone else has to confirm the fix.');
            $this->act($resolver, $this->issue->fresh(), ['action' => 'transition', 'new_status' => 'closed'])->assertForbidden();

            $this->assertSame('resolved', $this->issue->fresh()->status);
        }
    }

    public function test_someone_else_confirms_when_the_reporter_resolved_it_themselves()
    {
        $this->resolveAs($this->reporter);

        $this->act($this->manager, $this->issue, ['action' => 'close'])->assertOk();
        $this->assertSame('closed', $this->issue->fresh()->status);
    }

    public function test_other_staff_on_the_project_cannot_confirm_or_reject()
    {
        $this->resolveAs($this->worker);

        $this->act($this->bystander, $this->issue, ['action' => 'close'])->assertForbidden();
        $this->act($this->bystander, $this->issue, ['action' => 'reopen'])->assertForbidden();
        $this->assertSame('resolved', $this->issue->fresh()->status);
    }

    public function test_an_admin_can_work_an_issue_through_the_whole_workflow()
    {
        $issue = $this->makeIssue($this->project, $this->reporter);

        $this->act($this->admin, $issue, ['action' => 'assign', 'assignee_id' => $this->worker->id])->assertOk();
        $this->act($this->admin, $issue, ['action' => 'start_progress'])->assertOk();
        $this->act($this->worker, $issue->fresh(), ['action' => 'resolve'])->assertOk();
        $this->act($this->admin, $issue->fresh(), ['action' => 'close'])->assertOk();
        $this->act($this->admin, $issue->fresh(), ['action' => 'reopen', 'comment' => 'Regression'])->assertOk();

        $this->assertSame('reopened', $issue->fresh()->status);
    }

    public function test_an_issue_cannot_skip_steps()
    {
        $issue = $this->makeIssue($this->project, $this->reporter);

        $this->act($this->manager, $issue, ['action' => 'resolve'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'An issue cannot move from Open to Resolved.');
        $this->act($this->manager, $issue, ['action' => 'transition', 'new_status' => 'resolved'])->assertStatus(400);

        $this->assertSame('open', $issue->fresh()->status);
    }

    public function test_the_page_is_told_what_each_person_may_do()
    {
        $this->resolveAs($this->worker);
        $url = '/reports/' . $this->issue->uuid;

        $this->actingAs($this->reporter)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('abilities.confirm', true)->where('abilities.reopen', true)->where('abilities.assign', false));

        $this->actingAs($this->worker)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('abilities.confirm', false)->where('abilities.reopen', false)->where('abilities.reopen_work', true));

        $this->actingAs($this->manager)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('abilities.confirm', true)->where('abilities.assign', true)->where('abilities.edit', true));
    }
}
