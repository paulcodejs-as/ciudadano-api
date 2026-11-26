<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Panel') · Ciudadano admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    @stack('head')
    @vite(['resources/css/admin.css'])
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ auth()->check() ? route('admin.imports.index') : route('login') }}">
            <img class="brand-mark" src="{{ asset('img/ciudadano-mark.png') }}" alt="" width="32" height="32">
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
