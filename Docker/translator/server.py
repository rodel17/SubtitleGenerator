"""
SubGen's local translation service: Meta's NLLB-200 (distilled 600M, int8) on CTranslate2.

    POST /translate  {"source": "eng_Latn", "target": "tgl_Latn", "texts": ["Hello.", ...]}
                  -> {"translations": ["Kumusta.", ...]}
    GET  /health  -> {"status": "ok"}

Language codes are NLLB's (FLORES-200); the Laravel app maps its own codes to them.
The model files are downloaded once into MODEL_DIR (a Docker volume), pinned to a revision.
Only reachable inside the Docker network. The model is licensed CC-BY-NC 4.0 (non-commercial).
"""

import json
import os
import re
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import ctranslate2
import sentencepiece

MODEL_REPO = os.environ.get('MODEL_REPO', 'JustFrederik/nllb-200-distilled-600M-ct2-int8')
MODEL_REVISION = os.environ.get('MODEL_REVISION', '302d78f00e6fdb50a1064059df7c392b735e9d05')
MODEL_DIR = os.environ.get('MODEL_DIR', '/models/nllb-200-distilled-600M-ct2-int8')
MODEL_FILES = ['config.json', 'model.bin', 'shared_vocabulary.txt', 'sentencepiece.bpe.model']
THREADS = int(os.environ.get('THREADS', '2'))
PORT = int(os.environ.get('PORT', '8000'))

MAX_TEXTS = 128
MAX_TEXT_LENGTH = 2000
MAX_BODY_BYTES = 2 * 1024 * 1024


def download_model() -> None:
    os.makedirs(MODEL_DIR, exist_ok=True)

    for name in MODEL_FILES:
        path = os.path.join(MODEL_DIR, name)
        if os.path.exists(path):
            continue

        url = f'https://huggingface.co/{MODEL_REPO}/resolve/{MODEL_REVISION}/{name}'
        print(f'Downloading {url}', flush=True)
        # Download next to the target and rename, so an interrupted download is never mistaken for a complete one
        urllib.request.urlretrieve(url, path + '.part')
        os.replace(path + '.part', path)


download_model()
translator = ctranslate2.Translator(MODEL_DIR, device='cpu', compute_type='int8', inter_threads=1, intra_threads=THREADS)
tokenizer = sentencepiece.SentencePieceProcessor(model_file=os.path.join(MODEL_DIR, 'sentencepiece.bpe.model'))

with open(os.path.join(MODEL_DIR, 'shared_vocabulary.txt'), encoding='utf-8') as vocabulary:
    # Language codes such as "tgl_Latn" (language + script) are tokens of the model's vocabulary
    LANGUAGES = {token.strip() for token in vocabulary if re.fullmatch(r'[a-z]{3}_[A-Z][a-z]{3}', token.strip())}


def translate(texts: list[str], source: str, target: str) -> list[str]:
    # NLLB input: source language token, the sentence's pieces, end of sentence; the output starts with the target token
    batch = [[source, *tokenizer.encode(text, out_type=str), '</s>'] for text in texts]
    results = translator.translate_batch(
        batch,
        target_prefix=[[target]] * len(batch),
        beam_size=4,
        max_batch_size=16,
        max_decoding_length=256,
    )

    translations = []
    for result in results:
        tokens = result.hypotheses[0]
        translations.append(tokenizer.decode(tokens[1:] if tokens and tokens[0] == target else tokens))

    return translations


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path == '/health':
            self.respond(200, {'status': 'ok'})
        else:
            self.respond(404, {'error': 'Not found.'})

    def do_POST(self) -> None:
        if self.path != '/translate':
            self.respond(404, {'error': 'Not found.'})
            return

        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BODY_BYTES:
            self.respond(413, {'error': 'The request body is empty or too large.'})
            return

        try:
            payload = json.loads(self.rfile.read(length))
            source, target, texts = payload['source'], payload['target'], payload['texts']
        except (ValueError, KeyError, TypeError):
            self.respond(400, {'error': 'Send JSON with "source", "target" and "texts".'})
            return

        if source not in LANGUAGES or target not in LANGUAGES:
            self.respond(422, {'error': f'Unsupported language: {source if source not in LANGUAGES else target}.'})
            return

        if not isinstance(texts, list) or len(texts) > MAX_TEXTS or not all(isinstance(text, str) and len(text) <= MAX_TEXT_LENGTH for text in texts):
            self.respond(422, {'error': f'"texts" must be a list of at most {MAX_TEXTS} strings of up to {MAX_TEXT_LENGTH} characters.'})
            return

        # Blank lines stay blank instead of making the model invent something
        filled = [index for index, text in enumerate(texts) if text.strip()]
        translations = [''] * len(texts)
        for index, translation in zip(filled, translate([texts[index].strip() for index in filled], source, target)):
            translations[index] = translation

        self.respond(200, {'translations': translations})

    def respond(self, status: int, body: dict) -> None:
        data = json.dumps(body, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, format: str, *args) -> None:
        # Never log request contents; the status line is enough
        print(f'{self.command} {self.path} {args[1] if len(args) > 1 else ""}', flush=True)


if __name__ == '__main__':
    print(f'Translator ready on port {PORT} ({len(LANGUAGES)} languages)', flush=True)
    ThreadingHTTPServer(('0.0.0.0', PORT), Handler).serve_forever()
