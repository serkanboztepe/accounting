# İnşaat Yönetim Sistemi — CLAUDE.md

## İş Modeli

İnşaat şirketiyiz, birden fazla aktif projemiz var. Tüm maliyetler proje bazında takip edilir.

### Sözleşmeler

İki türlü sözleşme var:

- **Tedarikçi sözleşmeleri** — Toplu alım anlaşmaları (beton, demir, kapı, dolap vb.). Fiyat ve miktar baştan sabitlenir, malzeme şantiyelere geldikçe teslimat kaydı açılır. Bir tedarikçi sözleşmesi birden fazla projeye teslimat yapabilir (çapraz proje).
- **Taşeron sözleşmeleri** — Belirli bir projeye özel iş sözleşmeleri (elektrik tesisatı, alçıpan vb.).

### Ödeme Akışı

Sözleşme imzalanır → fiyatlar sabitlenir → çekler yazılır / nakit-EFT ödemeler yapılır. **Ödemeler teslimattan bağımsızdır.**

### Teslimat Akışı

Malzeme veya hizmet şantiyeye geldikçe teslimat kaydı girilir. Tedarikçi ay sonunda toplu fatura kesebileceğinden, fatura girerken hangi teslimatlara karşılık geldiği seçilir — bu sayede "hangi teslimat faturalandı, hangisi bekliyor" takip edilir.

### Çekler

Bizim verdiğimiz çekler (borcumuz). `contract_payments` tablosundan ayrı tutulur; ileride vade hatırlatması için. Çekler de bir ödeme yöntemidir.

### Faturalar

Şu an sadece **gelen** faturalar işleniyor (tedarikçi/taşerondan bize). Satış/giden fatura modülü henüz yok.

### Direkt Giderler

Sözleşme kapsamına girmeyen proje giderleri ayrıca takip edilir. **Proje ve cari seçimi opsiyoneldir** — genel şirket giderleri proje veya cariye bağlanmadan girilebilir.

---

## Yardımcı Tablolar

- **Cariler (Parties):** Tedarikçi ve taşeron firmalar. Sözleşmeler, ödemeler, çekler ve giderler bir cariye bağlanır.
- **Birimler:** Sözleşme ve teslimat kayıtlarında kullanılır (m², ton, adet, m vb.). Miktar bazlı sözleşmelerde `birim fiyat × miktar = tutar` hesabı yapılır.
- **Gider Kategorileri:** Direkt giderleri sınıflandırmak için (yakıt, kira, personel vb.).

---

## Rapor Sayfası Hesaplama Mantığı

| Metrik | Hesaplama |
|--------|-----------|
| Teslimat Maliyeti | O projeye yapılan tüm teslimatların toplamı (`contract_deliveries.project_id`) |
| Sözleşme Toplamı | O projeye ait sözleşmelerin toplamı (`contracts.project_id`) |
| Kalan Bakiye | Sözleşme toplamı − ödenen (çekler ayrı gösterilir) |
| Faturasız | Teslimat maliyeti − faturalanan |
| Toplam Proje Maliyeti | Direkt giderler + Teslimat maliyeti |

> **Not:** Teslimat maliyeti, sözleşme toplamını aşabilir. Bu bir hata değil; çapraz proje sözleşmelerinde başka projenin sözleşmesinden bu projeye teslimat yapılabilir.

---

## Teknik Altyapı

- **Framework:** Laravel + Filament (Türkçe arayüz)
- **Panel:** `/admin`, primary renk Amber
- **Uygulama adı:** İnşaat Yönetimi

---

## Para Formatı Kuralı

`App\Support\Money` sınıfı her yerde kullanılır — `number_format()` doğrudan kullanılmaz.

| Method | Kullanım | Örnek |
|--------|----------|-------|
| `Money::format($amount)` | Gösterim (PHP ve Blade) | `233999.99` → `"233.999,99"` |
| `Money::parse($value)` | Input okuma (`$get()`) | `"233.999,99"` → `233999.99` |
| `Money::store($value)` | DB'ye yazma (`dehydrate`) | `"233.999,99"` → `"233999.99"` |

**Blade şablonlarında:** `\App\Support\Money::format($amount)` kullan, `number_format()` kullanma.

**Neden:** Alpine.js `$money` mask, raw float ile set edilince nokta karakterini binlik ayraç olarak yanlış okuyordu. `Money::format()` ile set edilince sorun çözüldü. Tüm formatlamayı tek noktadan yönetmek için standart bu.

---

## Durum Değerleri

| Model | Değerler |
|-------|----------|
| Sözleşme | `draft`, `active`, `completed`, `cancelled` |
| Proje | `draft`, `active`, `completed`, `cancelled` |
| Çek | `portfolio`, `issued`, `collected`, `paid`, `cancelled`, `bounced` |
| Gider ödemesi | `paid`, `unpaid`, `partial` |
| Ödeme tipi | `cash`, `eft`, `bank_transfer`, `check`, `promissory_note`, `other` |

---

## Önemli Teknik Notlar

- `contract_deliveries.project_id` ayrı bir alandır — sözleşmenin proje_id'sinden farklı olabilir (çapraz proje teslimatı).
- `invoice_deliveries` tablosu, fatura ↔ teslimat köprüsüdür. `amount` alanı ile kısmi eşleştirme desteklenir (bir fatura birden fazla teslimatı kapsayabilir).
- `products` ve `stock_movements` tabloları daha önce oluşturulmuş ama sonradan kaldırılmış (`drop_stock_tables` migration). Projede stok modülü yok.
- Tüm para alanları `decimal(15,2)`, miktar alanları da `decimal(15,2)`.
- `@php(expr)` inline Blade sözdizimi bu projede PHP bloğunu kapatmıyor. Her zaman `@php ... @endphp` blok formunu kullan.
