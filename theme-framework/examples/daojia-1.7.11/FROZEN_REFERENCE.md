# Daojia 1.7.11 Frozen Reference

This directory contains the frozen Daojia 1.7.11 official theme reference and verified market package.

## Source Package

- File: `official.theme.daojia-1.7.11.zip`
- SHA-256: `5ac7eafdafa1fe62576ead8f6b3ae8ef04f976d706b7dfa77d165c05f5e2c621`
- Market package id: `daojia:1.7.11:stable`

## Why This Replaces 1.7.10

Daojia 1.7.10 rendered article cards on `/articles` with empty links because the helper only read top-level `url`, `slug`, and `content_type`.

Daiying CMS 1.2.65 list routes pass content records as ViewModel items where the raw content record can be nested under `item.content`. The reference permalink helper therefore must support both shapes:

- `item.url`
- `item.slug`
- `item.content.url`
- `item.content.slug`
- `item.content.content_type`

## Rules

- Use this version as the current Theme Framework golden reference.
- Do not edit this reference in place.
- New themes should start from `starters/blank/`, then copy the helper behavior from this reference when needed.
- Keep Daojia 1.7.10 only as a historical reference, not as a new theme starting point.
