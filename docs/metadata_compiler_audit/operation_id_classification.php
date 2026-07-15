<?php

/**
 * Manual classification of the operationId reconciliation GAPS (rev. 2).
 *
 * Keys are the generator's normalized route key "METHOD:/path/with/{}". Applied by
 * gen_operation_id_reconciliation.php to the route-only (R\S) and spec-only (S\R) gaps. The documented
 * ops (R∩S) need no classification — they inherit explicit opId or method-name fallback.
 *
 * resolution values:
 *   add     — route-only: a real JSON #[Route] endpoint missing from the legacy spec; document it in the
 *             new OpenAPI with the <ControllerShort><Method> id (-> lockfile).
 *   pending — route-only: a real endpoint but public-vs-internal exposure is undecided; held OUT of the
 *             spec + lockfile until the owner confirms, then reclassify to add (or exclude).
 *   exclude — route-only: not a public JSON API (test/util/HTML/binary); keep OUT of the spec + lockfile.
 *   stale   — spec-only: spec/client reference a route absent from compiled_routes (orphan or method
 *             drift); do NOT carry its operationId into the lockfile; remove after confirmation.
 *
 * This classification is a human decision over the 2026-07-15 snapshot; re-confirm if routes/spec move.
 */

return [
    /* ---------------- route-only: EXCLUDE (6) — not public JSON APIs ---------------- */
    'GET:/api/test'        => ['resolution' => 'exclude', 'reason' => 'dev/test endpoint (TestController::test), not a public API resource'],
    'GET:/test'            => ['resolution' => 'exclude', 'reason' => 'framework self-test endpoint (CoreUtilController::test), not a public API resource'],
    'POST:/core/update'    => ['resolution' => 'exclude', 'reason' => 'framework deploy/utility endpoint (CoreUtilController::coreUpdate), not a public API resource'],
    'GET:/qr'              => ['resolution' => 'exclude', 'reason' => 'returns HTML (getHtmlWithQr), not a JSON API'],
    'GET:/api/qr-fast'     => ['resolution' => 'exclude', 'reason' => 'returns QR payload/image (getFast), not a JSON API resource'],
    'GET:/api/image-resize'=> ['resolution' => 'exclude', 'reason' => 'returns image binary (resize), not a JSON API'],

    /* ---------------- route-only: ADD (22) — real endpoints missing from the spec ---------------- */
    'DELETE:/api/achievements/{}'              => ['resolution' => 'add', 'reason' => 'real #[Route] CRUD delete missing from spec'],
    'DELETE:/api/achievements/{}/pick'         => ['resolution' => 'add', 'reason' => 'real #[Route] action missing from spec'],
    'GET:/api/auth/landing-route'              => ['resolution' => 'add', 'reason' => 'real auth endpoint missing from spec'],
    'GET:/api/auth/reset'                      => ['resolution' => 'add', 'reason' => 'real auth endpoint missing from spec'],
    'GET:/api/dining-room/me'                  => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'GET:/api/map/locations/all'               => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'GET:/api/tickets/all'                     => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'GET:/api/tickets/{}'                      => ['resolution' => 'add', 'reason' => 'real resource endpoint (by user) missing from spec'],
    'GET:/api/tickets/{}/send'                 => ['resolution' => 'add', 'reason' => 'real ticket action missing from spec'],
    'GET:/api/updates'                         => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'GET:/api/vehicle-reports/{}/get-drivers'  => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'PATCH:/api/auth/add-access-rules'         => ['resolution' => 'add', 'reason' => 'METHOD DRIFT: route is PATCH but spec/client use POST (stale sibling below); add PATCH as canonical'],
    'POST:/api/achievements/{}/take'           => ['resolution' => 'add', 'reason' => 'real achievement action missing from spec'],
    'POST:/api/auth/fix-old-auth'              => ['resolution' => 'add', 'reason' => 'real auth migration endpoint missing from spec'],
    'POST:/api/dining-room/review'             => ['resolution' => 'add', 'reason' => 'real resource action missing from spec'],
    'POST:/api/news/all'                       => ['resolution' => 'add', 'reason' => 'real resource endpoint missing from spec'],
    'POST:/api/news/create'                    => ['resolution' => 'add', 'reason' => 'real resource action missing from spec'],
    'POST:/api/news/extract-from-json'         => ['resolution' => 'add', 'reason' => 'real resource action missing from spec'],
    'POST:/api/ticket/late'                    => ['resolution' => 'add', 'reason' => 'real ticket action missing from spec'],
    'POST:/api/updates/add'                    => ['resolution' => 'add', 'reason' => 'real resource action missing from spec'],
    'PUT:/api/tickets/{}/confirm'              => ['resolution' => 'add', 'reason' => 'real ticket action missing from spec'],
    'PUT:/api/tickets/{}/refusal'              => ['resolution' => 'add', 'reason' => 'real ticket action missing from spec'],

    /* ---------------- route-only: PENDING (4) — public-vs-internal undecided; OUT until owner confirms ---------------- */
    'GET:/api/import/tickets'                  => ['resolution' => 'pending', 'reason' => 'integration/import endpoint; public-vs-internal undecided -> held out of spec+lockfile until owner confirms'],
    'POST:/api/exchange-1c/employees'          => ['resolution' => 'pending', 'reason' => '1C integration endpoint; public-vs-internal undecided -> held out of spec+lockfile before exposing to LK client'],
    'POST:/api/import/access-rules/bulk'       => ['resolution' => 'pending', 'reason' => 'bulk import endpoint; public-vs-internal undecided -> held out of spec+lockfile until owner confirms'],
    'POST:/api/users/duplicate'                => ['resolution' => 'pending', 'reason' => 'admin action (deleteDuplicatesUsers); public-vs-internal undecided -> held out of spec+lockfile until owner confirms'],

    /* ---------------- spec-only: STALE (2) — spec/client reference absent/wrong routes ---------------- */
    'GET:/api/employees/documents/important/{}' => ['resolution' => 'stale', 'reason' => 'spec+client document a route ABSENT from compiled_routes (orphan); remove from spec/client after confirmation; do not carry opId'],
    'POST:/api/auth/add-access-rules'           => ['resolution' => 'stale', 'reason' => 'STALE METHOD: spec/client use POST but the route is PATCH (canonical sibling above); drop the POST entry, do not carry opId'],
];
