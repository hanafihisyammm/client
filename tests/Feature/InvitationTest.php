<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invite(string $email = 'new.person@example.com', string $role = 'staff'): User
    {
        $admin = User::where('role', 'super_admin')->first() ?? User::factory()->create(['role' => 'super_admin']);
        $company = \App\Models\Company::firstOrCreate(['name' => 'Acme'], ['status' => 'active']);

        $this->actingAs($admin)->post('/users', [
            'email' => $email,
            'role' => $role,
            'company_id' => $role === 'staff' ? $company->id : null,
        ])->assertRedirect('/users');
        auth()->logout();

        return User::where('email', $email)->firstOrFail();
    }

    private function linkFor(User $user, $expires = null): string
    {
        return URL::temporarySignedRoute('invitation.show', $expires ?? now()->addDays(7), ['user' => $user->uuid], false);
    }

    public function test_adding_a_user_needs_only_an_email_and_role_and_sends_an_invitation()
    {
        Notification::fake();

        foreach (['admin', 'manager', 'staff'] as $role) {
            $user = $this->invite("{$role}.person@example.com", $role);

            $this->assertSame($role, $user->role);
            $this->assertSame(ucfirst($role) . ' Person', $user->name);
            $this->assertNull($user->email_verified_at);
            // Only staff are placed in a company here; a manager is put in charge of one from the Clients page
            $this->assertSame($role === 'staff', $user->company_id !== null);
            Notification::assertSentTo($user, UserInvited::class);
        }
    }

    public function test_the_invitation_email_links_to_the_setup_page()
    {
        $user = $this->invite();

        $message = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('new.person@example.com', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('/invitation/' . $user->uuid, $message->getHtmlBody());
        $this->assertStringContainsString('signature=', $message->getHtmlBody());
        $this->assertCount(0, $user->notifications);
    }

    public function test_an_invited_user_can_set_their_name_and_password_and_is_logged_in()
    {
        $user = $this->invite();
        $link = $this->linkFor($user);

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('email', 'new.person@example.com'));

        $this->post($link, [
            'name' => 'Nora Person',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('Nora Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invitation_cannot_be_used_twice_or_without_a_valid_signature()
    {
        $user = $this->invite();
        $link = $this->linkFor($user);

        $this->get('/invitation/' . $user->uuid)->assertForbidden();
        $this->get($link . 'x')->assertForbidden();
        $this->get($this->linkFor($user, now()->subMinute()))->assertForbidden();

        $this->post($link, [
            'name' => 'Nora Person',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect('/dashboard');
        auth()->logout();

        $this->get($link)->assertRedirect('/login');
        $this->post($link, [
            'name' => 'Someone Else',
            'password' => 'An0ther-Passw0rd!',
            'password_confirmation' => 'An0ther-Passw0rd!',
        ])->assertRedirect('/login');
        $this->assertSame('Nora Person', $user->fresh()->name);
    }

    public function test_an_invitation_can_be_resent_only_while_the_account_is_pending()
    {
        $user = $this->invite();
        $admin = User::where('role', 'super_admin')->first();

        Notification::fake();

        $this->actingAs($admin)->post("/users/{$user->uuid}/invitation")->assertSessionHasNoErrors();
        Notification::assertSentTo($user, UserInvited::class);

        $user->forceFill(['email_verified_at' => now()])->save();
        Notification::fake();

        $this->actingAs($admin)->post("/users/{$user->uuid}/invitation")->assertSessionHasErrors('invitation');
        Notification::assertNothingSent();
    }

    public function test_staff_and_managers_cannot_invite_users_from_the_users_page()
    {
        foreach (['staff', 'manager'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post('/users', ['email' => 'x@example.com', 'role' => 'staff'])->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_staff_need_a_company_and_only_a_super_admin_can_invite_an_admin()
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($superAdmin)->post('/users', ['email' => 'no.company@example.com', 'role' => 'staff'])
            ->assertSessionHasErrors('company_id');

        $this->actingAs($admin)->post('/users', ['email' => 'another.admin@example.com', 'role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'no.company@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'another.admin@example.com']);
    }

    public function test_a_deactivated_person_cannot_use_their_invitation()
    {
        $user = $this->invite();
        $user->update(['deactivated_at' => now()]);

        $this->get($this->linkFor($user))->assertRedirect('/login');
        $this->post($this->linkFor($user), [
            'name' => 'Nora Person',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect('/login');

        $this->assertNull($user->fresh()->email_verified_at);
    }
}
