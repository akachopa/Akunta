"""Extractor terstruktur (plan.md §14.2, §37 Phase 4).

Fokus test ini bukan pada apakah aturan berhasil menemukan setiap field, melainkan pada
apakah extractor menolak hasil yang tidak konsisten. plan.md §37 Phase 4 mewajibkan output
invalid tidak masuk transaction pipeline, dan penanda `valid` adalah satu-satunya hal yang
menahannya.
"""

from decimal import Decimal
from typing import Any

import pytest

from app.extractors.bank_statement import BankStatementExtractor
from app.extractors.base import DocumentExtractor
from app.extractors.invoice import InvoiceExtractor
from app.extractors.qris import QrisSettlementExtractor
from app.extractors.receipt import ReceiptExtractor
from app.extractors.router import (
    NoExtractorAvailable,
    resolve_extractor,
    supported_document_types,
)
from app.providers.base import AIProviderInterface, ProviderResult, ProviderUsage
from app.providers.heuristic import HeuristicProvider

INVOICE_TEXT = """PT SUMBER MAKMUR
INVOICE
No. Invoice: INV-2383
Tanggal Invoice: 04/09/2026
Tanggal Jatuh Tempo: 04/10/2026
Dari: PT Sumber Makmur
Kepada: Toko Berkah Jaya
Subtotal: Rp 5.000.000
PPN: Rp 550.000
Total: Rp 5.550.000
"""

RECEIPT_TEXT = """TOKO BANGUNAN SEJAHTERA
Nama Toko: Toko Bangunan Sejahtera
No. Struk: STR-0091
Tanggal: 15/01/2026
Subtotal: 450.000
PPN: 49.500
Total Bayar: 499.500
Metode Pembayaran: Tunai
"""

QRIS_TEXT = """LAPORAN SETTLEMENT QRIS
Merchant ID: MID-889001
Nama Merchant: Toko Berkah Jaya
Tanggal Settlement: 15/01/2026
Total Penjualan: 2.000.000
Biaya MDR: 14.000
Net Settlement: 1.986.000
Jumlah Transaksi: 37
"""

STATEMENT_ROWS: list[list[str | None]] = [
    ["Bank", "BCA"],
    ["No. Rekening", "1234567890"],
    ["Atas Nama", "Toko Berkah Jaya"],
    ["Saldo Awal", "5.000.000"],
    ["Saldo Akhir", "1.450.000"],
    ["Tanggal", "Keterangan", "Debit", "Kredit", "Saldo"],
    ["15/01/2026", "SETORAN TUNAI", "", "2.000.000", "7.000.000"],
    ["16/01/2026", "TRSF PT SUMBER MAKMUR", "5.550.000", "", "1.450.000"],
]


def _text_pages(text: str) -> list[dict[str, Any]]:
    return [{"page_number": 1, "kind": "pdf_page", "text": text, "rows": None}]


def _row_pages(rows: list[list[str | None]]) -> list[dict[str, Any]]:
    return [{"page_number": 1, "kind": "sheet", "label": "Sheet1", "text": None, "rows": rows}]


def _extract(
    extractor: DocumentExtractor,
    document_type: str,
    pages: list[dict[str, Any]],
    **kwargs: Any,
) -> Any:
    return extractor.extract(
        pages=pages,
        document_type=document_type,
        provider=HeuristicProvider(),
        **kwargs,
    )


def _value(outcome: Any, key: str) -> str | None:
    for item in outcome.fields:
        if item.key == key:
            return item.value

    raise AssertionError(f"Field [{key}] tidak ada pada hasil ekstraksi.")


def _field(outcome: Any, key: str) -> Any:
    for item in outcome.fields:
        if item.key == key:
            return item

    raise AssertionError(f"Field [{key}] tidak ada pada hasil ekstraksi.")


class _StubProvider(AIProviderInterface):
    def __init__(self, data: dict[str, Any]) -> None:
        self.data = data
        self.schema: dict[str, Any] | None = None

    name = "stub"

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        raise NotImplementedError

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        self.schema = json_schema
        self.payload = payload

        return ProviderResult(
            data=self.data,
            provider=self.name,
            model="stub-1",
            prompt_version="1.0",
            latency_ms=1,
            usage=ProviderUsage(),
        )

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        raise NotImplementedError


def test_invoice_fields_are_extracted_with_evidence() -> None:
    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(INVOICE_TEXT))

    assert outcome.valid
    assert _value(outcome, "document_number") == "INV-2383"
    assert _value(outcome, "document_date") == "2026-09-04"
    assert _value(outcome, "due_date") == "2026-10-04"
    assert _value(outcome, "total") == "5550000.00"

    # plan.md §45.10 dan §45.12: setiap nilai harus dapat dilacak ke sumbernya.
    total = _field(outcome, "total")
    assert total.page_number == 1
    assert "5.550.000" in (total.source_text or "")


def test_invoice_money_is_a_decimal_string_never_a_float() -> None:
    """plan.md §44.14: uang tidak pernah melewati float."""
    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(INVOICE_TEXT))

    for key in ("subtotal", "tax", "total"):
        value = _value(outcome, key)

        assert isinstance(value, str)
        assert Decimal(value) == Decimal(value)


