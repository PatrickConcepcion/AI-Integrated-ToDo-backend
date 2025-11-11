<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key
    |--------------------------------------------------------------------------
    |
    | Your OpenAI API key. Get yours at: https://platform.openai.com/api-keys
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | The GPT model to use. Options: gpt-5-nano, gpt-4o-mini, gpt-3.5-turbo
    |
    */

    'model' => env('OPENAI_MODEL', 'gpt-5-nano'),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    |
    | Maximum tokens for AI responses. Controls response length and cost.
    | Approximate conversion: 1 token ≈ 0.75 words
    | 500 tokens ≈ 375 words ≈ 2-3 paragraphs
    |
    */

    'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 500),

    /*
    |--------------------------------------------------------------------------
    | Temperature
    |--------------------------------------------------------------------------
    |
    | Controls randomness/creativity in responses (0.0 to 2.0)
    | 0.0 = Deterministic, factual
    | 0.7 = Balanced (recommended)
    | 1.0+ = Creative, varied
    |
    */

    'temperature' => 1,

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum seconds to wait for OpenAI API response
    |
    */

    'timeout' => 30,

];
