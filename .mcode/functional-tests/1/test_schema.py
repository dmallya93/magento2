"""Module registration + declarative schema of Magento_Tag vs the M1 install-1.6.0.0.php script."""
import subprocess

from conftest import REPO_ROOT, db_query, ok

DBNAME = "magento"


def columns(table):
    rows = db_query(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION", (table,))
    return {r["COLUMN_NAME"]: (r["COLUMN_TYPE"], r["IS_NULLABLE"], r["COLUMN_DEFAULT"], r["EXTRA"]) for r in rows}


def indexes(table):
    rows = db_query(
        "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols "
        "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s GROUP BY INDEX_NAME, NON_UNIQUE",
        (table,))
    return {r["cols"]: int(r["NON_UNIQUE"]) for r in rows}


def fks(table):
    rows = db_query(
        "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE "
        "FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
        "ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME "
        "WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=%s AND k.REFERENCED_TABLE_NAME IS NOT NULL", (table,))
    return {r["COLUMN_NAME"]: (r["REFERENCED_TABLE_NAME"], r["REFERENCED_COLUMN_NAME"], r["DELETE_RULE"]) for r in rows}


class TestModuleRegistration:
    def test_module_enabled_cli(self):
        p = subprocess.run(["php", "bin/magento", "module:status", "Magento_Tag"], cwd=str(REPO_ROOT),
                           capture_output=True, text=True, timeout=300)
        assert p.returncode == 0, p.stderr
        assert "Module is enabled" in p.stdout, p.stdout

    def test_module_loaded_with_sequence_and_constants(self):
        r = ok("module_status")
        assert r["enabled"] is True
        assert set(r["module"]["sequence"]) == {"Magento_Catalog", "Magento_Customer", "Magento_Store", "Magento_Backend"}
        assert r["constants"] == {
            "STATUS_DISABLED": -1, "STATUS_PENDING": 0, "STATUS_APPROVED": 1,
            "ADD_STATUS_SUCCESS": "success", "ADD_STATUS_NEW": "new",
            "ADD_STATUS_EXIST": "exist", "ADD_STATUS_REJECTED": "rejected",
        }


class TestSchema:
    def test_tag_table_columns(self):
        c = columns("tag")
        assert list(c) == ["tag_id", "name", "status", "first_customer_id", "first_store_id"]
        assert c["tag_id"][0] == "int unsigned" and c["tag_id"][1] == "NO" and "auto_increment" in c["tag_id"][3]
        assert c["name"][0] == "varchar(255)" and c["name"][1] == "YES"
        assert c["status"][0] == "smallint" and c["status"][1] == "NO" and str(c["status"][2]) == "0"
        assert c["first_customer_id"][:2] == ("int unsigned", "YES")
        assert c["first_store_id"][:2] == ("smallint unsigned", "YES")

    def test_tag_has_no_unique_index_on_name(self):
        idx = indexes("tag")
        assert idx.get("tag_id") == 0  # primary
        assert "name" not in idx, f"tag.name must not be indexed uniquely (DD10): {idx}"
        assert all(not (cols.split(",") == ["name"] and nu == 0) for cols, nu in idx.items())

    def test_tag_foreign_keys_set_null(self):
        assert fks("tag") == {
            "first_customer_id": ("customer_entity", "entity_id", "SET NULL"),
            "first_store_id": ("store", "store_id", "SET NULL"),
        }

    def test_tag_relation_columns(self):
        c = columns("tag_relation")
        assert list(c) == ["tag_relation_id", "tag_id", "customer_id", "product_id", "store_id", "active", "created_at"]
        assert c["tag_relation_id"][0] == "int unsigned" and "auto_increment" in c["tag_relation_id"][3]
        assert c["tag_id"][:3] == ("int unsigned", "NO", "0")
        assert c["customer_id"][:2] == ("int unsigned", "YES")
        assert c["product_id"][:3] == ("int unsigned", "NO", "0")
        assert c["store_id"][:3] == ("smallint unsigned", "NO", "1")
        assert c["active"][:3] == ("smallint unsigned", "NO", "1")
        assert c["created_at"][0] == "timestamp" and c["created_at"][1] == "YES"

    def test_tag_relation_indexes(self):
        idx = indexes("tag_relation")
        assert idx.get("tag_id,customer_id,product_id,store_id") == 0, idx
        for col in ("product_id", "tag_id", "customer_id", "store_id"):
            assert col in idx, f"missing index on {col}: {idx}"

    def test_tag_relation_foreign_keys_cascade(self):
        assert fks("tag_relation") == {
            "tag_id": ("tag", "tag_id", "CASCADE"),
            "customer_id": ("customer_entity", "entity_id", "CASCADE"),
            "product_id": ("catalog_product_entity", "entity_id", "CASCADE"),
            "store_id": ("store", "store_id", "CASCADE"),
        }

    def test_tag_summary_schema(self):
        c = columns("tag_summary")
        assert list(c) == ["tag_id", "store_id", "customers", "products", "uses", "historical_uses",
                           "popularity", "base_popularity"]
        assert c["tag_id"][:3] == ("int unsigned", "NO", "0")
        assert c["store_id"][:3] == ("smallint unsigned", "NO", "0")
        for col in ("customers", "products", "uses", "historical_uses", "popularity", "base_popularity"):
            assert c[col][:3] == ("int unsigned", "NO", "0"), (col, c[col])
        idx = indexes("tag_summary")
        assert idx.get("tag_id,store_id") == 0 and "store_id" in idx and "tag_id" in idx, idx
        assert fks("tag_summary") == {
            "tag_id": ("tag", "tag_id", "CASCADE"),
            "store_id": ("store", "store_id", "CASCADE"),
        }

    def test_tag_properties_schema(self):
        c = columns("tag_properties")
        assert list(c) == ["tag_id", "store_id", "base_popularity"]
        assert c["tag_id"][:3] == ("int unsigned", "NO", "0")
        assert c["store_id"][:3] == ("smallint unsigned", "NO", "0")
        assert c["base_popularity"][:3] == ("int unsigned", "NO", "0")
        idx = indexes("tag_properties")
        assert idx.get("tag_id,store_id") == 0 and "store_id" in idx, idx
        assert fks("tag_properties") == {
            "tag_id": ("tag", "tag_id", "CASCADE"),
            "store_id": ("store", "store_id", "CASCADE"),
        }
