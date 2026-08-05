# TeoSeo - Typecho SEO 插件

为 Typecho 提供开箱即用的 SEO 能力,兼容 **Typecho 1.3**(已适配新版接口)。

## 功能

- **自动生成 /sitemap.xml** — 符合 Sitemaps 0.9 协议,动态生成首页 + 全部文章(+ 可选独立页面),URL 走路由表生成,兼容自定义链接格式(如 `/[slug]/`)
- **发布文章自动推送收录**:
  - **IndexNow** — 一次提交,Bing / Yandex / Seznam / Naver / 360 等多平台生效
  - **百度主动推送** — 百度搜索资源平台普通收录
- 推送失败静默降级(不影响发布流程),无外部依赖,仅需 PHP curl(无 curl 时自动退回流包装器)

## 安装

1. 下载并解压,将 `TeoSeo` 目录上传到 `usr/plugins/`
2. 后台 → 插件 → 启用 **TeoSeo**
3. 插件设置中填写:
   - **IndexNow API Key**:在 [Bing Webmaster](https://www.bing.com/webmasters/indexnow) 生成(32 位字符串)。需保证站点根目录存在 `{key}.txt` 验证文件(内容为 key 本身),插件会在推送时自动尝试写入
   - **百度推送 Token**:百度搜索资源平台 → 普通收录 → 主动推送 中获取

## 使用

- sitemap:访问 `https://你的域名/sitemap.xml`,并在 [robots.txt](https://zhuanlan.zhihu.com/p/341513115) 中加入 `Sitemap: https://你的域名/sitemap.xml`
- 推送:正常发布文章即可,无需任何手动操作

## 兼容性

- **Typecho 1.3+**(2026 版,已移除 `Typecho_Plugin_Interface` 全局接口与旧版 `Helper` 全局类,本插件全程使用命名空间版 API:`\Utils\Helper` / `\Typecho\Plugin` / `\Typecho\Widget`)
- PHP 8.x

## 常见问题

**Q: 发布后没有推送?**
- 检查插件设置里 key/token 是否填写、推送开关是否勾选
- 检查站点根目录 `{key}.txt` 是否存在(IndexNow 必需)
- 推送失败会写入 PHP 错误日志(`[TeoSeo]` 前缀)

**Q: sitemap 里没有某篇文章?**
- sitemap 只包含 `status=publish` 且 `type=post` 的内容,草稿/隐藏文章不会出现

## 相关文章

- [《Typecho1.3SEO插件TeoSeo:自动 sitemap + 发布即推送(IndexNow/百度)》](https://blog.astarry.cn/typecho-seo-plugin/) — 插件开发实录:功能、安装配置、以及给开发者的 Typecho 1.3 插件适配要点(接口变化 / 命名空间 / 面板机制 / 虚拟主机 SSL 坑)

*来自 [Astarry 的技术日记](https://blog.astarry.cn/),记录 Typecho 运维与开源实践。*

## License

GPL-2.0 — 与 Typecho 一致。欢迎 fork、issue、PR。

---

*由 [Astarry](https://blog.astarry.cn) 开发维护,在 blog.astarry.cn 生产环境验证通过。*
