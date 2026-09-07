"""Klasifikasi peristiwa ekonomi (plan.md §13.1 EVENT CLASSIFIER, §14.3).

Output provider ditolak bila kodenya di luar taksonomi plan.md §10. Laravel juga
memeriksa ulang, tetapi menolak di sini mencegah payload karangan pernah meninggalkan
worker.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from app.providers.base import AIProviderInterface, AIProviderOutputError, ProviderUsage
from app.providers.registry import resolve_for_task
from app.taxonomy import is_known_event

MAX_ALTERNATIVES = 3


@dataclass
class EventClassificationOutcome:
    event_code: str
    confidence: float
    reason: str | None
    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: ProviderUsage
    raw: dict[str, Any]
    alternatives: list[dict[str, Any]] = field(default_factory=list)
    missing_information: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "event_code": self.event_code,
            "confidence": self.confidence,
            "reason": self.reason,
            "alternatives": self.alternatives,
            "missing_information": self.missing_information,
            "provider": self.provider,
            "model": self.model,
            "prompt_version": self.prompt_version,
            "latency_ms": self.latency_ms,
            "usage": self.usage.to_dict(),
            "raw": self.raw,
        }


def classify_event(
    *,
    description: str | None,
    amount: str | None,
    direction: str | None,
    document_type: str | None = None,
    counterparty: str | None = None,
    business_context: str | None = None,
    provider: AIProviderInterface | None = None,
) -> EventClassificationOutcome:
    engine = provider or resolve_for_task("classify_economic_event")

    result = engine.classify_economic_event(
        {
            "description": description,
            "amount": amount,
            "direction": direction,
            "document_type": document_type,
            "counterparty": counterparty,
            "business_context": business_context,
        }
    )

    data = result.data
    event_code = data.get("event_code")

    if not isinstance(event_code, str) or not is_known_event(event_code):
        raise AIProviderOutputError(
            f"Provider [{result.provider}] mengembalikan event_code di luar taksonomi: "
            f"[{event_code!r}]."
        )

    confidence = _confidence(data.get("confidence"), result.provider)
    missing = data.get("missing_information")
    missing_information = (
        [item for item in missing if isinstance(item, str)] if isinstance(missing, list) else []
    )

    return EventClassificationOutcome(
        event_code=event_code,
        confidence=confidence,
        reason=data.get("reason") if isinstance(data.get("reason"), str) else None,
        alternatives=_alternatives(data.get("alternatives"), event_code, result.provider),
        missing_information=missing_information,
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

        event_code = candidate.get("event_code")

        if not isinstance(event_code, str) or not is_known_event(event_code):
            continue

        if event_code in seen:
            continue

        seen.add(event_code)
        alternatives.append(
            {
                "event_code": event_code,
                "confidence": _confidence(candidate.get("confidence", 0), provider),
            }
        )

    return alternatives
