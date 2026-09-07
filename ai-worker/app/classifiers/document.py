"""Document classifier (plan.md §13.1 DOCUMENT CLASSIFIER, §14.1).

Classifier tidak pernah mempercayai output provider apa adanya. Tiga hal diperiksa sebelum
hasilnya dikembalikan:

1. jenis dokumen harus berada di dalam taksonomi plan.md §7.1;
2. confidence harus berupa angka pada rentang 0..1;
3. alternatif harus jenis yang dikenali dan tidak menduplikasi jawaban utama.

Pemeriksaan ini bukan formalitas. plan.md §37 Phase 4 mewajibkan output selalu terstruktur,
dan satu jenis dokumen karangan yang lolos akan merambat menjadi jurnal yang salah pada
phase berikutnya.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from app.providers.base import AIProviderInterface, AIProviderOutputError, ProviderUsage
from app.providers.registry import resolve_for_task
from app.taxonomy import UNKNOWN_TYPE, is_known_type

# Batas jumlah karakter yang dikirim ke provider. Klasifikasi hanya memerlukan cuplikan
# awal dokumen, dan mengirim seluruh isi berkas akan membakar token tanpa menambah akurasi
# (plan.md §13.2).
SAMPLE_TEXT_LIMIT = 6000

MAX_ALTERNATIVES = 3


@dataclass
class ClassificationOutcome:
    document_type: str
    confidence: float
    reason: str | None
    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: ProviderUsage
    raw: dict[str, Any]
    alternatives: list[dict[str, Any]] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "document_type": self.document_type,
            "confidence": self.confidence,
            "reason": self.reason,
            "alternatives": self.alternatives,
            "provider": self.provider,
            "model": self.model,
            "prompt_version": self.prompt_version,
            "latency_ms": self.latency_ms,
            "usage": self.usage.to_dict(),
            "raw": self.raw,
        }


def build_sample_text(pages: list[dict[str, Any]], limit: int = SAMPLE_TEXT_LIMIT) -> str:
    """Menyusun cuplikan teks dari halaman hasil parse.

    Baris tabel diratakan menjadi teks berpemisah pipa supaya dokumen tabular seperti
    rekening koran tetap punya kosakata yang dapat dikenali classifier.
    """

    chunks: list[str] = []
    remaining = limit

    for page in pages:
        if remaining <= 0:
            break

        label = page.get("label")
        text = page.get("text")
        rows = page.get("rows")

        if isinstance(label, str) and label.strip() != "":
            chunks.append(label.strip())

        if isinstance(text, str) and text.strip() != "":
            chunks.append(text.strip()[:remaining])
            remaining -= min(len(text), remaining)

            continue

        if isinstance(rows, list):
            for row in rows[:40]:
                if remaining <= 0:
                    break

                if not isinstance(row, list):
                    continue

                line = " | ".join(str(cell) for cell in row if cell is not None)
                chunks.append(line[:remaining])
                remaining -= min(len(line), remaining)

    return "\n".join(chunk for chunk in chunks if chunk != "")[:limit]


def classify(
    *,
    filename: str,
    mime_type: str | None,
    pages: list[dict[str, Any]],
    business_context: str | None = None,
    provider: AIProviderInterface | None = None,
) -> ClassificationOutcome:
    engine = provider or resolve_for_task("classify_document")

    result = engine.classify_document(
        {
            "filename": filename,
            "mime_type": mime_type,
            "sample_text": build_sample_text(pages),
            "business_context": business_context,
        }
    )

    data = result.data
    document_type = data.get("document_type")

    if not isinstance(document_type, str) or not is_known_type(document_type):
        raise AIProviderOutputError(
            f"Provider [{result.provider}] mengembalikan jenis dokumen di luar taksonomi: "
            f"[{document_type!r}]."
        )

    confidence = _confidence(data.get("confidence"), result.provider)

    # Jenis unknown tidak boleh membawa confidence tinggi: "saya tidak tahu" dengan
    # keyakinan 0.99 akan membuat confidence engine meloloskannya sebagai siap proses.
    if document_type == UNKNOWN_TYPE:
        confidence = min(confidence, 0.5)

    return ClassificationOutcome(
        document_type=document_type,
        confidence=confidence,
        reason=data.get("reason") if isinstance(data.get("reason"), str) else None,
        alternatives=_alternatives(data.get("alternatives"), document_type, result.provider),
        provider=result.provider,
        model=result.model,
        prompt_version=result.prompt_version,
        latency_ms=result.latency_ms,
        usage=result.usage,
        raw=data,
    )


def _confidence(value: Any, provider: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise AIProviderOutputError(
            f"Provider [{provider}] mengembalikan confidence yang bukan angka."
        )

    if not 0.0 <= float(value) <= 1.0:
        raise AIProviderOutputError(
            f"Provider [{provider}] mengembalikan confidence di luar rentang 0..1: {value}."
        )

    return round(float(value), 4)


def _alternatives(value: Any, primary: str, provider: str) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        return []

    seen = {primary}
    alternatives: list[dict[str, Any]] = []

    for candidate in value:
        if len(alternatives) >= MAX_ALTERNATIVES:
            break

        if not isinstance(candidate, dict):
            continue

        document_type = candidate.get("document_type")

        if not isinstance(document_type, str) or not is_known_type(document_type):
            continue

        if document_type in seen:
            continue

        seen.add(document_type)

        alternatives.append(
            {
                "document_type": document_type,
                "confidence": _confidence(candidate.get("confidence", 0), provider),
            }
        )

    return alternatives
