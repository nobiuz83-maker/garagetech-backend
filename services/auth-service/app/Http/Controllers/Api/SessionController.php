<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function store(LoginRequest $request): Response
    {
        if (! Auth::attempt($request->validated())) {
            throw ValidationException::withMessages(['email' => 'INVALID_CREDENTIALS']);
        }

        $request->session()->regenerate();
        if (config('company_security.enabled')) {
            $request->session()->put('company_security.started_at', now()->timestamp);
            $request->session()->put('auth.password_confirmed_at', now()->timestamp);
        }

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->noContent();
    }
}
