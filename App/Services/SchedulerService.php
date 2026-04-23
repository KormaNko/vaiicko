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


    private int $firstDayBufferMinutes = 120;

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
        $toClear = Task::getAll(
            "user_id = ? AND is_dynamic = 1 AND is_schedule_block = 0 AND status != 'completed'",
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
            "user_id = ? AND planned_start IS NOT NULL AND is_schedule_block = 0 AND status != 'completed'",
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
            "user_id = ? AND is_dynamic = 1 AND is_schedule_block = 0 AND planned_start IS NULL AND status != 'completed'",
            [$userId],
            "deadline ASC, priority DESC"
        );

        if (!is_array($tasks)) {
            return;
        }

        $currentDate = date('Y-m-d');

        foreach ($tasks as $task) {
            $minutes = $task->getTimeToComplete();

            if ($minutes === null || $minutes <= 0) {
                continue;
            }

            $slotsNeeded = (int) ceil($minutes / $this->slotMinutes);
            $requiredSeconds = $slotsNeeded * $this->slotMinutes * 60;


            $currentDate = date('Y-m-d');


            $maxLookaheadDays = 30;
            $searchUntilDate = date('Y-m-d', strtotime("+$maxLookaheadDays days"));

            $scheduled = false;


            while (strtotime($currentDate) <= strtotime($searchUntilDate)) {
                $workDayStart = strtotime($this->combine($currentDate, $this->workStart));
                $workDayEnd = strtotime($this->combine($currentDate, $this->workEnd));


                if ($currentDate === date('Y-m-d')) {
                    $now = time();
                    $buffered = $now + ($this->firstDayBufferMinutes * 60);
                    // log the buffering decision for debugging
                    $this->log("Today buffer applied: now=" . date('Y-m-d H:i:s', $now) . ", buffered=" . date('Y-m-d H:i:s', $buffered) . ", workDayStart=" . date('Y-m-d H:i:s', $workDayStart) . ", workDayEnd=" . date('Y-m-d H:i:s', $workDayEnd));

                    if ($buffered >= $workDayEnd) {
                        $this->log("Buffered time after workDayEnd, skipping today: buffered=" . date('Y-m-d H:i:s', $buffered) . ", workDayEnd=" . date('Y-m-d H:i:s', $workDayEnd));
                        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                        continue;
                    }

                    $workDayStart = max($workDayStart, $buffered);
                }

                $categoryWindowStart = null;
                $categoryWindowEnd = null;

                if ($task->getCategoryId() !== null) {
                    try {
                        $cat = Category::getOne($task->getCategoryId());
                        if ($cat && $cat->getPlanFrom() !== null && $cat->getPlanTo() !== null) {
                            $pf = $cat->getPlanFrom();
                            $pt = $cat->getPlanTo();

                            $tsPf = strtotime($this->combine($currentDate, $pf));
                            $tsPt = strtotime($this->combine($currentDate, $pt));

                            if ($tsPf !== false && $tsPt !== false && $tsPt > $tsPf) {
                                $categoryWindowStart = max($workDayStart, $tsPf);
                                $categoryWindowEnd = min($workDayEnd, $tsPt);

                                if ($categoryWindowEnd <= $categoryWindowStart) {
                                    $categoryWindowStart = null;
                                    $categoryWindowEnd = null;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $categoryWindowStart = null;
                        $categoryWindowEnd = null;
                    }
                }

                $rangesToTry = [];

                if ($categoryWindowStart !== null && $categoryWindowEnd !== null) {
                    $rangesToTry[] = [
                        'start' => $categoryWindowStart,
                        'end' => $categoryWindowEnd
                    ];
                } else {
                    $rangesToTry[] = [
                        'start' => $workDayStart,
                        'end' => $workDayEnd
                    ];
                }

                foreach ($rangesToTry as $range) {
                    $rangeStart = $range['start'];
                    $rangeEnd = $range['end'];

                    $dayBlocks = [];
                    foreach ($occupied as $block) {
                        $s = max($block['start'], $rangeStart);
                        $e = min($block['end'], $rangeEnd);

                        if ($e > $s) {
                            $dayBlocks[] = [
                                'start' => $s,
                                'end' => $e
                            ];
                        }
                    }

                    usort($dayBlocks, fn($a, $b) => $a['start'] <=> $b['start']);

                    $gaps = [];
                    $cursor = $rangeStart;

                    foreach ($dayBlocks as $block) {
                        if ($block['start'] > $cursor) {
                            $gaps[] = [
                                'start' => $cursor,
                                'end' => $block['start']
                            ];
                        }

                        $cursor = max($cursor, $block['end']);
                    }

                    if ($cursor < $rangeEnd) {
                        $gaps[] = [
                            'start' => $cursor,
                            'end' => $rangeEnd
                        ];
                    }

                    foreach ($gaps as $gap) {
                        $taskStart = $this->roundToSlot($gap['start']);

                        if ($taskStart >= $gap['end']) {
                            continue;
                        }

                        $taskEnd = $taskStart + $requiredSeconds;

                        if ($taskEnd <= $gap['end']) {
                            $task->setPlannedStart(date('Y-m-d H:i:s', $taskStart));
                            $task->setPlannedEnd(date('Y-m-d H:i:s', $taskEnd));

                            try {
                                $task->save();
                            } catch (\Exception $e) {
                                $this->log("Failed scheduling task " . $task->getId());
                            }

                            // log the scheduled allocation
                            $this->log("Scheduled task " . $task->getId() . " for user=" . $task->getUserId() . " start=" . date('Y-m-d H:i:s', $taskStart) . " end=" . date('Y-m-d H:i:s', $taskEnd));

                            $occupied[] = [
                                'start' => $taskStart,
                                'end' => $taskEnd
                            ];

                            usort($occupied, fn($a, $b) => $a['start'] <=> $b['start']);

                            $scheduled = true;
                            break 2;
                        }
                    }
                }

                if ($scheduled) {
                    break;
                }

                $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
            }

            if (!$scheduled) {
                if ($task->getCategoryId() !== null) {
                    try {
                        $cat = Category::getOne($task->getCategoryId());
                        if ($cat && $cat->getPlanFrom() !== null && $cat->getPlanTo() !== null) {
                            $this->log("Could not schedule task " . $task->getId() . " within category window up to " . $searchUntilDate);
                            // ensure planned times remain null (they already are), and move on
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // ignore and continue
                    }
                }

                $this->log("Could not schedule task " . $task->getId() . " up to " . $searchUntilDate);
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


        if ($task->getParentId() !== null) {
            $this->log("Skipping split: task is already a child (parent_id set)");
            return;
        }

        // allow tasks without a category — we'll fall back to a 60-minute max chunk below

        if ($task->getAtomicTask() === 1) {
            $this->log("Skipping split: task is atomic");
            return;
        }

        $totalMinutes = $task->getTimeToComplete();
        if ($totalMinutes === null || $totalMinutes <= 0) {
            $this->log("Skipping split: invalid or missing timeToComplete (" . var_export($totalMinutes, true) . ")");
            return;
        }


        $category = null;
        $max = null;

        if ($task->getCategoryId() !== null) {
            try {
                $category = Category::getOne($task->getCategoryId());
            } catch (\Throwable $e) {
                $category = null;
            }
        }

        if ($category) {
            $max = $category->getMaxDuration();
            if ($max === null || $max <= 0) {
                $this->log("Category maxDuration invalid (" . var_export($max, true) . ") — using fallback 60 minutes");
                $max = 60;
            }
        } else {
            $this->log("No category or category not found for task — using default maxDuration 60 minutes");
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

        $fullParts = intdiv($totalMinutes, $max);
        $remainder = $totalMinutes % $max;

        $chunks = [];
        for ($i = 0; $i < $fullParts; $i++) {
            $chunks[] = $max;
        }
        if ($remainder > 0) {
            $chunks[] = $remainder;
        }

        $index = 1;
        foreach ($chunks as $chunkMinutes) {
            $child = new Task();
            $child->setTitle($task->getTitle() . ' - part ' . $index);
            $child->setDescription($task->getDescription());
            $child->setStatus($task->getStatus());
            $child->setPriority($task->getPriority());

            $child->setUserId($task->getUserId());
            $child->setDeadline($task->getDeadline());
            $child->setCategoryId($task->getCategoryId());
            $child->setParentId($parentId);
            $child->setTimeToComplete($chunkMinutes);
            $child->setAtomicTask(1);
            $child->setIsDynamic($task->getIsDynamic());
            $child->setPlannedStart(null);
            $child->setPlannedEnd(null);
            $child->setIsScheduleBlock(0);
            $now = date('Y-m-d H:i:s');
            $child->setCreatedAt($now);
            $child->setUpdatedAt($now);

            try {
                $child->save();
            } catch (\Exception $e) {
                $this->log("Failed creating child task for parent={$parentId}: " . $e->getMessage());
            }

            $index++;
        }

        $task->setIsScheduleBlock(1);
        $task->setTimeToComplete(null);
        try {
            $task->save();
        } catch (\Exception $e) {
            $this->log("Failed marking parent task as schedule block id={$parentId}: " . $e->getMessage());
        }
    }
}
