"""Abstraksi provider AI (plan.md §13.3).

plan.md §44.12 melarang hard-code aplikasi ke satu vendor AI. Interface di bawah adalah
satu-satunya kontrak yang boleh dipakai classifier dan extractor; adapter vendor berada di
modul terpisah dan tidak pernah dipanggil langsung.

Dua hal wajib dikembalikan setiap provider selain datanya sendiri: versi model/prompt
(plan.md §45.9) dan pemakaian token beserta biayanya (plan.md §32.1 `AI_cost_per_document`).
Tanpa keduanya prediksi tidak dapat diaudit ulang dan biayanya tidak dapat ditagihkan per
bisnis.
"""

from __future__ import annotations

import time
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any

from app.prompts import PromptContract


class AIProviderError(RuntimeError):
    """Kegagalan pada sisi provider, dibedakan dari kegagalan validasi output."""


class AIProviderOutputError(RuntimeError):
    """Provider merespons, tetapi outputnya tidak sesuai schema yang diminta.

    Dipisahkan dari AIProviderError karena penanganannya berbeda: kegagalan provider layak
    diretry, sedangkan output yang tidak sesuai schema akan berulang dan harus ditolak
    sebagai hasil (plan.md §37 Phase 4 "invalid output tidak masuk transaction pipeline").
    """


@dataclass(frozen=True)
class ProviderUsage:
    """Pemakaian sumber daya satu panggilan provider.

    Biaya berupa Decimal, bukan float, agar konsisten dengan aturan uang plan.md §44.14
    meski satuannya bukan rupiah.
    """

    input_tokens: int = 0
    output_tokens: int = 0
    cost: Decimal = Decimal("0")
    currency: str = "USD"

    def to_dict(self) -> dict[str, Any]:
        return {
            "input_tokens": self.input_tokens,
            "output_tokens": self.output_tokens,
            "cost": format(self.cost, "f"),
            "currency": self.currency,
        }


@dataclass(frozen=True)
class ProviderResult:
    data: dict[str, Any]
    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: ProviderUsage = field(default_factory=ProviderUsage)

    def to_dict(self) -> dict[str, Any]:
        return {
            "provider": self.provider,
            "model": self.model,
            "prompt_version": self.prompt_version,
            "latency_ms": self.latency_ms,
            "usage": self.usage.to_dict(),
        }


class AIProviderInterface(ABC):
    """Kontrak provider AI (plan.md §13.3)."""

    name: str

    @abstractmethod
    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        """plan.md §14.1 classify_document."""

    @abstractmethod
    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        """plan.md §14.2 extract_invoice dan turunannya."""

    @abstractmethod
    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        """plan.md §14.3 classify_economic_event."""


class StructuredProvider(AIProviderInterface):
    """Provider berbasis model bahasa dengan output terikat JSON schema.

    Subclass hanya perlu mengimplementasikan `complete()`; penyusunan prompt contract dan
    pengukuran latensi ditangani di sini supaya setiap vendor menerima kontrak yang sama
    persis (plan.md §14).
    """

    @abstractmethod
    def complete(self, contract: PromptContract) -> tuple[dict[str, Any], str, ProviderUsage]:
        """Menjalankan satu kontrak dan mengembalikan (data, model, usage)."""

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        from app.prompts import classify_document_contract

        return self._run(classify_document_contract(payload))

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        from app.prompts import extract_document_contract

        return self._run(
            extract_document_contract(payload, json_schema, schema_version, document_type)
        )

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        from app.prompts import classify_economic_event_contract

        return self._run(classify_economic_event_contract(payload))

    def _run(self, contract: PromptContract) -> ProviderResult:
        started = time.perf_counter()

        data, model, usage = self.complete(contract)

        if not isinstance(data, dict):
            raise AIProviderOutputError(
                f"Provider [{self.name}] tidak mengembalikan objek JSON untuk "
                f"kontrak [{contract.name}]."
            )

        return ProviderResult(
            data=data,
            provider=self.name,
            model=model,
            prompt_version=contract.version,
            latency_ms=int((time.perf_counter() - started) * 1000),
            usage=usage,
        )


class NullProvider(AIProviderInterface):
    """Provider yang menolak setiap inferensi.

    Dipakai ketika tidak ada provider yang dikonfigurasi. Menolak secara eksplisit lebih
    aman daripada mengembalikan prediksi palsu yang bisa masuk ke pipeline akuntansi.
    """

    name = "null"

    def classify_document(self, payload: dict[str, Any]) -> ProviderResult:
        raise AIProviderError("Tidak ada provider AI aktif untuk klasifikasi dokumen.")

    def extract_document(
        self,
        payload: dict[str, Any],
        json_schema: dict[str, Any],
        schema_version: str,
        document_type: str,
    ) -> ProviderResult:
        raise AIProviderError("Tidak ada provider AI aktif untuk ekstraksi dokumen.")

    def classify_economic_event(self, payload: dict[str, Any]) -> ProviderResult:
        raise AIProviderError("Tidak ada provider AI aktif untuk klasifikasi peristiwa ekonomi.")
