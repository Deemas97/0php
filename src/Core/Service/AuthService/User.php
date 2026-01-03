<?php
namespace Core\Service\AuthService;

class User
{
    private ?string $id;
    private ?string $role;
    private ?string $status;
    private ?string $email;
    private ?string $name;
    private ?string $avatar;

    private ?string $passwordHash;
    private ?string $apiToken;
    private ?string $createdAt;

    public function __construct(array $data = []) {
        if (!empty($data)) {
            $this->id = $data['id'] ?? null;
            $this->role = $data['role_id'] ?? '';
            $this->status = $data['status'] ?? '';
            $this->email = $data['email'] ?? '';
            $this->name = $data['name'] ?? '';
            $this->passwordHash = $data['password_hash'] ?? '';
            $this->apiToken = $data['api_token'] ?? '';
            $this->createdAt = $data['created_at'] ?? '';
            $this->avatar = $data['avatar'] ?? '';

            $this->passwordHash = $data['password_hash'] ?? null;
            $this->apiToken = $data['api_token'] ?? null;
            $this->createdAt = $data['created_at'] ?? null;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setRole(string $role): void
    { 
        $this->role = $role;
    }

    public function setStatus(string $status): void
    { 
        $this->status = $status;
    }

    public function setName(string $f, string $i): void
    { 
        $this->name = ($i . ' ' . $f);
    }

    public function setEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->email = $email;
        }
    }

    public function setAvatar(string $avatar): void
    {
        $this->avatar = $avatar;
    }
}