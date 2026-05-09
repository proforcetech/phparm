<?php

namespace App\Support\Auth;

use RuntimeException;

/**
 * Thrown when a route or service action requires a fresh TOTP step-up
 * verification (within {@see StepUpService::FRESHNESS_SECONDS}) and the
 * caller does not have one. Caught by the route handler and surfaced as
 * 403 with `code: step_up_required` so the frontend can prompt for TOTP
 * and retry the request without forcing a full re-login.
 *
 * Distinct from {@see UnauthorizedException} — the user is authenticated
 * and may even hold the underlying permission; they just need to prove
 * physical possession of the TOTP device again.
 */
class StepUpRequiredException extends RuntimeException
{
}
