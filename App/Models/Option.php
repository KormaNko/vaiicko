<?php

namespace App\Models;

use Framework\Core\Model;

/**
 * Class Option
 * Represents per-user application options/settings.
 */
class Option extends Model
{
    protected static ?string $tableName = 'options';
    protected static ?string $primaryKey = 'id';

    protected static array $columnsMap = [
        'id' => 'id',
        'user_id' => 'userId',
        'language' => 'language',
        'theme' => 'theme',
        'task_filter' => 'taskFilter',
        'task_sort' => 'taskSort',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    protected int $id;
    protected int $userId;
    protected string $language; // 'SK' | 'EN'
    protected string $theme; // 'light' | 'dark'
    protected string $taskFilter; // 'all'|'pending'|'in_progress'|'completed'
    protected string $taskSort; // enum values
    protected string $createdAt;
    protected string $updatedAt;

    /**
     * Fetch options for given user id. Returns null if not found.
     * @param int $userId
     * @return static|null
     * @throws \Exception
     */
    public static function getByUserId(int $userId): ?static
    {
        $items = static::getAll('user_id = ?', [$userId], null, 1);
        return $items[0] ?? null;
    }

    /**
     * Create default options for user and persist to DB.
     * @param int $userId
     * @return static
     * @throws \Exception
     */
    public static function createDefaultForUser(int $userId): static
    {
        $opt = new static();
        $opt->setUserId($userId);
        $opt->setLanguage('SK');
        $opt->setTheme('light');
        $opt->setTaskFilter('all');
        $opt->setTaskSort('none');
        $opt->setCreatedAt(date('Y-m-d H:i:s'));
        $opt->setUpdatedAt(date('Y-m-d H:i:s'));
        $opt->save();
        return $opt;
    }

    // Getters and setters
    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): void
    {
        $allowed = ['SK', 'EN'];
        if (!in_array($language, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid language');
        }
        $this->language = $language;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): void
    {
        $allowed = ['light', 'dark'];
        if (!in_array(strtolower($theme), $allowed, true)) {
            throw new \InvalidArgumentException('Invalid theme');
        }
        $this->theme = strtolower($theme);
    }

    public function getTaskFilter(): string
    {
        return $this->taskFilter;
    }

    public function setTaskFilter(string $filter): void
    {
        $allowed = ['all', 'pending', 'in_progress', 'completed'];
        if (!in_array($filter, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid task filter');
        }
        $this->taskFilter = $filter;
    }

    public function getTaskSort(): string
    {
        return $this->taskSort;
    }

    public function setTaskSort(string $sort): void
    {
        $allowed = [
            'none',
            'priority_asc',
            'priority_desc',
            'title_asc',
            'title_desc',
            'deadline_asc',
            'deadline_desc',
        ];
        if (!in_array($sort, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid task sort');
        }
        $this->taskSort = $sort;
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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'user_id' => $this->userId,
            'language' => $this->language,
            'theme' => $this->theme,
            'taskFilter' => $this->taskFilter,
            'task_filter' => $this->taskFilter,
            'taskSort' => $this->taskSort,
            'task_sort' => $this->taskSort,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
