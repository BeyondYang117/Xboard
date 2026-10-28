<?php

namespace Tests\Feature\Theme;

use App\Services\ThemeService;
use App\Support\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class PortalThemeTest extends TestCase
{
    private string $sandbox;
    private string $originalBase;
    private string $originalPublic;
    private string $originalStorage;
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = base_path();
        $this->originalPublic = public_path();
        $this->originalStorage = storage_path();
        $this->sandbox = sys_get_temp_dir() . '/xboard-theme-' . bin2hex(random_bytes(6));
        File::makeDirectory($this->sandbox . '/public', 0755, true);
        File::makeDirectory($this->sandbox . '/storage/theme', 0755, true);
        $this->app->setBasePath($this->sandbox);
        $this->app->usePublicPath($this->sandbox . '/public');
        $this->app->useStoragePath($this->sandbox . '/storage');

        $setting = Mockery::mock(Setting::class);
        $setting->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $this->settings[$key] ?? $default);
        $setting->shouldReceive('save')->andReturnUsing(function (array $values): bool {
            $this->settings = array_merge($this->settings, $values);
            return true;
        });
        $this->app->instance(Setting::class, $setting);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        $this->app->usePublicPath($this->originalPublic);
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    private function package(string $version = '0.2.0'): UploadedFile
    {
        $zipPath = $this->sandbox . '/portal-' . $version . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $config = json_decode(File::get($this->originalBase . '/user-portal/theme/config.json'), true);
        $config['version'] = $version;
        $zip->addFromString('config.json', json_encode($config));
        $zip->addFromString('dashboard.blade.php', '<main>{{ $title }}</main>');
        $zip->addFromString('assets/portal.js', '// portal ' . $version);
        $zip->close();
        return new UploadedFile($zipPath, 'portal.zip', 'application/zip', null, true);
    }

    public function test_uploaded_theme_can_be_activated_and_both_settings_are_updated(): void
    {
        $service = new ThemeService();
        $this->assertTrue($service->upload($this->package()));
        $this->assertArrayHasKey('XboardPortal', $service->getList());
        $this->assertTrue($service->switch('XboardPortal'));
        $this->assertSame('XboardPortal', admin_setting('frontend_theme'));
        $this->assertSame('XboardPortal', admin_setting('current_theme'));
        $this->assertFileExists(public_path('theme/XboardPortal/assets/portal.js'));
        $this->assertFalse($service->getList()['XboardPortal']['can_delete']);
    }

    public function test_upgrading_theme_preserves_configuration_and_rejects_same_version(): void
    {
        $service = new ThemeService();
        $service->upload($this->package());
        $service->updateConfig('XboardPortal', ['visual_style' => 'gothic', 'unknown_field' => 'ignored']);
        $service->upload($this->package('0.3.0'));
        $this->assertSame('gothic', $service->getConfig('XboardPortal')['visual_style']);
        $this->assertArrayNotHasKey('unknown_field', $service->getConfig('XboardPortal'));
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Theme exists and not a newer version');
        $service->upload($this->package('0.3.0'));
    }

    public function test_switching_back_removes_old_public_assets_after_success(): void
    {
        $service = new ThemeService();
        $service->upload($this->package());
        $service->switch('XboardPortal');
        File::makeDirectory(base_path('theme/Xboard'), 0755, true);
        File::put(base_path('theme/Xboard/dashboard.blade.php'), '<main>Original</main>');
        $service->switch('Xboard');
        $this->assertSame('Xboard', admin_setting('frontend_theme'));
        $this->assertDirectoryDoesNotExist(public_path('theme/XboardPortal'));
        $this->assertFileExists(public_path('theme/Xboard/dashboard.blade.php'));
        $this->assertDirectoryExists(base_path('storage/theme/XboardPortal'));
    }

    public function test_blade_boot_configuration_cannot_break_out_of_script(): void
    {
        $template = $this->originalBase . '/user-portal/theme/dashboard.blade.php';
        $html = view()->file($template, [
            'title' => '</script><script>alert(1)</script>',
            'description' => 'Description', 'logo' => '', 'theme' => 'XboardPortal',
            'theme_config' => ['tagline' => "quote'\"</script><script>alert(2)</script>"],
        ])->render();
        $this->assertStringNotContainsString('<script>alert(', $html);
        $this->assertStringContainsString('window.__XBOARD_PORTAL__', $html);
        $this->assertStringContainsString('/theme/XboardPortal/assets/portal.js', $html);
        $this->assertStringNotContainsString('user-scalable=no', $html);
    }

    public function test_release_package_uses_existing_content_hashed_assets_after_upgrade(): void
    {
        $config = json_decode(File::get($this->originalBase . '/user-portal/theme/config.json'), true);
        $archive = $this->originalBase . '/user-portal/release/XboardPortal-' . $config['version'] . '.zip';
        $this->assertFileExists($archive, 'Build the release package before running integration tests.');
        // Uploading moves its temporary file, so preserve the actual release.
        $upload = $this->sandbox . '/release.zip';
        File::copy($archive, $upload);
        $service = new ThemeService();
        $service->upload($this->package('0.2.2'));
        $service->switch('XboardPortal');
        $service->upload(new UploadedFile($upload, 'release.zip', 'application/zip', null, true));
        $service->switch('XboardPortal');
        $html = view()->file($service->getThemeViewPath('XboardPortal'), [
            'title' => 'Release test', 'description' => '', 'logo' => '',
            'theme' => 'XboardPortal', 'theme_config' => [],
        ])->render();
        foreach (['js', 'css'] as $extension) {
            $pattern = '~(/theme/XboardPortal/assets/portal-[\w-]+\.' . $extension . ')~';
            $this->assertSame(1, preg_match($pattern, $html, $match));
            $this->assertFileExists(public_path(ltrim($match[1], '/')));
            $this->assertStringNotContainsString('/assets/portal.' . $extension, $html);
        }
    }
}
