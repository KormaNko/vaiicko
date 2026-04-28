<?php

namespace App\Auth;

use Framework\Core\IIdentity;

class DbIdentity implements IIdentity
{
    private int $id;
    private string $name;
    private string $email;
    private ?string $role;

    public function __construct(int $id, string $firstName, string $lastName, string $email, ?string $role = null)
    {
        $this->id = $id;
        $this->name = trim($firstName . ' ' . $lastName);
        $this->email = $email;
        $this->role = $role;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }


    public function getRole(): ?string
    {
        return $this->role;
    }
}
