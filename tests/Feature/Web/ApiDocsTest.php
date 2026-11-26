<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Swagger UI en /docs y especificación OpenAPI sincronizada con routes/api.php. */
class ApiDocsTest extends TestCase
{
    public function test_docs_page_and_spec_are_served(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('swagger-ui', false)
            ->assertSee(route('docs.spec'), false);

        $response = $this->get('/docs/openapi.yaml')->assertOk();
        $this->assertStringStartsWith('application/yaml', $response->headers->get('Content-Type'));
        $this->assertSame('3.0.3', Yaml::parse($response->getContent())['openapi']);
    }

    /** Si añades o cambias un endpoint, documéntalo en resources/docs/openapi.yaml. */
    public function test_spec_documents_every_api_route(): void
    {
        $spec = Yaml::parseFile(resource_path('docs/openapi.yaml'));
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = "{$method} {$path}";
            }
        }

        sort($documented);
        sort($routes);
        $this->assertSame($routes, $documented);
    }

    public function test_internal_refs_resolve(): void
    {
        $raw = file_get_contents(resource_path('docs/openapi.yaml'));
        $spec = Yaml::parse($raw);
        preg_match_all('~\$ref: "#/components/(\w+)/(\w+)"~', $raw, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);
        foreach ($matches as [, $section, $name]) {
            $this->assertArrayHasKey($name, $spec['components'][$section] ?? [], "Falta components/{$section}/{$name}");
        }
    }
}
