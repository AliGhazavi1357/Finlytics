from __future__ import annotations

from datetime import date
from pathlib import Path

import edge_tts
import httpx
from gtts import gTTS
from sqlalchemy.orm import Session

from app.config import settings
from app.models import VoiceReport
from app.services.reports import build_ceo_script


async def refine_script_with_ai(script: str) -> tuple[str, str]:
    """Return (script, mode). Uses OpenAI if key exists, else template."""
    if not settings.openai_api_key:
        return script, "template"

    prompt = (
        "متن زیر یک گزارش مالی روزانه فارسی برای مدیرعامل است. "
        "آن را روان‌تر، رسمی‌تر و مناسب خوانش صوتی حدود یک دقیقه کن. "
        "فقط متن نهایی را برگردان، بدون توضیح اضافه.\n\n"
        f"{script}"
    )
    try:
        async with httpx.AsyncClient(timeout=40.0) as client:
            resp = await client.post(
                f"{settings.openai_base_url.rstrip('/')}/chat/completions",
                headers={
                    "Authorization": f"Bearer {settings.openai_api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": settings.openai_model,
                    "messages": [
                        {
                            "role": "system",
                            "content": "تو نویسنده گزارش‌های مالی فارسی برای مدیران ارشد هستی.",
                        },
                        {"role": "user", "content": prompt},
                    ],
                    "temperature": 0.4,
                },
            )
            resp.raise_for_status()
            data = resp.json()
            text = data["choices"][0]["message"]["content"].strip()
            if text:
                return text, "openai"
    except Exception:
        pass
    return script, "template-fallback"


async def synthesize_persian_audio(text: str, out_path: Path) -> str:
    """Try Edge TTS first, then Google TTS. Returns engine name."""
    try:
        communicate = edge_tts.Communicate(text, voice="fa-IR-DilaraNeural", rate="-5%")
        await communicate.save(str(out_path))
        if out_path.exists() and out_path.stat().st_size > 0:
            return "edge-tts"
    except Exception:
        pass

    # Fallback: gTTS (mp3)
    tts = gTTS(text=text, lang="fa")
    tts.save(str(out_path))
    if out_path.exists() and out_path.stat().st_size > 0:
        return "gtts"
    raise RuntimeError("ساخت فایل صوتی ناموفق بود")


async def generate_daily_voice_report(
    db: Session,
    report_date: date | None = None,
    *,
    force: bool = False,
) -> VoiceReport:
    report_date = report_date or date.today()
    existing = (
        db.query(VoiceReport)
        .filter(VoiceReport.report_date == report_date)
        .order_by(VoiceReport.id.desc())
        .first()
    )
    if (
        not force
        and existing
        and existing.audio_path
        and Path(existing.audio_path).exists()
    ):
        return existing

    base_script = build_ceo_script(db, report_date)
    script, mode = await refine_script_with_ai(base_script)

    filename = f"ceo_daily_{report_date.isoformat()}_{int(Path().cwd().stat().st_mtime)}.mp3"
    # Stable per-day filename; force overwrites
    filename = f"ceo_daily_{report_date.isoformat()}.mp3"
    out_path = settings.voice_dir / filename
    engine = "none"
    audio_path = None
    try:
        if out_path.exists():
            out_path.unlink()
        engine = await synthesize_persian_audio(script, out_path)
        audio_path = str(out_path)
    except Exception:
        audio_path = None

    report = VoiceReport(
        report_date=report_date,
        script_text=script,
        audio_path=audio_path,
        duration_hint="~60s",
        generation_mode=f"{mode}+{engine}",
    )
    db.add(report)
    db.commit()
    db.refresh(report)
    return report
