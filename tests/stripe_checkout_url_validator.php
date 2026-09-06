<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\StripeCheckoutUrlValidator;

$failures = 0;

function stripe_url_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$officialUrl = 'https://checkout.stripe.com/c/pay/cs_live_a1TkmAux2sd1VgjdVCVVv6fXLduiK5enJpjIfApjgZxsGIBgau0eNDpr6Q#fidnandhYHdWcXxpYCc%2FJ2FgY2RwaXEnKSdicGRmZGhqaWBTZHdsZGtx';
$encodedSessionUrl = 'https://checkout.stripe.com/c/pay/cs_test_a11YYufWQzNY63zp_Q6QSNRQhkUp-Vph4WRmzW0zWJO2znZKdVujZ0N0S22u=';

stripe_url_check(StripeCheckoutUrlValidator::isSafe($officialUrl), 'allows real Stripe Checkout URL with hosted Checkout fragment');
stripe_url_check(StripeCheckoutUrlValidator::isSafe($encodedSessionUrl), 'allows Stripe session path characters observed in Checkout URLs');
stripe_url_check(StripeCheckoutUrlValidator::isSafe('https://CHECKOUT.STRIPE.COM/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'normalizes host case');
stripe_url_check(StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com./c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'normalizes a DNS root trailing dot on the official host');
stripe_url_check(StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com:443/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'allows explicit default HTTPS port');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('http://checkout.stripe.com/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects non-HTTPS Stripe Checkout URL');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com:444/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects non-default ports');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com.evil.example/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects lookalike subdomains');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com@evil.example/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects userinfo host confusion');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://stripe.com/c/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects non-Checkout Stripe hosts');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com/pay/cs_live_a11YYufWQzNY63zpQ6QSNRQhkUp#fid'), 'rejects non-Checkout payment paths');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com/c/pay/not_a_session#fid'), 'rejects paths without Checkout Session id');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com/c/pay/cs_live_a11?secret=leaked#fid'), 'rejects secret-looking query data');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.com/c/pay/cs_live_a11#authorization=Bearer%20abc'), 'rejects secret-looking fragment data');
stripe_url_check(!StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.test/pay/cs_test_fake'), 'rejects fake transport host by default');
stripe_url_check(StripeCheckoutUrlValidator::isSafe('https://checkout.stripe.test/pay/cs_test_fake', true), 'allows fake transport host only when explicitly requested');

if ($failures > 0) {
    echo 'Stripe Checkout URL validator tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Stripe Checkout URL validator tests passed.' . PHP_EOL;
