<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Typecho\Cookie;
use Typecho\Common;
use Typecho\Config;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 登录流程控制器
 */
class Handler
{
    const COOKIE = '__oauthlogin_pending';

    /**
     * 是否已开启输出缓冲
     *
     * @var bool
     */
    private static $buffering = false;

    /**
     * 缓冲前所处的层级
     *
     * @var int
     */
    private static $obLevel = 0;

    /**
     * 本次流程的模式：login（登录）或 bind（在个人设置里绑定）
     *
     * @var string
     */
    private static $mode = 'login';

    /**
     * 发起登录
     *
     * @throws \Exception
     */
    public static function start()
    {
        $settings = Settings::load();

        $key = isset($_GET['provider']) ? strtolower((string) $_GET['provider']) : '';
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        if ('' === $key || !in_array($key, Settings::enabledProviders(), true)) {
            throw new \Exception('该登录方式未启用');
        }

        $provider = ProviderFactory::create($key, $settings);
        $state = bin2hex(random_bytes(16));

        // 绑定流程：必须在已登录状态下发起，完成后固定回个人设置
        $mode = (isset($_GET['mode']) && 'bind' === $_GET['mode']) ? 'bind' : 'login';

        if ('bind' === $mode && !self::hasLogin()) {
            throw new \Exception('绑定第三方账号前请先登录本站');
        }

        self::$mode = $mode;

        // 记录发起登录时所在的页面，登录成功后回到这里
        $return = 'bind' === $mode ? '' : self::safeReturnUrl(self::decodeReturn(isset($_GET['return']) ? (string) $_GET['return'] : ''));
        $referer = self::safeReturnUrl(isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');

        if ('bind' !== $mode) {
            if ('' === $return) {
                $return = $referer;
            } elseif ('' !== $referer && self::isSiteRoot($return) && !self::isSiteRoot($referer)) {
                // 主题的登录面板会跨页面复用：从首页进入文章后，面板里可能还留着首页时的链接，
                // 此时来源页比链接里的 return 更可信
                $return = $referer;
            }
        }

        Cookie::set(self::COOKIE, $key . '|' . $state . '|' . self::encodeReturn($return) . '|' . $mode, 900);

        self::go($provider->authorizeUrl($state, self::redirectUri($settings)));
    }

    /**
     * 处理回调
     *
     * @throws \Exception
     */
    public static function callback()
    {
        $settings = Settings::load();

        $pending = (string) Cookie::get(self::COOKIE, '');

        // 不是本插件发起的回调，交还给正常流程
        if ('' === $pending || false === strpos($pending, '|')) {
            return;
        }

        Cookie::delete(self::COOKIE);

        list($key, $expectedState, $returnCode, $mode) = array_pad(explode('|', $pending, 4), 4, '');
        self::$mode = ('bind' === $mode) ? 'bind' : 'login';

        $givenState = isset($_GET['state']) ? (string) $_GET['state'] : '';

        if ('' === $givenState || !hash_equals($expectedState, $givenState)) {
            throw new \Exception('登录状态已失效，请重新点击第三方登录');
        }

        if (!in_array($key, Settings::enabledProviders(), true)) {
            throw new \Exception('该登录方式已被停用');
        }

        $code = isset($_GET['code']) ? (string) $_GET['code'] : '';
        if ('' === $code) {
            throw new \Exception('未获得授权码，可能已取消授权');
        }

        $provider = ProviderFactory::create($key, $settings);
        $redirectUri = self::redirectUri($settings);

        $profile = $provider->fetchProfile($code, $redirectUri);

        if ('bind' === self::$mode) {
            self::bindAccount($key, $profile);
        }

        $user = Auth::resolve($key, $profile, $settings);

        Auth::commit((int) $user['uid'], 7 * 24 * 3600);

        // 优先回到发起登录时的页面，没有则按角色决定
        $target = self::safeReturnUrl(self::decodeReturn($returnCode));

        self::go('' !== $target ? $target : self::homeUrl($user));
    }

    /**
     * 把第三方账号绑定到当前登录用户
     *
     * @param string $key
     * @param array $profile
     * @throws \Exception
     */
    private static function bindAccount($key, array $profile)
    {
        if (!self::hasLogin()) {
            throw new \Exception('登录状态已失效，请重新登录后再绑定');
        }

        $user = User::alloc();
        $uid = (int) $user->uid;
        $openid = (string) $profile['id'];

        $bound = Store::findByOpenid($key, $openid);

        if (!empty($bound['uid']) && (int) $bound['uid'] !== $uid) {
            throw new \Exception('该第三方账号已绑定到其他用户，请先在那边解绑');
        }

        foreach (Store::findByUid($uid) as $row) {
            if ($row['provider'] === $key && (string) $row['openid'] !== $openid) {
                throw new \Exception('当前账号已绑定另一个' . self::providerLabel($key) . '账号，请先解绑');
            }
        }

        Store::bind($uid, $key, $profile);

        Profile::notice('已绑定「' . self::providerLabel($key) . '」', 'success');
        self::go(Profile::url());
    }

    /**
     * 授权方拒绝授权或返回错误
     *
     * @param string $error
     * @param string $description
     */
    public static function denied($error, $description = '')
    {
        $pending = (string) Cookie::get(self::COOKIE, '');

        // 不是本插件发起的请求（例如主题自身的参数），交还给正常流程
        if ('' === $pending || false === strpos($pending, '|')) {
            return;
        }

        Cookie::delete(self::COOKIE);

        $parts = explode('|', $pending, 4);
        self::$mode = (isset($parts[3]) && 'bind' === $parts[3]) ? 'bind' : 'login';

        $map = [
            'access_denied'             => '你取消了授权，登录未完成',
            'invalid_scope'             => '授权范围配置有误',
            'unauthorized_client'       => '应用未获授权，请检查客户端配置',
            'unsupported_response_type' => '该平台不支持当前授权方式',
            'temporarily_unavailable'   => '授权服务暂时不可用，请稍后再试',
            'server_error'              => '授权服务端出错，请稍后再试',
        ];

        $message = isset($map[$error]) ? $map[$error] : '第三方登录未完成';
        $description = trim((string) $description);

        if ('' !== $description) {
            $message .= '（' . $description . '）';
        }

        self::fail($message);
    }

    /**
     * 登录页注入资源
     *
     * @return string
     */
    public static function loginAssets()
    {
        $settings = Settings::load();

        if ('1' !== (string) $settings->showOnLogin) {
            return '';
        }

        $items = self::providerItems();

        if (empty($items)) {
            return '';
        }

        $options = Options::alloc();
        $base = rtrim(Url::rewrite((string) $options->pluginUrl), '/') . '/OAuthLogin';
        $version = '?v=1.5.0';

        $payload = json_encode(
            ['providers' => $items],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );

        return '<link rel="stylesheet" href="' . htmlspecialchars($base . '/assets/login.css' . $version, ENT_QUOTES, 'UTF-8') . '">'
            . '<script>window.__OAUTHLOGIN__=' . $payload . ';</script>'
            . '<script src="' . htmlspecialchars($base . '/assets/login.js' . $version, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    /**
     * 已启用的登录方式（供模板使用）
     *
     * @param string $returnUrl 发起登录时所在的页面，登录成功后回到这里
     * @return array
     */
    public static function providerItems($returnUrl = '')
    {
        $settings = Settings::load();
        $providers = ProviderFactory::enabled($settings);
        $extra = '';

        if ('' !== (string) $returnUrl) {
            $extra = '&return=' . rawurlencode(self::encodeReturn($returnUrl));
        }

        $items = [];
        foreach ($providers as $provider) {
            $items[] = [
                'key'   => $provider->key(),
                'label' => $provider->label(),
                // 用站内相对地址：浏览器会按当前域名解析，站点绑定多个域名时不会被带回主域名
                'href'  => Url::path('?oauth=login&provider=' . rawurlencode($provider->key()) . $extra),
                'icon'  => $provider->icon(),
                'svg'   => $provider->svg(),
                'color' => $provider->color(),
            ];
        }

        return $items;
    }

    /**
     * 开启前台输出缓冲：页面渲染结束后再注入登录入口
     */
    public static function bufferStart()
    {
        if (self::$buffering || 'cli' === PHP_SAPI) {
            return;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && 'GET' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            return;
        }

        // PJAX 只返回内容片段，不需要导航栏入口
        if (!empty($_SERVER['HTTP_X_PJAX'])) {
            return;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';

        if ('' !== $accept && false === strpos($accept, 'text/html') && false === strpos($accept, '*/*')) {
            return;
        }

        self::$obLevel = ob_get_level();
        self::$buffering = true;

        ob_start();
        register_shutdown_function([__CLASS__, 'bufferEnd']);
    }

    /**
     * 结束缓冲并注入
     */
    public static function bufferEnd()
    {
        if (!self::$buffering) {
            return;
        }

        self::$buffering = false;

        while (ob_get_level() > self::$obLevel) {
            ob_end_flush();
        }

        if (ob_get_level() !== self::$obLevel) {
            return;
        }

        $html = ob_get_clean();

        if (!is_string($html) || '' === $html) {
            echo $html;

            return;
        }

        try {
            echo self::injectFront($html);
        } catch (\Throwable $e) {
            echo $html;
        }
    }

    /**
     * 把第三方登录按钮插入到前台登录弹窗里
     *
     * @param string $html
     * @return string
     */
    private static function injectFront($html)
    {
        if (false === strpos($html, '</head>') && false === strpos($html, '</HEAD>')) {
            return $html;
        }

        $settings = Settings::load();

        if ('1' !== (string) $settings->showOnFront) {
            return $html;
        }

        if (self::hasLogin()) {
            return $html;
        }

        $items = self::providerItems(self::safeReturnUrl(self::currentUrl()));

        if (empty($items)) {
            return $html;
        }

        // 主题自带的登录面板（右上角钥匙图标展开的那个）
        if (!preg_match('/<div[^>]*id="login_container"[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $formEnd = stripos($html, '</form>', $match[0][1]);

        if (false === $formEnd) {
            return $html;
        }

        $insertAt = $formEnd + strlen('</form>');
        $html = substr($html, 0, $insertAt) . self::frontLoginBlock($items) . substr($html, $insertAt);

        $headPos = stripos($html, '</head>');

        if (false !== $headPos) {
            $html = substr_replace($html, self::frontStyle() . self::frontScript(), $headPos, 0);
        }

        return $html;
    }

    /**
     * 前台脚本：点击按钮时用当前地址覆盖链接里的 return
     *
     * 主题启用了 PJAX 与常驻的登录面板，面板 DOM 可能来自上一个页面（例如从首页进入文章后，
     * 面板里仍是首页渲染时的链接），因此「登录后回到哪里」必须在点击这一刻计算。
     *
     * @return string
     */
    private static function frontScript()
    {
        $js = <<<'JS'
(function () {
  function enc(s) {
    try {
      return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    } catch (e) {
      return "";
    }
  }
  function build(a) {
    if (!a || !a.href) return null;
    var here = String(location.href).split("#")[0];
    if (/[?&](oauth=login|code=|error=)/.test(here)) return null;
    var code = enc(here);
    if (!code) return null;
    try {
      var u = new URL(a.href, location.href);
      u.searchParams.set("return", code);
      return u.href;
    } catch (e) {
      return a.href + (a.href.indexOf("?") > -1 ? "&" : "?") + "return=" + code;
    }
  }
  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target && e.target.closest ? e.target.closest("a.oauth-front-item") : null;
    if (!a) return;
    var next = build(a);
    if (!next) return;
    e.preventDefault();
    location.href = next;
  }, true);
})();
JS;

        return '<script>' . $js . '</script>';
    }

    /**
     * 当前是否已登录
     *
     * @return bool
     */
    private static function hasLogin()
    {
        try {
            return (bool) User::alloc()->hasLogin();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 登录弹窗内的第三方登录板块
     *
     * @param array $items
     * @return string
     */
    private static function frontLoginBlock(array $items)
    {
        $html = '<div class="oauth-front-login">';
        $html .= '<div class="oauth-front-divider"><span>或使用第三方账号登录</span></div>';

        foreach ($items as $item) {
            $html .= '<a class="oauth-front-item" href="' . self::esc($item['href']) . '" rel="nofollow"'
                . ' title="' . self::esc($item['label']) . '">'
                . self::frontIcon($item)
                . '<span class="oauth-front-text">' . self::esc($item['label']) . '</span>'
                . '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * 图标
     *
     * @param array $item
     * @return string
     */
    private static function frontIcon(array $item)
    {
        if ('' !== (string) $item['icon']) {
            return '<span class="oauth-front-icon"><img src="' . self::esc($item['icon'])
                . '" alt="" loading="lazy"></span>';
        }

        if ('' !== (string) $item['svg']) {
            return '<span class="oauth-front-icon">' . $item['svg'] . '</span>';
        }

        return '<span class="oauth-front-icon oauth-front-icon-text">'
            . self::esc(mb_substr((string) $item['label'], 0, 1)) . '</span>';
    }

    /**
     * HTML 转义
     *
     * @param string $value
     * @return string
     */
    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 前台样式（作用于主题登录弹窗内部）
     *
     * @return string
     */
    private static function frontStyle()
    {
        return '<style>'
            . '#user_panel .oauth-front-login{margin-top:18px}'
            . '#user_panel .oauth-front-divider{display:flex;align-items:center;margin:0 0 12px;'
            . 'color:#b5b5b5;font-size:12px;line-height:1}'
            . '#user_panel .oauth-front-divider::before,#user_panel .oauth-front-divider::after{content:\'\';'
            . 'flex:1 1 auto;height:0;border-top:1px solid #ececec}'
            . '#user_panel .oauth-front-divider span{padding:0 10px;white-space:nowrap}'
            . '#user_panel .oauth-front-item{display:flex;align-items:center;justify-content:center;'
            . 'box-sizing:border-box;width:100%;height:40px;margin-bottom:10px;padding:0 14px;'
            . 'border:1px solid #e3e3e0;border-radius:20px;background:#fff;color:#5a5a5a;font-size:14px;'
            . 'font-weight:400;line-height:1;text-decoration:none;'
            . 'transition:border-color .22s,color .22s,background-color .22s}'
            . '#user_panel .oauth-front-item:last-child{margin-bottom:0}'
            . '#user_panel .oauth-front-item:hover,#user_panel .oauth-front-item:focus{border-color:#23A6D5;'
            . 'background:#F1F9FD;color:#1C8FBA;text-decoration:none}'
            . '#user_panel .oauth-front-icon{display:inline-flex;align-items:center;justify-content:center;'
            . 'width:20px;height:20px;margin-right:8px;flex:0 0 auto;overflow:hidden;border-radius:50%}'
            . '#user_panel .oauth-front-icon img{display:block;width:20px;height:20px;object-fit:cover}'
            . '#user_panel .oauth-front-icon svg{display:block;width:20px;height:20px}'
            . '#user_panel .oauth-front-icon-text{background:rgba(128,128,128,.15);font-size:11px;line-height:1}'
            . '#user_panel .oauth-front-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '</style>';
    }

    /**
     * 回调地址
     *
     * @param Config $settings
     * @return string
     */
    public static function redirectUri(Config $settings)
    {
        return Url::redirectUri((string) $settings->redirectUri);
    }

    /**
     * 登录后落地页（无来源页时的兜底）
     *
     * @param array $user
     * @return string
     */
    private static function homeUrl(array $user)
    {
        $staff = ['administrator', 'editor', 'contributor'];

        if (in_array((string) $user['group'], $staff, true)) {
            return Url::admin();
        }

        return Url::base();
    }

    /**
     * 当前访问的完整地址（用于发起登录时记录来源页）
     *
     * @return string
     */
    private static function currentUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if ('' === $uri) {
            return '';
        }

        // 已经是接口地址，不再套娃
        if (false !== strpos($uri, 'oauth=')) {
            return '';
        }

        return Url::base() . $uri;
    }

    /**
     * 校验来源页，只允许同站地址（防开放重定向）
     *
     * @param string $raw
     * @return string 合法则返回绝对地址，否则返回空串
     */
    private static function safeReturnUrl($raw)
    {
        $raw = trim((string) $raw);

        if ('' === $raw) {
            return '';
        }

        // 站内相对路径（排除协议相对地址 //host）
        if ('/' === substr($raw, 0, 1) && '//' !== substr($raw, 0, 2)) {
            $raw = Url::base() . $raw;
        }

        $parts = parse_url($raw);

        if (empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '';
        }

        // 只允许站点自身以及设置里额外列出的域名，避免 Host 被伪造后变成开放重定向
        if (!Url::trusted((string) $parts['host'])) {
            return '';
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '/';

        // 回到登录动作本身没有意义，会形成循环
        if (false !== strpos($path, 'login.php')) {
            return '';
        }

        if (false !== strpos($raw, 'oauth=') || false !== strpos($raw, 'code=')) {
            return '';
        }

        // 换成本次访问的域名，登录态才不会因为跨域名而丢失
        return Url::rewrite($raw);
    }

    /**
     * 是否为站点首页
     *
     * @param string $url
     * @return bool
     */
    private static function isSiteRoot($url)
    {
        $url = rtrim((string) $url, '/');

        foreach ([Url::base(), (string) Options::alloc()->siteUrl] as $candidate) {
            $candidate = rtrim((string) $candidate, '/');

            if ('' !== $candidate && $url === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提供方显示名称
     *
     * @param string $key
     * @return string
     */
    private static function providerLabel($key)
    {
        try {
            return ProviderFactory::create($key, Settings::load())->label();
        } catch (\Throwable $e) {
            return (string) $key;
        }
    }

    /**
     * 来源页编码：base64url，避免与 Cookie 分隔符冲突
     *
     * @param string $url
     * @return string
     */
    private static function encodeReturn($url)
    {
        $url = (string) $url;

        if ('' === $url) {
            return '';
        }

        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * 来源页解码
     *
     * @param string $code
     * @return string
     */
    private static function decodeReturn($code)
    {
        $code = (string) $code;

        if ('' === $code) {
            return '';
        }

        $b64 = strtr($code, '-_', '+/');
        $pad = strlen($b64) % 4;

        if (0 !== $pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $url = base64_decode($b64, true);

        return false === $url ? '' : $url;
    }

    /**
     * 失败跳转
     *
     * @param string $message
     */
    public static function fail($message)
    {
        $message = self::humanize(trim((string) $message));

        if ('' === $message) {
            $message = '第三方登录失败，请稍后再试';
        }

        // 绑定流程出错就回个人设置，不要把用户扔到登录页
        if ('bind' === self::$mode) {
            Profile::notice('绑定失败：' . $message, 'error');
            self::go(Profile::url());
        }

        $url = Url::admin('login.php') . '?oauth_error=' . rawurlencode($message);

        self::go($url);
    }

    /**
     * 把提供方的英文错误转成中文提示
     *
     * @param string $message
     * @return string
     */
    private static function humanize($message)
    {
        $map = [
            'Invalid or expired authorization code' => '授权已过期或已使用，请重新点击第三方登录',
            'Login required'                        => '请先在 OpenID Connect 提供方登录后再授权',
            'Unauthorized'                          => '授权未通过，请重新登录',
            'ClientID cannot be empty'              => '应用配置有误：缺少客户端 ID',
            'Invalid redirect_uri'                  => '回调地址与平台登记不一致，请检查插件设置',
            'Invalid client'                        => '客户端凭据无效，请检查插件设置',
            'access_denied'                         => '你已取消授权',
        ];

        foreach ($map as $needle => $hint) {
            if (false !== stripos($message, $needle)) {
                return $hint;
            }
        }

        if (preg_match('/^[\\x20-\\x7E]+$/', $message)) {
            return '第三方登录失败：' . $message;
        }

        return $message;
    }

    /**
     * 302 跳转并结束
     *
     * @param string $url
     */
    private static function go($url)
    {
        // 不触碰 Typecho 自身的输出缓冲：它依赖缓冲层发送响应头
        self::$buffering = false;

        try {
            Options::alloc()->response->redirect($url);
        } catch (\Throwable $e) {
            header('Location: ' . $url);
        }

        exit;
    }
}
