<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopee_affiliate' => [
        // Shopee Affiliate Open API (GraphQL). One endpoint per country; Malaysia by default.
        'endpoint' => env('SHOPEE_AFFILIATE_ENDPOINT', 'https://open-api.affiliate.shopee.com.my/graphql'),
        'currency' => env('SHOPEE_AFFILIATE_CURRENCY', 'RM'),
    ],

    'threads' => [
        'base_url' => env('THREADS_API_BASE_URL', 'https://graph.threads.com'),
        // A new post is checked this many times, this many seconds apart, before giving up for now.
        // Kept short so admin actions finish well inside PHP's time limit; queued jobs retry later.
        'status_checks' => (int) env('THREADS_STATUS_CHECKS', 6),
        'status_check_seconds' => (int) env('THREADS_STATUS_CHECK_SECONDS', 2),
        // Optional: the Meta app's ID and secret, for reading a post's text from its link (oEmbed).
        'app_id' => env('THREADS_APP_ID'),
        'app_secret' => env('THREADS_APP_SECRET'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Its own name rather than ANTHROPIC_BASE_URL, which tools such as Claude Code set in the
        // shell; a real environment variable would silently win over .env.
        'base_url' => env('ANTHROPIC_API_BASE_URL', 'https://api.anthropic.com'),
        // Used for accounts without their own model. Sonnet 5.5 or Haiku 5.5 cost less per post.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'timeout' => (float) env('ANTHROPIC_TIMEOUT', 120),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),
        // Shown in the admin model picker.
        'models' => [
            'claude-opus-5-5' => 'Claude Opus 5.5 (best writing, ~$4/$20 per M tokens)',
            'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (~$2/$10 per M tokens)',
            'claude-haiku-5-5' => 'Claude Haiku 5.5 (cheapest, ~$0.10/$0.50 per M tokens)',
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Where drafts are sent for approval: your private chat with the bot, or a group.
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        // Optional comma-separated Telegram user IDs allowed to approve; empty means anyone in the chat.
        'allowed_user_ids' => array_filter(explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', ''))),
        // Telegram sends this back in a header on every webhook call, proving the call is genuine.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    'campaigns' => [
        // Drafts are written during this daily window, spread evenly by each campaign's posts per day.
        'timezone' => env('CAMPAIGN_TIMEZONE', 'Asia/Kuala_Lumpur'),
        'window_start' => env('CAMPAIGN_WINDOW_START', '08:00'),
        'window_end' => env('CAMPAIGN_WINDOW_END', '22:00'),
    ],

    'recommendations' => [
        // Replies you post on other people's Threads posts, at most this many a day (spam safety).
        'daily_limit' => (int) env('RECOMMENDATION_DAILY_LIMIT', 8),
    ],

    'insights' => [
        // Posts published within this many days get a fresh metrics snapshot every day.
        'days' => (int) env('INSIGHTS_DAYS', 30),
    ],

    'facebook' => [
        'base_url' => env('FACEBOOK_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        // Graph API versions stay usable for about two years after release.
        'version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
    ],

];
