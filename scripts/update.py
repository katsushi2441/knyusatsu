#!/usr/bin/env python3
"""毎日の更新：直近4日ぶんを取り込み → 公開用 SQLite を組む → heteml に FTPS 1接続で置く。

  /usr/bin/python3 scripts/update.py

置き方は「一時名で送ってから rename」。送っている途中のファイルを PHP が読まないようにする。
新しい案件が0件の日は、組み直しも配置もしない（FTP を使わない）。
"""
import ftplib
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REMOTE = "/web/kurage_exbridge_jp/knyusatsu_data"


def env():
    e = {}
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("FTP_") and "=" in ln:
            k, v = ln.rstrip("\n").split("=", 1)
            e[k] = v.strip().strip('"').strip("'")
    return e


def main() -> dict:
    py = "/usr/bin/python3"
    out = subprocess.run([py, os.path.join(ROOT, "scripts", "collect.py")], capture_output=True, text=True, timeout=3600, check=True).stdout
    m = re.search(r"新しい案件 (\d+)件", out)
    new = int(m.group(1)) if m else 0
    res = {"new": new, "fails": out.count("失敗")}
    if new == 0:
        return res
    subprocess.run([py, os.path.join(ROOT, "scripts", "export.py")], capture_output=True, text=True, timeout=600, check=True)
    e = env()
    f = ftplib.FTP_TLS(e["FTP_HOST"], timeout=300)
    f.login(e["FTP_USER"], e["FTP_PASS"])
    f.prot_p()
    with open(os.path.join(ROOT, "php", "knyusatsu_data", "knyusatsu.sqlite"), "rb") as fh:
        f.storbinary(f"STOR {REMOTE}/knyusatsu.sqlite.tmp", fh, blocksize=1 << 18)
    f.rename(f"{REMOTE}/knyusatsu.sqlite.tmp", f"{REMOTE}/knyusatsu.sqlite")
    f.quit()
    res["deployed"] = True
    return res


if __name__ == "__main__":
    print(json.dumps(main(), ensure_ascii=False))
    sys.exit(0)
