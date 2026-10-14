<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

/**
 * A script can use the API with a personal access token, and gets exactly
 * what the token's user would get when signed in.
 */
class ApiTokenTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    public function test_the_api_refuses_a_request_with_no_session_and_no_token(): void
    {
        $this->getJson('/api/reports')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/reports')->assertUnauthorized();
    }

    public function test_a_token_lists_and_updates_issues_as_its_user(): void
    {
        $admin = $this->makeAdmin('super_admin');
        [$acme, $manager] = $this->makeCompany('Acme');
        $issue = $this->makeIssue($this->makeProject($acme, 'Acme Website'), $manager);

        $token = $admin->createToken('automation')->plainTextToken;

        $this->withToken($token)->getJson('/api/reports?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', (string) $issue->uuid);

        $this->withToken($token)->patchJson('/api/reports/'.$issue->uuid, ['action' => 'start_progress'])->assertOk();
        $this->withToken($token)->patchJson('/api/reports/'.$issue->uuid, ['action' => 'add_comment', 'comment' => 'Looking into it.'])->assertOk();

        $issue->refresh();
        $this->assertSame('in_progress', $issue->status);
        // Starting work no longer makes anyone the issue's assignee
        $this->assertNull($issue->assigned_to);
        $this->assertSame($admin->id, $issue->comments()->first()->user_id);
    }

    public function test_a_token_sees_only_what_its_user_may_see(): void
    {
        [$acme, $acmeManager] = $this->makeCompany('Acme');
        [$borneo, $borneoManager] = $this->makeCompany('Borneo');
        $this->makeIssue($this->makeProject($acme, 'Acme Website'), $acmeManager, ['title' => 'Acme issue']);
        $borneoIssue = $this->makeIssue($this->makeProject($borneo, 'Borneo Ordering'), $borneoManager, ['title' => 'Borneo issue']);

        $token = $acmeManager->createToken('automation')->plainTextToken;

        $titles = collect($this->withToken($token)->getJson('/api/reports')->assertOk()->json('data'))->pluck('title');
        $this->assertSame(['Acme issue'], $titles->all());

        $this->withToken($token)->getJson('/api/reports/'.$borneoIssue->uuid)->assertForbidden();
    }

    public function test_a_deactivated_users_token_stops_working(): void
    {
        $admin = $this->makeAdmin();
        $token = $admin->createToken('automation')->plainTextToken;

        $this->withToken($token)->getJson('/api/reports')->assertOk();

        $admin->update(['deactivated_at' => now()]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/reports')->assertForbidden();
    }

    public function test_the_command_creates_a_working_token_and_can_revoke_it(): void
    {
        $admin = $this->makeAdmin();

        $this->assertSame(0, Artisan::call('desk:token', ['email' => $admin->email]));
        $token = trim(Artisan::output());

        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('email', $admin->email);

        $this->assertSame(0, Artisan::call('desk:token', ['email' => $admin->email, '--revoke' => true]));
        $this->assertSame(0, $admin->tokens()->count());

        $this->assertSame(1, Artisan::call('desk:token', ['email' => 'nobody@example.com']));
    }
}
