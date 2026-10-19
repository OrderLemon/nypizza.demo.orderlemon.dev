<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

/**
 * What TypeSafe decided about one shopper message, ready for code to act on.
 *
 *   branch     router branch id, what it does ("do"), the tools Marvin may
 *              use for it and his instructions, with its confidence
 *   language   language the message is written in
 *   requested  language the shopper asks for (null when none; "other" for a
 *              language the shop does not list)
 *   faq        matching FAQ id (null when none)
 *   logistics  pickup or delivery, and when ("asap", …), when the message says so
 *
 * A failed decision (TypeSafe or data unavailable) has $ok = false and a
 * reason; cannotAct() is then true and the shopper gets the fallback reply.
 */
final readonly class Decision
{
    /**
     * @param list<string>         $tools               tools Marvin may call for this branch
     * @param array<string, float> $branchProbabilities
     */
    public function __construct(
        public bool $ok,
        public float $threshold,
        public ?string $fallbackReason = null,
        public ?string $branch = null,
        public ?string $do = null,
        public array $tools = [],
        public ?string $instructions = null,
        public float $branchConfidence = 0.0,
        public array $branchProbabilities = [],
        public ?string $language = null,
        public float $languageConfidence = 0.0,
        public ?string $requestedLanguage = null,
        public float $requestedLanguageConfidence = 0.0,
        public ?string $faq = null,
        public float $faqConfidence = 0.0,
        public ?string $orderTime = null,
        public ?string $logistics = null,
        public int $tokens = 0,
        public float $seconds = 0.0,
        public float $minLead = 0.15,
    ) {}

    public static function fallback(string $reason, float $threshold = 0.6): self
    {
        return new self(ok: false, threshold: $threshold, fallbackReason: $reason);
    }

    /** True when nothing can be acted on: TypeSafe failed or is not sure enough. */
    public function cannotAct(): bool
    {
        return $this->reason() !== null;
    }

    /** Why nothing can be acted on, or null when the decision is usable. */
    public function reason(): ?string
    {
        return match (true) {
            !$this->ok                                   => $this->fallbackReason ?? 'typesafe_failed',
            $this->branch === null || $this->do === null => 'no_branch',
            !$this->sureOfBranch()                       => 'low_confidence',
            default                                      => null,
        };
    }

    /**
     * Sure enough to act: confidence at the threshold, or the top branch is
     * near it (at least threshold - 0.1) and clearly ahead of the second
     * (by min_lead), as with a "yes" that only one of two readings fits well.
     */
    private function sureOfBranch(): bool
    {
        if ($this->branchConfidence >= $this->threshold) {
            return true;
        }
        $p = array_values($this->branchProbabilities);
        rsort($p);
        $top = (float) ($p[0] ?? 0.0);
        $second = (float) ($p[1] ?? 0.0);

        return $top >= $this->threshold - 0.1 && $top - $second >= $this->minLead;
    }

    public function isConfident(float $confidence): bool
    {
        return $confidence >= $this->threshold;
    }

    /** The tool name when the branch does "tool:<name>", else null. */
    public function tool(): ?string
    {
        return $this->do !== null && str_starts_with($this->do, 'tool:') ? substr($this->do, 5) : null;
    }

    /**
     * The two most likely branches with their probability, for the log when
     * TypeSafe is not sure.
     *
     * @return array<string, float>
     */
    public function topBranches(): array
    {
        $probabilities = $this->branchProbabilities;
        arsort($probabilities);

        return array_map(static fn(float $p): float => round($p, 2), array_slice($probabilities, 0, 2, true));
    }

    /** @return array<string, mixed> compact summary for logs */
    public function summary(): array
    {
        return array_filter([
            'branch'    => $this->branch,
            'conf'      => round($this->branchConfidence, 2),
            'reason'    => $this->reason(),
            'language'  => $this->language,
            'requested' => $this->requestedLanguage,
            'faq'       => $this->faq,
            'time'      => $this->orderTime,
            'logistics' => $this->logistics,
            'tokens'    => $this->tokens,
            'seconds'   => round($this->seconds, 2),
        ], static fn(mixed $v): bool => $v !== null && $v !== []);
    }
}
