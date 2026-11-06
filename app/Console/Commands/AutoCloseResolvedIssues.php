<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;

class AutoCloseResolvedIssues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'desk:auto-close';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close resolved issues nobody has confirmed or reopened within ' . Report::AUTO_CLOSE_AFTER_DAYS . ' days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $closed = 0;

        Report::dueForAutoClose()->with('project')->chunkById(100, function ($reports) use (&$closed) {
            foreach ($reports as $report) {
                if ($report->autoClose()) {
                    $closed++;
                }
            }
        });

        $this->info('Closed ' . $closed . ' resolved ' . ($closed === 1 ? 'issue' : 'issues') . ' nobody reviewed.');

        return self::SUCCESS;
    }
}
