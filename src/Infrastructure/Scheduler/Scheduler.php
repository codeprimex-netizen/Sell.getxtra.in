<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Domain\Admin\SettingsRepositoryInterface;
use Throwable;

/**
 * Minimal cron-style scheduler (Req 18.3). Tasks declare a frequency in
 * minutes; run() executes those due since their last run (tracked in
 * settings) so an external cron can simply call `bin/console schedule:run`
 * every minute. Task failures are isolated.
 */
final class Scheduler
{
    /** @var array<int, array{name:string, freq:int, task:callable}> */
    private array $tasks = [];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function register(string $name, int $frequencyMinutes, callable $task): void
    {
        $this->tasks[] = ['name' => $name, 'freq' => max(1, $frequencyMinutes), 'task' => $task];
    }

    public function isDue(string $name, int $frequencyMinutes, ?int $now = null): bool
    {
        $now ??= time();
        $last = (int) $this->settings->get('schedule.last.' . $name, 0);
        return ($now - $last) >= ($frequencyMinutes * 60);
    }

    /**
     * Run all due tasks; returns the names that ran.
     *
     * @return array<int,string>
     */
    public function run(bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $ran = [];

        foreach ($this->tasks as $task) {
            if (!$force && !$this->isDue($task['name'], $task['freq'], $now)) {
                continue;
            }
            try {
                ($task['task'])();
                $this->settings->set('schedule.last.' . $task['name'], $now);
                $ran[] = $task['name'];
            } catch (Throwable) {
                // Isolate failures; other tasks still run.
            }
        }

        return $ran;
    }

    /** @return array<int,string> */
    public function taskNames(): array
    {
        return array_map(static fn ($t) => $t['name'], $this->tasks);
    }
}
