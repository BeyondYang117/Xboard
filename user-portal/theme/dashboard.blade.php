<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="referrer" content="same-origin" />
    <title>{{ $title }}</title>
    <script>
        window.__XBOARD_PORTAL__ = {{ Illuminate\Support\Js::from([
            'title' => $title,
            'description' => ($theme_config['tagline'] ?? '') ?: $description,
            'logo' => $logo,
            'version' => '0.2.7',
            'appearance' => $theme_config['appearance'] ?? 'light',
            'accent' => $theme_config['accent'] ?? 'blue',
            'visualStyle' => $theme_config['visual_style'] ?? 'anime',
            'allowRegistration' => !(bool) admin_setting('stop_register', 0),
            'supportUrl' => $theme_config['support_url'] ?? '',
            'coverImage' => $theme_config['cover_image'] ?? '',
            'clients' => [
                'Windows' => $theme_config['client_windows_url'] ?? '',
                'macOS' => $theme_config['client_macos_url'] ?? '',
                'iOS' => $theme_config['client_ios_url'] ?? '',
                'Android' => $theme_config['client_android_url'] ?? '',
            ],
        ]) }};
    </script>
    <link rel="stylesheet" href="/theme/{{ $theme }}/assets/portal.css" />
    <script type="module" src="/theme/{{ $theme }}/assets/portal.js"></script>
</head>
<body>
    <div id="app"></div>
    <noscript>请启用 JavaScript 以使用 {{ $title }} 用户中心。</noscript>
</body>
</html>
