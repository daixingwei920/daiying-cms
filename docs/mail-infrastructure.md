# Mail Infrastructure

Daiying CMS Core provides a site-level mail foundation for outbound messages, templates, queueing, and provider registration. It is a Foundation API, not a webmail client.

Core owns the stable boundary:

```text
CMS Core -> Mail Service API -> Mail Provider / Plugin -> SMTP, PHP mail(), OAuth mail, or third-party delivery services
```

Plugins should call the public Mail Service instead of implementing their own SMTP logic or reading mail configuration tables directly.

## Scope

Core includes:

- `Cms\Core\Mail\MailService`
- `Cms\Core\Mail\MailProviderInterface`
- `Cms\Core\Mail\MailMessage`
- `Cms\Core\Mail\MailAddress`
- `Cms\Core\Mail\MailAttachment`
- `Cms\Core\Mail\MailResult`
- `Cms\Core\Mail\MailTemplateRepository`
- `Cms\Core\Mail\MailQueueRepository`
- `Cms\Core\Mail\MailEventRegistry`
- `Cms\Core\Mail\MailProviderRegistry`

Core does not include:

- Inbox or IMAP synchronization
- Sent mail, drafts, trash, contacts, or mail search
- Gmail or Outlook mailbox UI
- AI mail summaries or automatic replies

Those features belong in a future `official.mail` plugin or other mail plugins.

## Admin Settings

Administrators configure mail at:

```text
Admin -> Site Settings -> Mail Settings
```

The first Foundation settings support:

- Enable mail
- Provider selection
- SMTP host, port, encryption, username, and password/app password
- From name and from email
- Reply-To
- Timeout
- Queue preference
- Test email recipient

The SMTP password is encrypted at rest with the site secret key. When the settings page is reopened, Core exposes only whether a password is configured. Saving other fields with an empty password field preserves the existing secret.

## Providers

Core ships two baseline providers:

- `smtp`
- `php_mail`

Additional providers can be registered by plugins through `PluginContext::registerMailProvider()` when the plugin declares the `mail.provider` capability.

Provider implementations must implement:

```php
Cms\Core\Mail\MailProviderInterface
```

Required methods:

- `id()`
- `label()`
- `apiVersion()`
- `capabilities()`
- `send()`
- `testConnection()`

Future provider plugins can support Gmail OAuth, Microsoft OAuth, Amazon SES, SendGrid, Mailgun, or other delivery services without changing Core mail callers.

## Sending Mail

Plugins can access the mail service through:

```php
$mail = $context->mail();
```

Common calls:

```php
$mail->isEnabled();
$mail->send($message);
$mail->sendHtml('user@example.com', 'Subject', '<p>Hello</p>', 'Hello');
$mail->sendTemplate('order.paid', 'buyer@example.com', [
    'site_name' => 'Daiying CMS',
    'order_no' => 'NO-1001',
    'amount' => '360.00',
    'currency' => 'CNY',
]);
$mail->queue($message, 'order.paid');
$mail->testConnection('admin@example.com');
```

Mail failures return a `MailResult` or a friendly test error. They must not crash unrelated CMS pages.

## Templates

Mail templates are stored in `cms_mail_templates`.

Template fields:

- `template_id`
- `subject`
- `html_body`
- `text_body`
- `variables_json`
- `locale`
- `enabled`
- `owner`

Templates use simple variable replacement:

```text
{{site_name}}
{{user_name}}
{{order_no}}
{{amount}}
{{currency}}
```

Plugins may register or override templates for their own events, but should keep template IDs stable across versions.

## Events

Core reserves a shared mail event registry for notification flows. Foundation currently seeds:

- `user.registered`
- `user.password_reset`
- `order.created`
- `order.paid`
- `order.refunded`
- `license.issued`
- `plugin.reviewed`
- `system.error`
- `system.update`
- `security.alert`

Plugins can register additional mail events with `PluginContext::registerMailEvent()` when they declare the `mail.event` capability.

## Queue

Mail queue data is stored in `cms_mail_queue`.

Queue statuses:

- `pending`
- `sending`
- `sent`
- `failed`

The queue stores retry count, max attempts, last error, creation time, scheduled time, and sent time. `MailService::processQueue()` provides a normal PHP-host compatible execution path. A future Cron, worker, or queue provider can call the same service without changing plugin mail calls.

## Security

Core mail infrastructure follows these rules:

- Secrets are encrypted at rest.
- Admin APIs never return complete SMTP passwords or OAuth tokens.
- Mail logs and errors are redacted.
- Header injection is blocked in addresses, names, filenames, and subjects.
- Admin routes use existing authentication and CSRF protections.
- Plugins must not read `cms_core_mail_settings` directly.
- Plugins must not log SMTP passwords, app passwords, OAuth access tokens, or refresh tokens.

## Compatibility

The public Mail API starts at version `1.0`. Non-major Core releases should keep method names, argument shape, and result structure backward compatible.

Mail migrations are idempotent. Older sites with no mail configuration receive safe disabled defaults during upgrade. Foundation updates must not overwrite an existing site mail configuration or delete mail queue/history data.
