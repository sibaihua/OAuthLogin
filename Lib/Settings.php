<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Typecho\Config;
use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件配置读写
 */
class Settings
{
    const PLUGIN = 'OAuthLogin';

    /**
     * @var Config|null
     */
    private static $cache = null;

    /**
     * 默认配置
     *
     * @return array
     */
    public static function defaults()
    {
        return [
            // 司白画通行证（Cloudreve / OpenID Connect）
            'passportEnable'       => '1',
            'passportLabel'        => '司白画通行证',
            'passportIcon'         => 'https://cdn.sibh.cn/img/2026/07/6a57be4a036e9.png',
            'passportBase'         => 'https://pan.sibh.cn',
            'passportClientId'     => 'ecd1a1d0-d7e1-463d-9cae-1a7def1de83c',
            'passportClientSecret' => '',
            'passportScopes'       => 'openid email profile',

            // QQ 互联
            'qqEnable'     => '0',
            'qqAppId'      => '',
            'qqAppKey'     => '',

            // GitHub
            'githubEnable'       => '0',
            'githubClientId'     => '',
            'githubClientSecret' => '',

            // Gitee
            'giteeEnable'       => '0',
            'giteeClientId'     => '',
            'giteeClientSecret' => '',

            // 通用
            'redirectUri'   => '',
            'showOnLogin'   => '1',
            'showOnFront'   => '1',
            'emailMatch'    => '1',
            'autoRegister'  => '1',
            'defaultGroup'  => 'contributor',
        ];
    }

    /**
     * 确保配置行存在（Typecho 的 Options::plugin() 在无配置时会抛异常）
     */
    public static function ensureDefaults()
    {
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.options')->where('name = ?', 'plugin:' . self::PLUGIN)->limit(1)
        );

        if (empty($row)) {
            $db->query(
                $db->insert('table.options')->rows([
                    'name'  => 'plugin:' . self::PLUGIN,
                    'user'  => 0,
                    'value' => serialize(self::defaults()),
                ])
            );
        }

        self::$cache = null;
    }

    /**
     * 读取配置
     *
     * @return Config
     */
    public static function load()
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $data = self::defaults();

        try {
            $config = Options::alloc()->plugin(self::PLUGIN);
            if (method_exists($config, 'toArray')) {
                $saved = $config->toArray();
            } else {
                $saved = [];
                foreach ($config as $key => $value) {
                    $saved[$key] = $value;
                }
            }
            foreach ($saved as $key => $value) {
                $data[$key] = $value;
            }
        } catch (\Throwable $e) {
            // 未配置过时使用默认值
        }

        if (empty($data['redirectUri'])) {
            $data['redirectUri'] = self::guessRedirectUri();
        }

        self::$cache = new Config($data);

        return self::$cache;
    }

    /**
     * 推断回调地址（站点根）
     *
     * @return string
     */
    public static function guessRedirectUri()
    {
        try {
            return rtrim(Options::alloc()->siteUrl, '/') . '/';
        } catch (\Throwable $e) {
            return '/';
        }
    }

    /**
     * 已启用的登录方式
     *
     * @return array
     */
    public static function enabledProviders()
    {
        $settings = self::load();
        $list = [];

        if ('1' === (string) $settings->passportEnable && '' !== (string) $settings->passportClientId) {
            $list[] = 'oidc';
        }
        if ('1' === (string) $settings->qqEnable && '' !== (string) $settings->qqAppId) {
            $list[] = 'qq';
        }
        if ('1' === (string) $settings->githubEnable && '' !== (string) $settings->githubClientId) {
            $list[] = 'github';
        }
        if ('1' === (string) $settings->giteeEnable && '' !== (string) $settings->giteeClientId) {
            $list[] = 'gitee';
        }

        return $list;
    }
}
