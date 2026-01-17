<?php

namespace App\Controllers;

use App\Models\Task;
use App\Enums\Category;
use App\Enums\TaskStatus;
use Exception;
use InvalidArgumentException;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

/**
 * Class TaskController
 *
 * This controller handles task-related operations such as listing, creating, updating, and deleting tasks.
 * It provides RESTful API endpoints for task management.
 *
 * @package App\Controllers
 */
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

        // Require auth (returns Response when not authenticated)
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();
        $tasks = Task::getAll('user_id = ?', [$userId], 'created_at DESC');
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

        $task = new Task();
        $task->setTitle($request->value('title'));
        $task->setDescription($request->value('description') ?? null);

        // status: validate via enum
        try {
            $task->setStatus($request->value('status') ?? TaskStatus::PENDING);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid status value'], 400);
        }

        $task->setPriority((int)($request->value('priority') ?? 2));
        $task->setUserId($this->user->getIdentity()->getId());

        // deadline: optional, accept various date strings, normalize to Y-m-d H:i:s
        $deadlineRaw = $request->value('deadline');
        if ($deadlineRaw) {
            $ts = strtotime($deadlineRaw);
            if ($ts === false) {
                return $this->json(['error' => 'Invalid deadline format'], 400);
            }
            $task->setDeadline(date('Y-m-d H:i:s', $ts));
        } else {
            $task->setDeadline(null);
        }

        // category: optional, validate via enum
        $categoryRaw = $request->value('category');
        if ($categoryRaw !== null) {
            try {
                $task->setCategory($categoryRaw);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid category value'], 400);
            }
        }

        $task->setCreatedAt(date('Y-m-d H:i:s'));
        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        $task->save();

        return $this->json($task);
    }

    /**
     * Updates an existing task.
     *
     * @param Request $request The incoming request containing updated task data.
     * @return JsonResponse The JSON response containing the updated task.
     * @throws Exception If task update fails or task not found.
     */
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

        $task->setTitle($request->value('title') ?? $task->getTitle());
        $task->setDescription($request->value('description') ?? $task->getDescription());

        // status: validate if provided
        if ($request->value('status') !== null) {
            try {
                $task->setStatus($request->value('status'));
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid status value'], 400);
            }
        }

        $task->setPriority((int)($request->value('priority') ?? $task->getPriority()));

        // deadline: optional update
        if ($request->value('deadline') !== null) {
            $deadlineRaw = $request->value('deadline');
            if ($deadlineRaw === '') {
                $task->setDeadline(null);
            } else {
                $ts = strtotime($deadlineRaw);
                if ($ts === false) {
                    return $this->json(['error' => 'Invalid deadline format'], 400);
                }
                $task->setDeadline(date('Y-m-d H:i:s', $ts));
            }
        }

        // category: optional update
        if ($request->value('category') !== null) {
            try {
                $task->setCategory($request->value('category'));
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid category value'], 400);
            }
        }

        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        $task->save();

        return $this->json($task);
    }

    /**
     * Deletes a task.
     *
     * @param Request $request The incoming request containing the task ID.
     * @return JsonResponse The JSON response confirming deletion.
     * @throws Exception If task deletion fails or task not found.
     */
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

        return $this->json(['message' => 'Task deleted successfully']);
    }
}

