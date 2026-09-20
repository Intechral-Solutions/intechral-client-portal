<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\InvitationUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $service) {}

    /** Show the invitation acceptance / registration page. */
    public function show(string $token)
    {
        $invitation = $this->service->findValid($token);

        if (! $invitation) {
            return Inertia::render('auth/invitation-invalid');
        }

        return Inertia::render('auth/invitation-register', [
            'invitation' => [
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            'token' => $token,
        ]);
    }

    /** Process registration via an invitation link. */
    public function register(Request $request, string $token)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),
                'confirmed',
            ],
        ]);

        try {
            $user = $this->service->acceptWithPassword($token, $validated['name'], $validated['password']);
        } catch (InvitationUnavailableException) {
            return redirect()->route('invitation.show', $token);
        }

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    /** Operator sends an invitation. */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->service->invite($request->input('email'), $request->user());

        return back()->with('status', 'Invitation sent to '.$request->input('email'));
    }
}
