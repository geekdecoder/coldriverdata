<?php
declare(strict_types=1);

// Minimal Stripe client (no SDK): Checkout, billing portal and webhook handling.

class StripeError extends RuntimeException {}

function stripe_request(string $method, string $path, array $params = []): array
{
    $key = (string)cfg('stripe.secret_key');
    if ($key === '') throw new StripeError('Stripe is not configured yet.');
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $key . ':',
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($params) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new StripeError('Could not reach Stripe: ' . $err);
    $data = json_decode((string)$body, true) ?: [];
    if ($status >= 400) throw new StripeError($data['error']['message'] ?? ('Stripe error ' . $status));
    return $data;
}

/** Start a Checkout session. $method: card_monthly | card_yearly | crypto_yearly. Returns the redirect URL. */
function stripe_checkout_url(array $user, string $planKey, string $method): string
{
    $plan = plans()[$planKey] ?? null;
    if (!$plan) throw new StripeError('Unknown plan.');
    $p = [
        'client_reference_id' => $user['id'],
        'success_url' => url('/app/billing?paid=1'),
        'cancel_url' => url('/app/billing'),
        'metadata' => ['user_id' => $user['id'], 'plan' => $planKey],
    ];
    if ($user['stripe_customer_id']) $p['customer'] = $user['stripe_customer_id'];
    else $p['customer_email'] = $user['email'];

    if ($method === 'crypto_yearly') {
        if (!cfg('stripe.crypto_enabled')) throw new StripeError('Crypto payments are not turned on.');
        $p += [
            'mode' => 'payment',
            'payment_method_types' => ['crypto'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $plan['yearly'],
                    'product_data' => ['name' => cfg('app_name') . ' ' . $plan['name'] . ' — 12 months'],
                ],
            ]],
        ];
        $p['metadata']['months'] = 12;
        if (empty($p['customer'])) $p['customer_creation'] = 'always';
    } else {
        $priceId = $plan[$method === 'card_yearly' ? 'price_yearly' : 'price_monthly'] ?? '';
        if ($priceId === '') throw new StripeError('This plan has no Stripe price yet. Run bin/stripe-setup.php.');
        $p += [
            'mode' => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data' => ['metadata' => ['user_id' => $user['id'], 'plan' => $planKey]],
        ];
    }
    return stripe_request('POST', 'checkout/sessions', $p)['url'];
}

function stripe_portal_url(array $user): string
{
    return stripe_request('POST', 'billing_portal/sessions', [
        'customer' => $user['stripe_customer_id'],
        'return_url' => url('/app/billing'),
    ])['url'];
}

/** Verify the Stripe-Signature header. Returns the decoded event or null. */
function stripe_verify(string $payload, string $header, string $secret, int $tolerance = 300): ?array
{
    if ($secret === '') return null;
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $t = $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$t || !$sigs || abs(time() - (int)$t) > $tolerance) return null;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) return json_decode($payload, true);
    }
    return null;
}

function plan_for_price(string $priceId): ?string
{
    foreach (plans() as $key => $p) {
        if ($priceId !== '' && in_array($priceId, [$p['price_monthly'] ?? '', $p['price_yearly'] ?? ''], true)) return $key;
    }
    return null;
}

function stripe_apply_subscription(array $sub, ?int $userId = null): void
{
    $item = $sub['items']['data'][0] ?? [];
    $periodEnd = $sub['current_period_end'] ?? ($item['current_period_end'] ?? null);
    $plan = plan_for_price($item['price']['id'] ?? '') ?? ($sub['metadata']['plan'] ?? null);
    $userId = $userId ?? (isset($sub['metadata']['user_id']) ? (int)$sub['metadata']['user_id'] : null);
    $user = $userId
        ? q('SELECT * FROM users WHERE id = ?', [$userId])->fetch()
        : q('SELECT * FROM users WHERE stripe_subscription_id = ? OR stripe_customer_id = ?', [$sub['id'], $sub['customer']])->fetch();
    if (!$user || !$plan || !isset(plans()[$plan])) return;

    $status = $sub['status'] ?? 'active';
    $live = in_array($status, ['active', 'trialing', 'past_due'], true);
    $grace = $live ? (int)(cfg('stripe.grace_days') ?? 0) * 86400 : 0;
    $through = $periodEnd ? gmdate('Y-m-d H:i:s', (int)$periodEnd + $grace) : $user['paid_through'];
    if (in_array($status, ['incomplete', 'incomplete_expired'], true)) return;

    q('UPDATE users SET plan = ?, pay_method = ?, plan_status = ?, paid_through = ?, stripe_customer_id = ?, stripe_subscription_id = ? WHERE id = ?',
        [$plan, 'card', $status === 'trialing' ? 'active' : $status, $through, $sub['customer'], $sub['id'], $user['id']]);
}

function stripe_handle_event(array $event): void
{
    $o = $event['data']['object'] ?? [];
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $userId = (int)($o['metadata']['user_id'] ?? $o['client_reference_id'] ?? 0);
            $plan = $o['metadata']['plan'] ?? null;
            if (!$userId || !isset(plans()[$plan])) return;
            if (($o['mode'] ?? '') === 'subscription') {
                if (!empty($o['subscription'])) {
                    stripe_apply_subscription(stripe_request('GET', 'subscriptions/' . $o['subscription']), $userId);
                }
            } elseif (($o['payment_status'] ?? '') === 'paid') {
                $user = q('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
                if (!$user) return;
                $months = max(1, (int)($o['metadata']['months'] ?? 12));
                // Extend from the current expiry when renewing early on the same plan.
                $from = time();
                if ($user['plan'] === $plan && $user['paid_through']) {
                    $from = max($from, strtotime($user['paid_through'] . ' UTC'));
                }
                $through = gmdate('Y-m-d H:i:s', strtotime('+' . $months . ' months', $from));
                q('UPDATE users SET plan = ?, pay_method = ?, plan_status = ?, paid_through = ?, stripe_customer_id = COALESCE(?, stripe_customer_id) WHERE id = ?',
                    [$plan, 'crypto', 'active', $through, $o['customer'] ?? null, $userId]);
            }
            return;
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            stripe_apply_subscription($o);
            return;
        case 'invoice.payment_failed':
            if (!empty($o['customer'])) {
                q("UPDATE users SET plan_status = 'past_due' WHERE stripe_customer_id = ? AND pay_method = 'card'", [$o['customer']]);
            }
            return;
    }
}
