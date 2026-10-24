<?php

namespace TypechoPlugin\OAuthLogin\Provider;

use TypechoPlugin\OAuthLogin\Lib\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * GitHub
 */
class Github extends AbstractProvider
{
    public function key()
    {
        return 'github';
    }

    public function label()
    {
        return 'GitHub';
    }

    public function icon()
    {
        return '';
    }

    public function color()
    {
        return '#24292F';
    }

    public function svg()
    {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#24292F" d="M12 .5C5.73.5.5 5.73.5 12c0 5.08 3.29 9.39 7.86 10.91.58.1.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.7-3.88-1.54-3.88-1.54-.53-1.34-1.29-1.7-1.29-1.7-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.23-1.28-5.23-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 015.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.84 1.19 3.1 0 4.43-2.69 5.41-5.25 5.69.41.36.78 1.06.78 2.14 0 1.55-.01 2.8-.01 3.18 0 .31.21.67.8.56A11.51 11.51 0 0023.5 12C23.5 5.73 18.27.5 12 .5z"/></svg>';
    }

    public function authorizeUrl($state, $redirectUri)
    {
        $query = http_build_query([
            'client_id'    => (string) $this->settings->githubClientId,
            'redirect_uri' => $redirectUri,
            'scope'        => 'read:user user:email',
            'state'        => $state,
            'allow_signup' => 'true',
        ]);

        return 'https://github.com/login/oauth/authorize?' . $query;
    }

    public function fetchProfile($code, $redirectUri)
    {
        $res = Http::postForm('https://github.com/login/oauth/access_token', [
            'client_id'     => (string) $this->settings->githubClientId,
            'client_secret' => (string) $this->settings->githubClientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]);

        $json = Http::json($res['body']);

        if (empty($json['access_token'])) {
            throw new \Exception('GitHub 授权失败：' . Http::errorMessage($res, $json));
        }

        $accessToken = (string) $json['access_token'];

        $res = Http::get('https://api.github.com/user', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github+json',
        ]);
        $user = Http::json($res['body']);

        if (empty($user['id'])) {
            throw new \Exception('GitHub 用户信息获取失败：' . Http::errorMessage($res, $user));
        }

        $profile = $this->normalize($user);
        $profile['id'] = (string) $user['id'];

        if ('' === $profile['email']) {
            $profile['email'] = $this->fetchPrimaryEmail($accessToken);
        }

        return $profile;
    }

    /**
     * 获取主邮箱
     *
     * @param string $accessToken
     * @return string
     */
    private function fetchPrimaryEmail($accessToken)
    {
        $res = Http::get('https://api.github.com/user/emails', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github+json',
        ]);

        $list = Http::json($res['body']);

        if (!is_array($list)) {
            return '';
        }

        foreach ($list as $item) {
            if (!empty($item['primary']) && !empty($item['email'])) {
                return (string) $item['email'];
            }
        }

        foreach ($list as $item) {
            if (!empty($item['verified']) && !empty($item['email'])) {
                return (string) $item['email'];
            }
        }

        return '';
    }
}
