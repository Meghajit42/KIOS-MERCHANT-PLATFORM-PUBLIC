"""Outbound-only Kios print worker for Python 3.11 on Windows."""
import argparse
import base64
import binascii
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

DESTINATIONS = {"CASHIER_PRINTER", "GROUND_FLOOR_PRINTER", "FIRST_FLOOR_KITCHEN_PRINTER"}
TIMEOUT = 12
MAX_BYTES = 256 * 1024
HELPER = Path(__file__).resolve().parents[1] / "bin" / "windows-raw-printer.ps1"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class GatewayError(Exception):
    def __init__(self, status):
        self.status = status


class Api:
    def __init__(self, url, credential, ca_file=None):
        address = urllib.parse.urlsplit(url)
        if (address.scheme != "https" or not address.hostname or address.username
                or address.password or address.query or address.fragment
                or address.path != "/print-gateway.php"):
            raise ValueError("Use the HTTPS /print-gateway.php endpoint.")
        if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,63}\.[a-f0-9]{64}", credential):
            raise ValueError("Missing or invalid gateway credential.")
        self.url = url
        self.credential = credential
        context = ssl.create_default_context(cafile=ca_file)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=context)
        )

    def post(self, data):
        request = urllib.request.Request(self.url, json.dumps(data).encode(), {
            "Authorization": "Bearer " + self.credential,
            "Content-Type": "application/json",
        }, method="POST")
        try:
            with self.opener.open(request, timeout=TIMEOUT) as response:
                if response.status == 204:
                    return None
                if response.status != 200:
                    raise GatewayError(response.status)
                raw = response.read(MAX_BYTES * 2)
                return json.loads(raw)
        except urllib.error.HTTPError as error:
            raise GatewayError(error.code) from None


def submit(job, destinations, deadline):
    """Return failed only when no payload bytes could have reached a printer."""
    name = job.get("destination")
    if not isinstance(name, str) or name not in destinations:
        return "failed"
    if (name == "CASHIER_PRINTER") != (job.get("type") == "CASHIER_RECEIPT"):
        return "failed"
    if job.get("type") not in {"CASHIER_RECEIPT", "STATION_KOT"}:
        return "failed"
    try:
        payload = base64.b64decode(job["payload"], validate=True)
        if (not 0 < len(payload) <= MAX_BYTES
                or not hmac.compare_digest(hashlib.sha256(payload).hexdigest(), job["sha256"])):
            return "failed"
    except (KeyError, TypeError, ValueError, binascii.Error):
        return "failed"

    destination = destinations[name]
    if time.monotonic() + TIMEOUT + 5 >= deadline:
        return "failed"
    if destination["mode"] == "virtual":
        output = Path(destination["directory"]) / f"ticket-{job['id']}.escpos"
        try:
            handle = output.open("xb")
        except FileExistsError:
            return "needs_review"
        except OSError:
            return "failed"
        try:
            with handle:
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
        except OSError:
            return "needs_review"
        return "submitted"

    executable = Path(os.environ["SystemRoot"]) / "System32/WindowsPowerShell/v1.0/powershell.exe"
    packet = json.dumps({"printerName": destination["queue"], "bytesBase64": job["payload"]})
    try:
        outcome = subprocess.run(
            [str(executable), "-NoProfile", "-NonInteractive", "-File", str(HELPER)],
            input=base64.b64encode(packet.encode()) + b"\n", capture_output=True,
            timeout=TIMEOUT, creationflags=subprocess.CREATE_NO_WINDOW, check=False,
        )
    except subprocess.TimeoutExpired:
        return "needs_review"
    except OSError:
        return "failed"
    if outcome.returncode in (3, 4, 5):
        return "failed"
    try:
        if outcome.returncode == 0 and json.loads(outcome.stdout) == {"success": True, "category": "printed"}:
            return "submitted"
    except (UnicodeError, ValueError):
        pass
    return "needs_review"


