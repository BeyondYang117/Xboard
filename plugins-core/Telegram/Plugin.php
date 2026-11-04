<?php

namespace Plugin\Telegram;

use App\Exceptions\ApiException;
use App\Exceptions\TelegramApiException;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramBindingService;
use App\Services\TelegramReminderService;
use App\Services\TelegramService;
use App\Services\TelegramTicketService;
use App\Services\TicketService;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/menu' => ['description' => '打开菜单', 'handler' => 'handleStartCommand'],
    '/account' => ['description' => '账户概览', 'handler' => 'handleAccountCommand'],
    '/bind' => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/traffic' => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl' => ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/reminders' => ['description' => '提醒设置', 'handler' => 'handleRemindersCommand'],
    '/tickets' => ['description' => '我的工单', 'handler' => 'handleTicketsCommand'],
    '/newticket' => ['description' => '联系客服', 'handler' => 'handleNewTicketCommand'],
    '/cancel' => ['description' => '取消当前输入', 'handler' => 'handleCancelCommand'],
    '/unbind' => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
    '/help' => ['description' => '查看帮助', 'handler' => 'handleHelpCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = app(TelegramService::class);
    $this->registerDefaultCommands();
    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.admin.after', [$this, 'sendUserTicketReplyNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
  }

  public function schedule(Schedule $schedule): void
  {
    $schedule->call(fn () => app(TelegramReminderService::class)->dispatch($this->getConfig()))
      ->name('telegram:reminders')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }
    $payment = $order->payment;
    if (!$payment) {
      Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]);
      return;
    }
    $message = sprintf(
      "💰成功收款%s元\n———————————————\n支付接口：%s\n支付渠道：%s\n本站订单：%s",
      $order->total_amount / 100, $payment->payment, $payment->name, $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, true, '');
  }

  public function sendUserTicketReplyNotify(array $data): void
  {
    if (!$this->userTicketsEnabled() || !filter_var($this->getConfig('enable_user_ticket_notify', true), FILTER_VALIDATE_BOOLEAN)) {
      return;
    }
    [$ticket, $message] = $data;
    app(TelegramTicketService::class)->queueReply($ticket, $message);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }
    $message = $ticket->messages()->latest()->first();
    $user = User::find($ticket->user_id);
    if (!$user || !$message) {
      return;
    }
    $user->load('plan');
    $ip = request()?->ip() ?? '';
    $region = $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
      ? (new \Ip2Region())->simple($ip) : '';
    $text = "📮 工单提醒 #{$ticket->id}\n━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📧 邮箱：{$user->email}\n📍 位置：{$region}\n";
    if ($user->plan) {
      $remaining = $this->transferToGBString($user->getRemainingTraffic());
      $total = $this->transferToGBString($user->transfer_enable);
      $text .= "📦 套餐：{$user->plan->name}\n📊 流量：{$remaining} / {$total} GB（剩余/总计）\n";
      $text .= '⬆️⬇️ 已用：' . $this->transferToGBString($user->u) . ' / ' . $this->transferToGBString($user->d) . " GB\n";
      $text .= '⏰ 到期：' . $this->formatDate($user->expired_at) . "\n";
    } else {
      $text .= "📦 套餐：未订购任何套餐\n";
    }
    $text .= '💰 余额：' . number_format($user->balance / 100, 2, '.', '') . " 元\n";
    $text .= '💸 佣金：' . number_format($user->commission_balance / 100, 2, '.', '') . " 元\n";
    $subject = mb_strimwidth($ticket->subject, 0, 240, '…');
    $content = mb_strimwidth($message->message, 0, 2800, '…');
    $text .= "━━━━━━━━━━━━━━━━━━━━\n📝 主题：{$subject}\n💬 内容：{$content}";
    $this->telegramService->sendMessageWithAdmin($text, true, '');
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }
    $this->registerReplyHandler('/^(📮[^\n]*?工单提醒[^\n]*?#?|工单ID: ?)(\d+)/u', [$this, 'handleTicketReply']);
    $this->registerReplyHandler('/^(?:📬 客服回复|📨 我的工单|✍️ 回复工单) #(\d+)/u', [$this, 'handleUserTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  protected function menu(): array
  {
    return ['inline_keyboard' => [
      [['text' => '👤 账户概览', 'callback_data' => 'tg:account'], ['text' => '📊 流量查询', 'callback_data' => 'tg:traffic']],
      [['text' => '🔗 订阅链接', 'callback_data' => 'tg:subscription'], ['text' => '🔔 提醒设置', 'callback_data' => 'tg:reminders']],
      [['text' => '📮 我的工单', 'callback_data' => 'tg:tickets'], ['text' => '✍️ 联系客服', 'callback_data' => 'tg:newticket']],
      [['text' => '🔗 绑定账号', 'callback_data' => 'tg:bind'], ['text' => '📖 帮助', 'callback_data' => 'tg:help']],
      [['text' => '解除绑定', 'callback_data' => 'tg:unbind']],
    ]];
  }

  protected function sendMessage(object $msg, string $message, ?array $markup = null): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, '', $markup ?? ($msg->is_private ? $this->menu() : []));
  }

  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '请在私聊中使用此命令', []);
      return false;
    }
    if (isset($msg->sender_id) && (int) $msg->sender_id !== (int) $msg->chat_id) {
      return false;
    }
    return true;
  }

  protected function getBoundUser(object $msg): ?User
  {
    $users = User::where('telegram_id', $msg->chat_id)->limit(2)->get();
    if ($users->count() > 1) {
      $this->sendMessage($msg, '检测到历史重复绑定，请先使用 /unbind 清除绑定，再重新绑定一个账号。');
      return null;
    }
    if ($users->isEmpty()) {
      $this->sendMessage($msg, '请先绑定账号：发送 /bind 后加上您的订阅链接。');
      return null;
    }
    return $users->first();
  }

  public function handleStartCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }
    if ($msg->command === '/start' && isset($msg->args[0]) && str_starts_with($msg->args[0], 'bind_')) {
      $code = substr($msg->args[0], 5);
      $user = app(TelegramBindingService::class)->previewLink($code);
      $email = explode('@', $user->email, 2);
      $masked = mb_substr($email[0], 0, 1) . '***@' . ($email[1] ?? '');
      $this->sendMessage($msg, "🔗 确认绑定账号\n\n账号：{$masked}\n绑定后可查看账户、接收提醒和使用工单。\n\n请确认这是您在网站上生成的链接。链接 5 分钟内有效，请勿转发。", ['inline_keyboard' => [
        [['text' => '确认绑定', 'callback_data' => 'tg:bind_confirm:' . $code], ['text' => '取消', 'callback_data' => 'tg:menu']],
      ]]);
      return;
    }
    $text = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！') . "\n\n";
    $text .= $this->getConfig('start_bot_description', '🤖 您可以通过下方按钮查看账户、查询流量、获取订阅和设置提醒。') . "\n\n";
    $users = User::where('telegram_id', $msg->chat_id)->limit(2)->get();
    if ($users->count() === 1) {
      $text .= "✅ 您已绑定账号：{$users->first()->email}\n\n";
      $text .= $this->getConfig('start_unbind_guide', '点击下方按钮操作，或发送 /help 查看全部命令。');
    } elseif ($users->count() > 1) {
      $text .= '检测到历史重复绑定，请先使用 /unbind 清除绑定后重新绑定。';
    } else {
      $text .= $this->getConfig('start_bind_guide', '🔗 请在网站个人中心生成一次性绑定链接，打开 Telegram 后确认绑定；也可发送 /bind 加上订阅链接。') . "\n\n";
      $text .= $this->getConfig('start_bind_commands', '/bind [订阅链接] - 绑定账号');
    }
    $text .= "\n\n" . $this->getConfig('start_footer', '💡 请在私聊中使用机器人，妥善保管您的订阅链接。');
    $this->sendMessage($msg, str_replace('\\n', "\n", $text));
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    [$msg] = $data;
    if ($handled) {
      return true;
    }
    try {
      if ($msg->message_type === 'callback_query') {
        if ($msg->is_private) {
          app(TelegramTicketService::class)->cancelDraft($msg->chat_id);
        }
        return $this->handleCallback($msg);
      }
      // Commands remain usable even when sent as replies to earlier bot messages.
      if (isset($this->commands['commands'][$msg->command])) {
        if ($msg->is_private) {
          app(TelegramTicketService::class)->cancelDraft($msg->chat_id);
        }
        return $this->handleCommandMessage($msg);
      }
      if ($msg->message_type === 'reply_message' && $this->handleReplyMessage($msg)) {
        return true;
      }
      $tickets = app(TelegramTicketService::class);
      if ($msg->is_private && $tickets->hasDraft($msg->chat_id) && !str_starts_with($msg->text, '/')) {
        if ($user = $this->getTicketUser($msg)) {
          $tickets->continueDraft($user, $msg->text);
        }
        return true;
      }
      return false;
    } catch (TelegramApiException $e) {
      Log::warning('Telegram 命令响应发送失败', ['code' => $e->getCode()]);
      return true;
    } catch (ApiException $e) {
      $this->sendMessage($msg, $e->getMessage());
      return true;
    } catch (\Throwable $e) {
      $this->handleError([$msg, $e]);
      $this->sendMessage($msg, '系统繁忙，请稍后重试');
      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    foreach ($this->commands['replies'] ?? [] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }
    return false;
  }

  protected function handleCallback(object $msg): bool
  {
    if (!$this->checkPrivateChat($msg) || !str_starts_with($msg->callback_data, 'tg:')) {
      return false;
    }
    $handlers = [
      'menu' => 'handleStartCommand', 'account' => 'handleAccountCommand',
      'traffic' => 'handleTrafficCommand', 'subscription' => 'handleGetLatestUrlCommand',
      'reminders' => 'handleRemindersCommand', 'bind' => 'handleBindCommand',
      'help' => 'handleHelpCommand', 'unbind' => 'handleUnbindCommand',
      'tickets' => 'handleTicketsCommand', 'newticket' => 'handleNewTicketCommand',
    ];
    $action = substr($msg->callback_data, 3);
    if (isset($handlers[$action])) {
      $this->{$handlers[$action]}($msg);
    } elseif (preg_match('/^bind_confirm:([a-f0-9]{40})$/D', $action, $matches)) {
      $user = app(TelegramBindingService::class)->bindWithLink($msg->chat_id, $matches[1]);
      HookManager::call('user.telegram.bind.after', [$user]);
      $this->sendMessage($msg, '✅ 绑定成功，点击下方按钮查看您的账户。');
    } elseif (preg_match('/^tickets:(\d{1,5})$/D', $action, $matches)) {
      if ($user = $this->getTicketUser($msg)) {
        app(TelegramTicketService::class)->showList($user, (int) $matches[1]);
      }
    } elseif (preg_match('/^ticket:(\d{1,10}):(\d{1,5})$/D', $action, $matches)) {
      if ($user = $this->getTicketUser($msg)) {
        app(TelegramTicketService::class)->showTicket($user, (int) $matches[1], (int) $matches[2]);
      }
    } elseif (preg_match('/^ticket_(reply|close|close_confirm):(\d{1,10})$/D', $action, $matches)) {
      if ($user = $this->getTicketUser($msg)) {
        $method = ['reply' => 'beginReply', 'close' => 'beginClose', 'close_confirm' => 'close'][$matches[1]];
        app(TelegramTicketService::class)->{$method}($user, (int) $matches[2]);
      }
    } elseif ($action === 'unbind_confirm') {
      $this->confirmUnbind($msg);
    } elseif (preg_match('/^(expire|traffic)_(on|off)$/', $action, $matches)) {
      $user = $this->getBoundUser($msg);
      if ($user) {
        $field = 'remind_' . $matches[1];
        // Explicit on/off actions are idempotent when Telegram retries an update.
        $user->{$field} = $matches[2] === 'on';
        $user->saveOrFail();
        $this->handleRemindersCommand($msg);
      }
    } else {
      return false;
    }
    return true;
  }

  public function handleUnknownCommand(array $data): void
  {
    [$msg] = $data;
    if (!$msg->is_private || $msg->message_type !== 'message' || !$this->getConfig('auto_reply', true)) {
      return;
    }
    $this->sendMessage($msg, str_replace('\\n', "\n", $this->getConfig('help_text', '未知命令，请点击下方菜单或发送 /help。')));
  }

  public function handleError(array $data): void
  {
    [$msg, $e] = $data;
    Log::error('Telegram 消息处理错误', [
      'chat_id' => $msg->chat_id ?? null,
      'message_type' => $msg->message_type ?? null,
      'exception' => get_class($e),
    ]);
  }

  public function handleHelpCommand(object $msg): void
  {
    if ($this->checkPrivateChat($msg)) {
      $this->sendMessage($msg, "/menu - 打开菜单\n/account - 账户概览\n/bind [订阅链接] - 绑定账号\n/traffic - 查看流量\n/getlatesturl - 获取订阅链接\n/reminders - 提醒设置\n/tickets - 我的工单\n/newticket - 联系客服\n/cancel - 取消当前输入\n/unbind - 解绑账号\n/help - 查看帮助");
    }
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }
    $url = $msg->args[0] ?? null;
    if (!$url) {
      $this->sendMessage($msg, "🔗 绑定账号\n\n推荐：登录网站个人中心，生成一次性绑定链接，打开 Telegram 后确认绑定。\n\n也可复制订阅链接，发送 /bind 加一个空格，再粘贴订阅链接。\n\n请勿转发绑定链接，或将订阅链接发到群聊中。");
      return;
    }
    $token = $this->extractTokenFromUrl($url);
    if (!$token) {
      $this->sendMessage($msg, '订阅地址无效');
      return;
    }
    $user = app(TelegramBindingService::class)->bind($msg->chat_id, $token);
    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, '✅ 绑定成功，点击下方按钮查看您的账户。');
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsed = parse_url($url);
    if (!$parsed || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true) || empty($parsed['host'])) {
      return null;
    }
    parse_str($parsed['query'] ?? '', $query);
    $token = $query['token'] ?? basename($parsed['path'] ?? '');
    return is_string($token) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $token) ? $token : null;
  }

  public function handleAccountCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !($user = $this->getBoundUser($msg))) {
      return;
    }
    $user->load('plan');
    $resetAt = $user->next_reset_at ?: app(TrafficResetService::class)->calculateNextResetTime($user)?->timestamp;
    $status = $user->banned ? '已停用' : ($user->isAvailable() ? '正常' : '套餐已到期、流量已用尽或尚未订购');
    $text = "👤 账户概览\n\n账号：{$user->email}\n状态：{$status}\n";
    $text .= '套餐：' . ($user->plan?->name ?? '未订购') . "\n";
    $text .= '到期时间：' . ($user->plan ? $this->formatDate($user->expired_at) : '未订购') . "\n";
    $text .= '已用流量：' . $this->transferToGBString($user->getTotalUsedTraffic()) . " GB\n";
    $text .= '剩余 / 总流量：' . $this->transferToGBString($user->getRemainingTraffic()) . ' / ' . $this->transferToGBString($user->transfer_enable) . " GB\n";
    $text .= '下次重置：' . ($resetAt ? $this->formatDate($resetAt) : '不重置') . "\n";
    $text .= '账户余额：' . number_format($user->balance / 100, 2, '.', '') . ' 元';
    $this->sendMessage($msg, $text);
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !($user = $this->getBoundUser($msg))) {
      return;
    }
    $text = sprintf(
      "📊 流量使用情况\n\n已用流量：%s GB\n总流量：%s GB\n剩余流量：%s GB\n使用率：%.2f%%",
      $this->transferToGBString($user->getTotalUsedTraffic()), $this->transferToGBString($user->transfer_enable),
      $this->transferToGBString($user->getRemainingTraffic()), $user->getTrafficUsagePercentage()
    );
    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !($user = $this->getBoundUser($msg))) {
      return;
    }
    $this->sendMessage($msg, "🔗 您的订阅链接：\n\n" . Helper::getSubscribeUrl($user->token));
  }

  public function handleRemindersCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !($user = $this->getBoundUser($msg))) {
      return;
    }
    $rows = [];
    $text = "🔔 提醒设置\n\n";
    foreach (['expire' => '到期提醒', 'traffic' => '流量提醒'] as $kind => $label) {
      $enabled = (bool) $user->{'remind_' . $kind};
      $available = (bool) $this->getConfig('enable_' . $kind . '_reminder', true);
      $text .= $label . '：' . (!$available ? '站点暂未开启' : ($enabled ? '已开启' : '已关闭')) . "\n";
      if ($available) {
        $rows[] = [['text' => ($enabled ? '关闭' : '开启') . $label, 'callback_data' => 'tg:' . $kind . ($enabled ? '_off' : '_on')]];
      }
    }
    $text .= "\n到期提醒天数：" . $this->getConfig('expire_reminder_days', '7,3,1');
    $text .= "\n流量提醒阈值：" . max(1, min(100, (int) $this->getConfig('traffic_reminder_percent', 80))) . '%';
    $text .= "\n\n这里与网站的提醒开关同步。流量不足提醒每 24 小时最多一次。";
    $rows[] = [['text' => '返回菜单', 'callback_data' => 'tg:menu']];
    $this->sendMessage($msg, $text, ['inline_keyboard' => $rows]);
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }
    if (!User::where('telegram_id', $msg->chat_id)->exists()) {
      $this->sendMessage($msg, '您尚未绑定账号。');
      return;
    }
    $this->sendMessage($msg, '确定解除当前 Telegram 的全部账号绑定？解绑后将停止接收账户提醒。', ['inline_keyboard' => [
      [['text' => '确认解绑', 'callback_data' => 'tg:unbind_confirm'], ['text' => '取消', 'callback_data' => 'tg:menu']],
    ]]);
  }

  protected function confirmUnbind(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }
    app(TelegramBindingService::class)->unbind($msg->chat_id);
    app(TelegramTicketService::class)->cancelDraft($msg->chat_id);
    $this->sendMessage($msg, '✅ 已解除当前 Telegram 的全部账号绑定。');
  }

  private function userTicketsEnabled(): bool
  {
    return filter_var($this->getConfig('enable_user_tickets', true), FILTER_VALIDATE_BOOLEAN);
  }

  private function getTicketUser(object $msg): ?User
  {
    if (!$this->checkPrivateChat($msg)) {
      return null;
    }
    if (!$this->userTicketsEnabled()) {
      $this->sendMessage($msg, '站点暂未开启 Telegram 工单，请登录网站联系客服。');
      return null;
    }
    return $this->getBoundUser($msg);
  }

  public function handleTicketsCommand(object $msg): void
  {
    if ($user = $this->getTicketUser($msg)) {
      app(TelegramTicketService::class)->showList($user);
    }
  }

  public function handleNewTicketCommand(object $msg): void
  {
    if ($user = $this->getTicketUser($msg)) {
      app(TelegramTicketService::class)->beginCreate($user);
    }
  }

  public function handleCancelCommand(object $msg): void
  {
    if ($this->checkPrivateChat($msg)) {
      app(TelegramTicketService::class)->cancelDraft($msg->chat_id);
      $this->sendMessage($msg, '已取消当前输入。');
    }
  }

  public function handleUserTicketReply(object $msg, array $matches): void
  {
    if (empty($msg->reply_from_bot) || !isset($matches[1]) || !ctype_digit($matches[1])) {
      return;
    }
    if ($user = $this->getTicketUser($msg)) {
      app(TelegramTicketService::class)->reply($user, (int) $matches[1], $msg->text);
    }
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    if (!$this->checkPrivateChat($msg) || !($user = $this->getBoundUser($msg))) {
      return;
    }
    if ($user->banned || (!$user->is_admin && !$user->is_staff)) {
      $this->sendMessage($msg, '此操作仅限管理员或客服使用。');
      return;
    }
    if (empty($msg->reply_from_bot) || !isset($matches[2]) || !ctype_digit($matches[2])) {
      $this->sendMessage($msg, '请直接回复机器人的工单提醒消息。');
      return;
    }
    $ticketId = (int) $matches[2];
    $ticket = Ticket::find($ticketId);
    if (!$ticket) {
      $this->sendMessage($msg, '工单不存在');
      return;
    }
    if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
      $this->sendMessage($msg, '工单已关闭，无法回复。');
      return;
    }
    app(TicketService::class)->replyByAdmin($ticketId, $msg->text, $user->id);
    $this->sendMessage($msg, "工单 #{$ticketId} 回复成功");
  }

  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = ['command' => ltrim($command, '/'), 'description' => $config['description']];
    }
    return $commands;
  }

  private function formatDate(?int $timestamp): string
  {
    return $timestamp === null ? '长期有效' : Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i');
  }

  private function transferToGBString(?float $bytes): string
  {
    return number_format(Helper::transferToGB($bytes ?? 0), 2, '.', '');
  }
}
