#!/usr/bin/env python3
"""官公需情報ポータルサイトの検索APIから入札公告を集めて data/knyusatsu.sqlite に貯める。

  /usr/bin/python3 scripts/collect.py              # 直近4日ぶん（毎日の更新）
  /usr/bin/python3 scripts/collect.py --days 90    # 初回の取り込み

- 都道府県ごと × 期間で問い合わせる。1回の上限は1,000件なので、上限に当たった区間は日を半分に割って取り直す。
- 利用規約（https://www.kkj.go.jp/s/?tc=1）: API を使っていることを明記してポータルへリンクする／継続的な大量アクセスをしない
  → 1.2秒あけて、1日1回だけ回す。
- 締切日・開札日はほとんどの案件で入っていないので持たない。説明文は元の HTML のかけらが混じるので、文字だけにして短く持つ。
"""
from __future__ import annotations

import argparse
import datetime as dt
import html
import os
import re
import sqlite3
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "knyusatsu.sqlite")
API = "https://www.kkj.go.jp/api/"
UA = "Mozilla/5.0 (knyusatsu; +https://kurage.exbridge.jp/knyusatsu.php/about)"
WAIT = 1.2


def db() -> sqlite3.Connection:
    c = sqlite3.connect(DB)
    c.executescript("""
CREATE TABLE IF NOT EXISTS cases(
  key TEXT PRIMARY KEY, name TEXT, org TEXT, pref_code TEXT, pref TEXT, city_code TEXT, city TEXT,
  issue_date TEXT, category TEXT, procedure TEXT, url TEXT, filetype TEXT, summary TEXT, first_seen TEXT);
CREATE INDEX IF NOT EXISTS cases_org ON cases(org, issue_date);
CREATE INDEX IF NOT EXISTS cases_pref ON cases(pref_code, issue_date);
CREATE INDEX IF NOT EXISTS cases_date ON cases(issue_date);
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
""")
    return c


def clean(desc: str, name: str) -> str:
    """説明文から HTML のかけらと件名の繰り返しを除き、200字に切る"""
    t = html.unescape(desc or "")
    t = re.sub(r"<[^>]*>?", " ", t)
    t = re.sub(r'\b[a-z\-]+="[^"]*"', " ", t)          # bgcolor="#FFFFFF" のような属性のかけら
    t = re.sub(r"[\s　]+", " ", t).strip()
    if name and t.startswith(name):
        t = t[len(name):].strip()
    if CHROME.search(t):   # 発注機関のサイトの枠（JavaScript の案内・本文へスキップ・パンくず等）が混じったものは本文として出さない
        return ""
    return t[:200]


CHROME = re.compile(r"JavaScript|本文へ|スキップ|閲覧支援|文字サイズ|Language|ナビゲーション|ホーム\s*>|>\s*\S+\s*>")


def fetch(lg: int, start: dt.date, end: dt.date) -> tuple[int, list[ET.Element]]:
    q = urllib.parse.urlencode({"LG_Code": f"{lg:02d}", "CFT_Issue_Date": f"{start}/{end}", "Count": 1000})
    raw = urllib.request.urlopen(urllib.request.Request(API + "?" + q, headers={"User-Agent": UA}), timeout=60).read()
    time.sleep(WAIT)
    # 公告本文に XML で使えない制御文字が混じることがある（2026-10-07 に1件で取り込みが止まった）
    txt = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", " ", raw.decode("utf-8", "replace"))
    r = ET.fromstring(txt)
    return int(r.findtext(".//SearchHits") or 0), r.findall(".//SearchResult")


def collect(lg: int, start: dt.date, end: dt.date, c: sqlite3.Connection, now: str) -> int:
    hits, items = fetch(lg, start, end)
    if hits > len(items) and start < end:   # 上限に当たった：区間を半分に割る
        mid = start + (end - start) // 2
        return collect(lg, start, mid, c, now) + collect(lg, mid + dt.timedelta(days=1), end, c, now)
    n = 0
    for i in items:
        g = lambda k: (i.findtext(k) or "").strip()
        name = g("ProjectName")
        if not g("Key") or not name or name == "公示タイトル":
            continue
        row = (g("Key"), name, g("OrganizationName"), f"{lg:02d}", g("PrefectureName"), g("CityCode"), g("CityName"),
               g("CftIssueDate")[:10] or g("Date")[:10], g("Category"), g("ProcedureType"), g("ExternalDocumentURI"),
               g("FileType"), clean(g("ProjectDescription"), name), now)
        n += c.execute("INSERT OR IGNORE INTO cases VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)", row).rowcount
    return n


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=4)
    ap.add_argument("--only", type=int, nargs="*", help="この都道府県コードだけ（取り直し用）")
    ap.add_argument("--reclean", action="store_true", help="取り込み済みの説明文に、今の掃除のきまりを当て直す")
    ap.add_argument("--chunk", type=int, default=10, help="1回に問い合わせる日数")
    a = ap.parse_args()
    c = db()
    if a.reclean:
        n = 0
        for key, sm in c.execute("SELECT key, summary FROM cases").fetchall():
            if sm and CHROME.search(sm):
                c.execute("UPDATE cases SET summary='' WHERE key=?", (key,)); n += 1
        c.commit(); print(f"説明文を消した {n}件"); return
    now = dt.datetime.now().isoformat(timespec="seconds")
    end = dt.date.today()
    start = end - dt.timedelta(days=a.days)
    total = 0
    for lg in (a.only or range(1, 48)):
        s = start
        while s <= end:
            e = min(s + dt.timedelta(days=a.chunk - 1), end)
            try:
                total += collect(lg, s, e, c, now)
            except Exception as ex:   # 1区間の失敗で全体を止めない（次の回に取り直す）
                print(f"  失敗 {lg:02d} {s}〜{e}: {ex!r}"[:200])
            s = e + dt.timedelta(days=1)
        c.commit()
    c.execute("INSERT OR REPLACE INTO meta VALUES('collected_at', ?)", (now,))
    c.execute("INSERT OR REPLACE INTO meta VALUES('latest_issue', (SELECT MAX(issue_date) FROM cases))")
    c.commit()
    print(f"新しい案件 {total}件 ／ 全 {c.execute('SELECT COUNT(*) FROM cases').fetchone()[0]}件")


if __name__ == "__main__":
    main()
