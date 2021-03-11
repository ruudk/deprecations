<?php

declare(strict_types=1);

namespace Doctrine\Deprecations;

use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_reduce;
use function basename;
use function debug_backtrace;
use function sprintf;
use function trigger_error;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const E_USER_DEPRECATED;

/**
 * Manages Deprecation logging in different ways.
 *
 * By default triggered exceptions are not logged, only the amount of
 * deprecations triggered can be queried with `Deprecation::getUniqueTriggeredDeprecationsCount()`.
 *
 * To enable different deprecation logging mechanisms you can call the
 * following methods:
 *
 *  - Uses trigger_error with E_USER_DEPRECATED
 *    \Doctrine\Deprecations\Deprecation::enableWithTriggerError();
 *
 *  - Uses @trigger_error with E_USER_DEPRECATED
 *    \Doctrine\Deprecations\Deprecation::enableWithSuppressedTriggerError();
 *
 *  - Sends deprecation messages via a PSR-3 logger
 *    \Doctrine\Deprecations\Deprecation::enableWithPsrLogger($logger);
 *
 * Packages that trigger deprecations should use the `trigger()` method.
 */
class Deprecation
{
    private const TYPE_NONE                     = 0;
    private const TYPE_TRIGGER_ERROR            = 1;
    private const TYPE_TRIGGER_SUPPRESSED_ERROR = 2;
    private const TYPE_PSR_LOGGER               = 3;

    /** @var int */
    private static $type = self::TYPE_NONE;

    /** @var LoggerInterface|null */
    private static $logger;

    /** @var array<string,bool> */
    private static $ignoredPackages = [];

    /** @var array<string,int> */
    private static $ignoredLinks = [];

    /** @var array<string,int> */
    private static $temporarilyIgnoredLinks = [];

    /** @var bool */
    private static $deduplication = true;

    /**
     * Trigger a deprecation for the given package, starting with given version.
     *
     * The link should point to a Github issue or Wiki entry detailing the
     * deprecation. It is additionally used to de-duplicate the trigger of the
     * same deprecation during a request.
     *
     * @param mixed $args
     */
    public static function trigger(string $package, string $link, string $message, ...$args): void
    {
        // Do not trigger this deprecation if it is temporarily ignored,
        // because it is expected to be called for 1 or more times.
        if (array_key_exists($link, self::$temporarilyIgnoredLinks)) {
            self::$temporarilyIgnoredLinks[$link]--;

            if (self::$temporarilyIgnoredLinks[$link] <= 0) {
                unset(self::$temporarilyIgnoredLinks[$link]);
            }

            return;
        }

        if (array_key_exists($link, self::$ignoredLinks)) {
            self::$ignoredLinks[$link]++;
        } else {
            self::$ignoredLinks[$link] = 1;
        }

        if (self::$deduplication === true && self::$ignoredLinks[$link] > 1) {
            return;
        }

        // do not move this condition to the top, because we still want to
        // count occcurences of deprecations even when we are not logging them.
        if (self::$type === self::TYPE_NONE) {
            return;
        }

        if (isset(self::$ignoredPackages[$package])) {
            return;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        $message = sprintf($message, ...$args);

        if (self::$type === self::TYPE_TRIGGER_ERROR) {
            $message .= sprintf(
                ' (%s:%d called by %s:%d, %s, package %s)',
                basename($backtrace[0]['file']),
                $backtrace[0]['line'],
                basename($backtrace[1]['file']),
                $backtrace[1]['line'],
                $link,
                $package
            );

            trigger_error($message, E_USER_DEPRECATED);
        } elseif (self::$type === self::TYPE_TRIGGER_SUPPRESSED_ERROR) {
            $message .= sprintf(
                ' (%s:%d called by %s:%d, %s, package %s)',
                basename($backtrace[0]['file']),
                $backtrace[0]['line'],
                basename($backtrace[1]['file']),
                $backtrace[1]['line'],
                $link,
                $package
            );

            @trigger_error($message, E_USER_DEPRECATED);
        } elseif (self::$type === self::TYPE_PSR_LOGGER) {
            $context = [
                'file' => $backtrace[0]['file'],
                'line' => $backtrace[0]['line'],
            ];

            $context['package'] = $package;
            $context['link']    = $link;

            self::$logger->notice($message, $context);
        }
    }

    public static function enableWithTriggerError(): void
    {
        self::$type = self::TYPE_TRIGGER_ERROR;
    }

    public static function enableWithSuppressedTriggerError(): void
    {
        self::$type = self::TYPE_TRIGGER_SUPPRESSED_ERROR;
    }

    public static function enableWithPsrLogger(LoggerInterface $logger): void
    {
        self::$type   = self::TYPE_PSR_LOGGER;
        self::$logger = $logger;
    }

    public static function withoutDeduplication(): void
    {
        self::$deduplication = false;
    }

    public static function disable(): void
    {
        self::$type          = self::TYPE_NONE;
        self::$logger        = null;
        self::$deduplication = true;

        foreach (self::$ignoredLinks as $link => $count) {
            self::$ignoredLinks[$link] = 0;
        }

        self::$temporarilyIgnoredLinks = [];
    }

    public static function ignorePackage(string $packageName): void
    {
        self::$ignoredPackages[$packageName] = true;
    }

    public static function ignoreDeprecations(string ...$links): void
    {
        foreach ($links as $link) {
            self::$ignoredLinks[$link] = 0;
        }
    }

    public static function ignoreDeprecationTemporarily(string $link, int $times = 1): void
    {
        self::$temporarilyIgnoredLinks[$link] = $times;
    }

    public static function getUniqueTriggeredDeprecationsCount(): int
    {
        return array_reduce(self::$ignoredLinks, static function (int $carry, int $count) {
            return $carry + $count;
        }, 0);
    }

    /**
     * Returns each triggered deprecation link identifier and the amount of occurrences.
     *
     * @return array<string,int>
     */
    public static function getTriggeredDeprecations(): array
    {
        return self::$ignoredLinks;
    }
}
