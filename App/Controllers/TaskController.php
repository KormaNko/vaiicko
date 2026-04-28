<?php

namespace App\Controllers;

use App\Models\Task;
use App\Models\Category as CategoryModel;
use App\Enums\TaskStatus;
use Exception;
use InvalidArgumentException;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use App\Services\SchedulerService;

class TaskController extends AppController
{
    /**
     * Authorizes actions for the controller.
     *
     * Only logged-in users can access task operations.
     *
     * @param Request $request The incoming request.
     * @param string $action The action being performed.
     * @return bool True if authorized; false otherwise.
     */
    public function authorize(Request $request, string $action): bool
    {
        return $this->user->isLoggedIn();
    }

    /**
     * Lists all tasks for the current user.
     *
     * @param Request $request The incoming request.
     * @return JsonResponse The JSON response containing the list of tasks.
     */
    public function index(Request $request): Response
    {
        // CORS and preflight
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

       //musi byt admin
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();
        // do not return abstract schedule-block parent tasks
        $tasks = Task::getAll('user_id = ? AND is_schedule_block = 0', [$userId], 'created_at DESC');
        return $this->json($tasks);
    }

    /**
     * Creates a new task for the current user.
     *
     * @param Request $request The incoming request containing task data.
     * @return JsonResponse The JSON response containing the created task.
     * @throws Exception If task creation fails.
     */
    public function create(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        // Read values from form-encoded request (frontend sends application/x-www-form-urlencoded)
        $title = $request->value('title');
        if ($title === null || trim((string)$title) === '') {
            return $this->json(['error' => 'Title is required'], 400);
        }

        $task = new Task();
        $task->setTitle((string)$title);
        $task->setDescription($request->value('description') ?? null);

        $statusVal = $request->value('status') ?? null;
        try {
            $task->setStatus($statusVal ?? TaskStatus::PENDING);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid status value'], 400);
        }

        $task->setPriority((int)($request->value('priority') ?? 2));
        $task->setUserId($this->user->getIdentity()->getId());

        // deadline: empty string clears, otherwise parse
        $deadlineRaw = $request->value('deadline');
        if ($deadlineRaw === '' || $deadlineRaw === null) {
            $task->setDeadline(null);
        } else {
            $ts = strtotime($deadlineRaw);
            if ($ts === false) {
                return $this->json(['error' => 'Invalid deadline format'], 400);
            }
            $task->setDeadline(date('Y-m-d H:i:s', $ts));
        }

        // category_id (snake_case only)
        $catIdRaw = $request->value('category_id');
        if ($catIdRaw === null || $catIdRaw === '') {
            $task->setCategoryId(null);
        } else {
            if (!is_numeric($catIdRaw)) {
                return $this->json(['error' => 'Invalid category_id, must be integer or null'], 400);
            }
            $catId = (int)$catIdRaw;
            $category = CategoryModel::getOne($catId);
            if (!$category || $category->getUserId() !== $this->user->getIdentity()->getId()) {
                return $this->json(['error' => 'Invalid category'], 400);
            }
            $task->setCategoryId($catId);
        }

        // parent_id (snake_case only)
        $parentRaw = $request->value('parent_id');
        if ($parentRaw === '' || $parentRaw === null) {
            $task->setParentId(null);
        } else {
            if (!is_numeric($parentRaw)) {
                return $this->json(['error' => 'Invalid parent_id, must be integer or null'], 400);
            }
            $parentInt = (int)$parentRaw;
            if ($parentInt === 0) {
                $task->setParentId(null);
            } else {
                $task->setParentId($parentInt);
            }
        }

        // time_to_complete (snake_case only)
        $timeRaw = $request->value('time_to_complete');
        if ($timeRaw === '' || $timeRaw === null) {
            $task->setTimeToComplete(null);
        } else {
            if (!is_numeric($timeRaw)) {
                return $this->json(['error' => 'Invalid time_to_complete, must be integer (minutes) or null'], 400);
            }
            $timeInt = (int)$timeRaw;
            if ($timeInt < 0) {
                return $this->json(['error' => 'Invalid time_to_complete, must be non-negative'], 400);
            }
            $task->setTimeToComplete($timeInt);
        }

        // atomic_task (snake_case only)
        $atomicRaw = $request->value('atomic_task');
        if ($atomicRaw === null || $atomicRaw === '') {
            $task->setAtomicTask(0);
        } else {
            if ($atomicRaw === true || $atomicRaw === '1' || $atomicRaw === 1) {
                $task->setAtomicTask(1);
            } elseif ($atomicRaw === false || $atomicRaw === '0' || $atomicRaw === 0) {
                $task->setAtomicTask(0);
            } else {
                return $this->json(['error' => 'Invalid atomic_task value, must be 0 or 1'], 400);
            }
        }

        // is_dynamic (snake_case only)
        $dynamicRaw = $request->value('is_dynamic');
        if ($dynamicRaw === null || $dynamicRaw === '') {
            $task->setIsDynamic(0);
        } else {
            if ($dynamicRaw === true || $dynamicRaw === '1' || $dynamicRaw === 1) {
                $task->setIsDynamic(1);
            } elseif ($dynamicRaw === false || $dynamicRaw === '0' || $dynamicRaw === 0) {
                $task->setIsDynamic(0);
            } else {
                return $this->json(['error' => 'Invalid is_dynamic value, must be 0 or 1'], 400);
            }
        }

        // planned_start / planned_end (snake_case only); empty string clears
        $plannedStartRaw = $request->value('planned_start');
        if ($plannedStartRaw === '' ) {
            $task->setPlannedStart(null);
        } elseif ($plannedStartRaw !== null) {
            try {
                $task->setPlannedStart($plannedStartRaw);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid planned_start format'], 400);
            }
        }

        $plannedEndRaw = $request->value('planned_end');
        if ($plannedEndRaw === '' ) {
            $task->setPlannedEnd(null);
        } elseif ($plannedEndRaw !== null) {
            try {
                $task->setPlannedEnd($plannedEndRaw);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid planned_end format'], 400);
            }
        }

        $task->setCreatedAt(date('Y-m-d H:i:s'));
        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        if ($task->getIsDynamic() === 1 && $task->getTimeToComplete() === null) {
            $task->setTimeToComplete(30);
        }

        try {
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to save task', 'message' => $e->getMessage()], 500);
        }

        // trigger scheduler (best-effort)
        try {
            $root = dirname(__DIR__, 2);
            $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {@mkdir($logDir, 0777, true);}
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'scheduler.log';
            @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Controller triggering scheduler for user=' . $this->user->getIdentity()->getId() . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}

        try {
            $scheduler = new SchedulerService();
            try { $scheduler->splitTaskByCategory($task); } catch (\Throwable $e) { try { @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] splitTaskByCategory failed for task=' . $task->getId() . ' message=' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX); } catch (\Throwable $__e) {} }
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {}

        return $this->json($task);
    }

  //klasika update kategorii
    public function update(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->value('id');
        $task = Task::getOne($id);

        if (!$task || $task->getUserId() != $this->user->getIdentity()->getId()) {
            return $this->json(['error' => 'Task not found or access denied'], 404);
        }

        // update only using snake_case form fields
        if ($request->hasValue('title')) {
            $task->setTitle($request->value('title'));
        }
        if ($request->hasValue('description')) {
            $task->setDescription($request->value('description'));
        }
        if ($request->hasValue('status')) {
            $statusVal = $request->value('status');
            try { $task->setStatus($statusVal); } catch (InvalidArgumentException $e) { return $this->json(['error' => 'Invalid status value'], 400); }
        }
        if ($request->hasValue('priority')) {
            $task->setPriority((int)$request->value('priority'));
        }

        if ($request->hasValue('deadline')) {
            $deadlineRaw = $request->value('deadline');
            if ($deadlineRaw === '') {
                $task->setDeadline(null);
            } else {
                $ts = strtotime($deadlineRaw);
                if ($ts === false) return $this->json(['error' => 'Invalid deadline format'], 400);
                $task->setDeadline(date('Y-m-d H:i:s', $ts));
            }
        }

        if ($request->hasValue('parent_id')) {
            $parentRaw = $request->value('parent_id');
            if ($parentRaw === '' || $parentRaw === null) {
                $task->setParentId(null);
            } else {
                if (!is_numeric($parentRaw)) return $this->json(['error' => 'Invalid parent_id, must be integer or null'], 400);
                $parentInt = (int)$parentRaw;
                if ($parentInt === 0) $task->setParentId(null); else $task->setParentId($parentInt);
            }
        }

        if ($request->hasValue('time_to_complete')) {
            $timeRaw = $request->value('time_to_complete');
            if ($timeRaw === '' || $timeRaw === null) {
                $task->setTimeToComplete(null);
            } else {
                if (!is_numeric($timeRaw)) return $this->json(['error' => 'Invalid time_to_complete, must be integer or null'], 400);
                $timeInt = (int)$timeRaw; if ($timeInt < 0) return $this->json(['error' => 'Invalid time_to_complete, must be non-negative'], 400);
                $task->setTimeToComplete($timeInt);
            }
        }

        if ($request->hasValue('atomic_task')) {
            $atomicRaw = $request->value('atomic_task');
            if ($atomicRaw === '' || $atomicRaw === null) {
                $task->setAtomicTask(0);
            } else {
                if ($atomicRaw === true || $atomicRaw === '1' || $atomicRaw === 1) $task->setAtomicTask(1);
                elseif ($atomicRaw === false || $atomicRaw === '0' || $atomicRaw === 0) $task->setAtomicTask(0);
                else return $this->json(['error' => 'Invalid atomic_task value, must be 0 or 1'], 400);
            }
        }

        if ($request->hasValue('is_dynamic')) {
            $dynamicRaw = $request->value('is_dynamic');
            if ($dynamicRaw === '' || $dynamicRaw === null) {
                $task->setIsDynamic(0);
            } else {
                if ($dynamicRaw === true || $dynamicRaw === '1' || $dynamicRaw === 1) $task->setIsDynamic(1);
                elseif ($dynamicRaw === false || $dynamicRaw === '0' || $dynamicRaw === 0) $task->setIsDynamic(0);
                else return $this->json(['error' => 'Invalid is_dynamic value, must be 0 or 1'], 400);
            }
        }

        if ($request->hasValue('planned_start')) {
            $plannedStartRaw = $request->value('planned_start');
            if ($plannedStartRaw === '') {
                $task->setPlannedStart(null);
            } elseif ($plannedStartRaw === null) {
                $task->setPlannedStart(null);
            } else {
                try { $task->setPlannedStart($plannedStartRaw); } catch (InvalidArgumentException $e) { return $this->json(['error' => 'Invalid planned_start format'], 400); }
            }
        }

        if ($request->hasValue('planned_end')) {
            $plannedEndRaw = $request->value('planned_end');
            if ($plannedEndRaw === '' || $plannedEndRaw === null) {
                $task->setPlannedEnd(null);
            } else {
                try { $task->setPlannedEnd($plannedEndRaw); } catch (InvalidArgumentException $e) { return $this->json(['error' => 'Invalid planned_end format'], 400); }
            }
        }

        if ($request->hasValue('category_id')) {
            $catIdRaw = $request->value('category_id');
            if ($catIdRaw === '' || $catIdRaw === null) {
                $task->setCategoryId(null);
            } else {
                if (!is_numeric($catIdRaw)) return $this->json(['error' => 'Invalid category_id, must be integer or null'], 400);
                $catId = (int)$catIdRaw;
                $category = CategoryModel::getOne($catId);
                if (!$category || $category->getUserId() !== $this->user->getIdentity()->getId()) return $this->json(['error' => 'Invalid category'], 400);
                $task->setCategoryId($catId);
            }
        }

        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        if ($task->getIsDynamic() === 1 && $task->getTimeToComplete() === null) {
            $task->setTimeToComplete(30);
        }

        try {
            $root = dirname(__DIR__, 2);
            $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {@mkdir($logDir, 0777, true);}
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'scheduler.log';
            @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Controller (update) triggering scheduler for user=' . $this->user->getIdentity()->getId() . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}

        $task->save();

        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {}

        return $this->json($task);
    }

    public function delete(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->value('id');
        $task = Task::getOne($id);

        if (!$task || $task->getUserId() != $this->user->getIdentity()->getId()) {
            return $this->json(['error' => 'Task not found or access denied'], 404);
        }

        $task->delete();

        // trigger rescheduling for this user (do not fail the request if scheduler errors)
        try {
           $scheduler = new SchedulerService();
        $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {
            // ignore scheduler errors
        }

        return $this->json(['message' => 'Task deleted successfully']);
    }
}
