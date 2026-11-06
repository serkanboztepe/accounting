<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseContext;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\ProjectChoice;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Proje yokken maliyet sessizce Genel'e düşüyordu (Kaya İnşaat 06.11). Müteahhitte artık sorulur, ad yazılınca proje açılır,
 * 0 = Genel kalır; proje varken menüde "ya da yeni projenin adını yaz". Mimar / şubeli fırın eskisi gibi (pozitif kontrol). AI mock.
 */
class WhatsappNoProjectTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880013';

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile('muteahhit');
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 13'],
            'services.anthropic.chat_assistant' => false, 'modules.smart_assistant' => false]);
        Http::fake();
        Project::query()->update(['status' => 'cancelled']); // yerel denemedeki projeler karışmasın (geri alınır)
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function profile(string $profile): void
    {
        config(['modules' => ModuleProfiles::resolve($profile, ['projects' => true, 'expenses' => true]), 'app.profile' => $profile,
            'app.project_label' => null]);
    }

    private function expense(array $o = []): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 30000, 'paid' => true, 'date' => now()->format('Y-m-d'),
            'description' => 'Sıva işçiliği', 'category_id' => null, 'category_name' => null, 'party_id' => null, 'party_name' => null,
            'project_id' => null, 'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true], $o);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_contractor_without_projects_is_asked_and_typed_name_opens_project(): void
    {
        $this->ai($this->expense(), $this->expense(['project_name' => 'Çamlık Konutları', 'is_new_entry' => false]));

        $ask = $this->send('30 bin sıva işçiliği ödedim');
        $this->assertStringContainsString('Hangi proje? Henüz proje yok — adını yaz', $ask);
        $this->assertStringContainsString('*0* Genel', $ask);
        $this->assertSame(0, Expense::where('description', 'like', '%Sıva işçiliği%')->count(), 'sorulmadan kaydedilmez');

        $card = $this->send('Çamlık konutları');
        $this->assertStringContainsString('Proje: Çamlık Konutları (yeni)', $card);
        $this->send('evet');

        $project = Project::where('name', 'Çamlık Konutları')->first();
        $this->assertNotNull($project, 'onayla proje açılır');
        $this->assertSame($project->id, Expense::latest('id')->value('project_id'), 'gider yeni projeye yazılır');
    }

    public function test_contractor_without_projects_can_choose_general(): void
    {
        $this->ai($this->expense(['description' => 'Ofis kirası']));

        $this->send('ofis kirası 30 bin');
        $this->send('0');

        $this->assertNull(Expense::latest('id')->value('project_id'), '0 = Genel');
        $this->assertSame('Ofis kirası', Expense::latest('id')->value('description'));
    }

    public function test_contractor_menu_offers_new_project_name_when_projects_exist(): void
    {
        Project::create(['name' => 'Fatih Sitesi', 'status' => 'active']);
        $draft = ProjectChoice::prepare($this->expense(), self::PHONE);

        $this->assertTrue($draft['project_needed']);
        $this->assertStringContainsString('*1* Fatih Sitesi · *0* Genel · ya da yeni projenin adını yaz', implode("\n", ProjectChoice::lines($draft)));
        $this->assertStringContainsString('ya da yeni projenin adını yaz', (string) ProjectChoice::blocker($draft));
        $this->assertStringContainsString('listede OLMAYAN bir proje/şantiye', ExpenseContext::build(), 'AI liste dışı adı önerir');
    }

    /** Pozitif kontrol: mimar ve şubeli fırın eskisi gibi — proje yokken sorulmaz, menüde yeni ad yok. */
    public function test_architect_and_bakery_unchanged(): void
    {
        foreach (['mimar', 'trade'] as $profile) {
            $this->profile($profile);
            $this->assertArrayNotHasKey('project_needed', ProjectChoice::prepare($this->expense(), self::PHONE), "{$profile}: proje yokken sorulmaz");
            $this->assertStringNotContainsString('listede OLMAYAN bir proje', ExpenseContext::build());
        }

        Project::create(['name' => 'Ergenekon', 'status' => 'active']);
        $draft = ProjectChoice::prepare($this->expense(), self::PHONE);
        $this->assertStringNotContainsString('adını yaz', implode("\n", ProjectChoice::lines($draft)), 'fırın: menü aynı');
        $this->assertSame('Hangi şube? Numarasını yaz (0 = Genel).', ProjectChoice::blocker($draft));
    }

    public function test_guide_mentions_projects_only_when_places_on(): void
    {
        $guide = $this->send('nasıl');
        $this->assertStringContainsString('🏗 Proje: kayıtta adını geç', $guide);
        $this->assertStringContainsString('Yeni projeyse adını yaz, açarım.', $guide);

        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['projects' => false]), 'app.profile' => 'alacak_verecek']);
        $this->assertStringNotContainsString('kayıtta adını geç', $this->send('nasıl'), 'yerler kapalı: satır yok');
    }
}
