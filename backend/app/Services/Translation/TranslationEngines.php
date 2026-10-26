<?php

namespace App\Services\Translation;

use InvalidArgumentException;

/**
 * The translation engines a user can pick: "local" (Standard, on our server,
 * always there) and "azure" (Enhanced, only when Azure is configured).
 */
class TranslationEngines
{
    public const LOCAL = 'local';

    public const AZURE = 'azure';

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return AzureTranslator::isConfigured() ? [self::LOCAL, self::AZURE] : [self::LOCAL];
    }

    public function isAvailable(string $engine): bool
    {
        return in_array($engine, $this->available(), true);
    }

    public function translator(string $engine): Translator
    {
        return match ($engine) {
            self::LOCAL => app(LocalTranslator::class),
            self::AZURE => app(AzureTranslator::class),
            default => throw new InvalidArgumentException("Unknown translation engine [{$engine}]."),
        };
    }
}
