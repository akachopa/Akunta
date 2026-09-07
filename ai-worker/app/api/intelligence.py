"""Endpoint klasifikasi dan ekstraksi dokumen (plan.md §13.1, §14).

Pemetaan kode status HTTP di sini menentukan perilaku Laravel, jadi tiga kasusnya
dipisahkan dengan sengaja:

- **503** provider gagal atau belum dikonfigurasi. Kegagalan sementara, layak diretry oleh
  queue.
- **415** jenis dokumen belum punya extractor. Mengulang tidak akan mengubah hasilnya, jadi
  Laravel menandai tahapnya dilewati dan meminta manusia.
- **200 dengan `valid: false`** output provider tidak lolos validasi. Tetap 200 karena
  responsnya berisi bukti yang harus disimpan Laravel; menolaknya dengan 4xx akan membuang
  raw evidence yang diwajibkan plan.md §37 Phase 4.

Yang tidak pernah terjadi: output tidak valid dikembalikan sebagai sukses tanpa penanda.
"""

from __future__ import annotations

import logging

from fastapi import APIRouter, Depends, HTTPException, status

from app.api.security import require_internal_token
from app.classifiers.document import classify
from app.extractors.router import (
    NoExtractorAvailable,
    resolve_extractor,
    supported_document_types,
)
from app.providers.base import AIProviderError, AIProviderOutputError
from app.providers.registry import resolve_for_task
from app.schemas.classify import ClassifyRequest, ClassifyResponse
from app.schemas.extract import ExtractRequest, ExtractResponse
from app.taxonomy import DOCUMENT_TYPES

logger = logging.getLogger(__name__)

router = APIRouter(
    prefix="/v1",
    tags=["intelligence"],
    dependencies=[Depends(require_internal_token)],
)


@router.get("/classify/taxonomy")
def taxonomy() -> dict[str, list[str]]:
    """Taksonomi jenis dokumen dan jenis yang punya extractor.

    Laravel memverifikasi daftar ini terhadap enum-nya sendiri, sehingga taksonomi yang
    menyimpang antara kedua sisi tertangkap test alih-alih muncul sebagai klasifikasi yang
    ditolak saat produksi.
    """

    return {
        "document_types": list(DOCUMENT_TYPES),
        "extractable_document_types": supported_document_types(),
    }


@router.post("/classify", response_model=ClassifyResponse)
def classify_document(request: ClassifyRequest) -> ClassifyResponse:
    try:
        provider = resolve_for_task("classify_document", request.provider)
    except KeyError as exception:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=str(exception),
        ) from exception

    try:
        outcome = classify(
            filename=request.filename,
            mime_type=request.mime_type,
            pages=[page.model_dump() for page in request.pages],
            business_context=request.business_context,
            provider=provider,
        )
    except AIProviderOutputError as exception:
        # Output di luar taksonomi tidak dapat dipakai sama sekali, dan mengulanginya tidak
        # akan memperbaikinya. Laravel memperlakukan 422 sebagai prediksi yang ditolak.
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=str(exception),
        ) from exception
    except AIProviderError as exception:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=str(exception),
        ) from exception

    # plan.md §30: log tidak boleh memuat isi dokumen. Yang dicatat hanya keputusan dan
    # biayanya.
    logger.info(
        "document classified",
        extra={
            "document_type": outcome.document_type,
            "confidence": outcome.confidence,
            "provider": outcome.provider,
            "model": outcome.model,
        },
    )

    return ClassifyResponse.model_validate(outcome.to_dict())


@router.post("/extract", response_model=ExtractResponse)
def extract_document(request: ExtractRequest) -> ExtractResponse:
    try:
        extractor = resolve_extractor(request.document_type)
    except NoExtractorAvailable as exception:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            detail=str(exception),
        ) from exception

    try:
        provider = resolve_for_task("extract_document", request.provider)
    except KeyError as exception:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=str(exception),
        ) from exception

    try:
        outcome = extractor.extract(
            pages=[page.model_dump() for page in request.pages],
            document_type=request.document_type,
            business_context=request.business_context,
            provider=provider,
        )
    except AIProviderOutputError as exception:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=str(exception),
        ) from exception
    except AIProviderError as exception:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=str(exception),
        ) from exception

    logger.info(
        "document extracted",
        extra={
            "document_type": outcome.document_type,
            "extractor": outcome.extractor,
            "valid": outcome.valid,
            "confidence": outcome.confidence,
            "provider": outcome.provider,
        },
    )

    return ExtractResponse.model_validate(outcome.to_dict())
