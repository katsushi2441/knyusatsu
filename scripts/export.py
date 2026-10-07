#!/usr/bin/env python3
"""公開用の SQLite（php/knyusatsu_data.sqlite）を組む。heteml の knyusatsu.php がこれだけを読む。

  /usr/bin/python3 scripts/export.py

- 案件は直近 120 日ぶん。発注機関ごとに id（機関名の sha1 の先頭10桁）と件数・最新の公告日を持つ。
- 機関の id は名前から決まるので、毎日組み直しても URL は変わらない。
"""
import hashlib
import os
import sqlite3
import datetime as dt

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "data", "knyusatsu.sqlite")
OUT = os.path.join(ROOT, "php", "knyusatsu_data", "knyusatsu.sqlite")
DAYS = 120


def oid(name: str) -> str:
    return hashlib.sha1(name.encode("utf-8")).hexdigest()[:10]


def main() -> None:
    since = (dt.date.today() - dt.timedelta(days=DAYS)).isoformat()
    tmp = OUT + ".tmp"
    if os.path.exists(tmp):
        os.remove(tmp)
    s = sqlite3.connect(SRC)
    o = sqlite3.connect(tmp)
    o.executescript("""
CREATE TABLE cases(key TEXT PRIMARY KEY, org_id TEXT, name TEXT, org TEXT, pref_code TEXT, city TEXT, issue_date TEXT,
  category TEXT, procedure TEXT, url TEXT, filetype TEXT, summary TEXT);
CREATE TABLE orgs(id TEXT PRIMARY KEY, name TEXT, pref_code TEXT, n INTEGER, latest TEXT);
CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT);
""")
    rows = s.execute("SELECT key,name,org,pref_code,city,issue_date,category,procedure,url,filetype,summary FROM cases WHERE issue_date>=?", (since,)).fetchall()
    o.executemany("INSERT INTO cases VALUES(?,?,?,?,?,?,?,?,?,?,?,?)", [(r[0], oid(r[2]), *r[1:]) for r in rows])
    o.execute("""INSERT INTO orgs SELECT org_id, org, (SELECT pref_code FROM cases c2 WHERE c2.org_id=c.org_id GROUP BY pref_code ORDER BY COUNT(*) DESC LIMIT 1),
                 COUNT(*), MAX(issue_date) FROM cases c GROUP BY org_id""")
    o.executescript("CREATE INDEX c_org ON cases(org_id, issue_date); CREATE INDEX c_pref ON cases(pref_code, issue_date); CREATE INDEX c_date ON cases(issue_date); CREATE INDEX o_pref ON orgs(pref_code);")
    for k, v in s.execute("SELECT k,v FROM meta"):
        o.execute("INSERT INTO meta VALUES(?,?)", (k, v))
    o.execute("INSERT INTO meta VALUES('since', ?)", (since,))
    o.commit()
    o.execute("VACUUM")
    o.close()
    os.replace(tmp, OUT)
    c = sqlite3.connect(OUT)
    print(f"公開用 {c.execute('SELECT COUNT(*) FROM cases').fetchone()[0]}件・機関 {c.execute('SELECT COUNT(*) FROM orgs').fetchone()[0]} → {OUT}（{os.path.getsize(OUT)//1024}KB）")


if __name__ == "__main__":
    main()
