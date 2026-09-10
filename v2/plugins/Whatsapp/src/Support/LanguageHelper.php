<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Support;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Support\Logger;
use Plugins\Whatsapp\AI\AnthropicClient;
use RedisException;
use Throwable;

/**
 * Detects the shopper's language from a short text sample and translates the
 * small, fixed set of UI strings (button labels, fallback replies) this
 * controller sends outside of Marvin's own replies — those come straight out
 * of the model and already adapt to the shopper's language on their own;
 * these do not. Detection is a single, standalone Anthropic call — not a Marvin tool.
 * The language is stored in Redis cached for CACHE_TTL_SECONDS using the phone number as key.
 */
final class LanguageHelper
{
    private const DEFAULT_LANGUAGE = 'en';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24; // 24 hours

    private const TRANSLATIONS = [
        'welcome' => [
            'en' => "Hi, welcome to {{SHOP_NAME}}!",
            'nl' => "Hoi, welkom bij {{SHOP_NAME}}!",
            'tr' => "Merhaba, {{SHOP_NAME}}'e hoş geldiniz!",
            'es' => "¡Hola, bienvenido a {{SHOP_NAME}}!",
            'de' => "Hallo, willkommen bei {{SHOP_NAME}}!",
            'fr' => "Bonjour, bienvenue chez {{SHOP_NAME}} !",
        ],
        'welcome_wine' => [
            'en' => "Hi, welcome to {{SHOP_NAME}}!\nOrdering is very easy: you just tell me what you're looking for and your specific preferences, and I'll give you a few suggestions and add it to your basket if needed. Tell me when you're done, and then I'll give you a link to check out.",
            'nl' => "Hoi, welkom bij {{SHOP_NAME}}!\nBestellen gaat heel makkelijk: jij vertelt me gewoon waar je naar op zoek bent en je specifieke wensen, en ik doe je een paar voorstellen en voeg het eventueel toe aan je mandje. Zeg me wanneer je klaar bent, en dan geef ik je een link om af te rekenen.",
            'tr' => "Merhaba, {{SHOP_NAME}}'e hoş geldiniz!\nSipariş vermek çok kolay: sadece ne aradığınızı ve özel tercihlerinizi söyleyin, size birkaç öneri sunayım ve gerekirse sepetinize ekleyeyim. Bittiğinizde bana söyleyin, ardından ödeme yapmanız için bir bağlantı göndereyim.",
            'es' => "¡Hola, bienvenido a {{SHOP_NAME}}!\nHacer un pedido es muy fácil: solo dime lo que buscas y tus preferencias específicas, y te daré algunas sugerencias y las añadiré a tu cesta si es necesario. Avísame cuando hayas terminado y te enviaré un enlace para finalizar la compra.",
            'de' => "Hallo, willkommen bei {{SHOP_NAME}}!\nBestellen ist ganz einfach: Sag mir einfach, wonach du suchst und welche besonderen Vorlieben du hast, und ich gebe dir ein paar Vorschläge und lege sie bei Bedarf in deinen Warenkorb. Sag mir Bescheid, wenn du fertig bist, dann schicke ich dir einen Link zum Bezahlen.",
            'fr' => "Bonjour, bienvenue chez {{SHOP_NAME}} !\nCommander est très simple : dites-moi simplement ce que vous cherchez et vos préférences spécifiques, et je vous ferai quelques suggestions et les ajouterai à votre panier si besoin. Dites-moi quand vous avez terminé, et je vous enverrai un lien pour finaliser votre commande.",
        ],
        'the_usual' => [
            'en' => 'The usual',
            'nl' => 'Het vaste',
            'tr' => 'Her zamanki',
            'es' => 'Lo de siempre',
            'de' => 'Das Übliche',
            'fr' => "Comme d'habitude",
        ],
        'something_else' => [
            'en' => 'Something else',
            'nl' => 'Iets anders',
            'tr' => 'Başka bir şey',
            'es' => 'Otra cosa',
            'de' => 'Etwas anderes',
            'fr' => 'Autre chose',
        ],
        'todays_promo' => [
            'en' => "Today's promo",
            'nl' => 'Actie van vandaag',
            'tr' => 'Günün kampanyası',
            'es' => 'Promo de hoy',
            'de' => 'Heutiges Angebot',
            'fr' => 'Promo du jour',
        ],
        'voice_message_fallback' => [
            'en' => "Sorry, I couldn't understand that voice message. Could you type it instead?",
            'nl' => 'Sorry, ik kon dat spraakbericht niet verstaan. Kun je het typen in plaats daarvan?',
            'tr' => 'Üzgünüm, o sesli mesajı anlayamadım. Bunun yerine yazabilir misin?',
            'es' => 'Lo siento, no pude entender ese mensaje de voz. ¿Podrías escribirlo en su lugar?',
            'de' => 'Entschuldigung, ich konnte die Sprachnachricht nicht verstehen. Könntest du sie stattdessen tippen?',
            'fr' => "Désolé, je n'ai pas compris ce message vocal. Pourriez-vous plutôt l'écrire ?",
        ],
        'marvin_fallback' => [
            'en' => "Sorry, I can't help you right now. A colleague will help you further: {{SUPPORT_1}} or {{SUPPORT_2}}",
            'nl' => 'Sorry, ik kan je nu niet verder helpen. Een collega helpt je graag verder: {{SUPPORT_1}} of {{SUPPORT_2}}',
            'tr' => 'Üzgünüm, şu anda sana yardımcı olamıyorum. Bir meslektaşım sana yardımcı olacak: {{SUPPORT_1}} veya {{SUPPORT_2}}',
            'es' => 'Lo siento, no puedo ayudarte en este momento. Un compañero te ayudará: {{SUPPORT_1}} o {{SUPPORT_2}}',
            'de' => 'Entschuldigung, ich kann dir gerade nicht weiterhelfen. Ein Kollege wird dir weiterhelfen: {{SUPPORT_1}} oder {{SUPPORT_2}}',
            'fr' => "Désolé, je ne peux pas t'aider pour le moment. Un collègue t'aidera : {{SUPPORT_1}} ou {{SUPPORT_2}}",
        ],
        'track_order' => [
            'en' => 'Track my order',
            'nl' => 'Bestelling volgen',
            'tr' => 'Siparişimi takip et',
            'es' => 'Rastrear pedido',
            'de' => 'Bestellung verfolgen',
            'fr' => 'Suivre ma commande',
        ],
        'order_lost' => [
            'en' => 'Your order got lost. Please contact our team: {{SUPPORT_1}} or {{SUPPORT_2}}',
            'nl' => 'Je bestelling is helaas kwijtgeraakt. Neem contact op met ons team: {{SUPPORT_1}} of {{SUPPORT_2}}',
            'tr' => 'Siparişiniz kayboldu. Lütfen ekibimizle iletişime geçin: {{SUPPORT_1}} veya{{SUPPORT_2}}',
            'es' => 'Tu pedido se ha perdido. Por favor, contacta con nuestro equipo: {{SUPPORT_1}} o {{SUPPORT_2}}',
            'de' => 'Deine Bestellung ist leider verloren gegangen. Bitte kontaktiere unser Team: {{SUPPORT_1}} oder {{SUPPORT_2}}',
            'fr' => 'Votre commande a été perdue. Veuillez contacter notre équipe : {{SUPPORT_1}} ou {{SUPPORT_2}}',
        ],
        'open' => [
            'en' => 'OPEN',
            'nl' => 'OPENEN',
            'tr' => 'AÇ',
            'es' => 'ABRIR',
            'de' => 'ÖFFNEN',
            'fr' => 'OUVRIR',
        ],
        'thank_you_for_ordering' => [
            'en' => 'Thank you for ordering at {{SHOP_NAME}}!',
            'nl' => 'Bedankt voor je bestelling bij {{SHOP_NAME}}!',
            'tr' => '{{SHOP_NAME}} adresinden sipariş verdiğiniz için teşekkür ederiz!',
            'es' => '¡Gracias por tu pedido en {{SHOP_NAME}}!',
            'de' => 'Vielen Dank für deine Bestellung bei {{SHOP_NAME}}!',
            'fr' => 'Merci pour votre commande chez {{SHOP_NAME}} !',
        ],
        'open_menu_caption' => [
            'en' => 'To get your menu always click here',
            'nl' => 'Klik hier altijd voor je menu',
            'tr' => 'Menünüze ulaşmak için her zaman buraya tıklayın',
            'es' => 'Haz clic aquí siempre para ver tu menú',
            'de' => 'Klicke hier immer für dein Menü',
            'fr' => 'Cliquez toujours ici pour votre menu',
        ],
        'channel_invitation' => [
            'en' => 'Do you want to stay up to date on all our offers, daily specials, and menu updates? Then sign up for our WhatsApp channel',
            'nl' => 'Wil je op de hoogte blijven van al onze aanbiedingen, daghappen en menu updates? Meld je dan aan voor ons WhatsApp kanaal',
            'tr' => 'Tüm fırsatlarımızdan, günün özel menülerinden ve menü güncellemelerinden haberdar olmak ister misiniz? O zaman WhatsApp kanalımıza kaydolun',
            'es' => '¿Quieres estar al día de todas nuestras ofertas, especiales del día y actualizaciones del menú? Entonces regístrate en nuestro canal de WhatsApp',
            'de' => 'Möchtest du über all unsere Angebote, Tagesspecials und Menü-Updates auf dem Laufenden bleiben? Dann melde dich für unseren WhatsApp-Kanal an',
            'fr' => 'Souhaitez-vous rester informé de toutes nos offres, plats du jour et mises à jour du menu ? Alors inscrivez-vous à notre chaîne WhatsApp',
        ],
        'channel_invitation_wine' => [
            'en' => 'Would you like to stay up to date on all our offers, special wines, and tastings? Then sign up now for our WhatsApp channel.',
            'nl' => 'Wil je op de hoogte blijven van al onze aanbiedingen, speciale wijnen en proeverijen? Meld je dan nu aan voor ons WhatsApp-kanaal.',
            'tr' => 'Tüm fırsatlarımızdan, özel şaraplarımızdan ve tadım etkinliklerimizden haberdar olmak ister misiniz? O zaman hemen WhatsApp kanalımıza kaydolun.',
            'es' => '¿Quieres estar al día de todas nuestras ofertas, vinos especiales y catas? Entonces regístrate ahora en nuestro canal de WhatsApp.',
            'de' => 'Möchtest du über all unsere Angebote, besonderen Weine und Verkostungen auf dem Laufenden bleiben? Dann melde dich jetzt für unseren WhatsApp-Kanal an.',
            'fr' => 'Souhaitez-vous rester informé de toutes nos offres, vins spéciaux et dégustations ? Alors inscrivez-vous dès maintenant à notre chaîne WhatsApp.',
        ],
        'make_selection' => [
            'en' => 'Please make your selection below',
            'nl' => 'Maak hieronder je keuze',
            'tr' => 'Lütfen aşağıdan seçiminizi yapın',
            'es' => 'Por favor, haz tu selección a continuación',
            'de' => 'Bitte triff deine Auswahl unten',
            'fr' => 'Veuillez faire votre choix ci-dessous',
        ],
    ];

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    /**
     * Detected language for this phone number.
     *
     * A previously cached value always wins, even when $sample is empty —
     * e.g. a voice message whose transcription failed has no text to
     * classify, but a shopper already known (from an earlier text turn) to
     * write in Dutch should still get Dutch here, not silently fall back to
     * English. Classification (and caching its result) only happens when
     * nothing is cached yet AND there is text to work with; an empty sample
     * with nothing cached degrades to English WITHOUT writing to the cache,
     * so a later real message can still establish the real language rather
     * than being permanently stuck on a guess made from no data.
     */
    public function detect(string $phone, string $sample): string
    {
        $cached = $this->cachedLanguage($phone);
        if ($cached !== null) {

            return $cached;
        }

        $sample = trim($sample);
        if ($sample === '') {
            return self::DEFAULT_LANGUAGE;
        }

        $language = $this->classify($sample);
        $this->cacheLanguage($phone, $language);

        return $language;
    }

