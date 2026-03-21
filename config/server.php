<?php

return [
    'operator_id'  => env('OPERATOR_ID'),
    'name'         => env('SERVER_NAME_OVERRIDE'),   // SERVER_NAME is reserved by the HTTP server
    'domain'       => env('SERVER_DOMAIN'),
    'join_mode'    => env('SERVER_JOIN_MODE', 'open'),
    'private_key'  => env('SERVER_PRIVATE_KEY'),
];
