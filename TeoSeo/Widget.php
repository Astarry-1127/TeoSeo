<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Widget;
use Widget\ActionInterface;

/**
 * TeoSeo sitemap 输出组件
 *
 * 由路由 /sitemap.xml 触发, 动态生成符合
 * Sitemaps 0.9 协议的 XML 站点地图。
 *
 * @package TeoSeo
 */
class TeoSeo_Widget extends Widget implements ActionInterface
{
    /**
     * 构造函数
     *
     * @param mixed $request
     * @param mixed $response
     * @param mixed $params
     */
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
    }

    /**
     * 输出 sitemap.xml
     *
     * @return void
     */
    public function render()
    {
        $options = \Utils\Helper::options();
        $siteUrl = rtrim($options->siteUrl, '/');

        // 容错读取插件配置(未配置时使用默认值, 避免 500)
        $config = NULL;
        try {
            $config = $options->plugin('TeoSeo');
        } catch (Exception $e) {
            $config = NULL;
        }

        $includeHome   = !(is_array($config->sitemapHome) && !in_array('home', $config->sitemapHome));
        $includePages  = is_array($config->sitemapPages) && in_array('pages', $config->sitemapPages);

        // 查询已发布的文章
        $db   = \Typecho\Db::get();
        $rows = $db->fetchAll($db->select('slug', 'modified')
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('modified', \Typecho\Db::SORT_DESC));

        // 查询已发布的独立页面
        $pages = array();
        if ($includePages) {
            $pages = $db->fetchAll($db->select('slug', 'modified')
                ->from('table.contents')
                ->where('type = ?', 'page')
                ->where('status = ?', 'publish')
                ->order('modified', \Typecho\Db::SORT_DESC));
        }

        header('Content-Type: application/xml; charset=UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 首页
        if ($includeHome) {
            $this->urlEntry($siteUrl . '/', date('c'), 'daily', '1.0');
        }

        // 文章
        foreach ($rows as $row) {
            $url = Typecho\Router::url('post', array('slug' => $row['slug']), $siteUrl);
            if (empty($url) || '#' == $url) {
                $url = $siteUrl . '/' . $row['slug'] . '/';
            }
            $this->urlEntry($url, date('c', $row['modified']), 'weekly', '0.8');
        }

        // 独立页面
        foreach ($pages as $row) {
            $url = Typecho\Router::url('page', array('slug' => $row['slug']), $siteUrl);
            if (empty($url) || '#' == $url) {
                $url = $siteUrl . '/' . $row['slug'] . '.html';
            }
            $this->urlEntry($url, date('c', $row['modified']), 'monthly', '0.6');
        }

        echo '</urlset>';
    }

    /**
     * Action 接口实现(本插件未使用)
     *
     * @return void
     */
    public function action()
    {
    }

    /**
     * 输出单个 url 条目
     *
     * @param string $url 完整 URL
     * @param string $lastmod 最后修改时间(ISO 8601)
     * @param string $changefreq 更新频率
     * @param string $priority 优先级
     */
    private function urlEntry(string $url, string $lastmod, string $changefreq, string $priority)
    {
        echo '  <url>' . "\n";
        echo '    <loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . "\n";
        echo '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>' . "\n";
        echo '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
        echo '    <priority>' . $priority . '</priority>' . "\n";
        echo '  </url>' . "\n";
    }
}
