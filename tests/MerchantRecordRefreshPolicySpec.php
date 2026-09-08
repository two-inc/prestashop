<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/cron.php';

/**
 * Ruling 19.4: the merchant record is refreshed by event, not by expiry. A
 * cache miss, an API-key or environment save, the nightly cron controller and
 * the Diagnostics button each replace it; the 24h TTL only bounds how stale it
 * can get when none of those fires. A failed refresh keeps last-known-good.
 */
final class MerchantRecordRefreshPolicySpec
{
    public static function runAll(): void
    {
        self::testClockGating();
        self::testExplicitRefreshBypassesTheClock();
        self::testFailedRefreshKeepsLastKnownGood();
        self::testFailedRefreshOnAnUnresolvedCacheStillBacksOff();
        self::testFulfilmentPrimeStopsOnceAFetchHasSucceeded();
        self::testFetchedMarkerSurvivesShopScopedStorage();
        self::testFulfilmentPrimeFetchesOnlyWhenNeverFetched();
        self::testKeyAndEnvironmentSaveTriggers();
        self::testNightlyRefreshRefetches();
        self::testNightlyRefreshLogSeverity();
        self::testAcceptedRefreshIsFloored();
        self::testInvalidationIsScopedToTheEditedContext();
        self::testCronTokenGuard();
        self::testValidationNeverMintsAToken();
        self::testCronTokenIsReadFromQueryPostOrHeader();
        self::testCronRejectionLogIsThrottled();
        self::testCronControllerResponses();
    }

    /** @return array<string,mixed> */
    private static function merchantResponse(array $terms = array(30, 60)): array
    {
        return array('http_status' => 200, 'available_terms' => $terms, 'due_in_days' => 30);
    }

    /**
     * A harness counting GETs of the merchant record. The FX warm-up rides the
     * same transport, so it is answered with a failure and left to back off.
     *
     * @param array<string,mixed>|callable $merchant
     */
    private static function module($merchant): object
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();

