<?php

namespace TypechoPlugin\OAuthLogin;

use Typecho\Plugin as BasePlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Layout;
use TypechoPlugin\OAuthLogin\Lib\Handler;
use TypechoPlugin\OAuthLogin\Lib\Settings;
use TypechoPlugin\OAuthLogin\Lib\Store;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 为博客接入第三方账号登录（OAuth 2.0 / OpenID Connect），支持司白画通行证、QQ 互联、GitHub、Gitee
 *
 * @package OAuthLogin
 * @author 司白画
 * @version 1.3.0
 * @link https://blog.sibh.cn
 */
class Plugin implements PluginInterface
{
    /**
     * 启用插件
     *
     * @return string
     */
    public static function activate()
    {
        Store::install();
        Settings::ensureDefaults();

        BasePlugin::factory('index.php')->begin = __CLASS__ . '::onIndexBegin';
        BasePlugin::factory('index.php')->end = __CLASS__ . '::onIndexEnd';
        BasePlugin::factory('admin/header.php')->header = __CLASS__ . '::onAdminHeader';

        return _t('第三方登录已启用，请到「设置」中确认应用凭据与回调地址。');
    }

    /**
     * 禁用插件（保留绑定数据，仅移除钩子）
     *
     * @return string
     */
    public static function deactivate()
    {
        return _t('第三方登录已停用，账号绑定数据已保留。');
    }

