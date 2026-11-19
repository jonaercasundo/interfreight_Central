"""Verify one Redis outage and reconnection in a single Python process."""

import asyncio
import os
import time

from fastapi import HTTPException

from app.main import _claim_nonce


async def main() -> None:
    nonce = os.environ["IFC_PROBE_NONCE"]
    try:
        await _claim_nonce(nonce, int(time.time()))
    except HTTPException as error:
        print(f"redis_outage_http={error.status_code}")
        if error.status_code != 503:
            raise
    else:
        raise SystemExit("Redis was expected to be unavailable for the first probe.")

    deadline = time.monotonic() + float(os.getenv("IFC_PROBE_RECOVERY_TIMEOUT", "20"))
    while time.monotonic() < deadline:
        try:
            recovered = await _claim_nonce(nonce, int(time.time()))
            print(f"redis_recovered_nonce_claimed={str(recovered).lower()}")
            if not recovered:
                raise SystemExit("Recovered Redis rejected the new nonce.")
            return
        except HTTPException as error:
            if error.status_code != 503:
                raise
            await asyncio.sleep(0.5)

    raise SystemExit("Redis did not recover the nonce claim before timeout.")


asyncio.run(main())
