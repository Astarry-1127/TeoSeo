<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo 推送历史面板页
 *
 * 后台独立页面: 展示最近 50 条推送记录,
 * 支持手动输入 slug 重新推送(IndexNow + 百度)。
 * 通过 Helper::addPanel 注册, 由 admin/extending.php 加载。
 */

// ===== 处理手动推送 POST =====
$pushResult = NULL;
if ('POST' === $_SERVER['REQUEST_METHOD']) {
    // CSRF 校验(自定义 suffix, 不依赖 referer)
    if (isset($_POST['_']) && $_POST['_'] === $security->getToken('teoseo-push')) {
        $slug = trim(isset($_POST['slug']) ? $_POST['slug'] : '');
        if ('' !== $slug) {
            try {
                $db = \Typecho\Db::get();
                $exists = $db->fetchRow($db->select()->from('table.contents')
                    ->where('slug = ?', $slug)->limit(1));
                if ($exists) {
                    require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Plugin.php';
                    TeoSeo_Plugin::pushOnPublish(array('slug' => $slug), NULL);
                    $pushResult = '已推送: ' . $slug . '(IndexNow + 百度), 结果见下方表格最新两条';
                } else {
                    $pushResult = 'slug 不存在: ' . $slug;
                }
            } catch (\Throwable $e) {
                $pushResult = '推送失败: ' . $e->getMessage();
            }
        } else {
            $pushResult = '请填写文章 slug';
        }
    } else {
        $pushResult = '校验失败, 请刷新页面重试';
    }
}

include __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'header.php';
include __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'menu.php';
?>

<main class="flex-1 flex flex-col overflow-hidden bg-discord-light">
    <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 z-10">
        <div class="flex items-center text-discord-muted">
            <button id="mobile-menu-btn" class="mr-4 md:hidden text-discord-text focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
            <i class="fas fa-history mr-2 hidden md:inline"></i>
            <span class="mx-2 hidden md:inline">/</span>
            <span class="font-medium text-discord-text"><?php _e('TeoSeo 推送历史'); ?></span>
        </div>
    </header>
    <div class="flex-1 overflow-y-auto p-6">
        <div class="bg-white rounded-lg shadow-sm p-6 mb-4">
            <h2 class="text-base font-semibold mb-3">手动重新推送</h2>
<?php if (NULL !== $pushResult): ?>
            <p style="padding:10px 12px; border-radius:6px; margin-bottom:12px;
                      background:<?php echo (false === strpos($pushResult, '失败') && false === strpos($pushResult, '不存在') && false === strpos($pushResult, '校验') && false === strpos($pushResult, '请填写')) ? '#f0fdf4; color:#166534' : '#fef2f2; color:#b91c1c'; ?>;">
                <?php echo htmlspecialchars($pushResult); ?>
            </p>
<?php endif; ?>
            <form method="post" action="">
                <input type="hidden" name="_" value="<?php echo $security->getToken('teoseo-push'); ?>">
                <div class="flex items-center gap-2 flex-wrap">
                    <input type="text" name="slug" placeholder="文章 slug, 如 linux-crontab-guide"
                           style="flex:1; min-width:260px; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                    <button type="submit" style="padding:8px 20px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:14px; cursor:pointer;">
                        重新推送
                    </button>
                </div>
                <p style="color:#9ca3af; font-size:12px; margin-top:8px;">
                    手动触发 IndexNow + 百度推送(用于发布时漏推、或文章更新后想重新通知搜索引擎)。
                </p>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
<?php
try {
    $db     = \Typecho\Db::get();
    $prefix = $db->getPrefix();
    $rows   = $db->fetchAll($db->query("SELECT * FROM `{$prefix}seo_logs` ORDER BY `id` DESC LIMIT 50"));

    if (empty($rows)) {
        echo '<p style="color:#999;">暂无推送记录, 发布新文章后这里会显示每次推送给搜索引擎的结果。</p>';
    } else {
        echo '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        echo '<thead><tr style="background:#f5f5f5; text-align:left;">'
            . '<th style="padding:8px; border:1px solid #ddd;">时间</th>'
            . '<th style="padding:8px; border:1px solid #ddd;">文章</th>'
            . '<th style="padding:8px; border:1px solid #ddd;">推送 URL</th>'
            . '<th style="padding:8px; border:1px solid #ddd;">目标</th>'
            . '<th style="padding:8px; border:1px solid #ddd;">状态</th>'
            . '<th style="padding:8px; border:1px solid #ddd;">详情</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $ok    = ('success' == $row['status']);
            $color = $ok ? '#16a34a' : '#dc2626';
            echo '<tr>'
                . '<td style="padding:8px; border:1px solid #ddd; white-space:nowrap;">' . date('Y-m-d H:i:s', $row['created']) . '</td>'
                . '<td style="padding:8px; border:1px solid #ddd;">' . htmlspecialchars($row['slug']) . '</td>'
                . '<td style="padding:8px; border:1px solid #ddd; word-break:break-all;">' . htmlspecialchars($row['url']) . '</td>'
                . '<td style="padding:8px; border:1px solid #ddd; white-space:nowrap;">' . htmlspecialchars($row['target']) . '</td>'
                . '<td style="padding:8px; border:1px solid #ddd; color:' . $color . '; font-weight:bold; white-space:nowrap;">'
                . ($ok ? '成功' : '失败') . '</td>'
                . '<td style="padding:8px; border:1px solid #ddd; word-break:break-all;">'
                . htmlspecialchars($row['detail']) . '</td>'
                . '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="color:#999; font-size:12px; margin-top:1em;">仅显示最近 50 条, 完整记录保留在数据库 '
            . $prefix . 'seo_logs 表。</p>';
    }
} catch (\Throwable $e) {
    echo '<p style="color:#dc2626;">推送历史读取失败: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>
        </div>
    </div>
</main>

<?php include __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'footer.php'; ?>
