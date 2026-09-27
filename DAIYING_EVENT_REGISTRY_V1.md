# Daiying Event Registry V1

Only real dispatched events are listed.

Listener registration:

```php
$context->listen(EventClass::class, static function (object $event): void {});
```

Event dispatch is synchronous. Listener exception isolation is deferred; current dispatcher behavior lets listener exceptions escape.

| Event class | Trigger | Payload | Sync/async | Listener exception behavior | Since Core |
|---|---|---|---|---|---|
| `Cms\Core\Content\ContentPublishedEvent` | Core content publish flow dispatches after content is published | `contentId:int`, `contentType:string`, `title:string`, `slug:string`, `publicPath:string`, `publicUrl:string`, `publishedAt:string`, `trigger:string` | Sync | Escapes from dispatcher | 1.2.69 |
| `Cms\Core\Comment\CommentCreatedEvent` | `CommentRepository::create()` after a comment row is persisted | `commentId:int`, `contentId:int`, `authorUserId:?int`, `authorName:string`, `authorEmail:?string`, `status:string`, `createdAt:string` | Sync | Escapes from dispatcher | 1.2.70 |
| `Cms\Core\Auth\FrontUserRegisteredEvent` | `FrontUserAuthenticator::register()` after user row and Core session are created | `userId:int`, `email:?string`, `source:string`, `createdAt:string` | Sync | Escapes from dispatcher | 1.2.70 |
| `Cms\Core\Auth\FrontUserLoggedInEvent` | `FrontUserService::loginById()` after Core session is established | `userId:int`, `email:?string`, `provider:string`, `externalSubject:?string`, `loggedInAt:string` | Sync | Escapes from dispatcher | 1.2.70 |
| `Daiying\Commerce\Events\OrderPaidEvent` | official.commerce `pending_payment -> paid` transition after payment trust is confirmed and order is updated | `orderId:int`, `orderNumber:string`, `frontUserId:?int`, `buyerEmail:?string`, `amountMinor:int`, `currency:string`, `providerId:string`, `paymentId:?int`, `transactionId:?string`, `paidAt:string`, `idempotencyKey:string` | Sync | Escapes from dispatcher | 1.2.70 |

Not in V1 registry:

- Refund events.
- Cancellation events.
- Order-created events.
- String-only aliases such as `commerce.order.paid`.
