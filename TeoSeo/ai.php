<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo AI 内容优化面板页
 *
 * 1. 单篇生成 / 强制重新生成(覆盖已有摘要)
 * 2. 一键批量生成存量文章
 * 3. 生成全部走 AJAX(fetch), 页面不跳转不白屏: 点击后按钮变"生成中…",
 *    接口在后台跑(最长 aiTimeout), 完成后页面原地更新。
 * 通过 Helper::addPanel 注册, 由 admin/extending.php 加载。
 */

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Plugin.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Client.php';

// 虚拟主机默认 max_execution_time=30s, AI 接口慢的时候不够用, 单请求放宽
@set_time_limit(150);

$tokenName = 'teoseo-ai';
$msg       = NULL;

// ===== AJAX 生成入口(单篇 / 批量共用, 返回 JSON) =====
// 放最前面: 批量参数 batch=1 以前是 302 续跑, 现在批量逻辑整体搬到 JS 循环里
if (isset($_GET['ajax']) && '1' === (string) $_GET['ajax']) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!isset($_GET['_']) || $_GET['_'] !== $security->getToken($tokenName)) {
            echo json_encode(array('ok' => false, 'msg' => '校验失败, 请刷新页面重试'));
            exit;
        }

        $db     = \Typecho\Db::get();
        $prefix = $db->getPrefix();

        // 批量: 每请求处理一篇, JS 循环调用直到 done
        if (isset($_GET['batch']) && '1' === (string) $_GET['batch']) {
            $row = $db->fetchRow($db->query(
                "SELECT c.cid FROM `{$prefix}contents` c
                 LEFT JOIN `{$prefix}fields` f ON f.cid = c.cid AND f.name = 'teoseo_ai_summary'
                 WHERE c.type = 'post' AND c.status = 'publish' AND f.cid IS NULL
                 ORDER BY c.cid ASC LIMIT 1"
            ));
            if (!$row) {
                echo json_encode(array('ok' => true, 'done' => true, 'msg' => '批量生成完成'));
                exit;
            }
            $r = TeoSeo_Plugin::applyAiToCid(intval($row['cid']), false);
            echo json_encode(array('ok' => $r[0], 'done' => false, 'msg' => $r[1]));
            exit;
        }

        // 单篇
        $cid   = max(0, intval(isset($_GET['cid']) ? $_GET['cid'] : 0));
        $force = isset($_GET['force']);
        $r     = TeoSeo_Plugin::applyAiToCid($cid, $force);
        echo json_encode(array('ok' => $r[0], 'msg' => $r[1]));
        exit;
    } catch (\Throwable $e) {
        echo json_encode(array('ok' => false, 'msg' => '生成异常: ' . $e->getMessage()));
        exit;
    }
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
                <button type="button" id="teoseoBatchBtn"
                   style="padding:8px 20px; background:#16a34a; color:#fff; border:none; border-radius:6px; font-size:14px; cursor:pointer;">
                    批量生成(待生成 <?php echo $pendingCount; ?> 篇)
                </button>
                <?php else: ?>
                <span style="color:#16a34a; font-size:13px;">全部文章均已生成 AI 摘要</span>
                <?php endif; ?>
            </div>
            <p style="color:#9ca3af; font-size:12px; margin-top:8px;">
                批量生成逐篇进行(每篇一次接口调用), 全程在后台跑, 页面不会跳转。
                生成失败的文章不会反复重试; 结果同步写入推送历史(目标: AI摘要)。
            </p>
            <p id="teoseoMsg" style="display:none; padding:10px 12px; border-radius:6px; margin-top:12px;"></p>
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
                <tr id="row-<?php echo intval($row['cid']); ?>">
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
                        <button type="button" onclick="teoseoGen(<?php echo intval($row['cid']); ?>, false, this)"
                                style="padding:5px 12px; background:#2563eb; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">
                            <?php echo $hasSummary ? '重新生成' : '生成'; ?>
                        </button>
<?php if ($hasSummary): ?>
                        <button type="button" onclick="teoseoGen(<?php echo intval($row['cid']); ?>, true, this)"
                                style="padding:5px 12px; background:#d97706; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">
                            强制覆盖
                        </button>
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

<script>
var TEOSEO_BASE = 'extending.php?panel=TeoSeo%2Fai.php';
var TEOSEO_TOKEN = '<?php echo $security->getToken($tokenName); ?>';

function teoseoShowMsg(text, ok) {
    var el = document.getElementById('teoseoMsg');
    el.style.display = 'block';
    el.style.background = ok ? '#f0fdf4' : '#fef2f2';
    el.style.color = ok ? '#166534' : '#b91c1c';
    el.textContent = text;
}

function teoseoGen(cid, force, btn) {
    if (btn.disabled) return;
    var old = btn.textContent;
    btn.disabled = true;
    btn.textContent = '生成中…';
    fetch(TEOSEO_BASE + '&ajax=1&cid=' + cid + '&force=' + (force ? '1' : '0') + '&_=' + encodeURIComponent(TEOSEO_TOKEN))
        .then(function (r) { return r.json(); })
        .then(function (j) {
            teoseoShowMsg(j.msg, j.ok);
            // 刷新本行显示(摘要列/关键词列/按钮状态)
            location.reload();
        })
        .catch(function (e) {
            btn.disabled = false;
            btn.textContent = old;
            teoseoShowMsg('请求失败: ' + e.message, false);
        });
}

function teoseoBatch() {
    var btn = document.getElementById('teoseoBatchBtn');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '批量生成中…';
    teoseoShowMsg('开始批量生成, 每篇一次接口调用, 请保持页面打开…', true);
    var step = function () {
        fetch(TEOSEO_BASE + '&ajax=1&batch=1&_=' + encodeURIComponent(TEOSEO_TOKEN))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.done) {
                    teoseoShowMsg(j.msg, true);
                    location.reload();
                    return;
                }
                teoseoShowMsg(j.msg, j.ok);
                step(); // 继续下一篇
            })
            .catch(function (e) {
                btn.disabled = false;
                btn.textContent = '批量生成';
                teoseoShowMsg('批量生成中断: ' + e.message, false);
            });
    };
    step();
}

var teoseoBatchEl = document.getElementById('teoseoBatchBtn');
if (teoseoBatchEl) {
    teoseoBatchEl.onclick = teoseoBatch;
}
</script>

<?php include __TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__ . 'footer.php'; ?>
