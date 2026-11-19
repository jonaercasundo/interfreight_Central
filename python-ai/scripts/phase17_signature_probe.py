"""Safe local probe for signed FastAPI requests; uses no customer data."""

import hashlib
import hmac
import os
import time
import uuid

import httpx


token = os.environ["AI_SERVICE_TOKEN"]
url = os.getenv("IFC_PROBE_URL", "http://127.0.0.1:8000/internal/diagnostic")
body = "{}"


def send(nonce: str, *, timestamp: int | None = None, signature: str | None = None) -> int:
    signed_at = str(timestamp or int(time.time()))
    message = "\n".join([signed_at, nonce, "POST", "/internal/diagnostic", body])
    signed = signature or hmac.new(token.encode(), message.encode(), hashlib.sha256).hexdigest()
    response = httpx.post(
        url,
        content=body,
        headers={
            "Content-Type": "application/json",
            "X-IFC-Timestamp": signed_at,
            "X-IFC-Nonce": nonce,
            "X-IFC-Signature": signed,
            "X-Correlation-ID": str(uuid.uuid4()),
        },
        timeout=5,
    )
    return response.status_code


nonce = uuid.uuid4().hex
results = {
    "valid": send(nonce),
    "duplicate_nonce": send(nonce),
    "invalid_signature": send(uuid.uuid4().hex, signature="0" * 64),
    "expired_request": send(uuid.uuid4().hex, timestamp=int(time.time()) - 301),
}
for case, status in results.items():
    print(f"{case}_http={status}")

if results != {"valid": 200, "duplicate_nonce": 401, "invalid_signature": 401, "expired_request": 401}:
    raise SystemExit("Unexpected signed request behavior.")
