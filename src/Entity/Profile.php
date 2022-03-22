<?php

namespace App\Entity;

use App\Repository\ProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: ProfileRepository::class)]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'uuid')]
    private $uuid;

    #[ORM\Column(type: 'integer')]
    private $duration;

    #[ORM\Column(type: 'integer')]
    private $memory;

    #[ORM\Column(type: 'string', length: 255)]
    private $tailwindcss;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $wordpress;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $site;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    private $created;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid()
    {
        return $this->uuid;
    }

    public function setUuid($uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): self
    {
        $this->duration = $duration;

        return $this;
    }

    public function getMemory(): ?int
    {
        return $this->memory;
    }

    public function setMemory(int $memory): self
    {
        $this->memory = $memory;

        return $this;
    }

    public function getTailwindcss(): ?string
    {
        return $this->tailwindcss;
    }

    public function setTailwindcss(string $tailwindcss): self
    {
        $this->tailwindcss = $tailwindcss;

        return $this;
    }

    public function getWordpress(): ?string
    {
        return $this->wordpress;
    }

    public function setWordpress(string $wordpress): self
    {
        $this->wordpress = $wordpress;

        return $this;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function setSite(string $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }
}
