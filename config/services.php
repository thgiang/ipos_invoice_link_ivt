<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | iPOS
    |--------------------------------------------------------------------------
    |
    | Fabi is the POS/CMS side (sales and VAT invoices); IVT is the inventory
    | side (stock-outs and recipes). No fallback values are declared on purpose
    | — credentials must live in .env, never in the repository.
    |
    */

    'fabi' => [
        'base_url' => env('FABI_BASE_URL', 'https://posapi.ipos.vn'),
        'email' => env('FABI_EMAIL'),
        'password' => env('FABI_PASSWORD'),
        'access_token' => env('FABI_ACCESS_TOKEN'),
    ],

    'ivt' => [
        'base_url' => env('IVT_BASE_URL', 'https://apiivt.ipos.vn'),
        'email' => env('IVT_EMAIL'),
        'password' => env('IVT_PASSWORD'),
        'access_token' => env('IVT_ACCESS_TOKEN'),
        'device_id' => env('IVT_DEVICE_ID'),
        'secret_key' => env('IVT_SECRET_KEY'),
    ],

    /*
     * MISA AMIS ke toan. Khong co endpoint dang nhap: token/cookie/context lay
     * tu phien trinh duyet dang mo va het han trong ngay, nen phai cap nhat lai
     * khi command bao 401.
     */
    'misa' => [
        'base_url' => env('MISA_BASE_URL', 'https://actapp.misa.vn'),
        'token' => env('MISA_TOKEN'),
        'cookie' => env('MISA_COOKIE'),
        'device_id' => env('MISA_DEVICE_ID'),
        'context' => env('MISA_CONTEXT'),
    ],

];
