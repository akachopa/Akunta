"""Prompt contract (plan.md §14).

Setiap kontrak membawa versinya sendiri karena plan.md §45.9 mewajibkan versi model dan
prompt tersimpan bersama prediksi. Mengubah teks prompt tanpa menaikkan versi akan membuat
prediksi lama tidak dapat direproduksi, jadi versi di sini adalah bagian dari kontrak.

plan.md §44.1 melarang satu prompt besar untuk seluruh pipeline: setiap kontrak menangani
satu tugas, dengan JSON schema-nya sendiri sebagai batas output (plan.md §45.8).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from app.taxonomy import DOCUMENT_TYPES, ECONOMIC_EVENT_CODES


@dataclass(frozen=True)
class PromptContract:
    """Satu tugas inferensi: instruksi, payload, dan schema output yang mengikat."""

    name: str
    version: str
    system: str
    payload: dict[str, Any]
    json_schema: dict[str, Any]
    max_output_tokens: int = 1500
    metadata: dict[str, Any] = field(default_factory=dict)


CLASSIFY_DOCUMENT_VERSION = "1.0"

_CLASSIFY_SYSTEM = (
    "Anda adalah asisten pembukuan untuk UMKM Indonesia. Tugas Anda hanya menentukan "
    "jenis dokumen keuangan dari cuplikan teksnya. Jawab hanya dengan jenis dokumen dari "
    "daftar yang diberikan; jangan membuat jenis baru. Bila bukti tidak cukup, jawab "
    "'unknown' dengan confidence rendah alih-alih menebak. Confidence adalah peluang "
    "jawaban Anda benar, bukan tingkat keyakinan retoris."
)

_CLASSIFY_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "required": ["document_type", "confidence", "reason", "alternatives"],
    "properties": {
        "document_type": {"type": "string", "enum": list(DOCUMENT_TYPES)},
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "reason": {"type": "string"},
        "alternatives": {
            "type": "array",
            "maxItems": 3,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["document_type", "confidence"],
                "properties": {
                    "document_type": {"type": "string", "enum": list(DOCUMENT_TYPES)},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                },
            },
        },
    },
}


def classify_document_contract(payload: dict[str, Any]) -> PromptContract:
    """plan.md §14.1: filename, mime_type, sample_text, business_context."""

    return PromptContract(
        name="classify_document",
        version=CLASSIFY_DOCUMENT_VERSION,
        system=_CLASSIFY_SYSTEM,
        payload={
            "filename": payload.get("filename"),
            "mime_type": payload.get("mime_type"),
            "sample_text": payload.get("sample_text"),
            "business_context": payload.get("business_context"),
            "candidate_types": list(DOCUMENT_TYPES),
        },
        json_schema=_CLASSIFY_SCHEMA,
        max_output_tokens=400,
    )


EXTRACT_DOCUMENT_VERSION = "1.0"

_EXTRACT_SYSTEM = (
    "Anda mengekstraksi field dari dokumen keuangan UMKM Indonesia. Kembalikan hanya nilai "
    "yang benar-benar tertulis pada dokumen. Bila sebuah field tidak ada, kembalikan null; "
    "jangan menghitung, menyimpulkan, atau melengkapi sendiri. Nilai uang ditulis sebagai "
    "string desimal dengan titik sebagai pemisah desimal dan tanpa pemisah ribuan. Tanggal "
    "memakai format YYYY-MM-DD. Untuk setiap field sertakan nomor halaman dan cuplikan teks "
    "sumbernya supaya nilainya dapat diperiksa kembali."
)


def extract_document_contract(
    payload: dict[str, Any],
    json_schema: dict[str, Any],
    schema_version: str,
    document_type: str,
) -> PromptContract:
    """plan.md §14.2: schema output ditentukan per jenis dokumen oleh extractor."""

    return PromptContract(
        name="extract_document",
        version=EXTRACT_DOCUMENT_VERSION,
        system=_EXTRACT_SYSTEM,
        payload=payload,
        json_schema=json_schema,
        metadata={"document_type": document_type, "schema_version": schema_version},
    )


CLASSIFY_ECONOMIC_EVENT_VERSION = "1.0"

_EVENT_SYSTEM = (
    "Anda mengklasifikasikan satu transaksi keuangan UMKM Indonesia ke dalam taksonomi "
    "peristiwa ekonomi yang diberikan. Jawab hanya dengan kode dari daftar; jangan membuat "
    "kode baru. Bila bukti tidak cukup, pilih OTHER_OPERATING_INCOME atau "
    "OTHER_OPERATING_EXPENSE sesuai arah uang, dengan confidence rendah, dan sebutkan "
    "informasi yang kurang. Jangan menulis baris jurnal. Transfer internal, setoran modal, "
    "dan pokok pinjaman bukan pendapatan maupun beban."
)

_EVENT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "required": ["event_code", "confidence", "reason", "alternatives", "missing_information"],
    "properties": {
        "event_code": {"type": "string", "enum": list(ECONOMIC_EVENT_CODES)},
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "reason": {"type": "string"},
        "alternatives": {
            "type": "array",
            "maxItems": 3,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["event_code", "confidence"],
                "properties": {
                    "event_code": {"type": "string", "enum": list(ECONOMIC_EVENT_CODES)},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                },
            },
        },
        "missing_information": {
            "type": "array",
            "items": {"type": "string"},
        },
    },
}


def classify_economic_event_contract(payload: dict[str, Any]) -> PromptContract:
    """plan.md §14.3: deskripsi, nominal, arah, jenis dokumen, pihak lawan."""

    return PromptContract(
        name="classify_economic_event",
        version=CLASSIFY_ECONOMIC_EVENT_VERSION,
        system=_EVENT_SYSTEM,
        payload={
            "description": payload.get("description"),
            "amount": payload.get("amount"),
            "direction": payload.get("direction"),
            "document_type": payload.get("document_type"),
            "counterparty": payload.get("counterparty"),
            "business_context": payload.get("business_context"),
            "candidate_events": list(ECONOMIC_EVENT_CODES),
        },
        json_schema=_EVENT_SCHEMA,
        max_output_tokens=400,
    )
