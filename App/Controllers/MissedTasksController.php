<?php

namespace App\Controllers;

use App\Models\Task;
use App\Services\SchedulerService;
use App\Enums\TaskStatus;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use InvalidArgumentException;

/**
 * Controller for managing tasks whose planned window already passed (missed tasks).
 *
 * Routes expected (you can wire them in router):
 * - GET /missed-tasks?before=... -> list missed tasks for current user (optional `before` overrides 'now')
 * - POST /missed-tasks/{id}/complete -> mark missed task as completed (deletes task)
 * - POST /missed-tasks/{id}/not-complete -> mark missed task as not completed (re-enable and reschedule)
 */
class MissedTasksController extends AppController
{
    // authorize same as other controllers
    public function authorize(Request $request, string $action): bool
    {
        return $this->user->isLoggedIn();
    }

    private function sendCorsAndPreflight(Request $request)
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }
        return null;
    }

    /**
     * List missed tasks whose planned_end (or plannedStart/End range) is fully in the past.
     * Query params:
     * - before (optional) - ISO datetime to consider as 'now' for the check
     */
    public function index(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        // For now we only list missed tasks for the current user. If you need admin cross-user access,
        // implement an isAdmin method on your identity and add a check here.
        $userId = $this->user->getIdentity()->getId();

        $nowRaw = $request->value('before') ?? date('Y-m-d H:i:s');
        $ts = strtotime($nowRaw);
        if ($ts === false) {
            return $this->json(['error' => 'Invalid before datetime'], 400);
        }
        $now = date('Y-m-d H:i:s', $ts);

        // We consider a task missed if planned_end is not null and planned_end <= $now
        $tasks = Task::getAll(
            '(user_id = ?) AND (planned_end IS NOT NULL AND planned_end <= ?) AND (status != ?)',
            [$userId, $now, TaskStatus::COMPLETED],
            'planned_end ASC'
        );

        return $this->json($tasks);
    }

    /**
     * Mark a missed task as completed => set status to completed (do not delete)
     * Expects route param id or body param id
     */
    public function complete(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->value('id');
        $task = Task::getOne($id);
        if (!$task || $task->getUserId() != $this->user->getIdentity()->getId()) {
            return $this->json(['error' => 'Task not found or access denied'], 404);
        }

        // Mark the task as completed (do not delete the record)
        try {
            try {
                $task->setStatus(TaskStatus::COMPLETED);
            } catch (InvalidArgumentException $e) {
                // fallback to raw string if enum validation fails for some reason
                $task->setStatus('completed');
            }
            $task->setUpdatedAt(date('Y-m-d H:i:s'));
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to mark task as completed', 'message' => $e->getMessage()], 500);
        }

        // trigger reschedule for user after status change
        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {
            // do not fail the request on scheduler errors
        }

        return $this->json(['message' => 'Task marked as completed']);
    }

    /**
     * Mark a missed task as not completed => re-enable and trigger scheduler
     * This will set status back to pending and clear planned times so scheduler can reassign
     */
    public function notComplete(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->value('id');
        $task = Task::getOne($id);
        if (!$task || $task->getUserId() != $this->user->getIdentity()->getId()) {
            return $this->json(['error' => 'Task not found or access denied'], 404);
        }

        // reset planned fields so scheduler treats it as unscheduled
        try {
            $task->setPlannedStart(null);
            $task->setPlannedEnd(null);
            // set status back to pending
            try {
                $task->setStatus(TaskStatus::PENDING);
            } catch (InvalidArgumentException $e) {
                // fallback
                $task->setStatus('pending');
            }
            $task->setUpdatedAt(date('Y-m-d H:i:s'));
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to update task', 'message' => $e->getMessage()], 500);
        }

        // trigger reschedule for user
        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {
            // ignore
        }

        return $this->json(['message' => 'Task re-enabled and scheduler triggered', 'task' => $task]);
    }
}
