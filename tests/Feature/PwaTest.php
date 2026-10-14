<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidra Desk can be installed as an app. What the service worker does with requests is
 * tested in tests/browser/service-worker.test.mjs; installing it in a real browser in
 * tests/browser/pwa.test.mjs.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_describes_an_installable_app()
    {
        $manifest = $this->manifest();

        $this->assertSame('Sidra Desk', $manifest['name']);
        $this->assertSame('Sidra Desk', $manifest['short_name']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['id']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['theme_color']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['background_color']);
    }

    public function test_the_manifest_has_every_icon_the_browsers_ask_for()
    {
        $icons = collect($this->manifest()['icons']);

        foreach (['any', 'maskable'] as $purpose) {
            foreach (['192x192', '512x512'] as $size) {
                $this->assertTrue(
                    $icons->contains(fn ($icon) => $icon['purpose'] === $purpose && $icon['sizes'] === $size),
                    "A {$size} icon for \"{$purpose}\"",
                );
            }
        }

        // Each icon exists and really is the size the manifest says
        foreach ($icons as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);

            [$width, $height, $type] = getimagesize($path);
            $this->assertSame($icon['sizes'], "{$width}x{$height}", $icon['src']);
            $this->assertSame(IMAGETYPE_PNG, $type, $icon['src']);
            $this->assertSame('image/png', $icon['type']);
        }
    }

    public function test_the_service_worker_and_offline_page_are_published()
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        // The offline page is the same for everyone: no template, token or user data in it
        $offline = file_get_contents(public_path('offline.html'));
        $this->assertStringNotContainsString('{{', $offline);
        $this->assertStringNotContainsString('csrf', strtolower($offline));
        $this->assertStringContainsString("You're offline", $offline);

        // Everything the service worker keeps for offline use is a real file
        $worker = file_get_contents(public_path('sw.js'));
        foreach (['/offline.html', '/icons/icon-192.png', '/favicon.svg'] as $path) {
            $this->assertStringContainsString("'{$path}'", $worker);
            $this->assertFileExists(public_path(ltrim($path, '/')), $path);
        }
    }

    public function test_every_page_links_the_manifest_and_app_settings()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        foreach ([$this->get('/login'), $this->actingAs($user)->get('/dashboard')] as $response) {
            $response->assertOk()
                ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
                ->assertSee('<meta name="theme-color"', false)
                ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
                ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
        }
    }

    public function test_the_title_bar_follows_a_saved_dark_theme()
    {
        $user = User::factory()->withoutTwoFactor()->create(['appearance' => 'dark']);

        $this->actingAs($user)->get('/dashboard')->assertSee('<meta name="theme-color" content="#0e1116">', false);
    }

    public function test_signed_in_pages_are_never_stored_by_shared_caches()
    {
        $user = User::factory()->withoutTwoFactor()->create();

        $page = $this->actingAs($user)->get('/dashboard');
        $page->assertOk();
        $this->assertStringContainsString('private', $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $page->headers->get('Cache-Control'));

        $api = $this->actingAs($user)->getJson('/api/notifications');
        $api->assertOk();
        $this->assertStringContainsString('no-cache', $api->headers->get('Cache-Control'));
    }

    public function test_the_installed_app_opens_on_the_login_page_when_signed_out()
    {
        $this->get($this->manifest()['start_url'])->assertRedirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
