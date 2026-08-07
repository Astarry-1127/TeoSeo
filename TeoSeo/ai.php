<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo AI 内容优化面板页
 *
 * 1. 单篇生成 / 强制重新生成(覆盖已有摘要)
 * 2. 一键批量生成存量文章: 每请求处理一篇后自动跳转续跑, 避免超时,
 *    若某篇连续失败会停止, 防止无限循环。
 * 通过 Helper::addPanel 注册, 由 admin/extending.php 加载。
 */

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Plugin.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Client.php';

$baseRedirect = 'extending.php?panel=TeoSeo%2Fai.php';
$tokenName    = 'teoseo-ai';
$msg          = NULL;

// ===== 批量生成(GET, 每次处理一篇后 302 续跑, 直到没有待生成文章) =====
// 为啥不用循环一把梭? 虚拟主机 PHP 有 max_execution_time, 几十篇一起跑必超时,
// 一篇一跳转是最土也最稳的办法, 还顺便能看进度。
if (isset($_GET['batch']) && '1' === (string) $_GET['batch']) {
    if (!isset($_GET['_']) || $_GET['_'] !== $security->getToken($tokenName)) {
        $msg = '校验失败, 请刷新页面重试';
    } else {
        $done = max(0, intval(isset($_GET['done']) ? $_GET['done'] : 0));
        $last = max(0, intval(isset($_GET['last']) ? $_GET['last'] : 0));

        if ($done >= 200) {
            $msg = '已达本次批量上限(200 篇), 如需继续请再次点击批量生成';
        } else {
            try {
                $db     = \Typecho\Db::get();
                $prefix = $db->getPrefix();
                $row = $db->fetchRow($db->query(
                    "SELECT c.cid, c.slug FROM `{$prefix}contents` c
                     LEFT JOIN `{$prefix}fields` f ON f.cid = c.cid AND f.name = 'teoseo_ai_summary'
                     WHERE c.type = 'post' AND c.status = 'publish' AND f.cid IS NULL
                     ORDER BY c.cid ASC LIMIT 1"
                ));

                if ($row) {
                    $cid = intval($row['cid']);
                    if ($cid === $last) {
                        // 上一轮刚处理过这篇且字段仍未写入 -> 生成失败, 停止防止死循环
                        $msg = '第 ' . ($done + 1) . ' 篇生成失败(已中止), 请检查 AI 接口配置后重试';
                    } else {
                        TeoSeo_Plugin::applyAiToCid($cid, false);
                        header('Location: ' . $baseRedirect
                            . '&batch=1&_=' . rawurlencode($security->getToken($tokenName))
                            . '&done=' . ($done + 1) . '&last=' . $cid);
                        exit;
                    }
                } else {
                    $msg = '批量生成完成, 本次共处理 ' . $done . ' 篇文章';
                }
            } catch (\Throwable $e) {
                $msg = '批量生成中断: ' . $e->getMessage();
            }
        }
    }
}

// ===== 单篇生成(POST) =====
if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['generate'])) {
    if (isset($_POST['_']) && $_POST['_'] === $security->getToken($tokenName)) {
        $cid   = max(0, intval(isset($_POST['cid']) ? $_POST['cid'] : 0));
        $force = isset($_POST['force']);
        if ($cid > 0) {
            $result = TeoSeo_Plugin::applyAiToCid($cid, $force);
            $msg    = $result[1];
        } else {
            $msg = '参数错误';
        }
    } else {
        $msg = '校验失败, 请刷新页面重试';
    }
    // PRG: 处理完跳回列表页, 避免刷新重复请求浪费 API 额度
    header('Location: ' . $baseRedirect . '&msg=' . rawurlencode($msg));
    exit;
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}

