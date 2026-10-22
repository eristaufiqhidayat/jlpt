<?php

namespace App\Http\Middleware;

use App\Support\Portal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang sedang login harus memakai domain portalnya; bila tidak, keluar dan diarahkan ke domain yang benar. */
class EnsurePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! Portal::allows($user)) {
            $notice = Portal::wrongPortalNotice($user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('wrong_portal', $notice);
        }

        return $next($request);
    }
}
