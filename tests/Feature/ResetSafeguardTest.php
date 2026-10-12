<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo data and any database reset must stay on a developer's own machine.
 */
class ResetSafeguardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The switch is static, so put it back for the tests that follow
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    private function pretendToBe(string $environment, ?string $host = null): void
    {
        $this->app['env'] = $environment;

        if ($host !== null) {
            config(['database.connections.' . config('database.default') . '.host' => $host]);
        }
    }

    public function test_demo_data_is_only_considered_safe_on_a_local_database()
    {
        $this->assertTrue(DemoDataSeeder::isSafeHere());

        $this->pretendToBe('production');
        $this->assertFalse(DemoDataSeeder::isSafeHere());

        $this->pretendToBe('local', 'db.internal.example.com');
        $this->assertFalse(DemoDataSeeder::isSafeHere());

        $this->pretendToBe('staging', '127.0.0.1');
        $this->assertFalse(DemoDataSeeder::isSafeHere());
    }

    public function test_the_demo_seeder_refuses_to_run_in_production()
    {
        $this->pretendToBe('production');

        try {
            // --force is what a production deploy would pass; the seeder must still refuse
            Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
            $this->fail('The demo seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a local database', $e->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_seeding_in_production_only_ensures_the_super_admin()
    {
        $this->pretendToBe('production');

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(['super_admin'], User::pluck('role')->all());
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_the_demo_seeder_refuses_to_add_to_a_database_that_already_has_users()
    {
        User::factory()->create();

        try {
            $this->seed(DemoDataSeeder::class);
            $this->fail('The demo seeder ran on a database that already had users.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has users', $e->getMessage());
        }

        $this->assertSame(1, User::count());
    }

    public function test_reset_commands_are_refused_in_production()
    {
        User::factory()->create(['email' => 'keep.me@example.com']);
        DB::prohibitDestructiveCommands(true);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'] as $command) {
            $this->assertNotSame(0, Artisan::call($command, ['--force' => true]), "{$command} was not refused");
        }

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertDatabaseHas('users', ['email' => 'keep.me@example.com']);
    }

    public function test_the_demo_data_gives_each_company_its_own_manager_staff_projects_and_issues()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(['super_admin', 'admin', 'manager', 'staff'], User::distinct()->pluck('role')->all());
        // Only the Super Admin has a real address; every other demo account is non-deliverable
        $this->assertSame(['samantha@sidracore.com'], User::where('email', 'not like', '%@example.com')->pluck('email')->all());
        $this->assertSame('super_admin', User::where('email', 'samantha@sidracore.com')->value('role'));
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('audit_logs', 0);

        foreach (\App\Models\Company::with(['manager', 'staff', 'projects'])->get() as $company) {
            $this->assertSame($company->id, $company->manager->company_id);
            $this->assertGreaterThanOrEqual(2, $company->staff->count());
            $this->assertGreaterThanOrEqual(1, $company->projects->count());

            // Nobody is on a project of another company
            foreach ($company->projects as $project) {
                $this->assertSame([$company->id], $project->users->pluck('company_id')->unique()->values()->all());
                $this->assertGreaterThan(0, $project->reports()->count());
            }
        }

        $this->assertSame(1, User::whereNotNull('deactivated_at')->count());
        $this->assertSame(
            ['closed', 'in_progress', 'open', 'reopened', 'resolved'],
            \App\Models\Report::distinct()->orderBy('status')->pluck('status')->all()
        );
    }
}
