<?php

return [

    /*
    | System owner for SystemOwnerSeeder. Read from the environment at seed time only,
    | so no credentials live in the code or the repository. Leave unset to skip.
    */
    'owner' => [
        'name' => env('OWNER_NAME'),
        'username' => env('OWNER_USERNAME'),
        'email' => env('OWNER_EMAIL'),
        'password' => env('OWNER_PASSWORD'),
    ],

];
