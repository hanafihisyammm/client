<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        // For Inertia requests, return an empty response with 409 status to trigger client-side redirect
        if ($request->header('X-Inertia')) {
            return new Response('', 409, [
                'X-Inertia-Location' => '/dashboard'
            ]);
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => '/dashboard'], 200);
        }

        return redirect()->intended('/dashboard');
    }
}