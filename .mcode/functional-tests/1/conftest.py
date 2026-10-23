"""Shared fixtures for Magento_Tag milestone-1 functional tests.

The module has no HTTP surface yet, so every test drives the real installed Magento application through
``harness/tag_harness.php`` (Magento bootstrap + ObjectManager -> real models / resource models / collections /
helper) and verifies persisted state directly in MySQL and the structured entries in ``var/log/tag.log``.
"""
import json
import os
import re
import subprocess
import uuid
from pathlib import Path

import pymysql
import pytest
import requests

HERE = Path(__file__).resolve().parent
REPO_ROOT = HERE.parents[2]
HARNESS = HERE / "harness" / "tag_harness.php"
TAG_LOG = REPO_ROOT / "var" / "log" / "tag.log"
EVIDENCE_DIR = Path(os.environ.get("FT_EVIDENCE_DIR", "/tmp/ft_evidence_tag_m1"))

DB = dict(
    host=os.environ.get("DB_HOST", "127.0.0.1"),
    port=int(os.environ.get("DB_PORT", "3306")),
    user=os.environ.get("DB_USER", "magento"),
    password=os.environ["DB_PASSWORD"],
    database=os.environ.get("DB_NAME", "magento"),
)
BASE_URL = f"http://127.0.0.1:{os.environ.get('MAGENTO_PORT', '8082')}/"

STATUS_DISABLED, STATUS_PENDING, STATUS_APPROVED = -1, 0, 1

LOGGED_EVENTS = {"relation.decide", "tag.status.auto_reopened", "tag.merge", "tag.name.truncated",
                 "tag.name.duplicate_detected", "collection.load", "relation.deactivate"}

_calls = []  # evidence of harness invocations for the current test


class HarnessResult:
    def __init__(self, op, args, proc):
        self.op = op
        self.args = args
        self.returncode = proc.returncode
        self.stdout = proc.stdout
        self.stderr = proc.stderr
        self.data = {}
        for line in proc.stdout.splitlines():
            if line.startswith("@@RESULT@@"):
                self.data = json.loads(line[len("@@RESULT@@"):])

    def __getitem__(self, key):
        return self.data[key]

    @property
    def error(self):
        return self.data.get("error")


def harness(op, **args):
    proc = subprocess.run(
        ["php", str(HARNESS), op, json.dumps(args)],
        cwd=str(REPO_ROOT), capture_output=True, text=True, timeout=300,
    )
    res = HarnessResult(op, args, proc)
    _calls.append(res)
    return res


def ok(op, **args):
    res = harness(op, **args)
    assert res.returncode == 0, f"{op} failed: rc={res.returncode} {res.stdout[-2000:]} {res.stderr[-2000:]}"
    return res


def db_query(sql, params=None):
    conn = pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor, autocommit=True)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return cur.fetchall()
    finally:
        conn.close()


_LOG_RE = re.compile(r"^\[(?P<ts>[^\]]+)\] tag\.(?P<level>\w+): (?P<msg>.*?) (?P<ctx>\{.*\}) (?:\[\]|\{.*\})$")


def log_entries(operation_id=None, operation=None):
    """Parse var/log/tag.log; filter by operation_id and/or operation."""
    entries = []
    if not TAG_LOG.exists():
        return entries
    for line in TAG_LOG.read_text(encoding="utf-8", errors="replace").splitlines():
        m = _LOG_RE.match(line)
        if not m:
            continue
        try:
            ctx = json.loads(m.group("ctx"))
        except ValueError:
            continue
        if operation_id and ctx.get("operation_id") != operation_id:
            continue
        if operation and ctx.get("operation") != operation:
            continue
        entries.append({"level": m.group("level"), "message": m.group("msg"), **ctx})
    return entries


@pytest.fixture(scope="session", autouse=True)
def app_healthy():
    r = requests.get(BASE_URL, timeout=120)
    assert r.status_code == 200, f"storefront not healthy: {r.status_code}"


@pytest.fixture(scope="session")
def ns():
    return "ftrun_" + uuid.uuid4().hex[:8]


@pytest.fixture(scope="session")
def fx(ns, app_healthy):
    """Namespaced customers, products and a 2nd store view; removed (with every ns-prefixed tag) on teardown."""
    data = ok("setup_fixtures", ns=ns).data
    yield data
    harness("teardown_fixtures", ns=ns)


@pytest.fixture
def name(ns, request):
    """Unique, namespaced tag name per test."""
    counter = {"n": 0}

    def _make(suffix=""):
        counter["n"] += 1
        return f"{ns}_{request.node.name[:40]}_{counter['n']}{suffix}"

    return _make


@pytest.fixture(autouse=True)
def evidence(request):
    _calls.clear()
    yield
    EVIDENCE_DIR.mkdir(parents=True, exist_ok=True)
    lines = []
    for c in _calls:
        lines.append(f"$ php tag_harness.php {c.op} {json.dumps(c.args, ensure_ascii=False)}")
        lines.append(f"exit={c.returncode}")
        lines.append(c.stdout.strip())
        op_id = c.data.get("operation_id")
        if op_id:
            for e in log_entries(op_id):
                if e.get("operation") in LOGGED_EVENTS:
                    lines.append("tag.log: " + json.dumps(e, ensure_ascii=False))
    (EVIDENCE_DIR / f"{request.node.name}.txt").write_text("\n".join(lines), encoding="utf-8")


def make_tag(name, status, customer=None, store=None):
    data = {"name": name, "status": status}
    if customer is not None:
        data["first_customer_id"] = customer
    if store is not None:
        data["first_store_id"] = store
    return ok("tag_save", data=data)["tag_id"]


def make_rel(tag_id, customer_id, product_id, store_id, active=1):
    return ok(
        "relation_save",
        data={"tag_id": tag_id, "customer_id": customer_id, "product_id": product_id,
              "store_id": store_id, "active": active, "created_at": "2026-01-01 00:00:00"},
    )["tag_relation_id"]
