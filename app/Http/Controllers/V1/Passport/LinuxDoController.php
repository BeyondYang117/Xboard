<?php

namespace App\Http\Controllers\V1\Passport;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\LinuxDoService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LinuxDoController extends Controller
{
    public function __construct(private LinuxDoService $service)
    {
    }

    public function start(Request $request)
    {
        $params = $request->validate(['verifier' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        return $this->privateResponse($this->success($this->service->start($params['verifier'])));
    }

    public function callback(Request $request)
    {
        $this->service->requireEnabled();
        try {
            $params = $request->validate([
                'state' => 'required|string|size:64|alpha_num:ascii',
                'code' => 'nullable|string|max:2048',
                'error' => 'nullable|string|max:255',
            ]);
            $ticket = $this->service->callback($params['state'], $params['code'] ?? null, $params['error'] ?? null);
            $fragment = 'ticket=' . rawurlencode($ticket);
        } catch (ApiException $e) {
            $fragment = 'error=' . rawurlencode($e->getMessage());
        } catch (ValidationException $e) {
            $fragment = 'error=' . rawurlencode('Linux DO 授权回调参数无效，请重新授权。');
        } catch (LockTimeoutException $e) {
            $fragment = 'error=' . rawurlencode('授权请求正在处理中，请稍后重试。');
        } catch (\Throwable $e) {
            // Avoid reporting an exception with OAuth credentials in its context.
            \Illuminate\Support\Facades\Log::warning('Linux DO callback failed', ['type' => get_class($e)]);
            $fragment = 'error=' . rawurlencode('Linux DO 授权处理失败，请稍后重试。');
        }
        return $this->privateResponse(redirect()->away($this->service->frontendUrl() . '#linuxdo?' . $fragment));
    }

    public function exchange(Request $request)
    {
        $params = $request->validate($this->ticketRules());
        return $this->privateResponse($this->success($this->service->exchange($params['ticket'], $params['verifier'])));
    }

    public function complete(Request $request)
    {
        $params = $request->validate($this->ticketRules() + [
            'action' => 'required|in:login,register',
            'email' => 'required|string|email:strict|max:255',
            'password' => 'required|string|min:8|max:1024',
            'invite_code' => 'nullable|string|max:64',
            'email_code' => 'nullable|digits:6',
        ]);
        return $this->privateResponse($this->success($this->service->complete(
            $params['ticket'], $params['verifier'], $params['action'], $request
        )));
    }

    private function ticketRules(): array
    {
        return [
            'ticket' => 'required|string|size:64|alpha_num:ascii',
            'verifier' => 'required|string|regex:/^[a-f0-9]{64}$/D',
        ];
    }

    private function privateResponse($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
