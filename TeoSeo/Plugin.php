<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 兼容层: Typecho 1.3 的插件接口为命名空间版 Typecho\Plugin\PluginInterface,
// 全局别名 Typecho_Plugin_Interface 未注册, 但后台 parseInfo 依赖
// `implements Typecho_Plugin_Interface` 识别插件类 —— 这里自行定义空接口,
// 既满足后台识别, 又保证运行时类加载不报错。
if (!interface_exists('Typecho_Plugin_Interface', false)) {
    interface Typecho_Plugin_Interface
    {
    }
}

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
 * @version 1.1.0
 * @link https://blog.astarry.cn
 * @license GNU General Public License 2.0
 */
class TeoSeo_Plugin implements Typecho_Plugin_Interface
{
    /** sitemap 路由名称 */
    const SITEMAP_ROUTE = 'teoseo_sitemap';

    /** sitemap 路由路径 */
    const SITEMAP_PATH = '/sitemap.xml';

    /** 推送日志表名(不含前缀) */
    const LOG_TABLE = 'seo_logs';

    /**
     * 插件激活
     *
     * 1. 注册 /sitemap.xml 路由
     * 2. 挂载「文章发布后」钩子,用于自动推送
     * 3. 创建推送日志表
     *
     * @return string
     */
    public static function activate(): string
    {
        // 注册 sitemap 路由(自动处理路由表重编译)
        \Utils\Helper::addRoute(self::SITEMAP_ROUTE, self::SITEMAP_PATH, 'TeoSeo_Widget', 'render');

        // 文章发布后自动推送搜索引擎
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'pushOnPublish');

        // 创建推送日志表
        self::ensureLogTable();

        // 注册后台独立面板「推送历史」
        \Utils\Helper::addPanel(1, 'TeoSeo/logs.php', 'TeoSeo', '推送历史', 'administrator');

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
        \Utils\Helper::removePanel(1, 'TeoSeo/logs.php');
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

