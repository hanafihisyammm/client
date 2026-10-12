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

    public function test_an_admin_can_promote_one_of_the_companys_staff_to_manager()
    {
        foreach (['admin', 'super_admin'] as $role) {
            $admin = $this->makeAdmin($role);
            [$company, $oldManager] = $this->makeCompany('Acme ' . $role);
            $project = $this->makeProject($company, 'Website');
            $other = $this->makeProject($company, 'Mobile');
            $staff = $this->makeStaff($company, [$project], ['name' => 'Ben Staff']);
            $hidden = $this->makeIssue($other, $oldManager);

            $this->actingAs($staff)->get('/reports/' . $hidden->uuid)->assertForbidden();

            $this->actingAs($admin)->patch('/clients/' . $company->uuid, [
                'name' => $company->name,
                'status' => 'active',
                'manager_id' => $staff->id,
            ])->assertSessionHasNoErrors()
                ->assertSessionHas('flash.message', 'Client company updated and manager changed successfully.');

            $staff->refresh();
            $this->assertSame(['manager', $company->id], [$staff->role, $staff->company_id]);
            $this->assertSame($staff->id, $company->fresh()->manager_id);
            // A manager is on every project, so the individual assignment is gone
            $this->assertSame(0, $staff->projects()->count());

            // Manager access to the whole company now
            $this->actingAs($staff)->get('/reports/' . $hidden->uuid)->assertOk();
            $this->actingAs($staff)->get('/clients/' . $company->uuid)->assertOk();

            // By default the previous manager leaves the company
            $this->assertSame(['manager', null], [$oldManager->fresh()->role, $oldManager->fresh()->company_id]);
            $this->actingAs($oldManager->fresh())->get('/clients/' . $company->uuid)->assertForbidden();
        }
    }

    public function test_the_previous_manager_can_stay_in_the_company_as_staff()
    {
        $admin = $this->makeAdmin();
        [$company, $oldManager] = $this->makeCompany('Acme');
        $project = $this->makeProject($company, 'Website');
        $staff = $this->makeStaff($company, [$project]);
        // Reported by someone else: people can always see issues they reported themselves
        $issue = $this->makeIssue($project, $staff);

        $this->actingAs($admin)->patch('/clients/' . $company->uuid, [
            'name' => 'Acme',
            'status' => 'active',
            'manager_id' => $staff->id,
            'previous_manager' => 'staff',
        ])->assertSessionHasNoErrors();

        $oldManager->refresh();
        $this->assertSame(['staff', $company->id], [$oldManager->role, $oldManager->company_id]);

        // As staff with no projects yet, they see none of the company's work or its management page
        $this->actingAs($oldManager)->get('/reports/' . $issue->uuid)->assertForbidden();
        $this->actingAs($oldManager)->get('/clients/' . $company->uuid)->assertForbidden();
        $this->assertSame([], $this->actingAs($oldManager)->getJson('/api/reports')->json('data'));

        // ...and they now appear among the staff the new manager can assign
        $this->actingAs($staff->fresh())->get('/clients/' . $company->uuid)
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('company.manager.id', $staff->id)
                ->where('staff.0.id', $oldManager->id));
    }

    public function test_only_this_companys_active_staff_can_be_promoted()
    {
        $admin = $this->makeAdmin();
        [$acme, $acmeManager] = $this->makeCompany('Acme');
        [$borneo] = $this->makeCompany('Borneo');
        $outsider = $this->makeStaff($borneo);
        $deactivated = $this->makeStaff($acme, [], ['deactivated_at' => now()]);
        $payload = ['name' => 'Acme', 'status' => 'active'];

        // Staff of another company, a deactivated staff member, and an admin
        foreach ([$outsider, $deactivated, $admin] as $person) {
            $this->actingAs($admin)->patch('/clients/' . $acme->uuid, $payload + ['manager_id' => $person->id])
                ->assertSessionHasErrors('manager_id');
        }
        $this->actingAs($admin)->patch('/clients/' . $acme->uuid, $payload + ['manager_id' => $acmeManager->id, 'previous_manager' => 'owner'])
            ->assertSessionHasErrors('previous_manager');

        $this->assertSame($acmeManager->id, $acme->fresh()->manager_id);
        $this->assertSame(['staff', $borneo->id], [$outsider->fresh()->role, $outsider->fresh()->company_id]);
        $this->assertSame('staff', $deactivated->fresh()->role);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_managers_and_staff_cannot_change_who_the_manager_is()
    {
        [$company, $manager] = $this->makeCompany('Acme');
        $staff = $this->makeStaff($company);
        $payload = ['name' => 'Acme', 'status' => 'active', 'manager_id' => $staff->id];

        foreach ([$manager, $staff] as $user) {
            $this->actingAs($user)->patch('/clients/' . $company->uuid, $payload)->assertForbidden();
        }

        $this->assertSame($manager->id, $company->fresh()->manager_id);
        $this->assertSame('staff', $staff->fresh()->role);
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
