# Daiying Collector Smart Mode Implementation Report

Date: 2026-09-03

## Result

Status: PARTIAL PASS

This iteration completes the P0 video collector closed loop. Novel auto-follow, full scheduler daemon, and the broader smart-hosting recovery layer are not changed in this commit.

## Modified Files

- `content/plugins/official.video-collector/plugin.json`
- `content/plugins/official.video-collector/plugin.php`
- `content/plugins/official.video-collector/src/VideoSystem.php`
- `content/plugins/official.video-collector/migrations/002_video_smart_mode.php`
- `tests/video_collector_smart_mode.php`

## Version

- Plugin ID: `official.video-collector`
- Previous version: `0.1.2`
- New version: `0.2.0`
- Data schema: `1.0.0-alpha.2`

## Migration

Added `002_video_smart_mode` without modifying the published `001` migration checksum.

Schema additions:

- `video_collector_job_items`
- Provider smart-mode columns: `slug`, `auto_sync_enabled`, `health_status`, `resource_count`, `type_summary_json`, `category_summary_json`, `detected_at`
- Video source identity columns: `source_provider_id`, `source_external_id`, `source_url_hash`, `normalized_title`, `category_name`
- Job progress columns: totals, processed counts, success/failed/skipped counts, cursor, batch size, lifecycle timestamps
- Indexes for provider API lookup, video source lookup, normalized title lookup, and job item status scans

## Routes

Admin:

- `GET /admin/video-collector`
- `POST /admin/video-collector/provider/save`
- `POST /admin/video-collector/provider/toggle`
- `POST /admin/video-collector/provider/delete`
- `POST /admin/video-collector/job/create`
- `POST /admin/video-collector/job/run`
- `POST /admin/video-collector/job/action`
- `GET /admin/video-collector/provider/preview`
- `GET /admin/video-collector/source/health`

Front:

- `GET /videos`
- `GET /videos/detail?id=...`
- `GET /videos/watch?episode_id=...`

## Queue Implementation

Implemented database-backed import jobs and job items. Jobs can be created, run in chunks, paused, resumed, cancelled, and completed without relying on browser state.

## DB Write-Through

Imported provider payloads now write through to:

- `videos`
- `video_seasons`
- `video_episodes`
- `video_play_sources`
- `video_episode_play_urls`
- `video_category_mappings`
- `video_collector_jobs`
- `video_collector_job_items`

## Idempotency

Video identity now prefers `resource_provider_id + source_external_id`, with normalized title/year fallback. Episode identity uses video, season, and episode number. Play URL updates are matched by episode, play source, and source episode ID, so changed URLs update existing rows rather than creating duplicate playback lines.

## Scheduler

Status: NOT COMPLETE

The plugin stores `auto_sync_enabled` on providers and supports resumable chunked jobs, but this commit does not add a real cron/daemon runner. Manual batch execution is available through the admin route.

