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


            $useWindowFallback = true;
            while ($slotsNeeded > 0) {


                $workDayStart = strtotime($this->combine($currentDate, $this->workStart));
                $workDayEnd = strtotime($this->combine($currentDate, $this->workEnd));


                $dayStart = $workDayStart;
                $dayEnd = $workDayEnd;


                $categoryWindowStart = null;
                $categoryWindowEnd = null;
                if ($task->getCategoryId() !== null && $useWindowFallback) {
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

                if ($categoryWindowStart !== null && $categoryWindowEnd !== null) {
                    $dayStart = $categoryWindowStart;
                    $dayEnd = $categoryWindowEnd;
                }

                $now = strtotime($this->combine($currentDate, $currentTime));

                $now = $this->roundToSlot($now);


                if (isset($categoryWindowStart) && $categoryWindowStart !== null && $now < $categoryWindowStart) {
                    $now = $categoryWindowStart;
                }

                if ($now >= $dayEnd) {

                    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                    $currentTime = $this->workStart;
                    //pre pripad ak sme skusali pouzit category window, ale tento den uz nebol vhodny, tak nech to skusi znova na dalsom dni, ale bez okna
                    if ($categoryWindowStart !== null && $useWindowFallback) {
                        $useWindowFallback = false;
                    }
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
                        if ($block['end'] >= $dayEnd) {

                            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                            $currentTime = $this->workStart;

                            if ($categoryWindowStart !== null && $useWindowFallback) {
                                $useWindowFallback = false;
                            }
                            $collision = true;
                            break;
                        }

                        //if ($taskStart < $block['end'] && $taskEnd > $block['start']) {
                        //    if ($categoryWindowStart !== null && $useWindowFallback) {
                        //        $useWindowFallback = false; // okamžite vypni window, skúšaj celý deň
                        //    }
                        //
                        //    // posuň currentTime na koniec kolidujúceho bloku
                        //    $currentTime = date('H:i:s', $block['end']);
                        //    $collision = true;
                        //    break;
                        //}
                        //
                        //// potom klasicky: ak currentTime >= dayEnd, presuň deň
                        //if (strtotime($currentTime) >= $dayEnd) {
                        //    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                        //    $currentTime = $this->workStart;
                        //}

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
            $child->setAtomicTask(0);
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