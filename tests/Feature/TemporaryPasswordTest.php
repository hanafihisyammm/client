<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'Temp-Passw0rd-1';
    private const NEW = 'My-0wn-Passw0rd!';

    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Alice Admin']);
        $this->company = Company::create(['name' => 'Acme', 'status' => 'active']);
    }

    private function row(string $email, string $role = 'staff', string $password = self::TEMPORARY): array
    {
        return ['email' => $email, 'role' => $role, 'company_id' => $role === 'staff' ? $this->company->id : null, 'password' => $password];
    }

    /** A person just given a temporary password, already logged in with it. */
    private function loggedInWithTemporaryPassword(): User
    {
        $this->actingAs($this->admin)->post('/users/bulk', ['invites' => [$this->row('jane.tan@example.com')]])->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => 'jane.tan@example.com', 'password' => self::TEMPORARY])->assertRedirect('/dashboard');

        return User::where('email', 'jane.tan@example.com')->firstOrFail();
    }

    private function changePassword(string $password = self::NEW)
    {
        return $this->put('/change-password', ['name' => 'Jane Tan', 'password' => $password, 'password_confirmation' => $password]);
    }

    // ------------------------------------------------------------------ Creating accounts

    public function test_adding_users_sets_their_password_and_sends_no_email()
    {
        $this->actingAs($this->admin)->post('/users/bulk', ['invites' => [
            $this->row('jane.tan@example.com'),
            $this->row('MEI@example.com', 'manager', 'Other-Passw0rd-2'),
        ]])->assertRedirect('/users/create')->assertSessionHas('flash.message', '2 accounts created.');

        $jane = User::where('email', 'jane.tan@example.com')->firstOrFail();
        $mei = User::where('email', 'mei@example.com')->firstOrFail();

        $this->assertTrue(Hash::check(self::TEMPORARY, $jane->password));
        $this->assertTrue(Hash::check('Other-Passw0rd-2', $mei->password));
        $this->assertSame(['staff', $this->company->id, 'Jane Tan', $this->admin->id], [$jane->role, $jane->company_id, $jane->name, $jane->created_by]);
        $this->assertSame(['manager', null], [$mei->role, $mei->company_id]);

        foreach ([$jane, $mei] as $user) {
            $this->assertTrue($user->must_change_password);
            $this->assertNull($user->email_verified_at);
        }

        Notification::assertNothingSent();
    }

    public function test_adding_staff_and_a_new_manager_from_a_company_also_sends_no_email()
    {
        $this->actingAs($this->admin)->post('/clients/' . $this->company->uuid . '/staff', [
            'email' => 'dana@example.com',
            'password' => self::TEMPORARY,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/clients', [
            'name' => 'Borneo',
            'manager_email' => 'farah@example.com',
            'password' => self::TEMPORARY,
        ])->assertSessionHasNoErrors();

        foreach (['dana@example.com', 'farah@example.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue($user->must_change_password);
            $this->assertTrue(Hash::check(self::TEMPORARY, $user->password));
        }

        Notification::assertNothingSent();
    }

    public function test_a_password_is_required_and_must_meet_the_rules()
    {
        $this->actingAs($this->admin);

        $this->post('/users/bulk', ['invites' => [$this->row('a@example.com', 'staff', '')]])->assertSessionHasErrors('invites.0.password');
        $this->post('/users/bulk', ['invites' => [$this->row('a@example.com', 'staff', 'short')]])->assertSessionHasErrors('invites.0.password');
        $this->post('/users', $this->row('a@example.com', 'staff', ''))->assertSessionHasErrors('password');
        $this->post('/clients/' . $this->company->uuid . '/staff', ['email' => 'a@example.com'])->assertSessionHasErrors('password');
        $this->post('/clients', ['name' => 'Borneo', 'manager_email' => 'a@example.com'])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'a@example.com']);
    }

    public function test_a_registered_address_stops_the_whole_list_and_points_at_its_row()
    {
        User::factory()->create(['email' => 'here.already@example.com']);

        $this->actingAs($this->admin)->post('/users/bulk', ['invites' => [
            $this->row('new.one@example.com'),
            $this->row('Here.Already@example.com'),
        ]])->assertSessionHasErrors(['invites.1.email' => 'This email address is already registered.']);

        $this->assertDatabaseMissing('users', ['email' => 'new.one@example.com']);
    }

    public function test_a_failed_submission_does_not_keep_the_passwords_in_the_session()
    {
        $this->actingAs($this->admin)->post('/users/bulk', ['invites' => [
            $this->row('good@example.com'),
            $this->row('not-an-email'),
        ]])->assertSessionHasErrors('invites.1.email')->assertSessionMissing('_old_input');

        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAs($this->admin)->post('/users/bulk', ['invites' => [$this->row('taken@example.com')]])
            ->assertSessionHasErrors('invites.0.email')
            ->assertSessionMissing('_old_input');
    }

    public function test_the_audit_log_records_who_created_the_account_and_never_the_password()
    {
        AuditLog::query()->delete();

        $user = $this->loggedInWithTemporaryPassword();
        $this->changePassword()->assertRedirect('/dashboard');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $this->admin->id, 'subject_id' => $user->id]);

        $changed = AuditLog::where('action', 'user.updated')->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(['old' => '[hidden]', 'new' => '[hidden]'], $changed->changes['password']);
        $this->assertSame(['old' => true, 'new' => false], $changed->changes['must_change_password']);

        $everything = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString(self::TEMPORARY, $everything);
        $this->assertStringNotContainsString(self::NEW, $everything);
    }

    // ------------------------------------------------------------------ First login

    public function test_logging_in_with_a_temporary_password_goes_straight_to_the_change_password_page()
    {
        $this->loggedInWithTemporaryPassword();

        $this->get('/dashboard')->assertRedirect('/change-password');
        $this->get('/users')->assertRedirect('/change-password');
        $this->get('/settings/profile')->assertRedirect('/change-password');

        $this->get('/change-password')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/change-temporary-password')
            ->where('email', 'jane.tan@example.com')
            ->where('name', 'Jane Tan'));
    }

    public function test_the_api_is_closed_until_the_password_is_changed()
    {
        $user = $this->loggedInWithTemporaryPassword();

        $this->getJson('/api/reports')->assertForbidden();

        $token = $user->createToken('desk')->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/reports')->assertForbidden();
    }

    public function test_they_can_log_out_without_changing_it()
    {
        $this->loggedInWithTemporaryPassword();

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_choosing_a_new_password_finishes_setting_up_the_account()
    {
        $user = $this->loggedInWithTemporaryPassword();

        $this->put('/change-password', ['name' => 'Jane T', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Jane T', $user->name);
        $this->assertTrue(Hash::check(self::NEW, $user->password));

        // Still signed in, and now free to use the app; the page has nothing more to ask
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->get('/change-password')->assertRedirect('/dashboard');

        // The temporary password no longer works
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => self::TEMPORARY])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::NEW])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_new_password_must_be_valid_and_different_from_the_temporary_one()
    {
        $user = $this->loggedInWithTemporaryPassword();

        $this->changePassword(self::TEMPORARY)->assertSessionHasErrors('password');
        $this->changePassword('short')->assertSessionHasErrors('password');
        $this->put('/change-password', ['name' => 'Jane', 'password' => self::NEW, 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->put('/change-password', ['name' => '', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertSessionHasErrors('name');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY, $user->fresh()->password));
    }

    public function test_someone_with_their_own_password_is_not_interrupted()
    {
        $user = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/change-password')->assertRedirect('/dashboard');
    }

    // ------------------------------------------------------------------ An admin setting a password later

    public function test_a_password_an_admin_sets_for_someone_else_must_also_be_changed()
    {
        $user = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id]);
        $details = ['name' => $user->name, 'email' => $user->email, 'company_id' => $this->company->id];

        // Editing other details leaves their password alone
        $this->actingAs($this->admin)->put('/users/' . $user->uuid, $details)->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->must_change_password);

        $this->actingAs($this->admin)->put('/users/' . $user->uuid, $details + [
            'password' => self::TEMPORARY,
            'password_confirmation' => self::TEMPORARY,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_an_admin_changing_their_own_password_is_not_made_to_change_it_again()
    {
        $this->actingAs($this->admin)->put('/users/' . $this->admin->uuid, [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'password' => self::TEMPORARY,
            'password_confirmation' => self::TEMPORARY,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->admin->fresh()->must_change_password);
    }

    public function test_a_super_admin_signed_in_as_them_is_not_stuck_on_the_page()
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        // Set up earlier, then given a new password by an admin
        $user = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id]);
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($superAdmin)->post('/users/' . $user->uuid . '/impersonate')->assertRedirect();

        $this->get('/dashboard')->assertOk();
        // They still may not change it for them
        $this->changePassword()->assertRedirect();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_no_invitation_can_be_resent_to_someone_given_a_password()
    {
        $user = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'email_verified_at' => null]);
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->admin)->post('/users/' . $user->uuid . '/invitation')->assertSessionHasErrors('invitation');
        $this->actingAs($this->admin)->post('/clients/' . $this->company->uuid . '/staff/' . $user->uuid . '/invitation')->assertSessionHasErrors('invitation');
        $this->actingAs($this->admin)->post('/users/invitations', ['user_ids' => [$user->id]])->assertSessionHasErrors('invitation');

        Notification::assertNotSentTo($user, UserInvited::class);
    }
}
