<?php

namespace TypechoPlugin\OAuthLogin\Provider;

use Typecho\Config;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 登录提供方基类
 */
abstract class AbstractProvider
{
    /**
     * @var Config
     */
    protected $settings;

    /**
     * @param Config $settings
     */
    public function __construct(Config $settings)
    {
        $this->settings = $settings;
    }

    /**
     * 唯一标识
     *
     * @return string
     */
    abstract public function key();

    /**
     * 显示名称
     *
     * @return string
     */
    abstract public function label();

    /**
     * 图标地址（可为空）
     *
     * @return string
     */
    abstract public function icon();

    /**
     * 授权地址
     *
     * @param string $state
     * @param string $redirectUri
     * @return string
     */
    abstract public function authorizeUrl($state, $redirectUri);

    /**
     * 用 code 换取用户资料
     *
     * @param string $code
     * @param string $redirectUri
     * @return array ['id' => '', 'name' => '', 'avatar' => '', 'email' => '']
     */
    abstract public function fetchProfile($code, $redirectUri);

    /**
     * 内联 SVG 图标（优先于 icon 地址）
     *
     * @return string
     */
    public function svg()
    {
        return '';
    }

    /**
     * 按钮配色（用于 hover 时的高亮）
     *
     * @return string
     */
    public function color()
    {
        return '#467B96';
    }

    /**
     * 从多个可能字段中取值
     *
     * @param array $data
     * @param array $keys
     * @return string
     */
    protected function pick(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && '' !== (string) $data[$key]) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    /**
     * 统一整理用户资料
     *
     * @param array $data
     * @return array
     */
    protected function normalize(array $data)
    {
        return [
            'id'     => $this->pick($data, ['sub', 'openid', 'id', 'user_id', 'uid', 'unionid']),
            'name'   => $this->pick($data, ['name', 'preferred_username', 'nickname', 'nick', 'login', 'display_name', 'screenName']),
            'avatar' => $this->pick($data, ['picture', 'avatar', 'avatar_url', 'figureurl_qq_2', 'figureurl_qq_1', 'portrait']),
            'email'  => $this->pick($data, ['email', 'mail']),
        ];
    }
}
