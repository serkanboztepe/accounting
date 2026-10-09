<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Twilio webhook'u CSRF token göndermez.
        $middleware->validateCsrfTokens(except: [
            'whatsapp/webhook',
        ]);

        // Tek panel: firma, oturum açıldıktan HEMEN sonra seçilmeli — kullanıcıyı yükleyen
        // AuthenticateSession/Authenticate ve {party} gibi route bağlamalarından ÖNCE (yoksa
        // boş/yanlış veritabanına bakarlar). Öncelik listesi panel middleware'ini de sıralar.
        $middleware->web(append: [\App\Http\Middleware\IdentifyTenant::class]);
        $middleware->appendToPriorityList(
            after: \Illuminate\Session\Middleware\StartSession::class,
            append: \App\Http\Middleware\IdentifyTenant::class,
        );

        // İmzalı linkler (WhatsApp ekstre PDF'i): imza, {party} gibi kayıtlar veritabanından
        // yüklenmeden ÖNCE kontrol edilsin — bozuk imzada firma seçilmez, yükleme 500 veriyordu (403 olmalı).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \Illuminate\Routing\Middleware\ValidateSignature::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
