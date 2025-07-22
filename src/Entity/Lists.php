<?php

namespace App\Entity;

use App\Repository\ListsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListsRepository::class)]
class Lists
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $listId = null;

    #[ORM\Column]
    private ?int $householdId = null;

    #[ORM\Column]
    private ?int $shopId = null;

    #[ORM\Column]
    private ?int $itemId = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $purchased = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListId(): ?int
    {
        return $this->listId;
    }

    public function setListId(int $listId): static
    {
        $this->listId = $listId;

        return $this;
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

    public function getShopId(): ?int
    {
        return $this->shopId;
    }

    public function setShopId(int $shopId): static
    {
        $this->shopId = $shopId;

        return $this;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isPurchased(): bool
    {
        return $this->purchased;
    }

    public function setPurchased(bool $purchased): static
    {
        $this->purchased = $purchased;

        return $this;
    }
}
