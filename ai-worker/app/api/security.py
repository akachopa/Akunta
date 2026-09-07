"""Autentikasi request internal dari Laravel (plan.md §30).

Worker tidak diekspos ke publik, tetapi request internal tetap harus terautentikasi
supaya berkas finansial tidak dapat diparse oleh pihak lain yang berada di jaringan yang
sama. Health endpoint sengaja dikecualikan agar tetap dapat dipakai sebagai probe
container.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import Header, HTTPException, status

from app.config import get_settings


def require_internal_token(
    authorization: Annotated[str | None, Header()] = None,
) -> None:
    expected = get_settings().token

    # Tanpa token terkonfigurasi, worker berjalan terbuka. Itu hanya boleh terjadi pada
    # environment lokal, jadi kondisinya dibiarkan eksplisit alih-alih diam-diam lolos.
    if expected is None or expected == "":
        return

    scheme, _, credential = (authorization or "").partition(" ")

    if scheme.lower() != "bearer" or credential != expected:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token internal tidak valid.",
            headers={"WWW-Authenticate": "Bearer"},
        )
