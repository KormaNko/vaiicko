<?php

namespace App\Controllers;

use App\Models\Task;
use App\Enums\TaskStatus;
use App\Services\SchedulerService;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

/**
 * Controller for tasks that were scheduled after their deadline (planned window entirely after deadline).
 *
 * Previously this required both planned_start and planned_end to be strictly after the deadline.
 * Change: consider a task misscheduled after deadline when its planned_end is after the deadline
 * (planned_end IS NOT NULL AND planned_end > deadline). This covers cases where only the end
 * slips past the deadline (e.g. planned_end 16:00, deadline 15:30).
 */
class MisscheduledTasksController extends AppController
{
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
     * List tasks where planned_end is after the task's deadline.
     * Query params: none for now (we list for current user only).
     */
    public function index(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();

        // We treat a task as misscheduled after deadline when:
        // - deadline IS NOT NULL
        // - planned_end IS NOT NULL AND planned_end > deadline
        // - exclude already completed tasks
        // - ignore explicit schedule blocks
        $tasks = Task::getAll(
            '(user_id = ?) AND (deadline IS NOT NULL) AND (planned_end IS NOT NULL) AND (planned_end > deadline) AND (is_schedule_block = 0) AND (status != ?)',
            [$userId, TaskStatus::COMPLETED],
            'planned_start ASC'
        );

        return $this->json($tasks);
    }

    /**
     * Remove category from a task and trigger scheduler recalculation for that user.
     * Expects JSON body with { "task_id": <int> }.
     */
    public function removeCategory(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        // Accept JSON body or form/query param. Be tolerant to different client implementations.
        $taskId = null;

        try {
            $data = $request->json();
            // Request::json() may return an associative array or stdClass depending on decoding.
            if (is_array($data) && isset($data['task_id'])) {
                $taskId = (int)$data['task_id'];
            } elseif (is_object($data)) {
                // accept { task_id: 1 } or { id: 1 }
                if (isset($data->task_id)) {
                    $taskId = (int)$data->task_id;
                } elseif (isset($data->id)) {
                    $taskId = (int)$data->id;
                }
            }
        } catch (\Throwable $e) {
            // ignore JSON parse errors and fall back to request values
        }

        if ($taskId === null) {
            $val = $request->value('task_id') ?? $request->value('id');
            if ($val !== null && $val !== '') {
                $taskId = (int)$val;
            }
        }

        if ($taskId === null) {
            return $this->json(['error' => 'task_id is required'], 400);
        }

        $task = Task::getOne($taskId);
        if (!$task) {
            return $this->json(['error' => 'Task not found'], 404);
        }

        // Ensure current user owns the task (or is admin) - keep simple: owner only
        $currentUserId = $this->user->getIdentity()->getId();
        if ($task->getUserId() !== $currentUserId) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        // unset the category and save
        $task->setCategoryId(null);

        try {
            $task->save();
            // Ensure DB-level clearing in case another process overwrites via stale model: run direct UPDATE
            // explicit table/primary-key names to avoid calling protected Model methods from controller
            Task::executeRawSQL('UPDATE `tasks` SET category_id = NULL WHERE `id` = ?', [$taskId]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed saving task'], 500);
        }

        // reload and verify category is cleared to avoid concurrent overwrites from other callers
        $reloaded = Task::getOne($taskId);
        if (!$reloaded || $reloaded->getCategoryId() !== null) {
            // If category wasn't cleared, report failure so client can retry/inspect
            return $this->json(['error' => 'Failed clearing category'], 500);
        }

        // Recalculate schedule for the user
        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($currentUserId);
        } catch (\Throwable $e) {
            // don't log anything here per request; report scheduler failure without internal message
            return $this->json(['status' => 'ok', 'scheduler' => 'failed']);
        }

        return $this->json(['status' => 'ok']);
    }
}
