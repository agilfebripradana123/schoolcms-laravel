<?php

namespace Tests\Feature\PPDB;

use App\Models\System\AuditLog;
use App\Models\PPDB\Registrant;
use App\Models\System\Role;
use App\Models\Students\Student;
use App\Models\System\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PPDBAuditTrailTest extends TestCase
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

    private function authenticateAsAdmin(): User
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $user = User::where('role_id', $adminRole->id)->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Admin Test',
                'email' => 'admin.test' . mt_rand(1,10000) . '@local',
                'password' => bcrypt('password'),
                'role_id' => $adminRole->id
            ]);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    private function authenticateAsGuru(): User
    {
        $guruRole = Role::where('name', 'Guru')->first();
        $user = User::where('role_id', $guruRole->id)->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Guru Test',
                'email' => 'guru.test' . mt_rand(1,10000) . '@local',
                'password' => bcrypt('password'),
                'role_id' => $guruRole->id
            ]);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    private function authenticateAsSiswa(): User
    {
        $siswaRole = Role::where('name', 'Siswa')->first();
        $user = User::where('role_id', $siswaRole->id)->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Siswa Test',
                'email' => 'siswa.test' . mt_rand(1,10000) . '@local',
                'password' => bcrypt('password'),
                'role_id' => $siswaRole->id
            ]);
        }
        Sanctum::actingAs($user);
        return $user;
    }

    private function createTestRegistration(array $overrides = []): Registrant
    {
        $defaults = [
            'registration_number' => 'AUD-PPDB-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT),
            'full_name' => 'Audit Test ' . mt_rand(100, 999),
            'email' => 'aud.' . mt_rand(100000, 999999) . '@test.local',
            'gender' => 'L',
        ];

        // Status fields that might be guarded against mass assignment
        $statusFields = ['status', 'verification_status', 'selection_status', 're_registration_status'];
        
        $reg = Registrant::create($defaults);

        foreach ($overrides as $key => $value) {
            $reg->$key = $value;
        }

        foreach ($statusFields as $field) {
            if (!isset($overrides[$field])) {
                if ($field === 'status') $reg->$field = 'draft';
                else $reg->$field = 'pending';
            }
        }

        $reg->save();

        return $reg;
    }

    private function cleanupTestData(): void
    {
        DB::connection()->statement('DELETE FROM registrants WHERE registration_number LIKE "AUD-PPDB-%"');
        DB::connection()->statement('DELETE FROM audit_logs WHERE description LIKE "%AUD-PPDB-%"');
    }

    // 1. Admin create registration creates audit log
    public function test_admin_create_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();

        $response = $this->postJson('/api/registrations', [
            'full_name' => 'Audit Test Create',
            'email' => 'aud.create.' . mt_rand(1, 100000) . '@test.local',
            'gender' => 'L',
        ]);

        $response->assertStatus(201);
        $registrationNumber = $response->json('data.registration_number');
        $registrantId = $response->json('data.id');

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $registrantId)
            ->where('action', 'registration_created')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($user->id, $auditLog->user_id);
        $this->assertStringContainsString($registrationNumber, $auditLog->description);
    }

    // 2. Admin update registration creates audit log
    public function test_admin_update_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();

        $response = $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Audit Test Update',
            'gender' => 'P',
        ]);

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_updated')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($user->id, $auditLog->user_id);
    }

    // 3. Admin delete registration creates audit log
    public function test_admin_delete_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();

        $response = $this->deleteJson("/api/registrations/{$reg->id}");

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_deleted')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($user->id, $auditLog->user_id);
    }

    // 4. Admin verify registration creates audit log
    public function test_admin_verify_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['status' => 'submitted']);

        $response = $this->postJson("/api/registrations/{$reg->id}/verify");

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_verified')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($user->id, $auditLog->user_id);
        
        $desc = json_decode($auditLog->description, true);
        $this->assertEquals('submitted', $desc['from_status']);
        $this->assertEquals('verified', $desc['to_status']);
    }

    // 5. Admin reject registration creates audit log
    public function test_admin_reject_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration(['status' => 'submitted']);

        $response = $this->postJson("/api/registrations/{$reg->id}/reject", [
            'verification_notes' => 'Test rejection',
        ]);

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_rejected')
            ->first();

        $this->assertNotNull($auditLog);
    }

    // 6. Admin select registration creates audit log
    public function test_admin_select_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration([
            'status' => 'verified',
            'verification_status' => 'verified'
        ]);

        $response = $this->postJson("/api/registrations/{$reg->id}/select", [
            'selection_score' => 90.5,
        ]);

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_selected')
            ->first();

        $this->assertNotNull($auditLog);
    }

    // 7. Admin not-select registration creates audit log
    public function test_admin_not_select_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration([
            'status' => 'verified',
            'verification_status' => 'verified'
        ]);

        $response = $this->postJson("/api/registrations/{$reg->id}/not-select");

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_not_selected')
            ->first();

        $this->assertNotNull($auditLog);
    }

    // 8. Admin re-register creates audit log
    public function test_admin_re_register_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration([
            'status' => 'selected',
            'selection_status' => 'selected'
        ]);

        $response = $this->postJson("/api/registrations/{$reg->id}/re-register");

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_re_registered')
            ->first();

        $this->assertNotNull($auditLog);
    }

    // 9. Admin verify re-registration creates audit log
    public function test_admin_verify_re_registration_creates_audit_log(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration([
            'status' => 're_registered',
            'selection_status' => 'selected',
            're_registration_status' => 'completed'
        ]);

        $response = $this->postJson("/api/registrations/{$reg->id}/verify-re-registration");

        $response->assertStatus(200);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_re_registration_verified')
            ->first();

        $this->assertNotNull($auditLog);
    }

    // 10. Audit log uses authenticated user ID
    public function test_audit_log_uses_authenticated_user_id(): void
    {
        $user = $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration();

        // Pass a fake user_id in the payload
        $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Audit Test Update',
            'user_id' => 99999, // Should be ignored
        ]);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_updated')
            ->first();

        $this->assertEquals($user->id, $auditLog->user_id);
    }

    // 11-14. Audit log does not expose sensitive data
    public function test_audit_log_does_not_expose_sensitive_data(): void
    {
        $this->authenticateAsAdmin();
        $reg = $this->createTestRegistration([
            'nik' => '1234567890123456',
            'nisn' => '1234567890',
            'document_kk' => 'storage/path/to/kk.pdf'
        ]);

        $this->putJson("/api/registrations/{$reg->id}", [
            'full_name' => 'Audit Test Update',
        ]);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_updated')
            ->first();

        $desc = $auditLog->description;
        
        $this->assertStringNotContainsString('1234567890123456', $desc); // NIK
        $this->assertStringNotContainsString('1234567890', $desc); // NISN
        $this->assertStringNotContainsString('storage/path', $desc); // Path
        $this->assertStringNotContainsString('password', strtolower($desc)); // Password
    }

    // 15. Failed transaction does not create audit log
    public function test_failed_transaction_does_not_create_audit_log(): void
    {
        $this->authenticateAsAdmin();
        $reg1 = $this->createTestRegistration(['nik' => '9999999999999999']);
        $reg2 = $this->createTestRegistration();

        // Attempt an update that will fail unique constraint (duplicate NIK)
        $response = $this->putJson("/api/registrations/{$reg2->id}", [
            'nik' => '9999999999999999',
        ]);

        $response->assertStatus(422);

        // Ensure no updated audit log was created for reg2
        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg2->id)
            ->where('action', 'registration_updated')
            ->first();

        $this->assertNull($auditLog);
    }

    // 16. Guru cannot trigger workflow and therefore cannot create workflow audit
    public function test_guru_cannot_trigger_workflow_audit(): void
    {
        $this->authenticateAsGuru();
        $reg = $this->createTestRegistration(['status' => 'submitted']);

        $response = $this->postJson("/api/registrations/{$reg->id}/verify");
        $response->assertStatus(403);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_verified')
            ->first();

        $this->assertNull($auditLog);
    }

    // 17. Siswa cannot trigger workflow and therefore cannot create workflow audit
    public function test_siswa_cannot_trigger_workflow_audit(): void
    {
        $this->authenticateAsSiswa();
        $reg = $this->createTestRegistration(['status' => 'submitted']);

        $response = $this->postJson("/api/registrations/{$reg->id}/verify");
        $response->assertStatus(403);

        $auditLog = AuditLog::where('model', 'Registrant')
            ->where('model_id', $reg->id)
            ->where('action', 'registration_verified')
            ->first();

        $this->assertNull($auditLog);
    }
}
