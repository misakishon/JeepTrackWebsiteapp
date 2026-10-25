<?php

// Local sample names only. IDs are retained so existing trip links do not change.
return [
    'routes' => [
        1 => ['legacy' => 'Route A - Express', 'route_name' => 'Batac–Laoag', 'origin' => 'Batac Terminal', 'destination' => 'Laoag Terminal'],
        2 => ['legacy' => 'Route B - Central', 'route_name' => 'Batac–Paoay', 'origin' => 'Batac Terminal', 'destination' => 'Paoay Terminal'],
        3 => ['legacy' => 'Route C - Loop', 'route_name' => 'Paoay–Laoag', 'origin' => 'Paoay Terminal', 'destination' => 'Laoag Terminal'],
        4 => ['legacy' => 'Route D - Coastal', 'route_name' => 'Laoag–San Nicolas', 'origin' => 'Laoag Terminal', 'destination' => 'San Nicolas Terminal'],
        5 => ['legacy' => 'Route E - Suburban', 'route_name' => 'San Nicolas–Batac', 'origin' => 'San Nicolas Terminal', 'destination' => 'Batac Terminal'],
    ],
    'stops' => [
        12 => ['legacy' => 'Downtown Terminal', 'route_id' => 4, 'stop_name' => 'Laoag Terminal', 'coordinate_source' => 1],
        13 => ['legacy' => 'Airport Terminal', 'route_id' => 4, 'stop_name' => 'San Nicolas Terminal', 'coordinate_source' => 2],
        14 => ['legacy' => 'North Station', 'route_id' => 5, 'stop_name' => 'San Nicolas Terminal', 'coordinate_source' => 2],
        15 => ['legacy' => 'South Station', 'route_id' => 5, 'stop_name' => 'Batac Terminal', 'coordinate_source' => 4],
    ],
];
