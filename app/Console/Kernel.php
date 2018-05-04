<?php

namespace App\Console;

use App\Tasks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //每天凌晨更新用户每日任务
        $schedule->call(function () {
            Log::info(123);
            $tasks = Tasks::select('id')
                ->where('type', 2)
                ->get()->toArray();

            $tasks_rand = json_encode($tasks[array_rand($tasks)]);
            $key = 'task:random';

            if (Redis::exists($key)) {
                Redis::del($key);
            }

            Redis::set($key, $tasks_rand);
        })->daily();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
