<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

/**
 * One shop's conversation flow: the shared default flow merged with the shop's
 * own flow.json (see FlowLoader). Read-only; FlowLoader only hands out flows
 * that passed validation.
 *
 * Router branches and FAQ entries are lists of arrays with at least "id" and
 * "description" (router) or "match" (faq); what a branch does is in "do"
 * (tool:<name>, order, faq, language, handoff, marvin), an optional
 * "instructions" tells Marvin how to word the reply.
 */
final readonly class Flow
{
    /**
     * @param array<string, mixed> $data merged, validated flow
     */
    public function __construct(
        public int $shopId,
        private array $data,
        public bool $usesShopFile,
    ) {}

    public function threshold(): float
    {
        return (float) $this->data['settings']['threshold'];
    }

    /** How far the top branch must lead the second to count as sure when its confidence is just under the threshold. */
    public function minLead(): float
    {
        return (float) ($this->data['settings']['min_lead'] ?? 0.15);
    }

    public function historyTurns(): int
    {
        return (int) $this->data['settings']['history_turns'];
    }

    public function defaultLanguage(): string
    {
        return (string) $this->data['languages']['default'];
    }

    /** @return list<string> */
    public function languages(): array
    {
        return $this->data['languages']['supported'];
    }

    public function saveLanguageMinWords(): int
    {
        return (int) ($this->data['languages']['save_min_words'] ?? 3);
    }

    /** A detected language is only saved for the shopper at or above this confidence. */
    public function saveLanguageMinConfidence(): float
    {
        return (float) ($this->data['languages']['save_min_confidence'] ?? 0.8);
    }

    /** @return list<array<string, mixed>> */
    public function router(): array
    {
        return $this->data['router'];
    }

    /** @return array<string, mixed>|null */
    public function branch(string $id): ?array
    {
        return $this->byId($this->data['router'], $id);
    }

    /** @return list<array<string, mixed>> */
    public function faq(): array
    {
        return $this->data['faq'];
    }

    /** @return array<string, mixed>|null */
    public function faqEntry(string $id): ?array
    {
        return $this->byId($this->data['faq'], $id);
    }

    /** @return array<string, mixed> */
    public function handoff(): array
    {
        return $this->data['handoff'] ?? [];
    }

    /**
     * Text for a key in a language, falling back to the flow's default
     * language, then English. Null when the key does not exist at all.
     */
    public function text(string $key, string $language): ?string
    {
        $texts = $this->data['texts'][$key] ?? null;
        if (!is_array($texts)) {
            return null;
        }

        return $texts[$language] ?? $texts[$this->defaultLanguage()] ?? $texts['en'] ?? null;
    }

    /** Text for a key in exactly this language, without falling back; null when missing. */
    public function textIn(string $key, string $language): ?string
    {
        $text = $this->data['texts'][$key][$language] ?? null;

        return is_string($text) ? $text : null;
    }

    /** @return array<string, mixed> the whole merged flow, for logs and debugging */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function byId(array $entries, string $id): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }
}
