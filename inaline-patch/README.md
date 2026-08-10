# TeoSeo - Inaline 主题适配补丁

**为什么需要这个补丁**：Inaline 主题的正文渲染**直接调用 `Utils\Markdown::convert`（Parsedown）**，绕过了 Typecho 标准的 `markdown` filter。Parsedown 不把 `<details>` 当作块级 HTML，会在其内部插入 `<br>/</p>`，导致 `<summary>` 不再是 `<details>` 的第一个子元素——「本文要点」折叠块失效（无法折叠、样式丢失）。

该补丁让 Inaline 主题的正文渲染保护 `<details>` 折叠块，是 TeoSeo **GEO 优化**的前提之一。

## 补丁内容（4 个文件，覆盖对应主题文件）

| 文件 | 说明 |
| --- | --- |
| `Article.php` | 正文渲染改用 `MarkdownParser::parseMarkdown()`（保护 `<details>`） |
| `MarkdownParser.php` | 新增 `parseMarkdown()` 方法：渲染前占位符保护 details → `Utils\Markdown::convert` → 渲染后恢复 |
| `markdown.css` | 「本文要点」折叠块样式（橙色主题风、hover、`::marker` 三角） |
| `Site.php` | `markdown.css` 引用加版本参数（`?v=...`），打破 EdgeOne/CDN 缓存 |

## 应用方法

1. **备份**：先备份主题原文件（`usr/themes/Inaline/core/Widgets/Article.php`、`.../Modules/MarkdownParser/MarkdownParser.php`、`.../assets/css/markdown.css`、`.../core/Widgets/Site.php`）
2. **覆盖**：把本目录同名文件覆盖到对应路径
3. **缓存**：`Site.php` 中 `markdown.css` 的版本参数（`?v=20260815`）若与当前站点冲突，可自行改一个更大/不同的值；新文章顶部需按格式写入「本文要点」折叠块：

```html
<details><summary>本文要点</summary>
<ul>
<li>要点一</li>
<li>要点二</li>
</ul>
</details>
```

4. 覆盖后访问文章页确认折叠块正常显示（有边框背景、可点击展开）

## 回滚

用第 1 步的备份覆盖回去即可（并清除版本参数）。主题升级后需重新应用本补丁。

## 适配其他主题

其他主题若正文走 Typecho 标准渲染链路，TeoSeo 插件自带的 `markdown` filter 已能保护 `<details>`，无需本补丁；若主题也自定义渲染（绕过 filter），请在 [GitHub Issues](https://github.com/Astarry-1127/TeoSeo/issues) 留言请求适配。
