<?php

namespace App\Providers;

use App\Services\FabiClient;
use App\Services\IvtClient;
use App\Services\MisaClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FabiClient::class, fn (): FabiClient => new FabiClient(
            baseUrl: (string) config('services.fabi.base_url'),
            email: (string) config('services.fabi.email'),
            password: (string) config('services.fabi.password'),
            accessToken: (string) config('services.fabi.access_token'),
        ));

        $this->app->singleton(IvtClient::class, fn (): IvtClient => new IvtClient(
            baseUrl: (string) config('services.ivt.base_url'),
            email: (string) config('services.ivt.email'),
            password: (string) config('services.ivt.password'),
            accessToken: (string) config('services.ivt.access_token'),
            deviceId: (string) config('services.ivt.device_id'),
            secretKey: (string) config('services.ivt.secret_key'),
        ));

        $this->app->singleton(MisaClient::class, fn (): MisaClient => new MisaClient(
            baseUrl: (string) config('services.misa.base_url'),
            token: (string) config('services.misa.token'),
            cookie: (string) config('services.misa.cookie'),
            deviceId: (string) config('services.misa.device_id'),
            context: (string) config('services.misa.context'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