    /**
     * @param array<string, string|int|float> $vars replaces {{NAME}} placeholders
     *        (uppercased) in the translated string, e.g. ['shop_name' => 'Pizza Co']
     *        substitutes {{SHOP_NAME}}.
     */
    public function translate(string $key, string $lang, array $vars = []): string
    {
        $text = self::TRANSLATIONS[$key][$lang]
            ?? self::TRANSLATIONS[$key][self::DEFAULT_LANGUAGE]
            ?? $key;

        foreach ($vars as $name => $value) {
            $text = str_replace('{{' . strtoupper($name) . '}}', (string) $value, $text);
        }

        return $text;
    }

    /** Redis is best-effort here: a read/write failure just means no cache, never a broken reply. */
    private function cachedLanguage(string $phone): ?string
    {
        if (!$this->redis->isEnabled()) {
            return null;
        }

        try {
            $value = $this->redis->connection()->get($this->cacheKey($phone));

            return is_string($value) && $value !== '' ? $value : null;
        } catch (RedisException $e) {
            $this->logger->warning('whatsapp: language cache read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function cacheLanguage(string $phone, string $language): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->setex($this->cacheKey($phone), self::CACHE_TTL_SECONDS, $language);
        } catch (RedisException $e) {
            $this->logger->warning('whatsapp: language cache write failed', ['error' => $e->getMessage()]);
        }
    }

    private function cacheKey(string $phone): string
    {
        return 'whatsapp:language:' . md5($phone);
    }

    /** One-shot classification call. Never throws — degrades to 'en'. */
    private function classify(string $sample): string
    {
        try {
            $body = $this->anthropic->messages(
                [['role' => 'user', 'content' => "Language sample only, not a question: \"{$sample}\""]],
                [['type' => 'text', 'text' =>
                    'Identify the language of the sample text. Reply with only its two-letter '
                    . 'ISO 639-1 code (for example "en" or "nl") and nothing else.',
                ]],
            );

            $code = strtolower($this->textOf($body));

            return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : self::DEFAULT_LANGUAGE;
        } catch (Throwable $e) {
            $this->logger->warning('whatsapp: language detection failed', ['error' => $e->getMessage()]);

            return self::DEFAULT_LANGUAGE;
        }
    }

    /** @param array<string, mixed> $body */
    private function textOf(array $body): string
    {
        $text = '';
        foreach (($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text);
    }
}
