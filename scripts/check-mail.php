<?php

// Run inside the application container, from /www:
// docker exec -i -w /www CONTAINER php < scripts/check-mail.php
// Only environment/config bootstrappers and the database provider are loaded.
// No application boot hooks, mail sends, queue retries, or migrations are run.

use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

$root = getcwd();
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "请用 docker exec -i -w /www 容器名 php < check-mail.php 执行。\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
(new LoadEnvironmentVariables())->bootstrap($app);
(new LoadConfiguration())->bootstrap($app);
(new DatabaseServiceProvider($app))->register();

$config = $app['config'];
$secrets = [];
$rememberSecret = static function ($value) use (&$secrets): void {
    if (is_scalar($value) && (string) $value !== '') {
        $secrets[] = (string) $value;
    }
};
$rememberSecret($config->get('mail.password'));
$rememberSecret($config->get('mail.username'));
foreach ($config->get('database.connections', []) as $connection) {
    $rememberSecret($connection['password'] ?? null);
    $rememberSecret($connection['url'] ?? null);
}

$print = static function (string $title, $data) use (&$secrets): void {
    $clean = static function ($value) use (&$clean, &$secrets) {
        if (is_array($value) || is_object($value)) {
            return array_map($clean, (array) $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        // Suppress SQL/bindings entirely: old failures can contain mail credentials.
        $value = preg_replace('/\(Connection:.*$/s', '(SQL details omitted)', $value);
        foreach (array_unique($secrets) as $secret) {
            $value = str_replace($secret, '[REDACTED]', $value);
        }
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[EMAIL]', $value);
        return $value;
    };
    echo "\n=== {$title} ===\n";
    echo json_encode($clean($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
};

$sectionErrors = 0;
$check = static function (string $title, callable $query) use ($print, &$sectionErrors): void {
    try {
        $print($title, $query());
    } catch (Throwable $e) {
        $sectionErrors++;
        // Only show the first line, never a stack trace or serialized job payload.
        $print($title, ['check_error' => strtok($e->getMessage(), "\n")]);
    }
};

$db = $app['db']->connection();
$check('数据库连接（SELECT 1）', static function () use ($db) {
    $db->select('SELECT 1');
    return ['status' => 'OK', 'driver' => $db->getDriverName()];
});

$check('数据库中的 SMTP 配置', static function () use ($db, $rememberSecret) {
    $names = ['email_host', 'email_port', 'email_encryption', 'email_username', 'email_password', 'email_from_address'];
    $rows = $db->table('v2_settings')->whereIn('name', $names)->get(['name', 'value']);
    $settings = [];
    foreach ($rows as $row) {
        $decoded = json_decode((string) $row->value, true);
        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
        if (in_array($row->name, ['email_password', 'email_username', 'email_from_address'], true)) {
            $rememberSecret($value);
            $settings[$row->name] = $value === null || $value === '' ? '[EMPTY]' : '[SET]';
        } else {
            $settings[$row->name] = $value;
        }
    }
    foreach (array_diff($names, array_keys($settings)) as $name) {
        $settings[$name] = '[MISSING: check environment/config fallback]';
    }
    return $settings;
});

$check('邮件日志表结构', static function () use ($db) {
    $columns = $db->getSchemaBuilder()->getColumnListing('v2_mail_log');
    return [
        'columns' => $columns,
        'has_config_column' => in_array('config', $columns, true),
        'note' => '若代码仍将 config 写入 MailLog，而表中没有此字段，会导致日志保存失败。',
    ];
});

$check('最近 20 条邮件日志（不读取收件人、正文、配置）', static function () use ($db) {
    return $db->table('v2_mail_log')->orderByDesc('id')->limit(20)
        ->get(['id', 'template_name', 'error', 'created_at'])->map(static function ($row) {
            $row->created_at = is_numeric($row->created_at)
                ? gmdate('Y-m-d H:i:s', (int) $row->created_at) . ' UTC' : $row->created_at;
            $row->error = $row->error === null ? null : substr($row->error, 0, 3000);
            return (array) $row;
        })->all();
});

$check('最近 10 条邮件失败任务（不读取 payload）', static function () use ($app, $config) {
    $failedDb = $app['db']->connection($config->get('queue.failed.database'));
    $table = $config->get('queue.failed.table', 'failed_jobs');
    return $failedDb->table($table)->whereIn('queue', ['send_email', 'send_email_mass'])
        ->orderByDesc('failed_at')->limit(10)->get(['queue', 'failed_at', 'exception'])
        ->map(static function ($row) {
            // The first line normally contains the exception type and actual cause.
            $row->exception = substr(strtok($row->exception, "\n") ?: '', 0, 3000);
            return (array) $row;
        })->all();
});

$check('容器文件中的超时配置（不是已运行 worker 的内存快照）', static function () use ($config) {
    $job = (new ReflectionClass(\App\Jobs\SendEmailJob::class))->getDefaultProperties();
    return [
        'config_cached' => is_file(getcwd() . '/bootstrap/cache/config.php'),
        'mail_driver' => $config->get('mail.driver', $config->get('mail.default')),
        'env_smtp_host' => $config->get('mail.host'),
        'env_smtp_port' => $config->get('mail.port'),
        'env_smtp_encryption' => $config->get('mail.encryption'),
        'smtp_socket_timeout' => $config->get('mail.timeout', ini_get('default_socket_timeout')),
        'job_timeout' => $job['timeout'] ?? null,
        'job_tries' => $job['tries'] ?? null,
        'job_backoff' => $job['backoff'] ?? null,
        'redis_retry_after' => $config->get('queue.connections.redis.retry_after'),
    ];
});

echo "\n核对结束：未发送邮件、未重试任务、未修改数据库。\n";
exit($sectionErrors === 0 ? 0 : 1);
