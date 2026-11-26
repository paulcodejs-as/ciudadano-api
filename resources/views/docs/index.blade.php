<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ciudadano · Documentación de la API</title>
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .docs-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
            padding: 12px 20px; background: #0d366b; color: #fff; }
        .docs-brand { display: flex; align-items: center; }
        .docs-brand img { border-radius: 8px; margin-right: 10px; }
        .docs-top strong { font-size: 18px; }
        .docs-top span { opacity: .8; font-size: 14px; margin-left: 8px; }
        .docs-top nav a { color: #fff; text-decoration: none; font-size: 14px; margin-left: 16px; opacity: .9; }
        .docs-top nav a:hover { opacity: 1; text-decoration: underline; }
        .swagger-ui .topbar { display: none; }
        .swagger-ui .info { margin: 24px 0; }
        .swagger-ui .scheme-container { box-shadow: none; border-bottom: 1px solid #e5e5e5; }
    </style>
</head>
<body>
    <header class="docs-top">
        <div class="docs-brand"><img src="{{ asset('img/ciudadano-mark.png') }}" alt="" width="34" height="34"><strong>Ciudadano API</strong><span>v1 · /api/v1</span></div>
        <nav>
            <a href="{{ route('map.index') }}">Mapa</a>
            <a href="{{ route('admin.home') }}">Panel admin</a>
            <a href="{{ route('docs.spec') }}">openapi.yaml</a>
        </nav>
    </header>

    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json($specUrl),
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
            displayRequestDuration: true,
            docExpansion: 'list',
            defaultModelsExpandDepth: 0,
            tryItOutEnabled: false,
            filter: true,
        });
    </script>
</body>
</html>
