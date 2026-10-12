<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Light / Dark / System theme setting: what is saved, where, and what the page is told.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'staff']);
    }

    private function choose(string $appearance, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->from('/settings/appearance')->patch('/settings/appearance', ['appearance' => $appearance]);
    }

    // ------------------------------------------------------------------ Defaults

    public function test_someone_who_has_never_chosen_gets_the_system_theme()
    {
        $this->assertNull($this->user->appearance);

        $this->actingAs($this->user)->get('/settings/appearance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/appearance')->where('appearance', 'system'))
            ->assertSee('data-appearance="system"', false);
    }

    public function test_the_login_page_follows_the_system_theme_for_a_new_visitor()
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-appearance="system"', false)
            ->assertDontSee('class="dark"', false);
    }

    // ------------------------------------------------------------------ Choosing

    public function test_each_theme_can_be_chosen_and_is_saved_to_the_account_and_the_browser()
    {
        foreach (['dark', 'light', 'system'] as $appearance) {
            $this->choose($appearance)
                ->assertRedirect('/settings/appearance')
                ->assertSessionHasNoErrors()
                ->assertPlainCookie('appearance', $appearance);

            $this->assertSame($appearance, $this->user->fresh()->appearance);

            $this->actingAs($this->user->fresh())->get('/settings/appearance')
                ->assertInertia(fn (Assert $page) => $page->where('appearance', $appearance))
                ->assertSee('data-appearance="' . $appearance . '"', false);
        }
    }

    public function test_the_dark_theme_is_on_the_page_from_the_first_byte_so_it_never_flashes_light()
    {
        $this->choose('dark');

        $html = $this->actingAs($this->user->fresh())->get('/dashboard')->assertOk()->getContent();

        // The class is on <html> itself, before any script or stylesheet has run
        $this->assertMatchesRegularExpression('/<html[^>]*data-appearance="dark"[^>]*class="dark"/', $html);

        $this->choose('light');
        $html = $this->actingAs($this->user->fresh())->get('/dashboard')->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-appearance="light"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*class="dark"/', $html);
    }

    public function test_only_light_dark_and_system_are_accepted()
    {
        $this->choose('dark');

        foreach (['', 'blue', 'DARK', 'auto'] as $invalid) {
            $this->choose($invalid)->assertSessionHasErrors('appearance');
        }

        $this->assertSame('dark', $this->user->fresh()->appearance);
    }

    public function test_a_guest_cannot_save_a_theme()
    {
        $this->patch('/settings/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
    }

    public function test_one_persons_choice_does_not_affect_anyone_else()
    {
        $other = User::factory()->create(['role' => 'staff']);

        $this->choose('dark');

        $this->assertNull($other->fresh()->appearance);
        $this->actingAs($other)->get('/settings/appearance')->assertInertia(fn (Assert $page) => $page->where('appearance', 'system'));
    }

    // ------------------------------------------------------------------ Persistence

    public function test_the_choice_is_still_there_after_logging_out_and_in_again()
    {
        $this->choose('dark');
        $this->post('/logout');
        $this->assertGuest();

        // Logged out, the same browser keeps the theme through its cookie
        $this->withUnencryptedCookie('appearance', 'dark')->get('/login')
            ->assertSee('data-appearance="dark"', false)
            ->assertSee('class="dark"', false);

        $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($this->user);

        $this->get('/settings/appearance')->assertInertia(fn (Assert $page) => $page->where('appearance', 'dark'));
    }

    public function test_the_saved_choice_follows_the_user_to_a_browser_that_has_never_seen_it()
    {
        $this->user->forceFill(['appearance' => 'dark'])->save();

        // No cookie at all: a different computer
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('data-appearance="dark"', false)
            ->assertPlainCookie('appearance', 'dark');
    }

    public function test_the_account_setting_wins_over_an_older_choice_left_in_the_browser()
    {
        $this->user->forceFill(['appearance' => 'light'])->save();

        $this->actingAs($this->user)->withUnencryptedCookie('appearance', 'dark')->get('/dashboard')
            ->assertSee('data-appearance="light"', false)
            ->assertDontSee('class="dark"', false)
            ->assertPlainCookie('appearance', 'light');
    }

    public function test_a_tampered_cookie_falls_back_to_the_system_theme()
    {
        $this->withUnencryptedCookie('appearance', '"><script>alert(1)</script>')->get('/login')
            ->assertOk()
            ->assertSee('data-appearance="system"', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ With "Sign in as"

    public function test_a_super_admin_signed_in_as_someone_sees_their_theme_but_cannot_change_it()
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'appearance' => 'light']);
        $this->user->forceFill(['appearance' => 'dark'])->save();

        $this->actingAs($superAdmin)->post('/users/' . $this->user->uuid . '/impersonate');
        $this->assertTrue(session()->has(Impersonation::SESSION_KEY));

        $this->get('/dashboard')->assertSee('data-appearance="dark"', false);

        $this->patch('/settings/appearance', ['appearance' => 'light'])->assertSessionHas('flash.type', 'error');
        $this->assertSame('dark', $this->user->fresh()->appearance);

        // Back as themselves, their own theme again
        $this->post('/impersonation/stop');
        $this->get('/users')->assertSee('data-appearance="light"', false);
        $this->assertSame('light', $superAdmin->fresh()->appearance);
    }
}
