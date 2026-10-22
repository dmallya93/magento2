"""All 9 Tag::saveRelation() paths (Project Spec DD10 table) against the real DB + relation.decide logging (DD14)."""
from conftest import (STATUS_APPROVED, STATUS_DISABLED, STATUS_PENDING, db_query, log_entries, make_rel, make_tag, ok)


def rels(tid):
    return db_query("SELECT tag_relation_id, customer_id, product_id, store_id, active FROM tag_relation "
                    "WHERE tag_id=%s ORDER BY tag_relation_id", (tid,))


def decide(res):
    entries = log_entries(res["operation_id"], "relation.decide")
    assert len(entries) == 1, f"expected exactly one relation.decide entry, got {entries}"
    e = entries[0]
    assert e["level"] == "INFO" and e["module"] == "Magento_Tag"
    return e


def assert_decide(res, path, result, change, status_before, tid, c, p, s, inactive=None):
    e = decide(res)
    assert e["decision_path"] == path
    assert e["result_code"] == result
    assert e["persisted_change"] == change
    assert int(e["tag_status_before"]) == status_before
    assert int(e["tag_id"]) == tid and int(e["customer_id"]) == c and int(e["product_id"]) == p and int(e["store_id"]) == s
    if inactive is not None:
        assert e["inactive_links_counted"] is inactive
    return e


class TestApprovedBranch:
    def test_approved_product_untagged_success(self, fx, name):
        c, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c, store_id=1)
        assert r["result"] == "success"
        assert [(x["customer_id"], x["product_id"], x["store_id"], x["active"]) for x in rels(tid)] == [(c, p, 1, 1)]
        assert_decide(r, "approved.product_untagged", "success", "relation_created", 1, tid, c, p, 1, False)

    def test_approved_customer_unlinked_exist(self, fx, name):
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c2, p, 1)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "exist"
        rows = rels(tid)
        assert len(rows) == 2 and (rows[1]["customer_id"], rows[1]["product_id"], rows[1]["active"]) == (c1, p, 1)
        assert_decide(r, "approved.customer_unlinked", "exist", "relation_created", 1, tid, c1, p, 1, False)

    def test_approved_customer_link_inactive_reactivated_exist(self, fx, name):
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c2, p, 1, active=1)
        rid = make_rel(tid, c1, p, 1, active=0)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "exist"
        rows = rels(tid)
        assert len(rows) == 2
        mine = [x for x in rows if x["customer_id"] == c1]
        assert len(mine) == 1 and mine[0]["tag_relation_id"] == rid and mine[0]["active"] == 1
        assert_decide(r, "approved.customer_link_inactive", "exist", "relation_reactivated", 1, tid, c1, p, 1, False)

    def test_approved_customer_link_active_no_change_exist(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c1, p, 1, active=1)
        before = rels(tid)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "exist"
        assert rels(tid) == before
        assert_decide(r, "approved.customer_link_active", "exist", "none", 1, tid, c1, p, 1, False)

    def test_approved_inactive_only_relations_return_exist(self, fx, name):
        """DD10 note: the 'already tagged' check ignores `active`; inactive-only links give exist, not success."""
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c2, p, 1, active=0)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "exist"
        rows = rels(tid)
        assert len(rows) == 2
        assert [x["active"] for x in rows if x["customer_id"] == c1] == [1]
        assert [x["active"] for x in rows if x["customer_id"] == c2] == [0]
        assert_decide(r, "approved.customer_unlinked", "exist", "relation_created", 1, tid, c1, p, 1, True)

    def test_approved_product_tagged_only_in_other_store_is_success(self, fx, name):
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_APPROVED)
        make_rel(tid, c2, p, fx["store2"])
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "success"
        assert_decide(r, "approved.product_untagged", "success", "relation_created", 1, tid, c1, p, 1)


class TestPendingBranch:
    def test_pending_customer_unlinked_new(self, fx, name):
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_PENDING)
        make_rel(tid, c2, p, 1)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "new"
        rows = rels(tid)
        assert len(rows) == 2 and (rows[1]["customer_id"], rows[1]["active"]) == (c1, 1)
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_PENDING
        assert_decide(r, "pending.customer_unlinked", "new", "relation_created", 0, tid, c1, p, 1)

    def test_pending_customer_link_inactive_reactivated_new(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_PENDING)
        rid = make_rel(tid, c1, p, 1, active=0)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "new"
        rows = rels(tid)
        assert len(rows) == 1 and rows[0]["tag_relation_id"] == rid and rows[0]["active"] == 1
        assert_decide(r, "pending.customer_link_inactive", "new", "relation_reactivated", 0, tid, c1, p, 1)

    def test_pending_customer_link_active_no_change_new(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_PENDING)
        make_rel(tid, c1, p, 1, active=1)
        before = rels(tid)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "new"
        assert rels(tid) == before
        assert_decide(r, "pending.customer_link_active", "new", "none", 0, tid, c1, p, 1)


