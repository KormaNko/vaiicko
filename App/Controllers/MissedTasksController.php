<?php

namespace App\Controllers;

use App\Models\Task;
use App\Services\SchedulerService;
use App\Enums\TaskStatus;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use InvalidArgumentException;


class MissedTasksController extends AppController
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

        $nowRaw = $request->value('before') ?? date('Y-m-d H:i:s');
        $ts = strtotime($nowRaw);
        if ($ts === false) {
            return $this->json(['error' => 'Invalid before datetime'], 400);
        }
        $now = date('Y-m-d H:i:s', $ts);

        $tasks = Task::getAll(
            '(user_id = ?) AND (planned_end IS NOT NULL AND planned_end <= ?) AND (is_schedule_block = 0) AND (status != ?)',
            [$userId, $now, TaskStatus::COMPLETED],
            'planned_end ASC'
        );

        return $this->json($tasks);
    }


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

        try {
            try {
                $task->setStatus(TaskStatus::COMPLETED);
            } catch (InvalidArgumentException $e) {
                $task->setStatus('completed');
            }
            $task->setUpdatedAt(date('Y-m-d H:i:s'));
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to mark task as completed', 'message' => $e->getMessage()], 500);
        }


        return $this->json(['message' => 'Task marked as completed']);
    }


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

        // If the task is not dynamic, don't change its scheduling — nothing to do here
        if ((int)$task->getIsDynamic() === 0) {
            return $this->json(['message' => 'Task is not dynamic; no action taken', 'task' => $task]);
        }

        try {
            $task->setPlannedStart(null);
            $task->setPlannedEnd(null);
            try {
                $task->setStatus(TaskStatus::PENDING);
            } catch (InvalidArgumentException $e) {

                $task->setStatus('pending');
            }
            $task->setUpdatedAt(date('Y-m-d H:i:s'));
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to update task', 'message' => $e->getMessage()], 500);
        }

        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {

        }

        return $this->json(['message' => 'Task re-enabled and scheduler triggered', 'task' => $task]);
    }
}
