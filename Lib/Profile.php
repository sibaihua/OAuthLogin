<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Widget\Notice;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台「个人设置」里的第三方账号绑定面板
 */
class Profile
{
    /**
     * 解绑表单字段名
     */
    const FIELD = 'oauth_unbind';

    /**
     * 个人设置页地址
     *
     * @return string
     */
    public static function url()
    {
        return Options::alloc()->adminUrl . 'profile.php';
    }

    /**
     * 写入后台提示（Typecho 原生 notice，页面顶部弹出）
     *
     * @param string $message
     * @param string $type notice|success|error
     */
    public static function notice($message, $type = 'notice')
    {
        try {
            Notice::alloc()->set((string) $message, (string) $type);
        } catch (\Throwable $e) {
            // 提示写不进去不影响主流程
        }
    }

    /**
     * 处理解绑提交（挂在 admin/common.php:begin，早于任何输出）
     */
    public static function handleRequest()
    {
        if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '')) {
            return;
        }

        if (empty($_POST[self::FIELD])) {
            return;
        }

        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';

        if (!preg_match('#/admin/profile\.php$#', $script)) {
            return;
        }

        $user = User::alloc();

        if (!$user->hasLogin()) {
            return;
        }

        $uid = (int) $user->uid;
        $provider = strtolower((string) $_POST[self::FIELD]);
        $provider = preg_replace('/[^a-z0-9_]/', '', $provider);
        $token = isset($_POST['_oauth_token']) ? (string) $_POST['_oauth_token'] : '';

        if ('' === $provider || !hash_equals(self::token($uid), $token)) {
            self::notice('解绑校验失败，请刷新页面重试', 'error');
            self::back();
        }

        $rows = Store::findByUid($uid);
        $found = false;

        foreach ($rows as $row) {
            if ($row['provider'] === $provider) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            self::notice('没有找到该绑定', 'error');
            self::back();
        }

        Store::unbind($uid, $provider);

        self::notice('已解绑「' . self::label($provider) . '」', 'success');
        self::back();
    }

    /**
     * 跳回个人设置
     */
    private static function back()
    {
        $url = self::url() . '#personal-OAuthLogin';

        try {
            Options::alloc()->response->redirect($url);
        } catch (\Throwable $e) {
            header('Location: ' . $url);
        }

        exit;
    }

    /**
     * 解绑用的令牌（按用户区分，无需额外存储）
     *
     * @param int $uid
     * @return string
     */
    private static function token($uid)
    {
        $secret = (string) Options::alloc()->secret;

        return substr(hash_hmac('sha256', 'oauth-unbind|' . (int) $uid, $secret), 0, 32);
    }

    /**
     * 面板 HTML（由 Typecho 的 personalConfig 放进个人设置的 section 里）
     *
     * @param int|null $uid 不传则取当前登录用户
     * @return string
     */
    public static function panel($uid = null)
    {
        $uid = (null === $uid) ? (int) User::alloc()->uid : (int) $uid;

        if ($uid <= 0) {
            return '';
        }

        $settings = Settings::load();
        $providers = ProviderFactory::enabled($settings);

        if (empty($providers)) {
            return self::style() . '<p class="oauth-bind-tip" style="margin-top:0">'
                . self::esc(_t('暂未开放第三方账号绑定。')) . '</p>';
        }

        $bound = [];

        foreach (Store::findByUid($uid) as $row) {
            $bound[$row['provider']] = $row;
        }

        $site = rtrim((string) Options::alloc()->siteUrl, '/');

        $html = self::style();
        $html .= '<div class="oauth-bind">';

        foreach ($providers as $provider) {
            $key = $provider->key();
            $row = isset($bound[$key]) ? $bound[$key] : null;

            $html .= '<div class="oauth-bind-row">';
            $html .= '<span class="oauth-bind-icon">' . self::icon($provider) . '</span>';
            $html .= '<div class="oauth-bind-main">';
            $html .= '<div class="oauth-bind-name">' . self::esc($provider->label()) . '</div>';

            if ($row) {
                $meta = [];

                if ('' !== (string) $row['nickname']) {
                    $meta[] = self::esc((string) $row['nickname']);
                }

                if ('' !== (string) $row['email']) {
                    $meta[] = self::esc((string) $row['email']);
                }

                if (!empty($row['created'])) {
                    $meta[] = _t('绑定于 %s', date('Y-m-d', (int) $row['created']));
                }

                $html .= '<div class="oauth-bind-meta">'
                    . '<span class="oauth-bind-ok">' . self::esc(_t('已绑定')) . '</span>';
                if (!empty($meta)) {
                    $html .= ' · ' . implode(' · ', $meta);
                }
                $html .= '</div>';
            } else {
                $html .= '<div class="oauth-bind-meta">' . self::esc(_t('未绑定')) . '</div>';
            }

            $html .= '</div>';

            $html .= '<div class="oauth-bind-action">';

            if ($row) {
                // 面板在 Typecho 的个人设置表单内部，这里用 formaction 提交到个人设置页，
                // 由 admin/common.php:begin 钩子处理，避免嵌套 form
                $html .= '<input type="hidden" name="_oauth_token" value="' . self::esc(self::token($uid)) . '">'
                    . '<button type="submit" class="btn" formmethod="post" formaction="' . self::esc(self::url()) . '"'
                    . ' name="' . self::FIELD . '" value="' . self::esc($key) . '"'
                    . ' onclick="return confirm(\'' . self::esc(_t('确定解绑这个账号吗？解绑后需要改用用户名密码登录。')) . '\');">'
                    . self::esc(_t('解绑')) . '</button>';
            } else {
                $href = $site . '/?oauth=login&provider=' . rawurlencode($key) . '&mode=bind';
                $html .= '<a class="btn primary" href="' . self::esc($href) . '">' . self::esc(_t('绑定')) . '</a>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<p class="oauth-bind-tip">'
            . self::esc(_t('绑定后可以直接用第三方账号登录本站；解绑后请确认自己记得本站的用户名和密码。'))
            . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 提供方图标
     *
     * @param mixed $provider
     * @return string
     */
    private static function icon($provider)
    {
        $icon = (string) $provider->icon();

        if ('' !== $icon) {
            return '<img src="' . self::esc($icon) . '" alt="" width="22" height="22">';
        }

        $svg = (string) $provider->svg();

        if ('' !== $svg) {
            return $svg;
        }

        return '<span class="oauth-bind-initial">'
            . self::esc(mb_substr((string) $provider->label(), 0, 1)) . '</span>';
    }

    /**
     * 提供方名称
     *
     * @param string $key
     * @return string
     */
    private static function label($key)
    {
        try {
            return ProviderFactory::create($key, Settings::load())->label();
        } catch (\Throwable $e) {
            return (string) $key;
        }
    }

    /**
     * 面板样式（沿用后台的浅色风格）
     *
     * @return string
     */
    private static function style()
    {
        return '<style>'
            . '#personal-OAuthLogin .oauth-bind{margin:2px 0 0}'
            . '#personal-OAuthLogin .oauth-bind-row{display:flex;align-items:center;gap:12px;'
            . 'padding:12px 0;border-bottom:1px solid #EFEFEF}'
            . '#personal-OAuthLogin .oauth-bind-row:first-child{padding-top:2px}'
            . '#personal-OAuthLogin .oauth-bind-row:last-child{border-bottom:0;padding-bottom:2px}'
            . '#personal-OAuthLogin .oauth-bind-icon{display:flex;align-items:center;justify-content:center;'
            . 'width:34px;height:34px;flex:0 0 auto;border-radius:50%;background:#F4F6F7;overflow:hidden}'
            . '#personal-OAuthLogin .oauth-bind-icon img{width:22px;height:22px;object-fit:cover;border-radius:50%}'
            . '#personal-OAuthLogin .oauth-bind-icon svg{display:block;width:20px;height:20px}'
            . '#personal-OAuthLogin .oauth-bind-initial{font-size:13px;color:#666}'
            . '#personal-OAuthLogin .oauth-bind-main{flex:1 1 auto;min-width:0}'
            . '#personal-OAuthLogin .oauth-bind-name{font-size:14px;color:#333;line-height:1.6}'
            . '#personal-OAuthLogin .oauth-bind-meta{font-size:12px;color:#999;line-height:1.7;'
            . 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '#personal-OAuthLogin .oauth-bind-ok{color:#5BA267}'
            . '#personal-OAuthLogin .oauth-bind-action{flex:0 0 auto;white-space:nowrap}'
            . '#personal-OAuthLogin .oauth-bind-tip{margin:14px 0 0;color:#999;font-size:12px;line-height:1.8}'
            . '#personal-OAuthLogin .typecho-option-submit{display:none}'
            . '@media (max-width:520px){#personal-OAuthLogin .oauth-bind-meta{white-space:normal}}'
            . '</style>';
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
}
