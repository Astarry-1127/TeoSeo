<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo 推送历史面板页
 *
 * 后台独立页面, 展示最近 50 条搜索引擎推送记录。
 * 通过 Helper::addPanel 注册, 由 admin/extending.php 加载。
 */

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
