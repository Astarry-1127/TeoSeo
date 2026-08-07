<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo AI 内容优化客户端
 *
 * 统一走 OpenAI 兼容的 /chat/completions 协议, 所以 DeepSeek / 通义 / 智谱 / Kimi
 * 这些家的接口都能用, 换平台只改 BaseURL 和模型名就行, 不用改代码。
 *
 * @package TeoSeo
 */
class TeoSeo_Client
{
    /**
     * 生成摘要 + 关键词
     *
     * @param string $title 文章标题
     * @param string $content 文章正文
     * @param object $config 插件配置
     * @return array [摘要, 关键词字符串(英文逗号分隔), 拿不到时都是空串]
     */
    public static function generate(string $title, string $content, $config): array
    {
        $baseUrl = rtrim(trim((string) $config->aiBaseUrl), '/');
        $apiKey  = trim((string) $config->aiApiKey);
        $model   = trim((string) $config->aiModel);
        if ('' === $baseUrl || '' === $apiKey || '' === $model) {
            // 配置没填全就啥也别干, 免得后台报一堆错
            return array('', '');
        }

        $summaryLen = max(30, min(500, intval($config->aiSummaryLen ?: 120)));
        $timeout    = max(5, min(120, intval($config->aiTimeout ?: 30)));

        // 长文丢进 token 太贵, 正文截 6000 字够 AI 理解大意了
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
            $timeout,
            !empty($config->aiSkipVerifySSL)
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
     * 解析模型输出。
     * 有的模型会把 JSON 包在 ``` 代码块里, 有的会啰嗦两句再给 JSON,
     * 这里尽量宽容: 剥掉代码块标记后能解出 JSON 就用, 解不出就把整段当摘要。
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

        // 模型不听话直接返回了散文, 截一段当摘要用, 关键词就留空了
        return array(self::truncate($text, $summaryLen), '');
    }

    /**
     * 截断字符串(按字符数, 主要是控制 token 消耗)
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
     * SSL 校验默认开着——key 是钱, 不能裸奔。但西部数码这类虚拟主机 CA 链经常
     * 残缺, 校验会失败, 所以勾了「跳过 SSL 校验」时: 先按严格模式试一次,
     * 失败再降级重试, 这样两边都不耽误。
     *
     * @param string $url 请求地址
     * @param string $payload JSON 请求体
     * @param string $apiKey Authorization 头
     * @param int $timeout 超时秒数
     * @param bool $skipSsl 允许降级跳过 SSL 校验
     * @return array [HTTP 状态码, 响应体]
     */
    private static function httpPostJson(string $url, string $payload, string $apiKey, int $timeout, bool $skipSsl): array
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
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (false === $resp && $skipSsl) {
                // 严格模式连不上, 多半是宿主机的 CA 问题, 降级再试一次
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
            if (false === $resp) {
                $code = 0;
                $resp = curl_error($ch);
            }
            curl_close($ch);
            return array($code, (string) $resp);
        }

        // 没 curl 的环境(极少见)退回流包装器
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
