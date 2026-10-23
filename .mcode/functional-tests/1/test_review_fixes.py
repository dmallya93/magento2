"""Behaviour introduced/changed by the milestone-1 code-review fixes, verified against the real app + DB + tag.log."""
from conftest import (STATUS_APPROVED, STATUS_DISABLED, db_query, harness, log_entries, make_rel, make_tag, ok)


class TestAdminContext:
    def test_delete_tag_allowed_from_crontab_area(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = harness("tag_delete", id=tid, area="crontab")
        assert r.returncode == 0, r.stdout + r.stderr
        assert db_query("SELECT COUNT(*) c FROM tag WHERE tag_id=%s", (tid,))[0]["c"] == 0

    def test_delete_tag_rejected_from_graphql_area(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = harness("tag_delete", id=tid, area="graphql")
        assert r.returncode == 2, r.stdout + r.stderr
        assert "non-admin" in (r.error or "")
        assert db_query("SELECT COUNT(*) c FROM tag WHERE tag_id=%s", (tid,))[0]["c"] == 1


class TestEventPayload:
    def test_tag_event_object_key_is_object(self, fx):
        r = ok("event_payload", model="tag")
        assert r["event_prefix"] == "tag"
        assert "object" in r["keys"] and "tag" not in r["keys"] and r["object_is_model"] is True

    def test_relation_event_object_key_is_object(self, fx):
        r = ok("event_payload", model="relation")
        assert r["event_prefix"] == "tag_relation"
        assert "object" in r["keys"] and "tag_relation" not in r["keys"] and r["object_is_model"] is True


class TestRelationCreatedAt:
    def test_created_at_null_when_not_set(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = ok("relation_save", data={"tag_id": tid, "customer_id": fx["customers"][0],
                                      "product_id": fx["products"][0], "store_id": 1, "active": 1})
        assert r["row"]["created_at"] is None
        assert db_query("SELECT created_at FROM tag_relation WHERE tag_relation_id=%s",
                        (r["tag_relation_id"],))[0]["created_at"] is None

    def test_created_at_kept_when_set(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        rid = make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        assert str(db_query("SELECT created_at FROM tag_relation WHERE tag_relation_id=%s",
                            (rid,))[0]["created_at"]) == "2026-01-01 00:00:00"


class TestAddRelationsValidation:
    def test_null_product_ids_rejected_and_relations_kept(self, fx, name):
        p1, p2 = fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        ok("add_relations", tag_id=tid, store=1, product_ids=[p1, p2])
        r = harness("add_relations", tag_id=tid, store=1, product_ids=None)
        assert r.returncode == 2, r.stdout + r.stderr
        assert r.data.get("exception_class") == "InvalidArgumentException", r.data
        rows = db_query("SELECT product_id FROM tag_relation WHERE tag_id=%s", (tid,))
        assert sorted(x["product_id"] for x in rows) == sorted([p1, p2])

    def test_string_product_ids_rejected(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = harness("add_relations", tag_id=tid, store=1, product_ids=str(fx["products"][0]))
        assert r.returncode == 2 and r.data.get("exception_class") == "InvalidArgumentException", r.data
        assert db_query("SELECT COUNT(*) c FROM tag_relation WHERE tag_id=%s", (tid,))[0]["c"] == 0


class TestProductCollectionStoreId:
    def test_item_store_id_from_join_not_collection_store(self, fx, name):
        c1, (p1, p2) = fx["customers"][0], fx["products"][:2]
        s2 = fx["store2"]
        t_global = make_tag(name("_g"), STATUS_APPROVED, customer=c1, store=0)
        make_rel(t_global, c1, p1, s2)
        r = ok("product_collection", tag_id=t_global)
        items = {int(i["entity_id"]): i for i in r["items"]}
        assert int(items[p1]["store_id"]) == s2, items  # first_store_id=0 -> relation.store_id
        t_store = make_tag(name("_s"), STATUS_APPROVED, customer=c1, store=1)
        make_rel(t_store, c1, p2, s2)
        r = ok("product_collection", tag_id=t_store)
        items = {int(i["entity_id"]): i for i in r["items"]}
        assert int(items[p2]["store_id"]) == 1, items  # first_store_id!=0 -> t.first_store_id
        assert int(items[p2]["item_store_id"]) == s2


class TestNewLogging:
    def test_tag_delete_logs_start_and_completed(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = ok("tag_delete", id=tid)
        logs = log_entries(r["operation_id"], "tag.delete")
        assert [e["level"] for e in logs] == ["INFO", "INFO"], logs
        assert int(logs[0]["tag_id"]) == tid and int(logs[0]["before_status"]) == STATUS_APPROVED
        assert logs[1]["outcome"] == "success" and "duration_ms" in logs[1] and logs[1]["module"] == "Magento_Tag"

    def test_tag_aggregate_logs_info_start_and_completed(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        r = ok("aggregate", tag_id=tid)
        logs = log_entries(r["operation_id"], "tag.aggregate")
        assert len(logs) == 2 and all(e["level"] == "INFO" for e in logs), logs
        assert logs[-1]["outcome"] == "success" and int(logs[-1]["tag_id"]) == tid

    def test_relation_deactivate_success_log_shape(self, fx, name):
        c1, (p1, p2) = fx["customers"][0], fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        r1 = make_rel(tid, c1, p1, 1)
        r2 = make_rel(tid, c1, p2, 1, active=0)
        r = ok("relation_deactivate", tag_id=tid, customer_id=c1)
        logs = log_entries(r["operation_id"], "relation.deactivate")
        assert len(logs) == 2 and [e["level"] for e in logs] == ["INFO", "INFO"], logs
        done = logs[1]
        assert done["outcome"] == "success" and int(done["affected_count"]) == 1
        assert sorted(int(x) for x in done["relation_ids"]) == sorted([r1, r2])
        assert {int(k): int(v) for k, v in done["before_active"].items()} == {r1: 1, r2: 0}

    def test_save_relation_duplicate_warning(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p, 1)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "exist"
        warns = [e for e in log_entries(r["operation_id"], "relation.save") if e["level"] == "WARNING"]
        assert len(warns) == 1 and warns[0]["error_code"] == "relation.save.duplicate", warns
        assert warns[0]["saveRelation_result"] == "exist"

    def test_save_relation_rejected_disabled_warning(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_DISABLED)
        make_rel(tid, c1, p, 1)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        warns = [e for e in log_entries(r["operation_id"], "relation.save") if e["level"] == "WARNING"]
        assert len(warns) == 1 and warns[0]["error_code"] == "relation.save.rejected_disabled", warns
        assert warns[0]["saveRelation_result"] == r["result"]
        assert warns[0]["decision_path"] == "disabled.customer_already_linked"
