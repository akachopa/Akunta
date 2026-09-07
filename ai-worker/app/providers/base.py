"""Abstraksi provider AI (plan.md §13.3).

plan.md §44.12 melarang hard-code aplikasi ke satu vendor AI. Interface di bawah adalah
satu-satunya kontrak yang boleh dipakai layer domain; implementasi OpenAI, Anthropic,
Gemini, atau model lokal ditambahkan pada Phase 4 ketika classifier dan extractor
benar-benar dibangun.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from typing import Any


class AIProviderError(RuntimeError):
    """Kegagalan pada sisi provider, dibedakan dari kegagalan validasi input."""


class AIProviderInterface(ABC):
    """Kontrak provider AI.

    Seluruh method mengembalikan output terstruktur (plan.md §45.8) dan menyertakan
    versi model/prompt (plan.md §45.9), supaya prediksi dapat diaudit ulang.
    """

    name: str

    @abstractmethod
    def classify_document(self, payload: dict[str, Any]) -> dict[str, Any]:
        """plan.md §14.1 classify_document."""

    @abstractmethod
    def extract_document(self, payload: dict[str, Any]) -> dict[str, Any]:
        """plan.md §14.2 extract_invoice dan turunannya."""

    @abstractmethod
    def classify_economic_event(self, payload: dict[str, Any]) -> dict[str, Any]:
        """plan.md §14.3 classify_economic_event."""


class NullProvider(AIProviderInterface):
    """Provider default Phase 0.

    Menolak setiap permintaan inferensi secara eksplisit. Ini disengaja: plan.md §38
    melarang memulai AI pipeline sebelum ledger reliable, sehingga provider aktif baru
    dipasang pada Phase 4. Menolak dengan jelas lebih aman daripada mengembalikan
    prediksi palsu yang bisa masuk ke pipeline akuntansi.
    """

    name = "null"

    def classify_document(self, payload: dict[str, Any]) -> dict[str, Any]:
        raise AIProviderError("Belum ada provider AI aktif. Document classifier adalah Phase 4.")

    def extract_document(self, payload: dict[str, Any]) -> dict[str, Any]:
        raise AIProviderError("Belum ada provider AI aktif. Structured extraction adalah Phase 4.")

    def classify_economic_event(self, payload: dict[str, Any]) -> dict[str, Any]:
        raise AIProviderError(
            "Belum ada provider AI aktif. Economic event classifier adalah Phase 8."
        )
