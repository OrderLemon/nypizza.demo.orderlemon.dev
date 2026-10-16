<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

/**
 * One TypeSafe response: the typed answer per question id, plus what it cost.
 *
 * Read answers with choice()/noul()/score(); each throws TypeSafeException when
 * the id is missing or has another type, so a typo in a question id fails loud
 * instead of silently reading as "no".
 */
final readonly class TypeSafeResult
{
    /**
     * @param array<string, TypeSafeAnswer> $answers question id => answer
     */
    public function __construct(
        public string $model,
        public array $answers,
        public int $inputTokens,
        public int $outputTokens,
        public float $seconds,
        public int $attempts,
    ) {}

    /**
     * @param array<string, mixed> $body decoded response body
     *
     * @throws TypeSafeException when the body does not have the documented shape
     */
    public static function fromResponse(array $body, float $seconds, int $attempts): self
    {
        if (!is_array($body['answers'] ?? null)) {
            throw new TypeSafeException('TypeSafe response has no answers', 'typesafe_bad_response');
        }

        $answers = [];
        foreach ($body['answers'] as $id => $answer) {
            $answers[(string) $id] = TypeSafeAnswer::fromArray((string) $id, is_array($answer) ? $answer : []);
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new self(
            model: (string) ($body['model'] ?? ''),
            answers: $answers,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            seconds: $seconds,
            attempts: $attempts,
        );
    }

    public function has(string $id): bool
    {
        return isset($this->answers[$id]);
    }

    public function choice(string $id): TypeSafeAnswer
    {
        return $this->answer($id, TypeSafeAnswer::CHOICE);
    }

    public function noul(string $id): TypeSafeAnswer
    {
        return $this->answer($id, TypeSafeAnswer::NOUL);
    }

    public function score(string $id): TypeSafeAnswer
    {
        return $this->answer($id, TypeSafeAnswer::SCORE);
    }

    private function answer(string $id, string $type): TypeSafeAnswer
    {
        $answer = $this->answers[$id] ?? null;
        if ($answer === null || !$answer->is($type)) {
            throw new TypeSafeException("no {$type} answer for '{$id}'", 'typesafe_missing_answer');
        }

        return $answer;
    }
}
