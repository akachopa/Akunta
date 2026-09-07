"""Konfigurasi AI worker.

plan.md §30 mewajibkan secret berasal dari environment/secret manager, sehingga tidak
ada nilai kredensial yang di-hardcode di sini.
"""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_WORKER_", env_file=".env", extra="ignore")

    app_name: str = "Akunta AI Worker"
    environment: str = "local"

    # Token bersama dengan Laravel. Worker tidak diekspos ke publik, tetapi request
    # internal tetap harus terautentikasi.
    token: str | None = None

    # plan.md §13.3: provider dapat diganti tanpa mengubah domain logic. Nama provider
    # aktif dibaca dari environment; adapter aslinya dibangun pada Phase 4.
    default_provider: str = "null"


@lru_cache
def get_settings() -> Settings:
    return Settings()
