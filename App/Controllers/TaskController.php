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
// cleaned temporary debug imports/comments
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

       //musi byt admin
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

        // support JSON
        // normalizujem na pole
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
        //prijmam categori_id ale nie category objekt/string
        // frontend sometimes sends a `category` object {id,name}. Accept that and map to category_id.
        if (array_key_exists('category', $body) && is_array($body['category']) && array_key_exists('id', $body['category'])) {
            $body['category_id'] = $body['category']['id'];
        } elseif ($request->hasValue('category')) {
            // if form-encoded and category is present as JSON/string, try to decode
            $catVal = $request->value('category');
            if (is_string($catVal)) {
                $decoded = json_decode($catVal, true);
                if (is_array($decoded) && array_key_exists('id', $decoded)) {
                    $body['category_id'] = $decoded['id'];
                }
            }
        }

        $task = new Task();
        $title = $body['title'] ?? $request->value('title');
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

       //moze prist ako jsno alebo formular
        $catIdRaw = null;
        // accept both `category_id` and camelCase `categoryId`
        if (array_key_exists('category_id', $body)) {
            $catIdRaw = $body['category_id'];
        } elseif (array_key_exists('categoryId', $body)) {
            $catIdRaw = $body['categoryId'];
        } elseif ($request->hasValue('category_id')) {
            $catIdRaw = $request->value('category_id');
        } elseif ($request->hasValue('categoryId')) {
            $catIdRaw = $request->value('categoryId');
        }

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

        // handle parent task id (accept parent_id or parentId)
        $parentRaw = null;
        if (array_key_exists('parent_id', $body)) {
            $parentRaw = $body['parent_id'];
        } elseif (array_key_exists('parentId', $body)) {
            $parentRaw = $body['parentId'];
        } elseif ($request->hasValue('parent_id')) {
            $parentRaw = $request->value('parent_id');
        } elseif ($request->hasValue('parentId')) {
            $parentRaw = $request->value('parentId');
        }

        // treat empty string/null/0 as no parent
        if ($parentRaw === '' || $parentRaw === null) {
            $task->setParentId(null);
        } else {
            if (!is_numeric($parentRaw)) {
                return $this->json(['error' => 'Invalid parent_id, must be integer or null'], 400);
            }
            $parentInt = (int)$parentRaw;
            if ($parentInt === 0) {
                // frontend sometimes sends 0 — treat as no parent
                $task->setParentId(null);
            } else {
                $task->setParentId($parentInt);
            }
        }


        // handle timeToComplete (accept only snake_case `time_to_complete`)
        $timeRaw = null;
        // accept snake_case and camelCase
        if (array_key_exists('time_to_complete', $body)) {
            $timeRaw = $body['time_to_complete'];
        } elseif (array_key_exists('timeToComplete', $body)) {
            $timeRaw = $body['timeToComplete'];
        } elseif ($request->hasValue('time_to_complete')) {
            $timeRaw = $request->value('time_to_complete');
        } elseif ($request->hasValue('timeToComplete')) {
            $timeRaw = $request->value('timeToComplete');
        }

        if ($timeRaw === '' ) {
            // empty string -> treat as null
            $task->setTimeToComplete(null);
        } elseif ($timeRaw === null) {
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

        // handle atomic_task (snake_case only). DB default is 0; if not provided, use default 0
        $atomicRaw = null;
        // accept atomic_task or camelCase atomicTask
        if (array_key_exists('atomic_task', $body)) {
            $atomicRaw = $body['atomic_task'];
        } elseif (array_key_exists('atomicTask', $body)) {
            $atomicRaw = $body['atomicTask'];
        } elseif ($request->hasValue('atomic_task')) {
            $atomicRaw = $request->value('atomic_task');
        } elseif ($request->hasValue('atomicTask')) {
            $atomicRaw = $request->value('atomicTask');
        }

        if ($atomicRaw === null || $atomicRaw === '') {
            // not provided or empty -> default to 0
            $task->setAtomicTask(0);
        } else {
            // accept 0/1, '0'/'1', true/false
            if ($atomicRaw === true || $atomicRaw === '1' || $atomicRaw === 1) {
                $task->setAtomicTask(1);
            } elseif ($atomicRaw === false || $atomicRaw === '0' || $atomicRaw === 0) {
                $task->setAtomicTask(0);
            } else {
                return $this->json(['error' => 'Invalid atomic_task value, must be 0 or 1'], 400);
            }
        }

        // handle is_dynamic (snake_case only). DB default is 0; if not provided, use default 0
        $dynamicRaw = null;
        // accept is_dynamic or camelCase isDynamic
        if (array_key_exists('is_dynamic', $body)) {
            $dynamicRaw = $body['is_dynamic'];
        } elseif (array_key_exists('isDynamic', $body)) {
            $dynamicRaw = $body['isDynamic'];
        } elseif ($request->hasValue('is_dynamic')) {
            $dynamicRaw = $request->value('is_dynamic');
        } elseif ($request->hasValue('isDynamic')) {
            $dynamicRaw = $request->value('isDynamic');
        }

        if ($dynamicRaw === null || $dynamicRaw === '') {
            // not provided or empty -> default to 0
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

        // optional planned start/end (accept camelCase and snake_case)
        $plannedStartRaw = null;
        if (array_key_exists('planned_start', $body)) {
            $plannedStartRaw = $body['planned_start'];
        } elseif (array_key_exists('plannedStart', $body)) {
            $plannedStartRaw = $body['plannedStart'];
        } elseif ($request->hasValue('planned_start')) {
            $plannedStartRaw = $request->value('planned_start');
        } elseif ($request->hasValue('plannedStart')) {
            $plannedStartRaw = $request->value('plannedStart');
        }

        if ($plannedStartRaw === '' ) {
            $task->setPlannedStart(null);
        } elseif ($plannedStartRaw !== null) {
            try {
                $task->setPlannedStart($plannedStartRaw);
            } catch (InvalidArgumentException $e) {
                return $this->json(['error' => 'Invalid planned_start format'], 400);
            }
        }

        $plannedEndRaw = null;
        if (array_key_exists('planned_end', $body)) {
            $plannedEndRaw = $body['planned_end'];
        } elseif (array_key_exists('plannedEnd', $body)) {
            $plannedEndRaw = $body['plannedEnd'];
        } elseif ($request->hasValue('planned_end')) {
            $plannedEndRaw = $request->value('planned_end');
        } elseif ($request->hasValue('plannedEnd')) {
            $plannedEndRaw = $request->value('plannedEnd');
        }

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

        // If task is dynamic but timeToComplete not provided, set a sensible default so scheduler can plan it
        if ($task->getIsDynamic() === 1 && $task->getTimeToComplete() === null) {
            $task->setTimeToComplete(30); // default 30 minutes
        }

        try {
            $task->save();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to save task', 'message' => $e->getMessage()], 500);
        }

        // rely on the saved $task instance having the DB id set by the model.
        // (temporary reload/fallback logic removed)

        // log that we're about to run scheduler (best-effort)
        try {
            $root = dirname(__DIR__, 2);
            $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {@mkdir($logDir, 0777, true);}
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'scheduler.log';
            @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Controller triggering scheduler for user=' . $this->user->getIdentity()->getId() . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // ignore logging errors
        }

        // trigger splitting by category when needed, then rescheduling for this user (do not fail the request if scheduler errors)
        try {
            $scheduler = new SchedulerService();
            // split into child tasks if category imposes max duration
            try {
                $scheduler->splitTaskByCategory($task);
            } catch (\Throwable $e) {
                // log and continue
                try { @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] splitTaskByCategory failed for task=' . $task->getId() . ' message=' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX); } catch (\Throwable $__e) {}
            }

            // now recalculate schedule for the user
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {
            // ignore scheduler errors
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

        // accept parent_id / parentId on update
        if (array_key_exists('parent_id', $bodyArr) || array_key_exists('parentId', $bodyArr) || $request->hasValue('parent_id') || $request->hasValue('parentId')) {
            $parentRaw = null;
            if (array_key_exists('parent_id', $bodyArr)) {
                $parentRaw = $bodyArr['parent_id'];
            } elseif (array_key_exists('parentId', $bodyArr)) {
                $parentRaw = $bodyArr['parentId'];
            } elseif ($request->hasValue('parent_id')) {
                $parentRaw = $request->value('parent_id');
            } elseif ($request->hasValue('parentId')) {
                $parentRaw = $request->value('parentId');
            }

            if ($parentRaw === '' || $parentRaw === null) {
                $task->setParentId(null);
            } else {
                if (!is_numeric($parentRaw)) {
                    return $this->json(['error' => 'Invalid parent_id, must be integer or null'], 400);
                }
                $parentInt = (int)$parentRaw;
                if ($parentInt === 0) {
                    // treat 0 as null
                    $task->setParentId(null);
                } else {
                    $task->setParentId($parentInt);
                }
            }
        }

        // handle timeToComplete on update only when provided (snake_case only)
        if (array_key_exists('time_to_complete', $bodyArr) || array_key_exists('timeToComplete', $bodyArr) || $request->hasValue('time_to_complete') || $request->hasValue('timeToComplete')) {
            $timeRaw = null;
            if (array_key_exists('time_to_complete', $bodyArr)) {
                $timeRaw = $bodyArr['time_to_complete'];
            } elseif (array_key_exists('timeToComplete', $bodyArr)) {
                $timeRaw = $bodyArr['timeToComplete'];
            } elseif ($request->hasValue('time_to_complete')) {
                $timeRaw = $request->value('time_to_complete');
            } elseif ($request->hasValue('timeToComplete')) {
                $timeRaw = $request->value('timeToComplete');
            }

            if ($timeRaw === '') {
                // empty string means clear the value
                $task->setTimeToComplete(null);
            } elseif ($timeRaw === null) {
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
        }

        // handle atomic_task on update only when provided
        if (array_key_exists('atomic_task', $bodyArr) || array_key_exists('atomicTask', $bodyArr) || $request->hasValue('atomic_task') || $request->hasValue('atomicTask')) {
            $atomicRaw = null;
            if (array_key_exists('atomic_task', $bodyArr)) {
                $atomicRaw = $bodyArr['atomic_task'];
            } elseif (array_key_exists('atomicTask', $bodyArr)) {
                $atomicRaw = $bodyArr['atomicTask'];
            } elseif ($request->hasValue('atomic_task')) {
                $atomicRaw = $request->value('atomic_task');
            } elseif ($request->hasValue('atomicTask')) {
                $atomicRaw = $request->value('atomicTask');
            }

            if ($atomicRaw === '' || $atomicRaw === null) {
                // treat empty/null as 0 (DB default)
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
        }

        // handle is_dynamic on update only when provided
        if (array_key_exists('is_dynamic', $bodyArr) || array_key_exists('isDynamic', $bodyArr) || $request->hasValue('is_dynamic') || $request->hasValue('isDynamic')) {
            $dynamicRaw = null;
            if (array_key_exists('is_dynamic', $bodyArr)) {
                $dynamicRaw = $bodyArr['is_dynamic'];
            } elseif (array_key_exists('isDynamic', $bodyArr)) {
                $dynamicRaw = $bodyArr['isDynamic'];
            } elseif ($request->hasValue('is_dynamic')) {
                $dynamicRaw = $request->value('is_dynamic');
            } elseif ($request->hasValue('isDynamic')) {
                $dynamicRaw = $request->value('isDynamic');
            }

            if ($dynamicRaw === '' || $dynamicRaw === null) {
                // treat empty/null as 0 (DB default)
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
        }

        $task->setUpdatedAt(date('Y-m-d H:i:s'));

        // If task turned dynamic and timeToComplete is missing, set default to 30 minutes so scheduler can plan it
        if ($task->getIsDynamic() === 1 && $task->getTimeToComplete() === null) {
            $task->setTimeToComplete(30);
        }

        // log that update path is triggering scheduler
        try {
            $root = dirname(__DIR__, 2);
            $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {@mkdir($logDir, 0777, true);}
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'scheduler.log';
            @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Controller (update) triggering scheduler for user=' . $this->user->getIdentity()->getId() . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // ignore logging errors
        }

        $task->save();

        // trigger rescheduling for this user (do not fail the request if scheduler errors)
        try {
            $scheduler = new SchedulerService();
            $scheduler->recalculateForUser($this->user->getIdentity()->getId());
        } catch (\Exception $e) {
            // ignore scheduler errors
        }

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
