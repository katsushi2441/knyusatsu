# Kurage 入札情報ナビ（knyusatsu）

中小企業庁「官公需情報ポータルサイト」の検索APIで集めた入札公告を、発注機関ごと・都道府県ごとに一覧にし、キーワード・分類で探せるようにする PHP＋SQLite です。各案件は元の公告へリンクします。

公開: https://kurage.exbridge.jp/knyusatsu.php/

## 構成

- `scripts/collect.py` … 検索APIから都道府県×期間で取り込み、`data/knyusatsu.sqlite` に貯める（1,000件の上限に当たった区間は日を割って取り直す。1.2秒あけて1日1回）
- `scripts/export.py` … 直近120日ぶんを公開用の `php/knyusatsu_data/knyusatsu.sqlite` に組む（発注機関の id は機関名の sha1 先頭10桁）
- `scripts/update.py` … 毎日の更新（取り込み→組み直し→heteml に FTPS 1接続・一時名で送って rename）
- `php/knyusatsu.php` … 公開画面（検索・都道府県・発注機関・データについて・sitemap/robots/llms）

## データの範囲

- ポータルに登録された公告だけ（発注機関の公告のすべてではない。名古屋市はポータル未登録）
- 締切・開札日はポータルのデータにほとんど入っていないので持たない。入札結果も持たない
- 説明文は元のページの HTML のかけらを除き、サイトの枠（JavaScript の案内・本文へスキップ・パンくず）が混じるものは出さない

## 利用規約

官公需情報ポータルサイトの利用規約（https://www.kkj.go.jp/s/?tc=1 ）に従い、API を使っていることを明記してポータルへリンクし、継続的な大量アクセスをしない。

## ライセンス

MIT
