"""Magento\\Tag\\Model\\TagRelation operations and Tag::aggregate() / getRelatedProductIds() on real rows."""
from conftest import STATUS_APPROVED, STATUS_PENDING, db_query, log_entries, make_rel, make_tag, ok


class TestTagRelation:
    def test_load_by_tag_customer(self, fx, name):
        c1, c2, p1, p2 = *fx["customers"][:2], *fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        rid = make_rel(tid, c1, p2, 1)
        make_rel(tid, c2, p1, 1)
        r = ok("relation_load_by_tag_customer", product_id=p2, tag_id=tid, customer_id=c1, store_id=1)
        assert r["tag_relation_id"] == rid
        assert int(r["data"]["customer_id"]) == c1 and int(r["data"]["product_id"]) == p2

    def test_load_by_tag_customer_store_scoped_miss(self, fx, name):
        c1, p1 = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p1, 1)
        r = ok("relation_load_by_tag_customer", product_id=p1, tag_id=tid, customer_id=c1, store_id=fx["store2"])
        assert r["tag_relation_id"] is None

    def test_get_product_ids_filters(self, fx, name):
        c1, c2 = fx["customers"][:2]
        p1, p2, p3 = fx["products"][:3]
        s2 = fx["store2"]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p1, 1)
        make_rel(tid, c1, p2, 1, active=0)
        make_rel(tid, c2, p3, s2)
        assert ok("relation_product_ids", tag_id=tid)["product_ids"] == sorted([p1, p2, p3])
        assert ok("relation_product_ids", tag_id=tid, customer_id=c1)["product_ids"] == sorted([p1, p2])
        assert ok("relation_product_ids", tag_id=tid, store_id=s2)["product_ids"] == [p3]
        assert ok("relation_product_ids", tag_id=tid, status_filter=STATUS_APPROVED)["product_ids"] == sorted([p1, p2, p3])
        assert ok("relation_product_ids", tag_id=tid, status_filter=STATUS_PENDING)["product_ids"] == []
        assert ok("related_product_ids", tag_id=tid, store_id=1)["product_ids"] == sorted([p1, p2])

    def test_deactivate(self, fx, name):
        c1, c2 = fx["customers"][:2]
        p1, p2 = fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p1, 1)
        make_rel(tid, c1, p2, fx["store2"])
        make_rel(tid, c2, p1, 1)
        r = ok("relation_deactivate", tag_id=tid, customer_id=c1)
        state = {(x["customer_id"], x["product_id"]): x["active"] for x in db_query(
            "SELECT customer_id, product_id, active FROM tag_relation WHERE tag_id=%s", (tid,))}
        assert state == {(c1, p1): 0, (c1, p2): 0, (c2, p1): 1}
        logs = log_entries(r["operation_id"], "relation.deactivate")
        assert any(int(e.get("affected_count", -1)) == 2 for e in logs), logs

    def test_deactivate_not_found_logs_warning(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        r = ok("relation_deactivate", tag_id=tid, customer_id=fx["customers"][2])
        logs = log_entries(r["operation_id"], "relation.deactivate")
        assert any(e.get("error_code") == "relation.deactivate.not_found" and e["level"] == "WARNING" for e in logs), logs

    def test_add_relations_replaces_store_product_set(self, fx, name):
        p1, p2, p3 = fx["products"][:3]
        tid = make_tag(name(), STATUS_APPROVED)
        r1 = ok("add_relations", tag_id=tid, store=1, product_ids=[p1, p2])
        assert sorted(r1["inserted"]) == sorted([p1, p2]) and r1["deleted"] == []
        rows = db_query("SELECT product_id, customer_id, store_id, active FROM tag_relation WHERE tag_id=%s", (tid,))
        assert sorted(x["product_id"] for x in rows) == sorted([p1, p2])
        assert all(x["customer_id"] is None and x["store_id"] == 1 and x["active"] == 1 for x in rows)
        r2 = ok("add_relations", tag_id=tid, store=1, product_ids=[p2, p3])
        assert r2["inserted"] == [p3] and r2["deleted"] == [p1]
        rows = db_query("SELECT product_id FROM tag_relation WHERE tag_id=%s", (tid,))
        assert sorted(x["product_id"] for x in rows) == sorted([p2, p3])

    def test_tag_save_with_assigned_products_creates_relations(self, fx, name):
        p1, p2 = fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        ok("tag_save_assigned_products", tag_id=tid, store=fx["store2"], product_ids=[p1, p2])
        rows = db_query("SELECT product_id, store_id FROM tag_relation WHERE tag_id=%s", (tid,))
        assert sorted((x["product_id"], x["store_id"]) for x in rows) == sorted([(p1, fx["store2"]), (p2, fx["store2"])])


class TestAggregate:
    def test_aggregate_writes_tag_summary(self, fx, name):
        c1, c2, c3 = fx["customers"][:3]
        p1, p2 = fx["products"][:2]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p1, 1)
        make_rel(tid, c2, p1, 1)
        make_rel(tid, c2, p2, 1)
        make_rel(tid, c3, p2, 1, active=0)  # inactive: not counted
        r = ok("aggregate", tag_id=tid)
        summary = {int(x["store_id"]): x for x in r["summary"]}
        assert set(summary) == {0, 1}, summary
        for sid in (0, 1):
            s = summary[sid]
            assert int(s["customers"]) == 2 and int(s["products"]) == 2 and int(s["uses"]) == 3, s
            assert int(s["historical_uses"]) == 3 and int(s["popularity"]) == 3, s
        loaded = ok("tag_load", id=tid, check_store=1)
        assert loaded["is_available_in_store"] is True
        assert sorted(int(x) for x in loaded["data"]["visible_in_store_ids"]) == [0, 1]

    def test_aggregate_with_base_popularity(self, fx, name):
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, fx["customers"][0], fx["products"][0], 1)
        r = ok("aggregate", tag_id=tid, store=1, base_popularity=7)
        summary = {int(x["store_id"]): x for x in r["summary"]}
        assert int(summary[1]["base_popularity"]) == 7 and int(summary[1]["uses"]) == 1
