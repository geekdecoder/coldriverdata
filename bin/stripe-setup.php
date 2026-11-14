<?php
// Creates a Stripe product and monthly + yearly prices for each plan in config.php,
// then prints the Price IDs to paste into config.php. Safe to run in test mode first.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/stripe.php';

foreach (plans() as $key => $p) {
    $product = stripe_request('POST', 'products', ['name' => cfg('app_name') . ' ' . $p['name'], 'metadata' => ['plan' => $key]]);
    foreach (['monthly' => 'month', 'yearly' => 'year'] as $field => $interval) {
        $price = stripe_request('POST', 'prices', [
            'product' => $product['id'], 'currency' => 'usd', 'unit_amount' => $p[$field],
            'recurring' => ['interval' => $interval], 'metadata' => ['plan' => $key],
        ]);
        echo "$key  price_$field => '{$price['id']}'\n";
    }
}
