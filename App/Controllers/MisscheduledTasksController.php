<?php

namespace App\Controllers;

use App\Models\Task;
use App\Enums\TaskStatus;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

/**
 * Controller for tasks that were scheduled after their deadline (planned window entirely after deadline).
 *
 * Currently implements only index() which lists such tasks for the current user.
 *
 * Assumption: a task is considered "misscheduled after deadline" when both planned_start and planned_end
 * are NOT NULL and both are strictly greater than the task's deadline (deadline NOT NULL).
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
     * List tasks where planned_start and planned_end are both after the task's deadline.
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
        // - planned_start IS NOT NULL AND planned_end IS NOT NULL
        // - planned_start > deadline AND planned_end > deadline
        // - exclude already completed tasks
        $tasks = Task::getAll(
            '(user_id = ?) AND (deadline IS NOT NULL) AND (planned_start IS NOT NULL AND planned_end IS NOT NULL) AND (planned_start > deadline AND planned_end > deadline) AND (status != ?)',
            [$userId, TaskStatus::COMPLETED],
            'planned_start ASC'
        );

        return $this->json($tasks);
    }
}

