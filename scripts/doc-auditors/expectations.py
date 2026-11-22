"""Reviewed expectations, not parser escape hatches (Doc 11 §8.8)."""

# Doc 09 §19; post-2026-11-18 count approved by owner Fatharoni on
# 2026-11-22: "Yes, I approved it" (K1.3). Must equal the schema parser's tally.
GATE_H_TABLE_COUNT = 122
# Doc 09 §17/§19: queue implementation tables are not domain-owned tables.
FRAMEWORK_TABLES = {"jobs", "failed_jobs", "job_batches"}
# Doc 11 §8.1, Blueprint §9.
QUEUES = ("payments", "orders", "inventory", "notifications", "imports", "reports", "media")
# Doc 01 §9.2, Blueprint §11.
BUCKETS = ("product-media", "request-attachments", "invoice-documents", "shipment-documents", "exports", "audit-archives")
# Doc 11 §8.8 E and I; Doc 01 §3.1/§3.3.
ADR_NUMBERS = tuple(range(1, 13))
MODULE_COUNT = 22
TREE_ENTRY_COUNT = 56
# Doc 11 §8.5 and Doc 01 §3.3: these modules own no physical tables.
TABLELESS_MODULES = {"Search", "Reporting"}
# Doc 11 §8.8 S1-S3 and COVER (used by the coverage auditor).
MENU_GROUP_COUNT = 14
SUBMENU_COUNT = 87
BLUEPRINT_TABLE_COUNT = 60
# Blueprint §7 as transcribed in Doc 12 §2.1 and contracts.yml subset gate.
BLUEPRINT_PATHS = (
    "/health", "/products", "/products/{slug}", "/preorder-batches",
    "/preorder-batches/{id}", "/cart/items", "/checkout/sessions", "/orders",
    "/me/orders/{id}", "/payment-intents", "/webhooks/payments/{provider}",
    "/product-requests", "/product-requests/{id}/attachments/presign",
    "/me/quotations/{id}", "/quotations/{id}/accept",
    "/admin/preorder-batches/{id}/close", "/admin/purchase-orders", "/admin/shipments",
)
# Doc 11 §4.1 renames, §4.4 B retired pivots, §8.5 retired modules.
# "product_options" and "proof_files" explicitly stay; never blacklist them.
DEAD_TABLE_NAMES = {
    "user_addresses", "variants", "po_batches", "batch_offers", "po_lines",
    "quote_requests", "quotes", "quote_items", "stock_locations", "reservations",
    "grns", "grn_items", "parcels", "procurements", "procurement_codes",
    "role_user", "permission_role",
}
DEAD_MODULE_NAMES = {"Quotes", "Shipping", "IntlLogistics"}
# Doc 11 §1/B-3: retired order-axis label and payment-pending state.
DEAD_STATUS_NAMES = {"money_status", "pending_payment"}
# Doc 09 §20 and Doc 11 §4.3: deliberate non-tables must stay explained.
NON_TABLES = {
    "request_attachments", "order_status_history", "payables", "expenses",
    "staff_users", "personal_access_tokens", "purchase_requisitions",
}
# Doc 11 §4.4 B/C/F; staff_2fa_secrets already existed before this decision.
AUTH_TABLES = {
    "model_has_roles", "model_has_permissions", "role_has_permissions",
    "role_scopes", "user_identities", "activity_log",
}
# Doc 09 §2 users: the documented boolean CHECK, not a spelling guessed from SQL.
ADMIN_OPERATOR_CHECK = "CHECK (is_admin = false OR is_operator = true)"
# Doc 11 §4.4 A and Doc 09 §2: active flags/index and retired population column.
USER_FLAGS = ("is_operator", "is_admin")
OPERATOR_INDEX = "users_operator_status"
RETIRED_USER_COLUMN = "kind"
# Doc 10 §4 sprint bands; §4.3 explicitly reserves Sprint 0 foundation tables.
MIGRATION_BANDS = {
    "Identity": ((1, 199),), "Audit": ((1, 199),), "Platform": ((1, 199), (1400, 1599)),
    "Settings": ((1, 199),), "Content": ((1, 199), (1200, 1399)),
    "Catalog": ((200, 399),), "PreOrder": ((200, 399), (800, 999)),
    "Cart": ((400, 599),), "Orders": ((400, 799),), "Billing": ((400, 599),),
    "Payments": ((400, 599), (800, 999)), "Inventory": ((600, 799),),
    "Fulfillment": ((600, 799),), "Notification": ((600, 799),),
    "Procurement": ((800, 999),), "MarketplacePurchase": ((800, 999),),
    "Sourcing": ((1000, 1199),), "Logistics": ((1000, 1199),), "Support": ((1000, 1199),),
    "Promotion": ((1200, 1399),), "UGC": ((1200, 1399),), "Reporting": ((1400, 1599),),
    "Search": ((1400, 1599),),
}
# Doc 11 §8.8 G and the verification skill: detect these scripts, not all Unicode
# (Indonesian punctuation, mathematical arrows and tree drawing are legitimate).
SUSPICIOUS_UNICODE_NAMES = ("CJK", "IDEOGRAPH", "HIRAGANA", "KATAKANA", "HANGUL", "CYRILLIC", "FULLWIDTH", "HALFWIDTH")
# Doc 11 §8.8 G: repository-owned text only; dependencies, VCS and generated
# runtime/build output are not source. bootstrap/app.php IS source, unlike cache.
IGNORED_DIRS = {".git", "vendor", "node_modules", ".next", ".nuxt", ".output", "__pycache__"}
RUNTIME_DIRS = {"apps/api/storage", "apps/api/bootstrap/cache"}
# Local env values are not checked-in source; example env files ARE scanned.
IGNORED_FILES = {".env", ".env.dev-build.lock"}
TEXT_SUFFIXES = {".md", ".php", ".py", ".ts", ".tsx", ".vue", ".yaml", ".yml", ".json", ".sh", ".css", ".conf", ".ini", ".xml", ".toml", ".txt", ".example", ".lock"}
# ADR-010 and Doc 11 §8.5: two independent frontend npm locks, no workspace.
FRONTENDS = ("apps/admin", "apps/web")
WORKSPACE_FILES = ("package.json", "package-lock.json", "pnpm-workspace.yaml", "pnpm-workspace.yml", "pnpm-lock.yaml", "lerna.json", "yarn.lock", ".yarnrc.yml")
# Doc 01 §17 and docs/00-overview.md: numbered primary documents 00–12.
DOC_NUMBERS = tuple(f"{number:02d}" for number in range(13))
# Root convention Doc 01 §3.1; these titles locate sections even if renumbered.
DOC_FILES = {"01": "docs/01-architecture.md", "09": "docs/09-database-schema.md", "11": "docs/11-blueprint-reconciliation.md", "12": "docs/12-api-contract.md"}

