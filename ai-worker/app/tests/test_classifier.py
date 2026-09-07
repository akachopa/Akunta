"""Document classifier (plan.md §14.1, §37 Phase 4).

Classifier diuji dari dua sisi: apakah aturannya mengenali dokumen UMKM yang wajar, dan
apakah ia menolak output provider yang menyimpang dari kontrak. Sisi kedua lebih penting:
provider mana pun dapat mengembalikan apa saja, dan classifier adalah tempat terakhir
sebelum prediksi masuk ke Laravel.
"""

from typing import Any

import pytest

from app.classifiers.document import build_sample_text, classify
from app.providers.base import (
    AIProviderInterface,
    AIProviderOutputError,
    ProviderResult,
    ProviderUsage,
)
from app.providers.heuristic import CONFIDENCE_CEILING, HeuristicProvider


class _StubProvider(AIProviderInterface):
    """Provider yang mengembalikan payload apa pun, termasuk yang melanggar kontrak."""

    name = "stub"

    def __init__(self, data: dict[str, Any]) -> None:
        self.data = data

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        self.payload = payload

        return ProviderResult(
            data=self.data,
            provider=self.name,
            model="stub-1",
            prompt_version="1.0",
            latency_ms=1,
            usage=ProviderUsage(),
        )

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        raise NotImplementedError

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        raise NotImplementedError


def _pages(text: str) -> list[dict[str, Any]]:
    return [{"page_number": 1, "kind": "pdf_page", "label": None, "text": text, "rows": None}]


def _classify(text: str, **kwargs: Any) -> Any:
    return classify(
        filename=kwargs.pop("filename", "berkas.pdf"),
        mime_type="application/pdf",
        pages=_pages(text),
        provider=HeuristicProvider(),
        **kwargs,
    )


def test_recognises_bank_statement() -> None:
    outcome = _classify("REKENING KORAN\nNo. Rekening 1234567890\nSaldo Awal 5.000.000")

    assert outcome.document_type == "bank_statement"
    assert outcome.confidence > 0.7


def test_recognises_qris_settlement() -> None:
    outcome = _classify("Laporan Settlement QRIS\nMDR 0,7%\nNet Settlement 1.986.000")

    assert outcome.document_type == "qris_settlement"


def test_recognises_payroll_slip() -> None:
    outcome = _classify("SLIP GAJI Agustus 2026\nGaji Pokok 4.000.000\nPotongan BPJS 120.000")

    assert outcome.document_type == "payroll"


def test_recognises_utility_bill() -> None:
    outcome = _classify("Tagihan Listrik PLN\nMeter 0812371\nTotal 452.300")

    assert outcome.document_type == "utility_bill"


def test_transfer_proof_is_not_confused_with_bank_statement() -> None:
    """Keduanya memuat kosakata bank, tetapi artinya berbeda (plan.md §7.1)."""
    outcome = _classify("Bukti Transfer\nTransaksi Berhasil\nNominal Rp 5.000.000")

    assert outcome.document_type == "transfer_proof"


def test_business_context_separates_sales_from_purchase_invoice() -> None:
    """plan.md §14.1 menyertakan business_context justru untuk kasus ini."""
    document = (
        "INVOICE\nFaktur\nJatuh Tempo 2026-10-04\n"
        "Dari: Toko Berkah Jaya\nKepada: PT Sumber Makmur\nTotal 5.550.000"
    )

    as_seller = _classify(document, business_context="Toko Berkah Jaya")
    as_buyer = _classify(document, business_context="PT Sumber Makmur")

    assert as_seller.document_type == "sales_invoice"
    assert as_buyer.document_type == "purchase_invoice"


def test_unrecognised_document_is_unknown_with_low_confidence() -> None:
    """Menebak lebih berbahaya daripada mengaku tidak tahu (plan.md §2.3)."""
    outcome = _classify("Selamat ulang tahun, semoga sehat selalu.")

    assert outcome.document_type == "unknown"
    assert outcome.confidence == 0.0


def test_rule_based_confidence_never_reaches_auto_ready_threshold() -> None:
    """plan.md §32.2: false auto approval harus sangat rendah.

    Classifier kata kunci tidak layak memutuskan sendiri isi buku besar, jadi
    confidence-nya dibatasi di bawah ambang auto-ready default (0.95).
    """
    outcome = _classify(
        "REKENING KORAN\nMutasi Rekening\nNo. Rekening 1\nSaldo Awal 1\nSaldo Akhir 2\n"
        "Bank Statement\nAccount Statement\nNomor Rekening 1\nMutasi Tabungan 1"
    )

    assert outcome.confidence <= CONFIDENCE_CEILING
    assert CONFIDENCE_CEILING < 0.95


