# Shop Module Contract and Development Plan

Date: November 9, 2026. Status: design before implementation.

The module adds a product catalog, cart, checkout, orders, payments, and purchase
history to Stream Engine. This contract defines architectural boundaries,
invariants, and the development sequence. No code or migrations are introduced
at this stage.

## 1. Agreed Decisions and Scope

- A product is a `Feed` of type `product`. Feed is the primary product entity
  and identity.
- Product variants are separate Shop records linked to a Feed; they are not
  child Feeds.
- Even a simple product has one default variant. Cart items reference variants.
- Product photographs, documentation, and other files reside in object storage.
- The store is implemented as the `Shop` module under the existing module contract.
- Orders retain purchase snapshots independently of subsequent catalog changes.

The working scope for the first version is one store per site, one currency,
one warehouse, integer quantities, one payment adapter, and one shipping method.
These are design constraints for the first version; specific providers have
not been selected.

The first version excludes a marketplace, subscriptions, multiple warehouses
and currencies, complex promotions, reviews, and loyalty programs. Digital
delivery depends on the product type decision in section 12.

## 2. Architecture and Integration

Dependency direction: `Shop → Core / Service / Repository / Domain`.
The platform and other modules must not import Shop classes. Shop must not
import other modules, including Users, Profile, Search, and Sitemap.

The Shop controller declares its Feed type, public page actions, runtime routes,
API, cron tasks, and administration dashboard cards through existing extension
points. Store repositories, services, and models remain inside the module.
Shared contracts move into the platform only when multiple consumers actually
need them.

The module uses existing Feed, term, user, authorization, mail, and object
storage services. Platform extensions must be generic. The shared `Feed` does
not need price, SKU, stock, or order fields. Shop view models combine Feed data
with store data for presentation.

Migrations belong to the shared `migrations/` history rather than the module
directory. Upgrading an existing installation and installing from the schema
snapshot must produce the same schema.

Current engine limitations apply: administration pages require integration
with the frontend entry, and translations use the shared language catalogs.
Automatic discovery of these capabilities must not be assumed to exist.

## 3. Feed Product Catalog

### 3.1. Data Ownership

| Data | Source of truth |
| --- | --- |
| Title, slug, description, content, owner, visibility | Feed of type `product` |
| Categories and brands | Terms and Feed-to-term associations |
| Descriptive attributes | Terms for enumerations; typed Shop records for numeric values and units |
| SKU, option combination, price, availability | Shop variant |
| Stock and reservations | Shop inventory records |
| Files and their roles | Object metadata and product or variant associations |

`feed_metadata` may hold optional editorial settings. Price, currency, stock,
reservations, and order states use typed fields. Product titles and descriptions
are not duplicated in current Shop catalog tables.

Feed visibility controls access to content. Commercial availability separately
controls whether a product can be purchased. A publicly visible product may be
temporarily unavailable for purchase. Taking a product off sale must not require
deleting its Feed.

### 3.2. Variants and Attributes

A variant has its own ID, `product_feed_id`, SKU, a set of option values,
price, currency, active flag, and inventory tracking mode.

- SKU is unique within the store; case sensitivity and normalization rules
  must be settled before the migration.
- Duplicate option combinations within a product are prohibited.
- A simple product has exactly one active default variant with no options.
- A product with variants requires a specific combination; invalid combinations
  do not automatically create new variants.
- A variant belongs to exactly one Feed of type `product`.
- Deactivating a variant preserves order history.

Purchase options, such as color and size, determine the variant. Descriptive
attributes, such as material and power rating, do not create variants on their
own. Categories use a dedicated store vocabulary; their hierarchy is separate
from variant structure.

## 4. Files in Object Storage

The database stores the object key, storage profile, MIME type, size, original
filename, checksum, and upload state; images also have width and height.
A product association records the role, caption, `alt` text, position, and
optional variant ID. A variant in such an association must belong to the same
product.

File roles include cover image, gallery image, manual, certificate, and other
attachment. Digital products introduce a separate role for files delivered
after purchase.

- The stable identifier is the object key rather than the complete public URL.
- Public images and documents are served through object storage or a CDN.
- Private files must be inaccessible through the public endpoint; authorization
  precedes issuing a temporary URL. An unguessable key is not access control.
- The server generates object names; original filenames remain metadata.
- Actual MIME type, allowed format, and size are validated. Images use the
  existing processing pipeline where applicable.
- Previews and other derivatives are separate objects linked to the original.
- Replacing a file creates a new key. The old object is deleted after checking
  its references.

Uploads transition from `pending` to `ready` or `failed`. A customer-visible
association is created only for a ready object. The database and object storage
have no shared transaction: repeated operations must be safe, and cron removes
temporary objects and unused derivatives after a delay.

The current `S3ObjectStorage` supports uploads, HEAD checks, deletion, and public
URLs. Private delivery requires extending the shared storage contract. Permanent
local copies of product files are not planned; temporary processing files are
allowed.

