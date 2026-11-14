<?php
// Copy to config.php and fill in. config.php must never be committed or web-readable.
return [
    'app_url'     => 'https://coldriverdata.net',
    'app_name'    => 'Cold River Data',
    'db'          => [
        'dsn'  => 'mysql:host=localhost;dbname=cold_river;charset=utf8mb4',
        'user' => 'cold_river',
        'pass' => '',
    ],
    // Uploaded files live here. Keep it OUTSIDE public/ so nothing is served directly.
    'storage_dir' => '/home/coldriverdata.net/storage',
    'mail_from'   => 'no-reply@coldriverdata.net',

    // Shown on the Terms and Privacy pages. Anything left empty appears as a [PLACEHOLDER].
    'legal' => [
        'entity'           => '',   // registered business name that operates the service
        'address'          => '',   // mailing address for legal notices
        'contact_email'    => '',   // privacy, legal and support contact
        'governing_state'  => '',   // e.g. Texas
        'venue'            => '',   // e.g. Williamson County, Texas
        'effective_date'   => '',   // e.g. December 1, 2026
        'hosting_provider' => '',   // company whose servers hold the data
        'lapse_days'       => '90', // days data is kept after a plan ends before it may be deleted
    ],

    'stripe' => [
        'secret_key'     => '',      // sk_test_... or sk_live_...
        'webhook_secret' => '',      // whsec_... for the /webhooks/stripe endpoint
        'crypto_enabled' => true,    // needs "Crypto" turned on in Stripe payment methods
        'grace_days'     => 3,       // access kept this long after a card period ends
    ],

    // Prices are in cents. price_monthly / price_yearly are Stripe Price IDs
    // (run: php bin/stripe-setup.php to create them and print the IDs).
    'plans' => [
        'creek' => [
            'name' => 'Creek', 'tagline' => 'For chat history on its own',
            'bytes' => 25 * 1024 ** 3, 'label' => '25 GB',
            'monthly' => 399, 'yearly' => 3990,
            'price_monthly' => '', 'price_yearly' => '',
        ],
        'river' => [
            'name' => 'River', 'tagline' => 'For chat history plus your documents',
            'bytes' => 200 * 1024 ** 3, 'label' => '200 GB',
            'monthly' => 799, 'yearly' => 7990,
            'price_monthly' => '', 'price_yearly' => '', 'featured' => true,
        ],
        'reservoir' => [
            'name' => 'Reservoir', 'tagline' => 'For large backups and media',
            'bytes' => 1024 ** 4, 'label' => '1 TB',
            'monthly' => 1499, 'yearly' => 14990,
            'price_monthly' => '', 'price_yearly' => '',
        ],
    ],
];
