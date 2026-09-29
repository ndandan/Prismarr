<?php

namespace App\Entity\Media;

use App\Repository\Media\WokeometerTitleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Local mirror of one Wokeometer catalog row (movie, series or season).
 *
 * Written and read exclusively through raw DBAL in WokeometerTitleRepository
 * (the sync upserts pages of 50 rows inside a long-lived messenger consumer,
 * where the ORM identity map would only grow); this mapping (and its
 * WatchlistItem-style accessors) exists so the schema is declared once and
 * SchemaTool builds the same table in tests.
 * Keep it identical to migrations/Version20260929000000.php by hand — there
 * is no mapping-drift test.
 *
 * `mediaType` uses the TMDb/Wokeometer vocabulary `movie|tv`. Seasons are `tv`
 * rows with a `parentWokeometerId` + `seasonNumber`. All timestamps are
 * INTEGER UTC epoch seconds. Stored columns are deliberately minimal (no
 * poster_url / overview / audience_* — Wokeometer terms + storage minimalism).
 */
#[ORM\Entity(repositoryClass: WokeometerTitleRepository::class)]
#[ORM\Table(name: 'wokeometer_media')]
#[ORM\UniqueConstraint(name: 'uniq_wokeometer_media_wid', columns: ['wokeometer_id'])]
#[ORM\Index(name: 'idx_wokeometer_media_tmdb', columns: ['tmdb_id', 'media_type'])]
#[ORM\Index(name: 'idx_wokeometer_media_ext', columns: ['external_source', 'external_id'])]
#[ORM\Index(name: 'idx_wokeometer_media_updated', columns: ['wokeometer_updated_at'])]
#[ORM\Index(name: 'idx_wokeometer_media_parent', columns: ['parent_wokeometer_id'])]
class WokeometerTitle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id via reflection)

    #[ORM\Column(length: 36)]
    private string $wokeometerId;

    #[ORM\Column(length: 10)]
    private string $mediaType; // 'movie' | 'tv'

    #[ORM\Column(nullable: true)]
    private ?int $tmdbId = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $externalSource = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $parentWokeometerId = null;

    #[ORM\Column(nullable: true)]
    private ?int $seasonNumber = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $releaseDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $wokeScore = null; // 0-10; 0 is a valid score, null = unscored

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $tldr = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isAnalyzed = null;

    #[ORM\Column]
    private int $wokeometerUpdatedAt;

    #[ORM\Column]
    private int $lastSeenAt;

    #[ORM\Column]
    private int $syncedAt;

    public function __construct(string $wokeometerId, string $mediaType, string $title, int $wokeometerUpdatedAt, int $lastSeenAt, int $syncedAt)
    {
        $this->wokeometerId        = $wokeometerId;
        $this->mediaType           = $mediaType;
        $this->title               = $title;
        $this->wokeometerUpdatedAt = $wokeometerUpdatedAt;
        $this->lastSeenAt          = $lastSeenAt;
        $this->syncedAt            = $syncedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getWokeometerId(): string { return $this->wokeometerId; }

    public function getMediaType(): string { return $this->mediaType; }
    public function setMediaType(string $mediaType): static { $this->mediaType = $mediaType; return $this; }

    public function getTmdbId(): ?int { return $this->tmdbId; }
    public function setTmdbId(?int $tmdbId): static { $this->tmdbId = $tmdbId; return $this; }

    public function getExternalSource(): ?string { return $this->externalSource; }
    public function setExternalSource(?string $externalSource): static { $this->externalSource = $externalSource; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): static { $this->externalId = $externalId; return $this; }

    public function getParentWokeometerId(): ?string { return $this->parentWokeometerId; }
    public function setParentWokeometerId(?string $parentWokeometerId): static { $this->parentWokeometerId = $parentWokeometerId; return $this; }

    public function getSeasonNumber(): ?int { return $this->seasonNumber; }
    public function setSeasonNumber(?int $seasonNumber): static { $this->seasonNumber = $seasonNumber; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getReleaseDate(): ?string { return $this->releaseDate; }
    public function setReleaseDate(?string $releaseDate): static { $this->releaseDate = $releaseDate; return $this; }

    public function getWokeScore(): ?int { return $this->wokeScore; }
    public function setWokeScore(?int $wokeScore): static { $this->wokeScore = $wokeScore; return $this; }

    public function getTldr(): ?string { return $this->tldr; }
    public function setTldr(?string $tldr): static { $this->tldr = $tldr; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): static { $this->slug = $slug; return $this; }

    public function isAnalyzed(): ?bool { return $this->isAnalyzed; }
    public function setAnalyzed(?bool $isAnalyzed): static { $this->isAnalyzed = $isAnalyzed; return $this; }

    public function getWokeometerUpdatedAt(): int { return $this->wokeometerUpdatedAt; }
    public function setWokeometerUpdatedAt(int $wokeometerUpdatedAt): static { $this->wokeometerUpdatedAt = $wokeometerUpdatedAt; return $this; }

    public function getLastSeenAt(): int { return $this->lastSeenAt; }
    public function setLastSeenAt(int $lastSeenAt): static { $this->lastSeenAt = $lastSeenAt; return $this; }

    public function getSyncedAt(): int { return $this->syncedAt; }
    public function setSyncedAt(int $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
}
