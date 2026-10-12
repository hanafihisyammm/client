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
            ], $people),
        ]);
    }

    public function test_several_people_can_be_invited_at_once_each_with_their_own_role()
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $acme = \App\Models\Company::create(['name' => 'Acme', 'status' => 'active']);
        $borneo = \App\Models\Company::create(['name' => 'Borneo', 'status' => 'active']);

        $this->bulkInvite($admin, [
            ['jane.tan@example.com', 'staff', $acme->id],
            ['ALI@example.com', 'staff', $borneo->id],
            ['mei@example.com', 'manager'],
        ])->assertRedirect('/users')->assertSessionHas('flash.message', '3 invitations sent.');

        $jane = User::where('email', 'jane.tan@example.com')->firstOrFail();
        $ali = User::where('email', 'ali@example.com')->firstOrFail();
        $mei = User::where('email', 'mei@example.com')->firstOrFail();

        $this->assertSame(['staff', $acme->id, 'Jane Tan'], [$jane->role, $jane->company_id, $jane->name]);
        $this->assertSame(['staff', $borneo->id], [$ali->role, $ali->company_id]);
        // A manager is put in charge of a company from the Clients page, not here
        $this->assertSame(['manager', null], [$mei->role, $mei->company_id]);

        foreach ([$jane, $ali, $mei] as $user) {
            $this->assertNull($user->email_verified_at);
            $this->assertSame($admin->id, $user->created_by);
            Notification::assertSentToTimes($user, UserInvited::class, 1);
        }
    }

    public function test_already_registered_addresses_are_skipped_and_reported()
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $existing = User::factory()->create(['email' => 'here.already@example.com']);

        $this->bulkInvite($admin, [['new.one@example.com'], ['Here.Already@example.com']])
            ->assertSessionHas('flash.message', 'Invitation sent to new.one@example.com. Skipped 1 already registered: here.already@example.com.');

        $this->assertSame(1, User::where('email', 'here.already@example.com')->count());
        Notification::assertNotSentTo($existing, UserInvited::class);

        // Nothing new to invite is an error, not a silent success
        $this->bulkInvite($admin, [['new.one@example.com'], ['here.already@example.com']])
            ->assertSessionHasErrors(['invites' => 'All of these email addresses are already registered.']);
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
            ['email' => 'good@example.com', 'role' => 'manager'],
            ['email' => 'other@example.com', 'role' => 'staff'],
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
            ->assertSessionHas('flash.message', '2 invitations sent.');
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
