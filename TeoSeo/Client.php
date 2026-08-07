<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo AI 内容优化客户端
 *
 * 调用任意 OpenAI 兼容接口(DeepSeek / 通义千问 / 智谱 GLM / Kimi / OpenAI 等),
 * 为文章生成摘要与关键词。统一走 /chat/completions 协议。
 *
 * @package TeoSeo
 */
class TeoSeo_Client
{
    /**
     * 生成摘要 + 关键词
     *
     * @param string $title 文章标题
     * @param string $content 文章正文(markdown)
     * @param object $config 插件配置
     * @return array [摘要, 关键词字符串(英文逗号分隔), 可能为空字符串]
     */
    public static function generate(string $title, string $content, $config): array
    {
        $baseUrl = rtrim(trim((string) $config->aiBaseUrl), '/');
        $apiKey  = trim((string) $config->aiApiKey);
        $model   = trim((string) $config->aiModel);
        if ('' === $baseUrl || '' === $apiKey || '' === $model) {
            return array('', '');
        }

        $summaryLen = max(30, min(500, intval($config->aiSummaryLen ?: 120)));
        $timeout    = max(5, min(120, intval($config->aiTimeout ?: 15)));

        $content = self::truncate($content, 6000);

        $prompt = '你是一名专业的博客 SEO 助手。请阅读下面这篇文章的标题与正文，只输出一个 JSON 对象，不要输出任何其他文字。'
            . 'JSON 格式: {"summary": "文章摘要", "keywords": "关键词1,关键词2,关键词3"}'
            . '要求: summary 用简体中文概括文章的核心内容与结论，不超过 ' . $summaryLen
            . ' 个字，适合用作搜索引擎的 meta description；keywords 给出 3~8 个简体中文关键词，用英文逗号分隔。'
            . "\n\n文章标题: " . $title . "\n\n文章正文: " . $content;

        $payload = json_encode(array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => '你只输出 JSON，不输出任何多余内容。'),
                array('role' => 'user', 'content' => $prompt),
            ),
            'temperature' => 0.3,
            'max_tokens'  => 500,
        ));

        list($code, $resp) = self::httpPostJson(
            $baseUrl . '/chat/completions',
            $payload,
            'Bearer ' . $apiKey,
            $timeout
        );

        if (200 != $code) {
            error_log('[TeoSeo-AI] HTTP ' . $code . ' ' . substr($resp, 0, 300));
            return array('', '');
        }

        $data = json_decode($resp, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            error_log('[TeoSeo-AI] 响应解析失败: ' . substr($resp, 0, 300));
            return array('', '');
        }

        $text = trim((string) $data['choices'][0]['message']['content']);
        return self::parseResult($text, $summaryLen);
    }

    /**
     * 解析模型输出(尽力提取 summary / keywords, 解析失败时整段当作摘要)
     *
     * @param string $text 模型输出
     * @param int $summaryLen 摘要最大字数
     * @return array [摘要, 关键词字符串]
     */
    private static function parseResult(string $text, int $summaryLen): array
    {
        $cleaned = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));

        $json = json_decode($cleaned, true);
        if (is_array($json)) {
            $summary  = trim((string) (isset($json['summary']) ? $json['summary'] : ''));
            $keywords = trim((string) (isset($json['keywords']) ? $json['keywords'] : ''));
            return array($summary, $keywords);
        }

        return array(self::truncate($text, $summaryLen), '');
    }

    /**
     * 截断字符串(按字符, 控制 token 消耗)
     *
     * @param string $text 原文本
     * @param int $max 最大字符数
     * @return string
     */
    private static function truncate(string $text, int $max): string
    {
        $text = trim(strip_tags($text));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }

    /**
     * JSON POST(OpenAI 兼容 /chat/completions)
     *
     * @param string $url 请求地址
     * @param string $payload JSON 请求体
     * @param string $apiKey Authorization 头
     * @param int $timeout 超时秒数
     * @return array [HTTP 状态码, 响应体]
     */
    private static function httpPostJson(string $url, string $payload, string $apiKey, int $timeout): array
    {
        $headers = array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: ' . $apiKey,
        );

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0, // 虚拟主机环境 hostname 校验常失败, 一并关闭
            ));
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (false === $resp) {
                $code = 0;
                $resp = curl_error($ch);
            }
            curl_close($ch);
            return array($code, (string) $resp);
        }

        // 无 curl 时退回流包装器
        $resp = @file_get_contents($url, false, stream_context_create(array(
            'http' => array(
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => $timeout,
            ),
        )));

        return array(false === $resp ? 0 : 200, (string) $resp);
    }
}
