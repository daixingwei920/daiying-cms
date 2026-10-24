<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use InvalidArgumentException;

/** Internal implementation for the Issue #9 settings/output contract. */
final class SeoPresentation
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<string,mixed> */
    public static function input(Request $request): array
    {
        if ($request->input('seo_geo_section') !== '1') {
            return []; // An older form/client must not reset new options.
        }
        $values = [];
        foreach (['title' => 300, 'description' => 600, 'keywords' => 300] as $key => $max) {
            $raw = $request->input('seo_' . $key . '_template', '');
            if (!is_string($raw) || strlen($raw) > $max || preg_match('//u', $raw) !== 1
                || preg_match('/[\x00-\x1F\x7F<>]/', $raw) === 1) {
                throw new InvalidArgumentException('TDK 模板过长或包含无效字符。');
            }
            $left = str_replace(['{title}', '{site_name}', '{description}', '{keywords}'], '', $raw);
            if (strpbrk($left, '{}') !== false) {
                throw new InvalidArgumentException('TDK 模板仅支持 {title}、{site_name}、{description}、{keywords}。');
            }
            $values[$key . '_template'] = trim($raw);
        }
        foreach (['schema_enabled', 'llms_enabled', 'crawler_log_enabled'] as $key) {
            $values[$key] = $request->input('seo_' . $key, '') === '1';
        }
        foreach (['ai_search_policy', 'ai_training_policy'] as $key) {
            $value = $request->input('seo_' . $key, 'inherit');
            if (!in_array($value, ['inherit', 'allow', 'disallow'], true)) {
                throw new InvalidArgumentException('AI 爬虫策略无效。');
            }
            $values[$key] = $value;
        }
        $days = $request->input('seo_crawler_retention_days', '7');
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1 || (int) $days > 30) {
            throw new InvalidArgumentException('蜘蛛记录保留期必须为 1 至 30 天。');
        }
        $values['crawler_retention_days'] = (int) $days;
        return $values;
    }

    /** Existing SEO keys remain intact. @param array<string,mixed> $seo @param array<string,mixed> $input @return array<string,mixed> */
    public function metadata(array $seo, array $input): array
    {
        $vars = [
            '{site_name}' => (string) $this->settings->get('site.name', 'PHP CMS'),
            '{title}' => (string) ($seo['title'] ?? ''),
            '{description}' => (string) ($seo['description'] ?? ''),
            '{keywords}' => (string) ($seo['keywords'] ?? ''),
        ];
        $meta = is_array($input['explicit_meta'] ?? null) ? $input['explicit_meta'] : [];
        $replace = [];
        foreach (['title', 'description', 'keywords'] as $key) {
            $template = $this->settings->get('seo.' . $key . '_template', '');
            $explicit = trim((string) ($meta['seo_' . $key] ?? ''));
            // Historical Core fills seo_title with the content title on save.
            // Equal titles are the documented legacy default, not a custom TDK.
            $legacyDefault = $key === 'title' && isset($input['content_title']) && $explicit === $input['content_title'];
            if (is_string($template) && $template !== '' && ($explicit === '' || $legacyDefault)) {
                $seo[$key] = trim(strtr($template, $vars));
                $replace[] = $key;
            }
        }
        $seo['_core_tdk_replace'] = $replace;
        $seo['_core_schema_enabled'] = (bool) $this->settings->get('seo.schema_enabled', false);
        return $seo;
    }

    public function form(): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="card"><h2>SEO / GEO 基础配置</h2><input type="hidden" name="seo_geo_section" value="1">'
            . '<p>模板支持 {title}、{site_name}、{description}、{keywords}；文章或分类的独立 SEO 字段优先。留空沿用原输出。旧文章 SEO 标题与文章标题相同时视为默认值，可应用标题模板。</p>';
        foreach (['title' => '标题', 'description' => '描述', 'keywords' => '关键词'] as $key => $label) {
            $html .= '<label>' . $label . '模板<input name="seo_' . $key . '_template" maxlength="' . ($key === 'description' ? 600 : 300) . '" value="' . $e($this->settings->get('seo.' . $key . '_template', '')) . '"></label>';
        }
        foreach (['schema_enabled' => '输出公开页面 Schema', 'llms_enabled' => '启用公开内容 llms.txt', 'crawler_log_enabled' => '记录蜘蛛访问聚合统计'] as $key => $label) {
            $html .= '<p><label><input type="checkbox" name="seo_' . $key . '" value="1"' . ($this->settings->get('seo.' . $key, false) ? ' checked' : '') . '> ' . $label . '</label></p>';
        }
        foreach (['ai_search_policy' => 'AI 搜索蜘蛛', 'ai_training_policy' => 'AI 训练蜘蛛'] as $key => $label) {
            $html .= '<label>' . $label . '<select name="seo_' . $key . '">';
            foreach (['inherit' => '沿用默认 robots 规则', 'allow' => '允许公开内容抓取', 'disallow' => '声明禁止抓取'] as $value => $text) {
                $html .= '<option value="' . $value . '"' . ($this->settings->get('seo.' . $key, 'inherit') === $value ? ' selected' : '') . '>' . $text . '</option>';
            }
            $html .= '</select></label>';
        }
        $html .= '<label>蜘蛛聚合记录保留天数（1–30）<input type="number" min="1" max="30" name="seo_crawler_retention_days" value="' . $e($this->settings->get('seo.crawler_retention_days', 7)) . '"></label>'
            . '<p>UA 可伪造，统计不代表官方蜘蛛或收录。最多 2,000 条聚合、512 KiB；不保存 IP、原始 UA 或查询串。空闲站点须安排日志清理任务。robots 为访问声明，不能替代鉴权；GEO 不保证 AI 推荐或收录。</p>'
            . '<p><a href="/admin/seo/keywords">SEO 关键词</a> · <a href="/admin/seo/search-engines">搜索引擎</a> · <a href="/admin/seo/crawlers">蜘蛛记录与统计</a></p></section>';
        return $html;
    }
}
