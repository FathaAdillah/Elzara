<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\I18n;

use Illuminate\Translation\Translator;

final class LocalizationResolver
{
    public const SUPPORTED = ['id', 'en'];

    public function __construct(private LocalizedStringRepository $repository, private Translator $translator) {}

    public function locale(?string $preferred): string
    {
        return in_array($preferred, self::SUPPORTED, true) ? $preferred : 'id';
    }

    public function value(string $key, string $group, ?string $locale = null, ?int $plural = null): string
    {
        $locale = $this->locale($locale);
        foreach ($locale === 'en' ? ['en', 'id'] : ['id'] as $candidate) {
            $value = $this->repository->find($key, $group, $candidate, $plural);
            if ($value !== null) {
                return $value;
            }
            $value = $this->translator->get($key, [], $candidate, false);
            if (is_string($value) && $value !== '' && $value !== $key) {
                return $value;
            }
        }

        return $key;
    }
}
