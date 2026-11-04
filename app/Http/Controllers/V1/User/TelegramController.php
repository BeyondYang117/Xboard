<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        app(TelegramBindingService::class)->ensureBotEnabled();
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        $data = [
            'username' => $response->result->username
        ];
        return $this->success($data);
    }

    public function unbind(Request $request)
    {
        app(TelegramBindingService::class)->unbindUser($request->user()->id);
        return $this->success(true);
    }

    public function createBindingLink(Request $request)
    {
        return $this->success(app(TelegramBindingService::class)->createLink($request->user()))
            ->header('Cache-Control', 'no-store');
    }
}
