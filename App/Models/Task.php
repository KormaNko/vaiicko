<?php

namespace App\Models;

use Framework\Core\Model;
use App\Enums\TaskStatus;
use InvalidArgumentException;
use App\Models\Category as CategoryModel;

/**
 * Class Task
 *
 * Represents a task entity in the application. This model handles interactions with the tasks table in the database,
 * providing CRUD operations for task management.
 *
 * @package App\Models
 */
class Task extends Model
{
    /**
     * The database table name for tasks.
     */
    protected static ?string $tableName = 'tasks';

    /**
     * The primary key column name.
     */
    protected static ?string $primaryKey = 'id';

    /**
     * Mapping of database columns to model properties.
     */
    protected static array $columnsMap = [
        'id' => 'id',
        'title' => 'title',
        'description' => 'description',
        'status' => 'status',
        'priority' => 'priority',
        'user_id' => 'userId',
        'deadline' => 'deadline',
        'category_id' => 'categoryId',
        'parent_id' => 'parentId',
        'time_to_complete' => 'timeToComplete',
        'atomic_task' => 'atomicTask',
        'is_dynamic' => 'isDynamic',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    /**
     * Task ID.
     */
    protected int $id;

    /**
     * Task title.
     */
    protected string $title;

    /**
     * Task description.
     */
    protected ?string $description;

    /**
     * Task status (e.g., 'pending', 'in_progress', 'completed').
     */
    protected string $status;

    /**
     * Task priority (numerical value, e.g., 1=low, 2=medium, 3=high).
     */
    protected int $priority;

    /**
     * User ID who owns the task.
     */
    protected int $userId;

    /**
     * Deadline timestamp (nullable).
     */
    protected ?string $deadline;

    /**
     * Category ID of the task (nullable, FK to categories.id).
     */
    protected ?int $categoryId;

    /**
     * Parent task ID (nullable, FK to tasks.id). Represents a parent/child relationship.
     */
    protected ?int $parentId;

    /**
     * Estimated time to complete in minutes (nullable, unsigned int).
     */
    protected ?int $timeToComplete;

    /**
     * Whether this task is atomic (cannot be split). 0 or 1.
     */
    protected int $atomicTask;

    /**
     * Whether this task is dynamic (TINYINT 0/1).
     */
    protected int $isDynamic;

    /**
     * Creation timestamp.
     */
    protected string $createdAt;

    /**
     * Update timestamp.
     */
    protected string $updatedAt;

    /**
     * Constructor to initialize a Task instance.
     *
     * @param array $data Initial data for the task.
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($this, $setter)) {
                $this->$setter($value);
            } elseif (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    // Getters and Setters

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!TaskStatus::isValid($status)) {
            throw new InvalidArgumentException('Invalid status value');
        }
        $this->status = $status;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getDeadline(): ?string
    {
        return $this->deadline;
    }

    public function setDeadline(?string $deadline): void
    {
        $this->deadline = $deadline;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    /**
     * Get parent task id (nullable).
     */
    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * Set parent task id. Accepts null or positive integer (allow numeric strings).
     */
    public function setParentId(?int $parentId): void
    {
        if ($parentId !== null) {
            if (!is_int($parentId)) {
                if (is_string($parentId) && ctype_digit($parentId)) {
                    $parentId = (int)$parentId;
                } else {
                    throw new InvalidArgumentException('parentId must be an integer or null');
                }
            }
            if ($parentId <= 0) {
                throw new InvalidArgumentException('parentId must be a positive integer');
            }
        }
        $this->parentId = $parentId;
    }

    /**
     * Get estimated time to complete (minutes) or null.
     */
    public function getTimeToComplete(): ?int
    {
        return $this->timeToComplete;
    }

    /**
     * Set estimated time to complete (minutes). Accepts null or non-negative integer.
     *
     * @param int|null $timeToComplete
     */
    public function setTimeToComplete(?int $timeToComplete): void
    {
        if ($timeToComplete !== null) {
            if (!is_int($timeToComplete)) {
                // allow numeric strings but cast to int
                if (is_string($timeToComplete) && ctype_digit($timeToComplete)) {
                    $timeToComplete = (int)$timeToComplete;
                } else {
                    throw new InvalidArgumentException('timeToComplete must be integer or null');
                }
            }
            if ($timeToComplete < 0) {
                throw new InvalidArgumentException('timeToComplete must be non-negative');
            }
        }
        $this->timeToComplete = $timeToComplete;
    }

    /**
     * Get atomicTask (0 or 1)
     */
    public function getAtomicTask(): int
    {
        return $this->atomicTask;
    }

    /**
     * Set atomicTask (0 or 1)
     */
    public function setAtomicTask(int $atomicTask): void
    {
        if (!in_array($atomicTask, [0, 1], true)) {
            throw new InvalidArgumentException('atomicTask must be 0 or 1');
        }
        $this->atomicTask = $atomicTask;
    }

    /**
     * Get isDynamic (0 or 1)
     */
    public function getIsDynamic(): int
    {
        return $this->isDynamic;
    }

    /**
     * Set isDynamic (0 or 1)
     */
    public function setIsDynamic(int $isDynamic): void
    {
        if (!in_array($isDynamic, [0, 1], true)) {
            throw new InvalidArgumentException('isDynamic must be 0 or 1');
        }
        $this->isDynamic = $isDynamic;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        // Build a strict, stable representation of a Task according to the API contract.
        $out = [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'timeToComplete' => $this->timeToComplete,
            'atomicTask' => $this->atomicTask,
            'isDynamic' => $this->isDynamic,
            'deadline' => $this->deadline,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'parentId' => $this->parentId,
            // category must always be present as an object or null (only id, name, color)
            'category' => null,
        ];

        if ($this->categoryId !== null) {
            try {
                $cat = CategoryModel::getOne($this->categoryId);
                if ($cat) {
                    $out['category'] = [
                        'id' => $cat->getId(),
                        'name' => $cat->getName(),
                        'color' => $cat->getColor(),
                    ];
                } else {
                    // If category record is missing, represent as null (no fallbacks)
                    $out['category'] = null;
                }
            } catch (\Exception $e) {
                $out['category'] = null;
            }
        }

        return $out;
    }
}
