<?php

declare(strict_types=1);

use Cms\Core\Http\Request;

require __DIR__ . '/theme_t1_common.php';

$root = theme_t1_root('safe');
unlink($root . '/content/themes/default/templates/home.php');
$response = theme_t1_app($root)->handle(new Request('GET', '/'));

theme_t1_check($response->status() === 200 && str_contains($response->body(), '安全主题正在运行'), 'falls back to Safe Theme when default home template is damaged');
theme_t1_check(!str_contains($response->body(), 'Daiying Default') && !str_contains($response->body(), '商城'), 'Safe Theme remains recovery-oriented and minimal');

theme_t1_remove($root);
echo "Theme T1 safe fallback tests passed.\n";
