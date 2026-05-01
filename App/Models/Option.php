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
        'work_day_start' => 'workDayStart',
        'work_day_end' => 'workDayEnd',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    protected int $id;
    protected int $userId;
    protected string $language; // 'SK' | 'EN'
    protected string $theme; // 'light' | 'dark'
    protected string $taskFilter; // 'all'|'pending'|'in_progress'|'completed'
    protected string $taskSort; // enum values
    protected string $workDayStart; // HH:MM:SS
    protected string $workDayEnd;   // HH:MM:SS
    protected string $createdAt;
    protected string $updatedAt;


    public static function getByUserId(int $userId): ?static
    {
        $items = static::getAll('user_id = ?', [$userId], null, 1);
        return $items[0] ?? null;
    }


    public static function createDefaultForUser(int $userId): static
    {
        $opt = new static();
        $opt->setUserId($userId);
        $opt->setLanguage('SK');
        $opt->setTheme('light');
        $opt->setTaskFilter('all');
        $opt->setTaskSort('none');
        $opt->setWorkDayStart('08:00:00');
        $opt->setWorkDayEnd('16:00:00');
        $opt->setCreatedAt(date('Y-m-d H:i:s'));
        $opt->setUpdatedAt(date('Y-m-d H:i:s'));
        $opt->save();
        return $opt;
    }

    // getteri a setteri
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

    // Normalize time values to HH:MM:SS and validate
    private static function normalizeTimeString(?string $val): ?string
    {
        if ($val === null) return null;
        $v = trim($val);
        if ($v === '') return null;
        // Accept HH:MM or HH:MM:SS
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?: :[0-5]\d)?$/', $v)) {
            // accidental space before seconds handled by regex with space - but we'll normalize
        }
        // Replace space between date/time if any (not expected) and ensure format
        $parts = explode(':', $v);
        if (count($parts) === 2) {
            // add seconds
            $v = $v . ':00';
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v)) {
            throw new \InvalidArgumentException('Invalid time format, expected HH:MM or HH:MM:SS');
        }
        return $v;
    }

    public function getWorkDayStart(): string
    {
        return $this->workDayStart;
    }

    public function setWorkDayStart(string $time): void
    {
        $t = self::normalizeTimeString($time);
        if ($t === null) {
            throw new \InvalidArgumentException('work_day_start cannot be empty');
        }
        $this->workDayStart = $t;
    }

    public function getWorkDayEnd(): string
    {
        return $this->workDayEnd;
    }

    public function setWorkDayEnd(string $time): void
    {
        $t = self::normalizeTimeString($time);
        if ($t === null) {
            throw new \InvalidArgumentException('work_day_end cannot be empty');
        }
        $this->workDayEnd = $t;
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
            'user_id' => $this->userId,
            'language' => $this->language,
            'theme' => $this->theme,
            'task_filter' => $this->taskFilter,
            'task_sort' => $this->taskSort,
            'work_day_start' => $this->workDayStart,
            'work_day_end' => $this->workDayEnd,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
