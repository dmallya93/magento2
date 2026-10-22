"""ResourceModel\\Tag name rules: merge-on-save, multibyte 255-char truncation, duplicate-name detection (+ logs)."""
from conftest import STATUS_APPROVED, STATUS_PENDING, db_query, harness, log_entries, make_tag, ok


class TestMergeOnSave:
    def test_new_approved_tag_merges_into_pending(self, fx, name):
        n = name()
        pending_id = make_tag(n, STATUS_PENDING, customer=fx["customers"][0], store=1)
        r = ok("tag_save", data={"name": n, "status": STATUS_APPROVED, "admin_user_id": 1})
        assert r["tag_id"] == pending_id
        rows = db_query("SELECT tag_id, status, first_customer_id FROM tag WHERE name=%s", (n,))
        assert len(rows) == 1
        assert rows[0]["tag_id"] == pending_id and rows[0]["status"] == STATUS_APPROVED
        merges = log_entries(r["operation_id"], "tag.merge")
        assert len(merges) == 1
        m = merges[0]
        assert m["level"] == "INFO" and int(m["merged_into_tag_id"]) == pending_id and m["tag_name"] == n
        assert int(m["previous_status"]) == STATUS_PENDING and int(m["new_status"]) == STATUS_APPROVED
        # a merge is not a duplicate
        assert log_entries(r["operation_id"], "tag.name.duplicate_detected") == []

    def test_new_approved_tag_does_not_merge_into_approved(self, fx, name):
        n = name()
        make_tag(n, STATUS_APPROVED)
        r = harness("tag_save", data={"name": n, "status": STATUS_APPROVED})
        assert r.returncode == 2 and "AlreadyExists" in r["exception_class"], r.data
        assert log_entries(r["operation_id"], "tag.merge") == []
        assert db_query("SELECT COUNT(*) c FROM tag WHERE name=%s", (n,))[0]["c"] == 1

    def test_existing_tag_update_to_approved_is_not_a_merge(self, fx, name):
        n = name()
        tid = make_tag(n, STATUS_PENDING)
        r = ok("tag_save", id=tid, data={"status": STATUS_APPROVED})
        assert r["tag_id"] == tid
        assert log_entries(r["operation_id"], "tag.merge") == []


class TestTruncation:
    def test_multibyte_name_truncated_to_255_chars_on_save(self, fx, ns):
        prefix = f"{ns}_trunc_"
        long_name = prefix + "é漢" * 200  # multibyte (2- and 3-byte UTF-8) chars, > 255 chars
        r = ok("tag_save", data={"name": long_name, "status": STATUS_PENDING})
        stored = db_query("SELECT name, CHAR_LENGTH(name) l FROM tag WHERE tag_id=%s", (r["tag_id"],))[0]
        assert stored["l"] == 255
        assert stored["name"] == long_name[:255]
        logs = log_entries(r["operation_id"], "tag.name.truncated")
        assert len(logs) == 1
        e = logs[0]
        assert e["level"] == "WARNING" and e["call_site"] == "before_save"
        assert int(e["original_length"]) == len(long_name) and int(e["stored_length"]) == 255

    def test_load_by_name_truncates_before_lookup(self, fx, ns):
        prefix = f"{ns}_lbn_"
        long_name = prefix + "ü中" * 200
        tid = ok("tag_save", data={"name": long_name, "status": STATUS_APPROVED})["tag_id"]
        r = ok("tag_load_by_name", name=long_name + "EXTRA_SUFFIX_IGNORED")
        assert r["tag_id"] == tid
        logs = log_entries(r["operation_id"], "tag.name.truncated")
        assert len(logs) == 1
        e = logs[0]
        assert e["call_site"] == "load_by_name" and int(e["stored_length"]) == 255
        assert int(e["original_length"]) == len(long_name) + len("EXTRA_SUFFIX_IGNORED")
        assert int(e["tag_id"]) == tid

    def test_exactly_255_chars_not_truncated(self, fx, ns):
        n = (f"{ns}_x255_" + "é" * 300)[:255]
        r = ok("tag_save", data={"name": n, "status": STATUS_PENDING})
        assert db_query("SELECT name FROM tag WHERE tag_id=%s", (r["tag_id"],))[0]["name"] == n
        assert log_entries(r["operation_id"], "tag.name.truncated") == []


class TestDuplicateDetection:
    def test_duplicate_name_detected_without_blocking_save(self, fx, name):
        """Concurrent-submission race (M1): the second row is still saved; a WARNING lists the other ids."""
        n = name()
        first = make_tag(n, STATUS_PENDING)
        r = ok("tag_save", data={"name": n, "status": STATUS_PENDING}, simulate_race=True)
        second = r["tag_id"]
        assert second != first
        ids = sorted(x["tag_id"] for x in db_query("SELECT tag_id FROM tag WHERE name=%s", (n,)))
        assert ids == sorted([first, second])
        logs = log_entries(r["operation_id"], "tag.name.duplicate_detected")
        assert len(logs) == 1
        e = logs[0]
        assert e["level"] == "WARNING" and int(e["tag_id"]) == second and e["tag_name"] == n
        assert [int(x) for x in e["duplicate_tag_ids"]] == [first]

    def test_no_duplicate_warning_for_unique_new_tag(self, fx, name):
        r = ok("tag_save", data={"name": name(), "status": STATUS_PENDING})
        assert log_entries(r["operation_id"], "tag.name.duplicate_detected") == []