class TestDisabledBranch:
    def test_disabled_customer_already_linked_active_rejected(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_DISABLED)
        make_rel(tid, c1, p, 1, active=1)
        before = rels(tid)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "rejected"
        assert rels(tid) == before
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_DISABLED
        assert_decide(r, "disabled.customer_already_linked", "rejected", "none", -1, tid, c1, p, 1)
        assert log_entries(r["operation_id"], "tag.status.auto_reopened") == []

    def test_disabled_customer_already_linked_inactive_rejected(self, fx, name):
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_DISABLED)
        make_rel(tid, c1, p, 1, active=0)
        before = rels(tid)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "rejected"
        assert rels(tid) == before
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_DISABLED
        assert_decide(r, "disabled.customer_already_linked", "rejected", "none", -1, tid, c1, p, 1)

    def test_disabled_reopen_to_pending_new(self, fx, name):
        c1, c2, p = fx["customers"][0], fx["customers"][1], fx["products"][0]
        tid = make_tag(name(), STATUS_DISABLED, customer=c2, store=1)
        make_rel(tid, c2, p, 1)
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "new"
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_PENDING
        rows = rels(tid)
        assert len(rows) == 2 and (rows[1]["customer_id"], rows[1]["product_id"], rows[1]["active"]) == (c1, p, 1)
        assert_decide(r, "disabled.reopen_to_pending", "new", "tag_reopened_and_relation_created", -1, tid, c1, p, 1)
        reopened = log_entries(r["operation_id"], "tag.status.auto_reopened")
        assert len(reopened) == 1
        e = reopened[0]
        assert e["level"] == "WARNING" and e["scope"] == "global"
        assert int(e["previous_status"]) == -1 and int(e["new_status"]) == 0
        assert int(e["tag_id"]) == tid and int(e["customer_id"]) == c1 and int(e["product_id"]) == p

    def test_disabled_original_tagger_different_product_reopens_globally(self, fx, ns, name):
        """The original tagger tagging a *different* product reopens the tag; the flip is global (all stores)."""
        c1, p1, p2 = fx["customers"][0], fx["products"][0], fx["products"][1]
        s2 = fx["store2"]
        tid = make_tag(name(), STATUS_DISABLED, customer=c1, store=s2)
        make_rel(tid, c1, p1, s2)
        r = ok("save_relation", tag_id=tid, product_id=p2, customer_id=c1, store_id=s2)
        assert r["result"] == "new"
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_PENDING
        assert_decide(r, "disabled.reopen_to_pending", "new", "tag_reopened_and_relation_created", -1, tid, c1, p2, s2)
        # global: loaded in store 1 context, and status-filtered pending collection, the tag is PENDING
        loaded = ok("tag_load", id=tid, store_id=1)
        assert int(loaded["data"]["status"]) == STATUS_PENDING
        col = ok("tag_collection", name_like=f"{ns}%", status=STATUS_PENDING)
        assert tid in [int(i["tag_id"]) for i in col["items"]]
        col_disabled = ok("tag_collection", name_like=f"{ns}%", status=STATUS_DISABLED)
        assert tid not in [int(i["tag_id"]) for i in col_disabled["items"]]

    def test_disabled_same_customer_same_product_other_store_reopens(self, fx, name):
        """Link check is store scoped: a link in store 2 does not block reopening from store 1."""
        c1, p = fx["customers"][0], fx["products"][0]
        tid = make_tag(name(), STATUS_DISABLED)
        make_rel(tid, c1, p, fx["store2"])
        r = ok("save_relation", tag_id=tid, product_id=p, customer_id=c1, store_id=1)
        assert r["result"] == "new"
        assert db_query("SELECT status FROM tag WHERE tag_id=%s", (tid,))[0]["status"] == STATUS_PENDING
        assert_decide(r, "disabled.reopen_to_pending", "new", "tag_reopened_and_relation_created", -1, tid, c1, p, 1)
