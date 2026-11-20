/**
 * NAV_GROUPS = 14 grup nav admin, menyalin **verbatim** kolom "Grup Blueprint §4"
 * dan "Submenu verbatim Blueprint" dari docs/06-admin-dashboard-requirements.md §2.2
 * (checklist kelengkapan — tidak ada item yang boleh hilang).
 *
 * BATAS YANG TIDAK BOLEH DILUPAKAN: ini hanya kenyamanan UI. Otorisasi ditegakkan
 * di Laravel Policy/Gate pada setiap request (Doc 01 §9.1 + §9.3, Doc 04 §9);
 * menyembunyikan menu bukan kontrol akses, dan shell `/admin` untuk tamu harus
 * berbalas 401/403 dari server, bukan 200 + redirect klien (pola situs sumber
 * yang secara eksplisit tidak kita tiru). `roles` di bawah adalah proyeksi tabel
 * "cakupan menu" Doc 06 §1 untuk merender shell; sumber kebenaran sidebar yang
 * sebenarnya adalah permission yang dikembalikan API — bukan berkas ini.
 */

/** Sepuluh role yang di-seed (Doc 01 §9.1 / Doc 11 B-10). Bukan role ke-11: CMS ->
 *  `catalog-manager`, moderasi UGC -> `customer-support` + `sourcing-ops` (Doc 06 §1). */
export type AdminRole =
  | "superadmin"
  | "catalog-manager"
  | "sales-ops"
  | "preorder-ops"
  | "sourcing-ops"
  | "warehouse-staff"
  | "procurement-staff"
  | "finance"
  | "customer-support"
  | "analyst";

export interface NavGroup {
  /** Label grup persis seperti kolom pertama Doc 06 §2.2. */
  readonly label: string;
  /** Route group shell; layar nyatanya dibangun per sprint (Doc 06 §5), jadi sebagian
   *  masih kosong — shell ini sengaja merender navigasi, bukan layar palsu. */
  readonly href: string;
  readonly roles: readonly AdminRole[];
  /** Submenu persis seperti kolom kedua Doc 06 §2.2, bahasa sumber dipertahankan. */
  readonly items: readonly string[];
}

const ALL_ROLES: readonly AdminRole[] = [
  "superadmin", "catalog-manager", "sales-ops", "preorder-ops", "sourcing-ops",
  "warehouse-staff", "procurement-staff", "finance", "customer-support", "analyst",
];

export const NAV_GROUPS: readonly NavGroup[] = [
  {
    label: "Dashboard", href: "/", roles: ALL_ROLES,
    items: ["Overview", "Sales KPI", "Order Monitoring", "Payments", "Alerts"],
  },
  {
    label: "Catalog", href: "/catalog", roles: ["superadmin", "catalog-manager"],
    items: ["Products", "Variants/SKUs", "Categories", "Brands", "Collections/Drops", "Pricing", "Media"],
  },
  {
    label: "Sales", href: "/sales", roles: ["superadmin", "sales-ops"],
    items: ["All Orders", "Ready-to-Ship Orders", "Pre-Orders", "Request Orders", "Returns", "Cancellations"],
  },
  {
    label: "Pre-Order", href: "/pre-order", roles: ["superadmin", "preorder-ops"],
    items: ["Campaigns/Batches", "Batch Products", "Participants", "Closing", "Consolidation", "Cost Allocation"],
  },
  {
    label: "Request/Sourcing", href: "/request-sourcing", roles: ["superadmin", "sourcing-ops"],
    items: ["Incoming Requests", "Sourcing", "Quotations", "Approvals", "Quote-to-Order"],
  },
  {
    label: "Procurement", href: "/procurement", roles: ["superadmin", "procurement-staff"],
    items: ["Suppliers", "Purchase Requisitions", "Purchase Orders", "Supplier Invoices", "Goods Receipts"],
  },
  {
    label: "Inventory", href: "/inventory", roles: ["superadmin", "warehouse-staff"],
    items: ["Warehouses", "Stock Overview", "Reservations", "Movements", "Transfers", "Adjustments", "Stocktake"],
  },
  {
    label: "International Logistics", href: "/international-logistics",
    roles: ["superadmin", "procurement-staff"],
    items: ["Forwarders", "Consolidations", "Cargo", "Customs", "Landed Costs", "Milestones"],
  },
  {
    label: "Fulfillment", href: "/fulfillment", roles: ["superadmin", "warehouse-staff"],
    items: ["Picking", "Packing", "Shipping Labels", "Shipments", "Tracking", "Delivery Exceptions"],
  },
  {
    label: "Billing & Finance", href: "/billing", roles: ["superadmin", "finance"],
    items: ["Invoices", "DP/Installments", "Additional Charges", "Payments", "Refunds", "Receivables",
      "Payables", "Expenses", "Cash/Bank"],
  },
  {
    label: "Marketing & CMS", href: "/marketing-cms",
    roles: ["superadmin", "catalog-manager", "customer-support", "sourcing-ops"],
    items: ["Banners", "Content Pages", "Vouchers", "Campaigns", "SEO", "Reviews"],
  },
  {
    label: "CRM & Support", href: "/crm-support", roles: ["superadmin", "customer-support"],
    items: ["Customers", "Addresses", "Tickets", "Disputes", "Notifications"],
  },
  {
    label: "Reports", href: "/reports", roles: ["superadmin", "analyst"],
    items: ["Sales", "Gross Margin", "Batch Profitability", "Stock Aging", "Finance", "Logistics",
      "Customer Cohorts"],
  },
  {
    label: "System", href: "/system", roles: ["superadmin"],
    items: ["Staff", "Roles/Permissions", "Settings", "Webhooks", "Audit Logs", "Jobs", "Feature Flags"],
  },
];
