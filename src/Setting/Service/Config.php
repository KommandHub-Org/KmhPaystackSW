<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\Setting\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class Config
{
    public const KEY = 'KmhPaystackSW.config.';

    /**
     * @param SystemConfigService $systemConfigService Shopware system config service.
     */
    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    /**
     * Retrieves a configuration value by key.
     *
     * @param string $key Configuration key (without prefix).
     * @param array|bool|float|int|string|null $default Default value if config is not set.
     * @param string|null $salesChannelId Optional sales channel ID.
     *
     * @return array|bool|float|int|string|null The configuration value or default.
     */
    public function get(string $key, array|bool|float|int|string|null $default = null, ?string $salesChannelId = null): array|bool|float|int|string|null
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        return $value ?? $default;
    }

    /**
     * Retrieves a configuration value by key as string.
     *
     * @param string $key Configuration key (without prefix).
     * @param string|null $salesChannelId Optional sales channel ID.
     *
     * @return string The configuration value as string.
     */
    public function getString(string $key, ?string $salesChannelId = null): string
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    /**
     * Retrieves a boolean configuration value by key.
     *
     * @param string $key Configuration key (without prefix).
     * @param string|null $salesChannelId Optional sales channel ID.
     *
     * @return bool The configuration value as boolean.
     */
    public function getBool(string $key, ?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::KEY . $key, $salesChannelId);
    }

    /**
     * Retrieves an array configuration value by key.
     *
     * @param string $key Configuration key (without prefix).
     * @param string|null $salesChannelId Optional sales channel ID.
     *
     * @return array The configuration value as array.
     */
    public function getArray(string $key, ?string $salesChannelId = null): array
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        if (!is_array($value)) {
            return [];
        }

        return $value;
    }
}