def test_subtotal_label_is_not_read_as_total() -> None:
    """Kesalahan klasik: label "total" ditemukan di dalam kata "subtotal"."""
    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(INVOICE_TEXT))

    assert _value(outcome, "subtotal") == "5000000.00"
    assert _value(outcome, "total") == "5550000.00"


def test_invoice_with_inconsistent_total_is_invalid() -> None:
    """Aritmetika yang tidak cocok menandakan angka salah baca."""
    broken = INVOICE_TEXT.replace("Total: Rp 5.550.000", "Total: Rp 5.000.000")

    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(broken))

    assert outcome.valid is False
    assert any("Total tidak konsisten" in error for error in outcome.validation_errors)


def test_invoice_without_required_field_is_invalid() -> None:
    outcome = _extract(
        InvoiceExtractor(),
        "purchase_invoice",
        _text_pages("INVOICE\nTotal: Rp 1.000.000\n"),
    )

    assert outcome.valid is False
    assert any("document_number" in error for error in outcome.validation_errors)
    assert any("document_date" in error for error in outcome.validation_errors)


def test_receipt_does_not_require_a_document_number() -> None:
    """Struk yang sah sering tidak bernomor; field wajibnya disesuaikan kenyataan itu."""
    without_number = "\n".join(
        line for line in RECEIPT_TEXT.splitlines() if "No. Struk" not in line
    )

    outcome = _extract(ReceiptExtractor(), "receipt", _text_pages(without_number))

    assert outcome.valid
    assert _value(outcome, "document_number") is None
    assert _value(outcome, "total") == "499500.00"


def test_receipt_reads_payment_method() -> None:
    outcome = _extract(ReceiptExtractor(), "receipt", _text_pages(RECEIPT_TEXT))

    assert _value(outcome, "payment_method") == "Tunai"


def test_qris_settlement_separates_gross_mdr_and_net() -> None:
    """plan.md §44.3: kas masuk bukan pendapatan. Ketiga angka harus terpisah."""
    outcome = _extract(QrisSettlementExtractor(), "qris_settlement", _text_pages(QRIS_TEXT))

    assert outcome.valid
    assert _value(outcome, "gross_amount") == "2000000.00"
    assert _value(outcome, "mdr_fee") == "14000.00"
    assert _value(outcome, "net_amount") == "1986000.00"
    assert _value(outcome, "transaction_count") == "37"


def test_merchant_id_is_not_read_as_merchant_name() -> None:
    """Label "Merchant" cocok pada "Merchant ID", tetapi field-nya berbeda."""
    outcome = _extract(QrisSettlementExtractor(), "qris_settlement", _text_pages(QRIS_TEXT))

    assert _value(outcome, "merchant_id") == "MID-889001"
    assert _value(outcome, "merchant_name") == "Toko Berkah Jaya"


def test_qris_settlement_with_wrong_net_amount_is_invalid() -> None:
    broken = QRIS_TEXT.replace("Net Settlement: 1.986.000", "Net Settlement: 2.000.000")

    outcome = _extract(QrisSettlementExtractor(), "qris_settlement", _text_pages(broken))

    assert outcome.valid is False
    assert any("Settlement tidak konsisten" in error for error in outcome.validation_errors)


def test_bank_statement_reads_header_fields_and_rows() -> None:
    outcome = _extract(BankStatementExtractor(), "bank_statement", _row_pages(STATEMENT_ROWS))

    assert outcome.valid
    assert _value(outcome, "account_number") == "1234567890"
    assert _value(outcome, "account_holder") == "Toko Berkah Jaya"
    assert _value(outcome, "opening_balance") == "5000000.00"
    assert _value(outcome, "closing_balance") == "1450000.00"
    assert len(outcome.rows) == 2
    assert outcome.rows[0]["date"] == "2026-01-15"
    assert outcome.rows[0]["credit"] == "2000000.00"
    assert outcome.rows[1]["debit"] == "5550000.00"


def test_bank_statement_row_carries_its_own_evidence() -> None:
    outcome = _extract(BankStatementExtractor(), "bank_statement", _row_pages(STATEMENT_ROWS))

    assert outcome.rows[0]["page_number"] == 1
    assert "SETORAN TUNAI" in (outcome.rows[0]["source_text"] or "")


def test_bank_statement_with_missing_row_fails_the_balance_identity() -> None:
    """Pemeriksaan paling bernilai pada Phase 4 (plan.md §19.1).

    Satu baris yang terlewat membuat saldo tidak pernah dapat direkonsiliasi, jadi
    dokumennya harus ditandai tidak valid alih-alih diteruskan.
    """
    incomplete = [row for row in STATEMENT_ROWS if row[1] != "SETORAN TUNAI"]

    outcome = _extract(BankStatementExtractor(), "bank_statement", _row_pages(incomplete))

    assert outcome.valid is False
    assert any("Saldo tidak konsisten" in error for error in outcome.validation_errors)


