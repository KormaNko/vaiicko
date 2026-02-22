<?php

namespace App\Models;

use Framework\Core\Model;

class Category extends Model
{
    protected static ?string $tableName = 'categories';
    protected static ?string $primaryKey = 'id';

    protected static array $columnsMap = [
        'id' => 'id',
        'user_id' => 'userId',
        'name' => 'name',
        'color' => 'color',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
        'plan_from' => 'planFrom',
        'plan_to' => 'planTo',
        'max_duration' => 'maxDuration',
        'atomic_task' => 'atomicTask',
    ];

    protected int $id;
    protected int $userId;
    protected string $name;
    protected ?string $color;
    protected string $createdAt;
    protected string $updatedAt;

    // new fields
    protected ?string $planFrom;
    protected ?string $planTo;
    protected ?int $maxDuration;
    protected int $atomicTask;

    public function getId(): int { return $this->id; }
    public function setId(int $id): void { $this->id = $id; }

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $userId): void { $this->userId = $userId; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): void { $this->color = $color; }

    public function getCreatedAt(): string { return $this->createdAt; }
    public function setCreatedAt(string $createdAt): void { $this->createdAt = $createdAt; }

    public function getUpdatedAt(): string { return $this->updatedAt; }
    public function setUpdatedAt(string $updatedAt): void { $this->updatedAt = $updatedAt; }

    // new getters/setters
    public function getPlanFrom(): ?string { return $this->planFrom; }
    public function setPlanFrom(?string $planFrom): void { $this->planFrom = $planFrom; }

    public function getPlanTo(): ?string { return $this->planTo; }
    public function setPlanTo(?string $planTo): void { $this->planTo = $planTo; }

    public function getMaxDuration(): ?int { return $this->maxDuration; }
    public function setMaxDuration(?int $maxDuration): void { $this->maxDuration = $maxDuration; }

    public function getAtomicTask(): int { return $this->atomicTask; }
    public function setAtomicTask(int $atomicTask): void { $this->atomicTask = $atomicTask; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'name' => $this->name,
            'color' => $this->color,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'planFrom' => $this->planFrom,
            'planTo' => $this->planTo,
            'maxDuration' => $this->maxDuration,
            'atomicTask' => $this->atomicTask,
        ];
    }
}
