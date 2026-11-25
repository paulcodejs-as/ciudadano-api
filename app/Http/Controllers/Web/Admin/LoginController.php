<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/** Login de sesión (guard web) para el panel. Solo administradores. */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('admin.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! $user->is_admin || ! Hash::check($request->validated('password'), $user->password)) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Credenciales incorrectas o el usuario no es administrador.']);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.imports.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
