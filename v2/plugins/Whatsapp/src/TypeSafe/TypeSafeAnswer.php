<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

/**
 * One typed TypeSafe answer: a Choice, a Noul or a Score. Which fields are set
 * depends on $type:
 *
 *   choice  $choice (most likely option), $confidence, $probabilities (option => p)
 *   noul    $noul (probability of yes, 0..1; there is no separate confidence)
 *   score   $score (probability-weighted level, can fall between levels),
 *           $confidence, $probabilities (level => p), $legend (level => description)
 *
 * Helpers that only make sense for one type throw TypeSafeException on another,
 * so reading a noul as a choice fails loud instead of returning a default.
 */
final readonly class TypeSafeAnswer
{
    public const string CHOICE = 'choice';
    public const string NOUL   = 'noul';
    public const string SCORE  = 'score';

    /**
     * @param array<string, float>  $probabilities choice: option => p; score: level => p
     * @param array<string, string> $legend        score only: level => description
     */
    private function __construct(
        public string $type,
        public ?string $choice = null,
        public ?float $noul = null,
        public ?float $score = null,
        public ?float $confidence = null,
        public array $probabilities = [],
        public array $legend = [],
    ) {}

    /**
     * @param array<string, mixed> $answer one entry of the response's "answers"
     *
     * @throws TypeSafeException on an unknown type
     */
    public static function fromArray(string $id, array $answer): self
    {
        return match ($answer['type'] ?? null) {
            self::CHOICE => new self(
                type: self::CHOICE,
                choice: (string) ($answer['choice'] ?? ''),
                confidence: (float) ($answer['confidence'] ?? 0.0),
                probabilities: self::floats($answer['probabilities'] ?? []),
            ),
            self::NOUL => new self(
                type: self::NOUL,
                noul: (float) ($answer['noul'] ?? 0.0),
            ),
            self::SCORE => new self(
                type: self::SCORE,
                score: (float) ($answer['score'] ?? 0.0),
                confidence: (float) ($answer['confidence'] ?? 0.0),
                probabilities: self::floats($answer['probabilities'] ?? []),
                legend: array_map('strval', is_array($answer['legend'] ?? null) ? $answer['legend'] : []),
            ),
            default => throw new TypeSafeException("TypeSafe answer '{$id}' has an unknown type", 'typesafe_bad_response'),
        };
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /** Choice or score: probability of one option or level. */
    public function probability(string $option): float
    {
        $this->expect(__FUNCTION__, self::CHOICE, self::SCORE);

        return $this->probabilities[$option] ?? 0.0;
    }

    /** Choice or score: is the probability concentrated enough to act on? */
    public function isConfident(float $threshold): bool
    {
        $this->expect(__FUNCTION__, self::CHOICE, self::SCORE);

        return (float) $this->confidence >= $threshold;
    }

    /** Noul: does the condition hold? 0.5 means yes and no are about equally likely. */
    public function isYes(float $threshold = 0.5): bool
    {
        $this->expect(__FUNCTION__, self::NOUL);

        return (float) $this->noul >= $threshold;
    }

    /** Score: the single most likely level. */
    public function level(): int
    {
        $this->expect(__FUNCTION__, self::SCORE);
        $best = array_keys($this->probabilities, max($this->probabilities ?: [0.0]), true);

        return (int) ($best[0] ?? 0);
    }

    private function expect(string $method, string ...$types): void
    {
        if (!in_array($this->type, $types, true)) {
            throw new TypeSafeException("a {$this->type} answer has no {$method}()", 'typesafe_wrong_answer_type');
        }
    }

    /** @return array<string, float> */
    private static function floats(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = (float) $value;
        }

        return $out;
    }
}
