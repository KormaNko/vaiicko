<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Category;
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

    public function splitTaskByCategory(Task $task): void
    {
        // Debug log start
        $this->log("splitTaskByCategory invoked for task=" . $task->getId() . " parentId=" . ($task->getParentId() ?? 'null') . " categoryId=" . ($task->getCategoryId() ?? 'null') . " timeToComplete=" . ($task->getTimeToComplete() ?? 'null') . " atomic=" . $task->getAtomicTask());

        if ($task->getParentId() !== null) {
            $this->log("Skipping split: task is already a child (parent_id set)");
            return;
        }

        if ($task->getCategoryId() === null) {
            $this->log("Skipping split: task has no category");
            return;
        }

        if ($task->getAtomicTask() === 1) {
            $this->log("Skipping split: task is atomic");
            return;
        }

        $totalMinutes = $task->getTimeToComplete();
        if ($totalMinutes === null || $totalMinutes <= 0) {
            $this->log("Skipping split: invalid or missing timeToComplete (" . var_export($totalMinutes, true) . ")");
            return;
        }


        $category = Category::getOne($task->getCategoryId());
        if (!$category) {
            $this->log("Skipping split: category record not found id=" . $task->getCategoryId());
            return;
        }

        $max = $category->getMaxDuration();
        if ($max === null || $max <= 0) {
            // Fallback: if DB doesn't have max_duration column or value, use sensible default
            $this->log("Category maxDuration invalid (" . var_export($max, true) . ") — using fallback 60 minutes");
            $max = 60;
        }

        if ($max >= $totalMinutes) {
            $this->log("Skipping split: category maxDuration (" . $max . ") >= totalMinutes (" . $totalMinutes . ")");
            return;
        }


        try {
            $task->save();
        } catch (\Exception $e) {
            $this->log("Failed saving parent task before split: " . $task->getId());
            return;
        }

        $parentId = $task->getId();

        // Build chunks (max-sized parts, last one may be smaller)
        $fullParts = intdiv($totalMinutes, $max);
        $remainder = $totalMinutes % $max;

        $chunks = [];
        for ($i = 0; $i < $fullParts; $i++) {
            $chunks[] = $max;
        }
        if ($remainder > 0) {
            $chunks[] = $remainder;
        }

        // Create child tasks
        $index = 1;
        foreach ($chunks as $chunkMinutes) {
            $child = new Task();
            // Copy basic attributes
            $child->setTitle($task->getTitle() . ' - part ' . $index);
            $child->setDescription($task->getDescription());
            $child->setStatus($task->getStatus());
            $child->setPriority($task->getPriority());
            // Assign child tasks to the same user as the parent so the owner keeps their parts
            // (the project doesn't use team members in this setup).
            $child->setUserId($task->getUserId());
            $child->setDeadline($task->getDeadline());
            $child->setCategoryId($task->getCategoryId());
            $child->setParentId($parentId);
            $child->setTimeToComplete($chunkMinutes);
            // children are not atomic by default
            $child->setAtomicTask(0);
            // preserve dynamic flag
            $child->setIsDynamic($task->getIsDynamic());
            $child->setPlannedStart(null);
            $child->setPlannedEnd(null);
            $child->setIsScheduleBlock(0);
            // Ensure created_at/updated_at are set (DB columns are NOT NULL) to avoid '' datetime insertion
            $now = date('Y-m-d H:i:s');
            $child->setCreatedAt($now);
            $child->setUpdatedAt($now);

            try {
                $child->save();
            } catch (\Exception $e) {
                $this->log("Failed creating child task for parent={$parentId}: " . $e->getMessage());
                // continue trying to create other parts
            }

            $index++;
        }

        // Mark original as abstract schedule block and clear its time to avoid duplication
        $task->setIsScheduleBlock(1);
        $task->setTimeToComplete(null);
        try {
            $task->save();
        } catch (\Exception $e) {
            $this->log("Failed marking parent task as schedule block id={$parentId}: " . $e->getMessage());
        }
    }
}