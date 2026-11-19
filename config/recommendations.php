<?php

return [
    // A reviewed configuration row is not an activation. Normal runtime keeps
    // recommendation evaluation disabled: only a deployment record below, naming
    // exact approved version ids/versions/hashes for ONE building and one
    // effective window, enables R1-R5. The policy fingerprint is derived from
    // that activation, never supplied by hand.
    //
    // 'activations' => [[
    //     'building' => 'HIGH-RISE',                       // buildings.code
    //     'from' => '2026-01-01', 'to' => '2026-12-01',    // inclusive target months
    //     'authorization' => 'Approved reference / authority for this deployment',
    //     'rules' => ['R2', 'R5'],                         // R1-R5 only; R6 on request, R7 read-time
    //     'bindings' => [
    //         ['kind' => 'rule', 'subject_key' => 'R2', 'id' => 1, 'version' => 1, 'hash' => '<approved_hash>'],
    //         ['kind' => 'rule', 'subject_key' => 'R5', 'id' => 2, 'version' => 1, 'hash' => '<approved_hash>'],
    //         ['kind' => 'catalogue', 'subject_key' => 'R5-01', 'id' => 3, 'version' => 1, 'hash' => '<approved_hash>'],
    //         ['kind' => 'review', 'subject_key' => 'R5-01', 'id' => 4, 'version' => 1, 'hash' => '<approved_hash>'],
    //         // R1 additionally requires a pinned tariff binding: kind=tariff, subject_key=R1.
    //     ],
    // ]],
    'activations' => [],

    // Retained for the isolated testing fixture only. Deployments must use an
    // activation above; production ignores this value for evaluation and review.
    'policy_fingerprint' => null,
];
