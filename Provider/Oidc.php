<?php

namespace TypechoPlugin\OAuthLogin\Provider;

use TypechoPlugin\OAuthLogin\Lib\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 通用 OpenID Connect 提供方（默认对接司白画通行证 / Cloudreve）
 */
class Oidc extends AbstractProvider
{
    /**
     * 发现文档缓存
     *
     * @var array|null
     */
    private $meta = null;

    public function key()
    {
        return 'oidc';
    }

    public function label()
    {
        $label = (string) $this->settings->passportLabel;

        return '' !== $label ? $label : '司白画通行证';
    }

    public function icon()
    {
        return (string) $this->settings->passportIcon;
    }

    public function color()
    {
        return '#467B96';
    }

    /**
     * 提供方根地址
     *
     * @return string
     */
    private function base()
    {
        $base = (string) $this->settings->passportBase;

        return rtrim('' !== $base ? $base : 'https://pan.sibh.cn', '/');
    }

    /**
     * 读取 OpenID Connect 发现文档，失败时回退到 Cloudreve 的固定端点
     *
     * @return array
     */
    private function meta()
    {
        if (null !== $this->meta) {
            return $this->meta;
        }

        $base = $this->base();
        $fallback = [
            'authorization_endpoint' => $base . '/session/authorize',
            'token_endpoint'         => $base . '/api/v4/session/oauth/token',
            'userinfo_endpoint'      => $base . '/api/v4/session/oauth/userinfo',
        ];

        $res = Http::get($base . '/.well-known/openid-configuration');
        $json = Http::json($res['body']);

        if (!empty($json['authorization_endpoint']) && !empty($json['token_endpoint'])) {
            $this->meta = array_merge($fallback, $json);
        } else {
            $this->meta = $fallback;
        }

        return $this->meta;
    }

    public function authorizeUrl($state, $redirectUri)
    {
        $meta = $this->meta();

        $scopes = (string) $this->settings->passportScopes;
        if ('' === trim($scopes)) {
            $scopes = 'openid email profile';
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => (string) $this->settings->passportClientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => $scopes,
            'state'         => $state,
        ]);

        $endpoint = $meta['authorization_endpoint'];

        return $endpoint . (false === strpos($endpoint, '?') ? '?' : '&') . $query;
    }

    public function fetchProfile($code, $redirectUri)
    {
        $token = $this->exchangeToken($code, $redirectUri);
        $meta = $this->meta();

        $res = Http::get($meta['userinfo_endpoint'], [
            'Authorization: Bearer ' . $token['access_token'],
        ]);

        $json = Http::json($res['body']);

        if (empty($json)) {
            throw new \Exception('无法解析用户信息响应');
        }

        // 部分实现用 {code, msg, data} 包裹，且错误也返回 HTTP 200
        if (isset($json['code']) && (int) $json['code'] >= 400) {
            throw new \Exception(Http::errorMessage($res, $json));
        }

        $data = $this->extractClaims($json);
        $profile = $this->normalize($data);

        if ('' === $profile['id']) {
            throw new \Exception('用户信息中没有可用的唯一标识（sub）');
        }

        return $profile;
    }

    /**
     * 从嵌套结构里取出 claims
     *
     * @param array $json
     * @return array
     */
    private function extractClaims(array $json)
    {
        if (isset($json['data']) && is_array($json['data'])) {
            $json = $json['data'];
        }

        foreach (['user', 'profile', 'claims', 'info', 'data'] as $key) {
            if (!isset($json['sub']) && !isset($json['id']) && !isset($json['openid'])
                && isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
            }
        }

        return $json;
    }

    /**
     * 用授权码换取令牌
     *
     * @param string $code
     * @param string $redirectUri
     * @return array
     */
    private function exchangeToken($code, $redirectUri)
    {
        $meta = $this->meta();

        $res = Http::postForm($meta['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => (string) $this->settings->passportClientId,
            'client_secret' => (string) $this->settings->passportClientSecret,
            'redirect_uri'  => $redirectUri,
        ]);

        $json = Http::json($res['body']);

        $accessToken = '';

        if (!empty($json['access_token'])) {
            $accessToken = (string) $json['access_token'];
        } elseif (isset($json['data']['access_token'])) {
            $accessToken = (string) $json['data']['access_token'];
        }

        if ('' === $accessToken) {
            throw new \Exception(Http::errorMessage($res, $json));
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => (string) ($json['refresh_token'] ?? ''),
            'expires_at'    => !empty($json['expires_in']) ? time() + (int) $json['expires_in'] : 0,
        ];
    }
}
