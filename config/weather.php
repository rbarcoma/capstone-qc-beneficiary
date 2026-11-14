<?php

return [
    // Shared pilot-complex reference point, not building-level measurements.
    // Historical capstone files do not retain coordinate provenance.
    'latitude' => (float) env('WEATHER_LATITUDE', 14.6468),
    'longitude' => (float) env('WEATHER_LONGITUDE', 121.0500),
    'location_name' => 'Quezon City Hall Complex',
    'coordinate_reference' => env('WEATHER_COORDINATE_REFERENCE', 'https://en.wikipedia.org/wiki/Quezon_City_Hall#coordinates'),
];
