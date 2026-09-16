<?php

namespace Tests\Feature\Administration;

use App\Models\Administration\Document;
use App\Models\System\Role;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdministrationDocumentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();



        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function authenticateAsAdmin(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function authenticateAsGuru(): void
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        Sanctum::actingAs($user);
    }

    private function createTestDocument(): Document
    {
        $suffix = mt_rand(100000, 999999);

        return Document::create([
            'title' => 'Dokumen Test ' . $suffix,
            'document_number' => 'DOC-TEST-' . $suffix,
            'category' => 'sop',
            'file_path' => '/uploads/dokumen/doc-test-' . $suffix . '.pdf',
            'document_date' => '2026-01-15',
            'description' => 'Dokumen untuk pengujian CRUD administrasi.',
        ]);
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM documents WHERE document_number LIKE "DOC-TEST-%"');
    }

    // ─── Authorization ───────────────────────────────────────

    public function test_guest_list_returns_401(): void
    {
        $this->getJson('/api/documents')->assertStatus(401);
    }

    public function test_guest_cannot_create_document(): void
    {
        $this->postJson('/api/documents', [
            'title' => 'Dokumen Test',
            'category' => 'sop',
        ])->assertStatus(401);
    }

    public function test_guru_cannot_create_document(): void
    {
        $this->authenticateAsGuru();

        $this->postJson('/api/documents', [
            'title' => 'Dokumen Test',
            'category' => 'sop',
        ])->assertStatus(403);
    }

    // ─── List ────────────────────────────────────────────────

    public function test_admin_can_list_documents(): void
    {
        $this->authenticateAsAdmin();
        $this->createTestDocument();

        $response = $this->getJson('/api/documents?per_page=10');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
    }

    public function test_admin_can_search_documents(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $needle = substr($doc->document_number, 5);
        $response = $this->getJson('/api/documents?q=' . $needle);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($doc->id), 'Hasil pencarian harus memuat dokumen yang dibuat.');
    }

    public function test_admin_can_filter_documents_by_category(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $response = $this->getJson('/api/documents?category=peraturan');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($doc->id), 'Dokumen kategori SOP tidak boleh muncul pada filter peraturan.');
    }

    // ─── Show ────────────────────────────────────────────────

    public function test_admin_can_view_document(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $response = $this->getJson('/api/documents/' . $doc->id);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.title', $doc->title);
        $response->assertJsonPath('data.category', 'sop');
    }

    public function test_show_nonexistent_document_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->getJson('/api/documents/99999999')->assertStatus(404);
    }

    // ─── Create ──────────────────────────────────────────────

    public function test_admin_can_create_document(): void
    {
        $this->authenticateAsAdmin();
        $suffix = mt_rand(100000, 999999);

        $response = $this->postJson('/api/documents', [
            'title' => 'Dokumen Baru ' . $suffix,
            'document_number' => 'DOC-TEST-' . $suffix,
            'category' => 'sk',
            'file_path' => '/uploads/dokumen/new-' . $suffix . '.pdf',
            'document_date' => '2026-02-20',
            'description' => 'Dibuat melalui API.',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.category', 'sk');

        $this->assertDatabaseHas('documents', [
            'document_number' => 'DOC-TEST-' . $suffix,
            'category' => 'sk',
        ]);
    }

    public function test_create_requires_title(): void
    {
        $this->authenticateAsAdmin();

        $this->postJson('/api/documents', [
            'category' => 'sop',
        ])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_create_rejects_invalid_category(): void
    {
        $this->authenticateAsAdmin();

        $this->postJson('/api/documents', [
            'title' => 'Dokumen Invalid',
            'category' => 'akademik',
        ])->assertStatus(422)->assertJsonValidationErrors(['category']);
    }

    // ─── Update ──────────────────────────────────────────────

    public function test_admin_can_update_document(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $response = $this->putJson('/api/documents/' . $doc->id, [
            'title' => 'Dokumen Diperbarui ' . $doc->id,
            'category' => 'laporan',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.category', 'laporan');
    }

    public function test_admin_can_patch_document(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $response = $this->patchJson('/api/documents/' . $doc->id, [
            'title' => 'Dokumen Patch ' . $doc->id,
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Dokumen Patch ' . $doc->id);
    }

    public function test_update_nonexistent_document_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->putJson('/api/documents/99999999', [
            'title' => 'Tidak Ada',
            'category' => 'sop',
        ])->assertStatus(404);
    }

    // ─── Delete ──────────────────────────────────────────────

    public function test_admin_can_delete_document(): void
    {
        $this->authenticateAsAdmin();
        $doc = $this->createTestDocument();

        $response = $this->deleteJson('/api/documents/' . $doc->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('documents', ['id' => $doc->id]);
    }

    public function test_delete_nonexistent_document_returns_404(): void
    {
        $this->authenticateAsAdmin();

        $this->deleteJson('/api/documents/99999999')->assertStatus(404);
    }
}