"""Taksonomi dokumen (plan.md §7.1).

Daftar ini digandakan dari enum `DocumentType` di Laravel. Duplikasi disengaja: worker
harus dapat menolak nilai di luar taksonomi tanpa memanggil Laravel, sehingga model AI
tidak pernah dapat mengarang jenis dokumen baru (prinsip plan.md §14.3 diterapkan juga
pada classifier dokumen). Ketidakcocokan antara kedua daftar tertangkap oleh test
Laravel yang memeriksa endpoint `/v1/classify/taxonomy`.
"""

from __future__ import annotations

DOCUMENT_TYPES: tuple[str, ...] = (
    "bank_statement",
    "sales_invoice",
    "purchase_invoice",
    "receipt",
    "cash_receipt",
    "cash_disbursement",
    "transfer_proof",
    "qris_settlement",
    "ewallet_settlement",
    "pos_report",
    "marketplace_report",
    "consignment_report",
    "receivable_payment_receipt",
    "loan_agreement",
    "capital_deposit",
    "purchase_order",
    "goods_receipt",
    "payroll",
    "utility_bill",
    "advertising_invoice",
    "shipping_receipt",
    "rent_receipt",
    "repair_receipt",
    "tax_document",
    "inventory_report",
    "other",
    "unknown",
)

# Urutan prioritas classifier pada plan.md §37 Phase 4. Dipakai untuk memutus seri
# ketika beberapa jenis dokumen memperoleh skor yang sama: jenis yang lebih diprioritaskan
# produk yang menang, bukan urutan alfabet yang kebetulan.
CLASSIFIER_PRIORITY: tuple[str, ...] = (
    "bank_statement",
    "purchase_invoice",
    "sales_invoice",
    "receipt",
    "transfer_proof",
    "qris_settlement",
    "payroll",
    "utility_bill",
)

UNKNOWN_TYPE = "unknown"

# Taksonomi economic event (plan.md §10). Digandakan dari enum Laravel
# `EconomicEventCode` dengan alasan yang sama: worker menolak kode karangan tanpa
# memanggil Laravel (plan.md §14.3, §37 Phase 8).
ECONOMIC_EVENT_CODES: tuple[str, ...] = (
    "SALE_CASH",
    "SALE_CREDIT",
    "SALE_CONSIGNMENT",
    "RECEIVE_RECEIVABLE",
    "SALES_RETURN",
    "OTHER_OPERATING_INCOME",
    "OTHER_NONOPERATING_INCOME",
    "PURCHASE_INVENTORY_CASH",
    "PURCHASE_INVENTORY_CREDIT",
    "PURCHASE_RAW_MATERIAL",
    "PURCHASE_PACKAGING",
    "PURCHASE_RETURN",
    "PAY_SUPPLIER",
    "SALARY_EXPENSE",
    "UTILITY_EXPENSE",
    "RENT_EXPENSE",
    "MARKETING_EXPENSE",
    "SHIPPING_EXPENSE",
    "OFFICE_SUPPLIES_EXPENSE",
    "REPAIR_MAINTENANCE_EXPENSE",
    "BANK_FEE",
    "INTEREST_EXPENSE",
    "TAX_PAYMENT",
    "OTHER_OPERATING_EXPENSE",
    "ASSET_PURCHASE",
    "ASSET_SALE",
    "DEPRECIATION",
    "PREPAID_EXPENSE",
    "OWNER_CAPITAL",
    "OWNER_WITHDRAWAL",
    "LOAN_RECEIVED",
    "LOAN_PRINCIPAL_PAYMENT",
    "LOAN_INTEREST_PAYMENT",
    "BANK_TRANSFER_INTERNAL",
    "CASH_TO_BANK_TRANSFER",
    "BANK_TO_CASH_TRANSFER",
)


def is_known_event(value: str) -> bool:
    return value in ECONOMIC_EVENT_CODES


def is_known_type(value: str) -> bool:
    return value in DOCUMENT_TYPES


def priority_of(document_type: str) -> int:
    """Posisi jenis dokumen pada daftar prioritas; jenis di luar daftar berada di belakang."""

    if document_type in CLASSIFIER_PRIORITY:
        return CLASSIFIER_PRIORITY.index(document_type)

    return len(CLASSIFIER_PRIORITY)
