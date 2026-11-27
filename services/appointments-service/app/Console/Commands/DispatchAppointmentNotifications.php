<?php

namespace App\Console\Commands;

use App\Services\AppointmentNotificationService;
use Illuminate\Console\Command;

class DispatchAppointmentNotifications extends Command
{
    protected $signature = 'appointments:dispatch-notifications';

    protected $description = 'Queue appointment notifications that are due.';

    public function handle(AppointmentNotificationService $notifications): int
    {
        $count = $notifications->dispatchDue();
        $this->info("Queued {$count} due appointment notifications.");

        return self::SUCCESS;
    }
}
