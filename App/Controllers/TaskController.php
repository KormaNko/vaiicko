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
//bezne som si tu pomahal s ai aj genrtovali casti kododv
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

        // Reject any use of `category` (object or string). API accepts only `category_id` (int|null).
        if (array_key_exists('category', $body) || $request->hasValue('category')) {
            return $this->json(['error' => "Invalid parameter 'category'. Send 'category_id' (int|null) only."], 400);
        }

        $task = new Task();
        $title = $body['title'] ?? $request->value('title');
        // Title is required and must be a non-empty string. Coerce and validate to avoid exceptions
        if ($title === null || trim((string)$title) === '') {
            return $this->json(['error' => 'Title is required'], 400);
        }
        $task->setTitle((string)$title);
        $task->setDescription($body['description'] ?? $request->value('description') ?? null);

        //tu sa kontroluje podla enumu cize to musi sediet
        $statusVal = $body['status'] ?? $request->value('status') ?? null;
        try {
            $task->setStatus($statusVal ?? TaskStatus::PENDING);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid status value'], 400);
        }

        $task->setPriority((int)($body['priority'] ?? $request->value('priority') ?? 2));
        $task->setUserId($this->user->getIdentity()->getId());
        //ak neda deadline nevadi ale nezobrazi sa mu v kalendaria ale to uz na frontende
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

        // Only accept category_id (int|null). It may come in JSON body or as form value named 'category_id'.
        $catIdRaw = null;
        if (array_key_exists('category_id', $body)) {
            $catIdRaw = $body['category_id'];
        } elseif ($request->hasValue('category_id')) {
            $catIdRaw = $request->value('category_id');
        }

        if ($catIdRaw === null || $catIdRaw === '') {
            // explicit null or omitted => no category
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

        $task->setCreatedAt(date('Y-m-d H:i:s'));
        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        try {
            $task->save();
        } catch (\Exception $e) {
            // Return a JSON error instead of an uncaught exception page. Include message for debugging.
            return $this->json(['error' => 'Failed to save task', 'message' => $e->getMessage()], 500);
        }

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

        $bodyArr = (array)$body;

         $task->setTitle($body['title'] ?? $request->value('title') ?? $task->getTitle());
         $task->setDescription($body['description'] ?? $request->value('description') ?? $task->getDescription());

        //ak idem menit statis
        if (($body['status'] ?? $request->value('status')) !== null) {
            $statusVal = $body['status'] ?? $request->value('status');
            try {
                //musi byt v spravon formate
                $task->setStatus($statusVal);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid status value'], 400);
            }
        }

        $task->setPriority((int)($body['priority'] ?? $request->value('priority') ?? $task->getPriority()));

        //menim len ked pride
        if (($body['deadline'] ?? $request->value('deadline')) !== null) {
            $deadlineRaw = $body['deadline'] ?? $request->value('deadline');
            //prazdny rusim deadline
            if ($deadlineRaw === '') {
                $task->setDeadline(null);
            } else {
                //ak existuje parsujem ho tam
                $ts = strtotime($deadlineRaw);
                if ($ts === false) {
                    return $this->json(['error' => 'Invalid deadline format'], 400);
                }
                $task->setDeadline(date('Y-m-d H:i:s', $ts));
            }
        }

        // Reject any `category` param (object/string). Accept only `category_id` when provided.
        if (array_key_exists('category', $bodyArr) || $request->hasValue('category')) {
            return $this->json(['error' => "Invalid parameter 'category'. Send 'category_id' (int|null) only."], 400);
        }

        $catIdRaw = null;
        if (array_key_exists('category_id', $bodyArr)) {
            $catIdRaw = $bodyArr['category_id'];
        } elseif ($request->hasValue('category_id')) {
            $catIdRaw = $request->value('category_id');
        }

        // Only change category when category_id was explicitly provided in request
        if (array_key_exists('category_id', $bodyArr) || $request->hasValue('category_id')) {
            if ($catIdRaw === '' || $catIdRaw === null) {
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
        }

        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        $task->save();

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

        return $this->json(['message' => 'Task deleted successfully']);
    }
}

