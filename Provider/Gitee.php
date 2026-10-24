<?php

namespace TypechoPlugin\OAuthLogin\Provider;

use TypechoPlugin\OAuthLogin\Lib\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Gitee
 */
class Gitee extends AbstractProvider
{
    public function key()
    {
        return 'gitee';
    }

    public function label()
    {
        return 'Gitee';
    }

    public function icon()
    {
        return '';
    }

    public function color()
    {
        return '#C71D23';
    }

    public function svg()
    {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#C71D23"/>'
            . '<text x="12" y="12" text-anchor="middle" dominant-baseline="central" fill="#fff"'
            . ' font-family="Helvetica,Arial,sans-serif" font-size="8" font-weight="700">Gitee</text></svg>';
    }

    public function authorizeUrl($state, $redirectUri)
    {
        $query = http_build_query([
            'client_id'     => (string) $this->settings->giteeClientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'user_info emails',
            'state'         => $state,
        ]);

        return 'https://gitee.com/oauth/authorize?' . $query;
    }

    public function fetchProfile($code, $redirectUri)
    {
        $res = Http::postForm('https://gitee.com/oauth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => (string) $this->settings->giteeClientId,
            'client_secret' => (string) $this->settings->giteeClientSecret,
            'redirect_uri'  => $redirectUri,
        ]);

        $json = Http::json($res['body']);

        if (empty($json['access_token'])) {
            throw new \Exception('Gitee 授权失败：' . Http::errorMessage($res, $json));
        }

        $res = Http::get('https://gitee.com/api/v5/user?' . http_build_query([
            'access_token' => (string) $json['access_token'],
        ]));

        $user = Http::json($res['body']);

        if (empty($user['id'])) {
            throw new \Exception('Gitee 用户信息获取失败：' . Http::errorMessage($res, $user));
        }

        $profile = $this->normalize($user);
        $profile['id'] = (string) $user['id'];

        return $profile;
    }
}
