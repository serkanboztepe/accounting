---
name: Money helper — para format kuralı
description: Projede para parse/format için Money helper oluşturuldu, tüm yerler buna bağlandı
type: project
---

Para işlemleri `App\Support\Money` üzerinden yapılır.

- `Money::parse($val)` → float — Türkçe binlik ("600.000"), Türkçe ondalık ("233.999,99"), DB cast ("233999.99"), int/float — hepsini anlar
- `Money::format($amount)` → "233.999,99" — display için
- `Money::store($val)` → "233999.99" — DB'ye yazmak için

**Parse 3-kural mantığı:** (1) virgülden sonra hane varsa Türkçe ondalık — nokta sil, virgül→nokta; (2) sondan tam 2 hane nokta + öncesi tamsayı ise DB cast — direkt parse; (3) aksi halde Türkçe binlik — noktaları sil.

**Why:** Alpine.js `$money` mask Türkçe binlik ayraçla çalışıyor ve kullanıcı ondalık virgülünü koymadan submit edebiliyor → "600.000" gelir. Eski parse bunu `(float) "600.000"` → 600 okuyordu (PHP nokta=ondalık). 3-kural ile binlik/ondalık ambiguity çözüldü. Ayrıca `,\d{2}$` regex'i `,\d+$`'a genişletildi (tek haneli ondalık "0,5" destekli).

**How to apply:** `$set('amount', ...)` çağrılarında her zaman `Money::format()` kullan. `$get()` ile okunan değerleri `Money::parse()` ile float'a çevir. MoneyInput içi artık otomatik. Para alanı kullanan tüm callback'ler güvenli.
