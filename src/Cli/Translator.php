<?php declare(strict_types=1);

namespace Multiapi\Arco\Cli;

final class Translator
{
    /** @var array<string, string> */
    private array $messages;
    /** @var array<string, string> */
    private array $fallback;

    public function __construct(string $locale, string $langDir)
    {
        $this->fallback = self::load($langDir . '/en.php');
        $this->messages = $locale === 'en' ? $this->fallback : self::load($langDir . "/$locale.php");
    }

    /** @param array<string, string|int> $params */
    public function trans(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $this->fallback[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** @return array<string, string> */
    private static function load(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        /** @var array<string, string> $messages */
        $messages = require $file;
        return $messages;
    }
}
