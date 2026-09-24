<?php

/*
|--------------------------------------------------------------------------
| Emlak Beyanı — kurulum bazlı varsayılan konum (Property Tax)
|--------------------------------------------------------------------------
| Şirketin sabit konumu (il/ilçe/belediye) .env'den gelir; Emlak Beyanı
| projesi formunda otomatik dolar (her binada tekrar yazılmasın).
| Mahalle/cadde/ada-parsel bina bazında değiştiği için burada tutulmaz.
|
| .env örneği:
|   PROPERTY_TAX_CITY="ERZİNCAN"
|   PROPERTY_TAX_DISTRICT="MERKEZ"
|   PROPERTY_TAX_MUNICIPALITY="MERKEZ-ERZİNCAN"
*/

return [
    'city'         => env('PROPERTY_TAX_CITY', 'ERZİNCAN'),
    'district'     => env('PROPERTY_TAX_DISTRICT', 'MERKEZ'),
    'municipality' => env('PROPERTY_TAX_MUNICIPALITY', 'MERKEZ-ERZİNCAN'),
];
