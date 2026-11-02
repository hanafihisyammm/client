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

    /** Someone invited by email earlier, who has not used their invitation yet. New accounts now get a password instead. */
    private function invite(string $email = 'new.person@example.com', string $role = 'staff'): User
    {
        $company = \App\Models\Company::firstOrCreate(['name' => 'Acme'], ['status' => 'active']);

        return User::factory()->create([
            'name' => 'New Person',
            'email' => $email,
            'role' => $role,
            'company_id' => $role === 'staff' ? $company->id : null,
            'email_verified_at' => null,
        ]);
    }

    private function linkFor(User $user, $expires = null): string
    {
        return URL::temporarySignedRoute('invitation.show', $expires ?? now()->addDays(7), ['user' => $user->uuid], false);
    }

    public function test_the_invitation_email_links_to_the_setup_page()
    {
        $user = $this->invite();
        app(\App\Services\NotificationService::class)->notifyUserInvited($user, User::factory()->create(['role' => 'admin']));

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
        $admin = User::factory()->create(['role' => 'super_admin']);

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

        $this->actingAs($superAdmin)->post('/users', ['email' => 'no.company@example.com', 'role' => 'staff', 'password' => 'Temp-Passw0rd-1'])
            ->assertSessionHasErrors('company_id');

        $this->actingAs($admin)->post('/users', ['email' => 'another.admin@example.com', 'role' => 'admin', 'password' => 'Temp-Passw0rd-1'])
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

    // ------------------------------------------------------------------ Bulk invite and bulk resend

    /**
     * @param  array<int, array{0: string, 1?: string, 2?: int|null}>  $people  [email, role, company id]
     */
    private function bulkInvite(User $actor, array $people)
    {
        $defaultCompany = \App\Models\Company::firstOrCreate(['name' => 'Acme'], ['status' => 'active'])->id;

        return $this->actingAs($actor)->post('/users/bulk', [
            'invites' => array_map(fn (array $person) => [
                'email' => $person[0],
                'role' => $person[1] ?? 'staff',
                'company_id' => ($person[1] ?? 'staff') === 'staff' ? ($person[2] ?? $defaultCompany) : null,
                'password' => 'Temp-Passw0rd-1',
            ], $people),
        ]);
    }

    public function test_one_bad_row_stops_the_whole_bulk_invite_and_points_at_that_row()
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->bulkInvite($admin, [['good@example.com'], ['not-an-email']])->assertSessionHasErrors('invites.1.email');
        // The same address twice, possibly with different roles
        $this->bulkInvite($admin, [['good@example.com', 'staff'], ['Good@example.com', 'manager']])
            ->assertSessionHasErrors(['invites.1.email' => 'This address is listed more than once.']);
        // Staff without a company, and an unknown role
        $this->actingAs($admin)->post('/users/bulk', ['invites' => [
            ['email' => 'good@example.com', 'role' => 'manager', 'password' => 'Temp-Passw0rd-1'],
            ['email' => 'other@example.com', 'role' => 'staff', 'password' => 'Temp-Passw0rd-1'],
        ]])->assertSessionHasErrors('invites.1.company_id');
        $this->bulkInvite($admin, [['good@example.com', 'owner']])->assertSessionHasErrors('invites.0.role');

        // Too many at once, and nobody at all
        $this->bulkInvite($admin, array_map(fn ($n) => ["person{$n}@example.com"], range(1, 51)))->assertSessionHasErrors('invites');
        $this->actingAs($admin)->post('/users/bulk', ['invites' => []])->assertSessionHasErrors('invites');

        $this->assertDatabaseMissing('users', ['email' => 'good@example.com']);
        $this->assertSame(0, User::where('email', 'like', 'person%')->count());
        Notification::assertNothingSent();
    }

    public function test_bulk_invite_follows_the_same_permissions_as_adding_one_user()
    {
        Notification::fake();
        [$admin, $superAdmin] = [User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'super_admin'])];
        $company = \App\Models\Company::create(['name' => 'Acme', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'company_id' => $company->id]);
        $staff = User::factory()->create(['role' => 'staff', 'company_id' => $company->id]);

        foreach ([$manager, $staff] as $user) {
            $this->bulkInvite($user, [['sneaky@example.com', 'staff', $company->id]])->assertForbidden();
            $this->actingAs($user)->post('/users/invitations', ['user_ids' => [$staff->id]])->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);

        // Only a super admin creates other admins; one admin row in the list stops the whole batch
        $this->bulkInvite($admin, [['fine@example.com', 'staff', $company->id], ['boss1@example.com', 'admin']])
            ->assertSessionHasErrors('invites.1.role');
        $this->assertDatabaseMissing('users', ['email' => 'fine@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'boss1@example.com']);

        $this->bulkInvite($superAdmin, [['boss1@example.com', 'admin'], ['boss2@example.com', 'admin']])
            ->assertSessionHas('flash.message', '2 accounts created.');
        $this->assertSame(2, User::where('role', 'admin')->whereNull('email_verified_at')->count());
    }

    public function test_invitations_can_be_resent_to_several_pending_users_at_once()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pendingA = User::factory()->create(['role' => 'staff', 'email_verified_at' => null]);
        $pendingB = User::factory()->create(['role' => 'manager', 'email_verified_at' => null]);
        $active = User::factory()->create(['role' => 'staff']);
        $deactivated = User::factory()->create(['role' => 'staff', 'email_verified_at' => null, 'deactivated_at' => now()]);
        $pendingSuperAdmin = User::factory()->create(['role' => 'super_admin', 'email_verified_at' => null]);

        Notification::fake();

        $this->actingAs($admin)->post('/users/invitations', [
            'user_ids' => [$pendingA->id, $pendingB->id, $active->id, $deactivated->id, $pendingSuperAdmin->id],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', '2 invitations resent. Skipped 3 that are not waiting for an invitation.');

        Notification::assertSentToTimes($pendingA, UserInvited::class, 1);
        Notification::assertSentToTimes($pendingB, UserInvited::class, 1);
        // Already set up, deactivated, and someone this admin may not manage
        Notification::assertNotSentTo($active, UserInvited::class);
        Notification::assertNotSentTo($deactivated, UserInvited::class);
        Notification::assertNotSentTo($pendingSuperAdmin, UserInvited::class);

        // Nobody was changed by resending
        $this->assertNull($pendingA->fresh()->email_verified_at);
        $this->assertNotNull($active->fresh()->email_verified_at);
    }

    public function test_bulk_resend_needs_at_least_one_pending_user()
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $active = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->post('/users/invitations', ['user_ids' => [$active->id]])->assertSessionHasErrors('invitation');
        $this->actingAs($admin)->post('/users/invitations', ['user_ids' => []])->assertSessionHasErrors('user_ids');
        $this->actingAs($admin)->post('/users/invitations', ['user_ids' => range(1, 51)])->assertSessionHasErrors('user_ids');

        Notification::assertNothingSent();
    }
}
