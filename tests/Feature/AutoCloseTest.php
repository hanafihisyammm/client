<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportActivityLog;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * A resolved issue that nobody confirms or reopens within 7 days is closed automatically.
 */
class AutoCloseTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private User $manager;
    private User $reporter;
    private User $worker;
    private Project $project;
    private Report $issue;

    protected function setUp(): void
    {
        parent::setUp();

        [$company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($company, 'Website');
        $this->reporter = $this->makeStaff($company, [$this->project]);
        $this->worker = $this->makeStaff($company, [$this->project]);
        $this->issue = $this->makeIssue($this->project, $this->reporter, ['status' => 'in_progress']);
    }

    private function resolve(): void
    {
        $this->act($this->worker, $this->issue, ['action' => 'resolve', 'resolution_summary' => 'Fixed'])->assertOk();
    }

    public function test_an_issue_resolved_seven_days_ago_without_review_is_closed()
    {
        $this->resolve();

        // The scheduler runs with nobody signed in
        $this->app['auth']->forgetGuards();
        $this->travel(7)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->issue->refresh();
        $this->assertSame('closed', $this->issue->status);
        $this->assertNotNull($this->issue->closed_at);

        $log = ReportActivityLog::where('report_id', $this->issue->id)->latest('id')->first();
        $this->assertNull($log->user_id);
        $this->assertSame('status_changed', $log->action);
        $this->assertTrue($log->new_values['auto_closed']);

        $audit = AuditLog::where('action', 'report.updated')->where('subject_id', $this->issue->id)->latest('id')->first();
        $this->assertNull($audit->user_id);
        $this->assertStringContainsString('from Resolved to Closed', $audit->description);
    }

    public function test_an_issue_resolved_less_than_seven_days_ago_stays_resolved()
    {
        $this->resolve();

        $this->travel(6)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->assertSame('resolved', $this->issue->fresh()->status);
    }

    public function test_the_period_starts_again_when_the_issue_is_resolved_again()
    {
        $this->resolve();
        $this->travel(5)->days();
        $this->act($this->reporter, $this->issue, ['action' => 'reopen', 'comment' => 'Still broken'])->assertOk();
        $this->act($this->worker, $this->issue, ['action' => 'start_progress'])->assertOk();
        $this->resolve();

        $this->travel(5)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->assertSame('resolved', $this->issue->fresh()->status);
    }

    public function test_issues_in_other_statuses_are_left_alone()
    {
        $open = $this->makeIssue($this->project, $this->reporter);
        $reopened = $this->makeIssue($this->project, $this->reporter, ['status' => 'reopened']);

        $this->travel(30)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->assertSame('open', $open->fresh()->status);
        $this->assertSame('reopened', $reopened->fresh()->status);
        $this->assertSame('in_progress', $this->issue->fresh()->status);
    }

    public function test_an_issue_resolved_before_resolved_at_was_recorded_uses_its_last_update()
    {
        $old = $this->makeIssue($this->project, $this->reporter, ['status' => 'resolved', 'resolved_at' => null]);

        $this->travel(8)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->assertSame('closed', $old->fresh()->status);
    }

    public function test_the_reporter_manager_and_resolver_are_told_it_was_closed_automatically()
    {
        $this->resolve();
        $this->travel(7)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        foreach ([$this->reporter, $this->manager, $this->worker] as $user) {
            $notification = $user->notifications()->latest()->first();
            $this->assertSame('closed', $notification->data['new_status']);
            $this->assertSame('System', $notification->data['updated_by']);
            $this->assertStringContainsString('closed automatically', $notification->data['message']);
        }
    }

    public function test_the_issue_page_shows_the_automatic_close_without_a_user()
    {
        $this->resolve();
        $this->travel(7)->days();
        $this->artisan('desk:auto-close')->assertSuccessful();

        $this->actingAs($this->reporter)->get('/reports/' . $this->issue->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.status', 'closed')
                ->where('report.activity_logs.0.user', null)
                ->where('report.activity_logs.0.new_values.auto_closed', true)
                ->etc());
    }

    public function test_it_is_scheduled_hourly()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'desk:auto-close'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
