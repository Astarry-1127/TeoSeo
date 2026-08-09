# TeoSeo - Typecho SEO 插件

为 Typecho 提供开箱即用的 SEO 能力,兼容 **Typecho 1.3**(已适配新版接口)。

## 功能

- **自动生成 /sitemap.xml** — 符合 Sitemaps 0.9 协议,动态生成首页 + 全部文章(+ 可选独立页面),URL 走路由表生成,兼容自定义链接格式(如 `/[slug]/`)
- **发布文章自动推送收录**:
  - **IndexNow** — 一次提交,Bing / Yandex / Seznam / Naver / 360 等多平台生效
  - **百度主动推送** — 百度搜索资源平台普通收录
- **手动重新推送**(v1.1.0):后台推送历史面板输入 slug 即可随时补推(发布漏推或文章更新后重新通知搜索引擎)
- **AI 内容优化**(v1.2.0,任意 OpenAI 兼容接口):
  - **发布即生成** — 文章发布时自动调用 AI 生成摘要与关键词,已有摘要不覆盖,失败静默不影响发布
  - **批量生成** — 后台「TeoSeo → AI 内容优化」面板一键处理存量文章,逐篇续跑防超时,失败自动中止防死循环
  - **前台输出** — AI 摘要自动输出为 `meta description` / `meta keywords`,并附带 JSON-LD Article 结构化数据
- **记录翻页查看**(v1.2.1):推送历史与 AI 内容优化面板均改为分页展示,每页 20 条,最高 999 页,长列表不再只显示最近 50 条
- **自动更新**(v1.2.2):设置页一键「检查更新 / 立即更新」,从 GitHub main 分支拉取最新代码,更新前自动备份旧版到 `usr/plugins/TeoSeo-backup/`,更新后自动重启插件
- **密钥脱敏显示**(v1.2.2):设置页的 IndexNow / 百度 / AI 三个 API Key 输入框改为前 4 位 + 后 4 位明文、中间 `*` 号,不修改直接保存时不会覆盖原 key
- 推送失败静默降级(不影响发布流程),无外部依赖,仅需 PHP curl(无 curl 时自动退回流包装器)

## 效果预览

插件设置页:IndexNow / 百度推送 / Sitemap / AI 内容优化(OpenAI 兼容接口)全部集中管理:

![插件设置页](screenshots/teoseo-settings.jpg)

后台「AI 内容优化」面板:一键批量生成存量文章摘要与关键词,未生成与已生成的对比:

![批量生成前:文章摘要与关键词均未生成](screenshots/teoseo-ai-panel-before.jpg)

![批量生成后:摘要与关键词自动填充,按钮变为更新生成](screenshots/teoseo-ai-panel-after.jpg)

推送历史面板:每次 IndexNow / 百度推送的成败与响应一目了然:

![推送历史面板](screenshots/teoseo-push-history.png)

## 安装

