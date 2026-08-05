<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo - Typecho SEO 插件
 *
 * 为 Typecho 提供开箱即用的 SEO 能力:
 * 1. 自动生成符合 Sitemaps 协议的 /sitemap.xml(首页 + 文章 + 独立页面)
 * 2. 文章发布时自动向搜索引擎推送收录:
 *    - IndexNow(Bing / Yandex / Seznam / Naver / 360 等)
 *    - 百度站长平台主动推送
 *
 * @package TeoSeo
 * @author Astarry
 * @version 1.0.0
 * @link https://blog.astarry.cn
 * @license GNU General Public License 2.0
 */
// 注: Typecho 1.3 已移除全局接口 Typecho_Plugin_Interface,
// 插件只需实现 activate/deactivate/config/personalConfig 四个静态方法即可
class TeoSeo_Plugin
{
    /** sitemap 路由名称 */
    const SITEMAP_ROUTE = 'teoseo_sitemap';

    /** sitemap 路由路径 */
    const SITEMAP_PATH = '/sitemap.xml';

    /**
     * 插件激活
     *
     * 1. 注册 /sitemap.xml 路由
     * 2. 挂载「文章发布后」钩子,用于自动推送
     *
     * @return string
     */
    public static function activate(): string
    {
        // 注册 sitemap 路由(自动处理路由表重编译)
        \Utils\Helper::addRoute(self::SITEMAP_ROUTE, self::SITEMAP_PATH, 'TeoSeo_Widget', 'render');

        // 文章发布后自动推送搜索引擎
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'pushOnPublish');

        return _t('TeoSeo 已激活: /sitemap.xml 已就绪, 发布文章将自动推送 IndexNow 与百度。');
    }

    /**
     * 插件禁用
     *
     * 移除 sitemap 路由(钩子随插件禁用自动失效)
     */
    public static function deactivate()
    {
        \Utils\Helper::removeRoute(self::SITEMAP_ROUTE);
    }

    /**
     * 后台配置表单
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /** 推送配置 */
        $indexnowKey = new Typecho_Widget_Helper_Form_Element_Text(
            'indexnowKey', NULL, '',
            _t('IndexNow API Key'),
            _t('在 <a href="https://www.bing.com/webmasters/indexnow" target="_blank">Bing Webmaster</a> 生成, 32 位字符串。启用后需确保站点根目录存在 <code>{key}.txt</code> 验证文件(内容为 key 本身), 插件会在推送时自动尝试写入。')
        );
        $form->addInput($indexnowKey);

        $baiduToken = new Typecho_Widget_Helper_Form_Element_Text(
            'baiduToken', NULL, '',
            _t('百度推送 Token'),
            _t('百度搜索资源平台 → 普通收录 → 主动推送 中获取, 形如 B3fOjAhPQ1R5HQch。留空则跳过百度推送。')
        );
        $form->addInput($baiduToken);

        $pushEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'pushEnabled', array('push' => _t('启用发布后自动推送')),
            array('push'),
            _t('推送开关')
        );
        $form->addInput($pushEnabled);

        /** sitemap 配置 */
        $sitemapHome = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'sitemapHome', array('home' => _t('包含首页')), array('home'),
            _t('sitemap 包含项'), _t('首页固定 changefreq=daily, priority=1.0; 文章 weekly/0.8; 页面 monthly/0.6')
        );
        $form->addInput($sitemapHome);

        $sitemapPages = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'sitemapPages', array('pages' => _t('包含独立页面')), array('pages'),
            '', NULL
        );
        $form->addInput($sitemapPages);
    }

    /**
     * 个人配置(未使用)
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 文章发布后回调: 推送 IndexNow + 百度
     *
     * @param array $contents 文章数据
     * @param mixed $widget 发布组件
     */
    public static function pushOnPublish(array $contents, $widget)
    {
        try {
            $options = \Utils\Helper::options();

            // 容错读取配置(插件刚启用尚未配置时不推送)
            $config = NULL;
            try {
                $config = $options->plugin('TeoSeo');
            } catch (Exception $e) {
                $config = NULL;
            }
            if (NULL === $config) {
                return;
            }

            if (empty($config->indexnowKey) && empty($config->baiduToken)) {
                return; // 未配置任何推送
            }

            $enabled = $config->pushEnabled;
            if (is_array($enabled) && !in_array('push', $enabled)) {
                return; // 推送被关闭
            }

            $siteUrl = rtrim($options->siteUrl, '/');
            if (empty($contents['slug'])) {
                return;
            }

            // 生成文章 URL(走路由表, 兼容自定义链接格式)
            $permalink = Typecho\Router::url('post', array('slug' => $contents['slug']), $siteUrl);
            if (empty($permalink) || '#' == $permalink) {
                $permalink = $siteUrl . '/' . $contents['slug'] . '/';
            }

            // IndexNow 推送
            if (!empty($config->indexnowKey)) {
                self::pushIndexNow($siteUrl, $config->indexnowKey, $permalink);
            }

            // 百度主动推送
            if (!empty($config->baiduToken)) {
                self::pushBaidu($siteUrl, $config->baiduToken, $permalink);
            }
        } catch (Exception $e) {
            error_log('[TeoSeo] pushOnPublish failed: ' . $e->getMessage());
        }
    }

    /**
     * IndexNow 推送(Bing / Yandex / Seznam / Naver / 360 等)
     *
     * @param string $siteUrl 站点根地址
     * @param string $key IndexNow key
     * @param string $url 待推送 URL
     */
    private static function pushIndexNow(string $siteUrl, string $key, string $url)
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);

        // 确保验证文件存在(尽力而为, 目录不可写则跳过推送)
        $keyFile = __TYPECHO_ROOT_DIR__ . '/' . $key . '.txt';
        if (!file_exists($keyFile) && is_writable(__TYPECHO_ROOT_DIR__)) {
            @file_put_contents($keyFile, $key);
        }
        if (!file_exists($keyFile)) {
            error_log('[TeoSeo] IndexNow key file missing: ' . $keyFile);
            return;
        }

        $payload = json_encode(array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $siteUrl . '/' . $key . '.txt',
            'urlList'     => array($url),
        ));

        self::httpPost(
            'https://api.indexnow.org/indexnow',
            $payload,
            array('Content-Type: application/json; charset=utf-8')
        );
    }

    /**
     * 百度站长平台主动推送(普通收录)
     *
     * @param string $siteUrl 站点根地址
     * @param string $token 百度推送 token
     * @param string $url 待推送 URL
     */
    private static function pushBaidu(string $siteUrl, string $token, string $url)
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);
        $api  = 'https://data.zz.baidu.com/urls?site=' . urlencode($host) . '&token=' . urlencode($token);

        self::httpPost($api, $url . "\n");
    }

    /**
     * 简单 HTTP POST(curl 优先, 失败静默)
     *
     * @param string $url 请求地址
     * @param string $body 请求体
     * @param array $headers 附加请求头
     */
    private static function httpPost(string $url, string $body, array $headers = array())
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => array_merge(array('Accept: application/json'), $headers),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ));
            $resp = curl_exec($ch);
            if (false === $resp) {
                error_log('[TeoSeo] POST failed: ' . curl_error($ch) . ' @ ' . $url);
            }
            curl_close($ch);
        } else {
            // 无 curl 时退回流包装器
            @file_get_contents($url, false, stream_context_create(array(
                'http' => array(
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 15,
                ),
            )));
        }
    }
}
