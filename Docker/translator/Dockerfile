# Local translation service (Docker/translator/server.py): NLLB-200 on CTranslate2.
# No PyTorch, so the image stays small; the model itself (~630 MB) is downloaded
# on first start into the translator_models volume, not baked into the image.
# Bookworm, not the newer trixie: its glibc refuses CTranslate2's executable-stack library
FROM python:3.12-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN pip install --no-cache-dir ctranslate2==4.5.0 sentencepiece==0.2.0

WORKDIR /app
COPY Docker/translator/server.py .

EXPOSE 8000
CMD ["python", "server.py"]
