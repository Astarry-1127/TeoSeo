<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TeoSeo 自动更新处理器(/action/teoseo-update)
 *
 * 通过 GitHub API 检查 main 分支是否有新版, 下载 zipball 并解压覆盖。
 * 更新前自动备份旧版到 usr/plugins/TeoSeo-backup/, 更新失败可手动回滚。
 * 安全: 仅允许后台 administrator 调用, 需 CSRF token。
 */

require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo/Plugin.php';

/**
 * TeoSeo 自动更新 Action widget
 *
 * 必须继承 Typecho\Widget 并实现 \Widget\ActionInterface——
 * Widget\Action 分发时用 Widget::widget() 以 ($request, $response, $params)
 * 三个参数实例化, 不继承 Widget 会因参数过多导致 500。
 */
class TeoSeo_Update extends \Typecho\Widget implements \Widget\ActionInterface
{
    const GITHUB = 'Astarry-1127/TeoSeo';
    const RAW_PLUGIN = 'https://raw.githubusercontent.com/Astarry-1127/TeoSeo/main/TeoSeo/Plugin.php';
    const ZIPBALL = 'https://api.github.com/repos/Astarry-1127/TeoSeo/zipball/main';
    const TOKEN_NAME = 'teoseo-update';

    /**
     * 构造函数(与 Widget 一致)
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
     * 入口
     */
    public function action()
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            try {
                // 权限校验
                $user = \Typecho\Widget::widget('Widget_User');
                if (!$user->hasLogin() || !$user->pass('administrator', false)) {
                    $this->out(false, '无权限');
                    return;
                }
            } catch (\Throwable $e) {
                error_log('[TeoSeo-Update] 权限校验异常: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $this->out(false, '权限校验异常: ' . $e->getMessage());
                return;
            }

            // CSRF 校验
            $security = \Typecho\Widget::widget('Widget_Security');
            if (!isset($_POST['_']) || $_POST['_'] !== $security->getToken(self::TOKEN_NAME)) {
                $this->out(false, '校验失败, 请刷新页面重试');
                return;
            }

            $action = isset($_POST['action']) ? $_POST['action'] : '';
            switch ($action) {
                case 'check':
                    $this->handleCheck();
                    break;
                case 'update':
                    $this->handleUpdate();
                    break;
                default:
                    $this->out(false, '未知操作');
            }
        } catch (\Throwable $e) {
            $this->out(false, '更新异常: ' . $e->getMessage());
        }
    }

    /**
     * 输出 JSON
     *
     * @param bool $ok
     * @param string $msg
     * @param mixed $data
     */
    private function out($ok, $msg, $data = null)
    {
        echo json_encode(array('ok' => (bool) $ok, 'msg' => (string) $msg, 'data' => $data));
        exit;
    }

    /**
     * 发送 HTTP GET
     *
     * @param string $url
     * @param int $timeout
     * @return array [code, body]
     */
    private function httpGet($url, $timeout = 30)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'TeoSeo-Updater/1.0',
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
        $resp = @file_get_contents($url, false, stream_context_create(array(
            'http' => array('timeout' => $timeout, 'user_agent' => 'TeoSeo-Updater/1.0'),
        )));
        return array(false === $resp ? 0 : 200, (string) $resp);
    }

    /**
     * 解析 Plugin.php 的 @version
     */
    private function parseVersion($content)
    {
        if (preg_match('/@version\s+([\w.]+)/', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 递归删除目录
     */
    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 检查更新
     */
    private function handleCheck()
    {
        $local = \TeoSeo_Plugin::VERSION;

        list($code, $body) = $this->httpGet(self::RAW_PLUGIN, 30);
        if (200 != $code || '' === $body) {
            $this->out(false, '无法连接 GitHub 检查更新(HTTP ' . $code . ')');
        }
        $remote = $this->parseVersion($body);
        if ('' === $remote) {
            $this->out(false, '无法解析远程版本号');
        }

        $hasUpdate = version_compare($remote, $local) > 0;
        $this->out(
            true,
            $hasUpdate
                ? '发现新版本 v' . $remote . '(当前 v' . $local . ')'
                : '当前已是最新版本 v' . $local,
            array('local' => $local, 'remote' => $remote, 'hasUpdate' => $hasUpdate)
        );
    }

    /**
     * 执行更新
     */
    private function handleUpdate()
    {
        $pluginDir = __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo';
        if (!is_dir($pluginDir)) {
            $this->out(false, '插件目录不存在: ' . $pluginDir);
        }

        // 1. 下载 zipball
        list($code, $body) = $this->httpGet(self::ZIPBALL, 120);
        if (200 != $code || '' === $body) {
            $this->out(false, '下载更新包失败(HTTP ' . $code . ')');
        }
        $tmpZip = sys_get_temp_dir() . '/teoseo-update-' . time() . '.zip';
        if (false === @file_put_contents($tmpZip, $body)) {
            $this->out(false, '无法写入临时文件(磁盘空间或权限不足?)');
        }

        // 2. 解压
        $tmpDir = sys_get_temp_dir() . '/teoseo-unzip-' . time();
        if (!@mkdir($tmpDir, 0755, true)) {
            @unlink($tmpZip);
            $this->out(false, '无法创建临时目录');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpZip)) {
            $this->rrmdir($tmpDir);
            @unlink($tmpZip);
            $this->out(false, '更新包不是有效的 zip 文件');
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        // 3. 找解压后的 TeoSeo 目录
        $srcDir = '';
        foreach (scandir($tmpDir) as $e) {
            if ('.' === $e || '..' === $e) {
                continue;
            }
            if (is_dir($tmpDir . '/' . $e . '/TeoSeo')) {
                $srcDir = $tmpDir . '/' . $e . '/TeoSeo';
                break;
            }
        }
        if ('' === $srcDir || !is_dir($srcDir)) {
            $this->rrmdir($tmpDir);
            @unlink($tmpZip);
            $this->out(false, '更新包结构异常, 未找到 TeoSeo 目录');
        }

        // 4. 备份旧版
        $backupDir = __TYPECHO_ROOT_DIR__ . '/usr/plugins/TeoSeo-backup';
        $this->rrmdir($backupDir);
        if (!@mkdir($backupDir, 0755, true)) {
            $this->rrmdir($tmpDir);
            @unlink($tmpZip);
            $this->out(false, '无法创建备份目录');
        }
        $coreFiles = array('Plugin.php', 'Client.php', 'Widget.php', 'ai.php', 'logs.php');
        $backedUp = 0;
        foreach ($coreFiles as $f) {
            if (file_exists($pluginDir . '/' . $f) && @copy($pluginDir . '/' . $f, $backupDir . '/' . $f)) {
                $backedUp++;
            }
        }

        // 5. 覆盖
        $overwritten = 0;
        foreach ($coreFiles as $f) {
            if (file_exists($srcDir . '/' . $f) && @copy($srcDir . '/' . $f, $pluginDir . '/' . $f)) {
                $overwritten++;
            }
        }

        // 6. 清理
        $this->rrmdir($tmpDir);
        @unlink($tmpZip);

        if (0 === $overwritten) {
            $this->out(false, '更新失败: 没有文件被覆盖(目标目录不可写?)');
        }

        // 7. 重启插件(重建钩子)
        // 正确升级法: 重置内存 + 清空 DB 注册表, 再重新 activate(注册路由/面板/钩子),
        // 最后把 export() 写回 DB。否则只覆盖文件不重建钩子, 新版新增钩子(如 GEO 的
        // headerOptions/excerpt/markdown)不会被注册, 且 DB 残留旧 handles 会导致设置页 500。
        $reloaded = true;
        try {
            $db = \Typecho\Db::get();
            \Typecho\Plugin::init(array('handles' => array(), 'activated' => array()));
            $db->query($db->update('table.options')
                ->rows(array('value' => json_encode(array('handles' => array(), 'activated' => array()))))
                ->where('name = ?', 'plugins'));

            \TeoSeo_Plugin::activate();
            \Typecho\Plugin::activate('TeoSeo');

            $db->query($db->update('table.options')
                ->rows(array('value' => json_encode(\Typecho\Plugin::export())))
                ->where('name = ?', 'plugins'));
        } catch (\Throwable $e) {
            $reloaded = false;
            error_log('[TeoSeo-Update] 插件重启失败: ' . $e->getMessage());
        }

        $this->out(
            true,
            '更新完成: 覆盖 ' . $overwritten . ' 个文件, 备份 ' . $backedUp . ' 个到 TeoSeo-backup/'
            . ($reloaded ? '' : ' (插件需手动重启)'),
            array('backedUp' => $backedUp, 'overwritten' => $overwritten)
        );
    }
}
