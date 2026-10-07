#!/usr/bin/env python3
"""官公需情報ポータルAPIの中身を、都道府県ごとに直近30日ぶん取って数える（調査用）。
  /usr/bin/python3 scripts/probe.py → outputs/probe_*.xml と集計
利用規約: API を使っていることを明記・ポータルへリンク・継続的な大量アクセスは禁止 → 1秒以上あけて47回だけ"""
import collections, datetime as dt, os, time, urllib.parse, urllib.request, xml.etree.ElementTree as ET
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "outputs", "probe")
os.makedirs(OUT, exist_ok=True)
end = dt.date.today(); start = end - dt.timedelta(days=30)
tot = 0; org = collections.Counter(); cat = collections.Counter(); proc = collections.Counter(); placeholder = 0; capped = []
for lg in range(1, 48):
    q = urllib.parse.urlencode({"LG_Code": lg, "CFT_Issue_Date": f"{start}/{end}", "Count": 1000})
    raw = urllib.request.urlopen(urllib.request.Request("https://www.kkj.go.jp/api/?" + q, headers={"User-Agent": "Mozilla/5.0 (knyusatsu probe)"}), timeout=60).read()
    open(f"{OUT}/lg{lg:02d}.xml", "wb").write(raw)
    r = ET.fromstring(raw); hits = int(r.findtext(".//SearchHits") or 0); items = r.findall(".//SearchResult")
    if hits > len(items): capped.append((lg, hits))
    tot += hits
    for i in items:
        org[i.findtext("OrganizationName") or "?"] += 1; cat[i.findtext("Category") or "なし"] += 1; proc[i.findtext("ProcedureType") or "なし"] += 1
        if (i.findtext("ProjectName") or "") in ("公示タイトル", "") or (i.findtext("ProjectName") or "").endswith(".pdf"): placeholder += 1
    time.sleep(1.2)
print("30日の件数", tot, "／1000件で打ち切られた県", capped)
print("機関数", len(org)); print("上位", org.most_common(25))
print("分類", cat.most_common()); print("公示種別", proc.most_common(6)); print("件名が無い・PDF名", placeholder)
