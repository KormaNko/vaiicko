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
    ];

    protected int $id;
    protected int $userId;
    protected string $name;
    protected ?string $color;
    protected string $createdAt;
    protected string $updatedAt;

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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'name' => $this->name,
            'color' => $this->color,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}

