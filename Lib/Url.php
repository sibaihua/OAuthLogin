<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 访问域名识别
 *
 * Typecho 的站点地址（siteUrl）是在后台写死的，当一个站同时绑定了多个域名时，
 * 前台与后台的第三方登录链接会一律指回写死的那个域名：访客在 A 域名点登录，
 * 却被送到 B 域名完成授权，而 Typecho 的登录态是按域名隔离的，
 * 于是就出现「授权成功但仍然未登录」「登录后跳回别的域名」这类问题。
 *
 * 这里负责识别本次请求真正使用的域名，凡是要交给浏览器的地址都改用它；
 * 为避免 Host 头被伪造把用户带去陌生站点，只信任站点地址本身与设置里额外列出的域名。
 */
class Url
{
    /**
     * 本次请求的来源（scheme://host[:port]）
     *
     * @var string|null
     */
    private static $origin = null;

    /**
     * 本次请求的域名，识别失败返回空串
     *
     * @return string
     */
    public static function origin()
    {
        if (null !== self::$origin) {
            return self::$origin;
        }

        self::$origin = self::detectOrigin();

        return self::$origin;
    }

    /**
     * 站点根地址：跟随访问域名时用当前域名，否则用 Typecho 的站点地址
     *
     * @return string
     */
    public static function base()
    {
        $site = '';

        try {
            $site = rtrim((string) Options::alloc()->siteUrl, '/');
        } catch (\Throwable $e) {
            // 取不到就用空值，下面兜底
        }

        if (!self::dynamic()) {
            return $site;
        }

        $origin = self::origin();

        if ('' === $origin || !self::trusted($origin)) {
            return $site;
        }

        return $origin . self::prefix();
    }

    /**
     * 站点内的相对地址（以 / 开头），交给浏览器时自动套用当前域名
     *
     * 用在 HTML 的 href / form 上比绝对地址稳妥：即使页面被 CDN 缓存、
     * 或者站点同时响应多个域名，也不会把用户带去别的域名。
     *
     * @param string $relative 例如 ?oauth=login&provider=oidc
     * @return string
     */
    public static function path($relative)
    {
        $relative = (string) $relative;

        if ('' === $relative) {
            return self::prefix() . '/';
        }

        return self::prefix() . '/' . ltrim($relative, '/');
    }

    /**
     * 后台地址（绝对地址，域名跟随当前访问）
     *
     * @param string $file 例如 login.php
     * @return string
     */
    public static function admin($file = '')
    {
        $admin = '';

        try {
            $admin = (string) Options::alloc()->adminUrl;
        } catch (\Throwable $e) {
            // 忽略
        }

        if ('' === $admin) {
            return self::base() . '/admin/' . ltrim((string) $file, '/');
        }

        return self::rewrite($admin . ltrim((string) $file, '/'));
    }

    /**
     * 把绝对地址的域名换成本次访问的域名（保留路径与参数）
     *
     * @param string $url
     * @return string
     */
    public static function rewrite($url)
    {
        $url = trim((string) $url);

        if ('' === $url || !self::dynamic()) {
            return $url;
        }

        $origin = self::origin();

        if ('' === $origin || !self::trusted($origin)) {
            return $url;
        }

        $parts = parse_url($url);

        if (empty($parts['host'])) {
            // 相对地址：本来就是跟当前域名走，不用改
            return $url;
        }

        if (strtolower((string) $parts['host']) === strtolower((string) parse_url($origin, PHP_URL_HOST))) {
            return $url;
        }

        $rewritten = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://[^/?\#]+#', $origin, $url, 1);

        return (is_string($rewritten) && '' !== $rewritten) ? $rewritten : $url;
    }

