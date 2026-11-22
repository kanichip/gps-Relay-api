# GPS Relay API

**PHP + SQLiteによるGPS測位データ中継API**

スマートフォンで取得したGPS測位データを、インターネット経由でPCへ安全に受け渡すための軽量APIサーバーです。

6桁のワンタイムキーを使って測位データを受け渡します。登録されたデータは10分間有効で、取得成功後は自動的に削除されます。

PHP標準機能とSQLiteだけで動作し、常駐プロセスや外部フレームワークは必要ありません。

## システム構成

```text
NCP Finder (iPhone / Android)
         │
         │ GPS測位
         │ HTTPS POST
         ▼
    gps.ballota.jp
    ┌──────────────────┐
    │ GPS Relay API    │
    │ PHP + SQLite     │
    │                  │
    │ 6桁キー発行      │
    │ 有効期限10分     │
    │ 取得後自動削除   │
    └──────────────────┘
         │
         │ HTTPS POST
         │ 6桁キーで取得
         ▼
    GPS2STELLA
    (Windows / Python)
         │
         │ Remote Control API
         ▼
    Stellarium
    観測地点を自動設定
```

スマートフォンとPCが同じLAN上にある必要はありません。双方がインターネットに接続できれば利用できます。

## 主な機能

- **GPS測位データの登録**：緯度・経度・標高をJSON形式で受信
- **6桁キーの自動発行**：サーバー側でランダムに生成
- **複数端末対応**：キーごとに独立した測位データを管理
- **有効期限10分**：期限切れデータは取得不可
- **ワンタイム取得**：取得成功後にデータを削除
- **SQLiteによる保存**：異なるPHPプロセス間でデータを共有
- **アクセス回数制限**：IPアドレス単位でリクエスト数を制限
- **CORS対応**：NCP FinderからのHTTPSアクセスに対応

## 動作環境

| 項目 | 内容 |
|---|---|
| Webサーバー | PHPを実行できるHTTPSサーバー |
| PHP | 8.3.37で動作確認 |
| データベース | SQLite（PDO SQLite） |
| 通信方式 | HTTPS / JSON |
| 常駐プロセス | 不要 |
| 外部PHPライブラリ | 不要 |
| 動作確認環境 | WADAX / Plesk |

## API仕様

エンドポイント：

`https://gps.ballota.jp/api.php`

### 1. GPS測位データ登録

**POST `/api.php?action=send`**

リクエスト：

```json
{
  "latitude": 34.250123,
  "longitude": 132.560456,
  "altitude": 85.2
}
```

レスポンス例：

```json
{
  "status": "ok",
  "code": "507582",
  "expires_in": 600
}
```

| 項目 | 内容 |
|---|---|
| status | 処理結果 |
| code | 発行された6桁キー |
| expires_in | 有効期間（秒） |

キーは毎回ランダムに発行され、登録中のキーとの重複を防止します。

### 2. GPS測位データ取得

**POST `/api.php?action=receive`**

リクエスト：

```json
{
  "code": "507582"
}
```

レスポンス例：

```json
{
  "status": "ok",
  "latitude": 34.250123,
  "longitude": 132.560456,
  "altitude": 85.2
}
```

正常取得すると、対象レコードはSQLiteから削除されます。

同じキーでの再取得はできません。

### 3. エラーレスポンス

| HTTPステータス | 内容 |
|---|---|
| 400 | 不正なリクエストまたは座標 |
| 403 | 許可されていないOrigin |
| 404 | キーが存在しない、期限切れ、または取得済み |
| 405 | 許可されていないHTTPメソッド |
| 429 | アクセス回数制限 |
| 500 | サーバー内部エラー |

## Windows PowerShellでの動作確認

### GPSデータ送信

```powershell
$body = @{
    latitude  = 13.756331
    longitude = 100.501762
    altitude  = 5.0
} | ConvertTo-Json

$result = Invoke-RestMethod `
    -Uri "https://gps.ballota.jp/api.php?action=send" `
    -Method Post `
    -ContentType "application/json" `
    -Body $body

$result
```

上記はタイ・バンコク付近のダミー座標です。

### GPSデータ取得

```powershell
$body = @{
    code = "発行された6桁キー"
} | ConvertTo-Json

Invoke-RestMethod `
    -Uri "https://gps.ballota.jp/api.php?action=receive" `
    -Method Post `
    -ContentType "application/json" `
    -Body $body
```

## サーバーへの設置

WADAX / Pleskでの構成例：

```text
/var/www/vhosts/ballota.jp/
│
├── gps.ballota.jp/
│   └── api.php
│
└── gps_data/
    └── gps.sqlite
```

`gps.sqlite` はPHPが初回アクセス時に自動生成します。

**重要：SQLiteファイルはWeb公開ディレクトリの外に配置してください。**

`gps_data` にはPHPプロセスからの書き込み権限が必要です。

本番運用ではHTTPSを有効にし、PHPの `display_errors` を無効にしてください。

## セキュリティとデータ管理

- キーは暗号学的に安全な乱数で生成
- 6桁キーは登録データごとに発行
- 登録後600秒で失効
- 取得成功後は即時削除
- 期限切れデータは後続リクエスト時に削除
- IPアドレスごとのアクセス回数制限を実装
- SQLiteで同時アクセス時の整合性を管理
- CORS許可対象はNCP Finderの配信元に限定

**注意事項：**

6桁キーは簡易的な一時アクセスコードであり、強固なユーザー認証ではありません。インターネットに公開する場合は、アクセス回数制限、ログ監視、必要に応じた追加の認証・保護が推奨されます。

また、GPS座標は個人の所在地を示す可能性があるため、取り扱いには注意してください。

SQLiteファイルや実際のGPS測位データをGitHubにコミットしないでください。

## 関連プロジェクト

### NCP Finder

スマートフォンのセンサーで天の北極方向を案内するWebアプリ。

GPSを測位して本APIへ送信し、6桁キーを取得します。

### GPS2STELLA

Windows用Pythonツール。

本APIから6桁キーでGPS座標を取得し、StellariumのRemote Control APIを通じて観測地点を自動変更します。

Stellarium 26.3で動作確認済み。25.4にも対応する設計です。

## 動作確認状況

| テスト項目 | 結果 |
|---|---|
| PHP 8.3での実行 | OK |
| SQLiteへのGPS登録 | OK |
| 6桁キー発行 | OK |
| キー指定による取得 | OK |
| 取得後の再取得防止 | OK |
| GPS2STELLAへの座標転送 | OK |
| Stellarium観測地点変更 | OK |
| NCP Finderからの実測GPS送信 | OK |

## ライセンス

ライセンス未設定。

---

GPS Relay APIは、Seestarなどのスマート望遠鏡を遠征先で利用するときの観測地点設定を簡単にするために開発しました。