// 统计待生成文章数 + 最近 50 篇文章列表(未生成的排前面)
$pendingCount = 0;
$rows         = array();
try {
    $db     = \Typecho\Db::get();
    $prefix = $db->getPrefix();

    $pendingCount = intval($db->fetchObject($db->query(
        "SELECT COUNT(*) AS n FROM `{$prefix}contents` c
         LEFT JOIN `{$prefix}fields` f ON f.cid = c.cid AND f.name = 'teoseo_ai_summary'
         WHERE c.type = 'post' AND c.status = 'publish' AND f.cid IS NULL"
    ))->n);

    $rows = $db->fetchAll($db->query(
        "SELECT c.cid, c.title, c.slug, c.created,
                f.str_value AS summary, k.str_value AS keywords
         FROM `{$prefix}contents` c
         LEFT JOIN `{$prefix}fields` f ON f.cid = c.cid AND f.name = 'teoseo_ai_summary'
         LEFT JOIN `{$prefix}fields` k ON k.cid = c.cid AND k.name = 'teoseo_ai_keywords'
         WHERE c.type = 'post' AND c.status = 'publish'
         ORDER BY (f.cid IS NULL) DESC, c.cid DESC
         LIMIT 50"
    ));
} catch (\Throwable $e) {
    $msg = '文章列表读取失败: ' . $e->getMessage();
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
            <i class="fas fa-magic mr-2 hidden md:inline"></i>
            <span class="mx-2 hidden md:inline">/</span>
            <span class="font-medium text-discord-text"><?php _e('TeoSeo AI 内容优化'); ?></span>
        </div>
    </header>
    <div class="flex-1 overflow-y-auto p-6">
        <div class="bg-white rounded-lg shadow-sm p-6 mb-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="text-base font-semibold">批量生成摘要与关键词</h2>
                <?php if ($pendingCount > 0): ?>
                <a href="extending.php?panel=TeoSeo%2Fai.php&batch=1&_=<?php echo rawurlencode($security->getToken($tokenName)); ?>"
                   style="padding:8px 20px; background:#16a34a; color:#fff; border:none; border-radius:6px; font-size:14px; text-decoration:none; cursor:pointer;">
                    批量生成(待生成 <?php echo $pendingCount; ?> 篇)
                </a>
                <?php else: ?>
                <span style="color:#16a34a; font-size:13px;">全部文章均已生成 AI 摘要</span>
                <?php endif; ?>
            </div>
            <p style="color:#9ca3af; font-size:12px; margin-top:8px;">
                批量生成逐篇进行(每篇一次接口调用), 页面会自动跳转直至完成, 期间请保持页面打开。
                生成失败的文章不会反复重试, 请先在设置页确认 AI 接口配置。生成结果会同步写入推送历史(目标: AI摘要)。
            </p>
<?php if (NULL !== $msg): ?>
            <p style="padding:10px 12px; border-radius:6px; margin-top:12px;
                      background:<?php echo (false === strpos($msg, '失败') && false === strpos($msg, '关闭') && false === strpos($msg, '未') && false === strpos($msg, '校验') && false === strpos($msg, '中止')) ? '#f0fdf4; color:#166534' : '#fef2f2; color:#b91c1c'; ?>;">
                <?php echo htmlspecialchars($msg); ?>
            </p>
<?php endif; ?>
        </div>

        <div class="bg-white rounded-lg shadow-sm p-6">
<?php if (empty($rows)): ?>
            <p style="color:#999;">暂无已发布的文章。</p>
<?php else: ?>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr style="background:#f5f5f5; text-align:left;">
                    <th style="padding:8px; border:1px solid #ddd;">文章</th>
                    <th style="padding:8px; border:1px solid #ddd;">摘要</th>
                    <th style="padding:8px; border:1px solid #ddd;">关键词</th>
                    <th style="padding:8px; border:1px solid #ddd; width:160px;">操作</th>
                </tr></thead><tbody>
<?php foreach ($rows as $row):
                $hasSummary = ('' !== trim((string) $row['summary']));
                $kw = trim((string) $row['keywords']);
?>
                <tr>
                    <td style="padding:8px; border:1px solid #ddd; max-width:260px;">
                        <div style="font-weight:600; word-break:break-all;"><?php echo htmlspecialchars($row['title']); ?></div>
                        <div style="color:#9ca3af; font-size:12px;"><?php echo htmlspecialchars($row['slug']); ?></div>
                    </td>
                    <td style="padding:8px; border:1px solid #ddd; color:<?php echo $hasSummary ? '#166534' : '#9ca3af'; ?>; max-width:320px;">
                        <?php echo $hasSummary
                            ? htmlspecialchars(function_exists('mb_substr') ? mb_substr($row['summary'], 0, 60) : substr($row['summary'], 0, 60)) . (function_exists('mb_strlen') && mb_strlen($row['summary']) > 60 ? '…' : '')
                            : '未生成'; ?>
                    </td>
                    <td style="padding:8px; border:1px solid #ddd; color:#9ca3af; max-width:200px; word-break:break-all;">
                        <?php echo '' !== $kw ? htmlspecialchars($kw) : '-'; ?>
                    </td>
                    <td style="padding:8px; border:1px solid #ddd; white-space:nowrap;">
                        <form method="post" action="" style="display:inline-block; margin:0 4px 0 0;">
                            <input type="hidden" name="_" value="<?php echo $security->getToken($tokenName); ?>">
                            <input type="hidden" name="generate" value="1">
                            <input type="hidden" name="cid" value="<?php echo intval($row['cid']); ?>">
                            <button type="submit" style="padding:5px 12px; background:#2563eb; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">
                                <?php echo $hasSummary ? '重新生成' : '生成'; ?>
                            </button>
                        </form>
<?php if ($hasSummary): ?>
                        <form method="post" action="" style="display:inline-block; margin:0;">
                            <input type="hidden" name="_" value="<?php echo $security->getToken($tokenName); ?>">
                            <input type="hidden" name="generate" value="1">
                            <input type="hidden" name="force" value="1">
                            <input type="hidden" name="cid" value="<?php echo intval($row['cid']); ?>">
                            <button type="submit" style="padding:5px 12px; background:#d97706; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">
                                强制覆盖
                            </button>
                        </form>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
                </tbody></table>
            <p style="color:#999; font-size:12px; margin-top:1em;">
                仅显示最近 50 篇已发布文章(未生成摘要的排在前面)。
            </p>
<?php endif; ?>
        </div>
    </div>
</main>

<?php include __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'footer.php'; ?>
