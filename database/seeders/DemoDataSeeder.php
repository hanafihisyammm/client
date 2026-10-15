<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportActivityLog;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Two client companies with their managers, staff, projects and issues, so every
 * role and the separation between companies can be tried on a local machine.
 *
 * Every address ends in @example.com, which can never be delivered to.
 * Every password is "password".
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Demo data may only be written to a local or testing database on this machine.
     */
    public static function isSafeHere(): bool
    {
        $connection = config('database.default');
        $host = config("database.connections.{$connection}.host");

        return app()->environment(['local', 'testing'])
            && in_array($host, ['127.0.0.1', 'localhost', '::1', null], true);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!self::isSafeHere()) {
            throw new RuntimeException('Refusing to seed demo data: this is not a local database.');
        }

        if (User::exists()) {
            throw new RuntimeException('Refusing to seed demo data into a database that already has users. Run "php artisan migrate:fresh --seed" on a local database instead.');
        }

        // Uploaded attachments belong to the data that was just wiped
        Storage::disk('public')->deleteDirectory('reports');

        $superAdmin = $this->user('Super Admin', 'samantha@sidracore.com', 'super_admin');
        $admin = $this->user('Adam Admin', 'admin@example.com', 'admin');

        // ---- Acme Trading
        $acme = Company::create(['name' => 'Acme Trading', 'status' => 'active', 'created_by' => $superAdmin->id]);
        $aisha = $this->user('Aisha Rahman', 'acme.manager@example.com', 'manager', $acme);
        $acme->update(['manager_id' => $aisha->id]);

        $ben = $this->user('Ben Lim', 'acme.staff1@example.com', 'staff', $acme);
        $chloe = $this->user('Chloe Tan', 'acme.staff2@example.com', 'staff', $acme);
        $daniel = $this->user('Daniel Wong', 'acme.staff3@example.com', 'staff', $acme, deactivated: true);

        $acmeWebsite = $this->project($acme, 'Acme Website', 'Public website and online catalogue', 'active', $aisha, [$ben, $chloe, $daniel]);
        $acmeMobile = $this->project($acme, 'Acme Mobile App', 'Customer ordering app', 'active', $aisha, [$chloe]);
        $acmeLegacy = $this->project($acme, 'Acme Legacy Portal', 'Old supplier portal, now retired', 'completed', $aisha, [$ben]);

        $this->issue($acmeWebsite, $ben, 'Checkout button does nothing on Safari', 'urgent', 'open', dueInDays: 2);
        $this->issue($acmeWebsite, $chloe, 'Product images load slowly', 'medium', 'open', dueInDays: -3);
        $this->issue($acmeWebsite, $ben, 'Search returns out-of-stock items', 'high', 'in_progress', worker: $chloe, dueInDays: 5);
        $this->issue($acmeWebsite, $ben, 'Wrong tax shown on invoices', 'high', 'resolved', worker: $chloe, resolver: $chloe, summary: 'Tax rate table corrected and invoices regenerated.');
        $this->issue($acmeWebsite, $chloe, 'Contact form emails go to spam', 'medium', 'reopened', worker: $ben, resolver: $ben, reopenedBy: $chloe, reason: 'Still landing in spam for Outlook addresses.');
        $this->issue($acmeMobile, $chloe, 'App crashes when the cart is empty', 'urgent', 'in_progress', worker: $chloe, dueInDays: 1);
        $this->issue($acmeMobile, $aisha, 'Add a reorder button to past orders', 'low', 'open');
        $this->issue($acmeLegacy, $ben, 'Export supplier list before shutdown', 'medium', 'closed', worker: $ben, resolver: $ben, closedBy: $aisha, summary: 'Exported to CSV and archived.');

        // ---- Borneo Foods
        $borneo = Company::create(['name' => 'Borneo Foods', 'status' => 'active', 'created_by' => $admin->id]);
        $farah = $this->user('Farah Idris', 'borneo.manager@example.com', 'manager', $borneo);
        $borneo->update(['manager_id' => $farah->id]);

        $gopal = $this->user('Gopal Nair', 'borneo.staff1@example.com', 'staff', $borneo);
        $hana = $this->user('Hana Yusof', 'borneo.staff2@example.com', 'staff', $borneo);

        $ordering = $this->project($borneo, 'Borneo Ordering System', 'Wholesale ordering for restaurants', 'active', $farah, [$gopal, $hana]);

        $this->issue($ordering, $gopal, 'Delivery date picker allows Sundays', 'medium', 'open', dueInDays: 7);
        $this->issue($ordering, $hana, 'Price list PDF is out of date', 'high', 'in_progress', worker: $gopal);
        $this->issue($ordering, $hana, 'Duplicate orders when the page is refreshed', 'urgent', 'resolved', worker: $gopal, resolver: $gopal, summary: 'Order submission is now protected against double clicks and refreshes.');
        $this->issue($ordering, $farah, 'Add halal certificate numbers to product pages', 'low', 'open');
        $this->issue($ordering, $gopal, 'Login page logo is stretched', 'low', 'closed', worker: $hana, resolver: $hana, closedBy: $gopal, summary: 'Logo aspect ratio fixed.');

        // The audit log should start empty rather than list every seeded record as a "System" action
        AuditLog::query()->delete();

        $this->command?->info('Demo data created. Every account uses the password "' . self::PASSWORD . '".');
        $this->command?->table(['Role', 'Email', 'Company'], [
            ['Super Admin', 'samantha@sidracore.com', '-'],
            ['Admin', 'admin@example.com', '-'],
            ['Manager', 'acme.manager@example.com', 'Acme Trading'],
            ['Staff', 'acme.staff1@example.com, acme.staff2@example.com', 'Acme Trading'],
            ['Staff (deactivated)', 'acme.staff3@example.com', 'Acme Trading'],
            ['Manager', 'borneo.manager@example.com', 'Borneo Foods'],
            ['Staff', 'borneo.staff1@example.com, borneo.staff2@example.com', 'Borneo Foods'],
        ]);
    }

    private function user(string $name, string $email, string $role, ?Company $company = null, bool $deactivated = false): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'company_id' => $company?->id,
            'deactivated_at' => $deactivated ? now()->subDays(10) : null,
        ]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * @param  array<int, User>  $members
     */
    private function project(Company $company, string $name, string $description, string $status, User $createdBy, array $members): Project
    {
        $project = Project::create([
            'company_id' => $company->id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'created_by' => $createdBy->id,
        ]);

        foreach ($members as $member) {
            $project->users()->attach($member->id, [
                'role' => 'member',
                'assigned_by' => $createdBy->id,
                'assigned_at' => now(),
            ]);
        }

        return $project;
    }

    /**
     * Create an issue already at the given status, with the activity history that
     * would have led there, so timelines and "who resolved it" rules behave as in real use.
     */
    private function issue(
        Project $project,
        User $reporter,
        string $title,
        string $priority,
        string $status,
        ?User $worker = null,
        ?User $resolver = null,
        ?User $closedBy = null,
        ?User $reopenedBy = null,
        ?string $summary = null,
        ?string $reason = null,
        ?int $dueInDays = null,
    ): Report {
        $createdAt = Carbon::now()->subDays(random_int(4, 12));

        $report = Report::create([
            'title' => $title,
            'description' => "Reported by {$reporter->name} for {$project->name}.\n\nSteps to reproduce and expected behaviour would be written here.",
            'page_url' => 'https://' . Str::slug($project->name) . '.example.com/',
            'project_id' => $project->id,
            'priority' => $priority,
            'severity' => ['urgent' => 'critical', 'high' => 'major', 'medium' => 'minor', 'low' => 'trivial'][$priority],
            'reported_by' => $reporter->id,
            'resolved_by' => $resolver?->id,
            'resolution_summary' => $summary,
            'status' => $status,
            'due_date' => $dueInDays !== null ? Carbon::today()->addDays($dueInDays) : null,
            'closed_at' => $status === 'closed' ? $createdAt->copy()->addDays(3) : null,
        ]);
        $report->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        // Whoever started work on it
        $worker ??= $resolver;
        $at = $createdAt->copy();

        if ($worker && $status !== 'open') {
            $this->log($report, $worker, 'open', 'in_progress', $at->addDay());
        }

        if ($resolver) {
            $this->log($report, $resolver, 'in_progress', 'resolved', $at->addDay(), ['resolution_summary' => $summary ?? 'Issue resolved']);
        }

        if ($status === 'closed' && $closedBy) {
            $this->log($report, $closedBy, 'resolved', 'closed', $at->addDay());
        }

        if ($status === 'reopened' && $reopenedBy) {
            $this->log($report, $reopenedBy, 'resolved', 'reopened', $at->addDay(), ['reason' => $reason]);

            if ($reason) {
                $comment = new ReportComment(['report_id' => $report->id, 'user_id' => $reopenedBy->id, 'comment' => $reason]);
                $comment->created_at = $at;
                $comment->updated_at = $at;
                $comment->save();
            }
        }

        return $report;
    }

    private function log(Report $report, User $user, string $from, string $to, Carbon $at, array $extra = []): void
    {
        $log = new ReportActivityLog([
            'report_id' => $report->id,
            'user_id' => $user->id,
            'action' => 'status_changed',
            'old_values' => ['status' => $from],
            'new_values' => array_merge(['status' => $to], array_filter($extra)),
        ]);
        $log->created_at = $at->copy();
        $log->updated_at = $at->copy();
        $log->save();
    }
}
