<?php

declare(strict_types=1);

return [

    /*
    | Lead platforms. key => connector class (implements App\Services\Leads\LeadSource).
    | Adding a platform is one class plus one line here.
    */
    'sources' => [
        'apollo' => \App\Services\Leads\Apollo\ApolloLeadSource::class,
    ],

    // People requested per search page. Search is free on Apollo; this only sets batch size.
    'page_size' => (int) env('LEADS_PAGE_SIZE', 25),

    // A profile is pulled at most once per this many minutes by the scheduler.
    'pull_interval_minutes' => (int) env('LEADS_PULL_INTERVAL_MINUTES', 50),
];
