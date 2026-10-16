<#
  Выкладка лендинга на хостинг по FTPS (явный TLS, порт 21).
  Пароль FTP спрашивается при запуске и никуда не сохраняется.

  Запуск из папки проекта:
    powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1            # выложить
    powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1 -DryRun    # только показать список файлов
#>
param(
  [string]$Server = "ftp.artro-flex.com",
  [string]$User   = "it3.artroflex@it3.artro-flex.com",
  [string]$RemoteRoot = "",   # FTP-пользователь обычно уже смотрит в /home/artrofle/it3.artro-flex
  [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# Что выкладываем: всё из git + серверный config.php. Внутренние документы не публикуем.
$files = @(git ls-files) | Where-Object {
  $_ -notmatch '^(docs/|deploy/|README\.md$|\.gitignore$|_data/reviews\.json$)'
}
if (Test-Path "api/config.php") { $files += "api/config.php" } else { throw "Нет api/config.php — скопируйте api/config.sample.php и заполните." }

# Отзывы, отредактированные на сервере, не затираем: _data/reviews.json грузим только если его там ещё нет
$dataFile = "_data/reviews.json"

Write-Host "Файлов к выкладке: $($files.Count)"
if ($DryRun) { $files | ForEach-Object { Write-Host "  $_" }; Write-Host "  ($dataFile — только при первой выкладке)"; exit 0 }

$sec = Read-Host "Пароль FTP для $User" -AsSecureString
$pass = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))

# Логин и пароль передаём через временный netrc, а не в командной строке
$netrc = Join-Path $env:TEMP ("lp-netrc-" + [guid]::NewGuid().ToString("N"))
Set-Content -Path $netrc -Value "machine $Server login $User password $pass" -Encoding ascii -NoNewline
$pass = $null

$base = "ftp://$Server/" + ($(if ($RemoteRoot) { $RemoteRoot.Trim('/') + '/' } else { '' }))
$common = @("--ssl-reqd", "--netrc-file", $netrc, "--ftp-create-dirs", "--silent", "--show-error", "--retry", "2")

try {
  # Проверка входа
  & curl.exe @common --list-only "$base" | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "Не удалось войти на FTP (код curl $LASTEXITCODE). Проверьте пароль." }

  $remoteData = & curl.exe @common --list-only "${base}_data/" 2>$null
  if (-not ($remoteData -match '^reviews\.json$')) { $files += $dataFile } else { Write-Host "  $dataFile уже есть на сервере — пропускаю" }

  $i = 0; $failed = @()
  foreach ($f in $files) {
    $i++
    Write-Host ("[{0}/{1}] {2}" -f $i, $files.Count, $f)
    & curl.exe @common -T "$f" "$base$f"
    if ($LASTEXITCODE -ne 0) { $failed += $f }
  }
  if ($failed.Count) { Write-Host "`nНе загрузились:" -ForegroundColor Red; $failed | ForEach-Object { Write-Host "  $_" }; exit 1 }
  Write-Host "`nГотово. Проверьте: https://it3.artro-flex.com/" -ForegroundColor Green
}
finally {
  Remove-Item $netrc -Force -ErrorAction SilentlyContinue
}