    /**
     * 回调地址
     *
     * 留空时按当前访问域名生成；填了固定地址时，若开启了「跟随访问域名」，
     * 只把其中的域名换掉，路径与参数保持原样。
     *
     * @param string $configured
     * @return string
     */
    public static function redirectUri($configured = '')
    {
        $configured = trim((string) $configured);
        $fallback = self::base() . '/';

        if ('' === $configured) {
            return $fallback;
        }

        if (!self::dynamic()) {
            return $configured;
        }

        $origin = self::origin();

        if ('' === $origin || !self::trusted($origin)) {
            return $configured;
        }

        $rewritten = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://[^/?\#]+#', $origin, $configured, 1);

        return (is_string($rewritten) && '' !== $rewritten) ? $rewritten : $configured;
    }

    /**
     * 允许跟随的域名（小写，不含端口）
     *
     * @return array
     */
    public static function allowedHosts()
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $hosts = [];

        try {
            $options = Options::alloc();

            foreach (['siteUrl', 'adminUrl'] as $name) {
                $host = self::hostOf((string) $options->{$name});

                if ('' !== $host) {
                    $hosts[] = $host;
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        try {
            $extra = (string) Settings::load()->extraHosts;

            foreach (preg_split('/[\s,;]+/', $extra) as $item) {
                $host = self::hostOf((string) $item);

                if ('' !== $host) {
                    $hosts[] = $host;
                }
            }
        } catch (\Throwable $e) {
            // 忽略
        }

        $cache = array_values(array_unique($hosts));

        return $cache;
    }

    /**
     * 该地址（或域名）是否允许跟随
     *
     * @param string $origin
     * @return bool
     */
    public static function trusted($origin)
    {
        $host = self::hostOf((string) $origin);

        if ('' === $host) {
            return false;
        }

        return in_array($host, self::allowedHosts(), true);
    }

    /**
     * 是否开启「跟随访问域名」
     *
     * @return bool
     */
    private static function dynamic()
    {
        try {
            return '1' === (string) Settings::load()->followHost;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 站点安装目录（例如 /blog），根目录返回空串
     *
     * @return string
     */
    private static function prefix()
    {
        try {
            $path = (string) parse_url((string) Options::alloc()->siteUrl, PHP_URL_PATH);
        } catch (\Throwable $e) {
            return '';
        }

        $path = rtrim($path, '/');

        return ('' === $path || '/' === $path) ? '' : $path;
    }

    /**
     * 从地址或裸域名里取主机名
     *
     * @param string $value
     * @return string
     */
    private static function hostOf($value)
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        $value = preg_replace('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', (string) $value);
        $value = preg_replace('/:\d+$/', '', (string) $value);

        return strtolower((string) $value);
    }

    /**
     * 识别本次请求的域名
     *
     * @return string
     */
    private static function detectOrigin()
    {
        if ('cli' === PHP_SAPI) {
            return '';
        }

        $host = '';

        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];

            $port = isset($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '';

            if ('' !== $port && !in_array($port, ['80', '443'], true)) {
                $host .= ':' . $port;
            }
        }

        $host = self::cleanHost($host);

        if ('' === $host) {
            return '';
        }

        return self::scheme() . '://' . $host;
    }

    /**
     * 校验 Host 头，只放行正常的域名 / IPv4 / 带端口的写法
     *
     * @param string $host
     * @return string
     */
    private static function cleanHost($host)
    {
        $host = strtolower(trim((string) $host));

        if ('' === $host) {
            return '';
        }

        if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host) && !preg_match('/^\[[0-9a-f:.]+\](:\d{1,5})?$/', $host)) {
            return '';
        }

        return $host;
    }

    /**
     * 本次请求是 http 还是 https（兼容反代）
     *
     * @return string
     */
    private static function scheme()
    {
        $https = false;

        if (!empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS'])) {
            $https = true;
        }

        if (isset($_SERVER['SERVER_PORT']) && '443' === (string) $_SERVER['SERVER_PORT']) {
            $https = true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $proto = strtolower(trim((string) $parts[0]));

            if ('https' === $proto) {
                $https = true;
            } elseif ('http' === $proto) {
                $https = false;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && 'on' === strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL'])) {
            $https = true;
        }

        return $https ? 'https' : 'http';
    }
}
