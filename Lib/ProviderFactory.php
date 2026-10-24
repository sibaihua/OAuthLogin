<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Typecho\Config;
use TypechoPlugin\OAuthLogin\Provider\Gitee;
use TypechoPlugin\OAuthLogin\Provider\Github;
use TypechoPlugin\OAuthLogin\Provider\Oidc;
use TypechoPlugin\OAuthLogin\Provider\Qq;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 登录提供方工厂
 */
class ProviderFactory
{
    /**
     * 创建指定提供方
     *
     * @param string $key
     * @param Config $settings
     * @return \TypechoPlugin\OAuthLogin\Provider\AbstractProvider
     * @throws \Exception
     */
    public static function create($key, Config $settings)
    {
        switch ($key) {
            case 'oidc':
                return new Oidc($settings);
            case 'qq':
                return new Qq($settings);
            case 'github':
                return new Github($settings);
            case 'gitee':
                return new Gitee($settings);
        }

        throw new \Exception('不支持的登录方式');
    }

    /**
     * 全部已启用提供方
     *
     * @param Config $settings
     * @return array
     */
    public static function enabled(Config $settings)
    {
        $list = [];

        foreach (Settings::enabledProviders() as $key) {
            try {
                $list[] = self::create($key, $settings);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $list;
    }
}
