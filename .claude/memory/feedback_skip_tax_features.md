---
name: feedback-skip-tax-features
description: Şimdilik KDV/stopaj/tevkifat gibi resmi vergi alanlarını sisteme ekleme, hafif tut
metadata:
  type: feedback
---

KDV, stopaj, tevkifat ve diğer resmi/vergisel hesaplama alanları (vat_rate, withholding, vergi dairesi mahsupları vs.) projeye **şimdilik** eklenmeyecek.

**Why:** Kullanıcı sisteme önce kendisi için kuruyor, çoğu taşeronu kayıt dışı çalışıyor ve evrak yok. Resmi muhasebe katmanı erken eklenirse pratik kullanımı bozar. Piyasaya açma kararı netleşince yeniden değerlendirilir.

**How to apply:** Fatura/sözleşme/ödeme tasarım önerilerinde KDV, stopaj, tevkifat alanları önermeyin. "Belgeli/belgesiz ödeme" gibi ergonomik ayrımlar tamam, ama tutarı `subtotal + vat + withholding` gibi parçalamayın — tek `amount` yeterli. Avans/teminat mantığı gibi *iş akışı* nüansları (vergi değil) önerilebilir.
