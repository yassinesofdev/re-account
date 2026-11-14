<?php

/*
| Organization subdomains.
|
| Orgs will be served at `<org-slug>.<domain>` (products as paths: /intelligence,
| /roleplay, /lms), so an org slug must be DNS-safe and must never be one of the
| platform names below. Checked by App\Rules\OrgSlug (explicit slugs → 422) and
| OrgService::uniqueSlug (auto-generated slugs get a numeric suffix instead).
|
| Lowercase, alphabetical within each group, no duplicates across groups.
| Expand before launch.
*/

return [

    'reserved' => [

        // Infrastructure, DNS, mail, environments
        'admin-api', 'analytics', 'api', 'apis', 'assets', 'autoconfig', 'autodiscover',
        'backup', 'beta', 'cdn', 'cpanel', 'demo', 'dev', 'dns', 'files', 'ftp', 'git',
        'go', 'graphql', 'imap', 'img', 'images', 'localhost', 'm', 'mail', 'media', 'metrics',
        'mobile', 'monitor', 'monitoring', 'mx', 'ns', 'ns1', 'ns2', 'pop', 'pop3', 'preview',
        'prod', 'production', 'proxy', 'qa', 'sandbox', 'server', 'sftp', 'smtp', 'ssh',
        'ssl', 'stage', 'staging', 'static', 'stats', 'status', 'storage', 'test',
        'testing', 'uploads', 'uat', 'vpn', 'webhook', 'webhooks', 'webmail', 'ws', 'www',

        // Auth, account, billing
        'account', 'accounts', 'auth', 'authentication', 'authorization', 'billing',
        'callback', 'checkout', 'id', 'identity', 'invite', 'invites', 'invoice',
        'invoices', 'login', 'logout', 'me', 'my', 'oauth', 'password', 'pay', 'payment',
        'payments', 'plans', 'pricing', 'profile', 'register', 'reset', 'settings',
        'signin', 'signout', 'signup', 'sso', 'subscribe', 'subscription',
        'subscriptions', 'user', 'users', 'verify',

        // Communication, support
        'ai', 'assistant', 'bot', 'bots', 'chat', 'community', 'contact', 'customers',
        'docs', 'email', 'faq', 'feedback', 'forum', 'help', 'helpdesk', 'inbox', 'info',
        'messages', 'news', 'newsletter', 'notifications', 'spam', 'support', 'ticket',
        'tickets',

        // Company, legal, operations
        'about', 'abuse', 'admin', 'administrator', 'blog', 'careers', 'company',
        'compliance', 'hostmaster', 'hr', 'internal', 'investors', 'job', 'jobs', 'legal',
        'marketing', 'no-reply', 'noreply', 'partners', 'postmaster', 'press', 'privacy',
        'root', 'sales', 'security', 'staff', 'superadmin', 'sysadmin', 'system', 'team',
        'terms', 'webmaster',

        // Products and platform
        'academy', 'app', 'apps', 'console', 'dashboard', 'data', 'data-admin',
        'developer', 'developers', 'home', 'intel', 'intelligence', 'learn', 'lms',
        'manager', 'market', 'marketplace', 'org', 'orgs', 'organization',
        'organizations', 'platform', 'portal', 'reports', 'roleplay', 'search',
        'shop', 'slug-availability', 'store', 'studio', 'workspace', // slug-availability: an API route name

        // Brand, regulators, generic
        'dld', 'dubai', 'example', 'null', 'official', 're', 're-account', 're-data',
        're-intel', 're-root', 'rera', 'undefined',

    ],

];
