import html, sys, urllib.parse

OUT = sys.argv[1]
WA = "905453606783"

ICON_CHAT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.3 7.5L3 21l2-5.5A8.4 8.4 0 1 1 21 11.5z"/></svg>'
LOGO = '<span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"><path d="M5 7h14M5 12h14M5 17h8"/></svg></span>'
FAVICON = "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='16' fill='%230E8A5F'/><path d='M18 22h28M18 32h28M18 42h16' stroke='white' stroke-width='5' stroke-linecap='round'/></svg>"

ALL = [("muteahhit", "Müteahhit"), ("mimar", "Mimar"), ("toptanci", "Toptancı ve nalbur"), ("alacak-verecek", "Alacak-verecek")]


def wa(text):
    return f"https://wa.me/{WA}?text=" + urllib.parse.quote(text)


def msg(kind, body, t):
    return f'<div class="msg {kind}">{body}<span class="t">{t}</span></div>'


def page(p):
    slug = p["slug"]
    cta = wa(f"Merhaba, Hesap Asistanım'ı {p['wa_as']} olarak denemek istiyorum")
    chat = "\n            ".join(msg(*m) for m in p["chat"])
    pains = "\n        ".join(f"<div><b>{a}</b><span>{b}</span></div>" for a, b in p["pains"])
    says = "\n        ".join(
        f'<div class="say"><span class="bubble">{html.escape(q)}</span><p>{a}</p></div>' for q, a in p["says"])
    mods = "\n        ".join(
        f'<div class="mod">{f"<span class=tag>{t}</span>" if t else ""}<h3>{h}</h3><p>{d}</p></div>' for t, h, d in p["mods"])
    others = " · ".join(f'<a href="/{s}">{n}</a>' for s, n in ALL if s != slug)
    return f'''<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{p["seo_title"]} | Hesap Asistanım</title>
<meta name="description" content="{html.escape(p["desc"])}">
<meta property="og:title" content="{p["title"]} — Hesap Asistanım">
<meta property="og:description" content="{html.escape(p["desc"])}">
<meta property="og:url" content="https://hesapasistanim.com/{slug}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Hesap Asistanım">
<meta property="og:locale" content="tr_TR">
<meta property="og:image" content="https://hesapasistanim.com/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#0E8A5F">
<link rel="canonical" href="https://hesapasistanim.com/{slug}">
<link rel="icon" href="{FAVICON}">
<link rel="apple-touch-icon" href="/icon-180.png">
<link rel="stylesheet" href="/site.css">
<script type="application/ld+json">
{{"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
  {{"@type": "ListItem", "position": 1, "name": "Hesap Asistanım", "item": "https://hesapasistanim.com/"}},
  {{"@type": "ListItem", "position": 2, "name": "{p["crumb"]}", "item": "https://hesapasistanim.com/{slug}"}}
]}}
</script>
</head>
<body>

<header>
  <div class="wrap nav">
    <a class="brand" href="/">
      {LOGO}
      Hesap Asistanım
    </a>
    <a class="btn sm" href="{cta}">
      {ICON_CHAT}
      <span>Dene</span>
    </a>
  </div>
</header>

<main>
  <section class="hero">
    <div class="wrap hero-grid">
      <div>
        <div class="crumb"><a href="/">Hesap Asistanım</a> / {p["crumb"]}</div>
        <span class="eyebrow">{p["eyebrow"]}</span>
        <h1>{p["h1"]}</h1>
        <p class="lead">{p["lead"]}</p>
        <div class="hero-cta">
          <a class="btn" href="{cta}">
            {ICON_CHAT}
            WhatsApp'tan dene
          </a>
          <a class="btn ghost" href="#neler">Neler var?</a>
        </div>
        <p class="note">Uygulama indirmen gerekmez. Zaten kullandığın WhatsApp yeter.</p>
      </div>

      <div class="phone" aria-label="Örnek WhatsApp yazışması">
        <div class="screen">
          <div class="chat-head">
            {LOGO.replace('class="logo"', 'class="logo"')}
            <div><b>Hesap Asistanım</b><small>çevrimiçi</small></div>
          </div>
          <div class="msgs">
            {chat}
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="alt">
    <div class="wrap">
      <h2>{p["pain_h"]}</h2>
      <p class="sub">{p["pain_sub"]}</p>
      <div class="pain">
        {pains}
      </div>
    </div>
  </section>

  <section>
    <div class="wrap">
      <h2>WhatsApp'tan yaz</h2>
      <p class="sub">Arkadaşına yazar gibi yaz. Asistan ne anladığını gösterir, sen “evet” deyince kaydeder.</p>
      <div class="says">
        {says}
      </div>
    </div>
  </section>

  <section class="alt" id="neler">
    <div class="wrap">
      <h2>{p["mods_h"]}</h2>
      <p class="sub">{p["mods_sub"]}</p>
      <div class="mods">
        {mods}
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="wrap">
      <div class="box">
        <h2>{p["cta_h"]}</h2>
        <p>WhatsApp'tan yaz, senin işine göre nasıl çalıştığını gösterelim. Kurulum yok, bilgisayar yok.</p>
        <a class="btn" href="{cta}">
          {ICON_CHAT}
          WhatsApp'tan yaz
        </a>
      </div>
      <p class="others cta-others">Diğer kurulumlar: {others}</p>
    </div>
  </section>
</main>

<footer>
  <div class="wrap foot">
    <div>
      <p><b>Hesap Asistanım</b>, <a href="https://s-coder.com.tr" target="_blank" rel="noopener">S-CODER</a> tarafından sunulur.</p>
      <p>E-posta: <a href="mailto:bilgi@hesapasistanim.com">bilgi@hesapasistanim.com</a></p>
    </div>
    <nav>
      <a href="/">Ana sayfa</a>
      <a href="/gizlilik.html">Gizlilik ve KVKK</a>
      <span>© 2026 Hesap Asistanım</span>
    </nav>
  </div>
</footer>

</body>
</html>
'''


