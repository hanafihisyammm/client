<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class TestNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the notification system';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService)
    {
        $this->info('Testing notification system...');

        // Get test users
        $admin = User::where('role', 'admin')->first();
        $client = User::where('role', 'client')->first();

        if (!$admin) {
            $this->error('No admin user found. Please create an admin user first.');
            return;
        }

        if (!$client) {
            $this->error('No client user found. Please create a client user first.');
            return;
        }

        $this->info("Admin: {$admin->name} ({$admin->email})");
        $this->info("Client: {$client->name} ({$client->email})");

        // Get or create a test project
        $project = Project::first();
        if (!$project) {
            $this->error('No projects found. Please create a project first.');
            return;
        }

        $this->info("Project: {$project->name}");

        // Test 1: Project updated notification
        $this->info("\n--- Testing Project Updated Notification ---");
        $changes = [
            'status' => ['old' => 'active', 'new' => 'inactive'],
            'description' => ['old' => 'Old description', 'new' => 'New description']
        ];
        $notificationService->notifyProjectUpdated($project, $admin, $changes);
        $this->info('✓ Project update notification sent');

        // Test 2: Report created notification
        $this->info("\n--- Testing Report Created Notification ---");
        $report = Report::with('project')->first();
        if (!$report) {
            $this->error('No reports found. Please create a report first.');
            return;
        }
        $notificationService->notifyReportCreated($report, $client);
        $this->info('✓ Report created notification sent');

        // Test 3: Report status changed notification
        $this->info("\n--- Testing Report Status Changed Notification ---");
        $notificationService->notifyReportStatusChanged($report, $admin, 'pending', 'in_progress');
        $this->info('✓ Report status change notification sent');

        $this->info("\n🎉 All notification tests completed!");
        $this->info("Check your email inbox to verify notifications were sent.");
        $this->info("Also check storage/logs/laravel.log for any email sending logs.");
    }
}