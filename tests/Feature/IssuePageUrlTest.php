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
 * Every new issue names the pages it was seen on (one or more), so it can be found and debugged.
 */
class IssuePageUrlTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    private const CHECKOUT = 'https://shop.example.com/checkout?step=2';
    private const CART = 'https://shop.example.com/cart';

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
            'page_urls' => [self::CHECKOUT],
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

    public function test_an_issue_is_saved_with_its_page_urls_in_order()
    {
        $this->createIssue(['page_urls' => [self::CHECKOUT, self::CART]])
            ->assertCreated()
            ->assertJsonPath('report.page_urls', [self::CHECKOUT, self::CART]);

        $this->assertSame([self::CHECKOUT, self::CART], Report::firstOrFail()->page_urls);
    }

    public function test_one_page_url_is_enough()
    {
        $this->createIssue()->assertCreated()->assertJsonPath('report.page_urls', [self::CHECKOUT]);
    }

    public function test_an_issue_cannot_be_created_without_a_page_url()
    {
        foreach ([null, [], ['']] as $missing) {
            $this->createIssue(['page_urls' => $missing])->assertStatus(422);
        }

        $this->createIssue(['page_urls' => []])
            ->assertJsonValidationErrors(['page_urls' => 'Add the URL of the page where the issue happens.']);

        $payload = ['title' => 'No URL', 'description' => 'x', 'project_id' => $this->project->id, 'priority' => 'low'];
        $this->actingAs($this->staff)->postJson('/api/reports', $payload)->assertJsonValidationErrors('page_urls');

        $this->assertSame(0, Report::count());
    }

    public function test_every_page_url_must_be_a_web_address()
    {
        foreach (['checkout page', 'shop.example.com/checkout', 'javascript:alert(1)', 'ftp://example.com/file', 'https://example.com/' . str_repeat('a', 2048)] as $bad) {
            $this->createIssue(['page_urls' => [self::CHECKOUT, $bad]])->assertJsonValidationErrors('page_urls.1');
        }

        // A single string instead of a list is refused too
        $this->createIssue(['page_urls' => self::CHECKOUT])->assertJsonValidationErrors('page_urls');

        $this->createIssue(['page_urls' => ['http://localhost:8000/dashboard']])->assertCreated();
    }

    public function test_the_same_page_cannot_be_listed_twice_and_the_list_has_a_limit()
    {
        $this->createIssue(['page_urls' => [self::CHECKOUT, self::CHECKOUT]])
            ->assertJsonValidationErrors(['page_urls.1' => 'This page URL is listed twice.']);

        $eleven = array_map(fn ($n) => "https://shop.example.com/page-{$n}", range(1, 11));
        $this->createIssue(['page_urls' => $eleven])->assertJsonValidationErrors('page_urls');
        $this->createIssue(['page_urls' => array_slice($eleven, 0, 10)])->assertCreated();
    }

    public function test_editing_keeps_the_page_urls_unless_new_ones_are_given()
    {
        $this->createIssue()->assertCreated();
        $issue = Report::firstOrFail();

        // Left out, as older API clients do: they stay as they were
        $this->act($this->manager, $issue, $this->editPayload(['title' => 'Renamed']))->assertOk();
        $this->assertSame([self::CHECKOUT], $issue->fresh()->page_urls);

        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => [self::CHECKOUT, self::CART]]))
            ->assertOk()
            ->assertJsonPath('report.page_urls', [self::CHECKOUT, self::CART]);

        // Removing one is fine, but the issue cannot be left with none or with a bad one
        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => [self::CART]]))->assertOk();
        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => []]))->assertJsonValidationErrors('page_urls');
        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => null]))->assertJsonValidationErrors('page_urls');
        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => ['not a url']]))->assertJsonValidationErrors('page_urls.0');
        $this->assertSame([self::CART], $issue->fresh()->page_urls);

        $change = $issue->activityLogs()->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame([self::CHECKOUT, self::CART], $change->old_values['page_urls']);
        $this->assertSame([self::CART], $change->new_values['page_urls']);
    }

    public function test_an_older_issue_without_page_urls_can_still_be_edited()
    {
        $issue = $this->makeIssue($this->project, $this->staff);
        $this->assertNull($issue->page_urls);

        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => []]))->assertOk();
        $this->assertNull($issue->fresh()->page_urls);

        $this->act($this->manager, $issue, $this->editPayload(['page_urls' => [self::CART]]))->assertOk();
        $this->assertSame([self::CART], $issue->fresh()->page_urls);
    }
}
