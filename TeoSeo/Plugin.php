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
 * 3. AI 内容优化(任意 OpenAI 兼容接口):
 *    - 发布时自动生成文章摘要与关键词(已有摘要不覆盖)
 *    - 后台面板批量生成存量文章
 *    - 前台输出 meta description / keywords / JSON-LD Article
 *
 * @package TeoSeo
 * @author Astarry
 * @version 1.3.0
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

    /** 插件版本号(与文件头 @version 保持一致, 自动更新以此比较) */
    const VERSION = '1.3.0';

    /** GEO 适配反馈页面(作者博客, 供使用者反馈希望适配的主题) */
    const GEO_FEEDBACK_URL = 'https://blog.astarry.cn/feedback/';

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

        // 文章发布后: 推送搜索引擎 + AI 生成摘要/关键词
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'onPublish');

        // 前台输出 SEO meta(description / keywords / JSON-LD)
        \Typecho\Plugin::factory('Widget_Archive')->header = array(__CLASS__, 'outputHeaderMeta');

        // 创建推送日志表
        self::ensureLogTable();

        // 注册后台独立面板「推送历史」与「AI 内容优化」(先清旧的同 URL 面板, 保证重复激活不累积菜单)
        self::ensurePanelUnique(1, 'TeoSeo/logs.php');
        self::ensurePanelUnique(2, 'TeoSeo/ai.php');
        \Utils\Helper::addPanel(1, 'TeoSeo/logs.php', 'TeoSeo 推送历史', '推送历史', 'administrator');
        \Utils\Helper::addPanel(2, 'TeoSeo/ai.php', 'TeoSeo AI 内容优化', 'AI 内容优化', 'administrator');

        // 自动更新 action(/action/teoseo-update, TeoSeo_Update 由 autoload 加载 Update.php)
        \Utils\Helper::addAction('teoseo-update', 'TeoSeo_Update');

        return _t('TeoSeo 已激活: /sitemap.xml 已就绪, 发布文章将自动推送 IndexNow / 百度, 并自动生成 AI 摘要与关键词。');
    }

    /**
     * 确保后台面板不重复: 先移除同 URL 的旧面板, 再让 activate() 重新添加。
     *
     * Helper::addPanel 每次激活都会无条件追加一条菜单(不像 file 那样 array_unique),
     * 重复激活/升级会把「推送历史」「AI 内容优化」累积成多个 TeoSeo 菜单项。
     * 在 addPanel 之前调用本方法即可保证幂等。
     *
     * @param int $index 菜单索引
     * @param string $fileName 面板文件路径
     */
    private static function ensurePanelUnique(int $index, string $fileName)
    {
        try {
            \Utils\Helper::removePanel($index, $fileName);
        } catch (\Throwable $e) {
            // 首次激活时面板表可能为空, removePanel 抛错可忽略
        }
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
        \Utils\Helper::removePanel(2, 'TeoSeo/ai.php');
        \Utils\Helper::removeAction('teoseo-update');
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

        /** AI 内容优化配置 */
        echo '<h3 style="margin:2em 0 0.8em; border-top:1px dashed #ddd; padding-top:1em;">AI 内容优化</h3>';

        $aiEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'aiEnabled', array('enable' => _t('启用 AI 内容优化')),
            array('enable'),
            _t('AI 开关'),
            _t('发布文章时自动生成摘要与关键词(已有摘要的不覆盖); 存量文章可在 <strong>TeoSeo → AI 内容优化</strong> 面板批量生成。')
        );
        $form->addInput($aiEnabled);

        $aiBaseUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'aiBaseUrl', NULL, 'https://open.bigmodel.cn/api/paas/v4',
            _t('AI 接口 BaseURL'),
            _t('任意 OpenAI 兼容接口, 如智谱 GLM <code>https://open.bigmodel.cn/api/paas/v4</code>(有免费模型)、DeepSeek <code>https://api.deepseek.com/v1</code>、通义千问 <code>https://dashscope.aliyuncs.com/compatible-mode/v1</code>。')
        );
        $form->addInput($aiBaseUrl);

        $aiApiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'aiApiKey', NULL, '',
            _t('API Key'),
            _t('在对应平台控制台生成, 仅保存在本地数据库中。')
        );
        $form->addInput($aiApiKey);

        $aiModel = new Typecho_Widget_Helper_Form_Element_Text(
            'aiModel', NULL, 'glm-4-flash-250414',
            _t('模型名'),
            _t('如 <code>glm-4-flash-250414</code>(智谱免费) / <code>deepseek-chat</code> / <code>qwen-plus</code> / <code>gpt-4o-mini</code>。注意: 推理类模型(如 <code>glm-4.7-flash</code>)把输出耗在思考过程上, content 常为空, 不适用。')
        );
        $form->addInput($aiModel);

        $aiSummaryLen = new Typecho_Widget_Helper_Form_Element_Text(
            'aiSummaryLen', NULL, '120',
            _t('摘要长度(字)'),
            _t('AI 生成的摘要最大字数, 建议 80~200。')
        );
        $form->addInput($aiSummaryLen);

        $aiTimeout = new Typecho_Widget_Helper_Form_Element_Text(
            'aiTimeout', NULL, '30',
            _t('请求超时(秒)'),
            _t('生成失败不影响发布, 请求超过该秒数自动放弃。长文接口一般要 20~40 秒, 默认 30 比较稳。')
        );
        $form->addInput($aiTimeout);

        $aiSkipVerifySSL = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'aiSkipVerifySSL', array('skip' => _t('允许跳过 SSL 校验')),
            NULL,
            _t('SSL 校验'),
            _t('默认严格校验, 若虚拟主机 CA 链残缺导致请求失败, 勾选后会自动降级重试一次。')
        );
        $form->addInput($aiSkipVerifySSL);

        /** GEO 优化配置 */
        echo '<h3 style="margin:2em 0 0.8em; border-top:1px dashed #ddd; padding-top:1em;">GEO 优化</h3>';

        $geoEnabled = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'geoEnabled', array('enable' => _t('启用 GEO 优化')),
            array(),
            _t('GEO 开关'),
            _t('默认关闭, 手动开启后生效。面向生成式引擎(AI 搜索/引用)的优化: ① meta description / og:description 清理(无 markdown 污染, 优先 AI 摘要) ② 输出 BreadcrumbList 结构化数据 ③ 保护文章顶部「本文要点」<code>&lt;details&gt;</code> 折叠块不被解析器破坏。其中折叠块保护需配合 Inaline 主题补丁(见仓库 <code>inaline-patch/</code>), 其他主题可留言请求适配。')
        );
        $form->addInput($geoEnabled);

        // GEO 主题适配检测: 非 Inaline 主题时, 勾选「启用 GEO 优化」弹窗提示适配范围
        // (双按钮: 确定=关闭弹窗, 反馈=跳转作者博客反馈页)
        $themeName = strtolower(trim((string) \Utils\Helper::options()->theme));
        if ('inaline' !== $themeName) {
            $feedbackUrl = self::GEO_FEEDBACK_URL;
            echo '<style>'
                . '#teoseoGeoModal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:none;align-items:center;justify-content:center}'
                . '#teoseoGeoModal .box{background:#fff;border-radius:10px;max-width:440px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,.2);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
                . '#teoseoGeoModal .box h4{margin:0 0 8px;font-size:15px}'
                . '#teoseoGeoModal .box p{margin:0;color:#555;font-size:13px;line-height:1.7}'
                . '#teoseoGeoModal .btns{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}'
                . '#teoseoGeoModal .btns a,#teoseoGeoModal .btns button{padding:8px 18px;border-radius:6px;text-decoration:none;font-size:13px;cursor:pointer;border:none}'
                . '#teoseoGeoModal .btn-ok{background:#e5e7eb;color:#333}'
                . '#teoseoGeoModal .btn-fb{background:#2563eb;color:#fff}'
                . '</style>'
                . '<div id="teoseoGeoModal"><div class="box">'
                . '<h4>' . _t('GEO 优化主题适配提示') . '</h4>'
                . '<p>' . _t('此功能目前仅适配 <strong>Inaline</strong> 主题。如果需要为您所使用的主题适配, 点击「反馈」前往作者博客留言, 作者会尽力适配。') . '</p>'
                . '<div class="btns">'
                . '<button type="button" class="btn-ok" onclick="document.getElementById(\'teoseoGeoModal\').style.display=\'none\'">' . _t('确定') . '</button>'
                . '<a href="' . htmlspecialchars($feedbackUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="btn-fb">' . _t('反馈') . '</a>'
                . '</div></div></div>'
                . '<script>'
                . '(function(){'
                . 'var cb=document.querySelector("input[name^=\'geoEnabled\']");'
                . 'if(!cb)return;'
                . 'function onGeoChange(){if(cb.checked){var m=document.getElementById("teoseoGeoModal");if(m)m.style.display="flex";}}'
                . 'cb.addEventListener("change",onGeoChange);'
                . '})();'
                . '</script>';
        }

        echo '<p style="color:#999; font-size:13px; margin-top:1.5em;">'
            . _t('推送历史见左侧菜单: <strong>TeoSeo → 推送历史</strong>(最近 50 条推送结果); 存量文章 AI 生成见 <strong>TeoSeo → AI 内容优化</strong>。')
            . '</p>';

        // 密钥类字段脱敏显示: 前4位明文 + 中间*号 + 后4位明文(不改核心 Config.php, 用 JS 在渲染后替换输入框值)
        $secretValues = array(
            'indexnowKey' => '',
            'baiduToken'  => '',
            'aiApiKey'    => '',
        );
        $existing = self::readConfig();
        if ($existing) {
            foreach ($secretValues as $k => $v) {
                if (isset($existing->$k) && '' !== (string) $existing->$k) {
                    $secretValues[$k] = self::maskSecret((string) $existing->$k);
                }
            }
        }
        $jsonMask = json_encode($secretValues, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<script>'
            . '(function(){'
            . 'var m=' . $jsonMask . ';'
            . 'function mask(){'
            . 'Object.keys(m).forEach(function(k){'
            . 'if(!m[k])return;'
            . 'var el=document.querySelector("input[name=\'"+k+"\']");'
            . 'if(el)el.value=m[k];'
            . '});'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",mask);}'
            . 'else{window.setTimeout(mask,0);}'
            . '})();'
            . '</script>';

        // 自动更新区块
        $updateToken = \Typecho\Widget::widget('Widget_Security')->getToken('teoseo-update');
        echo '<div id="teoseoUpdateBlock" style="margin:2em 0 1em; border-top:1px dashed #ddd; padding-top:1em;">';
        echo '<h3 style="margin:0 0 0.8em;">自动更新</h3>';
        echo '<p style="color:#666; font-size:13px; margin:0 0 0.8em;">'
            . _t('当前版本 <strong>v' . self::VERSION . '</strong>。自动更新从 GitHub main 分支拉取最新代码, 更新前自动备份旧版到 <code>usr/plugins/TeoSeo-backup/</code>, 更新后自动重启插件。')
            . '</p>';
        echo '<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">'
            . '<button type="button" id="teoseoCheckBtn" style="padding:8px 18px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:13px; cursor:pointer;">检查更新</button>'
            . '<button type="button" id="teoseoUpdateBtn" style="padding:8px 18px; background:#16a34a; color:#fff; border:none; border-radius:6px; font-size:13px; cursor:pointer; display:none;">立即更新</button>'
            . '</div>';
        echo '<p id="teoseoUpdateMsg" style="display:none; padding:10px 12px; border-radius:6px; margin-top:12px;"></p>';
        echo '<input type="hidden" id="teoseoUpdateToken" value="' . $updateToken . '">';
        echo '</div>';

        echo '<script>'
            . '(function(){'
            . 'var base=window.location.origin+"/action/teoseo-update";'
            . 'var token=document.getElementById("teoseoUpdateToken").value;'
            . 'var msgEl=document.getElementById("teoseoUpdateMsg");'
            . 'var checkBtn=document.getElementById("teoseoCheckBtn");'
            . 'var upBtn=document.getElementById("teoseoUpdateBtn");'
            . 'function show(t,ok){msgEl.style.display="block";msgEl.style.background=ok?"#f0fdf4":"#fef2f2";msgEl.style.color=ok?"#166534":"#b91c1c";msgEl.textContent=t;}'
            . 'function post(action,cb){fetch(base+"?action="+action,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8","X-Requested-With":"XMLHttpRequest"},body:"_="+encodeURIComponent(token)+"&action="+action}).then(function(r){return r.json();}).then(cb).catch(function(e){show("请求失败: "+e.message,false);});}'
            . 'checkBtn.onclick=function(){checkBtn.disabled=true;checkBtn.textContent="检查中…";post("check",function(j){checkBtn.disabled=false;checkBtn.textContent="检查更新";show(j.msg,j.ok);if(j.ok&&j.data&&j.data.hasUpdate){upBtn.style.display="inline-block";}else{upBtn.style.display="none";}});};'
            . 'upBtn.onclick=function(){if(!confirm("确定要更新插件吗? 更新前会自动备份旧版。"))return;upBtn.disabled=true;upBtn.textContent="更新中…";post("update",function(j){upBtn.disabled=false;upBtn.textContent="立即更新";show(j.msg,j.ok);if(j.ok){upBtn.style.display="none";setTimeout(function(){location.reload();},1500);}});};'
            . 'function moveBlock(){var blk=document.getElementById("teoseoUpdateBlock");if(!blk)return;var f=document.querySelector("form");if(!f)return;var submit=f.querySelector("input[type=submit],button[type=submit]");if(submit){submit.parentNode.insertBefore(blk,submit);}else{f.appendChild(blk);}}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",moveBlock);}else{window.setTimeout(moveBlock,0);}'
            . '})();'
            . '</script>';
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
     * 后台保存插件配置
     *
     * 设置页的 key 类字段(IndexNow / 百度 / AI)在输入框里是脱敏显示(前4后4明文, 中间*号)。
     * 用户不修改时提交上来的是脱敏串, 这里还原为数据库原值; 只有用户填了全新完整值才覆盖。
     *
     * @param array $settings 提交的表单值
     * @param bool $isInit 是否初始化
     */
    public static function configHandle(array $settings, bool $isInit)
    {
        $secretKeys = array('indexnowKey', 'baiduToken', 'aiApiKey');
        if (!$isInit) {
            $old = self::readConfig();
            foreach ($secretKeys as $k) {
                if (isset($settings[$k])) {
                    $submitted = (string) $settings[$k];
                    if (false !== strpos($submitted, '*') && $old && isset($old->$k)) {
                        // 用户没改(还是脱敏占位), 用原值
                        $settings[$k] = (string) $old->$k;
                    }
                }
            }
        }

        \Widget\Plugins\Edit::configPlugin('TeoSeo', $settings);
    }

    /**
     * 密钥脱敏: 前4位明文 + 中间*号 + 后4位明文(长度不足8位时全部*号)
     *
     * @param string $value 原始值
     * @return string 脱敏串
     */
    public static function maskSecret(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, $len - 4);
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
     * 发布后统一回调: 推送搜索引擎 + AI 生成摘要/关键词
     *
     * @param array $contents 文章数据
     * @param mixed $widget 发布组件
     */
    public static function onPublish(array $contents, $widget)
    {
        self::pushOnPublish($contents, $widget);
        self::aiOnPublish($contents, $widget);
    }

    /**
     * 发布后 AI 生成摘要与关键词。
     *
     * 走 register_shutdown_function 挂到请求末尾执行——AI 接口慢的话(几十秒)
     * 不能让发文章的人干等, 响应先回去, 生成结果写完字段下次刷新就能看到。
     * 失败静默记日志, 不影响发布本身。
     *
     * @param array $contents 文章数据
     * @param mixed $widget 发布组件
     */
    public static function aiOnPublish(array $contents, $widget)
    {
        try {
            $cid = isset($contents['cid']) ? intval($contents['cid']) : 0;
            if (0 === $cid && !empty($contents['slug'])) {
                // 兜底: 某些入口只给 slug, 反查一下 cid
                $row = self::findContentBySlug($contents['slug']);
                if ($row) {
                    $cid = intval($row['cid']);
                }
            }
            if (0 === $cid) {
                return;
            }
            register_shutdown_function(function () use ($cid) {
                try {
                    self::applyAiToCid($cid, false);
                } catch (\Throwable $e) {
                    error_log('[TeoSeo-AI] shutdown 阶段生成失败: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            error_log('[TeoSeo-AI] aiOnPublish failed: ' . $e->getMessage());
        }
    }

    /**
     * 对单篇文章执行 AI 生成(摘要 + 关键词), 结果写入自定义字段
     *
     * @param int $cid 文章 ID
     * @param bool $force 是否覆盖已有摘要
     * @return array [是否成功, 提示信息]
     */
    public static function applyAiToCid(int $cid, bool $force = false): array
    {
        try {
            return self::applyAiToCidInner($cid, $force);
        } catch (\Throwable $e) {
            error_log('[TeoSeo-AI] applyAiToCid failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return array(false, '生成异常: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    /**
     * 对单篇文章执行 AI 生成(摘要 + 关键词), 结果写入自定义字段
     *
     * @param int $cid 文章 ID
     * @param bool $force 是否覆盖已有摘要
     * @return array [是否成功, 提示信息]
     */
    private static function applyAiToCidInner(int $cid, bool $force = false): array
    {
        $config = self::readConfig();
        if (NULL === $config) {
            return array(false, '插件未配置, 请先到设置页填写 AI 接口');
        }
        $enabled = $config->aiEnabled;
        if (is_array($enabled) && !in_array('enable', $enabled)) {
            return array(false, 'AI 内容优化已关闭');
        }
        if ('' === trim((string) $config->aiBaseUrl) || '' === trim((string) $config->aiApiKey) || '' === trim((string) $config->aiModel)) {
            // 三个缺一个都不行, 免得调了半天接口报 401 才想起来 key 没填
            return array(false, 'AI 接口未配置完整(BaseURL / API Key / 模型)');
        }

        $db  = \Typecho\Db::get();
        $row = $db->fetchRow($db->select()->from('table.contents')
            ->where('cid = ?', $cid)->limit(1));
        if (!$row) {
            return array(false, '文章不存在');
        }

        $hasSummary = (NULL !== self::getAiField($cid, 'teoseo_ai_summary'));
        if ($hasSummary && !$force) {
            return array(false, '该文章已有 AI 摘要, 如需覆盖请用「强制重新生成」');
        }

        list($summary, $keywords) = TeoSeo_Client::generate($row['title'], $row['text'], $config);
        // 生成结果入库前统一清理 markdown 痕迹, 保证字段/前台/主题三方一致
        $summary  = self::cleanMetaText($summary);
        $keywords = self::cleanMetaText($keywords);
        if ('' === $summary) {
            self::logAi($row, false, 'AI 未返回有效摘要(请检查接口配置与余额)');
            return array(false, 'AI 未返回有效摘要, 请检查接口配置与余额');
        }

        self::saveAiField($cid, 'teoseo_ai_summary', $summary);
        self::saveAiField($cid, 'teoseo_ai_keywords', $keywords);
        // 同步写入主题(Inaline)识别的 SEO 字段, 让主题输出的 meta description 用上 AI 摘要。
        // 不然主题自带 description 在前, 插件钩子的去重逻辑会让 AI 摘要永远上不了前台。
        self::saveAiField($cid, 'seo_description', $summary);
        self::saveAiField($cid, 'seo_keywords', $keywords);

        $len = function_exists('mb_strlen') ? mb_strlen($summary) : strlen($summary);
        self::logAi($row, true, '摘要 ' . $len . ' 字, 关键词: ' . ('' === $keywords ? '无' : $keywords));
        return array(true, '已生成摘要(' . $len . ' 字)' . ('' === $keywords ? '' : ', 关键词: ' . $keywords));
    }

    /**
     * 前台输出 SEO meta: meta description / keywords / JSON-LD Article
     *
     * 由主题调用 $this->header() 触发, Widget\Archive::header() 会把
     * 已生成的 header 字符串与当前 archive 实例传给回调。
     *
     * @param string $header 已生成的头部元数据
     * @param mixed $archive 当前 Widget\Archive 实例
     */
    public static function outputHeaderMeta(string $header, $archive)
    {
        try {
            if (!($archive instanceof \Widget\Archive)) {
                return;
            }
            if (!$archive->is('post') && !$archive->is('page')) {
                return;
            }

            // 直接读字段表, 不依赖 fields 对象(兼容性最好)
            $summary  = '';
            $keywords = '';
            try {
                $db  = \Typecho\Db::get();
                $fds = $db->fetchAll($db->select('name', 'str_value')->from('table.fields')
                    ->where('cid = ?', $archive->cid));
                foreach ($fds as $f) {
                    if ('teoseo_ai_summary' === $f['name']) {
                        $summary = trim((string) $f['str_value']);
                    } elseif ('teoseo_ai_keywords' === $f['name']) {
                        $keywords = trim((string) $f['str_value']);
                    }
                }
            } catch (\Throwable $e) {
                // 字段读取失败时忽略
            }

            // meta description: AI 摘要优先, 否则从正文截取
            // 主题(如 Inaline)可能已经输出过 description/keywords, 再输出一遍会重复,
            // 所以先看 $header 里有没有, 有就让主题的留下
            // AI 摘要和正文 fallback 都统一走 cleanMetaText 清 markdown 痕迹
            // (AI 摘要有时带回 ## 等符号, 搜索引擎/AI 引擎看到会皱眉)
            $desc = self::cleanMetaText($summary);
            if ('' === $desc) {
                $desc = self::cleanMetaText((string) $archive->text);
            }
            if ('' !== $desc) {
                $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 150) : substr($desc, 0, 150);
            }
            $keywords = self::cleanMetaText($keywords);

            if ('' !== $desc && false === stripos($header, 'name="description"')) {
                echo '<meta name="description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            }
            if ('' !== $keywords && false === stripos($header, 'name="keywords"')) {
                echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            }

            // JSON-LD Article 结构化数据(给 Google/Bing 吃的那份)
            $options = \Utils\Helper::options();
            $siteTitle = trim((string) $options->title);

            $author = $siteTitle;
            try {
                if (isset($archive->author->name) && '' !== trim((string) $archive->author->name)) {
                    $author = trim((string) $archive->author->name);
                }
            } catch (\Throwable $e) {
            }

            $jsonLd = array(
                '@context'         => 'https://schema.org',
                '@type'            => 'Article',
                'headline'         => trim((string) $archive->title),
                'datePublished'    => date('c', $archive->created),
                'dateModified'     => date('c', $archive->modified),
                'mainEntityOfPage' => (string) $archive->permalink,
                'author'           => array('@type' => 'Person', 'name' => $author),
                'publisher'        => array('@type' => 'Organization', 'name' => $siteTitle),
            );
            if ('' !== $desc) {
                $jsonLd['description'] = $desc;
            }
            if ('' !== $keywords) {
                $jsonLd['keywords'] = $keywords;
            }
            // JSON_HEX_TAG/AMP: 防止标题或 AI 摘要里混进 </script> 之类的内容提前
            // 闭合 script 标签变成注入点, 这个坑之前真踩过
            echo '<script type="application/ld+json">'
                . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>' . "\n";

            // BreadcrumbList 结构化数据: 帮助搜索引擎与 AI 引擎理解站点层级(GEO 开关控制)
            if (self::geoEnabled()) {
                $crumb = array(
                    '@context'        => 'https://schema.org',
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => array(
                        array(
                            '@type'    => 'ListItem',
                            'position' => 1,
                            'name'     => '首页',
                            'item'     => rtrim((string) $options->siteUrl, '/') . '/',
                        ),
                    ),
                );
                try {
                    // 注意: $archive->categories 是重载属性, 不能对它用 reset()(引用语义会报
                    // "Indirect modification of overloaded property") —— 先取副本再索引
                    $cats = $archive->categories;
                    if (is_array($cats) && count($cats) > 0) {
                        $first = $cats[0];
                        $catName = trim((string) (isset($first['name']) ? $first['name'] : ''));
                        if ('' !== $catName) {
                            $crumb['itemListElement'][] = array(
                                '@type'    => 'ListItem',
                                'position' => 2,
                                'name'     => $catName,
                            );
                        }
                    }
                } catch (\Throwable $e) {
                }
                $crumb['itemListElement'][] = array(
                    '@type'    => 'ListItem',
                    'position' => count($crumb['itemListElement']) + 1,
                    'name'     => trim((string) $archive->title),
                    'item'     => (string) $archive->permalink,
                );
                echo '<script type="application/ld+json">'
                    . json_encode($crumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
                    . '</script>' . "\n";
            }
        } catch (\Throwable $e) {
            error_log('[TeoSeo-AI] outputHeaderMeta failed: ' . $e->getMessage());
        }
    }

    /**
     * 清理文本中的 Markdown 痕迹, 用于 meta description / 摘要 / 关键词输出
     *
     * 去掉行首 # 标题、引用/列表符号、行内强调/代码/删除线符号、
     * markdown 链接转纯文本、折叠空白。AI 生成的摘要与正文 fallback 都走这里。
     *
     * @param string $text 原文
     * @return string 清理后的单行文本
     */
    private static function cleanMetaText(string $text): string
    {
        $text = trim(strip_tags($text));
        if ('' === $text) {
            return '';
        }
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);            // 行首 # 标题
        $text = preg_replace('/^[>\-\*\+]\s+/m', '', $text);         // 引用 / 无序列表符号
        $text = preg_replace('/\[\s*\]\([^)]*\)/', '', $text);       // 空链接(图片占位)
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text); // markdown 链接 → 纯文本
        $text = preg_replace('/[*_`~>|]/', '', $text);               // 行内强调 / 代码 / 表格符
        $text = preg_replace('/\s+/', ' ', $text);                   // 折叠空白为单空格
        return trim($text);
    }

    /**
     * headerOptions filter: Typecho 核心输出 meta 前替换为干净版 description/keywords
     *
     * Typecho 核心对无自定义字段的文章会从 markdown 正文截取 description
     * (会带 ## 等符号, 详见 Widget_Archive::header() 的 $allows 组装)。
     * 这里在输出前统一换成 AI 摘要或清理后的正文, 同时保留 AI 关键词。
     * 钩子方式: Widget_Archive::headerOptions filter(在 echo 前执行)。
     */
    public static function cleanHeaderOptions(array $allows, $archive): array
    {
        try {
            if (!self::geoEnabled()) {
                return $allows;
            }
            if (!($archive instanceof \Widget\Archive) || (!$archive->is('post') && !$archive->is('page'))) {
                return $allows;
            }

            $summary  = '';
            $keywords = '';
            try {
                $db  = \Typecho\Db::get();
                $fds = $db->fetchAll($db->select('name', 'str_value')->from('table.fields')
                    ->where('cid = ?', $archive->cid));
                foreach ($fds as $f) {
                    if ('teoseo_ai_summary' === $f['name']) {
                        $summary = trim((string) $f['str_value']);
                    } elseif ('teoseo_ai_keywords' === $f['name']) {
                        $keywords = trim((string) $f['str_value']);
                    }
                }
            } catch (\Throwable $e) {
            }

            $desc = self::cleanMetaText($summary);
            if ('' === $desc) {
                $desc = self::cleanMetaText((string) $archive->text);
            }
            if ('' !== $desc) {
                $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 150) : substr($desc, 0, 150);
                $allows['description'] = $desc;
            }
            if ('' !== $keywords) {
                $allows['keywords'] = $keywords;
            }
        } catch (\Throwable $e) {
        }
        return $allows;
    }

    /**
     * excerpt filter: 让 Typecho 核心的摘录(archiveDescription / og:description /
     * 列表摘要)使用干净文本, 而不是带 markdown 符号的正文截取。
     *
     * 挂载点: Widget\Base\Contents 的 `excerpt` filter
     * (___excerpt() 里 Contents::pluginHandle()->filter('excerpt', ...))。
     * Typecho 核心在 single 页把 $this->plainExcerpt(= 从 excerpt 剥 HTML)赋给
     * archiveDescription, 进而被 name=description 与 og/twitter:description 使用;
     * 返回 AI 摘要(或清理后的正文), 从源头消灭 markdown 污染。
     */
    public static function cleanExcerpt($excerpt, $contents)
    {
        try {
            if (!self::geoEnabled()) {
                return $excerpt;
            }
            $cid = (isset($contents->cid) && $contents->cid) ? intval($contents->cid) : 0;
            if ($cid > 0) {
                $summary = self::getAiField($cid, 'teoseo_ai_summary');
                if ($summary) {
                    return self::cleanMetaText($summary);
                }
            }
            return self::cleanMetaText($excerpt);
        } catch (\Throwable $e) {
            return $excerpt;
        }
    }

    /**
     * markdown filter: 保护 <details> 折叠块不被 Parsedown 破坏
     *
     * Parsedown 不把 <details> 当块级 HTML, 会在其内部插入 <br>/</p>,
     * 导致 <summary> 不再是 <details> 的第一个子元素, 折叠功能与
     * details 样式全部失效(实测 2026-08-10)。
     * 处理方式: 渲染前用占位符替换 details 块, 内置 Markdown::convert
     * 渲染后再恢复原样。无 details 时返回 null, 交回内置解析。
     */
    public static function markdown(?string $text): ?string
    {
        if (!self::geoEnabled()) {
            return null;
        }
        if (null === $text || false === strpos($text, '<details')) {
            return null;
        }

        $map = array();
        $protected = preg_replace_callback(
            '/<details>\s*<summary>(.*?)<\/summary>([\s\S]*?)<\/details>/i',
            function ($m) use (&$map) {
                $k = '%%TEOSEODETAIL' . count($map) . '%%';
                $map[$k] = $m[0];
                return $k;
            },
            $text
        );

        $html = \Utils\Markdown::convert($protected);

        // 恢复占位符(移除可能被段落包裹的 <p>)
        $html = preg_replace_callback(
            '@<p>%%TEOSEODETAIL(\d+)%%</p>@',
            function ($m) use (&$map) {
                return $map['%%TEOSEODETAIL' . $m[1] . '%%'];
            },
            $html
        );
        $html = str_replace(array_keys($map), array_values($map), $html);

        return $html;
    }

    /**
     * 读取插件配置(未配置时返回 NULL, 避免 500)
     */
    private static function readConfig()
    {
        try {
            return \Utils\Helper::options()->plugin('TeoSeo');
        } catch (\Throwable $e) {
            return NULL;
        }
    }

    /**
     * GEO 优化是否启用(设置页「GEO 开关」)
     *
     * 默认关闭, 需用户手动开启; 开启后非 Inaline 主题会在设置页弹窗提示适配范围。
     *
     * @return bool
     */
    private static function geoEnabled(): bool
    {
        $config = self::readConfig();
        if (NULL === $config || !isset($config->geoEnabled)) {
            return false;
        }
        return is_array($config->geoEnabled) && in_array('enable', $config->geoEnabled);
    }

    /**
     * 读取文章自定义字段值
     *
     * @param int $cid 文章 ID
     * @param string $name 字段名
     * @return string|null 不存在时返回 NULL
     */
    private static function getAiField(int $cid, string $name)
    {
        try {
            $db  = \Typecho\Db::get();
            $row = $db->fetchRow($db->select('str_value')->from('table.fields')
                ->where('cid = ? AND name = ?', $cid, $name)->limit(1));
            return $row ? $row['str_value'] : NULL;
        } catch (\Throwable $e) {
            return NULL;
        }
    }

    /**
     * 写入(或更新)文章自定义字段
     *
     * @param int $cid 文章 ID
     * @param string $name 字段名
     * @param string $value 字段值
     */
    private static function saveAiField(int $cid, string $name, string $value)
    {
        $db  = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('cid')->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, $name)->limit(1));
        if ($row) {
            $db->query($db->update('table.fields')->rows(array('str_value' => $value))
                ->where('cid = ? AND name = ?', $cid, $name));
        } else {
            $db->query($db->insert('table.fields')->rows(array(
                'cid'         => $cid,
                'name'        => $name,
                // type 存字符串类型名('str'), 不是数字! 写 0 会让后台
                // custom-fields.php 去取不存在的 0_value 键, 刷出一堆警告
                'type'        => 'str',
                'str_value'   => $value,
                'int_value'   => 0,
                'float_value' => 0,
            )));
        }
    }

    /**
     * 记录 AI 生成日志(复用推送日志表, target 为 AI摘要)
     *
     * @param array $row 文章数据(cid / slug)
     * @param bool $ok 是否成功
     * @param string $detail 详情
     */
    private static function logAi(array $row, bool $ok, string $detail)
    {
        try {
            $options = \Utils\Helper::options();
            $siteUrl = rtrim($options->siteUrl, '/');
            $url = Typecho\Router::url('post', array('slug' => $row['slug']), $siteUrl);
            if (empty($url) || '#' == $url) {
                $url = $siteUrl . '/' . $row['slug'] . '/';
            }
            self::logPush($row['slug'], $url, 'AI摘要', $ok, $detail);
        } catch (\Throwable $e) {
            error_log('[TeoSeo-AI] logAi failed: ' . $e->getMessage());
        }
    }

    /**
     * 按 slug 查找文章(兜底用)
     *
     * @param string $slug 文章缩略名
     * @return array|null
     */
    private static function findContentBySlug(string $slug)
    {
        $db = \Typecho\Db::get();
        return $db->fetchRow($db->select()->from('table.contents')
            ->where('slug = ?', $slug)->limit(1));
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
