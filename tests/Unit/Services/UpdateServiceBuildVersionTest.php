<?php

namespace Tests\Unit\Services;

use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UpdateServiceBuildVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
    }

    public function test_container_uses_build_metadata_without_git(): void
    {
        config()->set('app.version', '20261027-43704b42132a4ac3267a5a4dd64a73822c4a5797');
        Process::fake(['*' => Process::result(exitCode: 128)]);
        $service = new UpdateService();
        $this->assertSame('20261027-43704b4', $service->getCurrentVersion());
        $service->updateVersionCache();
        $this->assertSame('20261027', Cache::get(UpdateService::CACHE_VERSION_DATE));
        $this->assertSame('43704b4', Cache::get(UpdateService::CACHE_VERSION));
    }

    public function test_source_checkout_still_uses_git_revision(): void
    {
        config()->set('app.version', '20261027-43704b4');
        Process::fake([
            'git rev-parse HEAD' => Process::result(output: 'abcdef0123456789'),
            'git log -1 --format=%cd:%H --date=format:%Y%m%d' => Process::result(output: '20261028:abcdef0123456789'),
            '*' => Process::result(),
        ]);
        $service = new UpdateService();
        $this->assertStringEndsWith('-abcdef0', $service->getCurrentVersion());
        $service->updateVersionCache();
        $this->assertSame('20261028-abcdef0', $service->getCurrentVersion());
    }
}
