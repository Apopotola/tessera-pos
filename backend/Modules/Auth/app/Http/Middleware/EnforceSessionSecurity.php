<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Services\PasswordService;
use Modules\Settings\Services\SettingsService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session rules on every API call from a signed-in browser session:
 *  - back office: signed out after Settings → Staff → session timeout without activity
 *    (not on computers where "keep me signed in" was ticked);
 *  - till: while the screen is locked only unlocking, signing out and the till context work;
 *  - back office: a forced password change comes before anything else.
 */
class EnforceSessionSecurity
{
    public const VIA = 'auth.via';

    public const REMEMBER = 'auth.remember';

    public const LAST_SEEN = 'auth.last_seen';

    public const TILL_LOCKED = 'till.locked';

    private const ALWAYS_ALLOWED = ['api.v1.auth.logout', 'api.v1.auth.me', 'api.v1.ping', 'api.v1.settings.public', 'api.v1.settings.files'];

    private const WHILE_LOCKED = ['api.v1.auth.till.unlock', 'api.v1.organisation.till-context'];

    private const WHILE_PASSWORD_DUE = ['api.v1.auth.password', 'api.v1.authorization.menus.index', 'api.v1.settings.app'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PasswordService $passwords,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! ($user = Auth::guard('web')->user())) {
            return $next($request);
        }

        $session = $request->session();
        $via = $session->get(self::VIA);
        $allowed = fn (array $names) => $request->routeIs(...self::ALWAYS_ALLOWED, ...$names);

        if ($via === 'password' && ! $session->get(self::REMEMBER)) {
            $minutes = (int) $this->settings->get('staff.session_timeout_minutes');
            $last = (int) $session->get(self::LAST_SEEN, now()->timestamp);
            if ($minutes > 0 && now()->timestamp - $last > $minutes * 60) {
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();

                return $this->refuse("You were signed out after {$minutes} minutes without activity.", 401, 'idle');
            }
        }
        $session->put(self::LAST_SEEN, now()->timestamp);

        if ($via === 'pin' && $session->get(self::TILL_LOCKED) && ! $allowed(self::WHILE_LOCKED)) {
            return $this->refuse('This till is locked. Enter your PIN to continue.', 423, 'locked');
        }

        if ($via === 'password' && $this->passwords->mustChange($user) && ! $allowed(self::WHILE_PASSWORD_DUE)) {
            return $this->refuse('Change your password to continue.', 403, 'password_change_required');
        }

        return $next($request);
    }

    private function refuse(string $message, int $status, string $reason): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'statusCode' => $status, 'errors' => ['session' => [$reason]]], $status);
    }
}
