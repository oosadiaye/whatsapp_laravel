<?php

declare(strict_types=1);

return [

    /*
    | Lead platforms. key => connector class (implements App\Services\Leads\LeadSource).
    | Adding a platform is one class plus one line here.
    */
    'sources' => [
        'apollo' => \App\Services\Leads\Apollo\ApolloLeadSource::class,
        'hunter' => \App\Services\Leads\Hunter\HunterLeadSource::class,
        'lusha' => \App\Services\Leads\Lusha\LushaLeadSource::class,
    ],

    // Apollo API base URL. Override to point at Apollo's sandbox or a local mock for end-to-end tests.
    'apollo_base_url' => env('APOLLO_BASE_URL', 'https://api.apollo.io/api/v1'),

    // Hunter and Lusha base URLs. Override to point at a mock for end-to-end tests.
    'hunter_base_url' => env('HUNTER_BASE_URL', 'https://api.hunter.io/v2'),
    'lusha_base_url' => env('LUSHA_BASE_URL', 'https://api.lusha.com/v3'),

    // People requested per search page. Search is free on Apollo; this only sets batch size.
    'page_size' => (int) env('LEADS_PAGE_SIZE', 25),

    // A profile is pulled at most once per this many minutes by the scheduler.
    'pull_interval_minutes' => (int) env('LEADS_PULL_INTERVAL_MINUTES', 50),
];
