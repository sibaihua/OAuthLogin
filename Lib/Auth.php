<?php

namespace TypechoPlugin\OAuthLogin\Lib;

use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 账号关联与登录
 */
class Auth
{
    /**
     * 根据第三方资料找到/创建本站用户
     *
     * @param string $providerKey
     * @param array $profile
     * @param Config $settings
     * @param array $token
     * @return array 用户行
     * @throws \Exception
     */
    public static function resolve($providerKey, array $profile, Config $settings, array $token = [])
    {
        $db = Db::get();

        $openid = (string) $profile['id'];

        if ('' === $openid) {
            throw new \Exception('第三方账号返回值异常，缺少唯一标识');
        }

        // 1. 已绑定
        $bind = Store::findByOpenid($providerKey, $openid);
        if (!empty($bind['uid'])) {
            $user = self::userById((int) $bind['uid']);
            if ($user) {
                Store::bind((int) $user['uid'], $providerKey, $profile, $token);
                return $user;
            }
            Store::deleteById((int) $bind['id']);
        }

        // 2. 邮箱匹配已有账号
        $uid = 0;
        $email = trim((string) ($profile['email'] ?? ''));

        if ('1' === (string) $settings->emailMatch && '' !== $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row = $db->fetchRow(
                $db->select()->from('table.users')->where('mail = ?', $email)->limit(1)
            );
            if (!empty($row['uid'])) {
                $uid = (int) $row['uid'];
            }
        }

        // 3. 自动注册
        if (!$uid) {
            if ('1' !== (string) $settings->autoRegister) {
                throw new \Exception('该第三方账号尚未绑定本站用户，请先登录后绑定');
            }

            $uid = self::createUser($profile, (string) $settings->defaultGroup, $providerKey);
        }

        Store::bind($uid, $providerKey, $profile, $token);

        $user = self::userById($uid);

        if (!$user) {
            throw new \Exception('用户创建失败');
        }

        return $user;
    }

    /**
     * 让 Typecho 记住登录状态
     *
     * @param int $uid
     * @param int $expire
     */
    public static function commit($uid, $expire = 0)
    {
        $widget = User::alloc();
        $row = ['uid' => (int) $uid];
        $widget->commitLogin($row, (int) $expire);
    }

    /**
     * 按主键取用户
     *
     * @param int $uid
     * @return array|null
     */
    public static function userById($uid)
    {
        $db = Db::get();

        $row = $db->fetchRow(
            $db->select()->from('table.users')->where('uid = ?', (int) $uid)->limit(1)
        );

        if (empty($row) || empty($row['activated'])) {
            return null;
        }

        return $row;
    }

    /**
     * 建号
     *
     * @param array $profile
     * @param string $group
     * @param string $providerKey
     * @return int
     * @throws \Exception
     */
    private static function createUser(array $profile, $group, $providerKey)
    {
        $db = Db::get();
        $now = time();

        $nickname = trim((string) ($profile['name'] ?? ''));
        if ('' === $nickname) {
            $nickname = '新用户';
        }

        $name = self::uniqueName($db, $nickname);
        $mail = self::uniqueMail($db, (string) ($profile['email'] ?? ''), $name, $providerKey);

        $allowed = ['administrator', 'editor', 'contributor', 'subscriber', 'visitor'];
        if (!in_array($group, $allowed, true)) {
            $group = 'contributor';
        }

        $rows = [
            'name'       => $name,
            'password'   => Common::hash(Common::randString(20)),
            'mail'       => $mail,
            'url'        => '',
            'screenName' => self::cut($nickname, 32),
            'created'    => $now,
            'activated'  => $now,
            'logged'     => 0,
            'group'      => $group,
            'authCode'   => '',
        ];

        $uid = $db->query($db->insert('table.users')->rows($rows));

        return (int) $uid;
    }

    /**
     * 生成唯一用户名
     *
     * @param Db $db
     * @param string $nickname
     * @return string
     */
    private static function uniqueName(Db $db, $nickname)
    {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '', $nickname);
        $base = strtolower(substr((string) $base, 0, 18));

        if ('' === $base || preg_match('/^[0-9\-_]+$/', $base)) {
            $base = 'u' . strtolower(Common::randString(6));
        }

        $name = $base;
        $i = 1;

        while (true) {
            $exists = $db->fetchRow(
                $db->select()->from('table.users')->where('name = ?', $name)->limit(1)
            );

            if (empty($exists)) {
                return $name;
            }

            $i++;
            $name = $base . $i;

            if ($i > 9999) {
                $name = $base . Common::randString(6);
                return $name;
            }
        }
    }

    /**
     * 生成唯一邮箱
     *
     * @param Db $db
     * @param string $email
     * @param string $name
     * @param string $providerKey
     * @return string
     */
    private static function uniqueMail(Db $db, $email, $name, $providerKey)
    {
        $candidate = '';

        if ('' !== $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $exists = $db->fetchRow(
                $db->select()->from('table.users')->where('mail = ?', $email)->limit(1)
            );

            if (empty($exists)) {
                return $email;
            }

            $candidate = $name . '+' . $providerKey . '@' . self::mailDomain($email);
        }

        if ('' === $candidate) {
            $candidate = $name . '@' . $providerKey . '.local';
        }

        return $candidate;
    }

    /**
     * 取邮箱域名
     *
     * @param string $email
     * @return string
     */
    private static function mailDomain($email)
    {
        $pos = strrpos($email, '@');

        return false === $pos ? 'local' : substr($email, $pos + 1);
    }

    /**
     * 截断
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
