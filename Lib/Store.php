<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 第三方账号绑定表
 */
class Store
{
    const TABLE = 'table.oauth_accounts';

    /**
     * 建表
     */
    public static function install()
    {
        $db = Db::get();
        $table = $db->getPrefix() . 'oauth_accounts';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(10) unsigned NOT NULL DEFAULT '0',
            `provider` varchar(32) NOT NULL DEFAULT '',
            `openid` varchar(191) NOT NULL DEFAULT '',
            `nickname` varchar(100) NOT NULL DEFAULT '',
            `avatar` varchar(255) NOT NULL DEFAULT '',
            `email` varchar(150) NOT NULL DEFAULT '',
            `access_token` varchar(1024) NOT NULL DEFAULT '',
            `refresh_token` varchar(1024) NOT NULL DEFAULT '',
            `expires_at` int(10) unsigned NOT NULL DEFAULT '0',
            `created` int(10) unsigned NOT NULL DEFAULT '0',
            `last_login` int(10) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `provider_openid` (`provider`,`openid`),
            KEY `uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $db->query($sql, Db::WRITE);
    }

    /**
     * 按第三方标识查找绑定
     *
     * @param string $provider
     * @param string $openid
     * @return array|null
     */
    public static function findByOpenid($provider, $openid)
    {
        $db = Db::get();

        return $db->fetchRow(
            $db->select()->from(self::TABLE)
                ->where('provider = ?', $provider)
                ->where('openid = ?', $openid)
                ->limit(1)
        );
    }

    /**
     * 按用户查找全部绑定
     *
     * @param int $uid
     * @return array
     */
    public static function findByUid($uid)
    {
        $db = Db::get();

        $rows = $db->fetchAll(
            $db->select()->from(self::TABLE)->where('uid = ?', (int) $uid)
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * 写入绑定
     *
     * @param int $uid
     * @param string $provider
     * @param array $profile
     * @param array $token
     */
    public static function bind($uid, $provider, array $profile, array $token = [])
    {
        $db = Db::get();
        $now = time();

        $existing = self::findByOpenid($provider, $profile['id']);

        $rows = [
            'uid'           => (int) $uid,
            'provider'      => (string) $provider,
            'openid'        => (string) $profile['id'],
            'nickname'      => self::cut((string) ($profile['name'] ?? ''), 100),
            'avatar'        => self::cut((string) ($profile['avatar'] ?? ''), 255),
            'email'         => self::cut((string) ($profile['email'] ?? ''), 150),
            'access_token'  => self::cut((string) ($token['access_token'] ?? ''), 1024),
            'refresh_token' => self::cut((string) ($token['refresh_token'] ?? ''), 1024),
            'expires_at'    => (int) ($token['expires_at'] ?? 0),
            'last_login'    => $now,
        ];

        if ($existing) {
            $db->query($db->update(self::TABLE)->rows($rows)->where('id = ?', (int) $existing['id']));
        } else {
            $rows['created'] = $now;
            $db->query($db->insert(self::TABLE)->rows($rows));
        }
    }

    /**
     * 更新登录时间
     *
     * @param int $id
     */
    public static function touch($id)
    {
        $db = Db::get();
        $db->query($db->update(self::TABLE)->rows(['last_login' => time()])->where('id = ?', (int) $id));
    }

    /**
     * 解绑
     *
     * @param int $uid
     * @param string $provider
     */
    public static function unbind($uid, $provider)
    {
        $db = Db::get();
        $db->query(
            $db->delete(self::TABLE)
                ->where('uid = ?', (int) $uid)
                ->where('provider = ?', (string) $provider)
        );
    }

    /**
     * 删除单条
     *
     * @param int $id
     */
    public static function deleteById($id)
    {
        $db = Db::get();
        $db->query($db->delete(self::TABLE)->where('id = ?', (int) $id));
    }

    /**
     * 多字节截断
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    private static function cut($value, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }
}