## 5. Money, Cart, and Checkout

Monetary amounts are stored as integers in the currency's minor units.
`float` is prohibited for storage and calculations. The currency is also
recorded on the order. Tax and total rounding rules must be defined before
checkout implementation.

A cart item contains a variant ID and a positive integer quantity. Displayed
prices may be cached but do not authorize charging a customer. Adding an item
to the cart does not reserve stock.

At checkout, the server revalidates Feed and variant availability, prices,
stock, selected shipping and payment methods, address, and total. Client totals
are untrusted. A price change requires the customer's confirmation again.

Creating the order, its items, reservations, and records for subsequent actions
happens in one database transaction. Repeating checkout with the same idempotency
key returns the same result; a different request payload with the same key is
rejected. The key is scoped to the cart owner and operation.

Payment provider requests and email delivery happen after commit. An external
service failure must not lose an existing order. Durable task/outbox records
and cron provide retries for external actions.

## 6. Orders and Purchase History

The conceptual model includes:

| Entity | Purpose |
| --- | --- |
| Order | Number, customer, contacts, address, currency, amounts, three state groups |
| Order item | Snapshot of title, SKU, options, quantity, price, and charges |
| Payment attempt | Provider, external ID, amount, currency, result |
| Refund | Amount, reason, external ID, execution state |
| Fulfillment | Shipping method, cost snapshot, tracking number, state |
| Order event | Timestamp, actor, reason, previous and new state |
| Comment | Author, text, visibility to the customer or staff only |

Order amounts include items, discounts, taxes, and shipping under an explicitly
defined formula; shipping cost is fixed when the order is confirmed.
Feed and variant IDs remain navigation references, but snapshots contain all
data needed for purchase history. Removing a reference does not remove its
snapshot.

A confirmed order is not edited like a cart. Changes are explicit operations
with audit records and financial consequences. The order number is convenient
for users, but knowing it does not grant access to the order.

“My purchases” is a view of orders and their items. Digital delivery, licenses,
or subscriptions require a separate entitlement registry.

## 7. States and Transitions

| Area | Initial state model |
| --- | --- |
| Order | `created`, `confirmed`, `completed`, `cancelled` |
| Payment | `unpaid`, `pending`, `paid`, `partially_refunded`, `refunded` |
| Fulfillment | `unfulfilled`, `processing`, `shipped`, `delivered`, `cancelled` |

A failed payment attempt is recorded separately and does not prevent another
attempt. Order states do not replace payment or fulfillment states.

- Confirmation means the store has accepted the order; automatic confirmation
  conditions depend on the chosen payment method.
- `completed` requires completed fulfillment and satisfied payment conditions.
- Cancellation does not mean money has already been refunded. Refunds have
  their own execution state.
- After shipping, the return workflow applies; simply releasing a reservation
  is not allowed.
- All transitions are validated on the server, applied atomically, and produce
  an event.
- The complete transition matrix, including prohibited transitions and required
  permissions, must be fixed before implementing the corresponding phase.

## 8. Stock and Reservations

For tracked inventory, available quantity equals physical stock minus active
reservations. Products without inventory tracking do not create reservations.

- Checking stock and creating a reservation are atomic; concurrent purchases
  must not make available quantity negative.
- Reservations expire. Cron releases expired reservations idempotently.
- Confirmed payment retains the reservation until fulfillment; the ordinary
  payment timeout no longer releases it.
- When goods are handed over for shipping, the reservation is consumed and
  physical stock is reduced in one operation.
- Cancellation before shipping releases the reservation. A refund alone does
  not replenish stock: a goods return is recorded separately.
- Every physical stock change has a reason and an inventory movement record.

Payment after reservation expiry requires another atomic stock check.
If stock is unavailable, the payment is recorded as received, and the order
requires a staff decision followed by a refund or agreed fulfillment. It must
not be presented as successfully allocated stock.

## 9. Payments and External Actions

Payments use a provider adapter. An order may have multiple payment attempts.
The customer's return to a success page does not confirm payment.

Confirmation comes from a verified server notification or a server-side status
check. Authenticity, payment-to-order association, amount, and currency are
validated. Repeated or out-of-order notifications must not duplicate payment,
reservation, email, or refund effects or revert a confirmed result.

External event and payment IDs have uniqueness constraints within the provider.
After a network timeout during payment creation, the operation is reconciled
with the provider using a stable key; blindly creating a second payment is
prohibited.

Successful refunds must not exceed the amount received. Cancellation, refunding
money, and returning goods are separate operations with their own validation
and audit records.

## 10. Interfaces and Permissions

Public screens include the catalog, category, product details with variant
selection, gallery and documentation, cart, checkout, payment result, order
list, and order details with customer-visible history.

Administration screens include products, variants, categories, files, stock
and movements, order list, order details, and payment and shipping settings.
Search by order number and customer, state filters, and an audit log are
required.

