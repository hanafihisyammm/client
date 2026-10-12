<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;

/**
 * Helpers for building client companies with their manager, staff, projects and issues.
 */
trait BuildsCompanies
{
    protected function makeAdmin(string $role = 'admin', array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attributes));
    }

    /**
     * A company together with its manager.
     *
     * @return array{0: Company, 1: User}
     */
    protected function makeCompany(string $name): array
    {
        $company = Company::create(['name' => $name, 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'manager', 'company_id' => $company->id, 'name' => $name . ' Manager']);
        $company->update(['manager_id' => $manager->id]);

        return [$company->fresh(), $manager];
    }

    /**
     * @param  array<int, Project>  $projects  Projects to put the staff member on
     */
    protected function makeStaff(Company $company, array $projects = [], array $attributes = []): User
    {
        $staff = User::factory()->create(array_merge(['role' => 'staff', 'company_id' => $company->id], $attributes));

        foreach ($projects as $project) {
            $this->addToProject($project, $staff);
        }

        return $staff;
    }

    protected function makeProject(Company $company, string $name, string $status = 'active'): Project
    {
        return Project::create([
            'company_id' => $company->id,
            'name' => $name,
            'status' => $status,
            'created_by' => $company->manager_id,
        ]);
    }

    protected function addToProject(Project $project, User $staff): void
    {
        $project->users()->attach($staff->id, [
            'role' => 'member',
            'assigned_by' => $project->created_by,
            'assigned_at' => now(),
        ]);
    }

    protected function makeIssue(Project $project, User $reporter, array $attributes = []): Report
    {
        return Report::create(array_merge([
            'title' => 'Login is broken',
            'description' => 'Cannot log in',
            'project_id' => $project->id,
            'priority' => 'high',
            'reported_by' => $reporter->id,
            'status' => 'open',
        ], $attributes));
    }

    protected function act(User $user, Report $issue, array $payload)
    {
        return $this->actingAs($user)->patchJson('/api/reports/' . $issue->uuid, $payload);
    }
}
