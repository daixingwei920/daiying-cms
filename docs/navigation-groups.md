# 通用两级导航（1.2.87 未发布候选）

后台 `/admin/navigation` 支持配置一级菜单、上级菜单、排序、显示状态和普通链接/独立按钮。无链接分组只允许一级；最多二级、200项，同排序保持输入顺序。名称和地址沿用原有编辑字段。隐藏上级会隐藏其子项；删除上级会保留子链接并提升到一级。插件依赖未启用的菜单保留在后台，前台隐藏；空分组不显示。

仍保存为 `config/app.php` 中的 `navigation.primary` 平铺数组，不修改数据库。原字段 label、url、type、enabled、requires_plugin 保留，新增稳定 id、parent_id、sort_order、presentation。旧数组未保存前不迁移、不改写；旧链接/顺序完整保留。后台保存使用原 POST、管理员、CSRF 及审计入口。无效层级、重复ID、循环、危险URL或被服务器截断的表单拒绝保存，原配置不变。超过服务器 max_input_vars 时应调整服务器限额或减少单次菜单项，不能以截断数据替换完整菜单。

主题公开能力：

- `$context->menu()` 和原 `navigation` 继续提供平铺可用链接，分组标题不伪装链接；二级链接不会从旧主题消失。
- `$context->menuTree()` 新增结构化菜单；顶层字段 id、label、url、current、active、presentation、children，子项有 id、label、url、current、presentation、children（空数组）。无链接分组 url 为空，主题用按钮展开，不能创建空地址跳转。
- `navigation_tree` 注入首页、列表、搜索、分类/标签、正文和错误页面。内部路径通过公共 BasePath 规则加站点子目录；外链不改变。显式 `menus.primary` 优先，空数组表示不显示。
- 提供 children 的自定义菜单可省略 id；支持 label/title/name 和 url/href。无效自定义结构返回空菜单，不使页面报错。二级子节点不支持继续嵌套。

官网主题作为单独的1.0.7候选修改，只消费公开主题能力。菜单内容/分组/排序/下载按钮来自后台配置；没有把官网业务菜单写入Core。旧Core用平铺导航降级。新版主题支持桌面悬停/点击、Tab/上下方向键/Escape、手机展开及点击外部关闭；无JavaScript时所有链接仍可访问。

验证分开记录：`tests/navigation_groups.php` 为Core真实SQLite/控制器测试；`tests/theme_api_v1.php` 为旧Theme API回归。桌面和手机模拟视口/键盘操作记录在任务外部验收报告，不能称为真实手机测试。正式发布和生产配置变更均不在本任务授权范围。