        $module = new class ($merchant) extends TwopaymentTestHarness {
            public int $merchantFetchCount = 0;
            /** @var array<string,mixed>|callable */
            private $merchant;

            public function __construct($merchant)
            {
                parent::__construct();
                $this->merchant = $merchant;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if (strpos((string) $endpoint, '/v1/merchant/') === 0) {
                    $this->merchantFetchCount++;
                    return is_callable($this->merchant) ? call_user_func($this->merchant, $this->merchantFetchCount) : $this->merchant;
                }

                return array('http_status' => 500);
            }

            public function saveGeneralForTest(): void
            {
                $this->saveTwoGeneralFormValues();
            }
        };

        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'merchant-1');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');

        return $module;
    }

    /** @param int[]|null $terms null stores '' - nothing serveable */
    private static function primeCache(?array $terms, int $checkedOn, bool $fetched = false): void
    {
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, $terms === null ? '' : json_encode($terms));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, $checkedOn);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, $fetched ? '0' : '');
    }

    /**
     * The clock-gated read path rides the 24h backstop once a fetch has succeeded, whatever it
     * carried, and the short backoff until one has - a shop that came up cold does not wait a day,
     * and a merchant whose record carries no terms is not fetched on every render forever.
     */
    private static function testClockGating(): void
    {
        $now = time();
        // [terms, checkedOn, fetched, expected fetches, description]
        $cases = array(
            array(array(30), $now, true, 0, 'a freshly checked record must not be refetched'),
            array(array(30), $now - 901, true, 0, 'the old 15-minute TTL must no longer trigger a refetch'),
            array(array(30), $now - 86401, true, 1, 'the 24h backstop must still expire the record'),
            array(null, $now, false, 0, 'a never-fetched record must not refetch on every render'),
            array(null, $now - 301, false, 1, 'a never-fetched record must be retried on the short backoff'),
            array(null, 0, false, 1, 'a never-checked record must be fetched on the first refresh point'),
            array(null, $now - 301, true, 0, 'a fetched record with no terms payload rides the backstop, not the backoff'),
            array(null, $now - 86401, true, 1, 'a fetched record with no terms payload still expires on the backstop'),
        );

        foreach ($cases as $case) {
            list($terms, $checkedOn, $fetched, $expected, $description) = $case;
            $module = self::module(self::merchantResponse());
            self::primeCache($terms, $checkedOn, $fetched);

            $module->getMerchantAvailableTerms(true);

            TinyAssert::same($expected, $module->merchantFetchCount, $description);
        }
    }

    private static function testExplicitRefreshBypassesTheClock(): void
    {
        $module = self::module(self::merchantResponse(array(45)));
        self::primeCache(array(30), time());

        TinyAssert::true($module->refreshMerchantRecord(), 'an explicit refresh must report success');
        TinyAssert::same(1, $module->merchantFetchCount, 'an explicit refresh must fetch even with a fresh clock');
        TinyAssert::same(array(45), $module->getMerchantAvailableTerms(false), 'a successful refresh must replace the cached record');
    }

    private static function testFailedRefreshKeepsLastKnownGood(): void
    {
        $module = self::module(array('http_status' => 503));
        self::primeCache(array(30), time());

        TinyAssert::false($module->refreshMerchantRecord(), 'a failed refresh must report failure');
        TinyAssert::same(array(30), $module->getMerchantAvailableTerms(false), 'a failed refresh must leave the cached record in place');
    }

    /**
     * The failure rollback is only correct for a RESOLVED record, whose due window is the full
     * TTL. Applying it to an unresolved one left `TS + BACKOFF` already in the past, so a cold
     * cache with a failing API went due on every single checkout render - a blocking HTTP call
     * per page view, reachable from an API-key save whose refetch then failed.
     */
    private static function testFailedRefreshOnAnUnresolvedCacheStillBacksOff(): void
    {
        $module = self::module(array('http_status' => 503));
        self::primeCache(null, 0);

        TinyAssert::false($module->refreshMerchantRecord(), 'the seeding refresh must fail');
        TinyAssert::same(1, $module->merchantFetchCount, 'the seeding refresh must have attempted a fetch');

        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(
            1,
            $module->merchantFetchCount,
            'a failed refresh on an unresolved cache must not leave the record due immediately'
        );
    }

    /**
     * The fulfilment prime is keyed on a fetch having SUCCEEDED, not on the terms payload: a
     * backend that legitimately omits `available_terms` would otherwise refetch on every
     * fulfilment forever.
     */
    private static function testFulfilmentPrimeStopsOnceAFetchHasSucceeded(): void
    {
        $cases = array(
            array(array('http_status' => 200, 'due_in_days' => 30), false, 'a success with no available_terms must still count as fetched'),
            array(array('http_status' => 200, 'available_terms' => array(30)), false, 'a success with terms counts as fetched'),
            array(array('http_status' => 503), true, 'a failed fetch must NOT count as fetched'),
        );

        foreach ($cases as $case) {
            list($response, $stillUnfetched, $description) = $case;
            $module = self::module($response);
            self::primeCache(null, 0);
            Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);

            TinyAssert::false($module->hasFetchedMerchantRecord(), 'a cold cache has never fetched');
            $module->refreshMerchantRecord();
            TinyAssert::same($stillUnfetched, !$module->hasFetchedMerchantRecord(), $description);
        }

        // THE case the fulfilment prime turns on: a success carrying no `available_terms` leaves
        // the payload predicate false forever, so the prime must key on the fetch, not the payload.
        $module = self::module(array('http_status' => 200, 'due_in_days' => 30));
        self::primeCache(null, 0);
        Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
        $module->refreshMerchantRecord();

        TinyAssert::same(array(), $module->getMerchantAvailableTerms(false), 'a success without available_terms leaves no serveable payload');
        TinyAssert::true(
            $module->hasFetchedMerchantRecord(),
            'that same success must still count as fetched, or the fulfilment prime refetches forever'
        );

        // A key rotation has to reset that, or the new merchant inherits the old one's
        // "already fetched" and fulfilment reads a flag belonging to nobody.
        $module->invalidateMerchantAvailableTerms();
        TinyAssert::false(
            $module->hasFetchedMerchantRecord(),
            'invalidating the record must reset the fetched marker, not just zero the flag'
        );
    }

    /**
     * A key or environment change means the cached record describes a merchant this shop is no
     * longer, so it is dropped and refetched rather than left to be served if the refetch fails.
     */
    private static function testKeyAndEnvironmentSaveTriggers(): void
    {
        $cases = array(
            array('test-api-key', 'staging', 0, 'a save changing neither the key nor the environment must not refetch'),
            array('rotated-api-key', 'staging', 1, 'saving a new API key must refetch'),
            array('test-api-key', 'production', 1, 'saving a new environment must refetch'),
        );

        foreach ($cases as $case) {
            list($apiKey, $environment, $expected, $description) = $case;
            $module = self::module(self::merchantResponse());
            Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
            self::primeCache(array(30), time());

            Tools::setTestValue('PS_TWO_ENVIRONMENT', $environment);
            Tools::setTestValue('PS_TWO_TITLE_1', 'Two title');
            Tools::setTestValue('PS_TWO_SUB_TITLE_1', 'Two subtitle');
            Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
            Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
            $module->saveGeneralForTest();

            TinyAssert::same($expected, $module->merchantFetchCount, $description);
        }
    }

    private static function testNightlyRefreshRefetches(): void
    {
        $module = self::module(self::merchantResponse(array(60)));
        self::primeCache(array(30), time());

        TinyAssert::same(
            Twopayment::CRON_STATUS_REFRESHED,
            $module->runTwoNightlyRefresh(),
            'the nightly refresh must report the record replaced'
        );
        TinyAssert::same(array(60), $module->getMerchantAvailableTerms(false), 'the nightly refresh must replace the cached record');
    }

    /** Only a fetch that was attempted and failed is worth a warning; a shop with no key yet is not. */
    private static function testNightlyRefreshLogSeverity(): void
    {
        // [api key, merchant response, expected status, expected log severity, description]
        $cases = array(
            array('test-api-key', self::merchantResponse(), Twopayment::CRON_STATUS_REFRESHED, 1, 'a refreshed record is informational'),
            array('test-api-key', array('http_status' => 503), Twopayment::CRON_STATUS_FAILED, 2, 'a failed fetch is a warning'),
            array('', self::merchantResponse(), Twopayment::CRON_STATUS_UNCONFIGURED, 1, 'a shop with no API key is informational, not a nightly warning'),
        );

        foreach ($cases as $case) {
            list($apiKey, $response, $expectStatus, $expectSeverity, $description) = $case;
            $module = self::module($response);
            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
            Configuration::updateValue(Twopayment::CONFIG_CRON_TOKEN, 'good-token');
            Configuration::deleteByName(Twopayment::CONFIG_CRON_LAST_RUN_TS);
            Tools::setTestValue('token', 'good-token');
            PrestaShopLogger::reset();

            $controller = new class () extends TwopaymentCronModuleFrontController {
                /** @var array<string,mixed>|null */
                public $captured = null;

                protected function respond($status, array $payload)
                {
                    $this->captured = array('status' => (int) $status, 'payload' => $payload);
                    throw new StubCronResponded();
                }
            };
            $controller->module = $module;
            try {
                $controller->postProcess();
            } catch (StubCronResponded $e) {
                // The response is the end of the request; captured above.
            }

            TinyAssert::same($expectStatus, $controller->captured['payload']['status'], $description . ' (status)');
            $severity = null;
            foreach (PrestaShopLogger::$logs as $log) {
                if (strpos($log['message'], 'Nightly refresh ran') !== false) {
                    $severity = $log['severity'];
                }
            }
            TinyAssert::same($expectSeverity, $severity, $description);
        }
    }

    /**
     * The token holder must not be able to drive one blocking outbound GET per request, so an
     * ACCEPTED refresh is floored; a once-a-day cron never reaches it.
     */
    private static function testAcceptedRefreshIsFloored(): void
    {
        $module = self::module(self::merchantResponse());
        self::primeCache(array(30), time());
        Configuration::deleteByName(Twopayment::CONFIG_CRON_LAST_RUN_TS);

        TinyAssert::same(
            Twopayment::CRON_STATUS_REFRESHED,
            $module->runTwoNightlyRefresh(),
            'the first accepted call refreshes'
        );
        TinyAssert::same(1, $module->merchantFetchCount, 'the first accepted call fetches');

        TinyAssert::same(
            Twopayment::CRON_STATUS_THROTTLED,
            $module->runTwoNightlyRefresh(),
            'a second call inside the floor must be throttled'
        );
        TinyAssert::same(1, $module->merchantFetchCount, 'a throttled call must do no outbound work');

        Configuration::updateValue(
            Twopayment::CONFIG_CRON_LAST_RUN_TS,
            time() - Twopayment::CRON_MIN_REFRESH_INTERVAL - 1
        );
        TinyAssert::same(
            Twopayment::CRON_STATUS_REFRESHED,
            $module->runTwoNightlyRefresh(),
            'a call after the floor refreshes again'
        );
        TinyAssert::same(2, $module->merchantFetchCount, 'that call fetches');
    }

    /** No shop under the rotated scope may still read the old record through the cascade; no shop outside it may lose its own. */
    private static function testInvalidationIsScopedToTheEditedContext(): void
    {
        // [context, context id, shops that must read unfetched, shops that must still read fetched, description]
        $cases = array(
            array(Shop::CONTEXT_SHOP, 1, array(1), array(2, 3), 'a shop with no row of its own must not keep reading the global row after its rotation'),
            array(Shop::CONTEXT_SHOP, 2, array(2), array(1, 3), 'a shop with its own row must not fall through to the global row after its rotation'),
            array(Shop::CONTEXT_GROUP, 1, array(1, 2), array(3), 'a group rotation must reach the shop rows under it and no other group'),
            array(Shop::CONTEXT_ALL, null, array(1, 2, 3), array(), 'an all-shops rotation must reach every shop row'),
        );

        foreach ($cases as $case) {
            list($context, $id, $unfetched, $fetched, $description) = $case;
            $module = self::module(self::merchantResponse());
            StubStore::$multistore = true;
            StubStore::$shops = array(1 => 1, 2 => 1, 3 => 2);
            StubStore::$configuration[Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED] = '1';
            StubStore::$configurationShop[2][Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED] = '1';
            StubStore::$configurationShop[3][Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED] = '1';

            Shop::setContext($context, $id);
            $module->invalidateMerchantAvailableTerms();

            foreach ($unfetched as $idShop) {
                Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
                TinyAssert::false($module->hasFetchedMerchantRecord(), $description . ' (shop ' . $idShop . ' fetched marker)');
                TinyAssert::false($module->isMerchantInvoiceDistributed(), $description . ' (shop ' . $idShop . ' must fail closed)');
            }
            foreach ($fetched as $idShop) {
                Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
                TinyAssert::true($module->hasFetchedMerchantRecord(), $description . ' (sibling shop ' . $idShop . ' fetched marker)');
                TinyAssert::true($module->isMerchantInvoiceDistributed(), $description . ' (sibling shop ' . $idShop . ' flag)');
            }
            StubStore::reset();
        }
    }

    private static function testCronTokenGuard(): void
    {
        $module = self::module(self::merchantResponse());
        $token = $module->getTwoCronToken();

        TinyAssert::true(strlen($token) >= 32, 'a minted token must not be guessable');
        TinyAssert::same($token, $module->getTwoCronToken(), 'the token must be stable once minted');

        $cases = array(
            array($token, true, 'the stored token must be accepted'),
            array('', false, 'an empty token must be refused'),
            array(null, false, 'a missing token must be refused'),
            array(strrev($token), false, 'a wrong token must be refused'),
            array(substr($token, 0, -1), false, 'a truncated token must be refused'),
        );

        foreach ($cases as $case) {
            list($candidate, $expected, $description) = $case;
            TinyAssert::same($expected, $module->isTwoCronTokenValid($candidate), $description);
        }
    }

    /**
     * The endpoint is public, so validating a presented token must never be the thing that
     * creates one - that would let an unauthenticated GET mint the shop's secret.
     */
    private static function testValidationNeverMintsAToken(): void
    {
        $module = self::module(self::merchantResponse());
        Configuration::deleteByName(Twopayment::CONFIG_CRON_TOKEN);

        TinyAssert::false($module->isTwoCronTokenValid('anything'), 'no stored token means nothing validates');
        TinyAssert::false(
            Configuration::hasKey(Twopayment::CONFIG_CRON_TOKEN),
            'validating a presented token must not mint one'
        );
    }

    private static function testCronTokenIsReadFromQueryPostOrHeader(): void
    {
        $cases = array(
            array('from-query', null, 'from-query', 'a token in the query string or POST body is read'),
            array('', 'from-header', 'from-header', 'the X-Two-Cron-Token header is read when no field is present'),
            array('from-query', 'from-header', 'from-query', 'an explicit field wins over the header'),
            array('', null, '', 'no token presented at all reads as empty'),
        );

        foreach ($cases as $case) {
            list($field, $header, $expected, $description) = $case;
            $module = self::module(self::merchantResponse());
            Tools::setTestValue('token', $field);
            unset($_SERVER['HTTP_X_TWO_CRON_TOKEN']);
            if ($header !== null) {
                $_SERVER['HTTP_X_TWO_CRON_TOKEN'] = $header;
            }

            TinyAssert::same($expected, $module->readTwoCronTokenFromRequest(), $description);
        }
        unset($_SERVER['HTTP_X_TWO_CRON_TOKEN']);
    }

    /** The URL is public, so an unthrottled log line per rejected hit is a scanner-triggered flood. */
    private static function testCronRejectionLogIsThrottled(): void
    {
        $module = self::module(self::merchantResponse());
        Configuration::deleteByName(Twopayment::CONFIG_CRON_REJECT_LOG_TS);

        TinyAssert::true($module->logTwoCronRejection(), 'the first rejection is logged');
        TinyAssert::false($module->logTwoCronRejection(), 'an immediate second rejection is not logged again');

        Configuration::updateValue(
            Twopayment::CONFIG_CRON_REJECT_LOG_TS,
            time() - Twopayment::CRON_REJECT_LOG_INTERVAL - 1
        );
        TinyAssert::true($module->logTwoCronRejection(), 'a rejection after the interval is logged again');
    }

    /**
     * Multistore writes Configuration shop-scoped, and core's Configuration::hasKey() reads only
     * the bucket its arguments name - with none, the GLOBAL one, with no cascade. A predicate
     * built on a bare hasKey() therefore answers false forever on such an install, which for the
     * fulfilment prime means a blocking refresh on EVERY fulfilment.
     */
    private static function testFetchedMarkerSurvivesShopScopedStorage(): void
    {
        foreach (array(false, true) as $multistore) {
            $label = $multistore ? 'multistore' : 'single shop';
            $module = self::module(self::merchantResponse());
            self::primeCache(null, 0);
            Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
            StubStore::$multistore = $multistore;

            TinyAssert::false($module->hasFetchedMerchantRecord(), $label . ': a cold cache has never fetched');
            $module->refreshMerchantRecord();
            TinyAssert::true(
                $module->hasFetchedMerchantRecord(),
                $label . ': a succeeded fetch must be visible however Configuration scoped the write'
            );

            StubStore::$multistore = false;
        }
    }

    /**
     * The prime guard itself. The order-status hook that CALLS it stays uncovered - that needs a
     * full fulfilment transition - so this pins the fetch/no-fetch decision only.
     */
    private static function testFulfilmentPrimeFetchesOnlyWhenNeverFetched(): void
    {
        $cases = array(
            array(false, true, 1, 'a never-fetched record must be primed'),
            array(true, false, 0, 'a record already fetched must not be re-primed'),
        );

        foreach ($cases as $case) {
            list($alreadyFetched, $expectAttempt, $expectFetches, $description) = $case;
            $module = self::module(self::merchantResponse());
            self::primeCache(null, 0);
            if ($alreadyFetched) {
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, 0);
            } else {
                Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
            }

            TinyAssert::same($expectAttempt, $module->primeMerchantRecordForFulfilment(), $description);
            TinyAssert::same($expectFetches, $module->merchantFetchCount, $description . ' (wire calls)');
        }
    }

    /**
     * The controller's own contract: what a caller gets, and that a rejected call is the only one
     * that reaches the reject log.
     */
    private static function testCronControllerResponses(): void
    {
        $cases = array(
            array('bad-token', array('http_status' => 200, 'available_terms' => array(30)), 403, false, true,
                'a wrong token is refused and logged as a rejection'),
            array('good-token', array('http_status' => 200, 'available_terms' => array(30)), 200, true, false,
                'a valid token refreshes and reports success'),
            array('good-token', array('http_status' => 503), 503, false, false,
                'a failed refresh answers non-2xx, so the crontab exit code shows a revoked key'),
            array('no-key', array('http_status' => 200, 'available_terms' => array(30)), 200, true, false,
                'a shop with no API key yet answers 200 - nothing to fetch is not a failure'),
        );

        foreach ($cases as $case) {
            list($token, $response, $expectStatus, $expectSuccess, $expectRejectLogged, $description) = $case;
            $module = self::module($response);
            self::primeCache(array(30), time(), true);
            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $token === 'no-key' ? '' : 'test-api-key');
            Tools::setTestValue('token', $token === 'no-key' ? 'good-token' : $token);
            Configuration::deleteByName(Twopayment::CONFIG_CRON_LAST_RUN_TS);
            Configuration::deleteByName(Twopayment::CONFIG_CRON_REJECT_LOG_TS);
            Configuration::updateValue(Twopayment::CONFIG_CRON_TOKEN, 'good-token');

            $controller = new class () extends TwopaymentCronModuleFrontController {
                /** @var array<string,mixed>|null */
                public $captured = null;

                protected function respond($status, array $payload)
                {
                    $this->captured = array('status' => (int) $status, 'payload' => $payload);
                    throw new StubCronResponded();
                }
            };
            $controller->module = $module;

            try {
                $controller->postProcess();
            } catch (StubCronResponded $e) {
                // The response is the end of the request; captured above.
            }

            TinyAssert::same($expectStatus, $controller->captured['status'], $description);
            TinyAssert::same($expectSuccess, $controller->captured['payload']['success'], $description . ' (success flag)');
            TinyAssert::same(
                $expectRejectLogged,
                Configuration::get(Twopayment::CONFIG_CRON_REJECT_LOG_TS) !== false,
                $description . ' (reject log)'
            );
        }

        // Second accepted call inside the floor: 429, and no outbound work.
        $module = self::module(self::merchantResponse());
        self::primeCache(array(30), time());
        Configuration::updateValue(Twopayment::CONFIG_CRON_TOKEN, 'good-token');
        Configuration::updateValue(Twopayment::CONFIG_CRON_LAST_RUN_TS, time());
        Tools::setTestValue('token', 'good-token');

        $controller = new class () extends TwopaymentCronModuleFrontController {
            /** @var array<string,mixed>|null */
            public $captured = null;

            protected function respond($status, array $payload)
            {
                $this->captured = array('status' => (int) $status, 'payload' => $payload);
                throw new StubCronResponded();
            }
        };
        $controller->module = $module;

        try {
            $controller->postProcess();
        } catch (StubCronResponded $e) {
            // As above.
        }

        TinyAssert::same(429, $controller->captured['status'], 'a call inside the floor is refused');
        TinyAssert::same(0, $module->merchantFetchCount, 'a throttled call must do no outbound work');
    }
}
