"""Endpoint parsing dokumen (plan.md §13.1, §29 sisi internal).

Berkas dikirim Laravel sebagai multipart, bukan lewat path storage, supaya worker tidak
perlu kredensial object storage sama sekali (plan.md §30). Worker juga tidak menyimpan
berkas: isinya hanya berada di memori selama request.

Endpoint ini murni deterministik. Klasifikasi dan ekstraksi terstruktur adalah Phase 4
dan tidak boleh dikerjakan di sini (plan.md §37, §44.1).
"""

from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.api.security import require_internal_token
from app.parsers.base import DocumentParseError, UnsupportedDocument
from app.parsers.router import parse, supported_extensions
from app.schemas.parse import ParseResponse

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/v1", tags=["parse"], dependencies=[Depends(require_internal_token)])

# Batas ini adalah jaring pengaman worker. Batas yang dilihat user ditegakkan Laravel
# lewat config akunta.documents.max_upload_size_kb.
MAX_CONTENT_BYTES = 64 * 1024 * 1024


@router.get("/parse/capabilities")
def capabilities() -> dict[str, list[str]]:
    return {"extensions": supported_extensions()}


@router.post("/parse", response_model=ParseResponse)
async def parse_document(
    file: Annotated[UploadFile, File()],
    filename: Annotated[str | None, Form()] = None,
    mime_type: Annotated[str | None, Form()] = None,
) -> ParseResponse:
    content = await file.read()

    if len(content) > MAX_CONTENT_BYTES:
        raise HTTPException(
            status_code=status.HTTP_413_CONTENT_TOO_LARGE,
            detail="Berkas melebihi batas ukuran worker.",
        )

    effective_name = filename or file.filename or "unknown"
    effective_mime = mime_type or file.content_type

    try:
        result = parse(content, effective_name, effective_mime)
    except UnsupportedDocument as exception:
        # 415 dipisahkan dari 422 supaya Laravel dapat membedakan "tipe berkas tidak
        # didukung" (status dokumen UNSUPPORTED) dari "berkas rusak" (FAILED, dapat
        # diretry setelah user mengunggah ulang).
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            detail=str(exception),
        ) from exception
    except DocumentParseError as exception:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=str(exception),
        ) from exception

    # plan.md §30 mewajibkan redaksi log data sensitif: hanya metadata yang dicatat,
    # tidak isi dokumen maupun nama berkasnya.
    logger.info(
        "document parsed",
        extra={
            "parser": result.parser,
            "content_kind": result.content_kind,
            "page_count": len(result.pages),
            "needs_ocr": result.needs_ocr,
        },
    )

    return ParseResponse.model_validate(result.to_dict())
