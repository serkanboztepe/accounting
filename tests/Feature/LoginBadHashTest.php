<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Bozuk şifre kaydı girişte 500 değil, normal "eşleşmiyor" sonucu vermeli. */
class LoginBadHashTest extends TestCase
{
    use DatabaseTransactions;

    public function test_malformed_password_hash_fails_login_without_exception(): void
    {
        $user = User::factory()->create();
        // Kopyalarken $ işaretleri kaybolmuş bcrypt kaydı (gerçek olay: 56 karakter).
        DB::table('users')->where('id', $user->id)->update(['password' => '$2y$12$kirpilmis-bozuk-kayit-abcdefghijklmnopqrstuv']);

        $this->assertFalse(Hash::check('herhangi', $user->fresh()->password));
        $this->assertFalse(auth()->attempt(['email' => $user->email, 'password' => 'herhangi']));
    }
}