class Worker:
    def __init__(self, api, destinations, journal):
        self.api = api
        self.destinations = destinations
        self.journal = sqlite3.connect(journal, timeout=TIMEOUT)
        self.journal.execute(
            "CREATE TABLE IF NOT EXISTS deliveries (job INTEGER PRIMARY KEY, claim TEXT NOT NULL, result TEXT, sent INTEGER NOT NULL DEFAULT 0)"
        )
        self.journal.execute("CREATE TABLE IF NOT EXISTS identity (name TEXT PRIMARY KEY)")
        scope = api.url + "|" + api.credential.split(".", 1)[0]
        with self.journal:
            self.journal.execute("INSERT INTO identity SELECT ? WHERE NOT EXISTS (SELECT 1 FROM identity)", (scope,))
            if self.journal.execute("SELECT name FROM identity").fetchone() != (scope,):
                raise ValueError("Use a separate journal per server and gateway.")

    def report(self):
        pending = self.journal.execute(
            "SELECT job, claim, result FROM deliveries WHERE result IS NOT NULL AND sent = 0 ORDER BY job"
        ).fetchall()
        for job, claim, result in pending:
            try:
                self.api.post({"action": "complete", "id": job, "claim": claim, "result": result})
                sent = 1
            except GatewayError as error:
                if error.status != 409:
                    raise
                print(f"Ticket {job} needs owner review. Its report was no longer accepted.", flush=True)
                sent = 2
            with self.journal:
                self.journal.execute("UPDATE deliveries SET sent = ?, claim = '' WHERE job = ?", (sent, job))

    def step(self):
        self.report()
        started = time.monotonic()
        job = self.api.post({"action": "claim"})
        if job is None:
            return False
        if (not isinstance(job, dict) or type(job.get("id")) is not int or job["id"] < 1
                or type(job.get("lease_seconds")) is not int or not 1 <= job["lease_seconds"] <= 90
                or not isinstance(job.get("claim"), str) or not re.fullmatch(r"[a-f0-9]{64}", job["claim"])):
            raise ValueError("Invalid claim. No bytes sent.")
        with self.journal:
            reserved = self.journal.execute(
                "INSERT OR IGNORE INTO deliveries (job, claim) VALUES (?, ?)", (job["id"], job["claim"])
            ).rowcount
        if not reserved:
            # A duplicate response or a prior crash is never permission to send again.
            return False
        result = submit(job, self.destinations, started + job["lease_seconds"])
        with self.journal:
            self.journal.execute("UPDATE deliveries SET result = ? WHERE job = ?", (result, job["id"]))
        self.report()
        return True


def read_config(path, allow_windows):
    config = json.loads(path.read_text(encoding="utf-8"))
    journal = Path(config["journal"])
    if not journal.is_absolute() or not journal.parent.is_dir():
        raise ValueError("Use an absolute journal path in an existing private directory.")
    destinations = config["destinations"]
    if not isinstance(destinations, dict) or not destinations or not set(destinations) <= DESTINATIONS:
        raise ValueError("Configure only known printer destinations.")
    for target in destinations.values():
        if target["mode"] == "virtual":
            directory = Path(target["directory"])
            if not directory.is_absolute() or not directory.is_dir():
                raise ValueError("Virtual spool requires an existing absolute directory.")
        elif (target["mode"] != "windows" or not allow_windows or os.name != "nt"
              or not re.fullmatch(r"[^\\/*?\x00-\x1f]{1,160}", target.get("queue", ""))
              or not HELPER.is_file()):
            raise ValueError("Windows printing requires explicit enablement and an exact installed queue.")
    return config


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("config", type=Path)
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--enable-windows-printer", action="store_true")
    args = parser.parse_args()
    try:
        config = read_config(args.config, args.enable_windows_printer)
        api = Api(config["url"], os.environ.get("KIOS_GATEWAY_CREDENTIAL", ""), config.get("ca_file"))
        worker = Worker(api, config["destinations"], config["journal"])
    except (OSError, ValueError, KeyError, TypeError, sqlite3.Error):
        print("Invalid gateway configuration or unavailable local journal.", flush=True)
        return 2

    delay = 2
    while True:
        try:
            active = worker.step()
            if args.once:
                return 0
            delay = 2
            if not active:
                time.sleep(delay)
        except GatewayError as error:
            if error.status < 500:
                print(f"Gateway refused the request (HTTP {error.status}). Check authorization or configuration.", flush=True)
                return 2
            if args.once:
                return 3
            time.sleep(delay)
            delay = min(delay * 2, 30)
        except (OSError, urllib.error.URLError):
            if args.once:
                return 3
            time.sleep(delay)
            delay = min(delay * 2, 30)
        except (ValueError, sqlite3.Error):
            print("Gateway stopped after an invalid response or journal error. Check pending claims.", flush=True)
            return 2


if __name__ == "__main__":
    raise SystemExit(main())
