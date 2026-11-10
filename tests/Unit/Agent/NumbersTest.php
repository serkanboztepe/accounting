<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Ops\Numbers;
use PHPUnit\Framework\TestCase;

/** Onayın ikinci anahtarı: müşterinin mesajındaki para rakamları (tarih ve saat rakam değildir). */
class NumbersTest extends TestCase
{
    public function test_reads_turkish_money_formats(): void
    {
        $this->assertSame([120000.0], Numbers::in('120.000'));
        $this->assertSame([120000.0], Numbers::in('evet 120000 tl'));
        $this->assertSame([1250000.5], Numbers::in('1.250.000,50 lira'));
        $this->assertSame([120000.0], Numbers::in('120 bin verdim'));
        $this->assertSame([1500000.0], Numbers::in('1.5 milyon'));
        $this->assertSame([742.0, 120.0], Numbers::in('742 -120 olacak'));
        $this->assertSame([12.5], Numbers::in('12,5 kg'));
    }

    public function test_dates_and_times_are_not_money(): void
    {
        $this->assertSame([], Numbers::in('8.11 günü'));
        $this->assertSame([], Numbers::in('08.11.2026 tarihinde'));
        $this->assertSame([], Numbers::in('saat 14:30'));
        $this->assertSame([], Numbers::in('evet'));
    }

    public function test_unknown_number_is_found_and_card_numbers_are_accepted(): void
    {
        $card = [120000.0, 742000.0, 622000.0];
        $this->assertNull(Numbers::unknown('evet', $card));
        $this->assertNull(Numbers::unknown('evet 120.000 doğru', $card));
        $this->assertNull(Numbers::unknown('evet 622 kaldı', $card), 'kısa söyleniş: 622 = 622.000');
        $this->assertNull(Numbers::unknown('evet 2 tane', $card), 'küçük sayı para değil');
        $this->assertSame(620.0, Numbers::unknown('evet ama 620 kalacak', $card));
        $this->assertSame(130000.0, Numbers::unknown('tamam 130 bin yaz', $card));
        $this->assertTrue(Numbers::contains('500000', 500000.0));
        $this->assertTrue(Numbers::contains('500.000 evet', 500000.0));
        $this->assertFalse(Numbers::contains('evet', 500000.0));
    }
}
