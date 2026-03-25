<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordHistory;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $service) {}

    /** Show the invitation acceptance / registration page. */
    public function show(string $token)
    {
        $invitation = $this->service->findValid($token);

        if (! $invitation) {
            return view('auth.invitation-invalid');
        }

        return view('auth.register', compact('invitation', 'token'));
    }

    /** Process registration via an invitation link. */
    public function register(Request $request, string $token)
    {
        $invitation = $this->service->findValid($token);

        if (! $invitation) {
            return view('auth.invitation-invalid');
        }

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),
                'confirmed',
            ],
        ]);

        $user = \App\Models\User::create([
            'name'        => $validated['name'],
            'email'       => $invitation->email,
            'password'    => Hash::make($validated['password']),
            'invited_by'  => $invitation->invited_by,
        ]);

        // Assign default role
        $user->assignRole('user');

        // Store initial password history entry
        PasswordHistory::create([
            'user_id'    => $user->id,
            'password'   => $user->password,
            'created_at' => now(),
        ]);

        $this->service->accept($invitation, $user);

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

        return back()->with('status', 'Invitation sent to ' . $request->input('email'));
    }
}