def test_alternatives_are_returned_without_duplicating_the_answer() -> None:
    """plan.md §14.1: output memuat alternatif beserta confidence-nya."""
    outcome = _classify("INVOICE\nFaktur\nNota\nStruk\nKasir\nJatuh Tempo\nTagihan")

    assert outcome.alternatives
    assert outcome.document_type not in [item["document_type"] for item in outcome.alternatives]


def test_classification_records_model_and_prompt_version() -> None:
    """plan.md §45.9: versi model dan prompt tersimpan bersama prediksi."""
    outcome = _classify("Struk pembelian di kasir")

    assert outcome.provider == "heuristic"
    assert outcome.model == "heuristic"
    assert outcome.prompt_version == "rules-1.0"


def test_raw_provider_output_is_preserved() -> None:
    """plan.md §37 Phase 4: raw evidence harus tersedia."""
    outcome = _classify("Nota penjualan tunai di kasir")

    assert outcome.raw["document_type"] == outcome.document_type
    assert "reason" in outcome.raw


def test_document_type_outside_taxonomy_is_rejected() -> None:
    """AI tidak boleh mengarang jenis dokumen di luar plan.md §7.1."""
    provider = _StubProvider({"document_type": "surat_cinta", "confidence": 0.99})

    with pytest.raises(AIProviderOutputError, match="taksonomi"):
        classify(filename="a.pdf", mime_type=None, pages=_pages("x"), provider=provider)


@pytest.mark.parametrize("confidence", [-0.1, 1.5, "tinggi", None, True])
def test_confidence_must_be_a_number_between_zero_and_one(confidence: Any) -> None:
    provider = _StubProvider({"document_type": "receipt", "confidence": confidence})

    with pytest.raises(AIProviderOutputError):
        classify(filename="a.pdf", mime_type=None, pages=_pages("x"), provider=provider)


def test_unknown_type_cannot_carry_high_confidence() -> None:
    """ "Saya tidak tahu" dengan keyakinan 0,99 akan lolos ambang auto-ready."""
    provider = _StubProvider({"document_type": "unknown", "confidence": 0.99})

    outcome = classify(filename="a.pdf", mime_type=None, pages=_pages("x"), provider=provider)

    assert outcome.confidence <= 0.5


def test_invalid_alternatives_are_dropped_instead_of_failing_the_prediction() -> None:
    """Alternatif adalah informasi tambahan; jawaban utama tetap dapat dipakai."""
    provider = _StubProvider(
        {
            "document_type": "receipt",
            "confidence": 0.9,
            "alternatives": [
                {"document_type": "tidak_ada", "confidence": 0.4},
                {"document_type": "receipt", "confidence": 0.3},
                {"document_type": "cash_receipt", "confidence": 0.2},
            ],
        }
    )

    outcome = classify(filename="a.pdf", mime_type=None, pages=_pages("x"), provider=provider)

    assert outcome.alternatives == [{"document_type": "cash_receipt", "confidence": 0.2}]


def test_sample_text_is_capped_so_classification_stays_cheap() -> None:
    """plan.md §13.2 melarang membakar token untuk task sederhana."""
    pages = [{"page_number": 1, "text": "x" * 20000, "rows": None}]

    assert len(build_sample_text(pages, limit=500)) <= 500


def test_sample_text_flattens_table_rows() -> None:
    """Rekening koran tidak punya `text`, hanya baris tabel."""
    pages = [
        {
            "page_number": 1,
            "label": "Sheet1",
            "text": None,
            "rows": [["Tanggal", "Keterangan"], ["2026-01-15", "SETORAN TUNAI"]],
        }
    ]

    sample = build_sample_text(pages)

    assert "Sheet1" in sample
    assert "SETORAN TUNAI" in sample


def test_classifier_payload_follows_the_prompt_contract() -> None:
    """plan.md §14.1 menetapkan field input classify_document."""
    provider = _StubProvider({"document_type": "receipt", "confidence": 0.8})

    classify(
        filename="struk.pdf",
        mime_type="application/pdf",
        pages=_pages("Struk"),
        business_context="Toko Berkah",
        provider=provider,
    )

    assert set(provider.payload) == {
        "filename",
        "mime_type",
        "sample_text",
        "business_context",
    }