def test_bank_statement_without_rows_is_invalid() -> None:
    header_only = [row for row in STATEMENT_ROWS if not row[0].startswith(("15/", "16/"))]

    outcome = _extract(BankStatementExtractor(), "bank_statement", _row_pages(header_only))

    assert outcome.valid is False
    assert any("baris mutasi" in error for error in outcome.validation_errors)


def test_confidence_drops_when_required_fields_are_missing() -> None:
    """plan.md §15.1 `extraction_confidence` harus mencerminkan kelengkapan."""
    complete = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(INVOICE_TEXT))
    partial = _extract(
        InvoiceExtractor(),
        "purchase_invoice",
        _text_pages("INVOICE\nNo. Invoice: INV-1\nTotal: 1.000.000\n"),
    )

    assert complete.confidence > partial.confidence
    assert partial.confidence < 0.8


def test_extraction_records_extractor_and_schema_version() -> None:
    """plan.md §45.9: versi schema tersimpan bersama hasilnya."""
    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages(INVOICE_TEXT))

    assert outcome.extractor == "invoice"
    assert outcome.schema_version == "1.0"
    assert outcome.provider == "heuristic"


def test_raw_provider_output_is_preserved_even_when_invalid() -> None:
    """plan.md §37 Phase 4: raw evidence tetap tersedia walau hasilnya ditolak."""
    outcome = _extract(InvoiceExtractor(), "purchase_invoice", _text_pages("Tidak ada apa pun"))

    assert outcome.valid is False
    assert "fields" in outcome.raw


def test_schema_requires_evidence_for_every_field() -> None:
    """Schema memaksa provider menyertakan asal setiap nilai (plan.md §45.10)."""
    schema = InvoiceExtractor().json_schema()
    field_schema = schema["properties"]["fields"]["properties"]["total"]

    assert set(field_schema["required"]) == {"value", "confidence", "page_number", "source_text"}
    assert schema["properties"]["fields"]["additionalProperties"] is False


def test_schema_is_handed_to_the_provider() -> None:
    """plan.md §45.8: output provider terikat JSON schema, bukan teks bebas."""
    provider = _StubProvider({"fields": {}})

    InvoiceExtractor().extract(
        pages=_text_pages(INVOICE_TEXT),
        document_type="purchase_invoice",
        provider=provider,
    )

    assert provider.schema == InvoiceExtractor().json_schema()
    assert provider.payload["document_type"] == "purchase_invoice"


def test_money_that_is_not_decimal_is_rejected_rather_than_coerced() -> None:
    """Nilai yang tidak dapat dibaca sebagai desimal tidak boleh menjadi nol diam-diam."""
    provider = _StubProvider(
        {
            "fields": {
                "document_number": {
                    "value": "INV-1",
                    "confidence": 0.9,
                    "page_number": 1,
                    "source_text": "INV-1",
                },
                "document_date": {
                    "value": "2026-09-04",
                    "confidence": 0.9,
                    "page_number": 1,
                    "source_text": "x",
                },
                "issuer_name": {
                    "value": "PT A",
                    "confidence": 0.9,
                    "page_number": 1,
                    "source_text": "x",
                },
                "total": {
                    "value": "lima juta",
                    "confidence": 0.99,
                    "page_number": 1,
                    "source_text": "x",
                },
            }
        }
    )

    outcome = InvoiceExtractor().extract(
        pages=[],
        document_type="purchase_invoice",
        provider=provider,
    )

    assert outcome.valid is False
    assert _value(outcome, "total") is None
    assert any("bukan desimal" in error for error in outcome.validation_errors)


def test_field_outside_the_schema_is_ignored() -> None:
    """Provider tidak dapat menambah field di luar kontrak."""
    provider = _StubProvider({"fields": {"nomor_rekening_pribadi": {"value": "123"}}})

    outcome = InvoiceExtractor().extract(
        pages=[],
        document_type="purchase_invoice",
        provider=provider,
    )

    assert [item.key for item in outcome.fields] == InvoiceExtractor().field_keys()


@pytest.mark.parametrize(
    ("document_type", "extractor"),
    [
        ("purchase_invoice", "invoice"),
        ("sales_invoice", "invoice"),
        ("utility_bill", "invoice"),
        ("receipt", "receipt"),
        ("cash_receipt", "receipt"),
        ("bank_statement", "bank_statement"),
        ("qris_settlement", "qris"),
        ("ewallet_settlement", "qris"),
    ],
)
def test_router_maps_document_types_to_extractors(document_type: str, extractor: str) -> None:
    assert resolve_extractor(document_type).name == extractor


@pytest.mark.parametrize("document_type", ["payroll", "loan_agreement", "unknown", "other"])
def test_document_types_without_extractor_are_reported_explicitly(document_type: str) -> None:
    """Memaksakan schema faktur pada slip gaji lebih berbahaya daripada menunggu manusia."""
    with pytest.raises(NoExtractorAvailable):
        resolve_extractor(document_type)

    assert document_type not in supported_document_types()
