<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

/**
 * What TypeSafe decided about one shopper message, ready for code to act on.
 *
 *   branch        router branch id, what it does ("do") and Marvin's
 *                 instructions for it, with its confidence
 *   language      language the message is written in
 *   requested     language the shopper asks for (null when none; "other" for
 *                 a language the shop does not list)
 *   faq           matching FAQ id (null when none)
 *   order slots   product, config options (size, base, …), quantity,
 *                 ingredient change, order time, pickup/delivery
 *
 * A fallback decision (TypeSafe or data unavailable) has $ok = false and a
 * reason; useMarvin() is then true and the message goes to Marvin as before.
 */
final readonly class Decision
{
    public const string ITEM_ONE = 'one';
    public const string ITEM_NONE = 'none';
    public const string ITEM_SEVERAL = 'several';

    /**
     * @param array<string, float>                                        $branchProbabilities
     * @param array<string, array{option: ?string, confidence: float}>     $options config group label => chosen option
     */
    public function __construct(
        public bool $ok,
        public float $threshold,
        public ?string $fallbackReason = null,
        public ?string $branch = null,
        public ?string $do = null,
        public ?string $instructions = null,
        public float $branchConfidence = 0.0,
        public array $branchProbabilities = [],
        public ?string $language = null,
        public float $languageConfidence = 0.0,
        public ?string $requestedLanguage = null,
        public float $requestedLanguageConfidence = 0.0,
        public ?string $faq = null,
        public float $faqConfidence = 0.0,
        public string $item = self::ITEM_NONE,
        public ?int $productId = null,
        public ?string $productName = null,
        public float $itemConfidence = 0.0,
        public array $options = [],
        public ?int $quantity = null,
        public float $modification = 0.0,
        public ?string $orderTime = null,
        public ?string $logistics = null,
        public int $tokens = 0,
        public float $seconds = 0.0,
    ) {}

    public static function fallback(string $reason, float $threshold = 0.6): self
    {
        return new self(ok: false, threshold: $threshold, fallbackReason: $reason);
    }

    /**
     * True when Marvin should handle the message as before: TypeSafe failed,
     * the router is not confident enough, or the branch hands off to Marvin.
     */
    public function useMarvin(): bool
    {
        return $this->reason() !== null;
    }

    /** Why the message goes to Marvin, or null when code can act on the decision. */
    public function reason(): ?string
    {
        return match (true) {
            !$this->ok                                  => $this->fallbackReason ?? 'typesafe_failed',
            $this->branch === null || $this->do === null => 'no_branch',
            $this->branchConfidence < $this->threshold   => 'low_confidence',
            $this->do === 'marvin'                       => 'branch_marvin',
            default                                      => null,
        };
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

    public function wantsModification(): bool
    {
        return $this->modification >= 0.5;
    }

    /** Chosen option for a config group ("Size" => "Large (40cm)"), or null. */
    public function option(string $group): ?string
    {
        return $this->options[$group]['option'] ?? null;
    }

    /** @return array<string, mixed> compact summary for logs */
    public function summary(): array
    {
        return array_filter([
            'branch'     => $this->branch,
            'conf'       => round($this->branchConfidence, 2),
            'marvin'     => $this->reason(),
            'language'   => $this->language,
            'requested'  => $this->requestedLanguage,
            'faq'        => $this->faq,
            'item'       => $this->item === self::ITEM_ONE ? $this->productName : ($this->item === self::ITEM_SEVERAL ? 'several' : null),
            'options'    => array_filter(array_map(static fn(array $o): ?string => $o['option'], $this->options)) ?: null,
            'quantity'   => $this->quantity,
            'modify'     => $this->wantsModification() ?: null,
            'time'       => $this->orderTime,
            'logistics'  => $this->logistics,
            'tokens'     => $this->tokens,
            'seconds'    => round($this->seconds, 2),
        ], static fn(mixed $v): bool => $v !== null && $v !== []);
    }
}
