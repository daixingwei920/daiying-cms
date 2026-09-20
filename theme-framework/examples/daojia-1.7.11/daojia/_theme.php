<?php
declare(strict_types=1);
use Cms\Core\Theme\TemplateContext;
function dj_content_url(array $c): string {
    if (!empty($c['url'])) return (string)$c['url'];
    $content=is_array($c['content']??null)?$c['content']:[];
    if (!empty($content['url'])) return (string)$content['url'];
    $s=trim((string)($c['slug']??$content['slug']??''),'/');
    if($s==='') return '';
    return (($c['content_type']??$content['content_type']??'article')==='article'?'/articles/':'/').rawurlencode($s);
}
function dj_logo_url(TemplateContext $c): string {
    foreach(['logo_image','site_logo_url'] as $k){
        $v=$k==='logo_image'?$c->setting($k,''):$c->get($k,'');
        if(is_array($v))$v=$v['url']??'';
        $v=trim((string)$v); if($v!=='')return $v;
    } return '';
}
function dj_date($v): string {
    $r=trim((string)$v); if($r==='')return '';
    try{return (new DateTimeImmutable($r))->format('Y年n月j日');}catch(Throwable $e){return $r;}
}
function dj_items(TemplateContext $c): array {
    foreach(['articles','items','contents','results'] as $k){$v=$c->get($k,null);if(is_array($v))return $v;}
    return [];
}
function dj_excerpt(array $i): string { return trim((string)($i['excerpt']??$i['summary']??'')); }
function dj_cover(array $i): string {
    foreach(['cover','cover_url','featured_image','featured_image_url'] as $k){
        $v=$i[$k]??''; if(is_array($v))$v=$v['url']??''; $v=trim((string)$v); if($v!=='')return $v;
    } return '';
}
