from fastapi import Depends, FastAPI, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from app.auth import get_optional_user
from app.config import BASE_DIR, settings
from app.database import Base, SessionLocal, engine
from app.models import User
from app.routers.api import api_router
from app.seed import migrate_legacy_labels, seed_database

templates = Jinja2Templates(directory=str(BASE_DIR / "templates"))


def create_app() -> FastAPI:
    Base.metadata.create_all(bind=engine)
    db = SessionLocal()
    try:
        seed_database(db)
        migrate_legacy_labels(db)
    finally:
        db.close()

    application = FastAPI(title=settings.app_name, docs_url="/api/docs", redoc_url=None)
    application.include_router(api_router)
    application.mount("/static", StaticFiles(directory=str(BASE_DIR / "static")), name="static")

    @application.get("/", response_class=HTMLResponse)
    async def home(request: Request, user: User | None = Depends(get_optional_user)):
        if user:
            return RedirectResponse("/app", status_code=302)
        return templates.TemplateResponse(
            "login.html",
            {"request": request, "app_name": settings.app_name},
        )

    @application.get("/login", response_class=HTMLResponse)
    async def login_page(request: Request):
        return templates.TemplateResponse(
            "login.html",
            {"request": request, "app_name": settings.app_name},
        )

    @application.get("/app", response_class=HTMLResponse)
    @application.get("/app/{page}", response_class=HTMLResponse)
    async def app_shell(request: Request, page: str = "dashboard"):
        return templates.TemplateResponse(
            "app.html",
            {"request": request, "app_name": settings.app_name, "page": page},
        )

    return application


app = create_app()
