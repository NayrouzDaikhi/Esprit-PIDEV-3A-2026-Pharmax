<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email format')]
    private ?string $email = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, name: 'first_name')]
    #[Assert\NotBlank(message: 'First name is required')]
    private ?string $firstName = null;

    #[ORM\Column(length: 255, name: 'last_name')]
    private ?string $lastName = null;

    public const STATUS_BLOCKED = 'BLOCKED';
    public const STATUS_UNBLOCKED = 'UNBLOCKED';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_UNBLOCKED;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'created_at')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'updated_at')]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true, name: 'google_id')]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    /**
     * Google Authenticator secret for two-factor authentication.
     * Null means 2FA is not enabled.
     */
    #[ORM\Column(length: 255, nullable: true, name: 'google_authenticator_secret')]
    private ?string $googleAuthenticatorSecret = null;

    /**
     * Temporary secret during 2FA setup process.
     * Stored on user to survive session regeneration.
     * Cleared after verification or setup cancellation.
     */
    #[ORM\Column(length: 255, nullable: true, name: 'google_authenticator_secret_pending')]
    private ?string $googleAuthenticatorSecretPending = null;

    /**
     * Flag to track if 2FA setup is in progress.
     */
    #[ORM\Column(type: 'boolean', name: 'is_2fa_setup_in_progress', options: ['default' => false])]
    private bool $is2faSetupInProgress = false;

    /**
     * @var Collection<int, Commande>
     */
    #[ORM\OneToMany(mappedBy: 'utilisateur', targetEntity: Commande::class, cascade: ['persist', 'remove'])]
    private Collection $commandes;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataFaceApi = null;

    public function getDataFaceApi(): ?string
    {
        return $this->dataFaceApi;
    }

    public function setDataFaceApi(?string $dataFaceApi): static
    {
        $this->dataFaceApi = $dataFaceApi;
        return $this;
    }

    public function __construct()
    {
        $this->commandes = new ArrayCollection();
    }

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

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password ?? '';
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function setIsBlocked(bool $blocked): static
    {
        $this->status = $blocked ? self::STATUS_BLOCKED : self::STATUS_UNBLOCKED;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    /**
     * Get the Google Authenticator secret.
     * Required by TwoFactorInterface from scheb/2fa-bundle.
     */
    public function getGoogleAuthenticatorSecret(): ?string
    {
        return $this->googleAuthenticatorSecret;
    }

    /**
     * Set the Google Authenticator secret.
     */
    public function setGoogleAuthenticatorSecret(?string $secret): static
    {
        $this->googleAuthenticatorSecret = $secret;

        return $this;
    }

    /**
     * Check if two-factor authentication is enabled for this user.
     * Required by TwoFactorInterface from scheb/2fa-bundle.
     */
    public function isTwoFactorAuthenticationEnabled(): bool
    {
        // 2FA is enabled if the secret is not null and not empty
        return !empty($this->googleAuthenticatorSecret);
    }

    /**
     * Check if Google Authenticator authentication is enabled.
     * Required by TwoFactorInterface.
     */
    public function isGoogleAuthenticatorEnabled(): bool
    {
        return !empty($this->googleAuthenticatorSecret);
    }

    /**
     * Get the username to display in Google Authenticator.
     * Required by TwoFactorInterface.
     */
    public function getGoogleAuthenticatorUsername(): string
    {
        return $this->email ?? 'User';
    }

    /**
     * Get the pending 2FA secret (during setup process).
     */
    public function getGoogleAuthenticatorSecretPending(): ?string
    {
        return $this->googleAuthenticatorSecretPending;
    }

    /**
     * Set the pending 2FA secret (during setup process).
     */
    public function setGoogleAuthenticatorSecretPending(?string $secret): static
    {
        $this->googleAuthenticatorSecretPending = $secret;

        return $this;
    }

    /**
     * Check if 2FA setup is in progress.
     */
    public function is2faSetupInProgress(): bool
    {
        return $this->is2faSetupInProgress;
    }

    /**
     * Set 2FA setup in progress flag.
     */
    public function set2faSetupInProgress(bool $inProgress): static
    {
        $this->is2faSetupInProgress = $inProgress;

        return $this;
    }

    /**
     * @return Collection<int, Commande>
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): static
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes->add($commande);
            $commande->setUtilisateur($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): static
    {
        if ($this->commandes->removeElement($commande)) {
            if ($commande->getUtilisateur() === $this) {
                $commande->setUtilisateur(null);
            }
        }

        return $this;
    }

        /**
     * Returning a salt is only needed, if you are not using a modern
     * hashing algorithm (e.g. bcrypt or sodium) in your security.yaml.
     *
     * @see UserInterface
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
}