PAGES = [
    dict(
        slug="muteahhit", crumb="Müteahhit", wa_as="müteahhit",
        title="Müteahhitler için", desc="Şantiye masrafını, ustaya ve malzemeciye verdiğini WhatsApp'tan yaz. Şantiye şantiye harcama, anlaşmalar, gelen mal ve çek takibi tek yerde.",
        eyebrow="Müteahhitler için",
        h1="Şantiyenin hesabı <em>cebinde.</em>",
        lead="Betoncuya ödemeyi, nalbur fişini, ustanın avansını şantiyeden WhatsApp'la yaz. Hangi projeye ne harcadın, kime ne kadar borcun kaldı, hepsi proje proje önünde.",
        chat=[
            ("out", "Deniz Betona 120 bin havale, Gül sitesi", "08:12"),
            ("in", "💸 <b>Ödeme</b> · Gül Sitesi<br>Sen → Deniz Beton<br>Tutar: <b>120.000 ₺</b> · Havale<br>Sözleşme kalanı: 480.000 → <b>360.000 ₺</b><br><br>Kaydedeyim mi? <b>evet</b> / <b>iptal</b>", "08:12"),
            ("out", "evet", "08:13"),
            ("in", '<span class="ok">✓ Kaydedildi.</span>', "08:13"),
            ("out", '<div class="receipt"><b>AKARYAKIT</b><span>MOTORİN</span><span>72,60 LT</span><i></i><span class="tot">TOPLAM<em style="font-style:normal">3.250,00</em></span></div>mazot, Gül sitesi', "11:40"),
            ("in", "🧾 <b>Gider</b> · Yakıt · Gül Sitesi<br>Tutar: <b>3.250 ₺</b><br>Kaydedeyim mi?", "11:40"),
        ],
        pain_h="Tanıdık geldi mi?",
        pain_sub="Şantiyede iş koşturmaktan hesaba vakit kalmıyor. Sonra ay sonu gelince kimse neyin nereye gittiğini bilmiyor.",
        pains=[
            ("“Bu projeye ne harcadık?”", "Fişler cepte, dekontlar telefonda, ödemeler akılda. Projenin gerçek maliyeti belli değil."),
            ("“Betoncuya ne kadar kaldı?”", "Anlaşma ne kadardı, ne ödedik, ne teslim aldık; her seferinde baştan hesap."),
            ("“Hangi çek ne zaman?”", "Yazılan çekler dağınık. Vadesi gelen çek sürpriz oluyor."),
        ],
        says=[
            ("Ali ustaya 20 bin avans verdim", "Ödeme olarak <b>Ali Usta</b>'nın hesabına yazılır, borcu düşer."),
            ("📷 fiş fotoğrafı + “seramik, Lale sitesi”", "Fişteki tutarı okur, <b>Lale Sitesi</b>'nin harcamalarına yazar."),
            ("Sıvacı Hüseyin ustaya ne kadar borcum var?", "Kalan borcunu söyler."),
            ("Deniz Betonun dökümünü gönder", "Borç-alacak dökümü <b>PDF</b> olarak WhatsApp'ına gelir, karşı tarafa iletirsin."),
        ],
        mods_h="Panelde neler var?",
        mods_sub="WhatsApp'tan girdiklerin, bilgisayardan açtığında proje proje düzenli şekilde karşında.",
        mods=[
            ("", "Proje maliyeti", "Her şantiyenin harcamaları, gelen malzemesi ve anlaşmaları; hangi projeye ne gitti, yan yana gör."),
            ("", "Sözleşmeler", "Malzemeci ve taşeronla yaptığın anlaşmalar: fiyat, miktar, ne ödedin, ne kaldı. Bir anlaşmadan birden çok şantiyeye mal gelebilir."),
            ("", "Gelen mal ve fatura", "Şantiyeye gelen malzemeyi ve yapılan işi kaydet; faturası gelenle gelmeyeni ayrı gör."),
            ("", "Çek takibi", "Yazdığın çekler vadeleri ve durumlarıyla bir listede; ödenen, bekleyen, karşılıksız."),
            ("", "Harcamalar", "Mazot, kira, yevmiye, nalbur… İstersen şantiyeye bağla, istersen genel masraf olarak gir."),
            ("", "Usta ve firma hesapları", "Her ustanın, taşeronun, malzemecinin borç-alacak dökümü; yazdır ya da PDF gönder."),
        ],
        cta_h="Şantiyenin hesabını bugün başlat",
    ),
    dict(
        slug="mimar", crumb="Mimar", wa_as="mimar",
        title="Mimarlar için", desc="Kat karşılığı hisse dağılım cetveli, emlak vergisi bildirimi ve müşteri alacakları. Bedeli ve gelen parayı WhatsApp'tan yaz.",
        eyebrow="Mimarlar için",
        h1="Hisse cetvelinden <em>tahsilata.</em>",
        lead="Kat karşılığı işlerinde hisse dağılımını hesapla, emlak beyannamelerini hazırla. Müşterine kestiğin bedeli ve aldığın ödemeyi WhatsApp'tan yaz, kimden ne alacağın olduğu hep önünde.",
        chat=[
            ("out", "Ahmet Yılmaz'a proje bedeli 80 bin", "10:05"),
            ("in", "🧾 <b>Satış</b><br>Ahmet Yılmaz → sana<br>Tutar: <b>80.000 ₺</b><br>Alacağın: 0 → <b>80.000 ₺</b><br><br>Kaydedeyim mi? <b>evet</b> / <b>iptal</b>", "10:05"),
            ("out", "evet", "10:06"),
            ("in", '<span class="ok">✓ Kaydedildi.</span>', "10:06"),
            ("out", "Ahmet Bey 30 bin gönderdi", "16:20"),
            ("in", "💰 <b>Tahsilat</b><br>Ahmet Yılmaz → sana<br>Tutar: <b>30.000 ₺</b><br>Alacağın: 80.000 → <b>50.000 ₺</b><br>Kaydedeyim mi?", "16:20"),
        ],
        pain_h="Tanıdık geldi mi?",
        pain_sub="Mimarın işi çizimle bitmiyor: hissedarlar, tapu, belediye, beyanname… Bir de alacak takibi.",
        pains=[
            ("“Kimin ne kadar hissesi var?”", "Kat karşılığında hissedar, müteahhit ve bağımsız bölüm dağılımı Excel'de karışıyor."),
            ("“Her mükellefe ayrı beyanname”", "Paylı mülkiyette emlak vergisi bildirimini tek tek hazırlamak saatler alıyor."),
            ("“Kim ödedi, kim ödemedi?”", "İş teslim edildi ama bedelin ne kadarı ödendi, takip eden yok."),
        ],
        says=[
            ("Ahmet Yılmaz'a proje bedeli 80 bin", "Müşterinin hesabına <b>alacak</b> olarak yazılır."),
            ("Ahmet Bey 30 bin gönderdi", "Gelen para işlenir, kalan alacak güncellenir."),
            ("Toplam alacağım ne kadar?", "Alacaklı olduğun müşterileri tutarlarıyla listeler."),
            ("Ahmet Yılmaz'ın dökümünü gönder", "Hesap dökümü <b>PDF</b> olarak gelir, müşterine iletirsin."),
        ],
        mods_h="Panelde neler var?",
        mods_sub="Mimarlık ofisinin masa başı işleri için hazır araçlar.",
        mods=[
            ("Kat karşılığı", "Hisse dağılım cetveli", "Arsa, bloklar ve hissedarları gir; bağımsız bölümleri ata. Kimin ne kadar hisse devredeceği otomatik hesaplanır, tapuya uygun cetvel yazdırılır."),
            ("Kat karşılığı", "Hesap yöntemi kendiliğinden", "Arsa payları girildiyse arsa paylı, girilmediyse blok/daire yöntemi otomatik seçilir. Toplam her zaman 1/1 tutar."),
            ("Emlak vergisi", "Emlak beyannamesi", "Proje, blok ve daireleri bir kez gir; paylı mülkiyette her mükellefin beyannamesi hisse oranıyla hazırlanır, PDF olarak alınır."),
            ("", "Müşteri alacakları", "Her müşterinin bedeli, ödediği ve kalan borcu; yazdırılabilir döküm."),
            ("", "Projeler", "Müşterinin projesi ada/parsel ve adresiyle; işler proje proje düzenli."),
            ("", "Sade ekran", "Sadece bu bölümler açılır. Stok, çek, sözleşme gibi ihtiyacın olmayan şeyler karşına çıkmaz."),
        ],
        cta_h="Ofisinin hesabını sadeleştir",
    ),
    dict(
        slug="toptanci", crumb="Toptancı ve nalbur", wa_as="toptancı",
        title="Toptancı ve nalburlar için", desc="Stok, satış, iade, teklif, veresiye ve müşteri alacakları. Gelen parayı ve masrafını WhatsApp'tan yaz, kimden ne alacağın var tek mesajla öğren.",
        eyebrow="Toptancı ve nalburlar için",
        h1="Rafındaki mal, <em>müşterideki alacak.</em>",
        lead="Ne sattın, kime veresiye verdin, depoda ne kaldı; hepsi tek yerde. Gelen parayı ve dükkân masrafını WhatsApp'tan yaz, alacak listesini tek mesajla al.",
        chat=[
            ("out", "Mehmet usta 25 bin ödedi nakit", "09:30"),
            ("in", "💰 <b>Tahsilat</b><br>Mehmet Usta → sana<br>Tutar: <b>25.000 ₺</b> · Nakit<br>Alacağın: 62.400 → <b>37.400 ₺</b><br><br>Kaydedeyim mi? <b>evet</b> / <b>iptal</b>", "09:30"),
            ("out", "evet", "09:31"),
            ("in", '<span class="ok">✓ Kaydedildi.</span>', "09:31"),
            ("out", "Toplam alacağım ne kadar?", "18:02"),
            ("in", "📒 <b>Alacakların</b><br>Mehmet Usta: 37.400 ₺<br>Kaya İnşaat: 18.900 ₺<br>…ve 6 kişi daha<br>Toplam: <b>142.650 ₺</b>", "18:02"),
        ],
        pain_h="Tanıdık geldi mi?",
        pain_sub="Dükkân koşturmacasında veresiye defteri, stok ve gelen para birbirine karışıyor.",
        pains=[
            ("“Kim ne kadar borçlu?”", "Veresiye defteri dolu ama toplam alacağın ne, kim ne zamandan beri ödemiyor, belli değil."),
            ("“Depoda ne kaldı?”", "Gelen ve çıkan mal tutulmayınca stok ancak sayımda ortaya çıkıyor."),
            ("“Teklif verdim, ne oldu?”", "Teklif bir yerde, satış başka yerde; müşteriye düzgün bir çıktı vermek bile zahmet."),
        ],
        says=[
            ("Mehmet usta 25 bin ödedi nakit", "Gelen para işlenir, Mehmet Usta'nın kalan borcu düşer."),
            ("📷 fatura fotoğrafı + “dükkan elektrik”", "Faturadaki tutarı okur, <b>masraf</b> olarak yazar."),
            ("Toplam alacağım ne kadar?", "Kimden ne alacağın var, tek tek listeler."),
            ("Mehmet ustanın dökümünü gönder", "Hesap dökümü <b>PDF</b> olarak gelir, müşterine iletirsin."),
        ],
        mods_h="Panelde neler var?",
        mods_sub="Satışı ve stoğu bilgisayardan gir; gelen parayı ve soruları WhatsApp'tan hallet.",
        mods=[
            ("", "Ürün ve hizmet kartları", "Sattığın ürünler ve hizmetler birimiyle bir listede; satışta listeden seç."),
            ("", "Stok", "Mal girişi ve satışla çıkış otomatik; her ürünün eldeki miktarı ve stok raporu."),
            ("", "Satış ve iade", "Çok kalemli satış, müşterinin hesabına kendiliğinden borç yazılır. İade al, stok ve hesap geri düzelir."),
            ("", "Teklif", "Düzgün bir teklif çıktısı ver; kabul edilince tek tıkla satış sözleşmesine dönüştür, ödeme planıyla."),
            ("", "Veresiye ve müşteri hesapları", "Her müşterinin aldığı mal, ödediği para ve kalan borcu; yazdırılabilir döküm."),
            ("", "Satış özeti", "Müşteriye ya da projeye göre ne sattın; dönem dönem rapor."),
        ],
        cta_h="Veresiye defterini cebine al",
    ),
    dict(
        slug="alacak-verecek", crumb="Alacak-verecek", wa_as="alacak-verecek takibi için",
        title="Alacak-verecek takibi", desc="Kime ne borcun var, kimden ne alacağın var; WhatsApp'tan yaz, asistan tutsun. Taşeron, usta, malzemeci ve her küçük işletme için en sade kurulum.",
        eyebrow="Taşeron, usta ve her küçük işletme için",
        h1="Kime ne borcun var, <em>bir mesajla bil.</em>",
        lead="Program öğrenmek yok, defter tutmak yok. Verdiğini, aldığını, harcadığını WhatsApp'tan yaz; borç ve alacakların hep güncel kalsın.",
        chat=[
            ("out", "Hasan abiye 10 bin verdim", "12:10"),
            ("in", "💸 <b>Ödeme</b><br>Sen → Hasan Abi<br>Tutar: <b>10.000 ₺</b><br>Borcun: 25.000 → <b>15.000 ₺</b><br><br>Kaydedeyim mi? <b>evet</b> / <b>iptal</b>", "12:10"),
            ("out", "evet", "12:10"),
            ("in", '<span class="ok">✓ Kaydedildi.</span>', "12:11"),
            ("out", "Kime ne kadar borcum var?", "19:45"),
            ("in", "📒 <b>Borçların</b><br>Hasan Abi: 15.000 ₺<br>Demir Ticaret: 8.200 ₺<br>Toplam: <b>23.200 ₺</b>", "19:45"),
        ],
        pain_h="Tanıdık geldi mi?",
        pain_sub="Hesap aklında, defterde ya da telefon notlarında. Biri sorunca emin olamıyorsun.",
        pains=[
            ("“Ben sana ne vermiştim?”", "Elden verilen, havaleyle gönderilen; zamanla hangisi ne kadar, karışıyor."),
            ("“Defter kayboldu”", "Defter, not kâğıdı, telefon notu… Bir gün kaybolur, hesap da gider."),
            ("“Döküm atar mısın?”", "Karşı taraf hesap dökümü isteyince oturup elle çıkarmak gerekiyor."),
        ],
        says=[
            ("Hasan abiye 10 bin verdim", "Ödeme olarak <b>Hasan Abi</b>'nin hesabına yazılır."),
            ("Demirciden 8 bin 200'lük demir aldım", "<b>Masraf</b> olarak yazılır; ödemediysen borç olarak kalır."),
            ("Kemal 5 bin gönderdi", "Gelen para işlenir, Kemal'den alacağın düşer."),
            ("Hasan abinin dökümünü gönder", "Hesap dökümü <b>PDF</b> olarak gelir, karşı tarafa iletirsin."),
        ],
        mods_h="Bu kadar sade",
        mods_sub="Sadece ihtiyacın olan. İşin büyüdükçe yeni bölümler açılabilir.",
        mods=[
            ("", "Kişi ve firma hesapları", "Çalıştığın herkesin borç-alacak dökümü, yürüyen bakiyesiyle."),
            ("", "Verdiğin, aldığın para", "Nakit ya da havale; kime verdin, kimden aldın."),
            ("", "Harcamalar", "Fişin fotoğrafını atman yeter."),
            ("", "Hesap dökümü", "Herhangi birinin dökümünü PDF olarak al, WhatsApp'tan ilet ya da yazdır."),
            ("", "Toplam borç ve alacak", "Tek mesajla: kime ne kadar borçlusun, kimden ne kadar alacaklısın."),
            ("", "Büyüyünce genişler", "İşin büyürse proje, stok ya da sözleşme bölümleri sonradan eklenir; kayıtların kaybolmaz."),
        ],
        cta_h="Defteri bırak, mesaj at",
    ),
]

