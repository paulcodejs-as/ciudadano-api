<?php

namespace Tests\Feature\Admin;

use App\Models\CrimeStatMonthly;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\V1\StatsTestData;
use Tests\TestCase;

/** API /api/v1/admin/imports (Sanctum) y panel /admin/imports (sesión). La cola es sync en tests. */
class ImportsTest extends TestCase
{
    use RefreshDatabase, StatsTestData;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedStats(); // Miraflores (150122) y Callao (070101)
        $this->admin = User::factory()->admin()->create(['email' => 'admin@test.pe', 'password' => 'secreto123']);
    }

    private function csv(string $name = 'denuncias_2025.csv', ?string $content = null): UploadedFile
    {
        $content ??= implode("\n", [
            'ANIO_REGISTRO,MES_REGISTRO,DPTO_HECHO_NEW,PROV_HECHO,DIST_HECHO,UBIGEO_HECHO,P_MODALIDADES,cantidad',
            '2025,3,LIMA,LIMA,MIRAFLORES,150122,Robo,7',
            '2025,3,CALLAO,CALLAO,CALLAO,70101,Hurto,4',
            '2025,3,AREQUIPA,AREQUIPA,AREQUIPA,40101,Robo,9',
        ]);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /* ---------- API ---------- */

    public function test_api_upload_imports_and_reports_status(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->post('/api/v1/admin/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_name', 'denuncias_2025.csv')
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.rows_read', 3)
            ->assertJsonPath('data.rows_imported', 2)
            ->assertJsonPath('data.user.name', $this->admin->name);

        $id = $response->json('data.id');
        $this->assertStringEndsWith("/api/v1/admin/imports/{$id}", $response->json('meta.status_url'));

        Storage::disk('local')->assertExists(Import::find($id)->file_path);
        $this->assertSame(7, CrimeStatMonthly::where(['year' => 2025, 'month' => 3])->whereHas('district', fn ($q) => $q->where('ubigeo', '150122'))->value('count'));

        $this->getJson("/api/v1/admin/imports/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonStructure(['data' => ['log', 'duration_seconds']]);

        $this->getJson('/api/v1/admin/imports')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.log');
    }

    public function test_api_rejects_file_without_sidpol_columns(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post('/api/v1/admin/imports', ['file' => $this->csv('otro.csv', "a,b,c\n1,2,3\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Import::count());
    }

    public function test_api_rejects_non_csv_and_missing_file(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post('/api/v1/admin/imports', ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/admin/imports', [])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_api_requires_admin_token(): void
    {
        $this->getJson('/api/v1/admin/imports')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/imports')->assertForbidden()->assertJsonPath('success', false);
        $this->post('/api/v1/admin/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_api_unknown_import_returns_404(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/imports/999')->assertNotFound();
    }

    /* ---------- Panel web ---------- */

    public function test_panel_requires_login(): void
    {
        $this->get('/admin/imports')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_log_in_and_out(): void
    {
        $this->withoutVite();

        $this->get('/admin/login')->assertOk()->assertSee('Iniciar sesión');

        $this->post('/admin/login', ['email' => 'admin@test.pe', 'password' => 'secreto123'])
            ->assertRedirect('/admin/imports');
        $this->assertAuthenticatedAs($this->admin);

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_non_admin_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'vecino@test.pe', 'password' => 'secreto123']);

        $this->from('/admin/login')->post('/admin/login', ['email' => 'vecino@test.pe', 'password' => 'secreto123'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_panel_shows_summary_and_history(): void
    {
        $this->withoutVite();
        Import::factory()->create(['file_name' => 'historico.csv', 'status' => 'done', 'user_id' => null]);

        $this->actingAs($this->admin)->get('/admin/imports')
            ->assertOk()
            ->assertSee('denuncias cargadas')
            ->assertSee('160') // 50 + 10 + 100 de StatsTestData
            ->assertSee('historico.csv')
            ->assertSee('Completada')
            ->assertSee('Límite actual');
    }

    public function test_panel_upload_redirects_to_detail(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->admin)->post('/admin/imports', ['file' => $this->csv()]);

        $import = Import::latest('id')->first();
        $response->assertRedirect("/admin/imports/{$import->id}")->assertSessionHas('status');

        $this->actingAs($this->admin)->get("/admin/imports/{$import->id}")
            ->assertOk()
            ->assertSee('denuncias_2025.csv')
            ->assertSee('Completada')
            ->assertSee('Filas leídas: 3')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_panel_upload_shows_validation_error(): void
    {
        $this->actingAs($this->admin)->from('/admin/imports')
            ->post('/admin/imports', ['file' => $this->csv('mal.csv', "x,y\n1,2\n")])
            ->assertRedirect('/admin/imports')
            ->assertSessionHasErrors('file');
    }

    public function test_pending_import_page_auto_refreshes(): void
    {
        $this->withoutVite();
        $import = Import::factory()->create(['status' => 'pending']);

        $this->actingAs($this->admin)->get("/admin/imports/{$import->id}")
            ->assertOk()
            ->assertSee('http-equiv="refresh"', false)
            ->assertSee('En cola');
    }

    public function test_job_marks_failed_import_without_crashing(): void
    {
        Storage::disk('local')->put('imports/roto.csv', "ANIO,MES\n");
        $import = Import::factory()->create(['status' => 'pending', 'file_path' => 'imports/roto.csv']);

        (new \App\Jobs\ImportSidpolCsv($import->id))->handle(app(\App\Services\Importers\SidpolCsvImporter::class));

        $this->assertSame('failed', $import->fresh()->status);
        $this->assertStringContainsString('Faltan columnas', $import->fresh()->log);
    }

    public function test_ini_size_parsing(): void
    {
        $this->assertSame(2 * 1024 * 1024, \App\Services\ImportService::iniBytes('2M'));
        $this->assertSame(1024 ** 3, \App\Services\ImportService::iniBytes('1G'));
        $this->assertSame(PHP_INT_MAX, \App\Services\ImportService::iniBytes('0'));
    }
}
