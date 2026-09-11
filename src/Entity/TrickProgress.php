<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrickStatus;
use App\Repository\TrickProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Derived progress projection for one trick (DATENMODELL.md, "trick_progress",
 * T-0201). Built and kept current exclusively by
 * App\Service\Trick\TrickProgressRefresher - there is no endpoint that sets a
 * status by hand (ticket "Warum": a hand-set status would be a second truth
 * next to the session data, and would make "Ollie sitzt" worthless because
 * you could no longer tell if it was measured or clicked).
 *
 * `trick` is a unidirectional `OneToOne` on purpose (design.md §2, same
 * pattern as `BodyWeight -> SkateSession`, T-0103): `Trick` (EPIC-01) gets no
 * inverse side, so this epic never touches that entity.
 */
#[ORM\Entity(repositoryClass: TrickProgressRepository::class)]
#[ORM\Table(name: 'trick_progress')]
#[ORM\UniqueConstraint(name: 'uniq_trick_progress_trick', columns: ['trick_id'])]
#[ORM\Index(name: 'idx_trick_progress_status', columns: ['status'])]
final class TrickProgress
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Trick::class)]
    #[ORM\JoinColumn(name: 'trick_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Trick $trick;

    #[ORM\Column(type: Types::TEXT, enumType: TrickStatus::class)]
    private TrickStatus $status;

    #[ORM\Column(name: 'first_landed_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstLandedOn;

    #[ORM\Column(name: 'landed_total', type: Types::INTEGER, options: ['default' => 0])]
    private int $landedTotal;

    #[ORM\Column(name: 'attempts_total', type: Types::INTEGER, options: ['default' => 0])]
    private int $attemptsTotal;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Neuanlage-Fall (design.md §4): attemptsTotal/landedTotal start at 0,
     * firstLandedOn at null. The caller (TrickProgressRefresher) is expected
     * to call applyIfChanged() immediately afterwards to fill in the real
     * numbers - this constructor alone never leaves a row with stale data.
     */
    public function __construct(Trick $trick, TrickStatus $status, \DateTimeImmutable $now)
    {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->trick = $trick;
        $this->status = $status;
        $this->firstLandedOn = null;
        $this->landedTotal = 0;
        $this->attemptsTotal = 0;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrick(): Trick
    {
        return $this->trick;
    }

    public function getStatus(): TrickStatus
    {
        return $this->status;
    }

    public function getFirstLandedOn(): ?\DateTimeImmutable
    {
        return $this->firstLandedOn;
    }

    public function getLandedTotal(): int
    {
        return $this->landedTotal;
    }

    public function getAttemptsTotal(): int
    {
        return $this->attemptsTotal;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Compares all four business fields against the current state, writes
     * them only on at least one real change, and only then bumps updatedAt
     * (AK 7, 8) - updatedAt stays a statement about progress, not about the
     * last page view.
     *
     * firstLandedOn is compared by its date string, not by object identity:
     * this column is DATE_IMMUTABLE (no time component), and Doctrine
     * hydrates a fresh DateTimeImmutable instance on every read, so `!==`
     * would always report a change even when the date itself is the same.
     */
    public function applyIfChanged(
        TrickStatus $status,
        int $attemptsTotal,
        int $landedTotal,
        ?\DateTimeImmutable $firstLandedOn,
        \DateTimeImmutable $now,
    ): bool {
        $changed = $this->status !== $status
            || $this->attemptsTotal !== $attemptsTotal
            || $this->landedTotal !== $landedTotal
            || $this->firstLandedOn?->format('Y-m-d') !== $firstLandedOn?->format('Y-m-d');

        if (!$changed) {
            return false;
        }

        $this->status = $status;
        $this->attemptsTotal = $attemptsTotal;
        $this->landedTotal = $landedTotal;
        $this->firstLandedOn = $firstLandedOn;
        $this->updatedAt = $now;

        return true;
    }
}
