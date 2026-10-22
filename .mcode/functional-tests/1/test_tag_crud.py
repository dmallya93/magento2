"""CRUD through Magento\\Tag\\Model\\Tag / TagRelation against the real DB, plus FK integrity."""
import uuid

from conftest import (STATUS_APPROVED, STATUS_DISABLED, STATUS_PENDING, db_query, harness, make_rel, make_tag, ok)


class TestTagCrud:
    def test_create_and_load_tag(self, fx, name):
        n = name()
        r = ok("tag_save", data={"name": n, "status": STATUS_PENDING,
                                 "first_customer_id": fx["customers"][0], "first_store_id": 1})
        tid = r["tag_id"]
        assert tid > 0
        row = db_query("SELECT * FROM tag WHERE tag_id=%s", (tid,))[0]
        assert row["name"] == n and row["status"] == 0
        assert row["first_customer_id"] == fx["customers"][0] and row["first_store_id"] == 1
        loaded = ok("tag_load", id=tid)
        assert loaded["tag_id"] == tid and loaded["data"]["name"] == n and int(loaded["data"]["status"]) == 0

    def test_load_by_name(self, fx, name):
        n = name()
        tid = make_tag(n, STATUS_APPROVED)
        r = ok("tag_load_by_name", name=n)
        assert r["tag_id"] == tid and r["data"]["name"] == n

    def test_load_by_name_not_found(self, fx, name):
        r = ok("tag_load_by_name", name=name("_does_not_exist"))
        assert r["tag_id"] is None and r["data"] == []

    def test_load_missing_id_returns_empty(self, fx):
        r = ok("tag_load", id=987654321)
        assert r["tag_id"] is None

    def test_update_tag_status_and_name(self, fx, name):
        n = name()
        tid = make_tag(n, STATUS_PENDING)
        n2 = n + "_renamed"
        ok("tag_save", id=tid, data={"status": STATUS_APPROVED, "name": n2})
        row = db_query("SELECT * FROM tag WHERE tag_id=%s", (tid,))[0]
        assert row["status"] == STATUS_APPROVED and row["name"] == n2
        assert db_query("SELECT COUNT(*) c FROM tag WHERE name=%s", (n,))[0]["c"] == 0

    def test_delete_tag_cascades_relations_summary_properties(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        ok("aggregate", tag_id=tid)
        db_query("INSERT INTO tag_properties (tag_id, store_id, base_popularity) VALUES (%s, 1, 5)", (tid,))
        assert db_query("SELECT COUNT(*) c FROM tag_summary WHERE tag_id=%s", (tid,))[0]["c"] > 0
        r = ok("tag_delete", id=tid)
        assert r["row"] is None
        for table in ("tag", "tag_relation", "tag_summary", "tag_properties"):
            assert db_query(f"SELECT COUNT(*) c FROM {table} WHERE tag_id=%s", (tid,))[0]["c"] == 0, table

    def test_delete_tag_rejected_from_frontend_area(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = harness("tag_delete", id=tid, area="frontend")
        assert r.returncode == 2
        assert "non-admin" in r.error
        assert db_query("SELECT COUNT(*) c FROM tag WHERE tag_id=%s", (tid,))[0]["c"] == 1

    def test_application_level_name_uniqueness(self, fx, name):
        n = name()
        make_tag(n, STATUS_APPROVED)
        r = harness("tag_save", data={"name": n, "status": STATUS_APPROVED})
        assert r.returncode == 2
        assert "AlreadyExists" in r["exception_class"], r.data
        assert db_query("SELECT COUNT(*) c FROM tag WHERE name=%s", (n,))[0]["c"] == 1

    def test_pending_new_tag_with_pending_name_rejected(self, fx, name):
        n = name()
        make_tag(n, STATUS_PENDING)
        r = harness("tag_save", data={"name": n, "status": STATUS_PENDING})
        assert r.returncode == 2 and "AlreadyExists" in r["exception_class"], r.data
        assert db_query("SELECT COUNT(*) c FROM tag WHERE name=%s", (n,))[0]["c"] == 1


class TestForeignKeyIntegrity:
    def test_customer_delete_sets_first_customer_null_and_cascades_relations(self, fx, ns, name):
        cid = ok("customer_create", email=f"{ns}_tmp_{uuid.uuid4().hex[:6]}@example.com")["customer_id"]
        tid = make_tag(name(), STATUS_PENDING, customer=cid, store=1)
        make_rel(tid, cid, fx["products"][0], 1)
        ok("customer_delete", id=cid)
        row = db_query("SELECT * FROM tag WHERE tag_id=%s", (tid,))[0]
        assert row["first_customer_id"] is None
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE customer_id=%s", (cid,))[0]["c"] == 0

    def test_product_delete_cascades_relations(self, fx, ns, name):
        # the 4th fixture product is reserved for this destructive test (not used by any other test)
        pid = fx["products"][3]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, fx["customers"][0], pid, 1)
        ok("product_delete", id=pid)
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE product_id=%s", (pid,))[0]["c"] == 0
        assert db_query("SELECT COUNT(*) c FROM tag WHERE tag_id=%s", (tid,))[0]["c"] == 1

    def test_store_delete_sets_first_store_null_and_cascades(self, fx, ns, name):
        db_query("INSERT INTO store (code, website_id, group_id, name, sort_order, is_active) "
                 "VALUES (%s, 1, 1, 'ft tmp', 98, 1)", (f"ft_{uuid.uuid4().hex[:10]}",))
        sid = db_query("SELECT MAX(store_id) s FROM store")[0]["s"]
        tid = make_tag(name(), STATUS_APPROVED, store=sid)
        make_rel(tid, fx["customers"][0], fx["products"][0], sid)
        db_query("INSERT INTO tag_summary (tag_id, store_id) VALUES (%s, %s)", (tid, sid))
        db_query("INSERT INTO tag_properties (tag_id, store_id) VALUES (%s, %s)", (tid, sid))
        db_query("DELETE FROM store WHERE store_id=%s", (sid,))
        assert db_query("SELECT first_store_id FROM tag WHERE tag_id=%s", (tid,))[0]["first_store_id"] is None
        for table in ("tag_relation", "tag_summary", "tag_properties"):
            assert db_query(f"SELECT COUNT(*) c FROM {table} WHERE tag_id=%s", (tid,))[0]["c"] == 0, table

    def test_relation_unique_constraint(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        r = harness("relation_save", data={"tag_id": tid, "customer_id": fx["customers"][0],
                                           "product_id": fx["products"][0], "store_id": 1, "active": 1})
        assert r.returncode == 2, r.data
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE tag_id=%s", (tid,))[0]["c"] == 1

    def test_relation_fk_rejects_unknown_product(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = harness("relation_save", data={"tag_id": tid, "customer_id": fx["customers"][0],
                                           "product_id": 999999999, "store_id": 1, "active": 1})
        assert r.returncode == 2, r.data
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE tag_id=%s", (tid,))[0]["c"] == 0

    def test_relation_delete(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        rid = make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        row = db_query("SELECT * FROM tag_relation WHERE tag_relation_id=%s", (rid,))[0]
        assert row["created_at"] is not None and row["active"] == 1
        ok("relation_delete", id=rid)
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE tag_relation_id=%s", (rid,))[0]["c"] == 0
