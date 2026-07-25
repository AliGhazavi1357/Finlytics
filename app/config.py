from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


BASE_DIR = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=str(BASE_DIR / ".env"), extra="ignore")

    app_name: str = "Finlytics"
    secret_key: str = "finlytics-demo-secret-key-change-in-production"
    algorithm: str = "HS256"
    access_token_expire_minutes: int = 60 * 24 * 7
    database_url: str = f"sqlite:///{BASE_DIR / 'data' / 'finlytics.db'}"
    openai_api_key: str = ""
    openai_base_url: str = "https://api.openai.com/v1"
    openai_model: str = "gpt-4o-mini"
    debug: bool = True
    voice_dir: Path = BASE_DIR / "data" / "voice"
    upload_dir: Path = BASE_DIR / "data" / "uploads"
    # Business limits (ریال)
    max_monthly_salary: float = 200_000_000
    min_monthly_salary: float = 5_000_000
    max_transaction_amount: float = 5_000_000_000
    ai_daily_question_limit: int = 5


settings = Settings()
settings.voice_dir.mkdir(parents=True, exist_ok=True)
settings.upload_dir.mkdir(parents=True, exist_ok=True)
(BASE_DIR / "data").mkdir(parents=True, exist_ok=True)