        echo '<p style="color:#999; font-size:13px; margin-top:1.5em;">'
            . _t('推送历史见左侧菜单: <strong>TeoSeo → 推送历史</strong>(最近 50 条推送结果)。')
            . '</p>';
    }

    /**
     * 个人配置(未使用)
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        return; // 个人配置暂未使用(保持方法体非空, 便于插件信息解析)
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

            $slug = $contents['slug'];

            // IndexNow 推送
            if (!empty($config->indexnowKey)) {
                list($ok, $detail) = self::pushIndexNow($siteUrl, $config->indexnowKey, $permalink);
                self::logPush($slug, $permalink, 'IndexNow', $ok, $detail);
            }

            // 百度主动推送
            if (!empty($config->baiduToken)) {
                list($ok, $detail) = self::pushBaidu($siteUrl, $config->baiduToken, $permalink);
                self::logPush($slug, $permalink, '百度', $ok, $detail);
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
     * @return array [成功与否, 详情]
     */
    private static function pushIndexNow(string $siteUrl, string $key, string $url): array
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);

        // 确保验证文件存在(尽力而为, 目录不可写则跳过推送)
        $keyFile = __TYPECHO_ROOT_DIR__ . '/' . $key . '.txt';
        if (!file_exists($keyFile) && is_writable(__TYPECHO_ROOT_DIR__)) {
            @file_put_contents($keyFile, $key);
        }
        if (!file_exists($keyFile)) {
            return array(false, 'key 验证文件不存在: ' . $keyFile);
        }

        $payload = json_encode(array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $siteUrl . '/' . $key . '.txt',
            'urlList'     => array($url),
        ));

        list($code, $resp) = self::httpPost(
            'https://api.indexnow.org/indexnow',
            $payload,
            array('Content-Type: application/json; charset=utf-8')
        );

        return array(200 == $code || 202 == $code, 'HTTP ' . $code . ' ' . substr($resp, 0, 200));
    }

    /**
     * 百度站长平台主动推送(普通收录)
     *
     * @param string $siteUrl 站点根地址
     * @param string $token 百度推送 token
     * @param string $url 待推送 URL
     * @return array [成功与否, 详情]
     */
    private static function pushBaidu(string $siteUrl, string $token, string $url): array
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);
        $api  = 'https://data.zz.baidu.com/urls?site=' . urlencode($host) . '&token=' . urlencode($token);

        list($code, $resp) = self::httpPost($api, $url . "\n");
        $detail = 'HTTP ' . $code . ' ' . substr($resp, 0, 200);
        $ok = (200 == $code && false !== strpos($resp, '"success"') && false === strpos($resp, '"error"'));

        return array($ok, $detail);
    }

    /**
     * 简单 HTTP POST(curl 优先, 无 curl 时退回流包装器)
     *
     * @param string $url 请求地址
     * @param string $body 请求体
     * @param array $headers 附加请求头
     * @return array [HTTP 状态码, 响应体]
     */
    private static function httpPost(string $url, string $body, array $headers = array()): array
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
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 15,
            ),
        )));
        $code = (false === $resp) ? 0 : 200;

        return array($code, (string) $resp);
    }

    /**
     * 确保推送日志表存在
     */
    private static function ensureLogTable()
    {
        try {
            $db     = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}" . self::LOG_TABLE . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `created` INT UNSIGNED NOT NULL DEFAULT 0,
                `slug` VARCHAR(255) NOT NULL DEFAULT '',
                `url` VARCHAR(500) NOT NULL DEFAULT '',
                `target` VARCHAR(50) NOT NULL DEFAULT '',
                `status` VARCHAR(20) NOT NULL DEFAULT '',
                `detail` VARCHAR(500) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `created` (`created`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {
            error_log('[TeoSeo] ensureLogTable failed: ' . $e->getMessage());
        }
    }

    /**
     * 写入推送日志
     *
     * @param string $slug 文章 slug
     * @param string $url 推送 URL
     * @param string $target 推送目标(IndexNow / 百度)
     * @param bool $ok 是否成功
     * @param string $detail 响应详情
     */
    private static function logPush(string $slug, string $url, string $target, bool $ok, string $detail)
    {
        try {
            $db     = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $db->query("INSERT INTO `{$prefix}" . self::LOG_TABLE . "`
                (`created`, `slug`, `url`, `target`, `status`, `detail`)
                VALUES ('" . time() . "', '" . addslashes($slug) . "', '" . addslashes($url) . "',
                        '" . addslashes($target) . "', '" . ($ok ? 'success' : 'fail') . "',
                        '" . addslashes($detail) . "')");
        } catch (Exception $e) {
            error_log('[TeoSeo] logPush failed: ' . $e->getMessage());
        }
    }

    /**
     * 渲染推送历史表格(设置页展示)
     */
    private static function renderPushHistory()
    {
        try {
            $db     = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $rows   = $db->fetchAll($db->query("SELECT * FROM `{$prefix}" . self::LOG_TABLE . "` ORDER BY `id` DESC LIMIT 20"));

            echo '<h2 style="margin-top:2.5em;">推送历史</h2>';
            if (empty($rows)) {
                echo '<p style="color:#999;">暂无推送记录, 发布新文章后这里会显示每次推送给搜索引擎的结果。</p>';
                return;
            }

            echo '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
            echo '<thead><tr style="background:#f5f5f5; text-align:left;">'
                . '<th style="padding:8px; border:1px solid #ddd;">时间</th>'
                . '<th style="padding:8px; border:1px solid #ddd;">文章</th>'
                . '<th style="padding:8px; border:1px solid #ddd;">目标</th>'
                . '<th style="padding:8px; border:1px solid #ddd;">状态</th>'
                . '<th style="padding:8px; border:1px solid #ddd;">详情</th>'
                . '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $ok    = ('success' == $row['status']);
                $color = $ok ? '#16a34a' : '#dc2626';
                echo '<tr>'
                    . '<td style="padding:8px; border:1px solid #ddd;">' . date('Y-m-d H:i:s', $row['created']) . '</td>'
                    . '<td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['slug']) . '</td>'
                    . '<td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['target']) . '</td>'
                    . '<td style="padding:8px; border:1px solid #ddd; color:' . $color . '; font-weight:bold;">'
                    . ($ok ? '成功' : '失败') . '</td>'
                    . '<td style="padding:8px; border:1px solid #ddd; word-break:break-all;">'
                    . htmlspecialchars($row['detail']) . '</td>'
                    . '</tr>';
            }

            echo '</tbody></table>';
            echo '<p style="color:#999; font-size:12px;">仅显示最近 20 条。日志保留在数据库 ' . $prefix . self::LOG_TABLE . ' 表。</p>';
        } catch (Exception $e) {
            echo '<p style="color:#999;">推送历史暂不可用: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
}
