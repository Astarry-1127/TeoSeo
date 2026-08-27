# TeoSeo — Typecho SEO / GEO 插件

> 适配 **Typecho 1.3** 的 SEO 插件：自动 sitemap · 发布即推送（IndexNow / 百度）· AI 摘要与关键词 · **GEO（生成式引擎）优化**

**仓库**：[GitHub](https://github.com/Astarry-1127/TeoSeo) · **博客**：[blog.astarry.cn](https://blog.astarry.cn) · **反馈 / 适配请求**：[Issues](https://github.com/Astarry-1127/TeoSeo/issues)

---

## 目录

- [功能特性](#功能特性)
- [安装](#安装)
- [功能截图](#功能截图)
- [配置说明](#配置说明)
- [GEO 优化与主题适配](#geo-优化与主题适配)
- [升级说明](#升级说明)
- [版本历史](#版本历史)

---

## 功能特性

| 能力 | 说明 |
| --- | --- |
| **自动 sitemap** | 生成 `/sitemap.xml`（首页 + 文章 + 独立页面，符合 Sitemaps 0.9），随文章发布自动更新 |
| **发布即推送** | IndexNow（Bing / Yandex / Seznam / Naver 等）+ 百度主动推送，后台「推送历史」面板记录 |
| **AI 内容优化** | 发布时自动生成摘要与关键词（任意 OpenAI 兼容接口），前台输出 meta description / keywords / JSON-LD Article |
| **GEO 优化** | meta / og:description 清理（无 markdown 污染）· BreadcrumbList 结构化数据 · 文章顶部「本文要点」折叠块保护 |

---

## 安装

1. 上传 `TeoSeo/` 目录到 `usr/plugins/`（完整路径 `usr/plugins/TeoSeo/`）
2. 后台「插件」→ 启用 TeoSeo
3. 设置页填入 IndexNow Key / 百度 Token / AI 接口（见[配置说明](#配置说明)）

> 如需 GEO 优化的折叠块渲染保护，还需应用主题适配补丁，见[GEO 优化与主题适配](#geo-优化与主题适配)。

---

## 功能截图

![插件设置页](screenshots/teoseo-settings.jpg)

![批量生成前：文章摘要与关键词均未生成](screenshots/teoseo-ai-panel-before.jpg)

![批量生成后：摘要与关键词自动填充，按钮变为更新生成](screenshots/teoseo-ai-panel-after.jpg)

![推送历史面板](screenshots/teoseo-push-history.png)

---

## 配置说明

| 配置项 | 说明 |
| --- | --- |
| IndexNow API Key | Bing Webmaster 生成，32 位字符串；站点根目录需存在 `{key}.txt` 验证文件 |
| 百度 Token | 百度搜索资源平台 → 普通收录 → 主动推送 |
| AI 接口 | 任意 OpenAI 兼容 `/chat/completions`（智谱 / DeepSeek / 通义等），填 BaseURL + API Key + 模型名 |
| GEO 开关 | 默认关闭，手动开启后启用 GEO 优化能力 |

---

## GEO 优化与主题适配

**GEO 优化**（生成式引擎优化，面向 AI 搜索引擎 / 引用）包括：

- meta description / og:description 清理：优先 AI 摘要，无 markdown 污染
- BreadcrumbList 结构化数据（首页 > 分类 > 文章）
- 「本文要点」折叠块保护：文章顶部 `<details>` 折叠块（默认折叠、点击展开），让 AI 引擎直接读到结论先行内容

其中**「本文要点」折叠块的渲染保护依赖主题的正文渲染方式**：

- **当前适配主题：Inaline**。需同时应用 [`inaline-patch/`](inaline-patch/README.md) 中的主题补丁（`Article.php` / `MarkdownParser.php` / `markdown.css` / `Site.php` 四个文件）
- 使用**其他主题**时：插件其余功能（sitemap / 推送 / AI 摘要 / meta 清理 / JSON-LD）不受影响，但「本文要点」折叠块可能无法正常渲染（Typecho 默认 markdown 解析器不识别 `<details>` 为块级元素，会被插入 `<br>/</p>` 破坏结构）
- **需要适配其他主题**：请在 [GitHub Issues](https://github.com/Astarry-1127/TeoSeo/issues) 留言，或到[博客](https://blog.astarry.cn)留言，会尽力适配

---

## 升级说明

> **v1.3.0 之前的版本（v1.2.x）无法通过自动更新直接升级到 v1.3.0**

- v1.2.x 的自动更新代码（`Update.php`）存在缺陷：更新后不重建插件钩子，且该文件不会随更新被覆盖（更新器不更新自身）
- v1.3.0 新增了 GEO 钩子（`headerOptions` / `excerpt` / `markdown`），旧更新逻辑无法正确处理，直接更新会导致插件激活状态损坏、设置页 500

**v1.2.x 升级到 v1.3.0（手动安装）**：

1. 后台停用并删除 `usr/plugins/TeoSeo` 目录
2. 下载 v1.3.0，上传全新 `TeoSeo/` 目录
3. 后台重新启用，重新填写配置
4. 如需 GEO 优化，同时应用 `inaline-patch/` 主题补丁

> **v1.3.0 之后的版本间更新**（如 v1.3.0 → v1.3.1）自动更新完整工作：更新后自动重建钩子，无需手动。

---

## 版本历史

- **v1.3.2**（2026-08-27）：修复主题多次调用 `$this->header()`（如 PureSuck 在评论区 `$this->header('description=0&...')`）导致 GEO meta / JSON-LD 重复输出的问题（`outputHeaderMeta` / `cleanHeaderOptions` 请求级去重）；PureSuck 主题 GEO 适配验证通过（details 折叠块保护 + meta 清理均自动生效，无需主题补丁）
- **v1.3.1**（2026-08-27）：修复「个人设置」页 500（移除多余的空 `personalConfig()`，Typecho 后台遍历启用插件个人配置时不再因缺失 `_plugin:TeoSeo` 抛异常）；AI 面板 JSON 响应纯净化（清空输出缓冲 + `json_encode` 失败兜底，杜绝前端 "Unexpected token '<'")
- **v1.3.0**（2026-08-10）：GEO 优化（meta/og 清理 + Breadcrumb JSON-LD + details 折叠块保护）、GEO 开关、主题适配检测弹窗、Inaline 适配补丁、自动更新钩子重建修复
- **v1.2.x**：AI 内容优化（摘要 / 关键词）、推送历史面板、自动更新、密钥脱敏

---

## 相关链接

- 博客：[https://blog.astarry.cn](https://blog.astarry.cn)
- GitHub：[https://github.com/Astarry-1127/TeoSeo](https://github.com/Astarry-1127/TeoSeo)
