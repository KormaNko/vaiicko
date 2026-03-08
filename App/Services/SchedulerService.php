<?php

namespace App\Services;

use App\Models\Task;
use InvalidArgumentException;


class SchedulerService
{
    private string $workStart = '08:00:00';
    private string $workEnd = '16:00:00';


    private int $slotMinutes = 30;

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


    public function recalculateForUser(int $userId): void
    {
        $this->log("Scheduler run for user={$userId}");


        $toClear = Task::getAll(
            "user_id = ? AND is_dynamic = 1 AND status != 'completed'",
            [$userId]
        );

        if (is_array($toClear)) {
            foreach ($toClear as $task) {

                $task->setPlannedStart(null);
                $task->setPlannedEnd(null);

                try {
                    $task->save();
                } catch (\Exception $e) {
                    $this->log("Failed clearing task " . $task->getId());
                }
            }
        }


        $existing = Task::getAll(
            "user_id = ? AND planned_start IS NOT NULL AND status != 'completed'",
            [$userId]
        );

        $occupied = [];

        if (is_array($existing)) {
            foreach ($existing as $task) {

                $start = $task->getPlannedStart();
                $end = $task->getPlannedEnd();

                if (!$start || !$end) {
                    continue;
                }

                $occupied[] = [
                    'start' => strtotime($start),
                    'end' => strtotime($end)
                ];
            }
        }

        usort($occupied, fn($a, $b) => $a['start'] <=> $b['start']);


        $tasks = Task::getAll(
            "user_id = ? AND is_dynamic = 1 AND planned_start IS NULL AND status != 'completed'",
            [$userId],
            "deadline ASC, priority DESC"
        );

        if (!is_array($tasks)) {
            return;
        }

        $currentDate = date('Y-m-d');
        $currentTime = $this->workStart;

        foreach ($tasks as $task) {

            $minutes = $task->getTimeToComplete();

            if ($minutes === null || $minutes <= 0) {
                continue;
            }


            $slotsNeeded = (int)ceil($minutes / $this->slotMinutes);

            while ($slotsNeeded > 0) {

                $dayStart = strtotime($this->combine($currentDate, $this->workStart));
                $dayEnd = strtotime($this->combine($currentDate, $this->workEnd));

                $now = strtotime($this->combine($currentDate, $currentTime));


                $now = $this->roundToSlot($now);

                if ($now >= $dayEnd) {

                    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                    $currentTime = $this->workStart;
                    continue;
                }

                $taskStart = $now;
                $taskEnd = $taskStart + ($slotsNeeded * $this->slotMinutes * 60);

                if ($taskEnd > $dayEnd) {
                    $taskEnd = $dayEnd;
                }


                $collision = false;

                foreach ($occupied as $block) {

                    if ($taskStart < $block['end'] && $taskEnd > $block['start']) {

                        $currentTime = date('H:i:s', $block['end']);
                        $collision = true;
                        break;
                    }
                }

                if ($collision) {
                    continue;
                }


                $task->setPlannedStart(date('Y-m-d H:i:s', $taskStart));
                $task->setPlannedEnd(date('Y-m-d H:i:s', $taskEnd));

                try {
                    $task->save();
                } catch (\Exception $e) {
                    $this->log("Failed scheduling task " . $task->getId());
                }


                $occupied[] = [
                    'start' => $taskStart,
                    'end' => $taskEnd
                ];

                usort($occupied, fn($a, $b) => $a['start'] <=> $b['start']);


                $slotsUsed = ($taskEnd - $taskStart) / ($this->slotMinutes * 60);
                $slotsNeeded -= $slotsUsed;

                $currentTime = date('H:i:s', $taskEnd);
            }
        }

        $this->log("Scheduler finished for user={$userId}");
    }

    /**
     * Round timestamp up to nearest slot boundary
     */
    private function roundToSlot(int $timestamp): int
    {
        $slot = $this->slotMinutes * 60;
        return ceil($timestamp / $slot) * $slot;
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
        $root = dirname(__DIR__, 2);

        $dir = $root . '/storage/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . '/scheduler.log';

        $time = date('Y-m-d H:i:s');

        @file_put_contents($file, "[$time] " . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}