"""Tag, Popular, Product and Customer collections loading against real data with their filters."""
import pytest

from conftest import STATUS_APPROVED, STATUS_DISABLED, STATUS_PENDING, log_entries, make_rel, make_tag, ok


@pytest.fixture(scope="module")
def data(fx, ns):
    """approved A (c1-p1 s1, c2-p1 s1, c2-p2 s2 inactive), pending P (c1-p2 s1), disabled D (c3-p1 s2)."""
    c1, c2, c3 = fx["customers"][:3]
    p1, p2 = fx["products"][:2]
    s2 = fx["store2"]
    a = make_tag(f"{ns}_col_approved", STATUS_APPROVED, customer=c1, store=1)
    p = make_tag(f"{ns}_col_pending", STATUS_PENDING, customer=c1, store=1)
    d = make_tag(f"{ns}_col_disabled", STATUS_DISABLED, customer=c3, store=s2)
    make_rel(a, c1, p1, 1)
    make_rel(a, c2, p1, 1)
    make_rel(a, c2, p2, s2, active=0)
    make_rel(p, c1, p2, 1)
    make_rel(d, c3, p1, s2)
    for t in (a, p, d):
        ok("aggregate", tag_id=t)
    return dict(a=a, p=p, d=d, c1=c1, c2=c2, c3=c3, p1=p1, p2=p2, s2=s2, ns=ns)


def ids(res, key="tag_id"):
    return sorted(int(i[key]) for i in res["items"])


class TestTagCollection:
    def test_status_filter(self, data):
        like = data["ns"] + "_col_%"
        assert ids(ok("tag_collection", name_like=like)) == sorted([data["a"], data["p"], data["d"]])
        assert ids(ok("tag_collection", name_like=like, status=STATUS_APPROVED)) == [data["a"]]
        assert ids(ok("tag_collection", name_like=like, status=STATUS_PENDING)) == [data["p"]]
        assert ids(ok("tag_collection", name_like=like, status=STATUS_DISABLED)) == [data["d"]]

    def test_product_customer_active_filters(self, data):
        like = data["ns"] + "_col_%"
        r = ok("tag_collection", name_like=like, join_rel=True, product_id=data["p1"], tag_group=True)
        assert ids(r) == sorted([data["a"], data["d"]])
        r = ok("tag_collection", name_like=like, join_rel=True, customer_id=data["c1"], tag_group=True)
        assert ids(r) == sorted([data["a"], data["p"]])
        r = ok("tag_collection", name_like=like, join_rel=True, customer_id=data["c2"], active=True, tag_group=True)
        assert ids(r) == [data["a"]]
        r = ok("tag_collection", name_like=like, join_rel=True, product_id=data["p2"], active=True, tag_group=True)
        assert ids(r) == [data["p"]]

    def test_store_filter_and_summary(self, data):
        like = data["ns"] + "_col_%"
        r = ok("tag_collection", name_like=like, store_filter=data["s2"])
        assert ids(r) == [data["d"]]
        r = ok("tag_collection", name_like=like, store_filter=1)
        assert ids(r) == sorted([data["a"], data["p"]])
        r = ok("tag_collection", name_like=like, summary_store=1, status=STATUS_APPROVED)
        assert len(r["items"]) == 1
        item = r["items"][0]
        assert int(item["tag_id"]) == data["a"] and int(item["popularity"]) == 2 and int(item["customers"]) == 2
        assert int(item["products"]) == 1

    def test_stores_visibility(self, data):
        r = ok("tag_collection", name_like=data["ns"] + "_col_%", stores_visibility=True)
        stores = {int(i["tag_id"]): sorted(int(s) for s in i["stores"]) for i in r["items"]}
        assert stores[data["a"]] == [0, 1] and stores[data["d"]] == [0, data["s2"]]

    def test_collection_load_logged(self, data):
        r = ok("tag_collection", name_like=data["ns"] + "_col_%", status=STATUS_APPROVED)
        logs = log_entries(r["operation_id"], "collection.load")
        assert logs, "expected a collection.load entry"
        e = logs[-1]
        assert e["level"] == "DEBUG" and "Tag\\Collection" in e["collection_class"]
        assert int(e["result_count"]) == 1 and "duration_ms" in e


class TestPopularCollection:
    def test_popular_only_approved_with_products(self, data):
        r = ok("popular_collection", store_id=1, name_prefix=data["ns"] + "_col_")
        assert [int(i["tag_id"]) for i in r["items"]] == [data["a"]]
        assert int(r["items"][0]["popularity"]) == 2
        r0 = ok("popular_collection", store_id=0, name_prefix=data["ns"] + "_col_")
        assert [int(i["tag_id"]) for i in r0["items"]] == [data["a"]]
        rs2 = ok("popular_collection", store_id=data["s2"], name_prefix=data["ns"] + "_col_")
        assert rs2["items"] == []  # disabled tag excluded, approved tag has no active s2 relation


class TestProductCollection:
    def test_products_by_tag(self, data):
        r = ok("product_collection", tag_id=data["a"])
        assert ids(r, "entity_id") == sorted([data["p1"], data["p2"]])
        names = {int(i["entity_id"]): i for i in r["items"]}
        assert names[data["p1"]]["name"].startswith("FT ") and names[data["p1"]]["tag_name"] == data["ns"] + "_col_approved"
        assert int(names[data["p1"]]["tag_status"]) == STATUS_APPROVED

    def test_products_by_tag_active_store_customer(self, data):
        assert ids(ok("product_collection", tag_id=data["a"], active=True), "entity_id") == [data["p1"]]
        assert ids(ok("product_collection", tag_id=data["a"], store=data["s2"]), "entity_id") == [data["p2"]]
        assert ids(ok("product_collection", customer_id=data["c1"], status=STATUS_PENDING), "entity_id") == [data["p2"]]

    def test_products_popularity(self, data):
        r = ok("product_collection", tag_id=data["a"], store=1, popularity_tag=data["a"], popularity_store=1)
        items = {int(i["entity_id"]): i for i in r["items"]}
        assert int(items[data["p1"]]["popularity"]) == 2


class TestCustomerCollection:
    def test_customers_by_tag(self, data):
        r = ok("customer_collection", tag_id=data["a"])
        assert r["count"] == 3
        assert sorted({int(i["entity_id"]) for i in r["items"]}) == sorted([data["c1"], data["c2"]])
        assert all(i["email"].startswith(data["ns"]) for i in r["items"])

    def test_customers_by_tag_product_store_status(self, data):
        r = ok("customer_collection", tag_id=data["a"], product_id=data["p1"], store=1)
        assert sorted(int(i["entity_id"]) for i in r["items"]) == sorted([data["c1"], data["c2"]])
        r = ok("customer_collection", status=STATUS_DISABLED, customer_id=data["c3"])
        assert [int(i["tag_id"]) for i in r["items"]] == [data["d"]]
        assert int(r["items"][0]["added_in"]) == data["s2"]

    def test_customers_name_filter_and_product_name(self, data):
        r = ok("customer_collection", name=data["ns"] + "_col_pending", product_name=True)
        assert len(r["items"]) == 1
        i = r["items"][0]
        assert int(i["entity_id"]) == data["c1"] and int(i["product_id"]) == data["p2"]
        assert i["product"].startswith("FT ") and i["product_sku"] == data["ns"] + "_p2"
