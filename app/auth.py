from datetime import datetime, timedelta
from typing import Optional

import bcrypt
from fastapi import Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from jose import JWTError, jwt
from sqlalchemy.orm import Session

from app.config import settings
from app.database import get_db
from app.models import User

bearer_scheme = HTTPBearer(auto_error=False)


def hash_password(password: str) -> str:
    return bcrypt.hashpw(password.encode("utf-8"), bcrypt.gensalt()).decode("utf-8")


def verify_password(plain: str, hashed: str) -> bool:
    try:
        return bcrypt.checkpw(plain.encode("utf-8"), hashed.encode("utf-8"))
    except ValueError:
        return False


def create_access_token(subject: str) -> str:
    expire = datetime.utcnow() + timedelta(minutes=settings.access_token_expire_minutes)
    return jwt.encode(
        {"sub": subject, "exp": expire},
        settings.secret_key,
        algorithm=settings.algorithm,
    )


def get_user_by_phone(db: Session, phone: str) -> Optional[User]:
    from app.numbers import to_en_digits

    normalized = to_en_digits(phone).strip().replace(" ", "").replace("-", "")
    return db.query(User).filter(User.phone == normalized).first()


def authenticate_user(db: Session, phone: str, password: str) -> Optional[User]:
    user = get_user_by_phone(db, phone)
    if not user or not verify_password(password, user.password_hash):
        return None
    return user


def get_current_user(
    request: Request,
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(bearer_scheme),
    db: Session = Depends(get_db),
) -> User:
    token = None
    if credentials:
        token = credentials.credentials
    if not token:
        token = request.cookies.get("access_token")
    if not token:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="ورود لازم است")

    try:
        payload = jwt.decode(token, settings.secret_key, algorithms=[settings.algorithm])
        phone = payload.get("sub")
        if not phone:
            raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="توکن نامعتبر")
    except JWTError as exc:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="توکن نامعتبر") from exc

    user = get_user_by_phone(db, phone)
    if not user or not user.is_active:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="کاربر یافت نشد")
    return user


def get_optional_user(
    request: Request,
    credentials: Optional[HTTPAuthorizationCredentials] = Depends(bearer_scheme),
    db: Session = Depends(get_db),
) -> Optional[User]:
    try:
        return get_current_user(request, credentials, db)
    except HTTPException:
        return None
