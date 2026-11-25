@extends('admin.layout')

@section('title', 'Iniciar sesión')

@section('content')
    <section class="card login-card">
        <h1>Iniciar sesión</h1>
        <p class="muted">Solo para administradores de Ciudadano.</p>

        <form method="POST" action="{{ route('login.store') }}" class="stack">
            @csrf

            <label class="field">
                <span>Correo</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>

            <label class="field">
                <span>Contraseña</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>

            @if ($errors->any())
                <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
            @endif

            <label class="check">
                <input type="checkbox" name="remember" value="1"> Mantener la sesión
            </label>

            <button type="submit" class="btn btn-primary">Entrar</button>
        </form>
    </section>
@endsection