# Doc 12 §6.2 is authoritative over the skill's obsolete 13-event claim.
EVENTS = tuple("UserRegistered ProductUpdated StockReserved ReservationExpired PaidStockExceptionRaised OrderPlaced OrderCancelled BatchClosed QuoteAccepted GoodsReceived PaymentCaptured RefundCompleted ShipmentDelivered NotificationDelivered DepositPaid BalanceInvoiced QuoteIssued QuoteExpired PackageShipped TrackingUpdated StockLow LandedCostAllocated ReturnDecided MediaValidated".split())
EVENT_COUNT = 24
# Doc 11 §8.3, copied from the CHECK constraints in Doc 09 §6.
STATUS_AXES = {
    "order_status": "awaiting_payment paid processing shipped delivered completed on_hold refund_requested refund_pending refunded disputed expired cancelled".split(),
    "payment_status": "unpaid partially_paid paid overdue refund_pending refunded disputed".split(),
    "fulfillment_status": "unprocessed reserved awaiting_batch at_warehouse ready_to_ship picking packed handed_over in_transit delivered returned".split(),
    "procurement_status": "not_required sourcing requisitioned po_issued supplier_confirmed in_transit customs received failed".split(),
}
# Doc 11 §8.2: ordered scenario-to-file pairs, not merely a file count.
RUNBOOKS = (
    ("payment captured but order unpaid", "payment-callback-late.md"),
    ("duplicate webhook", "webhook-replay.md"),
    ("expired reservation but late payment", "reservation-expiry-race.md"),
    ("batch over-quota", "batch-over-quota.md"), ("stuck job/DLQ", "queue-stuck.md"),
    ("supplier failure", "supplier-failure.md"), ("database failover", "database-failover.md"),
    ("MinIO unavailable", "object-storage-unreachable.md"),
    ("Cloudflare waiting room misconfiguration", "cloudflare-waiting-room.md"),
    ("refund reconciliation", "refund-reconciliation.md"),
    ("emergency disable checkout", "emergency-disable-checkout.md"),
)
# Doc 06 §2.2: exact group/submenu pairs, including deliberate English labels.
MENUS = {
    "Dashboard": "Overview, Sales KPI, Order Monitoring, Payments, Alerts",
    "Catalog": "Products, Variants/SKUs, Categories, Brands, Collections/Drops, Pricing, Media",
    "Sales": "All Orders, Ready-to-Ship Orders, Pre-Orders, Request Orders, Returns, Cancellations",
    "Pre-Order": "Campaigns/Batches, Batch Products, Participants, Closing, Consolidation, Cost Allocation",
    "Request/Sourcing": "Incoming Requests, Sourcing, Quotations, Approvals, Quote-to-Order",
    "Procurement": "Suppliers, Purchase Requisitions, Purchase Orders, Supplier Invoices, Goods Receipts",
    "Inventory": "Warehouses, Stock Overview, Reservations, Movements, Transfers, Adjustments, Stocktake",
    "International Logistics": "Forwarders, Consolidations, Cargo, Customs, Landed Costs, Milestones",
    "Fulfillment": "Picking, Packing, Shipping Labels, Shipments, Tracking, Delivery Exceptions",
    "Billing & Finance": "Invoices, DP/Installments, Additional Charges, Payments, Refunds, Receivables, Payables, Expenses, Cash/Bank",
    "Marketing & CMS": "Banners, Content Pages, Vouchers, Campaigns, SEO, Reviews",
    "CRM & Support": "Customers, Addresses, Tickets, Disputes, Notifications",
    "Reports": "Sales, Gross Margin, Batch Profitability, Stock Aging, Finance, Logistics, Customer Cohorts",
    "System": "Staff, Roles/Permissions, Settings, Webhooks, Audit Logs, Jobs, Feature Flags",
}
# Doc 06 §1 and Doc 11 B-10: canonical role bundles.
ROLES = "superadmin catalog-manager sales-ops preorder-ops sourcing-ops warehouse-staff procurement-staff finance customer-support analyst".split()
# Doc 11 §8.6: the six sourced candidate mappings.
CANDIDATES = ("DATABASE_SCHEMA.md", "OPENAPI_SPEC.yaml", "ORDER_STATE_MACHINES.md", "DEPLOYMENT_DOCKER.md", "CLOUDFLARE_RUNBOOK.md", "IMPLEMENTATION_TASKS.md")
# Doc 03 §8.2 / Doc 11 §3: ten numbered mappings each; §16 tests 1–10.
BLUEPRINT_ITEMS = tuple(range(1, 11))
# Doc 11 §4.3/§8.5: only these Blueprint table names have no physical table.
BLUEPRINT_TABLE_EXCEPTIONS = {"staff_users", "request_attachments", "order_status_history"}
# Doc 11 §4.1 (and §4.4 B): only explicit rename statements can waive old names.
RENAMES = dict(zip(
    "user_addresses variants po_batches batch_offers po_lines quote_requests quotes quote_items stock_locations reservations grns grn_items parcels procurements procurement_codes role_user permission_role Quotes Shipping IntlLogistics money_status pending_payment confirmed".split(),
    "addresses product_variants preorder_batches preorder_batch_items preorder_enrollments product_requests quotations quotation_items warehouses stock_reservations goods_receipts goods_receipt_items packages marketplace_purchases claim_codes model_has_roles model_has_permissions Sourcing Fulfillment Logistics payment_status awaiting_payment paid".split(),
))
# Doc 11 §8.4: these two old event spellings survive only in rename statements.
EVENT_RENAMES = {"ParcelShipped": "PackageShipped", "PoBatchArrived": "GoodsReceived"}
# Doc 01 §3.3 explicitly lists NotifyEvent as an Application command.
NON_EVENT_CLASSES = {"NotifyEvent"}
# Doc 11 §4.4 J and skill: distinguishing words, never generic auth/bypass.
ADR_TOPICS = {
    1: ("topologi", "modular-first"),
    2: ("invoice", "tagihan"),
    3: ("blueprint", "rujukan"),
    4: ("idempotensi", "idempotency", "outbox"),
    5: ("reservasi", "reservation", "ttl"),
    6: ("gateway", "settlement"),
    7: ("bff", "browser", "sesi", "token"),
    8: ("minio", "object storage", "object-storage"),
    9: ("waiting-room", "waiting room"),
    10: ("monorepo", "ci/cd"),
    11: ("alokasi", "allocation", "multi-warehouse"),
    12: ("rbac", "permission", "is_operator", "is_admin", "dependensi", "identitas"),
}
# Guessed lexical boundary, not a historical auditor rule: conventional event
# participles plus explicit 'event `Name`'. Mutation tests pin this limitation.
EVENT_SUFFIXES = tuple("Registered Updated Reserved Expired Raised Placed Cancelled Closed Accepted Received Captured Completed Delivered Paid Invoiced Issued Shipped Low Allocated Decided Validated Created Deleted Failed Hacked Event".split())
# Doc 06 §1 menu descriptions and runbooks/oversell.md §8 English test quote:
# these exact phrases are labels/common nouns, not SQL table declarations.
ENGLISH_OBJECT_PHRASES = (
    "Catalog (products, variants/SKU, categories, brands, collections/drops, pricing, media)",
    "Inventory (warehouses, stock, reservations, movements, transfers, adjustments, stocktake)",
    "successful reservations",
)
# Known contrary positive claims from Doc 11 B-1/B-2/B-3/B-8, §4.3/§4.4 C/E/F.
CONTRADICTIONS = {
    "SINGLE_INERTIA": r"(?:storefront dan admin memakai satu app Inertia|satu app Inertia untuk storefront dan admin)",
    "TWO_STATUS_AXES": r"(?:hanya|cukup) dua sumbu status",
    "WAITING_ROOM_STOCK": r"Waiting Room menjamin (?:fair.stock|stok|tidak oversell)",
    "BROWSER_BEARER": r"(?:browser|storefront) (?:wajib )?memakai bearer token",
    "SCRAMBLE_SOURCE": r"Scramble (?:adalah|menjadi) sumber kebenaran",
    "ACTIVITY_AUDIT": r"activity_log (?:menggantikan|adalah pengganti) audit_logs",
    "STAFF_TABLE": r"staff_users (?:wajib dibuat|adalah tabel terpisah)",
}
# Doc 09 §19: fallback verifies the entire documented inventory, not a guessed
# selection of 60 Blueprint names. No claim of having read Blueprint §6.
SCHEMA_TABLES = set("""
users addresses customer_profiles user_consents otp_challenges user_sessions roles permissions model_has_roles model_has_permissions role_has_permissions role_scopes user_identities staff_2fa_secrets
categories brands collections products product_collections product_options product_option_values product_variants variant_option_values product_media price_lists price_list_entries
media pages content_blocks content_block_versions nav_items localizations
preorder_batches preorder_batch_items preorder_enrollments preorder_allocations preorder_payment_plans
invoices invoice_lines invoice_allocations receivables
orders order_items order_charges order_adjustments order_addresses order_events returns return_items
warehouses stock_items stock_movements stock_reservations stock_transfers stocktakes
carts cart_items checkout_sessions wishlist_items
payment_intents payment_transactions payments payment_ledger refunds webhook_receipts reconciliation_runs reconciliation_items
courier_services shipping_tariff_rules shipping_rates_cache packages shipments shipment_order_items tracking_lanes lane_stages tracking_events shipment_stage_events
forwarders consolidations cargo_shipments cargo_items customs_charges landed_cost_allocations
vouchers voucher_redemptions loyalty_entries loyalty_tiers drops drop_items drop_claims
product_requests sourcing_options quotations quotation_versions quotation_items quotation_visibility_grants
suppliers purchase_orders purchase_order_items supplier_invoices goods_receipts goods_receipt_items
marketplace_purchases claim_codes proof_files reviews review_media review_moderation
feature_flags feature_flag_overrides settings notification_templates user_preferences notification_logs back_in_stock_subscriptions
tickets ticket_messages audit_logs activity_log outbox_events inbox_events idempotency_keys
""".split())
