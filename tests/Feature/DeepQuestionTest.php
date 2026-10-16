<?php

namespace Tests\Feature;

use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Faz 5: karşılaştırma/neden sorusunda (deep) cevabı büyük model yazar; basit okumada hızlı model kalır. */
class DeepQuestionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade'), 'services.anthropic.api_key' => 'test',
            'services.anthropic.chat_model' => 'claude-sonnet-5', 'services.anthropic.analysis_model' => 'claude-opus-5-5']);
        ChatAssistant::$deadline = null;
    }

    private function fake(bool $deep): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'query_records',
                'input' => ['types' => ['satis'], 'group_by' => 'project', 'deep' => $deep]]], 'usage' => []])
            ->push(['content' => [['type' => 'tool_use', 'id' => 't2', 'name' => 'reply', 'input' => ['text' => 'Araştırma önde.']]], 'usage' => []]),
        ]);
    }

    public function test_deep_question_answer_uses_big_model(): void
    {
        $this->fake(true);

        $r = app(ChatAssistant::class)->respond('whatsapp:+905550009991', 'hangi şube daha çok kazandırıyor', null);

        $this->assertSame('Araştırma önde.', $r['text']);
        $models = collect(Http::recorded())->map(fn ($p) => $p[0]['model'])->all();
        $this->assertSame(['claude-sonnet-5', 'claude-opus-5-5'], $models);
    }

    public function test_simple_read_stays_on_fast_model(): void
    {
        $this->fake(false);

        app(ChatAssistant::class)->respond('whatsapp:+905550009992', 'kime ne kadar ödedim', null);

        $this->assertSame(['claude-sonnet-5', 'claude-sonnet-5'], collect(Http::recorded())->map(fn ($p) => $p[0]['model'])->all());
    }

    /** İki dönemi karşılaştırırken aynı turda iki okuma: ikisi de çalışır, ikisinin sonucu birlikte döner (review D). */
    public function test_parallel_reads_all_get_results(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [
                ['type' => 'tool_use', 'id' => 'a', 'name' => 'query_records', 'input' => ['types' => ['satis'], 'date_from' => '2031-01-01', 'date_to' => '2031-01-07']],
                ['type' => 'tool_use', 'id' => 'b', 'name' => 'query_records', 'input' => ['types' => ['satis'], 'date_from' => '2031-01-08', 'date_to' => '2031-01-14']],
            ], 'usage' => []])
            ->push(['content' => [['type' => 'tool_use', 'id' => 'c', 'name' => 'reply', 'input' => ['text' => 'karşılaştırdım']]], 'usage' => []]),
        ]);

        $r = app(ChatAssistant::class)->respond('whatsapp:+905550009993', 'iki haftayı karşılaştır', null);

        $this->assertSame('karşılaştırdım', $r['text']);
        $second = Http::recorded()[1][0]['messages'];
        $ids = collect(end($second)['content'])->pluck('tool_use_id')->all();
        $this->assertSame(['a', 'b'], $ids);
    }
}
