<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCompanies;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use BuildsCompanies, RefreshDatabase;

    public function test_an_admin_creates_a_company_by_inviting_a_new_manager()
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/clients', ['name' => 'Acme Trading', 'manager_email' => 'aisha.rahman@example.com'])
            ->assertRedirect();

        $company = Company::where('name', 'Acme Trading')->firstOrFail();
        $manager = User::where('email', 'aisha.rahman@example.com')->firstOrFail();

        $this->assertSame($manager->id, $company->manager_id);
        $this->assertSame('manager', $manager->role);
        $this->assertSame($company->id, $manager->company_id);
        $this->assertSame('Aisha Rahman', $manager->name);
        $this->assertNull($manager->email_verified_at);
        Notification::assertSentTo($manager, UserInvited::class);
    }

    public function test_an_admin_can_put_an_existing_unassigned_manager_in_charge()
    {
        $admin = $this->makeAdmin();
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($admin)->get('/clients')
            ->assertInertia(fn (Assert $page) => $page->has('availableManagers', 1)->where('availableManagers.0.id', $manager->id));

        $this->actingAs($admin)->post('/clients', ['name' => 'Acme Trading', 'manager_id' => $manager->id])->assertRedirect();

        $company = Company::firstOrFail();
        $this->assertSame($manager->id, $company->manager_id);
        $this->assertSame($company->id, $manager->fresh()->company_id);

        // Once in charge of a company they are no longer offered for another
        $this->actingAs($admin)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('availableManagers', 0));
    }

    public function test_a_company_needs_a_manager_and_a_unique_name()
    {
        $admin = $this->makeAdmin();
        $this->makeCompany('Acme');

        $this->actingAs($admin)->post('/clients', ['name' => 'No Manager Ltd'])->assertSessionHasErrors('manager_email');
        $this->actingAs($admin)->post('/clients', ['name' => 'Acme', 'manager_email' => 'x@example.com'])->assertSessionHasErrors('name');

        $this->assertSame(1, Company::count());
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_a_manager_cannot_be_in_charge_of_two_companies()
    {
        $admin = $this->makeAdmin();
        [, $acmeManager] = $this->makeCompany('Acme');

        $this->actingAs($admin)->post('/clients', ['name' => 'Borneo', 'manager_id' => $acmeManager->id])
            ->assertSessionHasErrors('manager_id');
        $this->actingAs($admin)->post('/clients', ['name' => 'Borneo', 'manager_email' => $acmeManager->email])
            ->assertSessionHasErrors('manager_email');

        $this->assertSame(1, Company::count());
    }

    public function test_staff_and_admins_cannot_be_made_a_companys_manager()
    {
        $admin = $this->makeAdmin();
        [$acme] = $this->makeCompany('Acme');
        $staff = $this->makeStaff($acme);

        $this->actingAs($admin)->post('/clients', ['name' => 'Borneo', 'manager_id' => $staff->id])->assertSessionHasErrors('manager_id');
        $this->actingAs($admin)->post('/clients', ['name' => 'Borneo', 'manager_email' => $admin->email])->assertSessionHasErrors('manager_email');

        $this->assertSame('staff', $staff->fresh()->role);
        $this->assertSame(1, Company::count());
    }

    public function test_changing_the_manager_moves_access_to_the_new_one()
    {
        $admin = $this->makeAdmin();
        [$company, $oldManager] = $this->makeCompany('Acme');
        $project = $this->makeProject($company, 'Website');
        $issue = $this->makeIssue($project, $oldManager);
        $newManager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($admin)->patch('/clients/' . $company->uuid, [
            'name' => 'Acme',
            'status' => 'active',
            'manager_id' => $newManager->id,
        ])->assertSessionHas('flash.message', 'Client company updated and manager changed successfully.');

        $this->assertSame($newManager->id, $company->fresh()->manager_id);
        $this->assertSame($company->id, $newManager->fresh()->company_id);
        $this->assertNull($oldManager->fresh()->company_id);

        // The new manager can see the company's work; the old one no longer can
        $this->actingAs($newManager->fresh())->get('/reports/' . $issue->uuid)->assertOk();
        $this->actingAs($newManager->fresh())->get('/clients/' . $company->uuid)->assertOk();
        $this->actingAs($oldManager->fresh())->get('/reports/' . $issue->uuid)->assertForbidden();
        $this->actingAs($oldManager->fresh())->get('/clients/' . $company->uuid)->assertForbidden();
        $this->assertSame([], $this->actingAs($oldManager->fresh())->getJson('/api/reports')->json('data'));
    }

    public function test_the_company_page_shows_its_manager_staff_projects_and_counts()
    {
        $admin = $this->makeAdmin();
        [$company, $manager] = $this->makeCompany('Acme');
        $website = $this->makeProject($company, 'Website');
        $this->makeProject($company, 'Old Portal', 'completed');
        $staff = $this->makeStaff($company, [$website], ['name' => 'Ben Staff']);
        $this->makeStaff($company, [], ['deactivated_at' => now()]);
        $this->makeIssue($website, $staff);
        $this->makeIssue($website, $staff, ['status' => 'closed']);

        // Another company's records must not leak into the counts
        [$other] = $this->makeCompany('Borneo');
        $this->makeIssue($this->makeProject($other, 'Ordering'), $this->makeStaff($other));

        $this->actingAs($admin)->get('/clients/' . $company->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clients/show')
                ->where('company.manager.id', $manager->id)
                ->has('staff', 2)
                ->has('projects', 2)
                ->where('counts', [
                    'staff' => 2,
                    'active_staff' => 1,
                    'projects' => 2,
                    'active_projects' => 1,
                    'issues' => 2,
                    'open_issues' => 1,
                ])
                ->where('canManageCompany', true)
                ->where('canManageStaff', true));
    }

    public function test_only_admins_create_or_edit_companies()
    {
        [$company, $manager] = $this->makeCompany('Acme');
        $staff = $this->makeStaff($company);

        foreach ([$manager, $staff] as $user) {
            $this->actingAs($user)->get('/clients')->assertForbidden();
            $this->actingAs($user)->post('/clients', ['name' => 'Mine', 'manager_email' => 'm@example.com'])->assertForbidden();
            $this->actingAs($user)->patch('/clients/' . $company->uuid, ['name' => 'Renamed', 'status' => 'inactive'])->assertForbidden();
        }

        $this->assertSame('Acme', $company->fresh()->name);
        $this->assertSame(1, Company::count());
    }
}
