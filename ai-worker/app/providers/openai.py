"""Adapter OpenAI (plan.md §13.3).

Adapter ini memakai structured output berbasis JSON schema, bukan parsing teks bebas,
karena plan.md §45.8 mewajibkan output terstruktur dan §37 Phase 4 menuntut output yang
selalu berupa JSON valid. Schema dikirim dengan `strict` sehingga model tidak dapat
menambah field di luar kontrak.

Tidak ada logika domain di sini: adapter hanya menerjemahkan PromptContract menjadi request
HTTP dan mengembalikan datanya. Aturan bisnis apa pun yang ditulis di adapter akan
mengunci aplikasi ke satu vendor, yang dilarang plan.md §44.12.
"""

from __future__ import annotations

import json
from decimal import Decimal
from typing import Any

import httpx

from app.config import get_settings
from app.prompts import PromptContract
from app.providers.base import (
    AIProviderError,
    AIProviderOutputError,
    ProviderUsage,
    StructuredProvider,
)

# Harga per satu juta token. Dipakai hanya untuk mencatat estimasi biaya
# (plan.md §32.1 AI_cost_per_document); angka aslinya dapat ditimpa dari environment.
DEFAULT_INPUT_COST_PER_MTOK = Decimal("0.15")
DEFAULT_OUTPUT_COST_PER_MTOK = Decimal("0.60")


class OpenAIProvider(StructuredProvider):
    name = "openai"

    def __init__(self) -> None:
        settings = get_settings()

        self._api_key = settings.openai_api_key
        self._base_url = settings.openai_base_url.rstrip("/")
        self._model = settings.openai_model
        self._timeout = settings.provider_timeout
        self._input_cost = Decimal(str(settings.openai_input_cost_per_mtok))
        self._output_cost = Decimal(str(settings.openai_output_cost_per_mtok))

    def complete(self, contract: PromptContract) -> tuple[dict[str, Any], str, ProviderUsage]:
        if not self._api_key:
            raise AIProviderError(
                "AI_WORKER_OPENAI_API_KEY belum diset, sehingga provider openai tidak dapat "
                "dipakai."
            )

        request = {
            "model": self._model,
            "messages": [
                {"role": "system", "content": contract.system},
                {
                    "role": "user",
                    "content": json.dumps(contract.payload, ensure_ascii=False, default=str),
                },
            ],
            "max_tokens": contract.max_output_tokens,
            # Suhu nol: prediksi akuntansi harus sedapat mungkin dapat direproduksi.
            "temperature": 0,
            "response_format": {
                "type": "json_schema",
                "json_schema": {
                    "name": contract.name,
                    "schema": contract.json_schema,
                    "strict": True,
                },
            },
        }

        try:
            response = httpx.post(
                f"{self._base_url}/chat/completions",
                headers={"Authorization": f"Bearer {self._api_key}"},
                json=request,
                timeout=self._timeout,
            )
        except httpx.HTTPError as exception:
            raise AIProviderError(f"Provider openai tidak dapat dihubungi: {exception}") from (
                exception
            )

        if response.status_code >= 400:
            raise AIProviderError(
                f"Provider openai merespons HTTP {response.status_code} untuk kontrak "
                f"[{contract.name}]."
            )

        body = response.json()
        choices = body.get("choices") or []

        if not choices:
            raise AIProviderOutputError("Provider openai tidak mengembalikan pilihan jawaban.")

        content = (choices[0].get("message") or {}).get("content")

        if not isinstance(content, str) or content.strip() == "":
            raise AIProviderOutputError("Provider openai mengembalikan jawaban kosong.")

        try:
            data = json.loads(content)
        except json.JSONDecodeError as exception:
            raise AIProviderOutputError(
                f"Jawaban provider openai bukan JSON valid: {exception}"
            ) from exception

        return data, str(body.get("model") or self._model), self._usage(body.get("usage") or {})

    def _usage(self, usage: dict[str, Any]) -> ProviderUsage:
        input_tokens = int(usage.get("prompt_tokens") or 0)
        output_tokens = int(usage.get("completion_tokens") or 0)

        million = Decimal("1000000")
        cost = (Decimal(input_tokens) / million * self._input_cost) + (
            Decimal(output_tokens) / million * self._output_cost
        )

        return ProviderUsage(
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            cost=cost.quantize(Decimal("0.000001")),
            currency="USD",
        )
