<?php

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Content\BlockRenderer;

$failures = 0;

function paragraph_multiline_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$renderer = new BlockRenderer();

$single = $renderer->render([['type' => 'paragraph', 'data' => ['text' => 'A']]]);
paragraph_multiline_check($single === '<p>A</p>', 'single-line paragraph keeps existing paragraph contract');

$lf = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "A\nB"]]]);
paragraph_multiline_check($lf === '<p>A<br>B</p>', 'single LF inside a paragraph becomes a line break');

$crlf = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "A\r\nB"]]]);
paragraph_multiline_check($crlf === '<p>A<br>B</p>', 'CRLF inside a paragraph becomes a line break');

$blankLine = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "A\n\nB"]]]);
paragraph_multiline_check($blankLine === '<p>A</p><p>B</p>', 'blank line splits textarea text into multiple paragraphs');

$manyBlankLines = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "A\n\n\nB"]]]);
paragraph_multiline_check($manyBlankLines === '<p>A</p><p>B</p>', 'multiple consecutive blank lines do not create unsafe raw whitespace output');

$mixed = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "中文 A\nEnglish B\n\n中英文 & <tag>"]]]);
paragraph_multiline_check(
    $mixed === '<p>中文 A<br>English B</p><p>中英文 &amp; &lt;tag&gt;</p>',
    'Chinese, English, mixed text, and HTML special characters render safely with paragraph semantics'
);

$xss = $renderer->render([['type' => 'paragraph', 'data' => ['text' => "<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>"]]]);
paragraph_multiline_check(!str_contains($xss, '<script>') && !str_contains($xss, '<img '), 'script and image payloads are escaped');
paragraph_multiline_check(
    $xss === '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p><p>&lt;img src=x onerror=alert(1)&gt;</p>',
    'XSS payloads keep paragraph structure after escaping'
);

$styled = $renderer->render([['type' => 'paragraph', 'data' => [
    'text' => "A\n\nB",
    'style' => 'lead',
    'alignment' => 'center',
    'bold' => true,
]]]);
paragraph_multiline_check(
    $styled === '<p class="paragraph-lead text-align-center"><strong>A</strong></p><p class="paragraph-lead text-align-center"><strong>B</strong></p>',
    'paragraph style, alignment, and bold formatting apply to each split paragraph'
);

if ($failures > 0) {
    echo 'Paragraph multiline rendering tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Paragraph multiline rendering tests passed.' . PHP_EOL;

