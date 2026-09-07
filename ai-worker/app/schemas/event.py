"""Schema request dan response klasifikasi peristiwa ekonomi (plan.md §14.3, §45.8)."""

from typing import Any

from pydantic import BaseModel, Field

from app.schemas.classify import UsageResponse


class ClassifyEventRequest(BaseModel):
    description: str | None = None
    amount: str | None = None
    direction: str | None = None
    document_type: str | None = None
    counterparty: str | None = None
    business_context: str | None = None
    provider: str | None = None


class EventAlternative(BaseModel):
    event_code: str
    confidence: float


class ClassifyEventResponse(BaseModel):
    event_code: str
    confidence: float
    reason: str | None = None
    alternatives: list[EventAlternative] = Field(default_factory=list)
    missing_information: list[str] = Field(default_factory=list)

    provider: str
    model: str
    prompt_version: str
    latency_ms: int
    usage: UsageResponse
    raw: dict[str, Any] = Field(default_factory=dict)
