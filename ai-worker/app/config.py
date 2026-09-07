"""Konfigurasi AI worker.

plan.md §30 mewajibkan secret berasal dari environment/secret manager, sehingga tidak
ada nilai kredensial yang di-hardcode di sini.
"""

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict

WORKER_VERSION = "0.5.0"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_WORKER_", env_file=".env", extra="ignore")

    app_name: str = "Akunta AI Worker"
    environment: str = "local"

    # Token bersama dengan Laravel. Worker tidak diekspos ke publik, tetapi request
    # internal tetap harus terautentikasi.
    token: str | None = None

    # plan.md §13.3: provider dapat diganti tanpa mengubah domain logic. Default-nya
    # provider aturan deterministik, sehingga worker berguna tanpa kredensial vendor dan
    # tidak pernah memakai token secara tidak sengaja.
    default_provider: str = "heuristic"

    # plan.md §13.2 model routing: task yang berbeda boleh memakai provider berbeda.
    # Kosong berarti mengikuti default_provider.
    provider_classify: str | None = None
    provider_extract: str | None = None
    provider_classify_event: str | None = None

    provider_timeout: int = 60

    openai_api_key: str | None = None
    openai_base_url: str = "https://api.openai.com/v1"
    openai_model: str = "gpt-4o-mini"

    # Harga per satu juta token, dipakai hanya untuk mencatat estimasi biaya
    # (plan.md §32.1). Diletakkan di konfigurasi karena harga vendor berubah.
    openai_input_cost_per_mtok: float = 0.15
    openai_output_cost_per_mtok: float = 0.60


@lru_cache
def get_settings() -> Settings:
    return Settings()
