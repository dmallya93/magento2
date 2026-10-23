"""Magento\\Tag\\Helper\\Data: extractTags / cleanTags / statuses in the bootstrapped app."""
from conftest import ok


class TestExtractTags:
    def test_whitespace_split(self):
        r = ok("helper_extract", input="red  blue\tgreen\nyellow")
        assert r["clean"] == ["red", "blue", "green", "yellow"]

    def test_single_quoted_multi_word(self):
        r = ok("helper_extract", input="'big red' shoe 'summer sale'")
        assert r["clean"] == ["big red", "shoe", "summer sale"]

    def test_mixed_and_no_dedup(self):
        r = ok("helper_extract", input="foo foo 'bar baz'   qux")
        assert r["clean"] == ["foo", "foo", "bar baz", "qux"]

    def test_empty_and_whitespace_only(self):
        assert ok("helper_extract", input="")["clean"] == []
        assert ok("helper_extract", input="   \t  ")["clean"] == []

    def test_raw_tokens_keep_quotes(self):
        r = ok("helper_extract", input="'a b' c")
        assert "'a b'" in r["raw"] and "c" in r["raw"]


class TestCleanTags:
    def test_trim_and_drop_empty_keep_keys(self):
        r = ok("helper_clean", tags=["  foo ", "'bar'", "", "   ", "'  baz  '"])
        assert r["clean"] == {"0": "foo", "1": "bar", "4": "baz"}

    def test_unicode_nfc_normalization(self):
        decomposed = "café"  # e + combining acute
        r = ok("helper_clean", tags=[decomposed])
        assert r["clean"] == ["café"]


class TestStatuses:
    def test_statuses_arrays(self):
        r = ok("helper_statuses")
        assert r["statuses"] == {"-1": "Disabled", "0": "Pending", "1": "Approved"}
        assert r["options"] == [
            {"label": "Disabled", "value": -1},
            {"label": "Pending", "value": 0},
            {"label": "Approved", "value": 1},
        ]
