<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Plugins\Whatsapp\Support\LanguageHelper;
use Pmsrapi\V2\Support\Logger;

/**
 * Detects a shopper's language from their message and says whether it is
 * safe to save as their language.
 *
 * Saving needs a real sentence: at least languages.save_min_words words
 * (emoji and numbers do not count) and confidence of at least
 * languages.save_min_confidence. "ok", "👍" or a product name are not
 * saved; the caller keeps detecting on later messages until one is.
 *
 * TypeSafe does the detection; when it is unavailable, LanguageHelper's
 * Claude call is the fallback (saved only when the message is long enough).
 */
final class LanguageDetector
{
    public function __construct(
        private readonly TypeSafeClient $client,
        private readonly FlowLoader $flows,
        private readonly LanguageHelper $fallback,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array{language: string, confidence: float, save: bool}
     *         language is a supported code, or the shop's default when unsure
     */
    public function detect(string $phone, string $message): array
    {
        $flow = $this->flows->forShop();
        $message = trim($message);
        $longEnough = $this->words($message) >= $flow->saveLanguageMinWords();

        if ($message === '') {
            return ['language' => $flow->defaultLanguage(), 'confidence' => 0.0, 'save' => false];
        }

        $names = ['en' => 'English', 'nl' => 'Dutch', 'tr' => 'Turkish', 'es' => 'Spanish', 'de' => 'German', 'fr' => 'French',
                  'ar' => 'Arabic', 'it' => 'Italian', 'fy' => 'Frisian', 'pl' => 'Polish', 'pt' => 'Portuguese'];
        $criteria = [];
        foreach ($flow->languages() as $code) {
            $criteria[$code] = $names[$code] ?? $code;
        }
        $criteria['other'] = 'Another language, or too short or too generic to tell (ok, emoji, a product name)';

        try {
            $answer = $this->client->ask(['message' => mb_substr($message, 0, 1000)], [
                'language' => [
                    'type' => 'choice',
                    'instructions' => 'Which language is `message` written in? Judge the sentence itself, not product names or emoji in it.',
                    'criteria' => $criteria,
                ],
            ])->choice('language');
        } catch (TypeSafeException $e) {
            $this->logger->warning('typesafe: language detection failed, using the Claude fallback', ['error' => $e->getMessage()]);
            $language = $this->fallback->detect($phone, $message);

            return ['language' => $language, 'confidence' => 0.0, 'save' => $longEnough && in_array($language, $flow->languages(), true)];
        }

        $confidence = (float) $answer->confidence;
        $known = $answer->choice !== 'other';
        $result = [
            // For this reply: the detected language when it is reasonably clear, else the shop's default.
            'language'   => $known && $confidence >= $flow->threshold() ? $answer->choice : $flow->defaultLanguage(),
            'confidence' => $confidence,
            'save'       => $known && $longEnough && $confidence >= $flow->saveLanguageMinConfidence(),
        ];

        $this->logger->info('typesafe: language', $result + ['detected' => $answer->choice, 'words' => $this->words($message)]);

        return $result;
    }

    /** Words with at least one letter; emoji, numbers and punctuation do not count. */
    private function words(string $message): int
    {
        $tokens = preg_split('/\s+/u', $message) ?: [];

        return count(array_filter($tokens, static fn(string $t): bool => preg_match('/\p{L}/u', $t) === 1));
    }
}
