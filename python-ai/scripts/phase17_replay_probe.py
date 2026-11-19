"""Claim one test nonce in a standalone process using the configured Redis store."""

import asyncio
import os
import time

from app.main import _claim_nonce


nonce = os.environ["IFC_PROBE_NONCE"]
claimed = asyncio.run(_claim_nonce(nonce, int(time.time())))
print(f"nonce_claimed={str(claimed).lower()}")
