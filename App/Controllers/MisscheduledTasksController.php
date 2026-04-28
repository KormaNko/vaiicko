<?php

namespace App\Controllers;

use App\Models\Task;
use App\Enums\TaskStatus;
use App\Services\SchedulerService;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;


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


    public function index(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();

        $tasks = Task::getAll(
            '(user_id = ?) AND (deadline IS NOT NULL) AND (planned_end IS NOT NULL) AND (planned_end > deadline) AND (is_schedule_block = 0) AND (status != ?)',
            [$userId, TaskStatus::COMPLETED],
            'planned_start ASC'
        );

        return $this->json($tasks);
    }


    public function removeCategory(Request $request): Response
    {
        $pre = $this->sendCorsAndPreflight($request);
        if ($pre) return $pre;

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $taskId = null;

        try {
            $data = $request->json();
            if (is_array($data) && isset($data['task_id'])) {
                $taskId = (int)$data['task_id'];
            } elseif (is_object($data)) {
                if (isset($data->task_id)) {
                    $taskId = (int)$data->task_id;
                } elseif (isset($data->id)) {
                    $taskId = (int)$data->id;
                }
            }
        } catch (\Throwable $e) {
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

        $task->setCategoryId(null);

        try {
            $task->save();

            Task::executeRawSQL('UPDATE `tasks` SET category_id = NULL WHERE `id` = ?', [$taskId]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed saving task'], 500);
        }

        $reloaded = Task::getOne($taskId);
        if (!$reloaded || $reloaded->getCategoryId() !== null) {
            return $this->json(['error' => 'Failed clearing category'], 500);
        }

        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($currentUserId);
        } catch (\Throwable $e) {
            return $this->json(['status' => 'ok', 'scheduler' => 'failed']);
        }

        return $this->json(['status' => 'ok']);
    }
}
