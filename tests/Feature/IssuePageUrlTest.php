<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * Every new issue names the page it was seen on, so it can be found and debugged.
 */
class IssuePageUrlTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private Company $company;
    private User $manager;
    private User $staff;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->manager] = $this->makeCompany('Acme');
        $this->project = $this->makeProject($this->company, 'Website');
        $this->staff = $this->makeStaff($this->company, [$this->project]);
    }

    private function createIssue(array $overrides = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/reports', array_merge([
            'title' => 'Checkout is broken',
            'description' => 'The pay button does nothing',
            'page_url' => 'https://shop.example.com/checkout?step=2',
            'project_id' => $this->project->id,
            'priority' => 'high',
        ], $overrides));
    }

    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'action' => 'update_report',
            'title' => 'Checkout is broken',
            'description' => 'The pay button does nothing',
            'priority' => 'high',
        ], $overrides);
    }

    public function test_an_issue_is_saved_with_the_page_url()
    {
        $this->createIssue()
            ->assertCreated()
            ->assertJsonPath('report.page_url', 'https://shop.example.com/checkout?step=2');

        $this->assertSame('https://shop.example.com/checkout?step=2', Report::firstOrFail()->page_url);
    }

    public function test_an_issue_cannot_be_created_without_a_page_url()
    {
        foreach ([null, '', '   '] as $missing) {
            $this->createIssue(['page_url' => $missing])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['page_url' => 'The page URL field is required.']);
        }

        $payload = ['title' => 'No URL', 'description' => 'x', 'project_id' => $this->project->id, 'priority' => 'low'];
        $this->actingAs($this->staff)->postJson('/api/reports', $payload)->assertJsonValidationErrors('page_url');

        $this->assertSame(0, Report::count());
    }

    public function test_the_page_url_must_be_a_web_address()
    {
        foreach (['checkout page', 'shop.example.com/checkout', 'javascript:alert(1)', 'ftp://example.com/file', 'https://example.com/' . str_repeat('a', 2048)] as $bad) {
            $this->createIssue(['page_url' => $bad])->assertJsonValidationErrors('page_url');
        }

        $this->createIssue(['page_url' => 'http://localhost:8000/dashboard'])->assertCreated();
    }

    public function test_editing_keeps_the_page_url_unless_a_new_one_is_given()
    {
        $this->createIssue()->assertCreated();
        $issue = Report::firstOrFail();

        // Left out, as older API clients do: it stays as it was
        $this->act($this->manager, $issue, $this->editPayload(['title' => 'Renamed']))->assertOk();
        $this->assertSame('https://shop.example.com/checkout?step=2', $issue->fresh()->page_url);

        $this->act($this->manager, $issue, $this->editPayload(['page_url' => 'https://shop.example.com/cart']))
            ->assertOk()
            ->assertJsonPath('report.page_url', 'https://shop.example.com/cart');

        // Once given, it cannot be cleared or made invalid
        $this->act($this->manager, $issue, $this->editPayload(['page_url' => '']))->assertJsonValidationErrors('page_url');
        $this->act($this->manager, $issue, $this->editPayload(['page_url' => 'not a url']))->assertJsonValidationErrors('page_url');
        $this->assertSame('https://shop.example.com/cart', $issue->fresh()->page_url);

        $change = $issue->activityLogs()->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('https://shop.example.com/cart', $change->new_values['page_url']);
    }

    public function test_an_older_issue_without_a_page_url_can_still_be_edited()
    {
        $issue = $this->makeIssue($this->project, $this->staff);
        $this->assertNull($issue->page_url);

        $this->act($this->manager, $issue, $this->editPayload(['page_url' => null]))->assertOk();
        $this->assertNull($issue->fresh()->page_url);

        $this->act($this->manager, $issue, $this->editPayload(['page_url' => 'https://shop.example.com/']))->assertOk();
        $this->assertSame('https://shop.example.com/', $issue->fresh()->page_url);
    }
}
