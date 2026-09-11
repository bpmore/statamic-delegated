<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resolution cache
    |--------------------------------------------------------------------------
    |
    | Every territory and assignment on the site is loaded as one map and kept
    | in the cache under a version stamp; any change to a territory or an
    | assignment bumps the stamp, so nothing stale is ever served. The TTL is
    | only a backstop for a change the addon did not make itself, such as a row
    | edited by hand. Seconds; null keeps the map until the next change.
    |
    */

    'cache' => [
        'store' => null,
        'ttl' => 3600,
    ],

];
