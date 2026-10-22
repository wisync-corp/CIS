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
  # Gerar 32 bytes criptograficamente seguros usando .NET, sem depender do comando PHP/Docker.
  $bytes = New-Object byte[] 32
  $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
  try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
  $key = 'base64:' + [Convert]::ToBase64String($bytes)
  $content = [regex]::Replace($content, '(?m)^APP_KEY=\s*$', 'APP_KEY=' + $key)
  [System.IO.File]::WriteAllText($envPath, $content)
  Write-Host 'Chave de aplicação gerada no .env privado.'
}
docker compose up --build -d
if ($LASTEXITCODE -ne 0) {
  Write-Host 'Falhou a construção ou arranque dos serviços. A apresentar estado e registos:' -ForegroundColor Yellow
  docker compose ps -a
  docker compose logs --tail=100 cis-api
  throw 'Não foi possível iniciar a API. Partilhe a mensagem de erro acima, sem credenciais.'
}
Write-Host ''
Write-Host 'API CIS local: http://127.0.0.1:8000/api'
Write-Host 'Saúde Laravel: http://127.0.0.1:8000/up'
Write-Host 'Para criar a primeira conta: docker compose exec cis-api php artisan cis:bootstrap-admin admin@exemplo.test'
Write-Host 'Para parar: docker compose down'
