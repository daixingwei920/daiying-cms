<?php

declare(strict_types=1);

namespace Cms\Core\Admin;

use Cms\Core\Support\View;

/** Presentation only: callers retain authentication, validation and action handling. */
final class AdminDataView
{
    /** @param list<array{label:string,value:string,note?:string}> $stats
     *  @param list<array{id:string,title:string,html:string,note?:string}> $sections
     */
    public static function workspace(string $id, string $title, string $intro, array $stats, array $sections, string $actions = ''): string
    {
        $html = '<div class="admin-data-view" data-admin-data-view><header class="data-heading"><div><span class="data-eyebrow">DAIYING CMS · 管理中心</span><h1>' . View::escape($title) . '</h1><p class="muted">' . View::escape($intro) . '</p></div><div class="data-actions">' . $actions . '</div></header><div class="data-stats">';
        foreach ($stats as $stat) {
            $html .= '<div class="data-stat"><span>' . View::escape($stat['label']) . '</span><strong>' . View::escape($stat['value']) . '</strong><small>' . View::escape($stat['note'] ?? '') . '</small></div>';
        }
        $html .= '</div><div class="data-layout"><details open class="data-menu" data-report-menu><summary>切换查看分类</summary><nav aria-label="' . View::escape($title) . '分类" data-report-nav>';
        foreach ($sections as $index => $section) {
            $target = $id . '-' . $section['id'];
            $html .= '<a id="' . View::escape($target) . '-tab" href="#' . View::escape($target) . '" data-report-tab><span class="data-nav-number">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span><span>' . View::escape($section['title']) . '</span></a>';
        }
        $html .= '</nav></details><div class="data-panels">';
        foreach ($sections as $section) {
            $target = $id . '-' . $section['id'];
            $html .= '<section class="data-panel" id="' . View::escape($target) . '" data-report-panel><header class="data-panel-heading"><h2>' . View::escape($section['title']) . '</h2><p class="muted">' . View::escape($section['note'] ?? '') . '</p></header>' . $section['html'] . '</section>';
        }
        return $html . '</div></div></div>';
    }

    /** @param array<string,string> $fields */
    public static function facts(array $fields): string
    {
        $html = '<dl class="data-facts">';
        foreach ($fields as $label => $value) {
            $html .= '<div><dt>' . View::escape($label) . '</dt><dd>' . View::escape($value === '' ? '未提供' : $value) . '</dd></div>';
        }
        return $html . '</dl>';
    }

    /** @param array<mixed> $data */
    public static function raw(string $title, array $data): string
    {
        return '<details class="data-raw"><summary>' . View::escape($title) . '</summary><pre tabindex="0" aria-label="' . View::escape($title) . '">' . View::escape(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}') . '</pre></details>';
    }
}
