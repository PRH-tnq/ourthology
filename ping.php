<?php
declare(strict_types=1);

/*
 * Phase 113: a tiny "is the site answering us directly?" check for
 * chunked_upload.js (window.ourthologySecurityCheck). The host's own bot
 * check ("Verifying…" / "waiting to verify") sometimes steps in front of
 * uploads and saves on iPads; when it does, the answer to this request is
 * its challenge page instead of the JSON below, and the uploader lets that
 * check finish in a hidden frame (a real page visit, so it can) before
 * carrying on. No session, no database -- as cheap as a request can be.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo '{"ok":true,"ourthology":"ping"}';
