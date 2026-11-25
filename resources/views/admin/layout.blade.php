<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Panel') · Ciudadano admin</title>
    @stack('head')
    @vite(['resources/css/admin.css'])
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ auth()->check() ? route('admin.imports.index') : route('login') }}">
            <span class="brand-mark" aria-hidden="true"></span>
            <span><strong>Ciudadano</strong> <span class="muted">· panel</span></span>
        </a>
        @auth
            <nav class="topnav">
                <a href="{{ route('map.index') }}" target="_blank" rel="noopener">Ver mapa ↗</a>
                <span class="muted user">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="link-btn">Cerrar sesión</button>
                </form>
            </nav>
        @endauth
    </header>

    <main class="container">
        @if (session('status'))
            <div class="alert alert-ok" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
