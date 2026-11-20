import base64
import hashlib
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

from worker import GatewayError, Worker, submit


def ticket():
    payload = b"\x1b@TEST TICKET\n"
    return {
        "id": 1, "claim": "a" * 64, "lease_seconds": 90,
        "destination": "GROUND_FLOOR_PRINTER", "type": "STATION_KOT",
        "payload": base64.b64encode(payload).decode(),
        "sha256": hashlib.sha256(payload).hexdigest(),
    }


class PrintWorkerTest(unittest.TestCase):
    def test_bad_payload_and_destination_fail_before_spooling(self):
        with tempfile.TemporaryDirectory() as tmp:
            routes = {"GROUND_FLOOR_PRINTER": {"mode": "virtual", "directory": tmp}}
            self.assertEqual(submit({**ticket(), "sha256": "0" * 64}, routes, time.monotonic() + 90), "failed")
            self.assertEqual(submit({**ticket(), "destination": "CASHIER_PRINTER"}, routes, time.monotonic() + 90), "failed")
            self.assertEqual(list(Path(tmp).iterdir()), [])

    def test_existing_output_is_ambiguous(self):
        with tempfile.TemporaryDirectory() as tmp:
            routes = {"GROUND_FLOOR_PRINTER": {"mode": "virtual", "directory": tmp}}
            self.assertEqual(submit(ticket(), routes, time.monotonic() + 90), "submitted")
            self.assertEqual(submit(ticket(), routes, time.monotonic() + 90), "needs_review")
            self.assertEqual(len(list(Path(tmp).iterdir())), 1)

    def test_restart_replays_ack_without_resending(self):
        class Api:
            url = "https://localhost/print-gateway.php"
            credential = "test-identity"
            unavailable = True
            reports = 0

            def post(self, body):
                if body["action"] == "claim":
                    return ticket()
                if self.unavailable:
                    raise GatewayError(503)
                self.reports += 1
                return None

        with tempfile.TemporaryDirectory() as tmp, patch("worker.submit", return_value="submitted") as send:
            api = Api()
            journal = Path(tmp) / "test.sqlite"
            worker = Worker(api, {}, journal)
            with self.assertRaises(GatewayError):
                worker.step()
            worker.journal.close()
            api.unavailable = False
            worker = Worker(api, {}, journal)
            try:
                self.assertFalse(worker.step())
                self.assertEqual(api.reports, 1)
                send.assert_called_once()
            finally:
                worker.journal.close()


if __name__ == "__main__":
    unittest.main()
