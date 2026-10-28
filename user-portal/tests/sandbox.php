<?php

// Isolated browser integration fixture. Run only inside the disposable test container.
if (getenv('PORTAL_SANDBOX') !== '1') {
    http_response_code(403);
    exit('This fixture requires PORTAL_SANDBOX=1 inside the test container.');
}

$source = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . '/xboard-portal-sandbox';
foreach (['', '/storage/framework/views', '/storage/framework/cache/data', '/storage/logs', '/storage/theme', '/public', '/bootstrap'] as $directory) {
    if (!is_dir($sandbox . $directory)) mkdir($sandbox . $directory, 0755, true);
}
foreach (['app', 'config', 'routes', 'resources', 'database', 'plugins', 'plugins-core', 'vendor', 'composer.json', 'artisan'] as $directory) {
    if (!file_exists($sandbox . '/' . $directory)) symlink($source . '/' . $directory, $sandbox . '/' . $directory);
}
if (!file_exists($sandbox . '/bootstrap/app.php')) symlink($source . '/bootstrap/app.php', $sandbox . '/bootstrap/app.php');
$database = $sandbox . '/database.sqlite';
if (!file_exists($database)) touch($database);

require $source . '/vendor/autoload.php';
$app = require $source . '/bootstrap/app.php';
$app->setBasePath($sandbox);
$app->useStoragePath($sandbox . '/storage');
$app->usePublicPath($sandbox . '/public');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $database);
config()->set('cache.default', 'array');
config()->set('cache.stores.redis', ['driver' => 'array']);
Illuminate\Support\Facades\Cache::purge('redis');
$app->forgetInstance(App\Support\Setting::class);
config()->set('queue.default', 'sync');
config()->set('mail.driver', 'log');
Illuminate\Support\Facades\DB::purge('sqlite');

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'setup') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $plan = App\Models\Plan::create([
        'name' => '星旅 · Explorer', 'group_id' => 1, 'transfer_enable' => 200,
        'speed_limit' => 300, 'device_limit' => 5, 'show' => 0, 'sell' => 0, 'renew' => 1, 'capacity_limit' => null,
        'prices' => ['monthly' => '29', 'yearly' => '290', 'reset_traffic' => '9'],
        'reset_traffic_method' => 0, 'content' => '<h3>套餐详情</h3><ul><li>流量：{{transfer}} GB</li><li>速度限制：{{speed}} Mbps</li><li>同时在线设备：{{devices}} 台</li></ul><h3>服务说明</h3><ol><li>流量{{reset_method}}重置</li><li>支持多平台使用</li><li>7×24小时技术支持</li></ol>',
    ]);
    $user = App\Models\User::create([
        'email' => 'traveler@example.test', 'password' => password_hash('StarVoyage2026!', PASSWORD_DEFAULT),
        'uuid' => 'sandbox-traveler', 'token' => 'sandbox-subscription-not-for-real-use',
        'plan_id' => $plan->id, 'group_id' => 1, 'transfer_enable' => 200 * 1073741824,
        'u' => 4 * 1073741824, 'd' => 60 * 1073741824, 'device_limit' => 5, 'speed_limit' => 300,
        'expired_at' => time() + 30 * 86400, 'next_reset_at' => time() + 4 * 86400,
        'balance' => 100000, 'remind_expire' => 1, 'remind_traffic' => 0,
    ]);
    foreach ([1, 2, 3, 4, 5] as $day) {
        App\Models\StatUser::create(['user_id' => $user->id, 'record_type' => 'd', 'record_at' => now()->startOfMonth()->addDays($day)->timestamp, 'server_rate' => 1, 'u' => $day * 10000000, 'd' => $day * 100000000]);
    }
    App\Models\Knowledge::create(['language' => 'zh-CN', 'category' => '开始使用', 'title' => '如何导入你的订阅？', 'body' => "## 从这里出发\n\n在**开始使用**中选择设备、安装客户端，再导入订阅。\n\n请妥善保管自己的订阅链接。", 'show' => 1, 'sort' => 1]);
    App\Models\Notice::create(['title' => '欢迎来到星旅', 'content' => '<p>这是隔离环境中的主题集成测试。</p>', 'show' => 1, 'sort' => 1]);
    admin_setting(['app_name' => '星旅 Xboard', 'frontend_theme' => 'Xboard', 'current_theme' => 'Xboard', 'currency' => 'CNY', 'currency_symbol' => '¥', 'captcha_enable' => 0, 'email_verify' => 0]);
    admin_setting([
        'windows_version' => 'v2rayN', 'windows_download_url' => 'https://downloads.example.test/v2rayN-windows-64.zip',
        'macos_version' => 'v2rayN', 'macos_download_url' => 'https://downloads.example.test/v2rayN-macos-64.zip',
        'android_version' => 'v2rayNG', 'android_download_url' => 'https://downloads.example.test/v2rayNG.apk',
    ]);
    $version = json_decode(file_get_contents($source . '/user-portal/theme/config.json'), true)['version'];
    $packageName = "XboardPortal-{$version}.zip";
    $package = $source . '/user-portal/release/' . $packageName;
    $service = new App\Services\ThemeService();
    $service->upload(new Illuminate\Http\UploadedFile($package, $packageName, 'application/zip', null, true));
    $service->switch('XboardPortal');
    touch($sandbox . '/ready');
    echo "Sandbox installed and activated. Account: traveler@example.test / StarVoyage2026!\n";
    exit;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'skin') {
    $skin = $argv[2] ?? 'anime';
    if (!in_array($skin, ['anime', 'gothic', 'quiet'], true)) exit(1);
    (new App\Services\ThemeService())->updateConfig('XboardPortal', ['visual_style' => $skin]);
    echo "Sandbox skin: {$skin}\n";
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$staticPath = realpath($sandbox . '/public' . $requestPath);
if ($staticPath && str_starts_with($staticPath, $sandbox . '/public/') && is_file($staticPath)) return false;
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
