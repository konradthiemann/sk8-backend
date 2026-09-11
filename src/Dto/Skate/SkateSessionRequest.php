<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use App\Validator\NotInFuture;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Request payload shared by POST and PUT /api/skate-sessions/{id}: the edit
 * dialog always sends the full session, so one DTO covers both (T-0102
 * design.md §3).
 */
#[OA\Schema(required: ['sessionDate', 'durationMinutes', 'location', 'tricks'])]
final readonly class SkateSessionRequest
{
    public const int MAX_TRICKS = 50;

    /**
     * The startedAt/sessionDate same-day comparison below needs a fixed
     * timezone. It cannot use the `%app.timezone%` parameter like
     * App\Validator\NotInFutureValidator does: plain DTOs are never
     * container services, so no parameter can be injected here. Kept as the
     * app's one and only timezone (design.md §4.3); revisit together if that
     * ever needs to become configurable.
     */
    private const string TIMEZONE = 'Europe/Berlin';

    /**
     * @param list<SessionTrickInput> $tricks
     */
    public function __construct(
        #[Assert\NotBlank(message: 'skate_session.session_date.blank')]
        #[Assert\Date(message: 'skate_session.session_date.blank')]
        #[NotInFuture(mode: 'date', message: 'skate_session.session_date.future')]
        #[OA\Property(description: 'Session date, day precision', format: 'date', example: '2026-09-06')]
        public string $sessionDate,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'skate_session.started_at.future')]
        #[NotInFuture(mode: 'instant', message: 'skate_session.started_at.future')]
        #[OA\Property(description: 'When the session started (ISO 8601 with offset)', format: 'date-time', example: '2026-09-06T16:30:00+02:00', nullable: true)]
        public ?string $startedAt,

        #[Assert\NotBlank(message: 'skate_session.duration_minutes.blank')]
        #[Assert\Range(min: 1, max: 600, notInRangeMessage: 'skate_session.duration_minutes.range')]
        #[OA\Property(description: 'Duration in minutes, 1 to 600', example: 95)]
        public int $durationMinutes,

        #[Assert\NotBlank(message: 'skate_session.location.blank')]
        #[Assert\Length(max: 200, maxMessage: 'skate_session.location.too_long')]
        #[OA\Property(description: 'Where the session took place', example: 'Skatepark Braunschweig')]
        public string $location,

        #[Assert\Range(min: 30, max: 250, notInRangeMessage: 'skate_session.weight.range')]
        #[OA\Property(description: 'Body weight before the session, in kg', example: 78.4, nullable: true)]
        public ?float $weightBeforeKg = null,

        #[Assert\Range(min: 30, max: 250, notInRangeMessage: 'skate_session.weight.range')]
        #[OA\Property(description: 'Body weight after the session, in kg; only allowed together with weightBeforeKg', example: 77.1, nullable: true)]
        public ?float $weightAfterKg = null,

        #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'skate_session.perceived_exertion.range')]
        #[OA\Property(description: 'Perceived exertion, 1 to 10', example: 7, nullable: true)]
        public ?int $perceivedExertion = null,

        #[Assert\Range(min: 0, max: 10, notInRangeMessage: 'skate_session.knee_pain.range')]
        #[OA\Property(description: 'Knee pain, 0 to 10', example: 3, nullable: true)]
        public ?int $kneePain = null,

        #[Assert\Length(max: 2000, maxMessage: 'skate_session.notes.too_long')]
        #[OA\Property(description: 'Free-form notes', example: 'Manuals liefen gut, Knie ab 60 Minuten spuerbar.', nullable: true)]
        public ?string $notes = null,

        #[Assert\Count(max: self::MAX_TRICKS, maxMessage: 'skate_session.tricks.max')]
        #[Assert\Valid]
        #[OA\Property(description: 'Practiced tricks, at most 50 rows; trickSlug must be unique within the list', maxItems: self::MAX_TRICKS)]
        public array $tricks = [],
    ) {
    }

    /**
     * Criterion 7: weightAfterKg is only meaningful once there is a
     * weightBeforeKg to subtract it from.
     */
    #[Assert\Callback]
    public function validateWeightAfterRequiresWeightBefore(ExecutionContextInterface $context): void
    {
        if (null !== $this->weightAfterKg && null === $this->weightBeforeKg) {
            $context->buildViolation('skate_session.weight.after_without_before')
                ->atPath('weightAfterKg')
                ->addViolation();
        }
    }

    /**
     * Criterion 17: startedAt must fall on the same calendar day as
     * sessionDate, in the app's timezone - a session that starts just after
     * local midnight must not be attributed to the previous day.
     */
    #[Assert\Callback]
    public function validateStartedAtSameDayAsSessionDate(ExecutionContextInterface $context): void
    {
        if (null === $this->startedAt) {
            return;
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);

        try {
            $startedAt = new \DateTimeImmutable($this->startedAt);
            $sessionDate = new \DateTimeImmutable($this->sessionDate, $timezone);
        } catch (\Exception) {
            // Malformed value: Assert\DateTime / Assert\Date already report this.
            return;
        }

        if ($startedAt->setTimezone($timezone)->format('Y-m-d') !== $sessionDate->format('Y-m-d')) {
            $context->buildViolation('skate_session.started_at.other_day')
                ->atPath('startedAt')
                ->addViolation();
        }
    }

    /**
     * Criterion 13: the same trick must not appear twice in one request -
     * the database's uniq_session_trick constraint would reject it anyway,
     * but only after a confusing generic error. Not covered by design.md's
     * two named Assert\Callback methods; added because the ticket's own
     * validation table requires it ("trickSlug innerhalb der Liste
     * eindeutig") and criterion 13 exercises it directly.
     */
    #[Assert\Callback]
    public function validateTrickSlugsAreUnique(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->tricks as $trick) {
            if (!$trick instanceof SessionTrickInput) {
                continue;
            }

            if (isset($seen[$trick->trickSlug])) {
                $context->buildViolation('skate_session.tricks.duplicate')
                    ->atPath('tricks')
                    ->addViolation();

                return;
            }

            $seen[$trick->trickSlug] = true;
        }
    }
}
