<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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
    | Google (Gmail API for outbound mail)
    |--------------------------------------------------------------------------
    |
    | The client id and secret come from the OAuth client in the Google Cloud
    | project. The refresh token does NOT live here: it is minted at runtime by an
    | interactive consent and stored encrypted in the `settings` table, because a
    | deploy-time environment value cannot be produced by a browser sign-in.
    |
    | The redirect URI must match one registered on the OAuth client exactly,
    | including scheme, host, port and path.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/oauth/google/callback'),
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'revoke_uri' => 'https://oauth2.googleapis.com/revoke',
        'userinfo_uri' => 'https://www.googleapis.com/oauth2/v3/userinfo',

        // gmail.send is the narrowest scope that can send mail. It grants no read
        // access to the mailbox, which matters: the application never needs to
        // read anyone's email.
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.send',
            'openid',
            'email',
        ],
    ],

];