1. 下载并解压,将 `TeoSeo` 目录上传到 `usr/plugins/`
2. 后台 → 插件 → 启用 **TeoSeo**
3. 插件设置中填写:
   - **IndexNow API Key**:在 [Bing Webmaster](https://www.bing.com/webmasters/indexnow) 生成(32 位字符串)。需保证站点根目录存在 `{key}.txt` 验证文件(内容为 key 本身),插件会在推送时自动尝试写入
   - **百度推送 Token**:百度搜索资源平台 → 普通收录 → 主动推送 中获取
   - **AI 接口**(可选,不填则跳过 AI 功能):任意 OpenAI 兼容接口,填写 BaseURL / API Key / 模型名。例如 DeepSeek(`https://api.deepseek.com/v1` + `deepseek-chat`)、通义千问、智谱 GLM 等

## 使用

- sitemap:访问 `https://你的域名/sitemap.xml`,并在 [robots.txt](https://zhuanlan.zhihu.com/p/341513115) 中加入 `Sitemap: https://你的域名/sitemap.xml`
- 推送:正常发布文章即可,无需任何手动操作
- AI 摘要/关键词:发布新文章自动生成;存量文章在后台 **TeoSeo → AI 内容优化** 面板点「批量生成」逐篇处理(每篇一次接口调用,保持页面打开即可,自动跳转直到完成)
- 生成记录:AI 生成结果与推送记录一同写入「推送历史」面板(目标: AI摘要)

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

**Q: AI 摘要生成失败?**
- 检查设置页 AI 接口 BaseURL / API Key / 模型名是否填写正确
- 确认接口平台账户余额充足、模型名与平台一致
- 失败信息会写入 PHP 错误日志(`[TeoSeo-AI]` 前缀),也会记入「推送历史」面板(目标: AI摘要)

## 给 Typecho 1.3 插件开发者的避坑清单

开发 TeoSeo 过程中踩过的坑,浓缩成 8 条(详细版见博客):

1. 涉及外部接口的操作:**默认按 30s 执行上限设计**(虚拟主机 `max_execution_time=30s`),能异步就异步
2. 面板长操作:**优先 AJAX**,别用表单 POST + 302 跳转——`output_buffering` 会把响应憋住,白屏防不胜防
3. 自定义字段:`typecho_fields.type` 存**字符串类型名**(`'str'`),不是数字 0
4. 升级脚本:**先清 `options.plugins` 注册表再激活**,且只跑一次(⚠️ 会清空所有插件,仅限单插件场景;多插件请只删对应条目)
5. 输出到 `<script>` 的 JSON:**必须 `JSON_HEX_TAG`**,AI 生成的不可信内容会闭合标签注入
6. curl 外部 HTTPS:**默认严格校验**,虚拟主机 CA 残缺时按需降级,别一刀切全关(API Key 会裸奔)
7. 调 AI 生成:**避开推理模型**(content 常为空),prompt 里的要求要防模型原样抄进输出
8. `fastcgi_finish_request()` 是虚拟主机异步的好朋友,但要先解决 `output_buffering` 憋响应的问题

完整踩坑实录:[《TeoSeo 插件开发踩坑实录:从白屏到 JSON-LD 注入的八个坑》](https://blog.astarry.cn/teoseo-plugin-pitfalls-guide/)

## 更新日志

- **v1.2.4**(2026-08-09):
  - **菜单改名** — 后台左侧两个 TeoSeo 独立页面标题从统一的"TeoSeo"改为「TeoSeo 推送历史」与「TeoSeo AI 内容优化」,一眼可分辨。
  - **自动更新区块位置** — 设置页的「自动更新」区块移到所有配置项之下、保存设置按钮正上方(Typecho 的 echo 内容渲染在表单前, 用 JS 移入 form 内 submit 前)。
- **v1.2.3**(2026-08-09):
  - **修复菜单重复累积** — Typecho 的 `Helper::addPanel` 每次激活都无条件追加菜单项,重复激活/自动更新升级会把「推送历史」「AI 内容优化」累积成多个 TeoSeo 菜单。新增 `ensurePanelUnique()`:addPanel 前先 removePanel 同 URL 面板,保证激活幂等,菜单不再翻倍。
- **v1.2.2**(2026-08-09):
  - **自动更新** — 设置页新增「自动更新」区块,一键检查 GitHub main 分支是否有新版、一键下载更新。更新前自动备份旧版 5 个核心文件到 `usr/plugins/TeoSeo-backup/`,更新后自动重启插件重建钩子;仅后台管理员可操作(权限 + CSRF 校验),下载失败/解压异常/覆盖失败均有提示且不影响现有版本。
  - **密钥脱敏显示** — 设置页的 IndexNow API Key、百度推送 Token、AI API Key 三个输入框不再明文展示,改为前 4 位 + 后 4 位明文、中间 `*` 号。直接保存(不修改)时通过 `configHandle` 还原为数据库原 key,不会覆盖;填全新值才更新。
  - **修复** — 自动更新的 action widget 必须继承 `Typecho\Widget` 才能被 `Widget\Action` 以三参数实例化(此前直接 `implements ActionInterface` 会因参数过多触发 500)。
- **v1.2.1**(2026-08-09):推送历史与 AI 内容优化面板改翻页,每页 20 条、最高 999 页,长列表不再只显示最近 50 条;同步删除面板底部"完整记录保留在数据库 seo_logs 表"的冗余提示。
- **v1.2.0**(2026-08-07):AI 内容优化上线 —— 发布文章自动生成摘要与关键词、存量文章一键批量生成、前台输出 meta description / keywords 与 JSON-LD Article。本版本代码已从 `Ai` 分支合并到 `main`,当前主线即最新版。

## 相关文章

- [《Typecho1.3SEO插件TeoSeo:自动 sitemap + 发布即推送(IndexNow/百度)》](https://blog.astarry.cn/typecho-seo-plugin/) — 插件开发实录:功能、安装配置、以及给开发者的 Typecho 1.3 插件适配要点(接口变化 / 命名空间 / 面板机制 / 虚拟主机 SSL 坑)
- [《TeoSeo v1.2.0 发布:AI 自动生成摘要与关键词,SEO 三件套一次配齐》](https://blog.astarry.cn/teoseo-v1-2-0-ai-guide/) — AI 内容优化详解:摘要/关键词自动生成、meta/JSON-LD 输出、批量生成面板与上线前修掉的坑

*来自 [Astarry 的技术日记](https://blog.astarry.cn/),记录 Typecho 运维与开源实践。*

## License

GPL-2.0 — 与 Typecho 一致。欢迎 fork、issue、PR。

---

*由 [Astarry](https://blog.astarry.cn) 开发维护,在 blog.astarry.cn 生产环境验证通过。*
