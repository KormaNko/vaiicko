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

        // support JSON body or form-encoded
        // normalize body to an array (safe for non-JSON requests)
        $body = [];
        if ($request->isJson()) {
            try {
                $tmp = $request->json();
                if (is_object($tmp)) $tmp = (array)$tmp;
                if (is_array($tmp)) $body = $tmp;
            } catch (\JsonException $e) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON']))->setStatusCode(400);
            }
        }

        $task = new Task();
        $title = $body['title'] ?? $request->value('title');
        $task->setTitle($title);
        $task->setDescription($body['description'] ?? $request->value('description') ?? null);

        // status: validate via enum
        $statusVal = $body['status'] ?? $request->value('status') ?? null;
        try {
            $task->setStatus($statusVal ?? TaskStatus::PENDING);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid status value'], 400);
        }

        $task->setPriority((int)($body['priority'] ?? $request->value('priority') ?? 2));
        $task->setUserId($this->user->getIdentity()->getId());

        $deadlineRaw = $body['deadline'] ?? $request->value('deadline');
        if ($deadlineRaw) {
            $ts = strtotime($deadlineRaw);
            if ($ts === false) {
                return $this->json(['error' => 'Invalid deadline format'], 400);
            }
            $task->setDeadline(date('Y-m-d H:i:s', $ts));
        } else {
            $task->setDeadline(null);
        }

        // category handling: accept category_id, category (id) or raw category id
        $catIdRaw = null;
        if (array_key_exists('category_id', $body)) {
            $catIdRaw = $body['category_id'];
        } elseif (isset($body['category']) && is_array($body['category']) && array_key_exists('id', $body['category'])) {
            $catIdRaw = $body['category']['id'];
        } elseif (isset($body['category']) && (is_int($body['category']) || is_string($body['category'])) && is_numeric($body['category'])) {
            $catIdRaw = $body['category'];
        } else {
            // fallback to form value (may be null)
            $catIdRaw = $request->value('category_id') ?? $request->value('category');
        }

        if ($catIdRaw === null || $catIdRaw === '') {
            $task->setCategoryId(null);
        } else {
            $catId = (int)$catIdRaw;
            $category = CategoryModel::getOne($catId);
            if (!$category || $category->getUserId() !== $this->user->getIdentity()->getId()) {
                return $this->json(['error' => 'Invalid category'], 400);
            }
            $task->setCategoryId($catId);
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

        // support JSON body or form-encoded
        // normalize body to an array (safe for non-JSON requests)
        $body = [];
        if ($request->isJson()) {
            try {
                $tmp = $request->json();
                if (is_object($tmp)) $tmp = (array)$tmp;
                if (is_array($tmp)) $body = $tmp;
            } catch (\JsonException $e) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON']))->setStatusCode(400);
            }
        }

        $task->setTitle($body['title'] ?? $request->value('title') ?? $task->getTitle());
        $task->setDescription($body['description'] ?? $request->value('description') ?? $task->getDescription());

        if (($body['status'] ?? $request->value('status')) !== null) {
            $statusVal = $body['status'] ?? $request->value('status');
            try {
                $task->setStatus($statusVal);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid status value'], 400);
            }
        }

        $task->setPriority((int)($body['priority'] ?? $request->value('priority') ?? $task->getPriority()));

        if (($body['deadline'] ?? $request->value('deadline')) !== null) {
            $deadlineRaw = $body['deadline'] ?? $request->value('deadline');
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

        // detect category presence: accept category_id or nested category object (or raw id)
        $bodyArr = (array)$body;
        $categoryProvided = array_key_exists('category_id', $bodyArr)
            || array_key_exists('category', $bodyArr)
            || $request->hasValue('category_id')
            || $request->hasValue('category');

        if ($categoryProvided) {
            if (array_key_exists('category_id', $bodyArr)) {
                $catIdRaw = $bodyArr['category_id'];
            } elseif (array_key_exists('category', $bodyArr) && is_array($bodyArr['category']) && array_key_exists('id', $bodyArr['category'])) {
                $catIdRaw = $bodyArr['category']['id'];
            } elseif (array_key_exists('category', $bodyArr) && is_numeric($bodyArr['category'])) {
                $catIdRaw = $bodyArr['category'];
            } else {
                $catIdRaw = $request->value('category_id') ?? $request->value('category');
            }

            if ($catIdRaw === '') {
                $task->setCategoryId(null);
            } else {
                $catId = (int)$catIdRaw;
                $category = CategoryModel::getOne($catId);
                if (!$category || $category->getUserId() !== $this->user->getIdentity()->getId()) {
                    return $this->json(['error' => 'Invalid category'], 400);
                }
                $task->setCategoryId($catId);
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

