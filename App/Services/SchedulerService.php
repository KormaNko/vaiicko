<?php

namespace App\Services;

use App\Models\Task;
use InvalidArgumentException;

/**
 * SchedulerService
 *
 * Responsible for automatically scheduling tasks (dynamic and non-dynamic) for a given user.
 * It writes planned_start and planned_end on tasks that are not yet planned.
 */
class SchedulerService
{
    private string $workStart = '08:00:00';
    private string $workEnd   = '16:00:00';

    public function __construct(string $workStart = null, string $workEnd = null)
    {
        if ($workStart !== null) {
            $this->validateTimeFormat($workStart);
            $this->workStart = $workStart;
        }
        if ($workEnd !== null) {
            $this->validateTimeFormat($workEnd);
            $this->workEnd = $workEnd;
        }
    }

    /**
     * Recalculate scheduling for tasks of a user.
     *
     * Only dynamic tasks (is_dynamic = 1) will be (re)scheduled. Static tasks are considered
     * when building occupied blocks but will not be modified.
     *
     * @param int $userId
     */
    public function recalculateForUser(int $userId): void
    {
        $this->log("Scheduler run for user={$userId}");

        // === Clear existing planned times for dynamic tasks so we can recalculate them ===
        $toClear = Task::getAll(
            "user_id = ? AND is_dynamic = 1 AND status != 'completed'",
            [$userId]
        );

        $this->log('Found toClear count=' . (is_array($toClear) ? count($toClear) : 0));

        if (is_array($toClear)) {
            foreach ($toClear as $t) {
                $this->log('Clearing planned for task id=' . $t->getId());
                $t->setPlannedStart(null);
                $t->setPlannedEnd(null);
                try {
                    $t->save();
                } catch (\Exception $e) {
                    // ignore individual save errors to keep scheduling running
                    $this->log('Failed to clear task id=' . $t->getId() . ' error=' . $e->getMessage());
                }
            }
        }

        // 1) Load already occupied blocks (planned tasks from any source - static/manual or already planned)
        $existing = Task::getAll(
            "user_id = ? AND planned_start IS NOT NULL AND status != 'completed'",
            [$userId]
        );

        $occupied = [];

        $this->log('Found existing planned count=' . (is_array($existing) ? count($existing) : 0));

        if (is_array($existing)) {
            foreach ($existing as $t) {
                $ps = $t->getPlannedStart();
                $pe = $t->getPlannedEnd();
                if (!$ps || !$pe) {
                    continue;
                }
                $s = strtotime($ps);
                $e = strtotime($pe);
                if ($s === false || $e === false || $e <= $s) {
                    continue;
                }
                $occupied[] = [
                    'start' => (int)$s,
                    'end'   => (int)$e,
                ];
            }
        }

        // ensure occupied blocks are sorted by start
        usort($occupied, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        // 2) Load dynamic tasks that are NOT planned yet
        $tasks = Task::getAll(
            "user_id = ? AND is_dynamic = 1 AND planned_start IS NULL AND status != 'completed'",
            [$userId],
            "deadline ASC, priority DESC"
        );

        $this->log('Found dynamic unplanned count=' . (is_array($tasks) ? count($tasks) : 0));

        if (!is_array($tasks)) {
            return;
        }

        $currentDate = date('Y-m-d');
        $currentTime = $this->workStart;

        foreach ($tasks as $task) {
            $this->log('Considering task id=' . $task->getId() . ' timeToComplete=' . var_export($task->getTimeToComplete(), true) . ' deadline=' . var_export($task->getDeadline(), true));

            $minutesNeeded = $task->getTimeToComplete();

            if ($minutesNeeded === null || $minutesNeeded <= 0) {
                $this->log('Skipping task id=' . $task->getId() . ' due to missing or non-positive timeToComplete');
                continue;
            }

            while ($minutesNeeded > 0) {
                $dayStart = strtotime($this->combine($currentDate, $this->workStart));
                $dayEnd   = strtotime($this->combine($currentDate, $this->workEnd));
                $now      = strtotime($this->combine($currentDate, $currentTime));

                // if we're past the work day's end, move to next day
                if ($now >= $dayEnd) {
                    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                    $currentTime = $this->workStart;
                    continue;
                }

                // ensure now is at least dayStart
                if ($now < $dayStart) {
                    $now = $dayStart;
                    $currentTime = $this->workStart;
                }

                $taskStart = $now;
                $taskEnd   = strtotime("+{$minutesNeeded} minutes", $taskStart);

                // cap to day end
                if ($taskEnd > $dayEnd) {
                    $taskEnd = $dayEnd;
                }

                // check collisions with occupied blocks
                $collision = false;
                foreach ($occupied as $block) {
                    // if block is completely before this day, skip; if after dayEnd, skip
                    if ($block['end'] <= $dayStart || $block['start'] >= ($dayEnd + 24*3600)) {
                        continue;
                    }

                    // if collision within the current day
                    if ($taskStart < $block['end'] && $taskEnd > $block['start']) {
                        // if the block end goes beyond current day end, advance to next day
                        if ($block['end'] >= $dayEnd) {
                            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                            $currentTime = $this->workStart;
                        } else {
                            // move start to the end of the block (time component)
                            $currentTime = date('H:i:s', $block['end']);
                        }
                        $collision = true;
                        break;
                    }
                }

                if ($collision) {
                    // re-evaluate with new currentTime/date
                    continue;
                }

                // persist planned times
                $task->setPlannedStart(date('Y-m-d H:i:s', $taskStart));
                $task->setPlannedEnd(date('Y-m-d H:i:s', $taskEnd));
                try {
                    $task->save();
                    $this->log('Scheduled task id=' . $task->getId() . ' start=' . $task->getPlannedStart() . ' end=' . $task->getPlannedEnd());
                } catch (\Exception $e) {
                    $this->log('Failed to save scheduled times for task id=' . $task->getId() . ' error=' . $e->getMessage());
                }

                // add to occupied
                $occupied[] = [
                    'start' => (int)$taskStart,
                    'end' => (int)$taskEnd,
                ];

                // keep occupied sorted for consistent collision checks
                usort($occupied, function ($a, $b) {
                    return $a['start'] <=> $b['start'];
                });

                $minutesUsed = ($taskEnd - $taskStart) / 60;
                $minutesNeeded -= $minutesUsed;

                // advance currentTime to end of the scheduled block
                // if taskEnd is at or beyond dayEnd, the while loop will advance to next day at top
                $currentTime = date('H:i:s', $taskEnd);
            }
        }

        $this->log('Scheduler finished for user=' . $userId);
    }

    private function combine(string $date, string $time): string
    {
        return $date . ' ' . $time;
    }

    private function validateTimeFormat(string $time): void
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('Time must be in HH:MM:SS format');
        }
    }

    private function log(string $line): void
    {
        $root = dirname(__DIR__, 2); // project root
        $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'scheduler.log';
        $time = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$time}] " . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
