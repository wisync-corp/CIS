# CIS local — Windows PowerShell. Não envia dados para servidores externos.
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path (Join-Path $PSScriptRoot '..'))
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Instale e abra Docker Desktop antes de continuar.' }
docker info *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop não está em execução.' }
if (-not (Test-Path 'backend/.env')) {
  Copy-Item 'backend/.env.example' 'backend/.env'
  Write-Host 'Criado backend/.env local.'
}
$envPath = (Resolve-Path 'backend/.env').Path
$content = [System.IO.File]::ReadAllText($envPath)
if ($content -match '(?m)^APP_KEY=\s*$') {
  $key = (& docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32));')
  if ($LASTEXITCODE -ne 0 -or -not $key.StartsWith('base64:')) { throw 'Falha ao criar a chave de aplicação.' }
  $content = [regex]::Replace($content, '(?m)^APP_KEY=\s*$', 'APP_KEY=' + $key)
  [System.IO.File]::WriteAllText($envPath, $content)
  Write-Host 'Chave de aplicação gerada no .env privado.'
}
docker compose up --build -d
if ($LASTEXITCODE -ne 0) { throw 'Falha ao iniciar a API. Consulte: docker compose logs cis-api' }
Write-Host ''
Write-Host 'API CIS local: http://127.0.0.1:8000/api'
Write-Host 'Saúde Laravel: http://127.0.0.1:8000/up'
Write-Host 'Para criar a primeira conta: docker compose exec cis-api php artisan cis:bootstrap-admin admin@exemplo.test'
Write-Host 'Para parar: docker compose down'
