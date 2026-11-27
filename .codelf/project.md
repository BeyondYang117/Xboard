# Project Notes

Xboard is a Laravel 12 application with a Vue-based user portal and a plugin system. Core plugins live in `plugins-core/<PluginCode>` and expose a `Plugin.php` class plus a `config.json` manifest.

Payment plugins implement `App\Contracts\PaymentInterface` and register their method in the `available_payment_methods` hook. Admin-configurable fields come from `form()`, `pay()` returns the gateway redirect response, and `notify()` verifies the gateway callback and maps it to Xboard's `trade_no` and `callback_no` fields.

BEpusdt integration is in `plugins-core/Bepusdt`. It uses BEpusdt's signed JSON create-transaction API and supports selecting an API URL, API token, fiat currency, and supported USDT trade type. Keep gateway credentials in the payment record's protected config; never add real credentials to source control.

Core plugins are loaded by `App\Services\Plugin\PluginManager`; they are not necessarily discoverable through Composer's normal plugin namespace mapping. Unit tests for a core plugin should explicitly require its `Plugin.php` file when testing it directly.
