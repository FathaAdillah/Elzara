<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;

/** Sesi admin nyata (login + 2FA + XSRF) untuk uji rute katalog. */
trait AdminCatalogSession
{
    private array $cookies = [];

    private function loginAdmin(string ...$permissions): User
    {
        $email = 'catalog-'.Str::lower(Str::random(8)).'@example.test';
        $id = DB::table('users')->insertGetId(['public_id' => (string) Str::ulid(), 'name' => 'Catalog Operator', 'email' => $email, 'is_operator' => true,
            'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        DB::insert("INSERT INTO staff_2fa_secrets (user_id, secret_encrypted, confirmed_at, recovery_hashes) VALUES (?, convert_to(?, 'UTF8'), CURRENT_TIMESTAMP, ARRAY[?]::text[])",
            [$id, Crypt::encryptString('JBSWY3DPEHPK3PXP'), password_hash('A1B2C3D4E5F6', PASSWORD_ARGON2ID)]);
        $this->csrf(true);
        $challenge = $this->api('POST', '/api/v1/admin/auth/login', ['email' => $email, 'password' => 'fixture-password'])->assertOk()->json('challenge_id');
        $this->csrf(true);
        $this->api('POST', '/api/v1/admin/auth/2fa/verify', ['challenge_id' => $challenge, 'code' => 'A1B2C3D4E5F6'])->assertOk();
        $this->csrf();
        $user = User::query()->findOrFail($id);
        foreach ($permissions as $permission) {
            $this->grant($user, $permission);
        }

        return $user;
    }

    private function grant(User $user, string $name): void
    {
        $permission = Permission::query()->where(['name' => $name, 'guard_name' => 'admin'])->first()
            ?? Permission::create(['name' => $name, 'guard_name' => 'admin', 'group_name' => 'Catalog']);
        $user->givePermissionTo($permission);
    }

    private function csrf(bool $login = false): void
    {
        $this->api('GET', '/sanctum/csrf-cookie?scope=admin'.($login ? '&purpose=login' : ''))->assertNoContent();
    }

    /** @param array<string, mixed> $payload */
    private function api(string $method, string $path, array $payload = [], bool $idempotent = false, bool $csrf = true, ?UploadedFile $file = null): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on'];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        if ($idempotent) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = (string) Str::uuid();
        }
        if ($file !== null) {
            $response = $this->call($method, $path, $payload, $this->cookies, ['file' => $file], $headers);
        } else {
            $headers['CONTENT_TYPE'] = 'application/json';
            $response = $this->call($method, $path, [], $this->cookies, [], $headers, $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        }
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /** @return array{category: int, brand: int, collection: int} */
    private function seedTaxonomy(): array
    {
        return [
            'category' => DB::table('categories')->insertGetId(['slug' => 'apparel', 'name_key' => 'catalog.category.apparel']),
            'brand' => DB::table('brands')->insertGetId(['slug' => 'snowego', 'name' => 'Snowego', 'country' => 'ID']),
            'collection' => DB::table('collections')->insertGetId(['slug' => 'winter-drop', 'name_key' => 'catalog.collection.winter', 'kind' => 'fandom']),
        ];
    }
}
