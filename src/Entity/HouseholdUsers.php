<?php

namespace App\Entity;

use App\Repository\HouseholdUsersRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HouseholdUsersRepository::class)]
class HouseholdUsers
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $householdId = null;

    #[ORM\Column(length: 255)]
    private ?string $userId = null;

    #[ORM\Column(length: 255)]
    private ?string $role = null;

    #[ORM\Column(length: 255)]
    private ?string $status = null;

    #[ORM\Column(length: 255)]
    private ?string $joinedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHouseholdId(): ?int
    {
        return $this->householdId;
    }

    public function setHouseholdId(int $householdId): static
    {
        $this->householdId = $householdId;

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getJoinedAt(): ?string
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(string $joinedAt): static
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }
}
