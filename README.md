# TeoSeo - Typecho SEO / GEO 插件

适配 **Typecho 1.3** 的自研 SEO 插件：自动 sitemap + 发布即推送（IndexNow / 百度）+ AI 摘要与关键词 + **GEO（生成式引擎）优化**。

> Typecho 1.3 适配的 SEO 插件几乎空白（老插件如 uSitemap 依赖的旧接口已被移除），这是作者自研并开源的项目。
> 博客：https://blog.astarry.cn ｜ GitHub：https://github.com/Astarry-1127/TeoSeo

## 功能

1. **自动生成 sitemap.xml**（首页 + 文章 + 独立页面，符合 Sitemaps 0.9）
2. **发布即推送**：IndexNow（Bing/Yandex/Seznam/Naver 等）+ 百度主动推送，后台「推送历史」面板记录
3. **AI 内容优化**：发布时自动生成摘要与关键词（任意 OpenAI 兼容接口），前台输出 meta description / keywords / JSON-LD Article
4. **GEO 优化**（生成式引擎优化，针对 AI 搜索引擎/引用）：
   - meta description / og:description 清理（无 markdown 污染，优先 AI 摘要）
   - JSON-LD Article + BreadcrumbList
   - 支持文章顶部「本文要点」折叠块（`<details>`），让 AI 引擎直接读到结论先行内容

## 安装

1. 上传 `TeoSeo/` 目录到 `usr/plugins/`（完整目录名为 `usr/plugins/TeoSeo/`）
2. 后台「插件」→ 启用 TeoSeo
3. 在设置页填入 IndexNow Key / 百度 Token / AI 接口

## ⚠️ GEO 优化适配声明

GEO 优化中的**「本文要点」折叠块渲染保护**依赖主题的正文渲染方式。

- 当前适配主题：**Inaline**（需同时应用 `inaline-patch/` 中的主题补丁，见 `inaline-patch/README.md`）
- 使用**其他主题**时：插件其余功能（sitemap / 推送 / AI 摘要 / meta 清理 / JSON-LD）不受影响，但「本文要点」折叠块可能无法正常渲染（Typecho 默认 markdown 解析器不识别 `<details>` 为块级元素，会被插入 `<br>/</p>` 破坏结构）
- **需要适配其他主题**：请在 [GitHub Issues](https://github.com/Astarry-1127/TeoSeo/issues) 留言，或到博客留言，作者会尽力适配

## 配置

| 项 | 说明 |
| --- | --- |
| IndexNow API Key | Bing Webmaster 生成，32 位字符串，站点根目录需存在 `{key}.txt` |
| 百度 Token | 百度搜索资源平台 → 普通收录 → 主动推送 |
| AI 接口 | 任意 OpenAI 兼容 `/chat/completions`，如智谱/DeepSeek/通义，填 BaseURL + Key + 模型 |

## 版本

- **v1.3.0**（开发中）：GEO 优化（meta/og 清理、Breadcrumb JSON-LD、details 折叠块保护）、Inaline 适配补丁
- v1.2.x：AI 内容优化、推送历史面板

## 链接

- 博客：https://blog.astarry.cn
- GitHub：https://github.com/Astarry-1127/TeoSeo
