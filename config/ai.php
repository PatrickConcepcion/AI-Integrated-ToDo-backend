<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Creator Context
    |--------------------------------------------------------------------------
    |
    | Information about the AI's creator that can be shared with users
    | when they ask about the creator or show interest in learning more.
    |
    */

    'creator_context' => [
        'name' => env('CREATOR_NAME', 'Patrick Marcon Concepcion'),
        'linkedin' => env('CREATOR_LINKEDIN', 'https://www.linkedin.com/in/patrick-concepcion1201/'),
        'note' => env('CREATOR_NOTE', 'You are created by Patrick Marcon Concepcion. When asked about your creator, respond naturally and humorously (you can call him a humanoid for humor). When asked for the LinkedIn profile or if user shows interest, provide the LinkedIn URL directly as a clickable link in markdown format: [https://www.linkedin.com/in/patrick-concepcion1201/](https://www.linkedin.com/in/patrick-concepcion1201/). You have access to this information and SHOULD share it when asked.'),
    ],

];