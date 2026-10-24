<?php

namespace TypechoPlugin\OAuthLogin\Provider;

use TypechoPlugin\OAuthLogin\Lib\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * QQ 互联（OAuth 2.0）
 */
class Qq extends AbstractProvider
{
    public function key()
    {
        return 'qq';
    }

    public function label()
    {
        return 'QQ';
    }

    public function icon()
    {
        return '';
    }

    public function color()
    {
        return '#12B7F5';
    }

    public function svg()
    {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#12B7F5"/>'
            . '<text x="12" y="12" text-anchor="middle" dominant-baseline="central" fill="#fff"'
            . ' font-family="Helvetica,Arial,sans-serif" font-size="9" font-weight="700">QQ</text></svg>';
    }

    public function authorizeUrl($state, $redirectUri)
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => (string) $this->settings->qqAppId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'get_user_info',
        ]);

        return 'https://graph.qq.com/oauth2.0/authorize?' . $query;
    }

    public function fetchProfile($code, $redirectUri)
    {
        $accessToken = $this->exchangeToken($code, $redirectUri);
        $openid = $this->fetchOpenid($accessToken);

        $res = Http::get('https://graph.qq.com/user/get_user_info?' . http_build_query([
            'access_token'       => $accessToken,
            'oauth_consumer_key' => (string) $this->settings->qqAppId,
            'openid'             => $openid,
            'format'             => 'json',
        ]));

        $json = Http::json($res['body']);

        if (!empty($json['ret']) && 0 !== (int) $json['ret']) {
            throw new \Exception('QQ 用户信息获取失败：' . (string) ($json['msg'] ?? $json['ret']));
        }

        $profile = $this->normalize($json);
        $profile['id'] = $openid;

        if ('' === $profile['name']) {
            $profile['name'] = 'QQ 用户';
        }

        return $profile;
    }

    /**
     * 换取 access_token
     *
     * @param string $code
     * @param string $redirectUri
     * @return string
     */
    private function exchangeToken($code, $redirectUri)
    {
        $res = Http::get('https://graph.qq.com/oauth2.0/token?' . http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => (string) $this->settings->qqAppId,
            'client_secret' => (string) $this->settings->qqAppKey,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'fmt'           => 'json',
        ]));

        $json = Http::json($res['body']);

        if (empty($json['access_token'])) {
            // 兼容 urlencoded 响应
            parse_str($res['body'], $parsed);
            if (!empty($parsed['access_token'])) {
                return (string) $parsed['access_token'];
            }

            throw new \Exception('QQ 授权失败：' . Http::errorMessage($res, $json));
        }

        return (string) $json['access_token'];
    }

    /**
     * 获取 openid
     *
     * @param string $accessToken
     * @return string
     */
    private function fetchOpenid($accessToken)
    {
        $res = Http::get('https://graph.qq.com/oauth2.0/me?' . http_build_query([
            'access_token' => $accessToken,
            'fmt'          => 'json',
        ]));

        $json = Http::json($res['body']);

        if (empty($json['openid'])) {
            // 兼容 callback( {...} ); 形式
            if (preg_match('/\{.*\}/s', $res['body'], $matches)) {
                $json = Http::json($matches[0]);
            }
        }

        if (empty($json['openid'])) {
            throw new \Exception('QQ 未返回 openid');
        }

        return (string) $json['openid'];
    }
}
