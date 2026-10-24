<?php

namespace TypechoPlugin\OAuthLogin\Lib;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 轻量 HTTP 客户端（依赖 curl 扩展）
 */
class Http
{
    const TIMEOUT = 15;

    /**
     * 发送请求
     *
     * @param string $method
     * @param string $url
     * @param array|string|null $body
     * @param array $headers
     * @return array ['status' => int, 'body' => string, 'error' => string]
     */
    public static function request($method, $url, $body = null, array $headers = [])
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'PHP 缺少 curl 扩展'];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Typecho-OAuthLogin/1.0 (+https://blog.sibh.cn)',
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);

        if ('POST' === strtoupper($method)) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (null !== $body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
            }
        }

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if (false === $responseBody) {
            return ['status' => 0, 'body' => '', 'error' => $error ?: '请求失败'];
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'error' => ''];
    }

    /**
     * GET
     *
     * @param string $url
     * @param array $headers
     * @return array
     */
    public static function get($url, array $headers = [])
    {
        return self::request('GET', $url, null, $headers);
    }

    /**
     * 表单 POST（application/x-www-form-urlencoded）
     *
     * @param string $url
     * @param array $data
     * @param array $headers
     * @return array
     */
    public static function postForm($url, array $data, array $headers = [])
    {
        return self::request('POST', $url, $data, array_merge(
            ['Content-Type: application/x-www-form-urlencoded'],
            $headers
        ));
    }

    /**
     * 解析 JSON
     *
     * @param string $body
     * @return array
     */
    public static function json($body)
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 从响应中挑出错误信息
     *
     * @param array $res
     * @param array $json
     * @return string
     */
    public static function errorMessage(array $res, array $json = [])
    {
        if (!empty($json['error_description'])) {
            return (string) $json['error_description'];
        }
        if (!empty($json['error'])) {
            return (string) $json['error'];
        }
        if (!empty($json['msg'])) {
            return (string) $json['msg'];
        }
        if (!empty($json['message'])) {
            return (string) $json['message'];
        }
        if (!empty($res['error'])) {
            return (string) $res['error'];
        }

        return 'HTTP ' . $res['status'];
    }
}
