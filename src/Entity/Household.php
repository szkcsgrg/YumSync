<?php

namespace App\Entity;

use App\Repository\HouseholdRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HouseholdRepository::class)]
class Household
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $householdID = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $ownerUserId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHouseholdID(): ?int
    {
        return $this->householdID;
    }

    public function setHouseholdID(int $householdID): static
    {
        $this->householdID = $householdID;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOwnerUserId(): ?string
    {
        return $this->ownerUserId;
    }
    public function setOwnerUserId(string $ownerUserId): static
    {
        $this->ownerUserId = $ownerUserId;

        return $this;
    }
}