# Google'da görünen başlık + açıklama (hedef aramalar). Sayfa içi metin yukarıda.
SEO = {
    "muteahhit": ("Müteahhit Hesap Takip Programı · Şantiye Gider Takibi",
                  "Müteahhitler için hesap takip programı: şantiye gider takibi, usta ve malzemeci ödemeleri, sözleşme ve çek takibi. Masrafı WhatsApp'tan yaz, fişin fotoğrafını at; proje proje maliyeti gör."),
    "mimar": ("Kat Karşılığı Hisse Hesaplama ve Emlak Beyannamesi",
              "Mimarlar için kat karşılığı hisse dağılım cetveli hesaplama, paylı mülkiyette emlak vergisi beyannamesi hazırlama ve müşteri alacak takibi. Bedeli ve gelen parayı WhatsApp'tan yaz."),
    "toptanci": ("Toptancı ve Nalbur Programı · Stok, Satış, Veresiye Takibi",
                 "Toptancı ve nalbur programı: stok takibi, satış ve iade, teklif, veresiye ve müşteri alacak takibi. Gelen parayı WhatsApp'tan yaz, kimden ne alacağın var tek mesajla öğren."),
    "alacak-verecek": ("Veresiye Defteri ve Alacak Verecek Takibi WhatsApp'tan",
                       "Dijital veresiye defteri: kime ne borcun var, kimden ne alacağın var, WhatsApp'tan yaz. Borç alacak takibi, harcama kaydı ve PDF hesap dökümü; uygulama indirmeden."),
}

for p in PAGES:
    p["seo_title"], p["desc"] = SEO[p["slug"]]
    with open(f"{OUT}/{p['slug']}.html", "w") as f:
        f.write(page(p))
    print("wrote", p["slug"])
