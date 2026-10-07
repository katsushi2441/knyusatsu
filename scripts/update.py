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
# 置き先（FTP の接続先と、サーバー上のデータのフォルダ）は環境変数か、このリポジトリ直下の .env で渡す:
#   FTP_HOST / FTP_USER / FTP_PASS / REMOTE_DIR（例 /web/example_com/<製品>_data）。ENV_FILE で .env の場所を変えられる
REMOTE = os.environ.get("REMOTE_DIR", "")


def env():
    e = {k: v for k, v in os.environ.items() if k.startswith("FTP_") or k == "REMOTE_DIR"}
    path = os.environ.get("ENV_FILE", os.path.join(ROOT, ".env"))
    if os.path.exists(path):
        for ln in open(path, encoding="utf-8"):
            if (ln.startswith("FTP_") or ln.startswith("REMOTE_DIR")) and "=" in ln:
                k, v = ln.rstrip("\n").split("=", 1)
                e.setdefault(k, v.strip().strip('"').strip("'"))
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
    rd = e.get("REMOTE_DIR") or REMOTE
    if not rd:
        raise SystemExit("REMOTE_DIR（サーバー上のデータのフォルダ）を環境変数か .env で指定してください")
    f = ftplib.FTP_TLS(e["FTP_HOST"], timeout=300)
    f.login(e["FTP_USER"], e["FTP_PASS"])
    f.prot_p()
    with open(os.path.join(ROOT, "php", "knyusatsu_data", "knyusatsu.sqlite"), "rb") as fh:
        f.storbinary(f"STOR {rd}/knyusatsu.sqlite.tmp", fh, blocksize=1 << 18)
    f.rename(f"{rd}/knyusatsu.sqlite.tmp", f"{rd}/knyusatsu.sqlite")
    f.quit()
    res["deployed"] = True
    return res


if __name__ == "__main__":
    print(json.dumps(main(), ensure_ascii=False))
    sys.exit(0)
