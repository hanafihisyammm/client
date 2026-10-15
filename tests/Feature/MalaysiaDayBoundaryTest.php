<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * The day rolls over at midnight in Malaysia (UTC+8), not at midnight UTC,
 * while timestamps keep being stored in UTC.
 */
class MalaysiaDayBoundaryTest extends TestCase
{
    use BuildsCompanies;
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        [$company] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($company, 'Website');
    }

    /** Move the clock to a moment given in Malaysian time. */
    private function atMalaysiaTime(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Asia/Kuala_Lumpur'));
    }

    private function overdueCount(): int
    {
        $count = null;
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(function (Assert $page) use (&$count) {
            $count = $page->toArray()['props']['stats']['overdue'];
        });

        return $count;
    }

    private function overdueListCount(): int
    {
        return count($this->actingAs($this->admin)->getJson('/api/reports?overdue=1')->assertOk()->json('data'));
    }

    public function test_timestamps_are_still_stored_in_utc()
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Kuala_Lumpur', config('app.business_timezone'));

        $this->atMalaysiaTime('2026-10-16 00:30:00');

        $this->assertSame('2026-10-15 16:30:00', now()->toDateTimeString());
        $this->assertSame('2026-10-16', Report::businessToday());
    }

    public function test_an_issue_is_not_overdue_until_its_due_date_has_ended_in_malaysia()
    {
        $issue = $this->makeIssue($this->project, $this->admin, ['due_date' => '2026-10-15']);

        // Late on the due date itself
        $this->atMalaysiaTime('2026-10-15 23:59:00');
        $this->assertSame(0, $this->overdueCount());
        $this->assertSame(0, $this->overdueListCount());
        $this->assertFalse($issue->fresh()->isOverdue());
    }

    public function test_an_issue_becomes_overdue_at_midnight_malaysia_time_not_at_8am()
    {
        $issue = $this->makeIssue($this->project, $this->admin, ['due_date' => '2026-10-15']);

        // One minute past midnight in Malaysia is still 15 October in UTC
        $this->atMalaysiaTime('2026-10-16 00:01:00');
        $this->assertSame('2026-10-15', now()->toDateString());

        $this->assertSame(1, $this->overdueCount());
        $this->assertSame(1, $this->overdueListCount());
        $this->assertTrue($issue->fresh()->isOverdue());
    }

    public function test_resolved_and_closed_issues_are_never_overdue()
    {
        $resolved = $this->makeIssue($this->project, $this->admin, ['due_date' => '2026-10-15', 'status' => 'resolved']);
        $closed = $this->makeIssue($this->project, $this->admin, ['due_date' => '2026-10-15', 'status' => 'closed']);

        $this->atMalaysiaTime('2026-10-20 12:00:00');

        $this->assertSame(0, $this->overdueCount());
        $this->assertFalse($resolved->fresh()->isOverdue());
        $this->assertFalse($closed->fresh()->isOverdue());
    }

    public function test_a_due_date_of_today_in_malaysia_is_accepted_just_after_midnight()
    {
        $this->atMalaysiaTime('2026-10-16 00:30:00');

        $payload = ['title' => 'Fix it', 'description' => 'Broken', 'page_urls' => ['https://example.com/login'], 'project_id' => $this->project->id, 'priority' => 'high'];

        $this->actingAs($this->admin)->postJson('/api/reports', $payload + ['due_date' => '2026-10-16'])->assertCreated();
        // Yesterday in Malaysia, although it is still "today" in UTC
        $this->actingAs($this->admin)->postJson('/api/reports', $payload + ['due_date' => '2026-10-15'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    public function test_time_can_be_logged_for_today_in_malaysia_just_after_midnight()
    {
        $issue = $this->makeIssue($this->project, $this->admin);
        $url = '/api/reports/' . $issue->uuid . '/time-entries';

        $this->atMalaysiaTime('2026-10-16 00:30:00');

        $this->actingAs($this->admin)->postJson($url, ['hours' => 1, 'logged_date' => '2026-10-16'])->assertSuccessful();
        $this->actingAs($this->admin)->postJson($url, ['hours' => 1, 'logged_date' => '2026-10-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logged_date');
    }

    public function test_the_audit_log_date_filter_uses_malaysian_days()
    {
        AuditLog::query()->delete();

        // 00:30 on 16 October in Malaysia is stored as 16:30 on 15 October UTC
        $this->atMalaysiaTime('2026-10-16 00:30:00');
        AuditLog::record('project.updated', 'Early on the 16th');

        $this->atMalaysiaTime('2026-10-15 23:30:00');
        AuditLog::record('project.updated', 'Late on the 15th');

        $descriptionsFor = function (string $day) {
            $found = null;
            $this->actingAs($this->admin)->get("/audit-logs?from={$day}&to={$day}")->assertInertia(function (Assert $page) use (&$found) {
                $found = collect($page->toArray()['props']['logs']['data'])->pluck('description')->all();
            });

            return $found;
        };

        $this->assertSame(['Early on the 16th'], $descriptionsFor('2026-10-16'));
        $this->assertSame(['Late on the 15th'], $descriptionsFor('2026-10-15'));
    }
}
