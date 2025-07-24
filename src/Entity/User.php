<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User entity representing application users
 * Implements UserInterface to integrate with Symfony's security system
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true, length: 255)]
    private ?string $email = null;  // User's email address - unique identifier

    #[ORM\Column(length: 255)]
    private ?string $name = null;   // User's display name

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastlogin = null;  // Timestamp of last login

    #[ORM\Column(nullable: true)]
    private ?int $householdId = null;   // ID of household user belongs to (nullable)

    #[ORM\Column]
    private ?bool $isInitialSetupDone = false;  // Whether user completed initial setup

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $countryCode = null;  // User's country code (e.g., 'HU', 'US', 'AT')

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $country = null;  // User's country name (e.g., 'Hungary', 'United States')

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;  // User's region/state (e.g., 'Budapest', 'California')

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detectedFrom = null;  // How location was detected ('ip', 'google', 'manual')

    // Standard getters and setters for User properties
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
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

    public function getLastlogin(): ?string
    {
        return $this->lastlogin;
    }

    public function setLastlogin(?string $lastlogin): static
    {
        $this->lastlogin = $lastlogin;

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
    
    public function isInitialSetupDone(): ?bool
    {
        return $this->isInitialSetupDone;
    }
    
    public function setInitialSetupDone(bool $isInitialSetupDone): static
    {
        $this->isInitialSetupDone = $isInitialSetupDone;
        return $this;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): static
    {
        $this->countryCode = $countryCode;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function getDetectedFrom(): ?string
    {
        return $this->detectedFrom;
    }

    public function setDetectedFrom(?string $detectedFrom): static
    {
        $this->detectedFrom = $detectedFrom;
        return $this;
    }

    // UserInterface implementation - required for Symfony security integration
    
    /**
     * Returns the user identifier for authentication (email in our case)
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Returns the roles granted to this user
     * All users have ROLE_USER by default
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * Removes sensitive data from the user object
     * Called after authentication to clear any temporary credentials
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // Example: $this->plainPassword = null;
    }
}
