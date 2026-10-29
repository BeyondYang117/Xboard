<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinuxDoService
{
    private const BASE = 'https://connect.linux.do';
    private const TTL = 300;

    public function enabled(): bool
    {
        return (bool) config('services.linuxdo.enabled')
            && filled(config('services.linuxdo.client_id'))
            && filled(config('services.linuxdo.client_secret'))
            && $this->validRedirectUri();
    }

    private function validRedirectUri(): bool
    {
        $uri = parse_url((string) config('services.linuxdo.redirect_uri'));
        return is_array($uri) && isset($uri['host'], $uri['scheme'])
            && ($uri['scheme'] === 'https' || ($uri['scheme'] === 'http' && in_array($uri['host'], ['localhost', '127.0.0.1'], true)))
            && ($uri['path'] ?? '') === '/auth/linuxdo/callback'
            && !isset($uri['query']) && !isset($uri['fragment'])
            && !isset($uri['user']) && !isset($uri['pass']);
    }

    public function requireEnabled(): void
    {
        if (!$this->enabled()) {
            throw new ApiException('Linux DO 登录未启用或配置不完整。', 503);
        }
    }

    public function frontendUrl(): string
    {
        // Only the configured callback origin can receive the result.
        return substr((string) config('services.linuxdo.redirect_uri'), 0, -strlen('/auth/linuxdo/callback')) . '/';
    }

    public function start(string $verifier): array
    {
        $this->requireEnabled();
        $state = Str::random(64);
        Cache::put($this->key('state', $state), hash('sha256', $verifier), self::TTL);

        return ['url' => self::BASE . '/oauth2/authorize?' . http_build_query([
            'client_id' => config('services.linuxdo.client_id'),
            'redirect_uri' => config('services.linuxdo.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    public function callback(string $state, ?string $code, ?string $error): string
    {
        $this->requireEnabled();
        $browser = Cache::lock($this->key('state-lock', $state), 30)->block(5, function () use ($state) {
            return Cache::pull($this->key('state', $state));
        });
        if (!$browser) {
            throw new ApiException('授权请求已过期或已使用，请重新点击 Linux DO 登录。');
        }
        if ($error || !$code) {
            throw new ApiException('Linux DO 授权已取消或未完成。');
        }

        // Do not log provider response bodies: they may contain access tokens.
        try {
            $response = Http::asForm()->acceptJson()->withBasicAuth(
                (string) config('services.linuxdo.client_id'),
                (string) config('services.linuxdo.client_secret')
            )->withoutRedirecting()->connectTimeout(5)->timeout(15)->post(self::BASE . '/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.linuxdo.redirect_uri'),
            ]);
            $accessToken = $response->json('access_token');
            if (!$response->successful() || !is_string($accessToken) || $accessToken === '') {
                throw new ApiException('Linux DO 授权码验证失败，请重新授权。');
            }
            $response = Http::acceptJson()->withToken($accessToken)->withoutRedirecting()
                ->connectTimeout(5)->timeout(15)->get(self::BASE . '/api/user');
            $profile = $response->json();
            if (!$response->successful() || !is_array($profile)) {
                throw new ApiException('无法读取 Linux DO 账户资料，请稍后重试。');
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new ApiException('暂时无法连接 Linux DO，请稍后重试。', 502);
        }

        $id = $profile['id'] ?? null;
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id)
            || !is_string($profile['username'] ?? null) || $profile['username'] === '' || strlen($profile['username']) > 255
            || !is_int($profile['trust_level'] ?? null) || $profile['trust_level'] < 0 || $profile['trust_level'] > 4
            || !is_bool($profile['active'] ?? null) || !is_bool($profile['silenced'] ?? null)) {
            throw new ApiException('Linux DO 返回的账户资料不完整，请重新授权。');
        }
        if (!$profile['active'] || $profile['silenced']) {
            throw new ApiException('该 Linux DO 账户未激活或已被禁言，无法登录。', 403);
        }
        if ($profile['trust_level'] < (int) config('services.linuxdo.min_trust_level', 0)) {
            throw new ApiException('Linux DO 账户信任等级未达到本站要求。', 403);
        }

        $ticket = Str::random(64);
        Cache::put($this->key('ticket', $ticket), [
            'browser' => $browser, 'id' => (string) $id,
            'username' => $profile['username'], 'trust_level' => $profile['trust_level'],
        ], self::TTL);
        return $ticket;
    }

    public function exchange(string $ticket, string $verifier): array
    {
        return $this->withTicket($ticket, $verifier, function (array $profile) use ($ticket) {
            $user = User::where('linuxdo_id', $profile['id'])->first();
            if ($user) {
                $result = $this->login($user, $profile);
                Cache::forget($this->key('ticket', $ticket));
                return $result;
            }
            return ['requires_account' => true, 'username' => $profile['username'], 'trust_level' => $profile['trust_level']];
        });
    }

    public function complete(string $ticket, string $verifier, string $action, Request $request): array
    {
        return $this->withTicket($ticket, $verifier, function (array $profile) use ($ticket, $action, $request) {
            // Serialize both linking and registration for this provider identity.
            return Cache::lock($this->key('identity', $profile['id']), 60)->block(5, function () use ($profile, $ticket, $action, $request) {
                $result = DB::transaction(function () use ($profile, $action, $request) {
                    if (User::where('linuxdo_id', $profile['id'])->exists()) {
                        throw new ApiException('该 Linux DO 账户已绑定，请重新使用 Linux DO 登录。', 409);
                    }
                    [$success, $user] = $action === 'register'
                        ? app(RegisterService::class)->register($request)
                        : app(LoginService::class)->login($request->string('email')->toString(), $request->string('password')->toString());
                    if (!$success) {
                        throw new ApiException($user[1], $user[0]);
                    }
                    $user = User::lockForUpdate()->findOrFail($user->id);
                    if ($user->linuxdo_id !== null) {
                        throw new ApiException('本站账户已绑定其他 Linux DO 账户。', 409);
                    }
                    $user->linuxdo_id = $profile['id'];
                    // Password login already dispatches this hook in LoginService.
                    return $this->login($user, $profile, $action !== 'login');
                });
                Cache::forget($this->key('ticket', $ticket));
                return $result;
            });
        });
    }

    private function login(User $user, array $profile, bool $dispatchHook = true): array
    {
        if ($user->banned) {
            throw new ApiException('本站账户已被封禁，无法登录。', 403);
        }
        $user->linuxdo_username = $profile['username'];
        $user->last_login_at = time();
        $user->save();
        if ($dispatchHook) {
            HookManager::call('user.login.after', $user);
        }
        return (new AuthService($user))->generateAuthData();
    }

    private function withTicket(string $ticket, string $verifier, callable $callback): array
    {
        $this->requireEnabled();
        return Cache::lock($this->key('ticket-lock', $ticket), 60)->block(5, function () use ($ticket, $verifier, $callback) {
            $profile = Cache::get($this->key('ticket', $ticket));
            if (!is_array($profile) || !hash_equals($profile['browser'], hash('sha256', $verifier))) {
                throw new ApiException('授权结果已过期或不属于当前浏览器，请重新授权。');
            }
            if ($profile['trust_level'] < (int) config('services.linuxdo.min_trust_level', 0)) {
                throw new ApiException('Linux DO 账户信任等级未达到本站要求。', 403);
            }
            return $callback($profile);
        });
    }

    private function key(string $type, string $value): string
    {
        return 'linuxdo:' . $type . ':' . hash('sha256', $value);
    }
}
