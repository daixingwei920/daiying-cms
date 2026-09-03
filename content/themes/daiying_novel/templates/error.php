<?php

declare(strict_types=1);

http_response_code(404);
$assetBase = '/content/themes/daiying_novel/assets';
$inlineCss = is_file(__DIR__ . '/../assets/style.css') ? (string) file_get_contents(__DIR__ . '/../assets/style.css') : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>未找到</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/style.css">
    <?php if ($inlineCss !== ''): ?><style><?= $inlineCss ?></style><?php endif; ?>
</head>
<body>
<main class="error-shell">
    <section class="error-box">
        <p class="eyebrow">Daiying Novel</p>
        <h1>页面未找到</h1>
        <p class="detail-desc">当前小说或章节暂时不可访问，可能还没有完成采集或已经调整链接。</p>
        <div class="error-actions">
            <a href="/novels">返回书库</a>
            <a class="secondary" href="/">返回首页</a>
        </div>
    </section>
</main>
</body>
</html>