    /**
     * 后台配置面板
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $style = new Layout('style');
        $style->html(self::configStyle());
        $form->addItem($style);

        $tip = new Layout('div', ['class' => 'oauth-conf-tip']);
        $tip->html(
            '<strong>' . _t('回调地址') . '</strong>：' .
            _t('必须与第三方平台登记的一字不差（含结尾斜杠），否则授权会被拒绝。') . '<br>' .
            '<strong>' . _t('启用方式') . '</strong>：' .
            _t('填入对应平台的凭据并设为「启用」，后台登录页与网站前台就会出现该登录按钮。')
        );
        $form->addItem($tip);

        self::configGroup($form, _t('一、司白画通行证（OpenID Connect）'));

        $passportEnable = new Element\Radio(
            'passportEnable',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '1',
            _t('启用通行证登录'),
            _t('使用 pan.sibh.cn 的统一账号登录本博客')
        );
        $form->addInput($passportEnable);

        $passportLabel = new Element\Text(
            'passportLabel',
            null,
            _t('司白画通行证'),
            _t('按钮文字'),
            _t('登录页上显示的名称')
        );
        $form->addInput($passportLabel);

        $passportIcon = new Element\Text(
            'passportIcon',
            null,
            'https://cdn.sibh.cn/img/2026/07/6a57be4a036e9.png',
            _t('按钮图标'),
            _t('图片地址，留空则只显示文字')
        );
        $form->addInput($passportIcon);

        $passportBase = new Element\Text(
            'passportBase',
            null,
            'https://pan.sibh.cn',
            _t('服务地址'),
            _t('OpenID Connect 提供方根地址，插件会自动读取 <code>/.well-known/openid-configuration</code>')
        );
        $form->addInput($passportBase);

        $passportClientId = new Element\Text(
            'passportClientId',
            null,
            '',
            _t('客户端 ID'),
            _t('Cloudreve 后台「OAuth 应用」中的客户端 ID')
        );
        $form->addInput($passportClientId);

        $passportClientSecret = new Element\Text(
            'passportClientSecret',
            null,
            '',
            _t('客户端密钥'),
            _t('<strong class="warning">请勿泄露</strong>。在 Cloudreve 后台重置后会显示新密钥，此处需同步更新')
        );
        $form->addInput($passportClientSecret);

        $passportScopes = new Element\Text(
            'passportScopes',
            null,
            'openid email profile',
            _t('授权范围'),
            _t('多个范围用空格分隔，通常保持默认即可')
        );
        $form->addInput($passportScopes);

        self::configGroup($form, _t('二、QQ 互联'));

        $qqEnable = new Element\Radio(
            'qqEnable',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '0',
            _t('启用 QQ 登录'),
            _t('需在 QQ 互联开放平台创建网站应用并通过审核')
        );
        $form->addInput($qqEnable);

        $qqAppId = new Element\Text('qqAppId', null, '', _t('APP ID'), _t('QQ 互联应用的 APP ID'));
        $form->addInput($qqAppId);

        $qqAppKey = new Element\Text('qqAppKey', null, '', _t('APP Key'), _t('QQ 互联应用的 APP Key，请勿泄露'));
        $form->addInput($qqAppKey);

        self::configGroup($form, _t('三、GitHub'));

        $githubEnable = new Element\Radio(
            'githubEnable',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '0',
            _t('启用 GitHub 登录'),
            _t('在 GitHub Settings → Developer settings → OAuth Apps 中创建应用')
        );
        $form->addInput($githubEnable);

        $githubClientId = new Element\Text('githubClientId', null, '', _t('Client ID'), '');
        $form->addInput($githubClientId);

        $githubClientSecret = new Element\Text('githubClientSecret', null, '', _t('Client Secret'), _t('请勿泄露'));
        $form->addInput($githubClientSecret);

        self::configGroup($form, _t('四、Gitee'));

        $giteeEnable = new Element\Radio(
            'giteeEnable',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '0',
            _t('启用 Gitee 登录'),
            _t('在 Gitee 设置 → 第三方应用 中创建应用')
        );
        $form->addInput($giteeEnable);

        $giteeClientId = new Element\Text('giteeClientId', null, '', _t('Client ID'), '');
        $form->addInput($giteeClientId);

        $giteeClientSecret = new Element\Text('giteeClientSecret', null, '', _t('Client Secret'), _t('请勿泄露'));
        $form->addInput($giteeClientSecret);

        self::configGroup($form, _t('五、登录与账号策略'));

        $redirectUri = new Element\Text(
            'redirectUri',
            null,
            '',
            _t('回调地址'),
            _t('必须与第三方平台登记的完全一致。站内回调走首页，默认 <code>https://blog.sibh.cn/</code>')
        );
        $form->addInput($redirectUri);

        $showOnLogin = new Element\Radio(
            'showOnLogin',
            ['1' => _t('显示'), '0' => _t('隐藏')],
            '1',
            _t('在后台登录页显示'),
            _t('关闭后仅保留接口，不再向登录页注入按钮')
        );
        $form->addInput($showOnLogin);

        $showOnFront = new Element\Radio(
            'showOnFront',
            ['1' => _t('显示'), '0' => _t('隐藏')],
            '1',
            _t('在网站前台显示'),
            _t('未登录访客打开顶部的登录面板时，用户名密码输入框下方会出现第三方登录按钮')
        );
        $form->addInput($showOnFront);

        $emailMatch = new Element\Radio(
            'emailMatch',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('按邮箱自动关联已有账号'),
            _t('第三方账号邮箱与站内用户邮箱相同时，直接登录该账号（建议保持开启）')
        );
        $form->addInput($emailMatch);

        $autoRegister = new Element\Radio(
            'autoRegister',
            ['1' => _t('开启'), '0' => _t('关闭')],
            '1',
            _t('首次登录自动注册'),
            _t('关闭后，未绑定的第三方账号将无法登录')
        );
        $form->addInput($autoRegister);

        $defaultGroup = new Element\Select(
            'defaultGroup',
            [
                'contributor'   => _t('贡献者'),
                'editor'        => _t('编辑'),
                'visitor'       => _t('访客'),
                'subscriber'    => _t('订阅者'),
                'administrator' => _t('管理员（谨慎）'),
            ],
            'contributor',
            _t('新用户默认用户组'),
            _t('<strong class="warning">设为管理员会让任何能通过第三方验证的人获得后台全部权限</strong>')
        );
        $form->addInput($defaultGroup);
    }

    /**
     * 用户个人配置（暂无）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 配置面板内的分组标题
     *
     * @param Form $form
     * @param string $title
     */
    private static function configGroup(Form $form, $title)
    {
        $group = new Layout('div', ['class' => 'oauth-conf-group']);
        $group->html(htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'));
        $form->addItem($group);
    }

    /**
     * 配置面板样式
     *
     * @return string
     */
    private static function configStyle()
    {
        return '.oauth-conf-tip{background:#F7FAFB;border-left:3px solid #467B96;padding:10px 14px;'
            . 'margin:0 0 18px;color:#666;font-size:13px;line-height:1.9;border-radius:2px}'
            . '.oauth-conf-tip code{background:#EEF3F5;padding:1px 5px;border-radius:2px;'
            . 'font-family:Consolas,Monaco,monospace}'
            . '.oauth-conf-group{margin:26px 0 6px;padding:0 0 6px;border-bottom:1px solid #E9E9E6;'
            . 'color:#467B96;font-size:14px;font-weight:bold}';
    }

    /**
     * 站点入口钩子：拦截第三方登录发起与回调
     *
     * @param mixed $unused
     */
    public static function onIndexBegin($unused = null)
    {
        $action = isset($_GET['oauth']) ? (string) $_GET['oauth'] : '';

        try {
            if ('login' === $action) {
                Handler::start();
            } elseif (isset($_GET['code'], $_GET['state'])) {
                Handler::callback();
            } elseif (isset($_GET['error'])) {
                // 授权方拒绝或返回错误（如 access_denied），仅在本次流程由本插件发起时接管
                Handler::denied(
                    (string) $_GET['error'],
                    isset($_GET['error_description']) ? (string) $_GET['error_description'] : ''
                );
            }
        } catch (\Throwable $e) {
            Handler::fail($e->getMessage());
        }

        Handler::bufferStart();
    }

    /**
     * 路由分发结束：把前台登录入口注入到已渲染的页面里
     *
     * @param mixed $unused
     */
    public static function onIndexEnd($unused = null)
    {
        Handler::bufferEnd();
    }

    /**
     * 后台 <head> 钩子：只在登录页注入第三方登录板块
     *
     * @param string $header
     * @return string
     */
    public static function onAdminHeader($header)
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';

        if (!preg_match('#/admin/login\.php$#', $script)) {
            return $header;
        }

        try {
            return $header . Handler::loginAssets();
        } catch (\Throwable $e) {
            return $header;
        }
    }
}
