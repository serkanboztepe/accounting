<?php

namespace App\Support;

class Printing
{
    /**
     * Filament aksiyonuna eklenince (extraAttributes): yeni sekme açmadan gizli
     * bir iframe'e $url yükler ve doğrudan tarayıcının yazdır penceresini açar.
     * Kullanıcı oradan "PDF olarak kaydet" diyebilir. url, JS kapalıysa
     * bağlantı olarak da çalışsın diye aksiyonda ->url(...) ile birlikte kalır.
     */
    public static function iframeAttributes(string $url): array
    {
        return [
            'data-print-url' => $url,
            'x-on:click.prevent' => <<<'JS'
                let f = document.getElementById('doc-print-frame');
                if (! f) {
                    f = document.createElement('iframe');
                    f.id = 'doc-print-frame';
                    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
                    document.body.appendChild(f);
                }
                f.onload = () => { f.contentWindow.focus(); f.contentWindow.print(); };
                f.src = $el.dataset.printUrl;
            JS,
        ];
    }
}