Proposed separate permissions cover catalog, inventory, orders, refunds, and
settings management. Their integration with the current AccessService model
must be designed before the administration API. The content moderator role
alone does not grant access to order personal data or refunds.

Customers can access only their own orders and authorized files. The server
checks every request. Browser-session mutations require CSRF protection;
provider notifications require separate authenticity verification. Logs must
not contain provider secrets or payment credentials. Internal comments must
not appear in the public API.

## 11. Phased Development Plan

Phases are sequential, and each ends with a verifiable outcome. Implementation
starts only after the current design discussion is complete.

### Phase 0. Resolve Product Decisions

- Resolve section 12 questions and define amount calculation and reservation rules.
- Approve the state and permission matrices and the first version's scope.
- Define payment, shipping, and private file delivery adapter contracts.

Acceptance criteria: no open question blocks the next phase; remaining
questions are explicitly deferred to later releases.

### Phase 1. Module Foundation and Catalog Schema

- Register Shop and the `product` Feed type; define the store's public mount.
- Add variants, options, prices, and required associations; integrate terms.
- Add shared migrations, the access model, and the administration entry point.

Acceptance criteria: simple products and products with variants can be created;
SKU and combination uniqueness is enforced; existing Feeds remain functional;
upgrade and fresh installation checks pass.

### Phase 2. Files and Catalog Management

- Implement object storage uploads, associations, gallery, documentation, and previews.
- Add upload states, replacement, deletion, and temporary object cleanup.
- Implement editing of products, variants, categories, and attributes.

Acceptance criteria: products are fully manageable through administration;
upload failures do not create broken public associations; replacement does not
leave a stale cached cover image; private files, if included in the release,
are inaccessible through public storage.

### Phase 3. Storefront and Cart

- Add the catalog, categories, filters, product details, and variant selection.
- Implement the cart and the selected ownership and lifetime rules.
- Provide URLs, localization, accessibility, and theme overrides.

Acceptance criteria: customers can build a cart; hidden and inactive products
cannot be purchased; invalid option combinations are rejected; the server
recalculates amounts.

### Phase 4. Orders and Inventory

- Implement checkout, snapshots, reservations, and inventory movements.
- Add checkout idempotency, event history, and durable tasks.
- Implement cancellation, reservation expiry, and customer order lists and details.

Acceptance criteria: concurrent purchases of the last unit succeed only once;
repeated checkout does not duplicate an order; product edits and deletion do
not change history; repeated cron execution does not release a reservation
twice; another customer's order is inaccessible.

### Phase 5. Payments and Shipping

- Connect the selected adapters, server notifications, and payment reconciliation.
- Implement payment attempts, refunds, and fulfillment transitions.
- Add customer notifications with retries after failures.

Acceptance criteria: verify success, rejection, timeout, duplicate and
out-of-order notifications, amount/currency mismatches, late payment, refunds,
and repeated refunds. Provider or mail failures do not lose orders or cause
duplicate charges.

### Phase 6. Order Administration and Release

- Complete administration filters, actions, permissions, and audit history.
- Integrate search and sitemap through platform contracts if included in the
  agreed release scope; verify catalog cache invalidation.
- Prepare operational documentation covering cron, secrets, storage, payment
  reconciliation, database and object recovery, and problem orders.

Acceptance criteria: verify the complete flow from publishing a product to
shipping and returns; validate permissions and CSRF, both installation paths,
MariaDB and MySQL, and PHP and frontend checks under project rules. Disabling
Shop does not break unrelated modules.

## 12. Open Decisions

| Question | Decision deadline |
| --- | --- |
| Physical, digital, or mixed products; digital file delivery rules | Before phase 1 |
| Guest checkout, guest order access, cart merging after sign-in | Before phase 3 |
| Currency, taxes, tax-inclusive pricing, rounding | Before price schema work in phase 1 |
| Reservation lifetime, confirmation timing, unpaid and offline orders | Before phase 4 |
| Sales countries, address fields, shipping method and calculation | Before phase 4 |
| Payment provider, refunds, reconciliation, supported payment methods | Before phase 5 |
| Retention and anonymization of contacts and order history | Before phase 4 |
| Search and sitemap inclusion in the first release | Before phase 3 |

These questions do not change the agreed foundation: products are Feeds,
variants are Shop records, and files reside in object storage. Answers refine
the contract before the dependent parts are implemented.

## 13. Related Engine Contracts

- [Modules and dependency boundaries](wiki/Developer-Modules.md).
- [Database and migrations](wiki/Developer-Database-and-Migrations.md).
- [API and security](wiki/Developer-API-and-Security.md).
- [Pages and routing](wiki/Developer-Pages-and-Routing.md).

Design changes must be recorded in this contract first. Completed phases are
marked here with their actual outcomes; the existence of this document alone
does not mean the remaining work has been implemented.
